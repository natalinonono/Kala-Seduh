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
 * Menghitung rekomendasi suhu air seduh & penjelasan karakter untuk satu tasting note tertentu.
 * Memperhitungkan proses pasca-panen (Natural/Anaerobic diekstraksi lebih mudah -> sedikit lebih rendah).
 *
 * @return array{rasa: string, kategori: string, suhu: int, rentang: string, penjelasan: string}
 */
function resolveSingleNoteBrewTemp(string $note, string $proses = 'Washed'): array
{
    $cleanNote = trim($note);
    $lower = strtolower($cleanNote);
    $isHighSolubility = in_array(strtolower(trim($proses)), ['natural', 'anaerobic'], true);
    $offset = $isHighSolubility ? -1 : 0;

    if (preg_match('/citrus|orange|lemon|lime|bergamot|jeruk|grapefruit|blackberry|berry|plum|hibiscus|acidity|asam/', $lower) === 1) {
        $suhu = 93 + $offset;
        return [
            'rasa'       => $cleanNote,
            'kategori'   => 'Fruity Cerah & Citrus',
            'suhu'       => $suhu,
            'rentang'    => ($suhu - 1) . '°C–' . ($suhu + 1) . '°C',
            'penjelasan' => "Suhu {$suhu}°C memberi energi ekstraksi tinggi untuk mengangkat aroma buah segar & keasaman cerah {$cleanNote}.",
        ];
    }

    if (preg_match('/jasmine|floral|bunga|tea|teh|peach|lychee|leci|apricot|apple|pineapple|nanas|tropical|melon/', $lower) === 1) {
        $suhu = 91 + $offset;
        return [
            'rasa'       => $cleanNote,
            'kategori'   => 'Floral, Teh & Buah Segar',
            'suhu'       => $suhu,
            'rentang'    => ($suhu - 1) . '°C–' . ($suhu + 1) . '°C',
            'penjelasan' => "Suhu {$suhu}°C optimal melarutkan aroma wangi & manis buah halus {$cleanNote} tanpa membuat aftertaste sepat.",
        ];
    }

    if (preg_match('/caramel|karamel|brown sugar|palm sugar|cane sugar|gula|sugar|honey|madu|molasses|vanilla|sweet/', $lower) === 1) {
        $suhu = 89 + $offset;
        return [
            'rasa'       => $cleanNote,
            'kategori'   => 'Manis Alami & Karamel',
            'suhu'       => $suhu,
            'rentang'    => ($suhu - 1) . '°C–' . ($suhu + 1) . '°C',
            'penjelasan' => "Suhu {$suhu}°C menonjolkan rasa manis {$cleanNote} agar terasa bulat di lidah tanpa over-extraction.",
        ];
    }

    if (preg_match('/chocolate|choco|cocoa|cokelat|almond|nut|kacang|roasted|cedar|spice|rempah|herbal|cinnamon|kayu|earthy/', $lower) === 1) {
        $suhu = 88 + $offset;
        return [
            'rasa'       => $cleanNote,
            'kategori'   => 'Body Tebal, Cokelat & Rempah',
            'suhu'       => $suhu,
            'rentang'    => ($suhu - 1) . '°C–' . ($suhu + 1) . '°C',
            'penjelasan' => "Suhu lebih tenang ({$suhu}°C) meredam keasaman sehingga tekstur creamy & rasa hangat {$cleanNote} lebih dominan.",
        ];
    }

    $suhu = 90 + $offset;
    return [
        'rasa'       => $cleanNote,
        'kategori'   => 'Karakter Seimbang',
        'suhu'       => $suhu,
        'rentang'    => ($suhu - 1) . '°C–' . ($suhu + 1) . '°C',
        'penjelasan' => "Suhu {$suhu}°C menghasilkan ekstraksi seimbang untuk menonjolkan karakter {$cleanNote}.",
    ];
}

/**
 * Menghasilkan daftar pilihan fokus rasa beserta suhu air seduh yang dianjurkan
 * berdasarkan kolom tasting_notes & proses pasca-panen biji kopi.
 *
 * @return array<int, array{rasa: string, kategori: string, suhu: int, rentang: string, penjelasan: string}>
 */
function buildBeanFlavorTargets(string $tastingNotes, string $proses = 'Washed'): array
{
    $parts = array_values(array_filter(array_map('trim', explode(',', $tastingNotes))));
    if (empty($parts)) {
        $parts = ['Seimbang (Balanced Cup)'];
    }

    $targets = [];
    foreach ($parts as $note) {
        $targets[] = resolveSingleNoteBrewTemp($note, $proses);
    }

    return $targets;
}

/**
 * Memastikan pilihan fokus rasa cocok dengan salah satu tasting note bean terkait,
 * lalu mengembalikan profil suhu seduhnya.
 *
 * @return array{rasa: string, kategori: string, suhu: int, rentang: string, penjelasan: string}
 */
function matchBeanFlavorTarget(string $tastingNotes, string $proses, string $requestedFlavor): array
{
    $targets = buildBeanFlavorTargets($tastingNotes, $proses);
    $cleanReq = strtolower(trim($requestedFlavor));

    if ($cleanReq !== '') {
        foreach ($targets as $t) {
            if (strtolower($t['rasa']) === $cleanReq) {
                return $t;
            }
        }
    }

    return $targets[0];
}

