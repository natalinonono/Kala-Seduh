<?php
/**
 * Helper umum untuk aplikasi Kala Seduh Slow Bar.
 */

declare(strict_types=1);

if (!date_default_timezone_set('Asia/Jakarta')) {
    date_default_timezone_set('UTC');
}

/**
 * Muat file .env lokal jika tersedia (berguna saat pengembangan lokal;
 * di Vercel variabel diambil langsung dari Environment Variables).
 */
function loadLocalEnv(string $envPath): void
{
    static $loaded = false;
    if ($loaded || !is_file($envPath) || !is_readable($envPath)) {
        $loaded = true;
        return;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        $loaded = true;
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        $pos = strpos($trimmed, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($trimmed, 0, $pos));
        $val = trim(substr($trimmed, $pos + 1));

        if (
            (str_starts_with($val, '"') && str_ends_with($val, '"')) ||
            (str_starts_with($val, "'") && str_ends_with($val, "'"))
        ) {
            $val = substr($val, 1, -1);
        }

        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $val);
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }
    }

    $loaded = true;
}

loadLocalEnv(__DIR__ . '/../../.env');

/**
 * Ambil nilai environment variable dengan fallback berurutan.
 */
function envValue(string $key, ?string $default = null): ?string
{
    $val = getenv($key);
    if ($val !== false && $val !== '') {
        return (string) $val;
    }
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string) $_ENV[$key];
    }
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return (string) $_SERVER[$key];
    }
    return $default;
}

/**
 * Escape HTML untuk mencegah XSS.
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Format angka menjadi Rupiah (contoh: Rp 45.000).
 */
function formatRupiah(int|float|string $amount): string
{
    $num = (int) round((float) $amount);
    return 'Rp ' . number_format($num, 0, ',', '.');
}

/**
 * Format timestamp menjadi tanggal & jam Indonesia (WIB).
 */
function formatWaktuIndo(?string $timestamp): string
{
    if (!$timestamp) {
        return '-';
    }
    try {
        $dt = new DateTimeImmutable($timestamp);
        $dtWib = $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
        $bulan = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
            5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
            9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
        ];
        $m = (int) $dtWib->format('n');
        return $dtWib->format('d') . ' ' . ($bulan[$m] ?? $dtWib->format('M')) . ' ' . $dtWib->format('Y • H:i') . ' WIB';
    } catch (Throwable) {
        return $timestamp;
    }
}

/**
 * Format jam singkat WIB (contoh: 14:25 WIB).
 */
function formatJamSingkat(?string $timestamp): string
{
    if (!$timestamp) {
        return '-';
    }
    try {
        $dt = new DateTimeImmutable($timestamp);
        $dtWib = $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
        return $dtWib->format('H:i') . ' WIB';
    } catch (Throwable) {
        return '-';
    }
}

/**
 * Kirim respons JSON dan hentikan eksekusi.
 */
function jsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Redirect HTTP dan hentikan eksekusi.
 */
function redirectTo(string $location): never
{
    header('Location: ' . $location);
    exit;
}

/**
 * Deteksi apakah permintaan masuk melalui HTTPS (termasuk proxy Vercel).
 */
function isHttpsRequest(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    if ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443) {
        return true;
    }
    return false;
}

/**
 * Simpan flash message sementara via cookie singkat (stateless untuk Vercel).
 */
