<?php
/**
 * Endpoint Pemesanan Manual Brew (POST) — Kala Seduh Slow Bar.
 * Memvalidasi stok biji kopi terhadap rasio_kopi_gram metode seduh,
 * menyimpan pesanan, dan mengurangi stok_gram secara atomik dalam DB transaction.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

/**
 * Mengambil input baik dari body JSON maupun form-urlencoded.
 *
 * @return array<string, mixed>
 */
function getOrderRequestData(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains(strtolower($contentType), 'application/json')) {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }
    return $_POST;
}

function wantsJsonResponse(): bool
{
    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    $requestedWith = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    return str_contains($accept, 'application/json')
        || str_contains($contentType, 'application/json')
        || $requestedWith === 'xmlhttprequest'
        || (isset($_POST['ajax']) && $_POST['ajax'] === '1');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if (wantsJsonResponse()) {
        jsonResponse(['success' => false, 'message' => 'Metode harus POST.'], 405);
    }
    redirectTo('/');
}

$input = getOrderRequestData();
$isJson = wantsJsonResponse();
$redirectTarget = (!empty($input['from']) && $input['from'] === 'admin') ? '/admin' : '/';

$respondError = static function (string $message, int $statusCode = 400) use ($isJson, $redirectTarget): never {
    if ($isJson) {
        jsonResponse(['success' => false, 'message' => $message], $statusCode);
    }
    setFlashMessage('error', $message);
    redirectTo($redirectTarget);
};

$namaPelanggan = trim((string) ($input['nama_pelanggan'] ?? ''));
$nomorMeja = trim((string) ($input['nomor_meja'] ?? ''));
$bijiKopiId = (int) ($input['biji_kopi_id'] ?? 0);
$metodeSeduhId = (int) ($input['metode_seduh_id'] ?? 0);
$tipeSajian = trim((string) ($input['tipe_sajian'] ?? 'Hot'));
$catatan = trim((string) ($input['catatan'] ?? ''));

if ($namaPelanggan === '' || mb_strlen($namaPelanggan) > 100) {
    $respondError('Nama pelanggan wajib diisi (maksimal 100 karakter).');
}
if ($nomorMeja === '' || mb_strlen($nomorMeja) > 30) {
    $respondError('Nomor meja atau kursi bar wajib diisi.');
}
if ($bijiKopiId <= 0 || $metodeSeduhId <= 0) {
    $respondError('Pilih biji kopi dan metode seduh yang valid.');
}
if (!in_array($tipeSajian, ['Hot', 'Iced'], true)) {
    $respondError('Tipe sajian harus Hot atau Iced.');
}
if (mb_strlen($catatan) > 300) {
    $catatan = mb_substr($catatan, 0, 300);
}

