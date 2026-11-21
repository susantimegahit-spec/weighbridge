<?php

namespace App\Http\Controllers;

use App\Models\DeliveryNote;
use App\Models\SalesOrder;
use App\Models\Truck;
use App\Models\WeighbridgeTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class WeighbridgeController extends Controller
{
    public function index(): View
    {
        $tickets = WeighbridgeTicket::with(['salesOrder', 'truck', 'deliveryNote'])
            ->orderBy('id')
            ->get();

        $salesOrders = SalesOrder::orderBy('id')->get();
        $trucks = Truck::orderBy('id')->get();
        $deliveryNotes = DeliveryNote::with(['salesOrder', 'truck', 'weighbridgeTicket'])
            ->latest('issued_at')
            ->get();

        return view('weighbridge.index', compact('tickets', 'salesOrders', 'trucks', 'deliveryNotes'));
    }

    /**
     * Penimbangan awal (Gross). Net = Gross - Tare.
     * Jika net melebihi batas maksimal truk -> overload_hold (surat jalan diblokir).
     */
    public function weigh(Request $request, WeighbridgeTicket $ticket): RedirectResponse
    {
        $request->validate([
            'gross_weight_kg' => ['required', 'numeric', 'gt:' . $ticket->tare_weight_kg],
        ]);

        return DB::transaction(function () use ($request, $ticket) {
            $ticket = WeighbridgeTicket::with('truck')->lockForUpdate()->findOrFail($ticket->id);

            if ($ticket->status !== WeighbridgeTicket::STATUS_PENDING) {
                return back()->with('error', 'Tiket ini sudah ditimbang / bukan berstatus menunggu penimbangan.');
            }

            $this->applyWeight($ticket, (float) $request->input('gross_weight_kg'));
            $ticket->weighed_at = now();
            $ticket->save();

            if ($ticket->is_overload) {
                return back()->with(
                    'error',
                    sprintf(
                        'OVERLOAD %s kg (net %s kg, batas %s kg). Surat jalan ditahan. Kurangi muatan lalu timbang ulang.',
                        $this->fmt($ticket->overload_kg),
                        $this->fmt($ticket->net_weight_kg),
                        $this->fmt($ticket->truck->max_net_weight_kg),
                    )
                );
            }

            return back()->with('success', 'Penimbangan selesai. Tiket disetujui untuk dispatch.');
        });
    }

    /**
     * Selesaikan overload: muatan dikurangi, ditimbang ulang. SO TIDAK dibatalkan,
     * sisa muatan dikirim lewat tiket ritase berikutnya.
     */
    public function resolveOverload(Request $request, WeighbridgeTicket $ticket): RedirectResponse
    {
        $request->validate([
            'new_gross_weight_kg' => ['required', 'numeric', 'gt:' . $ticket->tare_weight_kg],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return DB::transaction(function () use ($request, $ticket) {
            $ticket = WeighbridgeTicket::with('truck')->lockForUpdate()->findOrFail($ticket->id);

            if ($ticket->status !== WeighbridgeTicket::STATUS_OVERLOAD_HOLD) {
                return back()->with('error', 'Hanya tiket berstatus overload_hold yang dapat diselesaikan.');
            }

            $previousGross = $ticket->gross_weight_kg;
            $this->applyWeight($ticket, (float) $request->input('new_gross_weight_kg'));

            // Masih overload -> tetap ditahan, catat percobaan timbang ulang.
            if ($ticket->is_overload) {
                $ticket->weighed_at = now();
                $ticket->save();

                return back()->with(
                    'error',
                    sprintf(
                        'Masih OVERLOAD %s kg. Kurangi muatan lagi lalu timbang ulang.',
                        $this->fmt($ticket->overload_kg)
                    )
                );
            }

            $reweighNote = sprintf(
                '[Timbang ulang %s] Gross %s -> %s kg. %s',
                now()->format('Y-m-d H:i'),
                $this->fmt($previousGross),
                $this->fmt($ticket->gross_weight_kg),
                $request->input('notes', '')
            );
            $ticket->notes = trim(($ticket->notes ? $ticket->notes . "\n" : '') . $reweighNote);
            $ticket->weighed_at = now();
            $ticket->save();

            return back()->with('success', 'Overload teratasi. Tiket disetujui untuk dispatch (pengiriman parsial); SO tidak dibatalkan.');
        });
    }

    public function dispatch(WeighbridgeTicket $ticket): RedirectResponse
    {
        return DB::transaction(function () use ($ticket) {
            // Lock tiket agar klik ganda tidak menerbitkan surat jalan dua kali.
            $ticket = WeighbridgeTicket::lockForUpdate()->findOrFail($ticket->id);

            if ($ticket->status === WeighbridgeTicket::STATUS_OVERLOAD_HOLD || $ticket->is_overload) {
                return back()->with('error', 'Surat jalan DITOLAK: tiket overload. Kurangi muatan dan timbang ulang terlebih dahulu.');
            }

            if ($ticket->status !== WeighbridgeTicket::STATUS_APPROVED) {
                return back()->with('error', 'Surat jalan hanya dapat diterbitkan untuk tiket yang sudah disetujui (approved_for_dispatch).');
            }

            DeliveryNote::create([
                'delivery_number' => 'SJ-' . now()->format('YmdHis') . '-' . $ticket->id,
                'weighbridge_ticket_id' => $ticket->id,
                'sales_order_id' => $ticket->sales_order_id,
                'truck_id' => $ticket->truck_id,
                'dispatched_qty_kg' => $ticket->net_weight_kg ?? 0,
                'issued_at' => now(),
            ]);

            $ticket->update([
                'status' => WeighbridgeTicket::STATUS_DISPATCHED,
            ]);

            $salesOrder = SalesOrder::lockForUpdate()->findOrFail($ticket->sales_order_id);
            $newShippedQty = $salesOrder->shipped_qty_kg + ($ticket->net_weight_kg ?? 0);
            $salesOrder->update([
                'shipped_qty_kg' => $newShippedQty,
                'status' => $newShippedQty >= $salesOrder->ordered_qty_kg
                    ? SalesOrder::STATUS_COMPLETED
                    : SalesOrder::STATUS_PARTIALLY_SHIPPED,
            ]);

            return back()->with('success', 'Surat Jalan berhasil diterbitkan.');
        });
    }

    /**
     * Hitung net / overload dan tentukan status tiket (belum disimpan).
     */
    private function applyWeight(WeighbridgeTicket $ticket, float $gross): void
    {
        $net = round($gross - (float) $ticket->tare_weight_kg, 2);
        $max = (float) $ticket->truck->max_net_weight_kg;
        $overload = max(0, round($net - $max, 2));

        $ticket->gross_weight_kg = $gross;
        $ticket->net_weight_kg = $net;
        $ticket->is_overload = $overload > 0;
        $ticket->overload_kg = $overload;
        $ticket->status = $overload > 0
            ? WeighbridgeTicket::STATUS_OVERLOAD_HOLD
            : WeighbridgeTicket::STATUS_APPROVED;
    }

    private function fmt(?float $value): string
    {
        return number_format((float) $value, 0, ',', '.');
    }
}
