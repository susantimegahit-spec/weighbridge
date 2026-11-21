-- Laporan Pos Timbangan (PostgreSQL / DBeaver)
-- LEFT JOIN ke delivery_notes agar tiket yang surat jalannya belum terbit tetap tampil.
SELECT
    t.ticket_number      AS nomor_tiket,
    so.so_number         AS nomor_so,
    so.customer_name     AS nama_customer,
    tr.plate_number      AS plat_nomor_truk,
    t.net_weight_kg      AS berat_bersih_kg,
    t.status             AS status_tiket,
    dn.delivery_number   AS nomor_surat_jalan
FROM weighbridge_tickets t
JOIN sales_orders so        ON so.id = t.sales_order_id
JOIN trucks tr              ON tr.id = t.truck_id
LEFT JOIN delivery_notes dn ON dn.weighbridge_ticket_id = t.id
ORDER BY t.id;
