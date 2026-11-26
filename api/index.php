<?php
/**
 * Halaman Pelanggan — Kala Seduh Slow Bar
 * Katalog Biji Kopi, Form Pemesanan Interaktif (Transaction + Auto-Deduct Stok),
 * dan Antrean Seduh Live.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

$currentUser = getCurrentUser();
$flash = consumeFlashMessage();
$dbError = null;
$beans = [];
$methods = [];
$queueOrders = [];

try {
    $pdo = getDbConnection();

    ensureBijiKopiHargaPerKgColumn($pdo);
    syncIndonesianBeansCatalog($pdo);
    ensureMetodeSeduhStockColumn($pdo);

    $beans = $pdo->query(
        'SELECT id, nama_kopi, origin, proses, tasting_notes, stok_gram, harga, harga_per_kg, gambar_url
         FROM biji_kopi
         ORDER BY CASE WHEN stok_gram > 0 THEN 0 ELSE 1 END, id ASC'
    )->fetchAll();

    $methods = $pdo->query(
        'SELECT id, nama_metode, rasio_kopi_gram, estimasi_menit, biaya_tambahan, stok_porsi
         FROM metode_seduh
         ORDER BY id ASC'
    )->fetchAll();
    $methods = syncExtraDefaultMethods($pdo, $methods);

    $queueOrders = $pdo->query(
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
    )->fetchAll();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$minRatio = 15;
if (!empty($methods)) {
    $ratios = array_map(static fn(array $m): int => (int) $m['rasio_kopi_gram'], $methods);
    $minRatio = min($ratios);
}

$featuredBean = null;
foreach ($beans as $b) {
    if ((int) $b['stok_gram'] >= $minRatio) {
        $featuredBean = $b;
        break;
    }
}
if ($featuredBean === null && !empty($beans)) {
    $featuredBean = $beans[0];
}

$groupedQueue = [
    'Menunggu'       => [],
    'Sedang Diseduh' => [],
    'Selesai'        => [],
];
foreach ($queueOrders as $ord) {
    $st = (string) $ord['status'];
    if (isset($groupedQueue[$st])) {
        $groupedQueue[$st][] = $ord;
    }
}
$activeQueueCount = count($groupedQueue['Menunggu']) + count($groupedQueue['Sedang Diseduh']);

$prosesList = ['Natural', 'Washed', 'Honey', 'Anaerobic'];
$prosesCounts = array_fill_keys($prosesList, 0);
foreach ($beans as $b) {
    $p = (string) $b['proses'];
    if (isset($prosesCounts[$p])) {
        $prosesCounts[$p]++;
    }
}

$beansJson = json_encode(
    array_map(static fn(array $b): array => [
        'id'            => (int) $b['id'],
        'nama_kopi'     => (string) $b['nama_kopi'],
        'origin'        => (string) $b['origin'],
        'proses'        => (string) $b['proses'],
        'tasting_notes' => (string) $b['tasting_notes'],
        'stok_gram'     => (int) $b['stok_gram'],
        'harga'         => (int) $b['harga'],
        'harga_per_kg'  => (int) ($b['harga_per_kg'] ?? 250000),
        'gambar_url'    => resolveBeanImageUrl((string) $b['gambar_url']),
    ], $beans),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
);

$methodsJson = json_encode(
    array_map(static function (array $m): array {
        $profile = getMethodProfile((string) $m['nama_metode']);
        return [
            'id'              => (int) $m['id'],
            'nama_metode'     => (string) $m['nama_metode'],
            'rasio_kopi_gram' => (int) $m['rasio_kopi_gram'],
            'estimasi_menit'  => (int) $m['estimasi_menit'],
            'biaya_tambahan'  => (int) $m['biaya_tambahan'],
            'stok_porsi'      => $m['stok_porsi'] !== null ? (int) $m['stok_porsi'] : null,
            'kategori'        => $profile['kategori'],
            'dampak_badge'    => $profile['dampak_badge'],
            'perbedaan'       => $profile['perbedaan'],
            'pengaruh_rasa'   => $profile['pengaruh_rasa'],
        ];
    }, $methods),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
);

$pageTitle = 'Kala Seduh — Slow Bar & Katalog Manual Brew';
require __DIR__ . '/partials/head.php';
?>
<body class="bg-cream text-espresso antialiased min-h-screen flex flex-col">
  <!-- Header Navigasi Tetap -->
  <header class="sticky top-0 z-40 w-full bg-surface/95 backdrop-blur-md border-b border-sand">
    <div class="max-w-7xl mx-auto px-5 lg:px-12 h-20 flex items-center justify-between gap-4">
      <a href="/" class="flex items-center gap-3">
        <div class="w-10 h-10 rounded bg-espresso text-cream flex items-center justify-center shadow-sm">
          <span class="material-symbols-outlined text-[22px]">coffee_maker</span>
        </div>
        <div class="flex flex-col">
          <span class="font-editorial text-2xl font-medium text-espresso leading-none tracking-wide">Kala Seduh</span>
          <span class="text-[10px] uppercase tracking-[0.2em] text-muted font-semibold mt-1">Slow Bar &amp; Ritual</span>
        </div>
      </a>

      <nav class="hidden md:flex items-center gap-7 text-sm font-medium" id="main-nav">
        <a href="#katalog-biji" class="nav-link text-espresso font-semibold border-b-2 border-terracotta pb-1 transition-colors whitespace-nowrap">Katalog Beans</a>
        <a href="#teknik-seduh" class="nav-link text-muted hover:text-espresso border-b-2 border-transparent pb-1 transition-colors whitespace-nowrap">Teknik Seduh</a>
        <a href="#antrean-section" class="nav-link text-muted hover:text-espresso border-b-2 border-transparent pb-1 transition-colors whitespace-nowrap">Antrean Live</a>
        <a href="#filosofi-section" class="nav-link text-muted hover:text-espresso border-b-2 border-transparent pb-1 transition-colors whitespace-nowrap">Filosofi Rasa</a>
      </nav>

      <div class="flex items-center gap-2.5 shrink-0">
        <button
          type="button"
          onclick="openOrderDrawer()"
          class="hidden sm:inline-flex items-center gap-1.5 px-4 py-2 rounded bg-terracotta hover:bg-terracotta-dark text-white text-xs font-semibold uppercase tracking-wider transition-colors shadow-sm whitespace-nowrap"
        >
          <span class="material-symbols-outlined text-[16px]">local_cafe</span>
          <span>Seduh Sekarang</span>
        </button>

        <?php if ($currentUser !== null): ?>
          <a href="/admin" class="inline-flex items-center gap-1.5 px-4 py-2 rounded bg-espresso hover:bg-[#3D2C22] text-cream text-xs font-semibold transition-colors whitespace-nowrap">
            <span class="material-symbols-outlined text-[16px]">dashboard</span>
            <span>Dashboard (<?= e($currentUser['username']) ?>)</span>
          </a>
        <?php else: ?>
          <a href="/login" class="inline-flex items-center gap-1.5 px-4 py-2 rounded bg-surface-high hover:bg-sand text-espresso border border-sand text-xs font-semibold transition-colors whitespace-nowrap">
            <span class="material-symbols-outlined text-[16px]">person</span>
            <span>Portal Barista</span>
          </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Navigasi Ringkas Mobile -->
    <div class="md:hidden border-t border-sand/80 bg-surface-low/90 px-4 py-2 flex items-center justify-between gap-2 overflow-x-auto text-xs font-semibold">
      <div class="flex items-center gap-4 whitespace-nowrap">
        <a href="#katalog-biji" class="text-espresso">Katalog Beans</a>
        <a href="#teknik-seduh" class="text-muted hover:text-espresso">Teknik Seduh</a>
        <a href="#antrean-section" class="text-muted hover:text-espresso">Antrean Live</a>
        <a href="#filosofi-section" class="text-muted hover:text-espresso">Filosofi</a>
      </div>
      <button type="button" onclick="openOrderDrawer()" class="sm:hidden shrink-0 px-3 py-1 rounded bg-terracotta text-white text-[11px] font-semibold">
        Seduh
      </button>
    </div>
  </header>

  <!-- Bar Informasi Kalibrasi Pagi -->
  <div class="w-full bg-surface-high border-b border-sand py-2 px-5 text-center">
    <p class="text-xs text-muted flex items-center justify-center gap-2 flex-wrap">
      <span class="inline-block w-2 h-2 rounded-full bg-terracotta animate-pulse"></span>
      <span>Bar Kalibrasi Pagi Selesai • Suhu Air Ideal 91°C–93°C • Rasio Manual Brew Presisi</span>
    </p>
  </div>

  <main class="flex-1 w-full">
    <div class="max-w-7xl mx-auto px-5 lg:px-12 py-10 space-y-16">

      <?php if ($flash !== null): ?>
        <?php
          $flashClasses = match ($flash['type']) {
              'success' => 'bg-sage-soft border-[#C2D6CD] text-sage-text',
              'error'   => 'bg-[#FFEBEE] border-[#F5C6CB] text-[#93000A]',
              default   => 'bg-surface-mid border-sand text-espresso',
          };
        ?>
        <div class="px-5 py-3.5 rounded-lg border text-sm font-medium flex items-center gap-2.5 <?= $flashClasses ?>">
          <span class="material-symbols-outlined text-[20px]">
            <?= $flash['type'] === 'success' ? 'check_circle' : ($flash['type'] === 'error' ? 'error' : 'info') ?>
          </span>
          <span><?= e($flash['message']) ?></span>
        </div>
      <?php endif; ?>

      <?php if ($dbError !== null): ?>
        <div class="bg-[#FFEBEE] border border-[#F5C6CB] text-[#93000A] rounded-xl p-6 space-y-2">
          <div class="flex items-center gap-2 font-semibold">
            <span class="material-symbols-outlined">database</span>
            <span>Koneksi Database Supabase Belum Tersambung</span>
          </div>
          <p class="text-sm"><?= e($dbError) ?></p>
          <p class="text-xs opacity-90">
            Pastikan Anda telah mengimpor <code class="px-1.5 py-0.5 rounded bg-white/70 font-mono">database.sql</code> ke Supabase SQL Editor dan mengatur variabel <code class="px-1.5 py-0.5 rounded bg-white/70 font-mono">DB_HOST</code>, <code class="px-1.5 py-0.5 rounded bg-white/70 font-mono">DB_PORT</code>, <code class="px-1.5 py-0.5 rounded bg-white/70 font-mono">DB_NAME</code>, <code class="px-1.5 py-0.5 rounded bg-white/70 font-mono">DB_USER</code>, dan <code class="px-1.5 py-0.5 rounded bg-white/70 font-mono">DB_PASS</code>.
          </p>
        </div>
      <?php endif; ?>

      <!-- Section 1: Hero Editorial Slow Bar -->
      <section class="grid grid-cols-1 lg:grid-cols-12 gap-8 lg:gap-12 items-center">
        <div class="lg:col-span-6 space-y-6">
          <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-surface-high border border-sand">
            <span class="material-symbols-outlined text-[15px] text-terracotta">water_drop</span>
            <span class="text-[11px] font-semibold uppercase tracking-[0.14em] text-muted">Ritual Slow Bar • Batch Seduh Mandiri</span>
          </div>

          <h1 class="font-editorial text-4xl sm:text-5xl lg:text-[52px] text-espresso leading-[1.12] tracking-tight">
            Seduh Perlahan,<br />
            <span class="italic font-normal text-[#4E3829]">Nikmati Karakter Rasa.</span>
          </h1>

          <p class="text-base text-muted leading-relaxed max-w-xl">
            Ruang jeda di mana setiap cangkir diseduh manual dengan presisi, menghargai perjalanan biji kopi dari lereng pegunungan hingga cangkir keramik Anda.
          </p>

          <!-- Quick Stat Chips -->
          <div class="flex flex-wrap gap-3 pt-1">
            <div class="flex items-center gap-2.5 px-4 py-2.5 bg-paper border border-sand rounded-lg">
              <span class="material-symbols-outlined text-terracotta text-[20px]">thermostat</span>
              <div>
                <span class="block text-[10px] uppercase tracking-wider text-muted font-semibold">Suhu Seduh</span>
                <span class="text-sm font-bold text-espresso tabular-nums">92.0°C</span>
              </div>
            </div>
            <div class="flex items-center gap-2.5 px-4 py-2.5 bg-paper border border-sand rounded-lg">
              <span class="material-symbols-outlined text-terracotta text-[20px]">scale</span>
              <div>
                <span class="block text-[10px] uppercase tracking-wider text-muted font-semibold">Rasio Dasar</span>
                <span class="text-sm font-bold text-espresso tabular-nums">1 : 15 (15g / 225ml)</span>
              </div>
            </div>
            <div class="flex items-center gap-2.5 px-4 py-2.5 bg-paper border border-sand rounded-lg">
              <span class="material-symbols-outlined text-terracotta text-[20px]">timer</span>
              <div>
                <span class="block text-[10px] uppercase tracking-wider text-muted font-semibold">Ekstraksi</span>
                <span class="text-sm font-bold text-espresso tabular-nums">3–7 Menit</span>
              </div>
            </div>
          </div>

          <div class="flex flex-wrap items-center gap-3 pt-2">
            <a href="#katalog-biji" class="inline-flex items-center gap-2 px-5 py-3 rounded bg-espresso hover:bg-[#3D2C22] text-cream text-sm font-semibold transition-colors shadow-sm">
              <span>Pilih Beans</span>
              <span class="material-symbols-outlined text-[18px]">arrow_downward</span>
            </a>
            <a href="#antrean-section" class="inline-flex items-center gap-2 px-5 py-3 rounded bg-paper hover:bg-surface-high text-espresso border border-sand text-sm font-semibold transition-colors">
              <span>Pantau Antrean (<span id="hero-active-count"><?= $activeQueueCount ?></span> Aktif)</span>
            </a>
          </div>
        </div>

        <!-- Hero Visual Spotlight Card -->
        <div class="lg:col-span-6">
          <?php $heroImg = resolveBeanImageUrl($featuredBean['gambar_url'] ?? null); ?>
          <div class="relative rounded-xl overflow-hidden bg-[#231710] border border-sand shadow-ambient">
            <div class="relative h-[380px] sm:h-[460px] w-full bg-[#231710] overflow-hidden">
              <img
                src="<?= e($heroImg) ?>"
                alt=""
                aria-hidden="true"
                class="absolute inset-0 w-full h-full object-cover blur-2xl scale-125 opacity-55 pointer-events-none"
                onerror="this.style.display='none'"
              />
              <img
                src="<?= e($heroImg) ?>"
                alt="<?= e($featuredBean['nama_kopi'] ?? 'Beans Kala Seduh') ?>"
                class="relative z-10 w-full h-full object-contain"
                onerror="this.style.display='none'"
              />
              <div class="absolute inset-0 z-20 bg-gradient-to-t from-espresso-dark/90 via-espresso-dark/25 to-transparent pointer-events-none"></div>

              <div class="absolute top-4 left-4 right-4 z-30 flex items-center justify-between gap-2 flex-wrap">
                <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/90 backdrop-blur-md text-espresso text-[11px] font-semibold uppercase tracking-wider">
                  <span class="w-2 h-2 rounded-full bg-terracotta animate-pulse"></span>
                  Kurasi Beans Hari Ini
                </span>
                <?php if ($featuredBean !== null): ?>
                  <span class="px-3 py-1 rounded-full bg-espresso/80 backdrop-blur-md text-cream font-editorial italic text-sm">
                    Tersedia • <?= (int) $featuredBean['stok_gram'] ?>g
                  </span>
                <?php endif; ?>
              </div>

              <div class="absolute bottom-0 left-0 right-0 z-30 p-6 text-cream space-y-2">
                <?php if ($featuredBean !== null): ?>
                  <span class="text-[11px] uppercase tracking-[0.16em] text-[#DAC2B5] block font-semibold">
                    <?= e($featuredBean['origin']) ?> • <?= e($featuredBean['proses']) ?>
                  </span>
                  <h2 class="font-editorial text-3xl font-medium text-white">
                    <?= e($featuredBean['nama_kopi']) ?>
                  </h2>
                  <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
                    <div class="flex flex-wrap items-center gap-1.5">
                      <?php foreach (array_filter(array_map('trim', explode(',', (string) $featuredBean['tasting_notes']))) as $note): ?>
                        <span class="text-xs px-2.5 py-0.5 rounded-full bg-white/15 backdrop-blur-sm text-cream border border-white/20">
                          <?= e($note) ?>
                        </span>
                      <?php endforeach; ?>
                    </div>
                    <button
                      type="button"
                      onclick="openOrderDrawer(<?= (int) $featuredBean['id'] ?>)"
                      class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg bg-terracotta hover:bg-terracotta-dark text-white text-xs font-semibold transition-colors shadow-sm"
                    >
                      <span>Pilih Beans Ini</span>
                      <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                    </button>
                  </div>
                <?php else: ?>
                  <h2 class="font-editorial text-3xl font-medium text-white">Kala Seduh Slow Bar</h2>
                  <p class="text-sm text-[#DAC2B5]">Single origin Nusantara pilihan diseduh manual untuk Anda.</p>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Section 2: Antrean Seduh Live -->
      <section id="antrean-section" class="scroll-mt-28 bg-paper border border-sand rounded-xl p-6 lg:p-8 shadow-sm space-y-6">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-sand pb-4">
          <div class="flex items-start gap-3">
            <span class="w-3 h-3 rounded-full bg-terracotta animate-pulse mt-1.5 shrink-0"></span>
            <div>
              <h2 class="font-editorial text-2xl text-espresso font-medium">Antrean Seduh Live</h2>
              <p class="text-sm text-muted">
                Transparansi status penyeduhan real-time • Diperbarui otomatis setiap 5 detik
              </p>
            </div>
          </div>
          <div class="flex items-center gap-3 flex-wrap">
            <span class="px-3 py-1 rounded-full bg-surface-mid border border-sand text-xs font-semibold text-espresso tabular-nums" id="live-clock-badge">
              Waktu Bar: <?= date('H:i') ?> WIB
            </span>
            <button type="button" onclick="refreshLiveQueue(true)" class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-surface-high hover:bg-sand text-xs font-semibold text-espresso transition-colors">
              <span class="material-symbols-outlined text-[15px]">sync</span>
              <span>Segarkan</span>
            </button>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5" id="live-queue-grid">
          <!-- Kolom 1: Menunggu -->
          <div class="bg-surface-low border border-sand rounded-lg p-4 space-y-3">
            <div class="flex items-center justify-between">
              <span class="text-xs font-bold uppercase tracking-wider text-espresso flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[17px] text-outline">hourglass_empty</span>
                Menunggu (<span id="count-menunggu"><?= count($groupedQueue['Menunggu']) ?></span>)
              </span>
              <span class="text-[11px] text-muted">Dalam Antrean</span>
            </div>
            <div class="space-y-2.5" id="col-menunggu">
              <?php if (empty($groupedQueue['Menunggu'])): ?>
                <p class="text-xs text-muted italic py-4 text-center">Belum ada pesanan menunggu.</p>
              <?php else: ?>
                <?php foreach ($groupedQueue['Menunggu'] as $ord): ?>
                  <div class="bg-white border border-sand rounded p-3 space-y-1">
                    <div class="flex items-center justify-between gap-2">
                      <span class="text-xs font-bold text-espresso tabular-nums"><?= e($ord['kode_pesanan']) ?> • <?= e(formatJamSingkat((string) $ord['created_at'])) ?></span>
                      <span class="text-[11px] px-2 py-0.5 rounded bg-surface-mid text-muted font-semibold"><?= e($ord['nama_metode']) ?></span>
                    </div>
                    <p class="text-sm font-semibold text-espresso"><?= e($ord['nama_kopi']) ?></p>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <!-- Kolom 2: Sedang Diseduh -->
          <div class="bg-white border border-[#F3D1C4] rounded-lg p-4 space-y-3 shadow-sm">
            <div class="flex items-center justify-between">
              <span class="text-xs font-bold uppercase tracking-wider text-terracotta flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-terracotta animate-pulse"></span>
                Sedang Diseduh (<span id="count-seduh"><?= count($groupedQueue['Sedang Diseduh']) ?></span>)
              </span>
              <span class="text-[11px] font-semibold text-terracotta">Pouring Live</span>
            </div>
            <div class="space-y-2.5" id="col-seduh">
              <?php if (empty($groupedQueue['Sedang Diseduh'])): ?>
                <p class="text-xs text-muted italic py-4 text-center">Barista siap menyeduh pesanan berikutnya.</p>
              <?php else: ?>
                <?php foreach ($groupedQueue['Sedang Diseduh'] as $ord): ?>
                  <div class="bg-terracotta-soft/70 border border-[#F3D1C4] rounded p-3 space-y-1">
                    <div class="flex items-center justify-between gap-2">
                      <span class="text-xs font-bold text-[#8F3F27] tabular-nums"><?= e($ord['kode_pesanan']) ?> • <?= e(formatJamSingkat((string) $ord['created_at'])) ?></span>
                      <span class="text-[11px] px-2 py-0.5 rounded bg-terracotta text-white font-semibold"><?= e($ord['nama_metode']) ?></span>
                    </div>
                    <p class="text-sm font-semibold text-espresso"><?= e($ord['nama_kopi']) ?></p>
                    <p class="text-xs text-[#772F18]">Sedang diracik barista • Est. ~<?= (int) $ord['estimasi_menit'] ?> menit</p>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <!-- Kolom 3: Selesai -->
          <div class="bg-surface-low border border-sand rounded-lg p-4 space-y-3">
            <div class="flex items-center justify-between">
              <span class="text-xs font-bold uppercase tracking-wider text-sage-text flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[17px] text-sage-text">check_circle</span>
                Siap &amp; Selesai (<span id="count-selesai"><?= count($groupedQueue['Selesai']) ?></span>)
              </span>
              <span class="text-[11px] text-muted">Hari Ini</span>
            </div>
            <div class="space-y-2.5" id="col-selesai">
              <?php if (empty($groupedQueue['Selesai'])): ?>
                <p class="text-xs text-muted italic py-4 text-center">Belum ada pesanan selesai hari ini.</p>
              <?php else: ?>
                <?php foreach ($groupedQueue['Selesai'] as $ord): ?>
                  <div class="bg-sage-soft/70 border border-[#C2D6CD] rounded p-3 space-y-1">
                    <div class="flex items-center justify-between gap-2">
                      <span class="text-xs font-bold text-espresso tabular-nums"><?= e($ord['kode_pesanan']) ?> • <?= e(formatJamSingkat((string) $ord['created_at'])) ?></span>
                      <span class="text-[11px] px-2 py-0.5 rounded-full bg-white text-sage-text border border-[#C2D6CD] font-semibold">Siap Disajikan</span>
                    </div>
                    <p class="text-sm font-medium text-espresso"><?= e($ord['nama_kopi']) ?> • <?= e($ord['nama_metode']) ?></p>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </section>

      <!-- Section 3: Katalog Biji Kopi -->
      <section id="katalog-biji" class="scroll-mt-28 space-y-6">
        <div class="space-y-4">
          <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
            <div class="space-y-1.5">
              <span class="text-[11px] font-bold uppercase tracking-[0.18em] text-terracotta block">Langkah 1 • Katalog Beans</span>
              <h2 class="font-editorial text-3xl sm:text-4xl text-espresso font-medium leading-tight">Pilih Beans Favorit Anda</h2>
              <p class="text-sm text-muted max-w-2xl">
                Pilih biji kopi Nusantara yang tersedia, lalu tentukan teknik penyeduhan manual yang diinginkan.
              </p>
            </div>

            <div class="relative w-full md:w-72 shrink-0">
              <span class="material-symbols-outlined text-[18px] text-outline absolute left-3.5 top-1/2 -translate-y-1/2">search</span>
              <input
                type="text"
                id="bean-search-input"
                oninput="applyCatalogFilters()"
                placeholder="Cari biji, origin, atau tasting notes..."
                class="w-full h-11 pl-10 pr-4 rounded-lg bg-white border border-sand text-sm text-espresso placeholder:text-outline focus:outline-none focus:border-espresso focus:ring-1 focus:ring-espresso transition"
              />
            </div>
          </div>

          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-paper border border-sand rounded-xl px-4 py-3">
            <div class="flex flex-wrap items-center gap-2" id="filter-container">
              <button type="button" onclick="setProcessFilter('all')" data-proses="all" class="filter-tab px-3.5 py-1.5 rounded-full bg-espresso text-cream text-xs font-semibold transition-colors whitespace-nowrap">
                Semua (<?= count($beans) ?>)
              </button>
              <?php foreach ($prosesList as $prosesName): ?>
                <button type="button" onclick="setProcessFilter('<?= e($prosesName) ?>')" data-proses="<?= e($prosesName) ?>" class="filter-tab px-3.5 py-1.5 rounded-full bg-surface-low border border-sand hover:bg-surface-high text-espresso text-xs font-semibold transition-colors whitespace-nowrap">
                  <?= e($prosesName) ?> (<?= $prosesCounts[$prosesName] ?>)
                </button>
              <?php endforeach; ?>
            </div>

            <span id="catalog-count-label" class="text-xs text-muted font-medium whitespace-nowrap tabular-nums">
              Menampilkan <?= count($beans) ?> dari <?= count($beans) ?> varian
            </span>
          </div>
        </div>

        <?php if (empty($beans)): ?>
          <div class="bg-paper border border-sand rounded-xl p-10 text-center text-muted">
            Belum ada data biji kopi di katalog. Silakan tambahkan melalui Dashboard Admin.
          </div>
        <?php else: ?>
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-7" id="beans-grid">
            <?php foreach ($beans as $bean): ?>
              <?php
                $beanId = (int) $bean['id'];
                $stok = (int) $bean['stok_gram'];
                $isOut = $stok < $minRatio;
                $isLow = !$isOut && $stok < 100;
                $notes = array_filter(array_map('trim', explode(',', (string) $bean['tasting_notes'])));
              ?>
              <article
                class="bean-card group bg-paper border border-sand rounded-xl overflow-hidden shadow-sm hover:shadow-ambient hover:-translate-y-0.5 hover:border-[#C9BFB0] transition-all duration-300 flex flex-col justify-between h-full"
                data-bean-id="<?= $beanId ?>"
                data-proses="<?= e($bean['proses']) ?>"
                data-search="<?= e(strtolower($bean['nama_kopi'] . ' ' . $bean['origin'] . ' ' . $bean['tasting_notes'] . ' ' . $bean['proses'])) ?>"
              >
                <div class="flex-1 flex flex-col">
                  <!-- Gambar Beans (Utuh tanpa terpotong) -->
                  <?php $beanImgUrl = resolveBeanImageUrl((string) $bean['gambar_url']); ?>
                  <div class="relative aspect-[4/3] w-full bg-[#231710] overflow-hidden">
                    <img
                      src="<?= e($beanImgUrl) ?>"
                      alt=""
                      aria-hidden="true"
                      class="absolute inset-0 w-full h-full object-cover blur-xl scale-125 opacity-55 pointer-events-none"
                      loading="lazy"
                      onerror="this.style.display='none'"
                    />
                    <img
                      src="<?= e($beanImgUrl) ?>"
                      alt="<?= e($bean['nama_kopi']) ?>"
                      class="relative z-10 w-full h-full object-contain group-hover:scale-[1.03] transition-transform duration-500"
                      loading="lazy"
                      onerror="this.style.display='none'"
                    />
                    <div class="absolute inset-x-0 top-0 z-20 h-16 bg-gradient-to-b from-espresso-dark/35 to-transparent pointer-events-none"></div>
                    <div class="absolute top-3 left-3 z-30">
                      <span class="px-2.5 py-1 rounded-full bg-white/95 backdrop-blur-sm text-espresso text-[11px] font-bold uppercase tracking-wider border border-sand shadow-sm">
                        <?= e($bean['proses']) ?>
                      </span>
                    </div>
                    <div class="absolute top-3 right-3 z-30">
                      <span
                        id="stock-badge-<?= $beanId ?>"
                        class="px-2.5 py-1 rounded-full text-[11px] font-semibold tabular-nums border shadow-sm <?= $isOut ? 'bg-[#FFEBEE] text-[#93000A] border-[#F5C6CB]' : ($isLow ? 'bg-terracotta-soft text-[#8F3F27] border-[#F3D1C4]' : 'bg-white/95 text-espresso border-sand') ?>"
                      >
                        <?= $isOut ? 'Stok Habis (' . $stok . 'g)' : ($isLow ? 'Menipis • ' . $stok . 'g' : 'Stok ' . number_format($stok, 0, ',', '.') . 'g') ?>
                      </span>
                    </div>
                  </div>

                  <!-- Info Kopi -->
                  <div class="p-5 flex-1 flex flex-col justify-between gap-4">
                    <div class="space-y-3">
                      <div>
                        <span class="font-editorial italic text-sm text-muted block"><?= e($bean['origin']) ?></span>
                        <h3 class="font-editorial text-2xl font-medium text-espresso group-hover:text-terracotta transition-colors leading-snug">
                          <?= e($bean['nama_kopi']) ?>
                        </h3>
                      </div>

                      <div class="flex flex-wrap gap-1.5">
                        <?php foreach ($notes as $note): ?>
                          <span class="px-2.5 py-0.5 rounded-full bg-white border border-sand text-xs text-espresso">
                            <?= e($note) ?>
                          </span>
                        <?php endforeach; ?>
                      </div>
                    </div>

                    <div class="bg-surface-low border border-sand/80 rounded-lg px-3.5 py-2.5 text-xs text-muted flex items-center justify-between">
                      <span>Estimasi porsi (<?= (int) $minRatio ?>g/cup)</span>
                      <strong class="text-espresso tabular-nums" id="cup-est-<?= $beanId ?>">
                        ≈ <?= intdiv($stok, max(1, $minRatio)) ?> cangkir
                      </strong>
                    </div>
                  </div>
                </div>

                <!-- Harga & Tombol Pilih -->
                <div class="px-5 py-4 flex items-center justify-between gap-3 border-t border-sand/80 bg-surface/50">
                  <div class="min-w-0">
                    <span class="text-[10px] uppercase tracking-wider text-muted font-semibold block">Harga Kopi / Cup</span>
                    <span class="text-lg font-bold text-espresso tabular-nums"><?= e(formatRupiah((int) $bean['harga'])) ?></span>
                    <span class="text-[11px] text-muted block tabular-nums mt-0.5">
                      Harga Beans: <strong class="font-semibold text-espresso/80"><?= e(formatRupiah((int) ($bean['harga_per_kg'] ?? 250000))) ?>/kg</strong>
                    </span>
                  </div>
                  <button
                    type="button"
                    id="btn-order-bean-<?= $beanId ?>"
                    onclick="openOrderDrawer(<?= $beanId ?>)"
                    <?= $isOut ? 'disabled' : '' ?>
                    class="px-4 py-2.5 rounded-lg text-xs font-semibold tracking-wide transition-colors flex items-center gap-1.5 whitespace-nowrap <?= $isOut ? 'bg-surface-mid text-outline cursor-not-allowed' : 'bg-espresso hover:bg-terracotta text-cream shadow-sm' ?>"
                  >
                    <span><?= $isOut ? 'Stok Habis' : 'Pilih Beans' ?></span>
                    <span class="material-symbols-outlined text-[16px]">coffee</span>
                  </button>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
          <p id="empty-filter-msg" class="hidden text-center text-sm text-muted py-10 bg-paper border border-sand rounded-xl">
            Tidak ada biji kopi yang sesuai dengan filter pencarian Anda.
          </p>
        <?php endif; ?>
      </section>

      <!-- Section 4: Teknik Penyeduhan Tersedia -->
      <section id="teknik-seduh" class="scroll-mt-28 space-y-6">
        <div class="space-y-4">
          <div class="space-y-1.5">
            <span class="text-[11px] font-bold uppercase tracking-[0.18em] text-terracotta block">Langkah 2 • Metode Ekstraksi &amp; Sajian</span>
            <h2 class="font-editorial text-3xl sm:text-4xl text-espresso font-medium leading-tight">Pilih Teknik Penyeduhan</h2>
            <p class="text-sm text-muted max-w-2xl">
              Kenali perbedaan tiap alat dan minuman — mulai dari pour-over yang menjaga kemurnian rasa hingga espresso &amp; susu.
            </p>
          </div>

          <div class="bg-paper border border-sand rounded-xl p-4 sm:p-5 flex items-start gap-3.5 shadow-sm">
            <div class="w-9 h-9 rounded-full bg-terracotta-soft text-terracotta flex items-center justify-center shrink-0 mt-0.5">
              <span class="material-symbols-outlined text-[20px]">science</span>
            </div>
            <div class="text-xs sm:text-sm text-muted leading-relaxed">
              <strong class="text-espresso block mb-0.5">Apakah teknik penyeduhan memengaruhi rasa beans?</strong>
              <span>
                <strong class="text-terracotta">Ya, sangat memengaruhi.</strong> Filter kertas (<em>V60, Kalita, Syphon</em>) menjaga aroma bunga/buah dan rasa asli beans tetap jernih; tanpa filter kertas atau rendaman dingin (<em>French Press, Cold Brew</em>) membuat body lebih tebal dan keasaman turun; sementara berbasis tekanan/susu (<em>Americano, Latte</em>) mengubah profil rasa menjadi lebih bold atau creamy manis.
              </span>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
          <?php foreach ($methods as $m): ?>
            <?php
              $mId = (int) $m['id'];
              $methodImgUrl = resolveMethodImageUrl((string) $m['nama_metode']);
              $methodProfile = getMethodProfile((string) $m['nama_metode']);
              $methodStok = $m['stok_porsi'] !== null ? (int) $m['stok_porsi'] : null;
              $methodHabis = $methodStok !== null && $methodStok <= 0;
              $methodMenipis = $methodStok !== null && $methodStok > 0 && $methodStok <= 3;
            ?>
            <div class="group bg-paper border border-sand rounded-xl overflow-hidden flex flex-col justify-between hover:border-[#C9BFB0] hover:shadow-ambient hover:-translate-y-0.5 transition-all duration-300">
              <div class="flex-1 flex flex-col">
                <!-- Foto Teknik Penyeduhan (Utuh tanpa terpotong) -->
                <div class="relative aspect-[4/3] w-full bg-[#231710] overflow-hidden">
                  <img
                    src="<?= e($methodImgUrl) ?>"
                    alt=""
                    aria-hidden="true"
                    class="absolute inset-0 w-full h-full object-cover blur-xl scale-125 opacity-55 pointer-events-none"
                    loading="lazy"
                    onerror="this.style.display='none'"
                  />
                  <img
                    src="<?= e($methodImgUrl) ?>"
                    alt="Teknik seduh <?= e($m['nama_metode']) ?>"
                    class="relative z-10 w-full h-full object-contain group-hover:scale-[1.03] transition-transform duration-500"
                    loading="lazy"
                    onerror="this.style.display='none'"
                  />
                  <div class="absolute inset-x-0 top-0 z-20 h-14 bg-gradient-to-b from-espresso-dark/35 to-transparent pointer-events-none"></div>
                  <div class="absolute top-3 left-3 z-30">
                    <span class="px-2.5 py-1 rounded-full border text-[10px] font-bold uppercase tracking-wider shadow-sm <?= e($methodProfile['badge_class']) ?>">
                      <?= e($methodProfile['dampak_badge']) ?>
                    </span>
                  </div>
                  <div class="absolute top-3 right-3 z-30">
                    <?php if ($methodStok !== null): ?>
                      <span
                        id="method-card-badge-<?= $mId ?>"
                        class="px-2.5 py-1 rounded-full text-[11px] font-semibold tabular-nums border shadow-sm <?= $methodHabis ? 'bg-[#FFEBEE] text-[#93000A] border-[#F5C6CB]' : ($methodMenipis ? 'bg-terracotta-soft text-[#8F3F27] border-[#F3D1C4]' : 'bg-white/95 text-espresso border-sand') ?>"
                      >
                        <?= $methodHabis ? 'Habis (0 porsi)' : 'Sisa ' . $methodStok . ' porsi' ?>
                      </span>
                    <?php else: ?>
                      <span class="px-2.5 py-1 rounded-full bg-espresso/90 backdrop-blur-sm text-cream text-[11px] font-semibold tabular-nums shadow-sm">
                        ~<?= (int) $m['estimasi_menit'] ?> menit
                      </span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="p-5 flex-1 flex flex-col justify-between gap-3.5">
                  <div class="space-y-2.5">
                    <div>
                      <span class="text-[10px] font-semibold uppercase tracking-wider text-muted block"><?= e($methodProfile['kategori']) ?></span>
                      <h3 class="font-editorial text-2xl font-medium text-espresso group-hover:text-terracotta transition-colors">
                        <?= e($m['nama_metode']) ?>
                      </h3>
                    </div>

                    <div class="space-y-2 text-xs leading-relaxed">
                      <p class="text-muted">
                        <strong class="text-espresso">Perbedaan:</strong> <?= e($methodProfile['perbedaan']) ?>
                      </p>
                      <p class="text-muted bg-white border border-sand rounded-lg p-2.5">
                        <strong class="text-terracotta">Pengaruh ke rasa:</strong> <?= e($methodProfile['pengaruh_rasa']) ?>
                      </p>
                    </div>
                  </div>

                  <div class="bg-surface-low border border-sand/80 rounded-lg p-3 space-y-1 text-xs text-muted">
                    <div class="flex justify-between">
                      <span>Takaran Beans:</span>
                      <strong class="text-espresso tabular-nums"><?= (int) $m['rasio_kopi_gram'] ?>g</strong>
                    </div>
                    <div class="flex justify-between">
                      <span>Biaya Teknik:</span>
                      <strong class="text-espresso tabular-nums"><?= (int) $m['biaya_tambahan'] > 0 ? '+' . e(formatRupiah((int) $m['biaya_tambahan'])) : 'Termasuk' ?></strong>
                    </div>
                    <?php if ($methodStok !== null): ?>
                      <div class="flex justify-between pt-1 border-t border-sand/60">
                        <span>Persediaan Batch:</span>
                        <strong id="method-card-stock-<?= $mId ?>" class="tabular-nums <?= $methodHabis ? 'text-[#93000A]' : ($methodMenipis ? 'text-[#8F3F27]' : 'text-sage-text') ?>">
                          <?= $methodHabis ? 'Habis (0 porsi)' : 'Terbatas • ' . $methodStok . ' porsi' ?>
                        </strong>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <div class="px-5 pb-5">
                <button
                  type="button"
                  id="btn-use-method-<?= $mId ?>"
                  onclick="openOrderDrawer(null, <?= $mId ?>)"
                  <?= $methodHabis ? 'disabled' : '' ?>
                  class="w-full py-2.5 rounded-lg text-xs font-semibold transition-colors flex items-center justify-center gap-1.5 <?= $methodHabis ? 'bg-surface-mid text-outline cursor-not-allowed' : 'bg-surface-high hover:bg-espresso text-espresso hover:text-cream' ?>"
                >
                  <span><?= $methodHabis ? 'Persediaan Habis' : 'Gunakan ' . e($m['nama_metode']) ?></span>
                  <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                </button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <!-- Section 5: Filosofi Slow Bar -->
      <section id="filosofi-section" class="scroll-mt-28 bg-paper border border-sand rounded-xl p-6 lg:p-10 grid grid-cols-1 lg:grid-cols-12 gap-8 items-center">
        <div class="lg:col-span-4 space-y-2">
          <span class="text-[11px] uppercase tracking-[0.18em] text-terracotta font-bold">Etiket Menikmati Seduhan</span>
          <h3 class="font-editorial text-3xl text-espresso font-medium">Meresapi Setiap Lapisan Suhu</h3>
          <p class="font-editorial italic text-base text-muted">
            Kopi filter manual bukanlah minuman instan. Karakter rasa bertransformasi dinamis saat suhu perlahan turun.
          </p>
        </div>
        <div class="lg:col-span-8 grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="p-4 bg-white border border-sand rounded-lg space-y-1.5">
            <span class="inline-block text-[11px] font-semibold px-2 py-0.5 rounded bg-surface-mid text-muted">1. Panas (65°C–70°C)</span>
            <h4 class="text-sm font-bold text-espresso">Aroma &amp; Kelembutan</h4>
            <p class="text-xs text-muted leading-relaxed">Uap pertama memancarkan aroma bunga, karamel, dan rasa manis dasar yang bersih.</p>
          </div>
          <div class="p-4 bg-white border border-sand rounded-lg space-y-1.5">
            <span class="inline-block text-[11px] font-semibold px-2 py-0.5 rounded bg-surface-mid text-muted">2. Hangat (50°C–60°C)</span>
            <h4 class="text-sm font-bold text-espresso">Karakter Buah (Acidity)</h4>
            <p class="text-xs text-muted leading-relaxed">Keasaman buah beri, jeruk, dan persik mulai mekar dengan keseimbangan yang jernih.</p>
          </div>
          <div class="p-4 bg-white border border-sand rounded-lg space-y-1.5">
            <span class="inline-block text-[11px] font-semibold px-2 py-0.5 rounded bg-surface-mid text-muted">3. Sejuk (35°C–45°C)</span>
            <h4 class="text-sm font-bold text-espresso">Sweet Aftertaste</h4>
            <p class="text-xs text-muted leading-relaxed">Tetesan terakhir yang paling manis menyerupai madu bunga liar dan teh buah.</p>
          </div>
        </div>
      </section>

    </div>
  </main>

  <!-- Slide-Over Order Drawer (Form Pemesanan Interaktif) -->
  <div id="order-drawer" class="fixed inset-0 z-50 hidden pointer-events-none opacity-0 transition-opacity duration-300 justify-end" aria-hidden="true">
    <div id="order-drawer-backdrop" onclick="closeOrderDrawer()" class="absolute inset-0 bg-espresso-dark/50 backdrop-blur-sm transition-opacity duration-300 opacity-0"></div>

    <div id="order-drawer-panel" class="relative w-full max-w-lg bg-surface h-full shadow-2xl overflow-y-auto transform translate-x-full transition-transform duration-300 ease-out pointer-events-auto p-6 sm:p-8 flex flex-col justify-between border-l border-sand">
      <div class="space-y-6">
        <div class="flex items-start justify-between border-b border-sand pb-4">
          <div>
            <span class="text-[11px] font-bold uppercase tracking-widest text-terracotta">Pemesanan Slow Bar</span>
            <h2 class="font-editorial text-2xl font-medium text-espresso" id="drawer-title">Pilih Beans &amp; Teknik Seduh</h2>
            <p class="text-xs text-muted mt-0.5" id="drawer-subtitle">Cukup pilih biji kopi dan teknik penyeduhan favorit Anda.</p>
          </div>
          <button type="button" onclick="closeOrderDrawer()" class="w-9 h-9 rounded-full bg-surface-high hover:bg-sand flex items-center justify-center text-espresso transition-colors">
            <span class="material-symbols-outlined text-[20px]">close</span>
          </button>
        </div>

        <div id="order-error-banner" class="hidden px-4 py-3 rounded border bg-[#FFEBEE] border-[#F5C6CB] text-[#93000A] text-xs font-medium"></div>

        <form id="brew-order-form" action="/proses-order" method="POST" class="space-y-6" onsubmit="submitOrderForm(event)">
          <!-- 1. Pilih Beans -->
          <div class="space-y-2">
            <label for="order-bean-select" class="block text-xs font-bold uppercase tracking-wider text-espresso">
              1. Pilih Beans
            </label>
            <select
              id="order-bean-select"
              name="biji_kopi_id"
              required
              onchange="updateOrderCalculator()"
              class="w-full h-11 px-3 rounded-lg bg-white border border-sand text-sm text-espresso focus:outline-none focus:border-espresso"
            >
              <?php foreach ($beans as $b): ?>
                <option value="<?= (int) $b['id'] ?>">
                  <?= e($b['nama_kopi']) ?> — Cup <?= e(formatRupiah((int) $b['harga'])) ?> • Beans <?= e(formatRupiah((int) ($b['harga_per_kg'] ?? 250000))) ?>/kg (Sisa <?= (int) $b['stok_gram'] ?>g)
                </option>
              <?php endforeach; ?>
            </select>
            <div class="flex items-center gap-3 bg-surface-low border border-sand rounded-lg p-2.5">
              <img id="drawer-bean-thumb" src="" alt="" class="w-12 h-12 rounded object-cover bg-surface-mid shrink-0" />
              <p class="text-xs text-muted" id="drawer-bean-meta"></p>
            </div>
          </div>

          <!-- 2. Pilih Teknik Penyeduhan -->
          <div class="space-y-2.5">
            <span class="block text-xs font-bold uppercase tracking-wider text-espresso">
              2. Pilih Teknik Penyeduhan
            </span>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5" id="drawer-methods-grid">
              <?php foreach ($methods as $idx => $m): ?>
                <?php
                  $mId = (int) $m['id'];
                  $drawerMethodImg = resolveMethodImageUrl((string) $m['nama_metode']);
                  $drawerProfile = getMethodProfile((string) $m['nama_metode']);
                  $drawerMethodStok = $m['stok_porsi'] !== null ? (int) $m['stok_porsi'] : null;
                  $drawerMethodHabis = $drawerMethodStok !== null && $drawerMethodStok <= 0;
                ?>
                <label
                  id="drawer-method-label-<?= $mId ?>"
                  class="rounded-xl border border-sand bg-paper p-2.5 transition-all flex items-center gap-2.5 <?= $drawerMethodHabis ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer hover:border-espresso has-[:checked]:bg-espresso has-[:checked]:text-cream has-[:checked]:border-espresso' ?>"
                >
                  <input
                    type="radio"
                    name="metode_seduh_id"
                    value="<?= $mId ?>"
                    class="hidden"
                    <?= $idx === 0 && !$drawerMethodHabis ? 'checked' : '' ?>
                    <?= $drawerMethodHabis ? 'disabled' : '' ?>
                    onchange="updateOrderCalculator()"
                  />
                  <img src="<?= e($drawerMethodImg) ?>" alt="<?= e($m['nama_metode']) ?>" class="w-11 h-11 rounded-lg object-cover shrink-0 bg-surface-mid" />
                  <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-1">
                      <span class="font-semibold text-sm truncate"><?= e($m['nama_metode']) ?></span>
                      <span class="text-[11px] opacity-80 tabular-nums shrink-0"><?= (int) $m['rasio_kopi_gram'] ?>g</span>
                    </div>
                    <div class="text-[10px] opacity-85 truncate">
                      <?= $drawerMethodStok !== null ? ($drawerMethodHabis ? 'Persediaan Habis' : 'Terbatas • Sisa ' . $drawerMethodStok . ' porsi') : e($drawerProfile['dampak_badge']) ?>
                    </div>
                    <div class="text-[10px] opacity-75 mt-0.5 flex items-center justify-between">
                      <span>~<?= (int) $m['estimasi_menit'] ?> mnt</span>
                      <span><?= (int) $m['biaya_tambahan'] > 0 ? '+' . e(formatRupiah((int) $m['biaya_tambahan'])) : 'Termasuk' ?></span>
                    </div>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>

            <!-- Penjelasan Perbedaan & Pengaruh ke Rasa Beans -->
            <div id="drawer-method-insight" class="bg-white border border-sand rounded-xl p-3.5 space-y-1.5 text-xs">
              <div class="flex items-center justify-between gap-2">
                <span class="font-bold text-espresso uppercase tracking-wider text-[10px]" id="insight-category">-</span>
                <span class="px-2 py-0.5 rounded-full bg-terracotta-soft text-[#8F3F27] text-[10px] font-bold" id="insight-impact-badge">-</span>
              </div>
              <p class="text-muted leading-relaxed">
                <strong class="text-espresso">Perbedaan:</strong> <span id="insight-diff">-</span>
              </p>
              <p class="text-muted leading-relaxed">
                <strong class="text-terracotta">Pengaruh ke rasa beans:</strong> <span id="insight-flavor">-</span>
              </p>
            </div>
          </div>

          <!-- Ringkasan Harga & Validasi Stok -->
          <div class="bg-surface-low border border-sand rounded-xl p-4 space-y-2 text-xs">
            <div class="flex justify-between text-muted">
              <span>Harga Kopi (Seduh / Cup)</span>
              <span class="font-semibold text-espresso tabular-nums" id="summary-bean-price">Rp 0</span>
            </div>
            <div class="flex justify-between text-muted text-[11px]">
              <span>Harga Roasted Beans (Per kg)</span>
              <span class="font-medium text-espresso/80 tabular-nums" id="summary-bean-kg-price">Rp 0/kg</span>
            </div>
            <div class="flex justify-between text-muted">
              <span>Teknik Penyeduhan (<span id="summary-method-name">-</span>)</span>
              <span class="font-semibold text-espresso tabular-nums" id="summary-method-fee">Rp 0</span>
            </div>
            <div class="flex justify-between text-muted">
              <span>Takaran / Sisa Stok Beans</span>
              <span class="font-semibold tabular-nums" id="summary-gram-check">-</span>
            </div>
            <div id="summary-method-stock-row" class="hidden flex justify-between text-muted">
              <span>Persediaan Batch (<span id="summary-method-stock-name">-</span>)</span>
              <span class="font-semibold tabular-nums" id="summary-method-stock-val">-</span>
            </div>
            <div class="pt-2 border-t border-sand flex justify-between items-center text-sm font-bold text-espresso">
              <span>Total</span>
              <span class="text-lg text-terracotta tabular-nums" id="summary-total-price">Rp 0</span>
            </div>
          </div>

          <button
            type="submit"
            id="btn-submit-order"
            class="w-full h-12 rounded-lg bg-terracotta hover:bg-terracotta-dark text-white font-semibold text-sm tracking-wide transition-colors flex items-center justify-center gap-2 shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <span class="material-symbols-outlined text-[18px]">local_cafe</span>
            <span id="btn-submit-label">Seduh Sekarang</span>
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- Toast Notifikasi -->
  <div id="order-toast" class="fixed bottom-6 right-6 z-50 max-w-sm bg-espresso text-cream px-5 py-4 rounded-xl shadow-2xl border border-[#4E3829] flex items-start gap-3 transform translate-y-24 opacity-0 pointer-events-none transition-all duration-300">
    <div class="w-8 h-8 rounded-full bg-terracotta text-white flex items-center justify-center shrink-0 mt-0.5">
      <span class="material-symbols-outlined text-[18px]">check</span>
    </div>
    <div>
      <h4 class="text-sm font-bold text-white" id="toast-title">Pesanan Diterima</h4>
      <p class="text-xs text-[#DAC2B5] mt-0.5" id="toast-body"></p>
    </div>
  </div>

  <footer class="w-full bg-surface-low border-t border-sand mt-12">
    <div class="max-w-7xl mx-auto px-5 lg:px-12 py-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
      <div>
        <h3 class="font-editorial text-2xl text-espresso">Kala Seduh Slow Bar</h3>
        <p class="font-editorial italic text-sm text-muted">Menghargai waktu, suhu air, dan setiap tetes ekstraksi manual.</p>
      </div>
      <div class="text-xs text-muted space-y-1 md:text-right">
        <p>Selasa – Minggu: 08.00 – 21.00 WIB</p>
        <p>© <?= date('Y') ?> Kala Seduh Slow Bar • PHP Native + PostgreSQL Supabase</p>
      </div>
    </div>
  </footer>

  <script>
    const BEANS_DATA = <?= $beansJson ?: '[]' ?>;
    const METHODS_DATA = <?= $methodsJson ?: '[]' ?>;
    const MIN_RATIO = <?= (int) $minRatio ?>;
    let activeProsesFilter = 'all';
    let drawerCloseTimer = null;

    function formatRupiah(num) {
      return 'Rp ' + Number(num || 0).toLocaleString('id-ID');
    }

    function escapeHtml(str) {
      return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }

    function setProcessFilter(proses) {
      activeProsesFilter = proses;
      document.querySelectorAll('.filter-tab').forEach((btn) => {
        const isActive = btn.dataset.proses === proses;
        if (isActive) {
          btn.className = 'filter-tab px-3.5 py-1.5 rounded-full bg-espresso text-cream text-xs font-semibold transition-colors whitespace-nowrap';
        } else {
          btn.className = 'filter-tab px-3.5 py-1.5 rounded-full bg-surface-low border border-sand hover:bg-surface-high text-espresso text-xs font-semibold transition-colors whitespace-nowrap';
        }
      });
      applyCatalogFilters();
    }

    function applyCatalogFilters() {
      const q = (document.getElementById('bean-search-input')?.value || '').trim().toLowerCase();
      const cards = document.querySelectorAll('.bean-card');
      let visibleCount = 0;

      cards.forEach((card) => {
        const matchProses = activeProsesFilter === 'all' || card.dataset.proses === activeProsesFilter;
        const matchSearch = q === '' || (card.dataset.search || '').includes(q);
        if (matchProses && matchSearch) {
          card.style.display = 'flex';
          visibleCount++;
        } else {
          card.style.display = 'none';
        }
      });

      const countLabel = document.getElementById('catalog-count-label');
      if (countLabel) {
        countLabel.textContent = `Menampilkan ${visibleCount} dari ${cards.length} varian`;
      }

      const emptyMsg = document.getElementById('empty-filter-msg');
      if (emptyMsg) {
        emptyMsg.classList.toggle('hidden', visibleCount > 0);
      }
    }

    function openOrderDrawer(beanId, methodId) {
      const select = document.getElementById('order-bean-select');
      if (select) {
        if (beanId) {
          select.value = String(beanId);
        } else {
          const available = BEANS_DATA.find((b) => b.stok_gram >= MIN_RATIO);
          if (available) {
            select.value = String(available.id);
          }
        }
      }
      if (methodId) {
        const methodRadio = document.querySelector(`input[name="metode_seduh_id"][value="${methodId}"]`);
        if (methodRadio && !methodRadio.disabled) {
          methodRadio.checked = true;
        }
      } else {
        const checkedNow = document.querySelector('input[name="metode_seduh_id"]:checked');
        if (!checkedNow || checkedNow.disabled) {
          const firstValid = Array.from(document.querySelectorAll('input[name="metode_seduh_id"]')).find((el) => !el.disabled);
          if (firstValid) firstValid.checked = true;
        }
      }
      const errBanner = document.getElementById('order-error-banner');
      if (errBanner) {
        errBanner.classList.add('hidden');
        errBanner.textContent = '';
      }

      updateOrderCalculator();

      if (drawerCloseTimer) {
        clearTimeout(drawerCloseTimer);
        drawerCloseTimer = null;
      }

      const drawer = document.getElementById('order-drawer');
      const backdrop = document.getElementById('order-drawer-backdrop');
      const panel = document.getElementById('order-drawer-panel');
      drawer.setAttribute('aria-hidden', 'false');
      drawer.classList.remove('hidden', 'pointer-events-none', 'opacity-0');
      drawer.classList.add('flex');
      void drawer.offsetWidth;
      drawer.classList.add('opacity-100');
      if (backdrop) {
        backdrop.classList.remove('opacity-0');
        backdrop.classList.add('opacity-100');
      }
      panel.classList.remove('translate-x-full');
      panel.classList.add('translate-x-0');
    }

    function closeOrderDrawer() {
      const drawer = document.getElementById('order-drawer');
      const backdrop = document.getElementById('order-drawer-backdrop');
      const panel = document.getElementById('order-drawer-panel');
      if (!drawer || !panel) return;
      drawer.setAttribute('aria-hidden', 'true');
      drawer.classList.remove('opacity-100');
      drawer.classList.add('opacity-0', 'pointer-events-none');
      if (backdrop) {
        backdrop.classList.remove('opacity-100');
        backdrop.classList.add('opacity-0');
      }
      panel.classList.remove('translate-x-0');
      panel.classList.add('translate-x-full');
      if (drawerCloseTimer) {
        clearTimeout(drawerCloseTimer);
      }
      drawerCloseTimer = setTimeout(() => {
        drawer.classList.remove('flex');
        drawer.classList.add('hidden');
        drawerCloseTimer = null;
      }, 310);
    }

    function updateOrderCalculator() {
      const beanId = Number(document.getElementById('order-bean-select')?.value || 0);
      const checkedMethod = document.querySelector('input[name="metode_seduh_id"]:checked');
      const methodId = Number(checkedMethod ? checkedMethod.value : 0);

      const bean = BEANS_DATA.find((b) => b.id === beanId);
      const method = METHODS_DATA.find((m) => m.id === methodId);

      const beanMeta = document.getElementById('drawer-bean-meta');
      const beanThumb = document.getElementById('drawer-bean-thumb');
      const sumBean = document.getElementById('summary-bean-price');
      const sumBeanKg = document.getElementById('summary-bean-kg-price');
      const sumMethodName = document.getElementById('summary-method-name');
      const sumMethodFee = document.getElementById('summary-method-fee');
      const sumGramCheck = document.getElementById('summary-gram-check');
      const sumTotal = document.getElementById('summary-total-price');
      const submitBtn = document.getElementById('btn-submit-order');
      const submitLabel = document.getElementById('btn-submit-label');

      if (!bean || !method) {
        if (submitBtn) submitBtn.disabled = true;
        return;
      }

      if (beanThumb && bean.gambar_url) {
        beanThumb.src = bean.gambar_url;
        beanThumb.alt = bean.nama_kopi;
      }
      if (beanMeta) {
        beanMeta.textContent = `${bean.origin} • ${bean.proses} • ${bean.tasting_notes} • Beans ${formatRupiah(bean.harga_per_kg)}/kg`;
      }

      const insightCat = document.getElementById('insight-category');
      const insightBadge = document.getElementById('insight-impact-badge');
      const insightDiff = document.getElementById('insight-diff');
      const insightFlavor = document.getElementById('insight-flavor');
      if (insightCat) insightCat.textContent = method.kategori || 'Teknik Penyeduhan';
      if (insightBadge) insightBadge.textContent = method.dampak_badge || '';
      if (insightDiff) insightDiff.textContent = method.perbedaan || '';
      if (insightFlavor) insightFlavor.textContent = method.pengaruh_rasa || '';

      const beansCukup = bean.stok_gram >= method.rasio_kopi_gram;
      const metodeCukup = method.stok_porsi === null || method.stok_porsi > 0;
      const bisaSeduh = beansCukup && metodeCukup;
      const total = bean.harga + method.biaya_tambahan;

      if (sumBean) sumBean.textContent = formatRupiah(bean.harga);
      if (sumBeanKg) sumBeanKg.textContent = `${formatRupiah(bean.harga_per_kg)}/kg`;
      if (sumMethodName) sumMethodName.textContent = method.nama_metode;
      if (sumMethodFee) sumMethodFee.textContent = formatRupiah(method.biaya_tambahan);
      if (sumTotal) sumTotal.textContent = formatRupiah(total);

      if (sumGramCheck) {
        sumGramCheck.textContent = `Butuh ${method.rasio_kopi_gram}g / Sisa ${bean.stok_gram}g`;
        sumGramCheck.className = beansCukup
          ? 'font-semibold text-sage-text tabular-nums'
          : 'font-semibold text-[#93000A] tabular-nums';
      }

      const methodStockRow = document.getElementById('summary-method-stock-row');
      const methodStockName = document.getElementById('summary-method-stock-name');
      const methodStockVal = document.getElementById('summary-method-stock-val');
      if (methodStockRow && methodStockName && methodStockVal) {
        if (method.stok_porsi !== null) {
          methodStockRow.classList.remove('hidden');
          methodStockName.textContent = method.nama_metode;
          methodStockVal.textContent = method.stok_porsi > 0 ? `Sisa ${method.stok_porsi} porsi` : 'Habis (0 porsi)';
          methodStockVal.className = method.stok_porsi > 0
            ? 'font-semibold text-sage-text tabular-nums'
            : 'font-semibold text-[#93000A] tabular-nums';
        } else {
          methodStockRow.classList.add('hidden');
        }
      }

      if (submitBtn && submitLabel) {
        submitBtn.disabled = !bisaSeduh;
        if (!metodeCukup) {
          submitLabel.textContent = `Persediaan ${method.nama_metode} Habis`;
        } else if (!beansCukup) {
          submitLabel.textContent = `Stok ${bean.nama_kopi} Tidak Cukup`;
        } else {
          submitLabel.textContent = 'Seduh Sekarang';
        }
      }
    }

    function updateMethodStockUI(methodId, newMethodStock) {
      const method = METHODS_DATA.find((m) => m.id === Number(methodId));
      if (!method) return;
      method.stok_porsi = newMethodStock === null || newMethodStock === undefined ? null : Number(newMethodStock);

      const isLimited = method.stok_porsi !== null;
      if (!isLimited) return;

      const habis = method.stok_porsi <= 0;
      const menipis = !habis && method.stok_porsi <= 3;

      const cardBadge = document.getElementById(`method-card-badge-${methodId}`);
      if (cardBadge) {
        cardBadge.className = 'px-2.5 py-1 rounded-full text-[11px] font-semibold tabular-nums border shadow-sm ' + (
          habis
            ? 'bg-[#FFEBEE] text-[#93000A] border-[#F5C6CB]'
            : menipis
            ? 'bg-terracotta-soft text-[#8F3F27] border-[#F3D1C4]'
            : 'bg-white/95 text-espresso border-sand'
        );
        cardBadge.textContent = habis ? 'Habis (0 porsi)' : `Sisa ${method.stok_porsi} porsi`;
      }

      const cardStock = document.getElementById(`method-card-stock-${methodId}`);
      if (cardStock) {
        cardStock.className = 'tabular-nums ' + (habis ? 'text-[#93000A]' : (menipis ? 'text-[#8F3F27]' : 'text-sage-text'));
        cardStock.textContent = habis ? 'Habis (0 porsi)' : `Terbatas • ${method.stok_porsi} porsi`;
      }

      const cardBtn = document.getElementById(`btn-use-method-${methodId}`);
      if (cardBtn) {
        cardBtn.disabled = habis;
        cardBtn.className = 'w-full py-2.5 rounded-lg text-xs font-semibold transition-colors flex items-center justify-center gap-1.5 ' + (
          habis
            ? 'bg-surface-mid text-outline cursor-not-allowed'
            : 'bg-surface-high hover:bg-espresso text-espresso hover:text-cream'
        );
        cardBtn.innerHTML = `<span>${habis ? 'Persediaan Habis' : 'Gunakan ' + escapeHtml(method.nama_metode)}</span><span class="material-symbols-outlined text-[15px]">arrow_forward</span>`;
      }

      const label = document.getElementById(`drawer-method-label-${methodId}`);
      const radio = document.querySelector(`input[name="metode_seduh_id"][value="${methodId}"]`);
      if (label && radio) {
        radio.disabled = habis;
        label.className = 'rounded-xl border border-sand bg-paper p-2.5 transition-all flex items-center gap-2.5 ' + (
          habis
            ? 'opacity-50 cursor-not-allowed'
            : 'cursor-pointer hover:border-espresso has-[:checked]:bg-espresso has-[:checked]:text-cream has-[:checked]:border-espresso'
        );
        const subText = label.querySelector('.min-w-0 > div:nth-child(2)');
        if (subText) {
          subText.textContent = habis ? 'Persediaan Habis' : `Terbatas • Sisa ${method.stok_porsi} porsi`;
        }
      }
    }

    function updateBeanCardStockUI(beanId, newStock) {
      const bean = BEANS_DATA.find((b) => b.id === Number(beanId));
      if (bean) {
        bean.stok_gram = Number(newStock);
      }

      const badge = document.getElementById(`stock-badge-${beanId}`);
      const cupEst = document.getElementById(`cup-est-${beanId}`);
      const btn = document.getElementById(`btn-order-bean-${beanId}`);
      const option = document.querySelector(`#order-bean-select option[value="${beanId}"]`);

      const isOut = newStock < MIN_RATIO;
      const isLow = !isOut && newStock < 100;

      if (badge) {
        badge.className = 'px-2.5 py-1 rounded-full text-[11px] font-semibold tabular-nums border ' + (
          isOut
            ? 'bg-[#FFEBEE] text-[#93000A] border-[#F5C6CB]'
            : isLow
            ? 'bg-terracotta-soft text-[#8F3F27] border-[#F3D1C4]'
            : 'bg-white/95 text-espresso border-sand'
        );
        badge.textContent = isOut
          ? `Stok Habis (${newStock}g)`
          : isLow
          ? `Menipis • ${newStock}g`
          : `Stok ${Number(newStock).toLocaleString('id-ID')}g`;
      }

      if (cupEst) {
        cupEst.textContent = `≈ ${Math.floor(newStock / Math.max(1, MIN_RATIO))} cangkir`;
      }

      if (btn) {
        btn.disabled = isOut;
        btn.className = 'px-4 py-2.5 rounded-lg text-xs font-semibold tracking-wide transition-colors flex items-center gap-1.5 whitespace-nowrap ' + (
          isOut
            ? 'bg-surface-mid text-outline cursor-not-allowed'
            : 'bg-espresso hover:bg-terracotta text-cream shadow-sm'
        );
        btn.innerHTML = `<span>${isOut ? 'Stok Habis' : 'Pilih Beans'}</span><span class="material-symbols-outlined text-[16px]">coffee</span>`;
      }

      if (option && bean) {
        option.textContent = `${bean.nama_kopi} — Cup ${formatRupiah(bean.harga)} • Beans ${formatRupiah(bean.harga_per_kg)}/kg (Sisa ${newStock}g)`;
      }
    }

    function showToast(title, body) {
      const toast = document.getElementById('order-toast');
      const titleEl = document.getElementById('toast-title');
      const bodyEl = document.getElementById('toast-body');
      if (!toast || !titleEl || !bodyEl) return;

      titleEl.textContent = title;
      bodyEl.textContent = body;
      toast.classList.remove('translate-y-24', 'opacity-0', 'pointer-events-none');
      toast.classList.add('translate-y-0', 'opacity-100');

      setTimeout(() => {
        toast.classList.add('translate-y-24', 'opacity-0', 'pointer-events-none');
        toast.classList.remove('translate-y-0', 'opacity-100');
      }, 5000);
    }

    async function submitOrderForm(event) {
      event.preventDefault();
      const form = event.target;
      const errBanner = document.getElementById('order-error-banner');
      const submitBtn = document.getElementById('btn-submit-order');
      const submitLabel = document.getElementById('btn-submit-label');

      if (errBanner) errBanner.classList.add('hidden');
      if (submitBtn) submitBtn.disabled = true;
      if (submitLabel) submitLabel.textContent = 'Memproses Pesanan...';

      const payload = {
        biji_kopi_id: Number(form.biji_kopi_id.value),
        metode_seduh_id: Number(form.querySelector('input[name="metode_seduh_id"]:checked')?.value || 0),
      };

      try {
        const res = await fetch('/proses-order', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
          },
          body: JSON.stringify(payload),
        });
        const data = await res.json();

        if (!res.ok || !data.success) {
          throw new Error(data.message || 'Gagal mengirim pesanan.');
        }

        updateBeanCardStockUI(data.biji_kopi_id, data.updated_stok_gram);
        if (data.metode_seduh_id && data.updated_method_stok !== undefined) {
          updateMethodStockUI(data.metode_seduh_id, data.updated_method_stok);
        }
        closeOrderDrawer();
        form.reset();
        const firstMethod = Array.from(form.querySelectorAll('input[name="metode_seduh_id"]')).find((el) => !el.disabled);
        if (firstMethod) firstMethod.checked = true;

        const batchNote = data.updated_method_stok !== null && data.updated_method_stok !== undefined
          ? ` • Persediaan ${data.order.nama_metode} sisa ${data.updated_method_stok} porsi.`
          : '';
        showToast(
          `Pesanan ${data.order.kode_pesanan} Diterima`,
          `${data.order.nama_kopi} (${data.order.nama_metode}) • Sisa beans ${data.updated_stok_gram}g${batchNote}`
        );
        await refreshLiveQueue(false);
      } catch (err) {
        if (errBanner) {
          errBanner.textContent = err.message;
          errBanner.classList.remove('hidden');
        }
      } finally {
        updateOrderCalculator();
      }
    }

    function renderLiveQueue(orders) {
      const waiting = orders.filter((o) => o.status === 'Menunggu');
      const brewing = orders.filter((o) => o.status === 'Sedang Diseduh');
      const done = orders.filter((o) => o.status === 'Selesai');

      const setText = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.textContent = String(val);
      };
      setText('count-menunggu', waiting.length);
      setText('count-seduh', brewing.length);
      setText('count-selesai', done.length);
      setText('hero-active-count', waiting.length + brewing.length);

      const colMenunggu = document.getElementById('col-menunggu');
      if (colMenunggu) {
        colMenunggu.innerHTML = waiting.length === 0
          ? '<p class="text-xs text-muted italic py-4 text-center">Belum ada pesanan menunggu.</p>'
          : waiting.map((o) => `
            <div class="bg-white border border-sand rounded p-3 space-y-1">
              <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-bold text-espresso tabular-nums">${escapeHtml(o.kode_pesanan)} • ${escapeHtml(o.jam_wib)}</span>
                <span class="text-[11px] px-2 py-0.5 rounded bg-surface-mid text-muted font-semibold">${escapeHtml(o.nama_metode)}</span>
              </div>
              <p class="text-sm font-semibold text-espresso">${escapeHtml(o.nama_kopi)}</p>
            </div>
          `).join('');
      }

      const colSeduh = document.getElementById('col-seduh');
      if (colSeduh) {
        colSeduh.innerHTML = brewing.length === 0
          ? '<p class="text-xs text-muted italic py-4 text-center">Barista siap menyeduh pesanan berikutnya.</p>'
          : brewing.map((o) => `
            <div class="bg-terracotta-soft/70 border border-[#F3D1C4] rounded p-3 space-y-1">
              <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-bold text-[#8F3F27] tabular-nums">${escapeHtml(o.kode_pesanan)} • ${escapeHtml(o.jam_wib)}</span>
                <span class="text-[11px] px-2 py-0.5 rounded bg-terracotta text-white font-semibold">${escapeHtml(o.nama_metode)}</span>
              </div>
              <p class="text-sm font-semibold text-espresso">${escapeHtml(o.nama_kopi)}</p>
              <p class="text-xs text-[#772F18]">Sedang diracik barista • Est. ~${Number(o.estimasi_menit)} menit</p>
            </div>
          `).join('');
      }

      const colSelesai = document.getElementById('col-selesai');
      if (colSelesai) {
        colSelesai.innerHTML = done.length === 0
          ? '<p class="text-xs text-muted italic py-4 text-center">Belum ada pesanan selesai hari ini.</p>'
          : done.map((o) => `
            <div class="bg-sage-soft/70 border border-[#C2D6CD] rounded p-3 space-y-1">
              <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-bold text-espresso tabular-nums">${escapeHtml(o.kode_pesanan)} • ${escapeHtml(o.jam_wib)}</span>
                <span class="text-[11px] px-2 py-0.5 rounded-full bg-white text-sage-text border border-[#C2D6CD] font-semibold">Siap Disajikan</span>
              </div>
              <p class="text-sm font-medium text-espresso">${escapeHtml(o.nama_kopi)} • ${escapeHtml(o.nama_metode)}</p>
            </div>
          `).join('');
      }
    }

    async function refreshLiveQueue(notify) {
      try {
        const res = await fetch('/antrean', { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const data = await res.json();
        if (!data.success) return;

        renderLiveQueue(data.orders || []);
        if (data.stocks) {
          Object.entries(data.stocks).forEach(([id, gram]) => {
            updateBeanCardStockUI(Number(id), Number(gram));
          });
        }
        if (data.method_stocks) {
          Object.entries(data.method_stocks).forEach(([id, porsi]) => {
            updateMethodStockUI(Number(id), porsi === null ? null : Number(porsi));
          });
        }
        updateOrderCalculator();
        const clockEl = document.getElementById('live-clock-badge');
        if (clockEl && data.server_time) {
          clockEl.textContent = 'Waktu Bar: ' + data.server_time;
        }
        if (notify) {
          showToast('Antrean Diperbarui', 'Status seduh dan stok gramasi sudah tersinkron dengan server.');
        }
      } catch (_) {
        // Abaikan error sementara saat polling
      }
    }

    function setActiveNavLink(hash) {
      document.querySelectorAll('#main-nav .nav-link').forEach((link) => {
        const active = link.getAttribute('href') === hash;
        link.classList.toggle('text-espresso', active);
        link.classList.toggle('font-semibold', active);
        link.classList.toggle('border-terracotta', active);
        link.classList.toggle('text-muted', !active);
        link.classList.toggle('border-transparent', !active);
      });
    }

    document.addEventListener('DOMContentLoaded', () => {
      updateOrderCalculator();
      setInterval(() => refreshLiveQueue(false), 5000);
      document.querySelectorAll('#main-nav .nav-link').forEach((link) => {
        link.addEventListener('click', () => setActiveNavLink(link.getAttribute('href')));
      });
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
          closeOrderDrawer();
        }
      });
    });
  </script>
</body>
</html>
