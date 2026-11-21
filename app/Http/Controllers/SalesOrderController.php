<?php

namespace App\Http\Controllers;

use App\Models\SalesOrder;
use App\Models\Truck;
use App\Models\WeighbridgeTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesOrderController extends Controller
{
    /**
     * Buat tiket ritase berikutnya untuk sisa muatan SO (pengiriman parsial).
     */
    public function createNextTicket(Request $request, SalesOrder $salesOrder): RedirectResponse
    {
        $request->validate([
            'truck_id' => ['required', 'exists:trucks,id'],
        ]);

        return DB::transaction(function () use ($request, $salesOrder) {
            $salesOrder = SalesOrder::lockForUpdate()->findOrFail($salesOrder->id);

            if ($salesOrder->status === SalesOrder::STATUS_COMPLETED || $salesOrder->remaining_qty_kg <= 0) {
                return back()->with('error', 'Sales Order sudah selesai (completed), tidak ada sisa muatan untuk dikirim.');
            }

            $truck = Truck::findOrFail($request->input('truck_id'));
            $sequence = $salesOrder->weighbridgeTickets()->count() + 1;

            $ticket = WeighbridgeTicket::create([
                'ticket_number' => sprintf('WB-%s-R%d-%d', $salesOrder->so_number, $sequence, now()->timestamp),
                'sales_order_id' => $salesOrder->id,
                'truck_id' => $truck->id,
                'tare_weight_kg' => $truck->tare_weight_kg,
                'status' => WeighbridgeTicket::STATUS_PENDING,
                'notes' => sprintf(
                    'Ritase ke-%d. Sisa SO: %s kg.',
                    $sequence,
                    number_format($salesOrder->remaining_qty_kg, 0, ',', '.')
                ),
            ]);

            return back()->with('success', 'Tiket ritase berikutnya dibuat: ' . $ticket->ticket_number);
        });
    }
}