function setFlashMessage(string $type, string $message): void
{
    $payload = json_encode(['type' => $type, 'message' => $message], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return;
    }
    setcookie('kala_seduh_flash', base64_encode($payload), [
        'expires' => time() + 60,
        'path' => '/',
        'secure' => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Ambil dan hapus flash message sekali baca.
 *
 * @return array{type: string, message: string}|null
 */
function consumeFlashMessage(): ?array
{
    $raw = $_COOKIE['kala_seduh_flash'] ?? '';
    if ($raw === '') {
        return null;
    }

    setcookie('kala_seduh_flash', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE['kala_seduh_flash']);

    $decoded = base64_decode((string) $raw, true);
    if ($decoded === false) {
        return null;
    }
    $data = json_decode($decoded, true);
    if (!is_array($data) || !isset($data['type'], $data['message'])) {
        return null;
    }
    return [
        'type' => (string) $data['type'],
        'message' => (string) $data['message'],
    ];
}

/**
 * Memastikan foto biji kopi selalu menampilkan foto biji kopi (beans).
 * Memetakan URL seed lama yang bukan foto biji kopi ke foto biji kopi yang sesuai.
 */
function resolveBeanImageUrl(?string $url): string
{
    $defaultBeans = 'https://images.unsplash.com/photo-1590093217544-da597729d81a?auto=format&fit=crop&w=900&q=80';
    $clean = trim((string) ($url ?? ''));
    if ($clean === '') {
        return $defaultBeans;
    }

    $legacyNonBeanMap = [
        'photo-1620051787220-50768889e32c' => 'https://images.unsplash.com/photo-1590093217544-da597729d81a?auto=format&fit=crop&w=900&q=80',
        'photo-1622088891722-c6f91b80627f' => 'https://images.unsplash.com/photo-1703113391576-3beb676b7638?auto=format&fit=crop&w=900&q=80',
        'photo-1534778101976-62847782c213' => 'https://images.unsplash.com/photo-1692299108834-038511803008?auto=format&fit=crop&w=900&q=80',
        'photo-1732870695254-b2718d0796ff' => 'https://images.unsplash.com/photo-1567613747125-f7ac12360d77?auto=format&fit=crop&w=900&q=80',
        'photo-1556742526-795a8eac090e'     => 'https://images.unsplash.com/photo-1712251769294-16163363ebef?auto=format&fit=crop&w=900&q=80',
    ];

    foreach ($legacyNonBeanMap as $needle => $replacement) {
        if (str_contains($clean, $needle)) {
            return $replacement;
        }
    }

    return $clean;
}

/**
 * Mengembalikan URL foto teknik penyeduhan manual brew sesuai nama metode.
 */
function resolveMethodImageUrl(string $namaMetode, ?string $dbUrl = null): string
{
    $clean = trim((string) ($dbUrl ?? ''));
    if ($clean !== '') {
        return $clean;
    }

    $lower = strtolower($namaMetode);
    if (str_contains($lower, 'v60')) {
        return 'https://images.unsplash.com/photo-1622088891722-c6f91b80627f?auto=format&fit=crop&w=900&q=80';
    }
    if (str_contains($lower, 'kalita')) {
        return 'https://images.unsplash.com/photo-1732870695254-b2718d0796ff?auto=format&fit=crop&w=900&q=80';
    }
    if (str_contains($lower, 'aeropress')) {
        return 'https://images.unsplash.com/photo-1771518854988-319b8ccf71c5?auto=format&fit=crop&w=900&q=80';
    }
    if (str_contains($lower, 'syphon') || str_contains($lower, 'siphon')) {
        return 'https://images.unsplash.com/photo-1774014045872-3cc2b5e7e816?auto=format&fit=crop&w=900&q=80';
    }
    if (str_contains($lower, 'french press')) {
        return 'https://images.unsplash.com/photo-1763931413710-cb62218fc4ff?auto=format&fit=crop&w=900&q=80';
    }
    if (str_contains($lower, 'cold brew')) {
        return 'https://images.unsplash.com/photo-1537371737569-a050cc10225b?auto=format&fit=crop&w=900&q=80';
    }
    if (str_contains($lower, 'americano')) {
        return 'https://images.unsplash.com/photo-1558557019-051d86ef616f?auto=format&fit=crop&w=900&q=80';
    }
    if (str_contains($lower, 'latte')) {
        return 'https://images.unsplash.com/photo-1534778101976-62847782c213?auto=format&fit=crop&w=900&q=80';
    }

    return 'https://images.unsplash.com/photo-1620051787220-50768889e32c?auto=format&fit=crop&w=900&q=80';
}

/**
 * Mengembalikan penjelasan perbedaan teknik penyeduhan & pengaruhnya terhadap rasa beans.
 *
 * @return array{kategori: string, dampak_badge: string, badge_class: string, perbedaan: string, pengaruh_rasa: string}
 */
function getMethodProfile(string $namaMetode): array
{
    $lower = strtolower($namaMetode);

    if (str_contains($lower, 'v60')) {
        return [
            'kategori'      => 'Pour-Over • Filter Kertas Kerucut',
            'dampak_badge'  => 'Rasa Asli Jernih',
            'badge_class'   => 'bg-sage-soft text-sage-text border-[#C2D6CD]',
            'perbedaan'     => 'Aliran air cepat melalui filter kertas berbentuk kerucut sehingga minyak kopi tersaring bersih.',
            'pengaruh_rasa' => 'Sangat menjaga karakter asli beans: aroma floral/buah dan keasaman cerah terasa paling jelas dengan body ringan.',
        ];
    }
    if (str_contains($lower, 'kalita')) {
        return [
            'kategori'      => 'Pour-Over • Flat-Bottom',
            'dampak_badge'  => 'Seimbang & Bulat',
            'badge_class'   => 'bg-surface-mid text-espresso border-sand',
            'perbedaan'     => 'Dasar dripper datar dengan 3 lubang kecil membuat air terendam lebih merata dibanding V60.',
            'pengaruh_rasa' => 'Menghaluskan keasaman tajam dari beans dan menonjolkan rasa manis karamel serta body lebih bulat.',
        ];
    }
    if (str_contains($lower, 'aeropress')) {
        return [
            'kategori'      => 'Immersion + Tekanan Ringan',
            'dampak_badge'  => 'Pekat & Juicy',
            'badge_class'   => 'bg-surface-mid text-espresso border-sand',
            'perbedaan'     => 'Kopi direndam singkat lalu didorong tekanan udara tangan melewati filter mikro.',
            'pengaruh_rasa' => 'Membuat karakter rasa beans lebih padat dan juicy dengan keasaman yang lebih lembut daripada V60.',
        ];
    }
    if (str_contains($lower, 'syphon') || str_contains($lower, 'siphon')) {
        return [
            'kategori'      => 'Vacuum Infusion • Tabung Kaca',
            'dampak_badge'  => 'Aroma Tajam & Bersih',
            'badge_class'   => 'bg-sage-soft text-sage-text border-[#C2D6CD]',
            'perbedaan'     => 'Menggunakan tekanan uap panas dan ruang vakum kaca pada suhu stabil untuk menyeduh kopi.',
            'pengaruh_rasa' => 'Menonjolkan aroma volatil beans sangat kuat dan rasa manis bersih menyerupai teh tanpa rasa pahit.',
        ];
    }
    if (str_contains($lower, 'french press')) {
        return [
            'kategori'      => 'Full Immersion • Filter Logam',
            'dampak_badge'  => 'Mengubah: Body Tebal',
            'badge_class'   => 'bg-terracotta-soft text-[#8F3F27] border-[#F3D1C4]',
            'perbedaan'     => 'Direndam penuh lalu disaring jaring logam (tanpa kertas), sehingga minyak alami kopi ikut terbawa.',
            'pengaruh_rasa' => 'Memengaruhi rasa beans secara nyata: tekstur jauh lebih tebal/oily, nada cokelat & earthy menguat, sedangkan asam buah mereda.',
        ];
    }
    if (str_contains($lower, 'cold brew')) {
        return [
            'kategori'      => 'Slow Cold Extraction',
            'dampak_badge'  => 'Mengubah: Low Acid & Halus',
            'badge_class'   => 'bg-terracotta-soft text-[#8F3F27] border-[#F3D1C4]',
            'perbedaan'     => 'Diseduh dengan air dingin selama 12–16 jam tanpa pemanasan sama sekali.',
            'pengaruh_rasa' => 'Mengubah profil rasa beans drastis: tingkat keasaman turun jauh (ramah lambung) dan rasa manis cokelat-karamel terasa dominan.',
        ];
    }
    if (str_contains($lower, 'americano')) {
        return [
            'kategori'      => 'Espresso + Air Panas',
            'dampak_badge'  => 'Mengubah: Bold & Intens',
            'badge_class'   => 'bg-[#2C1E16] text-[#F5F0E6] border-[#2C1E16]',
            'perbedaan'     => 'Diekstraksi dengan mesin tekanan tinggi (9 bar) menjadi shot espresso, lalu ditambah air panas.',
            'pengaruh_rasa' => 'Sangat memengaruhi rasa beans: rasa jauh lebih pekat, pahit-manis intens dengan crema, sehingga catatan buah ringan menjadi lebih dalam.',
        ];
    }
    if (str_contains($lower, 'latte')) {
        return [
            'kategori'      => 'Espresso + Susu Kukus',
            'dampak_badge'  => 'Mengubah Total + Susu',
            'badge_class'   => 'bg-terracotta text-white border-terracotta',
            'perbedaan'     => 'Shot espresso pekat dipadukan dengan steamed milk hangat dan lapisan microfoam lembut.',
            'pengaruh_rasa' => 'Paling memengaruhi rasa asli beans: susu menutupi keasaman buah/floral dan mengubahnya menjadi creamy, manis, dan cokelat susu.',
        ];
    }

    return [
        'kategori'      => 'Manual Brew Spesial',
        'dampak_badge'  => 'Karakter Khas Alat',
        'badge_class'   => 'bg-surface-mid text-espresso border-sand',
        'perbedaan'     => 'Teknik penyeduhan dengan rasio gramasi dan waktu ekstraksi khusus sesuai metode alat.',
        'pengaruh_rasa' => 'Setiap metode penyeduhan memengaruhi intensitas aroma, tingkat keasaman, dan ketebalan body dari beans yang dipilih.',
    ];
}

/**
 * Menambahkan opsi teknik penyeduhan lanjutan (French Press, Cold Brew, Americano, Latte)
 * secara aman apabila database masih berisi 4 metode seed awal saja.
 *
 * @param array<int, array<string, mixed>> $methods
 * @return array<int, array<string, mixed>>
 */
function syncExtraDefaultMethods(PDO $pdo, array $methods): array
{
    $existingNames = array_map(static fn(array $m): string => (string) $m['nama_metode'], $methods);
    sort($existingNames);

    if ($existingNames === ['AeroPress', 'Kalita Wave', 'Syphon', 'V60']) {
        $pdo->exec(
            "INSERT INTO metode_seduh (nama_metode, rasio_kopi_gram, estimasi_menit, biaya_tambahan) VALUES
            ('French Press', 18, 4, 2000),
            ('Cold Brew', 20, 2, 5000),
            ('Americano', 18, 3, 2000),
            ('Latte', 18, 4, 8000)
            ON CONFLICT (nama_metode) DO NOTHING"
        );
        return $pdo->query(
            'SELECT id, nama_metode, rasio_kopi_gram, estimasi_menit, biaya_tambahan
             FROM metode_seduh
             ORDER BY id ASC'
        )->fetchAll();
    }

    return $methods;
}
