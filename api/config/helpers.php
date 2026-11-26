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
 * Mengembalikan daftar dan penjelasan tiap metode proses pasca-panen biji kopi
 * (Natural, Washed, Honey, Anaerobic) serta pengaruhnya terhadap karakter rasa.
 *
 * @return array{judul: string, cara_proses: string, karakter_rasa: string, cocok_untuk: string}
 */
function getProcessProfile(string $proses): array
{
    $profiles = getAllProcessProfiles();
    return $profiles[$proses] ?? [
        'judul'         => $proses,
        'cara_proses'   => 'Metode pengolahan pasca-panen khusus sebelum biji kopi disangrai.',
        'karakter_rasa' => 'Membentuk keseimbangan rasa manis, keasaman, dan ketebalan body.',
        'cocok_untuk'   => 'Beragam metode penyeduhan manual.',
    ];
}

/**
 * Seluruh profil penjelasan proses pasca-panen biji kopi.
 *
 * @return array<string, array{judul: string, cara_proses: string, karakter_rasa: string, cocok_untuk: string}>
 */
function getAllProcessProfiles(): array
{
    return [
        'Natural' => [
            'judul'         => 'Natural (Jemur Utuh)',
            'cara_proses'   => 'Buah ceri kopi tidak dikupas dan langsung dijemur utuh bersama kulit serta daging buahnya 2–4 minggu sebelum digiling.',
            'karakter_rasa' => 'Gula buah meresap ke biji sehingga terasa manis buah matang (berry/tropis), body tebal & legit, keasaman lembut.',
            'cocok_untuk'   => 'Pecinta kopi manis buah & body tebal.',
        ],
        'Washed' => [
            'judul'         => 'Washed (Cuci Bersih)',
            'cara_proses'   => 'Kulit ceri kopi dikupas, lalu biji difermentasi 12–36 jam dan dicuci bersih memakai air hingga seluruh lendir buah hilang sebelum dijemur.',
            'karakter_rasa' => 'Rasa paling bersih (clean), jernih, keasaman cerah (citrus/floral), dan karakter asli daerah asalnya tetap utuh.',
            'cocok_untuk'   => 'Pecinta kopi bersih, segar & floral/citrus.',
        ],
        'Honey' => [
            'judul'         => 'Honey Process (Lendir Buah)',
            'cara_proses'   => 'Kulit luar ceri dikupas, tetapi lapisan lendir manis buahnya (mucilage) dibiarkan menempel saat dijemur (bukan ditambah madu).',
            'karakter_rasa' => 'Seimbang di antara Washed dan Natural: manis karamel lembut dengan keasaman halus.',
            'cocok_untuk'   => 'Pecinta rasa manis seimbang tanpa terlalu asam atau berat.',
        ],
        'Anaerobic' => [
            'judul'         => 'Anaerobic (Kedap Udara)',
            'cara_proses'   => 'Ceri atau biji kopi dimasukkan ke tangki tertutup kedap udara tanpa oksigen selama beberapa hari sebelum dikeringkan agar fermentasi terkendali.',
            'karakter_rasa' => 'Aroma sangat ekspresif & unik (winey, buah fermentasi, rempah/teh), manis tajam dan kompleks.',
            'cocok_untuk'   => 'Pecinta eksplorasi aroma unik & rasa intens.',
        ],
    ];
}

/**
 * Memastikan kolom stok_porsi pada tabel metode_seduh tersedia di database
 * dan memberi stok batch awal (8 porsi) untuk Cold Brew bila baru ditambahkan.
 */
function ensureMetodeSeduhStockColumn(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $exists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = 'metode_seduh'
           AND column_name = 'stok_porsi'"
    )->fetchColumn();

    if ($exists === 0) {
        $pdo->exec(
            'ALTER TABLE metode_seduh
             ADD COLUMN IF NOT EXISTS stok_porsi INTEGER DEFAULT NULL
             CHECK (stok_porsi IS NULL OR stok_porsi >= 0)'
        );
        $pdo->exec(
            "UPDATE metode_seduh
             SET stok_porsi = 8
             WHERE LOWER(nama_metode) LIKE '%cold brew%' AND stok_porsi IS NULL"
        );
    }

    $checked = true;
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
            "INSERT INTO metode_seduh (nama_metode, rasio_kopi_gram, estimasi_menit, biaya_tambahan, stok_porsi) VALUES
            ('French Press', 18, 4, 1000, NULL),
            ('Cold Brew', 20, 2, 3000, 8),
            ('Americano', 18, 3, 0, NULL),
            ('Latte', 18, 4, 4000, NULL)
            ON CONFLICT (nama_metode) DO NOTHING"
        );
        return $pdo->query(
            'SELECT id, nama_metode, rasio_kopi_gram, estimasi_menit, biaya_tambahan, stok_porsi
             FROM metode_seduh
             ORDER BY id ASC'
        )->fetchAll();
    }

    return $methods;
}

