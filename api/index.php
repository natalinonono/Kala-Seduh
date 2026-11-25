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

    $beans = $pdo->query(
        'SELECT id, nama_kopi, origin, proses, tasting_notes, stok_gram, harga, gambar_url
         FROM biji_kopi
         ORDER BY CASE WHEN stok_gram > 0 THEN 0 ELSE 1 END, id ASC'
    )->fetchAll();

    $methods = $pdo->query(
        'SELECT id, nama_metode, rasio_kopi_gram, estimasi_menit, biaya_tambahan
         FROM metode_seduh
         ORDER BY id ASC'
    )->fetchAll();

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
        'gambar_url'    => (string) $b['gambar_url'],
    ], $beans),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
);

$methodsJson = json_encode(
    array_map(static fn(array $m): array => [
        'id'              => (int) $m['id'],
        'nama_metode'     => (string) $m['nama_metode'],
        'rasio_kopi_gram' => (int) $m['rasio_kopi_gram'],
        'estimasi_menit'  => (int) $m['estimasi_menit'],
        'biaya_tambahan'  => (int) $m['biaya_tambahan'],
    ], $methods),
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

      <nav class="hidden md:flex items-center gap-8 text-sm font-medium" id="main-nav">
        <a href="#katalog-biji" class="nav-link text-espresso font-semibold border-b-2 border-terracotta pb-1 transition-colors whitespace-nowrap">Katalog Biji</a>
        <a href="#antrean-section" class="nav-link text-muted hover:text-espresso border-b-2 border-transparent pb-1 transition-colors whitespace-nowrap">Antrean Seduh Live</a>
        <a href="#filosofi-section" class="nav-link text-muted hover:text-espresso border-b-2 border-transparent pb-1 transition-colors whitespace-nowrap">Filosofi Rasa</a>
      </nav>

      <div class="flex items-center gap-2.5 shrink-0">
        <button
          type="button"
          onclick="openOrderDrawer()"
          class="hidden sm:inline-flex items-center gap-1.5 px-4 py-2 rounded bg-terracotta hover:bg-terracotta-dark text-white text-xs font-semibold uppercase tracking-wider transition-colors shadow-sm whitespace-nowrap"
        >
          <span class="material-symbols-outlined text-[16px]">local_cafe</span>
          <span>Pesan Seduh</span>
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
        <a href="#katalog-biji" class="text-espresso">Katalog Biji</a>
        <a href="#antrean-section" class="text-muted hover:text-espresso">Antrean Live</a>
        <a href="#filosofi-section" class="text-muted hover:text-espresso">Filosofi Rasa</a>
      </div>
      <button type="button" onclick="openOrderDrawer()" class="sm:hidden shrink-0 px-3 py-1 rounded bg-terracotta text-white text-[11px] font-semibold">
        Pesan Seduh
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
              <span>Pilih Biji Kopi</span>
              <span class="material-symbols-outlined text-[18px]">arrow_downward</span>
            </a>
            <a href="#antrean-section" class="inline-flex items-center gap-2 px-5 py-3 rounded bg-paper hover:bg-surface-high text-espresso border border-sand text-sm font-semibold transition-colors">
              <span>Pantau Antrean (<span id="hero-active-count"><?= $activeQueueCount ?></span> Aktif)</span>
            </a>
          </div>
        </div>

        <!-- Hero Visual Spotlight Card -->
        <div class="lg:col-span-6">
          <div class="relative rounded-xl overflow-hidden bg-surface-high border border-sand shadow-ambient">
            <div class="relative h-[340px] sm:h-[420px] w-full bg-[#2C1E16]">
              <img
                src="<?= e($featuredBean['gambar_url'] ?? 'https://images.unsplash.com/photo-1620051787220-50768889e32c?auto=format&fit=crop&w=1000&q=80') ?>"
                alt="<?= e($featuredBean['nama_kopi'] ?? 'Manual Brew Kala Seduh') ?>"
                class="w-full h-full object-cover"
                onerror="this.style.display='none'"
              />
              <div class="absolute inset-0 bg-gradient-to-t from-espresso-dark/90 via-espresso-dark/30 to-transparent"></div>

              <div class="absolute top-4 left-4 right-4 flex items-center justify-between gap-2 flex-wrap">
                <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/90 backdrop-blur-md text-espresso text-[11px] font-semibold uppercase tracking-wider">
                  <span class="w-2 h-2 rounded-full bg-terracotta animate-pulse"></span>
                  Kurasi Pilihan Hari Ini
                </span>
                <?php if ($featuredBean !== null): ?>
                  <span class="px-3 py-1 rounded-full bg-espresso/80 backdrop-blur-md text-cream font-editorial italic text-sm">
                    Tersedia • <?= (int) $featuredBean['stok_gram'] ?>g
                  </span>
                <?php endif; ?>
              </div>

              <div class="absolute bottom-0 left-0 right-0 p-6 text-cream space-y-2">
                <?php if ($featuredBean !== null): ?>
                  <span class="text-[11px] uppercase tracking-[0.16em] text-[#DAC2B5] block font-semibold">
                    <?= e($featuredBean['origin']) ?> • <?= e($featuredBean['proses']) ?>
                  </span>
                  <h2 class="font-editorial text-3xl font-medium text-white">
                    <?= e($featuredBean['nama_kopi']) ?>
                  </h2>
                  <div class="flex flex-wrap items-center gap-1.5 pt-1">
                    <?php foreach (array_filter(array_map('trim', explode(',', (string) $featuredBean['tasting_notes']))) as $note): ?>
                      <span class="text-xs px-2.5 py-0.5 rounded-full bg-white/15 backdrop-blur-sm text-cream border border-white/20">
                        <?= e($note) ?>
                      </span>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <h2 class="font-editorial text-3xl font-medium text-white">Kala Seduh Slow Bar</h2>
                  <p class="text-sm text-[#DAC2B5]">Single origin pilihan diseduh manual untuk Anda.</p>
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
                  <div class="bg-white border border-sand rounded p-3 space-y-1.5">
                    <div class="flex items-center justify-between gap-2">
                      <span class="text-xs font-bold text-espresso tabular-nums"><?= e($ord['kode_pesanan']) ?> • <?= e($ord['nomor_meja']) ?></span>
                      <span class="text-[11px] px-2 py-0.5 rounded bg-surface-mid text-muted font-semibold"><?= e($ord['nama_metode']) ?> (<?= e($ord['tipe_sajian']) ?>)</span>
                    </div>
                    <p class="text-sm font-semibold text-espresso"><?= e($ord['nama_kopi']) ?></p>
                    <p class="text-xs text-muted">Atas nama <strong class="text-espresso"><?= e($ord['nama_pelanggan']) ?></strong> • <?= e(formatJamSingkat((string) $ord['created_at'])) ?></p>
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
                  <div class="bg-terracotta-soft/70 border border-[#F3D1C4] rounded p-3 space-y-1.5">
                    <div class="flex items-center justify-between gap-2">
                      <span class="text-xs font-bold text-[#8F3F27] tabular-nums"><?= e($ord['kode_pesanan']) ?> • <?= e($ord['nomor_meja']) ?></span>
                      <span class="text-[11px] px-2 py-0.5 rounded bg-terracotta text-white font-semibold"><?= e($ord['nama_metode']) ?> (<?= e($ord['tipe_sajian']) ?>)</span>
                    </div>
                    <p class="text-sm font-semibold text-espresso"><?= e($ord['nama_kopi']) ?></p>
                    <div class="flex items-center justify-between text-xs text-[#772F18]">
                      <span>Tamu: <strong><?= e($ord['nama_pelanggan']) ?></strong></span>
                      <span>Est. ~<?= (int) $ord['estimasi_menit'] ?> menit</span>
                    </div>
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
                  <div class="bg-sage-soft/70 border border-[#C2D6CD] rounded p-3 space-y-1.5">
                    <div class="flex items-center justify-between gap-2">
                      <span class="text-xs font-bold text-espresso tabular-nums"><?= e($ord['kode_pesanan']) ?> • <?= e($ord['nomor_meja']) ?></span>
                      <span class="text-[11px] px-2 py-0.5 rounded-full bg-white text-sage-text border border-[#C2D6CD] font-semibold">Siap Disajikan</span>
                    </div>
                    <p class="text-sm font-medium text-espresso"><?= e($ord['nama_kopi']) ?> • <?= e($ord['nama_metode']) ?></p>
                    <p class="text-xs text-muted">Tamu: <strong class="text-espresso"><?= e($ord['nama_pelanggan']) ?></strong> • <?= e(formatJamSingkat((string) $ord['created_at'])) ?></p>
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
              <span class="text-[11px] font-bold uppercase tracking-[0.18em] text-terracotta block">Katalog Biji Terpilih</span>
              <h2 class="font-editorial text-3xl sm:text-4xl text-espresso font-medium leading-tight">Pilihan Kurasi Slow Bar</h2>
              <p class="text-sm text-muted max-w-2xl">
                Setiap pesanan memotong stok gramasi secara real-time sesuai rasio alat seduh yang Anda pilih.
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
                  <!-- Gambar Kopi -->
                  <div class="relative h-48 w-full bg-surface-high overflow-hidden">
                    <img
                      src="<?= e($bean['gambar_url']) ?>"
                      alt="<?= e($bean['nama_kopi']) ?>"
                      class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                      loading="lazy"
                      onerror="this.style.display='none'"
                    />
                    <div class="absolute inset-x-0 top-0 h-16 bg-gradient-to-b from-espresso-dark/30 to-transparent pointer-events-none"></div>
                    <div class="absolute top-3 left-3">
                      <span class="px-2.5 py-1 rounded-full bg-white/95 backdrop-blur-sm text-espresso text-[11px] font-bold uppercase tracking-wider border border-sand shadow-sm">
                        <?= e($bean['proses']) ?>
                      </span>
                    </div>
                    <div class="absolute top-3 right-3">
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
                <div class="px-5 py-4 flex items-center justify-between border-t border-sand/80 bg-surface/50">
                  <div>
                    <span class="text-[10px] uppercase tracking-wider text-muted font-semibold block">Harga Mulai</span>
                    <span class="text-lg font-bold text-espresso tabular-nums"><?= e(formatRupiah((int) $bean['harga'])) ?></span>
                  </div>
                  <button
                    type="button"
                    id="btn-order-bean-<?= $beanId ?>"
                    onclick="openOrderDrawer(<?= $beanId ?>)"
                    <?= $isOut ? 'disabled' : '' ?>
                    class="px-4 py-2.5 rounded-lg text-xs font-semibold tracking-wide transition-colors flex items-center gap-1.5 whitespace-nowrap <?= $isOut ? 'bg-surface-mid text-outline cursor-not-allowed' : 'bg-espresso hover:bg-terracotta text-cream shadow-sm' ?>"
                  >
                    <span><?= $isOut ? 'Stok Habis' : 'Pilih & Seduh' ?></span>
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

      <!-- Section 4: Filosofi Slow Bar -->
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
  <div id="order-drawer" class="fixed inset-0 z-50 pointer-events-none opacity-0 transition-opacity duration-300 flex justify-end" aria-hidden="true">
    <div onclick="closeOrderDrawer()" class="absolute inset-0 bg-espresso-dark/50 backdrop-blur-sm"></div>

    <div id="order-drawer-panel" class="relative w-full max-w-lg bg-surface h-full shadow-2xl overflow-y-auto transform translate-x-full transition-transform duration-300 ease-out pointer-events-auto p-6 sm:p-8 flex flex-col justify-between border-l border-sand">
      <div class="space-y-6">
        <div class="flex items-start justify-between border-b border-sand pb-4">
          <div>
            <span class="text-[11px] font-bold uppercase tracking-widest text-terracotta">Konfigurasi Manual Brew</span>
            <h2 class="font-editorial text-2xl font-medium text-espresso" id="drawer-title">Buat Pesanan Seduh</h2>
            <p class="text-xs text-muted mt-0.5" id="drawer-subtitle">Pilih biji single origin, alat seduh, dan tipe sajian Anda.</p>
          </div>
          <button type="button" onclick="closeOrderDrawer()" class="w-9 h-9 rounded-full bg-surface-high hover:bg-sand flex items-center justify-center text-espresso transition-colors">
            <span class="material-symbols-outlined text-[20px]">close</span>
          </button>
        </div>

        <div id="order-error-banner" class="hidden px-4 py-3 rounded border bg-[#FFEBEE] border-[#F5C6CB] text-[#93000A] text-xs font-medium"></div>

        <form id="brew-order-form" action="/proses-order" method="POST" class="space-y-5" onsubmit="submitOrderForm(event)">
          <!-- Pilih Biji Kopi -->
          <div class="space-y-1.5">
            <label for="order-bean-select" class="block text-xs font-bold uppercase tracking-wider text-espresso">
              1. Pilih Biji Kopi
            </label>
            <select
              id="order-bean-select"
              name="biji_kopi_id"
              required
              onchange="updateOrderCalculator()"
              class="w-full h-11 px-3 rounded bg-white border border-sand text-sm text-espresso focus:outline-none focus:border-espresso"
            >
              <?php foreach ($beans as $b): ?>
                <option value="<?= (int) $b['id'] ?>">
                  <?= e($b['nama_kopi']) ?> — <?= e(formatRupiah((int) $b['harga'])) ?> (Sisa <?= (int) $b['stok_gram'] ?>g)
                </option>
              <?php endforeach; ?>
            </select>
            <p class="text-xs text-muted" id="drawer-bean-meta"></p>
          </div>

          <!-- Pilih Metode Seduh -->
          <div class="space-y-2">
            <span class="block text-xs font-bold uppercase tracking-wider text-espresso">
              2. Pilih Metode Seduh
            </span>
            <div class="grid grid-cols-2 gap-2.5" id="drawer-methods-grid">
              <?php foreach ($methods as $idx => $m): ?>
                <label class="cursor-pointer rounded-lg border border-sand bg-paper p-3 transition-all hover:border-espresso has-[:checked]:bg-espresso has-[:checked]:text-cream has-[:checked]:border-espresso">
                  <input
                    type="radio"
                    name="metode_seduh_id"
                    value="<?= (int) $m['id'] ?>"
                    class="hidden"
                    <?= $idx === 0 ? 'checked' : '' ?>
                    onchange="updateOrderCalculator()"
                  />
                  <div class="flex items-center justify-between gap-1">
                    <span class="font-semibold text-sm"><?= e($m['nama_metode']) ?></span>
                    <span class="text-[11px] opacity-80 tabular-nums"><?= (int) $m['rasio_kopi_gram'] ?>g</span>
                  </div>
                  <div class="text-[11px] opacity-80 mt-1 flex items-center justify-between">
                    <span>~<?= (int) $m['estimasi_menit'] ?> menit</span>
                    <span><?= (int) $m['biaya_tambahan'] > 0 ? '+' . e(formatRupiah((int) $m['biaya_tambahan'])) : 'Termasuk' ?></span>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Pilih Tipe Sajian -->
          <div class="space-y-2">
            <span class="block text-xs font-bold uppercase tracking-wider text-espresso">
              3. Tipe Sajian
            </span>
            <div class="grid grid-cols-2 gap-2.5">
              <label class="cursor-pointer rounded-lg border border-sand bg-paper p-3 flex items-center justify-center gap-2 transition-all has-[:checked]:bg-terracotta has-[:checked]:text-white has-[:checked]:border-terracotta">
                <input type="radio" name="tipe_sajian" value="Hot" checked class="hidden" onchange="updateOrderCalculator()" />
                <span class="material-symbols-outlined text-[18px]">local_fire_department</span>
                <span class="text-sm font-semibold">Hot (~65°C)</span>
              </label>
              <label class="cursor-pointer rounded-lg border border-sand bg-paper p-3 flex items-center justify-center gap-2 transition-all has-[:checked]:bg-terracotta has-[:checked]:text-white has-[:checked]:border-terracotta">
                <input type="radio" name="tipe_sajian" value="Iced" class="hidden" onchange="updateOrderCalculator()" />
                <span class="material-symbols-outlined text-[18px]">ac_unit</span>
                <span class="text-sm font-semibold">Iced (Flash Brew)</span>
              </label>
            </div>
          </div>

          <!-- Detail Pemesan -->
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="space-y-1">
              <label for="order-nama" class="block text-xs font-semibold text-espresso">Nama Pelanggan *</label>
              <input
                id="order-nama"
                name="nama_pelanggan"
                type="text"
                required
                maxlength="100"
                placeholder="Contoh: Nadia"
                class="w-full h-11 px-3 rounded bg-white border border-sand text-sm text-espresso focus:outline-none focus:border-espresso"
              />
            </div>
            <div class="space-y-1">
              <label for="order-meja" class="block text-xs font-semibold text-espresso">Nomor Meja / Bar *</label>
              <select
                id="order-meja"
                name="nomor_meja"
                required
                class="w-full h-11 px-3 rounded bg-white border border-sand text-sm text-espresso focus:outline-none focus:border-espresso"
              >
                <option value="Meja 01">Meja 01</option>
                <option value="Meja 02">Meja 02</option>
                <option value="Meja 03">Meja 03</option>
                <option value="Meja 04">Meja 04</option>
                <option value="Meja 05">Meja 05</option>
                <option value="Meja 06">Meja 06</option>
                <option value="Bar Seat 01">Bar Seat 01 (Slow Bar)</option>
                <option value="Bar Seat 02">Bar Seat 02 (Slow Bar)</option>
                <option value="Bar Seat 03">Bar Seat 03 (Slow Bar)</option>
                <option value="Bar Seat 04">Bar Seat 04 (Slow Bar)</option>
                <option value="Take Away">Take Away</option>
              </select>
            </div>
          </div>

          <div class="space-y-1">
            <label for="order-catatan" class="block text-xs font-semibold text-espresso">Catatan Khusus (Opsional)</label>
            <input
              id="order-catatan"
              name="catatan"
              type="text"
              maxlength="300"
              placeholder="Contoh: Fokus ke rasa manis / es batu sedikit"
              class="w-full h-11 px-3 rounded bg-white border border-sand text-sm text-espresso focus:outline-none focus:border-espresso"
            />
          </div>

          <!-- Ringkasan Harga & Validasi Stok -->
          <div class="bg-surface-low border border-sand rounded-lg p-4 space-y-2 text-xs">
            <div class="flex justify-between text-muted">
              <span>Harga Biji Kopi</span>
              <span class="font-semibold text-espresso tabular-nums" id="summary-bean-price">Rp 0</span>
            </div>
            <div class="flex justify-between text-muted">
              <span>Biaya Metode Seduh (<span id="summary-method-name">-</span>)</span>
              <span class="font-semibold text-espresso tabular-nums" id="summary-method-fee">Rp 0</span>
            </div>
            <div class="flex justify-between text-muted">
              <span>Kebutuhan Gramasi / Sisa Stok</span>
              <span class="font-semibold tabular-nums" id="summary-gram-check">-</span>
            </div>
            <div class="pt-2 border-t border-sand flex justify-between items-center text-sm font-bold text-espresso">
              <span>Total Pembayaran</span>
              <span class="text-lg text-terracotta tabular-nums" id="summary-total-price">Rp 0</span>
            </div>
          </div>

          <button
            type="submit"
            id="btn-submit-order"
            class="w-full h-12 rounded bg-terracotta hover:bg-terracotta-dark text-white font-semibold text-sm tracking-wide transition-colors flex items-center justify-center gap-2 shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <span class="material-symbols-outlined text-[18px]">local_cafe</span>
            <span id="btn-submit-label">Kirim Pesanan ke Barista</span>
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

    function openOrderDrawer(beanId) {
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
      const errBanner = document.getElementById('order-error-banner');
      if (errBanner) {
        errBanner.classList.add('hidden');
        errBanner.textContent = '';
      }

      updateOrderCalculator();

      const drawer = document.getElementById('order-drawer');
      const panel = document.getElementById('order-drawer-panel');
      drawer.setAttribute('aria-hidden', 'false');
      drawer.classList.remove('pointer-events-none', 'opacity-0');
      drawer.classList.add('opacity-100');
      panel.classList.remove('translate-x-full');
      panel.classList.add('translate-x-0');
    }

    function closeOrderDrawer() {
      const drawer = document.getElementById('order-drawer');
      const panel = document.getElementById('order-drawer-panel');
      drawer.setAttribute('aria-hidden', 'true');
      drawer.classList.add('opacity-0');
      panel.classList.remove('translate-x-0');
      panel.classList.add('translate-x-full');
      setTimeout(() => {
        drawer.classList.add('pointer-events-none');
      }, 300);
    }

    function updateOrderCalculator() {
      const beanId = Number(document.getElementById('order-bean-select')?.value || 0);
      const checkedMethod = document.querySelector('input[name="metode_seduh_id"]:checked');
      const methodId = Number(checkedMethod ? checkedMethod.value : 0);

      const bean = BEANS_DATA.find((b) => b.id === beanId);
      const method = METHODS_DATA.find((m) => m.id === methodId);

      const beanMeta = document.getElementById('drawer-bean-meta');
      const sumBean = document.getElementById('summary-bean-price');
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

      if (beanMeta) {
        beanMeta.textContent = `${bean.origin} • ${bean.proses} • ${bean.tasting_notes}`;
      }

      const cukup = bean.stok_gram >= method.rasio_kopi_gram;
      const total = bean.harga + method.biaya_tambahan;

      if (sumBean) sumBean.textContent = formatRupiah(bean.harga);
      if (sumMethodName) sumMethodName.textContent = method.nama_metode;
      if (sumMethodFee) sumMethodFee.textContent = formatRupiah(method.biaya_tambahan);
      if (sumTotal) sumTotal.textContent = formatRupiah(total);

      if (sumGramCheck) {
        sumGramCheck.textContent = `Butuh ${method.rasio_kopi_gram}g / Sisa ${bean.stok_gram}g`;
        sumGramCheck.className = cukup
          ? 'font-semibold text-sage-text tabular-nums'
          : 'font-semibold text-[#93000A] tabular-nums';
      }

      if (submitBtn && submitLabel) {
        submitBtn.disabled = !cukup;
        submitLabel.textContent = cukup
          ? 'Kirim Pesanan ke Barista'
          : `Stok ${bean.nama_kopi} Tidak Cukup`;
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
        btn.className = 'px-4 py-2.5 rounded text-xs font-semibold tracking-wide transition-colors flex items-center gap-1.5 ' + (
          isOut
            ? 'bg-surface-mid text-outline cursor-not-allowed'
            : 'bg-espresso hover:bg-terracotta text-cream shadow-sm'
        );
        btn.innerHTML = `<span>${isOut ? 'Stok Habis' : 'Pilih & Seduh'}</span><span class="material-symbols-outlined text-[16px]">coffee</span>`;
      }

      if (option && bean) {
        option.textContent = `${bean.nama_kopi} — ${formatRupiah(bean.harga)} (Sisa ${newStock}g)`;
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
        tipe_sajian: form.querySelector('input[name="tipe_sajian"]:checked')?.value || 'Hot',
        nama_pelanggan: form.nama_pelanggan.value.trim(),
        nomor_meja: form.nomor_meja.value,
        catatan: form.catatan.value.trim(),
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
        closeOrderDrawer();
        form.reset();
        const defaultHot = form.querySelector('input[name="tipe_sajian"][value="Hot"]');
        if (defaultHot) defaultHot.checked = true;
        const firstMethod = form.querySelector('input[name="metode_seduh_id"]');
        if (firstMethod) firstMethod.checked = true;

        showToast(
          `Pesanan ${data.order.kode_pesanan} Diterima`,
          `${data.order.nama_pelanggan} (${data.order.nomor_meja}) • ${data.order.nama_kopi} (${data.order.nama_metode}). Stok tersisa ${data.updated_stok_gram}g.`
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
            <div class="bg-white border border-sand rounded p-3 space-y-1.5">
              <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-bold text-espresso tabular-nums">${escapeHtml(o.kode_pesanan)} • ${escapeHtml(o.nomor_meja)}</span>
                <span class="text-[11px] px-2 py-0.5 rounded bg-surface-mid text-muted font-semibold">${escapeHtml(o.nama_metode)} (${escapeHtml(o.tipe_sajian)})</span>
              </div>
              <p class="text-sm font-semibold text-espresso">${escapeHtml(o.nama_kopi)}</p>
              <p class="text-xs text-muted">Atas nama <strong class="text-espresso">${escapeHtml(o.nama_pelanggan)}</strong> • ${escapeHtml(o.jam_wib)}</p>
            </div>
          `).join('');
      }

      const colSeduh = document.getElementById('col-seduh');
      if (colSeduh) {
        colSeduh.innerHTML = brewing.length === 0
          ? '<p class="text-xs text-muted italic py-4 text-center">Barista siap menyeduh pesanan berikutnya.</p>'
          : brewing.map((o) => `
            <div class="bg-terracotta-soft/70 border border-[#F3D1C4] rounded p-3 space-y-1.5">
              <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-bold text-[#8F3F27] tabular-nums">${escapeHtml(o.kode_pesanan)} • ${escapeHtml(o.nomor_meja)}</span>
                <span class="text-[11px] px-2 py-0.5 rounded bg-terracotta text-white font-semibold">${escapeHtml(o.nama_metode)} (${escapeHtml(o.tipe_sajian)})</span>
              </div>
              <p class="text-sm font-semibold text-espresso">${escapeHtml(o.nama_kopi)}</p>
              <div class="flex items-center justify-between text-xs text-[#772F18]">
                <span>Tamu: <strong>${escapeHtml(o.nama_pelanggan)}</strong></span>
                <span>Est. ~${Number(o.estimasi_menit)} menit</span>
              </div>
            </div>
          `).join('');
      }

      const colSelesai = document.getElementById('col-selesai');
      if (colSelesai) {
        colSelesai.innerHTML = done.length === 0
          ? '<p class="text-xs text-muted italic py-4 text-center">Belum ada pesanan selesai hari ini.</p>'
          : done.map((o) => `
            <div class="bg-sage-soft/70 border border-[#C2D6CD] rounded p-3 space-y-1.5">
              <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-bold text-espresso tabular-nums">${escapeHtml(o.kode_pesanan)} • ${escapeHtml(o.nomor_meja)}</span>
                <span class="text-[11px] px-2 py-0.5 rounded-full bg-white text-sage-text border border-[#C2D6CD] font-semibold">Siap Disajikan</span>
              </div>
              <p class="text-sm font-medium text-espresso">${escapeHtml(o.nama_kopi)} • ${escapeHtml(o.nama_metode)}</p>
              <p class="text-xs text-muted">Tamu: <strong class="text-espresso">${escapeHtml(o.nama_pelanggan)}</strong> • ${escapeHtml(o.jam_wib)}</p>
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
          updateOrderCalculator();
        }
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
    });
  </script>
</body>
</html>