try {
    $pdo = getDbConnection();
    $pdo->beginTransaction();

    // 1. Kunci baris biji kopi (FOR UPDATE) agar stok aman dari race condition
    $stmtBean = $pdo->prepare(
        'SELECT id, nama_kopi, origin, proses, stok_gram, harga FROM biji_kopi WHERE id = :id FOR UPDATE'
    );
    $stmtBean->execute([':id' => $bijiKopiId]);
    $bean = $stmtBean->fetch();

    if (!$bean) {
        $pdo->rollBack();
        $respondError('Biji kopi yang dipilih tidak ditemukan.', 404);
    }

    // 2. Ambil parameter metode seduh (rasio gramasi & biaya tambahan)
    $stmtMethod = $pdo->prepare(
        'SELECT id, nama_metode, rasio_kopi_gram, estimasi_menit, biaya_tambahan FROM metode_seduh WHERE id = :id'
    );
    $stmtMethod->execute([':id' => $metodeSeduhId]);
    $method = $stmtMethod->fetch();

    if (!$method) {
        $pdo->rollBack();
        $respondError('Metode seduh yang dipilih tidak ditemukan.', 404);
    }

    $stokSekarang = (int) $bean['stok_gram'];
    $butuhGram = (int) $method['rasio_kopi_gram'];

    // 3. Validasi kecukupan stok gramasi
    if ($stokSekarang < $butuhGram) {
        $pdo->rollBack();
        $respondError(
            sprintf(
                'Maaf, stok %s tidak mencukupi (tersisa %dg, dibutuhkan %dg untuk metode %s).',
                $bean['nama_kopi'],
                $stokSekarang,
                $butuhGram,
                $method['nama_metode']
            ),
            409
        );
    }

    $totalHarga = (int) $bean['harga'] + (int) $method['biaya_tambahan'];
    $tempKode = 'TMP-' . bin2hex(random_bytes(6));

    // 4. Simpan pesanan baru dengan status awal "Menunggu"
    $stmtInsert = $pdo->prepare(
        'INSERT INTO pesanan (
            kode_pesanan, nama_pelanggan, nomor_meja, biji_kopi_id,
            metode_seduh_id, tipe_sajian, catatan, total_harga, status
        ) VALUES (
            :kode, :nama, :meja, :biji_id,
            :metode_id, :sajian, :catatan, :total_harga, :status
        ) RETURNING id, created_at'
    );
    $stmtInsert->execute([
        ':kode'        => $tempKode,
        ':nama'        => $namaPelanggan,
        ':meja'        => $nomorMeja,
        ':biji_id'     => $bijiKopiId,
        ':metode_id'   => $metodeSeduhId,
        ':sajian'      => $tipeSajian,
        ':catatan'     => $catatan,
        ':total_harga' => $totalHarga,
        ':status'      => 'Menunggu',
    ]);
    $inserted = $stmtInsert->fetch();
    $orderId = (int) ($inserted['id'] ?? 0);

    // Hasilkan kode_pesanan yang rapi dan unik (contoh: KS-046)
    $kodePesanan = 'KS-' . str_pad((string) $orderId, 3, '0', STR_PAD_LEFT);
    $stmtUpdateCode = $pdo->prepare('UPDATE pesanan SET kode_pesanan = :kode WHERE id = :id');
    $stmtUpdateCode->execute([':kode' => $kodePesanan, ':id' => $orderId]);

    // 5. Kurangi stok_gram secara otomatis pada tabel biji_kopi
    $stmtDeduct = $pdo->prepare(
        'UPDATE biji_kopi
         SET stok_gram = stok_gram - :gram
         WHERE id = :id AND stok_gram >= :gram
         RETURNING stok_gram'
    );
    $stmtDeduct->execute([
        ':gram' => $butuhGram,
        ':id'   => $bijiKopiId,
    ]);
    $deducted = $stmtDeduct->fetch();

    if (!$deducted) {
        $pdo->rollBack();
        $respondError('Gagal memperbarui stok biji kopi. Silakan ulangi pemesanan.', 409);
    }

    $pdo->commit();

    $newStock = (int) $deducted['stok_gram'];
    $orderResult = [
        'id'              => $orderId,
        'kode_pesanan'    => $kodePesanan,
        'nama_pelanggan'  => $namaPelanggan,
        'nomor_meja'      => $nomorMeja,
        'nama_kopi'       => (string) $bean['nama_kopi'],
        'nama_metode'     => (string) $method['nama_metode'],
        'rasio_kopi_gram' => $butuhGram,
        'estimasi_menit'  => (int) $method['estimasi_menit'],
        'tipe_sajian'     => $tipeSajian,
        'catatan'         => $catatan,
        'total_harga'     => $totalHarga,
        'status'          => 'Menunggu',
        'created_at'      => (string) ($inserted['created_at'] ?? ''),
    ];

    if ($isJson) {
        jsonResponse([
            'success'           => true,
            'message'           => "Pesanan {$kodePesanan} berhasil dikirim ke antrean barista!",
            'order'             => $orderResult,
            'biji_kopi_id'      => $bijiKopiId,
            'updated_stok_gram' => $newStock,
        ], 201);
    }

    setFlashMessage(
        'success',
        "Pesanan {$kodePesanan} atas nama {$namaPelanggan} berhasil masuk antrean. Stok {$bean['nama_kopi']} tersisa {$newStock}g."
    );
    redirectTo($redirectTarget);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[Proses Order Error] ' . $e->getMessage());
    $respondError('Terjadi kesalahan sistem saat memproses pesanan: ' . $e->getMessage(), 500);
}