/**
 * Memastikan kolom harga_per_kg tersedia di tabel biji_kopi (untuk membedakan
 * harga biji kopi per kg dan harga seduhan per cangkir), sekaligus menyesuaikan
 * data seed lama agar tarif seduhan & tambahan teknik lebih realistis.
 */
function ensureBijiKopiHargaPerKgColumn(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $exists = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = current_schema()
           AND table_name = 'biji_kopi'
           AND column_name = 'harga_per_kg'"
    )->fetchColumn();

    if ($exists === 0) {
        $pdo->exec(
            'ALTER TABLE biji_kopi
             ADD COLUMN IF NOT EXISTS harga_per_kg INTEGER NOT NULL DEFAULT 250000
             CHECK (harga_per_kg >= 0)'
        );

        $seedBeanPricing = [
            ['Aceh Gayo Pantan Musara',   45000, 22000, 320000],
            ['Flores Bajawa Ngura',       38000, 18000, 240000],
            ['Bali Kintamani Ulian',      55000, 21000, 290000],
            ['Java Preanger Gunung Halu', 42000, 20000, 280000],
            ['Toraja Sapan Minanga',      40000, 19000, 260000],
            ['Papua Wamena Kurima',       60000, 24000, 340000],
        ];
        $stmtBean = $pdo->prepare(
            'UPDATE biji_kopi
             SET harga = :new_harga, harga_per_kg = :harga_kg
             WHERE nama_kopi = :nama AND harga = :old_harga'
        );
        foreach ($seedBeanPricing as [$nama, $oldHarga, $newHarga, $hargaKg]) {
            $stmtBean->execute([
                ':new_harga' => $newHarga,
                ':harga_kg'  => $hargaKg,
                ':nama'      => $nama,
                ':old_harga' => $oldHarga,
            ]);
        }

        $seedMethodFees = [
            ['Kalita Wave',  2000,  1000],
            ['AeroPress',    3000,  2000],
            ['Syphon',       10000, 5000],
            ['French Press', 2000,  1000],
            ['Cold Brew',    5000,  3000],
            ['Americano',    2000,  0],
            ['Latte',        8000,  4000],
        ];
        $stmtMethod = $pdo->prepare(
            'UPDATE metode_seduh
             SET biaya_tambahan = :new_fee
             WHERE nama_metode = :nama AND biaya_tambahan = :old_fee'
        );
        foreach ($seedMethodFees as [$namaMetode, $oldFee, $newFee]) {
            $stmtMethod->execute([
                ':new_fee' => $newFee,
                ':nama'    => $namaMetode,
                ':old_fee' => $oldFee,
            ]);
        }
    }

    $checked = true;
}

/**
 * Menyesuaikan seed biji kopi non-Indonesia lama (Ethiopia & Kenya) di database
 * menjadi biji kopi single origin Nusantara (Bali Kintamani Ulian & Papua Wamena Kurima)
 * tanpa memutus relasi pesanan historis.
 */
function syncIndonesianBeansCatalog(PDO $pdo): void
{
    static $synced = false;
    if ($synced) {
        return;
    }

    $replacements = [
        [
            'old_nama'     => 'Ethiopia Yirgacheffe Chelchele',
            'nama_kopi'    => 'Bali Kintamani Ulian',
            'origin'       => 'Bangli, Bali • 1.450 mdpl',
            'proses'       => 'Washed',
            'notes'        => 'Jasmine Blossom, Mandarin Orange, Lemon Zest',
            'harga'        => 21000,
            'harga_per_kg' => 290000,
        ],
        [
            'old_nama'     => 'Kenya Nyeri Kiandu AA',
            'nama_kopi'    => 'Papua Wamena Kurima',
            'origin'       => 'Lembah Baliem, Papua • 1.800 mdpl',
            'proses'       => 'Washed',
            'notes'        => 'Blackberry, Red Plum, Cane Sugar',
            'harga'        => 24000,
            'harga_per_kg' => 340000,
        ],
    ];

    $stmt = $pdo->prepare(
        'UPDATE biji_kopi
         SET nama_kopi = :nama,
             origin = :origin,
             proses = :proses,
             tasting_notes = :notes,
             harga = :harga,
             harga_per_kg = :harga_kg
         WHERE nama_kopi = :old_nama'
    );

    foreach ($replacements as $row) {
        $stmt->execute([
            ':nama'     => $row['nama_kopi'],
            ':origin'   => $row['origin'],
            ':proses'   => $row['proses'],
            ':notes'    => $row['notes'],
            ':harga'    => $row['harga'],
            ':harga_kg' => $row['harga_per_kg'],
            ':old_nama' => $row['old_nama'],
        ]);
    }

    $synced = true;
}