/**
 * Memastikan kolom stok_porsi pada tabel metode_seduh tersedia di database.
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
    }

    $checked = true;
}

/**
 * Menambahkan opsi teknik penyeduhan lanjutan (French Press, Americano, Latte)
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
 * Menghapus metode Cold Brew dari database bila belum pernah terpakai di tabel pesanan,
 * serta menyaringnya dari daftar metode aktif agar riwayat pesanan lama tetap aman.
 *
 * @param array<int, array<string, mixed>> $methods
 * @return array<int, array<string, mixed>>
 */
function removeColdBrewMethod(PDO $pdo, array $methods): array
{
    $hasColdBrew = false;
    foreach ($methods as $m) {
        if (str_contains(strtolower((string) ($m['nama_metode'] ?? '')), 'cold brew')) {
            $hasColdBrew = true;
            break;
        }
    }

    if (!$hasColdBrew) {
        return $methods;
    }

    $pdo->exec(
        "DELETE FROM metode_seduh
         WHERE LOWER(nama_metode) LIKE '%cold brew%'
           AND NOT EXISTS (
               SELECT 1 FROM pesanan p WHERE p.metode_seduh_id = metode_seduh.id
           )"
    );

    return array_values(array_filter(
        $methods,
        static fn(array $m): bool => !str_contains(strtolower((string) ($m['nama_metode'] ?? '')), 'cold brew')
    ));
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
            ['Aceh Gayo Pantan Musara',    45000, 22000, 320000],
            ['Flores Bajawa Wogo',        38000, 18000, 240000],
            ['Bali Kintamani Ulian',      55000, 21000, 290000],
            ['Java Preanger Gunung Halu', 42000, 20000, 280000],
            ['Toraja Sapan Minanga',      40000, 19000, 260000],
            ['Papua Wamena',              60000, 24000, 340000],
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
 * Menyesuaikan data seed biji kopi lama agar seluruh katalog memakai nama varian,
 * origin, ketinggian, dan tasting notes single origin Indonesia yang nyata di dunia nyata,
 * tanpa memutus relasi pesanan historis. Jika $preloadedBeans disediakan, pengecekan
 * dilakukan di memori agar tidak menambah query DB pada setiap request normal.
 *
 * @param array<int, array<string, mixed>>|null $preloadedBeans
 * @return array<int, array<string, mixed>>
 */
function syncIndonesianBeansCatalog(PDO $pdo, ?array $preloadedBeans = null): array
{
    static $synced = false;
    if ($synced) {
        return $preloadedBeans ?? [];
    }

    $legacyNames = [
        'Flores Bajawa Ngura',
        'Ethiopia Yirgacheffe Chelchele',
        'Kenya Nyeri Kiandu AA',
        'Papua Wamena Kurima',
    ];
    $legacyPairs = [
        'Aceh Gayo Pantan Musara'   => 'Aceh Tengah • 1.650 mdpl',
        'Bali Kintamani Ulian'      => 'Bangli, Bali • 1.450 mdpl',
        'Java Preanger Gunung Halu' => 'Bandung Barat • 1.500 mdpl',
        'Toraja Sapan Minanga'      => 'Toraja Utara • 1.700 mdpl',
    ];

    $needsUpdate = false;
    if ($preloadedBeans !== null) {
        foreach ($preloadedBeans as $b) {
            $bName = (string) ($b['nama_kopi'] ?? '');
            $bOrigin = (string) ($b['origin'] ?? '');
            if (in_array($bName, $legacyNames, true) || (isset($legacyPairs[$bName]) && $legacyPairs[$bName] === $bOrigin)) {
                $needsUpdate = true;
                break;
            }
        }
    } else {
        $needsUpdate = (bool) $pdo->query(
            "SELECT 1 FROM biji_kopi
             WHERE nama_kopi IN ('Flores Bajawa Ngura', 'Ethiopia Yirgacheffe Chelchele', 'Kenya Nyeri Kiandu AA', 'Papua Wamena Kurima')
                OR (nama_kopi = 'Aceh Gayo Pantan Musara' AND origin = 'Aceh Tengah • 1.650 mdpl')
                OR (nama_kopi = 'Bali Kintamani Ulian' AND origin = 'Bangli, Bali • 1.450 mdpl')
                OR (nama_kopi = 'Java Preanger Gunung Halu' AND origin = 'Bandung Barat • 1.500 mdpl')
                OR (nama_kopi = 'Toraja Sapan Minanga' AND origin = 'Toraja Utara • 1.700 mdpl')
             LIMIT 1"
        )->fetchColumn();
    }

    if (!$needsUpdate) {
        $synced = true;
        return $preloadedBeans ?? [];
    }

    $updates = [
        [
            'match_nama'   => 'Aceh Gayo Pantan Musara',
            'match_origin' => 'Aceh Tengah • 1.650 mdpl',
            'nama_kopi'    => 'Aceh Gayo Pantan Musara',
            'origin'       => 'Pegasing, Aceh Tengah • 1.650 mdpl',
            'proses'       => 'Anaerobic',
            'notes'        => 'Citrus, Lychee, Brown Sugar',
            'harga'        => 22000,
            'harga_per_kg' => 320000,
        ],
        [
            'match_nama'   => 'Flores Bajawa Ngura',
            'match_origin' => null,
            'nama_kopi'    => 'Flores Bajawa Wogo',
            'origin'       => 'Golewa, Ngada, NTT • 1.500 mdpl',
            'proses'       => 'Washed',
            'notes'        => 'Milk Chocolate, Caramel, Roasted Almond',
            'harga'        => 18000,
            'harga_per_kg' => 240000,
        ],
        [
            'match_nama'   => 'Ethiopia Yirgacheffe Chelchele',
            'match_origin' => null,
            'nama_kopi'    => 'Bali Kintamani Ulian',
            'origin'       => 'Kintamani, Bangli • 1.350 mdpl',
            'proses'       => 'Washed',
            'notes'        => 'Orange Citrus, Jasmine, Cane Sugar',
            'harga'        => 21000,
            'harga_per_kg' => 290000,
        ],
        [
            'match_nama'   => 'Bali Kintamani Ulian',
            'match_origin' => 'Bangli, Bali • 1.450 mdpl',
            'nama_kopi'    => 'Bali Kintamani Ulian',
            'origin'       => 'Kintamani, Bangli • 1.350 mdpl',
            'proses'       => 'Washed',
            'notes'        => 'Orange Citrus, Jasmine, Cane Sugar',
            'harga'        => 21000,
            'harga_per_kg' => 290000,
        ],
        [
            'match_nama'   => 'Java Preanger Gunung Halu',
            'match_origin' => 'Bandung Barat • 1.500 mdpl',
            'nama_kopi'    => 'Java Preanger Gunung Halu',
            'origin'       => 'Gunung Halu, Bandung Barat • 1.450 mdpl',
            'proses'       => 'Honey',
            'notes'        => 'Peach, Black Tea, Palm Sugar',
            'harga'        => 20000,
            'harga_per_kg' => 280000,
        ],
        [
            'match_nama'   => 'Toraja Sapan Minanga',
            'match_origin' => 'Toraja Utara • 1.700 mdpl',
            'nama_kopi'    => 'Toraja Sapan Minanga',
            'origin'       => 'Sapan Minanga, Toraja Utara • 1.700 mdpl',
            'proses'       => 'Natural',
            'notes'        => 'Dark Chocolate, Brown Sugar, Cedar Spice',
            'harga'        => 19000,
            'harga_per_kg' => 260000,
        ],
        [
            'match_nama'   => 'Kenya Nyeri Kiandu AA',
            'match_origin' => null,
            'nama_kopi'    => 'Papua Wamena',
            'origin'       => 'Lembah Baliem, Jayawijaya • 1.600 mdpl',
            'proses'       => 'Washed',
            'notes'        => 'Brown Sugar, Milk Chocolate, Herbal Spice',
            'harga'        => 24000,
            'harga_per_kg' => 340000,
        ],
        [
            'match_nama'   => 'Papua Wamena Kurima',
            'match_origin' => null,
            'nama_kopi'    => 'Papua Wamena',
            'origin'       => 'Lembah Baliem, Jayawijaya • 1.600 mdpl',
            'proses'       => 'Washed',
            'notes'        => 'Brown Sugar, Milk Chocolate, Herbal Spice',
            'harga'        => 24000,
            'harga_per_kg' => 340000,
        ],
    ];

    $stmtByName = $pdo->prepare(
        'UPDATE biji_kopi
         SET nama_kopi = :nama,
             origin = :origin,
             proses = :proses,
             tasting_notes = :notes,
             harga = :harga,
             harga_per_kg = :harga_kg
         WHERE nama_kopi = :match_nama'
    );
    $stmtByNameOrigin = $pdo->prepare(
        'UPDATE biji_kopi
         SET nama_kopi = :nama,
             origin = :origin,
             proses = :proses,
             tasting_notes = :notes,
             harga = :harga,
             harga_per_kg = :harga_kg
         WHERE nama_kopi = :match_nama AND origin = :match_origin'
    );

    foreach ($updates as $row) {
        $params = [
            ':nama'       => $row['nama_kopi'],
            ':origin'     => $row['origin'],
            ':proses'     => $row['proses'],
            ':notes'      => $row['notes'],
            ':harga'      => $row['harga'],
            ':harga_kg'   => $row['harga_per_kg'],
            ':match_nama' => $row['match_nama'],
        ];
        if ($row['match_origin'] !== null) {
            $params[':match_origin'] = $row['match_origin'];
            $stmtByNameOrigin->execute($params);
        } else {
            $stmtByName->execute($params);
        }
    }

    $synced = true;

    if ($preloadedBeans !== null) {
        return $pdo->query(
            'SELECT id, nama_kopi, origin, proses, tasting_notes, stok_gram, harga, harga_per_kg, gambar_url
             FROM biji_kopi
             ORDER BY id ASC'
        )->fetchAll();
    }

    return [];
}
