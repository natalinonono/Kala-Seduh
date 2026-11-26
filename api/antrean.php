<?php
/**
 * Endpoint JSON Antrean Seduh Live & Ketersediaan Stok — Kala Seduh Slow Bar.
 * Digunakan oleh halaman pelanggan untuk memperbarui status antrean & stok secara real-time.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDbConnection();

    $stmtQueue = $pdo->query(
        "SELECT
            p.id,
            p.kode_pesanan,
            p.nama_pelanggan,
            p.nomor_meja,
            p.tipe_sajian,
            p.catatan,
            p.total_harga,
            p.status,
            p.created_at,
            b.nama_kopi,
            b.proses,
            m.nama_metode,
            m.rasio_kopi_gram,
            m.estimasi_menit
         FROM pesanan p
         JOIN biji_kopi b ON b.id = p.biji_kopi_id
         JOIN metode_seduh m ON m.id = p.metode_seduh_id
         WHERE p.status IN ('Menunggu', 'Sedang Diseduh')
            OR (
                p.status = 'Selesai'
                AND (p.created_at AT TIME ZONE 'Asia/Jakarta')::date = (NOW() AT TIME ZONE 'Asia/Jakarta')::date
            )
         ORDER BY
            CASE p.status
                WHEN 'Sedang Diseduh' THEN 1
                WHEN 'Menunggu' THEN 2
                ELSE 3
            END,
            p.created_at DESC
         LIMIT 24"
    );
    $orders = $stmtQueue->fetchAll();

    foreach ($orders as &$row) {
        $row['id'] = (int) $row['id'];
        $row['total_harga'] = (int) $row['total_harga'];
        $row['rasio_kopi_gram'] = (int) $row['rasio_kopi_gram'];
        $row['estimasi_menit'] = (int) $row['estimasi_menit'];
        $row['jam_wib'] = formatJamSingkat((string) $row['created_at']);
    }
    unset($row);

    $stmtStocks = $pdo->query('SELECT id, stok_gram FROM biji_kopi ORDER BY id ASC');
    $stocks = [];
    foreach ($stmtStocks->fetchAll() as $s) {
        $stocks[(int) $s['id']] = (int) $s['stok_gram'];
    }

    try {
        $stmtMethodStocks = $pdo->query(
            "SELECT id, stok_porsi
             FROM metode_seduh
             WHERE LOWER(nama_metode) NOT LIKE '%cold brew%'
             ORDER BY id ASC"
        );
        $methodStockRows = $stmtMethodStocks->fetchAll();
    } catch (PDOException $e) {
        if (isSqlState($e, '42703')) {
            ensureMetodeSeduhStockColumn($pdo);
            $methodStockRows = $pdo->query(
                "SELECT id, stok_porsi
                 FROM metode_seduh
                 WHERE LOWER(nama_metode) NOT LIKE '%cold brew%'
                 ORDER BY id ASC"
            )->fetchAll();
        } else {
            throw $e;
        }
    }
    $methodStocks = [];
    foreach ($methodStockRows as $ms) {
        $methodStocks[(int) $ms['id']] = $ms['stok_porsi'] !== null ? (int) $ms['stok_porsi'] : null;
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    jsonResponse([
        'success'       => true,
        'server_time'   => date('H:i:s') . ' WIB',
        'orders'        => $orders,
        'stocks'        => $stocks,
        'method_stocks' => $methodStocks,
    ]);
} catch (Throwable $e) {
    error_log('[Antrean API Error] ' . $e->getMessage());
    jsonResponse([
        'success' => false,
        'message' => 'Gagal memuat antrean live.',
    ], 500);
}
