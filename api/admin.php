<?php
/**
 * Dashboard Admin & Barista — Kala Seduh Slow Bar.
 * Fitur: Statistik & Peringatan Stok < 100g, Manajemen Antrean Seduh,
 * CRUD Master Data Biji Kopi & Metode Seduh, dan Laporan Transaksi + Cetak.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

$currentUser = requireAuth();
$csrfToken = generateCsrfToken();

$allowedTabs = ['antrean', 'biji', 'metode', 'laporan'];
$activeTab = (string) ($_GET['tab'] ?? 'antrean');
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'antrean';
}

$todayDate = date('Y-m-d');
$defaultStartDate = date('Y-m-01');
$dariTanggal = (string) ($_GET['dari_tanggal'] ?? $defaultStartDate);
$sampaiTanggal = (string) ($_GET['sampai_tanggal'] ?? $todayDate);
$filterStatus = (string) ($_GET['status_filter'] ?? 'Semua');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dariTanggal)) {
    $dariTanggal = $defaultStartDate;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampaiTanggal)) {
    $sampaiTanggal = $todayDate;
}
if ($dariTanggal > $sampaiTanggal) {
    [$dariTanggal, $sampaiTanggal] = [$sampaiTanggal, $dariTanggal];
}

$allowedProses = ['Natural', 'Washed', 'Honey', 'Anaerobic'];
$allowedStatuses = ['Menunggu', 'Sedang Diseduh', 'Selesai'];
$defaultCoffeeImg = 'https://images.unsplash.com/photo-1590093217544-da597729d81a?auto=format&fit=crop&w=900&q=80';

$redirectAdmin = static function (string $tab, array $extraQuery = []): never {
    $params = array_merge(['tab' => $tab], $extraQuery);
    redirectTo('/admin?' . http_build_query($params));
};

// ============================================================================
// Penanganan Aksi POST (PRG Pattern)
// ============================================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postTab = (string) ($_POST['tab'] ?? $activeTab);
    if (!in_array($postTab, $allowedTabs, true)) {
        $postTab = 'antrean';
    }

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlashMessage('error', 'Sesi keamanan tidak valid. Silakan muat ulang halaman dan coba lagi.');
        $redirectAdmin($postTab);
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        $pdo = getDbConnection();
        ensureBijiKopiHargaPerKgColumn($pdo);
        syncIndonesianBeansCatalog($pdo);

        switch ($action) {
            case 'update_status':
                $orderId = (int) ($_POST['order_id'] ?? 0);
                $newStatus = (string) ($_POST['status'] ?? '');
                if ($orderId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
                    setFlashMessage('error', 'Parameter pembaruan status pesanan tidak valid.');
                    $redirectAdmin('antrean');
                }
                $stmt = $pdo->prepare('UPDATE pesanan SET status = :status WHERE id = :id RETURNING kode_pesanan');
                $stmt->execute([':status' => $newStatus, ':id' => $orderId]);
                $updated = $stmt->fetch();
                if ($updated) {
                    setFlashMessage('success', "Status pesanan {$updated['kode_pesanan']} diperbarui menjadi \"{$newStatus}\".");
                } else {
                    setFlashMessage('error', 'Pesanan tidak ditemukan.');
                }
                $redirectAdmin('antrean');

            case 'add_bean':
                $nama = trim((string) ($_POST['nama_kopi'] ?? ''));
                $origin = trim((string) ($_POST['origin'] ?? ''));
                $proses = (string) ($_POST['proses'] ?? '');
                $notes = trim((string) ($_POST['tasting_notes'] ?? ''));
                $stok = max(0, (int) ($_POST['stok_gram'] ?? 0));
                $harga = max(0, (int) ($_POST['harga'] ?? 0));
                $hargaPerKg = max(0, (int) ($_POST['harga_per_kg'] ?? 0));
                $gambar = trim((string) ($_POST['gambar_url'] ?? ''));

                if ($gambar === '' || !filter_var($gambar, FILTER_VALIDATE_URL)) {
                    $gambar = $defaultCoffeeImg;
                }
                if ($nama === '' || $origin === '' || $notes === '' || !in_array($proses, $allowedProses, true)) {
                    setFlashMessage('error', 'Lengkapi seluruh data biji kopi dengan proses yang valid.');
                    $redirectAdmin('biji');
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO biji_kopi (nama_kopi, origin, proses, tasting_notes, stok_gram, harga, harga_per_kg, gambar_url)
                     VALUES (:nama, :origin, :proses, :notes, :stok, :harga, :harga_kg, :gambar)'
                );
                $stmt->execute([
                    ':nama'     => $nama,
                    ':origin'   => $origin,
                    ':proses'   => $proses,
                    ':notes'    => $notes,
                    ':stok'     => $stok,
                    ':harga'    => $harga,
                    ':harga_kg' => $hargaPerKg,
                    ':gambar'   => $gambar,
                ]);
                setFlashMessage('success', "Biji kopi \"{$nama}\" berhasil ditambahkan ke katalog.");
                $redirectAdmin('biji');

            case 'edit_bean':
                $id = (int) ($_POST['id'] ?? 0);
                $nama = trim((string) ($_POST['nama_kopi'] ?? ''));
                $origin = trim((string) ($_POST['origin'] ?? ''));
                $proses = (string) ($_POST['proses'] ?? '');
                $notes = trim((string) ($_POST['tasting_notes'] ?? ''));
                $stok = max(0, (int) ($_POST['stok_gram'] ?? 0));
                $harga = max(0, (int) ($_POST['harga'] ?? 0));
                $hargaPerKg = max(0, (int) ($_POST['harga_per_kg'] ?? 0));
                $gambar = trim((string) ($_POST['gambar_url'] ?? ''));

                if ($gambar === '' || !filter_var($gambar, FILTER_VALIDATE_URL)) {
                    $gambar = $defaultCoffeeImg;
                }
                if ($id <= 0 || $nama === '' || $origin === '' || $notes === '' || !in_array($proses, $allowedProses, true)) {
                    setFlashMessage('error', 'Data pembaruan biji kopi tidak lengkap atau tidak valid.');
                    $redirectAdmin('biji');
                }

                $stmt = $pdo->prepare(
                    'UPDATE biji_kopi
                     SET nama_kopi = :nama, origin = :origin, proses = :proses,
                         tasting_notes = :notes, stok_gram = :stok, harga = :harga,
                         harga_per_kg = :harga_kg, gambar_url = :gambar
                     WHERE id = :id'
                );
                $stmt->execute([
                    ':id'       => $id,
                    ':nama'     => $nama,
                    ':origin'   => $origin,
                    ':proses'   => $proses,
                    ':notes'    => $notes,
                    ':stok'     => $stok,
                    ':harga'    => $harga,
                    ':harga_kg' => $hargaPerKg,
                    ':gambar'   => $gambar,
                ]);
                setFlashMessage('success', "Data biji kopi \"{$nama}\" berhasil diperbarui.");
                $redirectAdmin('biji');

            case 'restock_bean':
                $id = (int) ($_POST['id'] ?? 0);
                $mode = (string) ($_POST['restock_mode'] ?? 'add');
                $gram = max(0, (int) ($_POST['gram'] ?? 0));

                if ($id <= 0) {
                    setFlashMessage('error', 'ID biji kopi tidak valid.');
                    $redirectAdmin('biji');
                }

                if ($mode === 'set') {
                    $stmt = $pdo->prepare('UPDATE biji_kopi SET stok_gram = :gram WHERE id = :id RETURNING nama_kopi, stok_gram');
                    $stmt->execute([':gram' => $gram, ':id' => $id]);
                } else {
                    $stmt = $pdo->prepare('UPDATE biji_kopi SET stok_gram = stok_gram + :gram WHERE id = :id RETURNING nama_kopi, stok_gram');
                    $stmt->execute([':gram' => $gram, ':id' => $id]);
                }
                $res = $stmt->fetch();
                if ($res) {
                    setFlashMessage('success', "Stok {$res['nama_kopi']} berhasil diperbarui menjadi {$res['stok_gram']} gram.");
                } else {
                    setFlashMessage('error', 'Biji kopi tidak ditemukan.');
                }
                $redirectAdmin($postTab);

            case 'delete_bean':
                $id = (int) ($_POST['id'] ?? 0);
                if ($id <= 0) {
                    setFlashMessage('error', 'ID biji kopi tidak valid.');
                    $redirectAdmin('biji');
                }
                try {
                    $stmt = $pdo->prepare('DELETE FROM biji_kopi WHERE id = :id RETURNING nama_kopi');
                    $stmt->execute([':id' => $id]);
                    $deleted = $stmt->fetch();
                    if ($deleted) {
                        setFlashMessage('success', "Biji kopi \"{$deleted['nama_kopi']}\" telah dihapus.");
                    } else {
                        setFlashMessage('error', 'Biji kopi tidak ditemukan.');
                    }
                } catch (PDOException $fkEx) {
                    if ($fkEx->getCode() === '23503') {
                        setFlashMessage('error', 'Biji kopi tidak dapat dihapus karena sudah memiliki riwayat transaksi. Anda dapat mengubah stoknya menjadi 0 gram.');
                    } else {
                        throw $fkEx;
                    }
                }
                $redirectAdmin('biji');

            case 'add_method':
                ensureMetodeSeduhStockColumn($pdo);
                $namaMetode = trim((string) ($_POST['nama_metode'] ?? ''));
                $rasio = max(1, (int) ($_POST['rasio_kopi_gram'] ?? 15));
                $estimasi = max(1, (int) ($_POST['estimasi_menit'] ?? 4));
                $biaya = max(0, (int) ($_POST['biaya_tambahan'] ?? 0));
                $rawStokPorsi = trim((string) ($_POST['stok_porsi'] ?? ''));
                $stokPorsi = $rawStokPorsi === '' ? null : max(0, (int) $rawStokPorsi);

                if ($namaMetode === '') {
                    setFlashMessage('error', 'Nama metode seduh wajib diisi.');
                    $redirectAdmin('metode');
                }
                $stmt = $pdo->prepare(
                    'INSERT INTO metode_seduh (nama_metode, rasio_kopi_gram, estimasi_menit, biaya_tambahan, stok_porsi)
                     VALUES (:nama, :rasio, :estimasi, :biaya, :stok_porsi)'
                );
                $stmt->bindValue(':nama', $namaMetode);
                $stmt->bindValue(':rasio', $rasio, PDO::PARAM_INT);
                $stmt->bindValue(':estimasi', $estimasi, PDO::PARAM_INT);
                $stmt->bindValue(':biaya', $biaya, PDO::PARAM_INT);
                $stmt->bindValue(':stok_porsi', $stokPorsi, $stokPorsi === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmt->execute();
                setFlashMessage('success', "Metode seduh \"{$namaMetode}\" berhasil ditambahkan.");
                $redirectAdmin('metode');

            case 'edit_method':
                ensureMetodeSeduhStockColumn($pdo);
                $id = (int) ($_POST['id'] ?? 0);
                $namaMetode = trim((string) ($_POST['nama_metode'] ?? ''));
                $rasio = max(1, (int) ($_POST['rasio_kopi_gram'] ?? 15));
                $estimasi = max(1, (int) ($_POST['estimasi_menit'] ?? 4));
                $biaya = max(0, (int) ($_POST['biaya_tambahan'] ?? 0));
                $rawStokPorsi = trim((string) ($_POST['stok_porsi'] ?? ''));
                $stokPorsi = $rawStokPorsi === '' ? null : max(0, (int) $rawStokPorsi);

                if ($id <= 0 || $namaMetode === '') {
                    setFlashMessage('error', 'Data metode seduh tidak valid.');
                    $redirectAdmin('metode');
                }
                $stmt = $pdo->prepare(
                    'UPDATE metode_seduh
                     SET nama_metode = :nama, rasio_kopi_gram = :rasio, estimasi_menit = :estimasi, biaya_tambahan = :biaya, stok_porsi = :stok_porsi
                     WHERE id = :id'
                );
                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $stmt->bindValue(':nama', $namaMetode);
                $stmt->bindValue(':rasio', $rasio, PDO::PARAM_INT);
                $stmt->bindValue(':estimasi', $estimasi, PDO::PARAM_INT);
                $stmt->bindValue(':biaya', $biaya, PDO::PARAM_INT);
                $stmt->bindValue(':stok_porsi', $stokPorsi, $stokPorsi === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmt->execute();
                setFlashMessage('success', "Metode seduh \"{$namaMetode}\" berhasil diperbarui.");
                $redirectAdmin('metode');

            case 'restock_method':
                ensureMetodeSeduhStockColumn($pdo);
                $id = (int) ($_POST['id'] ?? 0);
                $porsi = max(0, (int) ($_POST['porsi'] ?? 0));
                $mode = (string) ($_POST['restock_mode'] ?? 'add');

                if ($id <= 0) {
                    setFlashMessage('error', 'ID metode seduh tidak valid.');
                    $redirectAdmin('metode');
                }
                if ($mode === 'set') {
                    $stmt = $pdo->prepare('UPDATE metode_seduh SET stok_porsi = :porsi WHERE id = :id RETURNING nama_metode, stok_porsi');
                    $stmt->execute([':porsi' => $porsi, ':id' => $id]);
                } else {
                    $stmt = $pdo->prepare('UPDATE metode_seduh SET stok_porsi = COALESCE(stok_porsi, 0) + :porsi WHERE id = :id RETURNING nama_metode, stok_porsi');
                    $stmt->execute([':porsi' => $porsi, ':id' => $id]);
                }
                $resM = $stmt->fetch();
                if ($resM) {
                    setFlashMessage('success', "Persediaan {$resM['nama_metode']} diperbarui menjadi {$resM['stok_porsi']} porsi.");
                }
                $redirectAdmin('metode');

            case 'delete_method':
                $id = (int) ($_POST['id'] ?? 0);
                if ($id <= 0) {
                    setFlashMessage('error', 'ID metode seduh tidak valid.');
                    $redirectAdmin('metode');
                }
                try {
                    $stmt = $pdo->prepare('DELETE FROM metode_seduh WHERE id = :id RETURNING nama_metode');
                    $stmt->execute([':id' => $id]);
                    $deleted = $stmt->fetch();
                    if ($deleted) {
                        setFlashMessage('success', "Metode seduh \"{$deleted['nama_metode']}\" berhasil dihapus.");
                    } else {
                        setFlashMessage('error', 'Metode seduh tidak ditemukan.');
                    }
                } catch (PDOException $fkEx) {
                    if ($fkEx->getCode() === '23503') {
                        setFlashMessage('error', 'Metode seduh tidak dapat dihapus karena masih digunakan pada riwayat pesanan.');
                    } else {
                        throw $fkEx;
                    }
                }
                $redirectAdmin('metode');

            default:
                setFlashMessage('error', 'Aksi tidak dikenali.');
                $redirectAdmin($postTab);
        }
    } catch (Throwable $e) {
        error_log('[Admin Action Error] ' . $e->getMessage());
        setFlashMessage('error', 'Gagal memproses permintaan: ' . $e->getMessage());
        $redirectAdmin($postTab);
    }
}

// ============================================================================
// Pengambilan Data Dashboard (GET)
// ============================================================================
$flash = consumeFlashMessage();
$dbError = null;
$stats = [
    'total_pendapatan'  => 0,
    'pendapatan_hari'   => 0,
    'pesanan_hari_ini'  => 0,
    'pesanan_aktif'     => 0,
    'total_gram_stok'   => 0,
];
$lowStockBeans = [];
$beans = [];
$methods = [];
$queueOrders = [];
$reportOrders = [];
$reportOmzet = 0;

try {
    $pdo = getDbConnection();

    $kpiRow = $pdo->query(
        "SELECT
            COALESCE(SUM(total_harga), 0) AS total_pendapatan,
            COALESCE(SUM(CASE WHEN (created_at AT TIME ZONE 'Asia/Jakarta')::date = (NOW() AT TIME ZONE 'Asia/Jakarta')::date THEN total_harga ELSE 0 END), 0) AS pendapatan_hari,
            COUNT(*) FILTER (WHERE (created_at AT TIME ZONE 'Asia/Jakarta')::date = (NOW() AT TIME ZONE 'Asia/Jakarta')::date) AS pesanan_hari_ini,
            COUNT(*) FILTER (WHERE status IN ('Menunggu', 'Sedang Diseduh')) AS pesanan_aktif
         FROM pesanan"
    )->fetch();

    if ($kpiRow) {
        $stats['total_pendapatan'] = (int) $kpiRow['total_pendapatan'];
        $stats['pendapatan_hari'] = (int) $kpiRow['pendapatan_hari'];
        $stats['pesanan_hari_ini'] = (int) $kpiRow['pesanan_hari_ini'];
        $stats['pesanan_aktif'] = (int) $kpiRow['pesanan_aktif'];
    }

    ensureBijiKopiHargaPerKgColumn($pdo);
    syncIndonesianBeansCatalog($pdo);

    $beans = $pdo->query(
        'SELECT id, nama_kopi, origin, proses, tasting_notes, stok_gram, harga, harga_per_kg, gambar_url
         FROM biji_kopi
         ORDER BY id ASC'
    )->fetchAll();

    foreach ($beans as $b) {
        $gram = (int) $b['stok_gram'];
        $stats['total_gram_stok'] += $gram;
        if ($gram < 100) {
            $lowStockBeans[] = $b;
        }
    }

    ensureMetodeSeduhStockColumn($pdo);

    $methods = $pdo->query(
        'SELECT id, nama_metode, rasio_kopi_gram, estimasi_menit, biaya_tambahan, stok_porsi
         FROM metode_seduh
         ORDER BY id ASC'
    )->fetchAll();
    $methods = syncExtraDefaultMethods($pdo, $methods);
    $methods = removeColdBrewMethod($pdo, $methods);

    $lowStockMethods = [];
    foreach ($methods as $mRow) {
        if ($mRow['stok_porsi'] !== null && (int) $mRow['stok_porsi'] <= 3) {
            $lowStockMethods[] = $mRow;
        }
    }

    $queueOrders = $pdo->query(
        "SELECT
            p.id, p.kode_pesanan, p.nama_pelanggan, p.nomor_meja,
            p.tipe_sajian, p.catatan, p.total_harga, p.status, p.created_at,
            b.nama_kopi, b.proses, m.nama_metode, m.rasio_kopi_gram, m.estimasi_menit
         FROM pesanan p
         JOIN biji_kopi b ON b.id = p.biji_kopi_id
         JOIN metode_seduh m ON m.id = p.metode_seduh_id
         WHERE p.status IN ('Menunggu', 'Sedang Diseduh')
            OR (
                p.status = 'Selesai'
                AND (p.created_at AT TIME ZONE 'Asia/Jakarta')::date = (NOW() AT TIME ZONE 'Asia/Jakarta')::date
            )
         ORDER BY p.created_at ASC"
    )->fetchAll();

    $reportSql = "SELECT
            p.id, p.kode_pesanan, p.nama_pelanggan, p.nomor_meja,
            p.tipe_sajian, p.catatan, p.total_harga, p.status, p.created_at,
            b.nama_kopi, m.nama_metode, m.rasio_kopi_gram
         FROM pesanan p
         JOIN biji_kopi b ON b.id = p.biji_kopi_id
         JOIN metode_seduh m ON m.id = p.metode_seduh_id
         WHERE (p.created_at AT TIME ZONE 'Asia/Jakarta')::date BETWEEN :dari AND :sampai";
    $reportParams = [
        ':dari'   => $dariTanggal,
        ':sampai' => $sampaiTanggal,
    ];
    if (in_array($filterStatus, $allowedStatuses, true)) {
        $reportSql .= ' AND p.status = :status_filter';
        $reportParams[':status_filter'] = $filterStatus;
    }
    $reportSql .= ' ORDER BY p.created_at DESC';

    $stmtReport = $pdo->prepare($reportSql);
    $stmtReport->execute($reportParams);
    $reportOrders = $stmtReport->fetchAll();

    foreach ($reportOrders as $ro) {
        $reportOmzet += (int) $ro['total_harga'];
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
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
$groupedQueue['Selesai'] = array_reverse($groupedQueue['Selesai']);

$pageTitle = 'Dashboard Barista & Admin — Kala Seduh';
$isPrintPage = true;
require __DIR__ . '/partials/head.php';
?>
<body class="bg-cream text-espresso antialiased min-h-screen">
  <!-- Backdrop Mobile Sidebar -->
  <div id="mobile-sidebar-backdrop" onclick="toggleSidebar(false)" class="fixed inset-0 bg-espresso-dark/50 z-40 hidden lg:hidden no-print"></div>

  <!-- Sidebar Navigasi -->
  <aside id="admin-sidebar" class="fixed left-0 top-0 h-full w-72 bg-surface-low border-r border-sand z-50 flex flex-col justify-between py-6 transform -translate-x-full lg:translate-x-0 transition-transform duration-300 no-print">
    <div>
      <div class="px-6 flex items-center justify-between mb-6">
        <a href="/" class="flex items-center gap-3">
          <div class="w-9 h-9 rounded bg-espresso text-cream flex items-center justify-center">
            <span class="material-symbols-outlined text-[20px]">coffee_maker</span>
          </div>
          <div>
            <span class="font-editorial text-xl font-semibold text-espresso block leading-none">Kala Seduh</span>
            <span class="text-[10px] uppercase tracking-[0.18em] text-muted font-semibold mt-1 block">Workspace Bar</span>
          </div>
        </a>
        <button type="button" onclick="toggleSidebar(false)" class="lg:hidden p-1 text-muted hover:text-espresso">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>

      <!-- Info Akun Aktif -->
      <div class="mx-5 mb-6 p-3.5 rounded-lg bg-surface-high border border-sand flex items-center gap-3">
        <div class="w-2.5 h-2.5 rounded-full bg-terracotta animate-pulse shrink-0"></div>
        <div class="min-w-0">
          <span class="text-sm font-bold text-espresso block truncate"><?= e($currentUser['nama_lengkap']) ?></span>
          <span class="text-xs text-muted uppercase tracking-wider font-semibold">
            <?= e($currentUser['role']) ?> • @<?= e($currentUser['username']) ?>
          </span>
        </div>
      </div>

      <nav class="px-4 space-y-1.5">
        <?php
          $navItems = [
              ['tab' => 'antrean', 'icon' => 'hourglass_top', 'label' => 'Antrean & Operasional', 'badge' => $stats['pesanan_aktif']],
              ['tab' => 'biji',    'icon' => 'inventory_2',   'label' => 'Master Biji Kopi',      'badge' => count($lowStockBeans) > 0 ? count($lowStockBeans) . '!' : null],
              ['tab' => 'metode',  'icon' => 'local_cafe',    'label' => 'Metode Seduh',          'badge' => null],
              ['tab' => 'laporan', 'icon' => 'receipt_long',  'label' => 'Laporan Transaksi',     'badge' => null],
          ];
          foreach ($navItems as $item):
              $isActive = $activeTab === $item['tab'];
        ?>
          <a
            href="/admin?tab=<?= e($item['tab']) ?>"
            class="flex items-center justify-between px-4 py-2.5 rounded-lg text-sm font-semibold transition-colors <?= $isActive ? 'bg-espresso text-cream shadow-sm' : 'text-muted hover:bg-surface-mid hover:text-espresso' ?>"
          >
            <span class="flex items-center gap-3">
              <span class="material-symbols-outlined text-[20px]"><?= e($item['icon']) ?></span>
              <span><?= e($item['label']) ?></span>
            </span>
            <?php if ($item['badge'] !== null && (string) $item['badge'] !== '0'): ?>
              <span class="px-2 py-0.5 rounded-full text-[11px] font-bold <?= $isActive ? 'bg-terracotta text-white' : 'bg-terracotta-soft text-[#8F3F27]' ?>">
                <?= e((string) $item['badge']) ?>
              </span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </nav>
    </div>

    <div class="px-4 space-y-2 border-t border-sand pt-4">
      <a href="/" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm font-semibold text-muted hover:bg-surface-mid hover:text-espresso transition-colors">
        <span class="material-symbols-outlined text-[20px]">storefront</span>
        <span>Halaman Pelanggan</span>
      </a>
      <a href="/logout" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm font-semibold text-[#93000A] hover:bg-[#FFEBEE] transition-colors">
        <span class="material-symbols-outlined text-[20px]">logout</span>
        <span>Keluar (Logout)</span>
      </a>
    </div>
  </aside>

  <!-- Konten Utama -->
  <div class="lg:pl-72 main-shell min-h-screen flex flex-col">
    <!-- Top Header -->
    <header class="sticky top-0 z-30 h-16 bg-surface/95 backdrop-blur-md border-b border-sand px-5 lg:px-8 flex items-center justify-between gap-4 no-print">
      <div class="flex items-center gap-3">
        <button type="button" onclick="toggleSidebar(true)" class="lg:hidden p-2 rounded-lg bg-surface-mid text-espresso">
          <span class="material-symbols-outlined text-[20px]">menu</span>
        </button>
        <div>
          <h1 class="font-editorial text-xl sm:text-2xl text-espresso font-medium leading-tight">
            <?php
              echo match ($activeTab) {
                  'biji'    => 'Master Data Biji Kopi & Stok',
                  'metode'  => 'Master Metode Seduh Manual',
                  'laporan' => 'Laporan Transaksi & Rekap Omzet',
                  default   => 'Dashboard Operasional & Antrean Barista',
              };
            ?>
          </h1>
        </div>
      </div>

      <div class="flex items-center gap-2.5">
        <span class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-surface-mid border border-sand text-xs font-medium text-muted tabular-nums">
          <span class="material-symbols-outlined text-[15px] text-terracotta">schedule</span>
          <?= date('d M Y • H:i') ?> WIB
        </span>
        <button
          type="button"
          onclick="openWalkinModal()"
          class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-terracotta hover:bg-terracotta-dark text-white text-xs font-semibold transition-colors shadow-sm"
        >
          <span class="material-symbols-outlined text-[17px]">add</span>
          <span class="hidden sm:inline">Catat Pesanan Walk-in</span>
          <span class="sm:hidden">Order</span>
        </button>
      </div>
    </header>

    <main class="flex-1 p-5 lg:p-8 space-y-6">
      <!-- Kop Laporan Khusus Print -->
      <div class="print-only border-b-2 border-espresso pb-4 mb-6">
        <div class="flex items-start justify-between">
          <div>
            <h1 class="font-editorial text-2xl font-bold text-espresso">KALA SEDUH SLOW BAR</h1>
            <p class="text-xs text-muted">Laporan Rekapitulasi Transaksi Manual Brew</p>
          </div>
          <div class="text-right text-xs">
            <p>Periode: <strong><?= e($dariTanggal) ?></strong> s/d <strong><?= e($sampaiTanggal) ?></strong></p>
            <p>Dicetak oleh: <?= e($currentUser['nama_lengkap']) ?> (<?= date('d/m/Y H:i') ?> WIB)</p>
          </div>
        </div>
      </div>

      <?php if ($flash !== null): ?>
        <?php
          $flashStyle = match ($flash['type']) {
              'success' => 'bg-sage-soft border-[#C2D6CD] text-sage-text',
              'error'   => 'bg-[#FFEBEE] border-[#F5C6CB] text-[#93000A]',
              default   => 'bg-surface-mid border-sand text-espresso',
          };
        ?>
        <div class="no-print px-4 py-3 rounded-lg border text-sm font-medium flex items-center gap-2 <?= $flashStyle ?>">
          <span class="material-symbols-outlined text-[18px]">
            <?= $flash['type'] === 'success' ? 'check_circle' : ($flash['type'] === 'error' ? 'error' : 'info') ?>
          </span>
          <span><?= e($flash['message']) ?></span>
        </div>
      <?php endif; ?>

      <?php if ($dbError !== null): ?>
        <div class="no-print px-5 py-4 rounded-xl border bg-[#FFEBEE] border-[#F5C6CB] text-[#93000A] text-sm">
          <strong>Kesalahan Database:</strong> <?= e($dbError) ?>
        </div>
      <?php endif; ?>

      <!-- Banner Peringatan Stok < 100 Gram atau Persediaan Teknik Seduh Menipis -->
      <?php if (!empty($lowStockBeans) || !empty($lowStockMethods)): ?>
        <div class="no-print bg-terracotta-soft border border-[#E7B6A5] rounded-xl p-4 flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div class="flex items-start gap-3">
            <div class="w-9 h-9 rounded-full bg-terracotta text-white flex items-center justify-center shrink-0 mt-0.5">
              <span class="material-symbols-outlined text-[20px]">warning</span>
            </div>
            <div class="space-y-1">
              <h2 class="text-sm font-bold text-[#772F18]">
                Peringatan Stok &amp; Persediaan Menipis
              </h2>
              <?php if (!empty($lowStockBeans)): ?>
                <p class="text-xs text-[#8F3F27]">
                  <strong>Beans (&lt; 100g):</strong>
                  <?php
                    $lowLabels = array_map(
                        static fn(array $lb): string => e($lb['nama_kopi']) . ' (' . (int) $lb['stok_gram'] . 'g)',
                        $lowStockBeans
                    );
                    echo implode(' • ', $lowLabels);
                  ?>
                </p>
              <?php endif; ?>
              <?php if (!empty($lowStockMethods)): ?>
                <p class="text-xs text-[#8F3F27]">
                  <strong>Persediaan Teknik Seduh (&le; 3 porsi):</strong>
                  <?php
                    $lowMethodLabels = array_map(
                        static fn(array $lm): string => e($lm['nama_metode']) . ' (' . (int) $lm['stok_porsi'] . ' porsi)',
                        $lowStockMethods
                    );
                    echo implode(' • ', $lowMethodLabels);
                  ?>
                </p>
              <?php endif; ?>
            </div>
          </div>
          <a href="/admin?tab=<?= !empty($lowStockBeans) ? 'biji' : 'metode' ?>" class="shrink-0 inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-espresso hover:bg-[#3D2C22] text-cream text-xs font-semibold transition-colors">
            <span>Kelola Stok</span>
            <span class="material-symbols-outlined text-[16px]">arrow_forward</span>
          </a>
        </div>
      <?php endif; ?>

      <!-- KPI Summary Bento (Selalu tampil di non-print) -->
      <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 no-print">
        <div class="bg-paper border border-sand rounded-xl p-5 space-y-2">
          <div class="flex items-center justify-between text-xs uppercase tracking-wider text-muted font-semibold">
            <span>Total Pendapatan</span>
            <span class="material-symbols-outlined text-[20px] text-terracotta">payments</span>
          </div>
          <div class="font-editorial text-3xl font-semibold text-espresso tabular-nums">
            <?= e(formatRupiah($stats['total_pendapatan'])) ?>
          </div>
          <p class="text-xs text-muted">
            Hari ini: <strong class="text-espresso tabular-nums"><?= e(formatRupiah($stats['pendapatan_hari'])) ?></strong>
          </p>
        </div>

        <div class="bg-paper border border-sand rounded-xl p-5 space-y-2">
          <div class="flex items-center justify-between text-xs uppercase tracking-wider text-muted font-semibold">
            <span>Pesanan Hari Ini</span>
            <span class="material-symbols-outlined text-[20px] text-terracotta">coffee</span>
          </div>
          <div class="font-editorial text-3xl font-semibold text-espresso tabular-nums">
            <?= $stats['pesanan_hari_ini'] ?> <span class="text-base font-sans font-normal text-muted">Pesanan</span>
          </div>
          <p class="text-xs text-muted">
            Aktif di bar: <strong class="text-terracotta"><?= $stats['pesanan_aktif'] ?> antrean</strong>
          </p>
        </div>

        <div class="bg-paper border border-sand rounded-xl p-5 space-y-2">
          <div class="flex items-center justify-between text-xs uppercase tracking-wider text-muted font-semibold">
            <span>Total Stok Biji</span>
            <span class="material-symbols-outlined text-[20px] text-espresso">scale</span>
          </div>
          <div class="font-editorial text-3xl font-semibold text-espresso tabular-nums">
            <?= number_format($stats['total_gram_stok'], 0, ',', '.') ?> <span class="text-base font-sans font-normal text-muted">gram</span>
          </div>
          <p class="text-xs text-muted">
            Dari <strong><?= count($beans) ?> varietas</strong> single origin Nusantara
          </p>
        </div>

        <div class="bg-paper border <?= !empty($lowStockBeans) ? 'border-[#E7B6A5] bg-terracotta-soft/40' : 'border-sand' ?> rounded-xl p-5 space-y-2">
          <div class="flex items-center justify-between text-xs uppercase tracking-wider font-semibold <?= !empty($lowStockBeans) ? 'text-[#8F3F27]' : 'text-muted' ?>">
            <span>Stok &lt; 100 Gram</span>
            <span class="material-symbols-outlined text-[20px] <?= !empty($lowStockBeans) ? 'text-terracotta' : 'text-sage-text' ?>">
              <?= !empty($lowStockBeans) ? 'warning' : 'verified' ?>
            </span>
          </div>
          <div class="font-editorial text-3xl font-semibold tabular-nums <?= !empty($lowStockBeans) ? 'text-[#8F3F27]' : 'text-sage-text' ?>">
            <?= count($lowStockBeans) ?> <span class="text-base font-sans font-normal">Varian</span>
          </div>
          <p class="text-xs <?= !empty($lowStockBeans) ? 'text-[#8F3F27]' : 'text-muted' ?>">
            <?= !empty($lowStockBeans) ? 'Perlu segera restock' : 'Semua stok dalam batas aman' ?>
          </p>
        </div>
      </section>

      <!-- ================================================================ -->
      <!-- TAB 1: MANAJEMEN ANTREAN SEDUH                                   -->
      <!-- ================================================================ -->
      <?php if ($activeTab === 'antrean'): ?>
        <section class="space-y-4">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
              <h2 class="font-editorial text-2xl text-espresso font-medium">Papan Antrean Seduh Barista</h2>
              <p class="text-xs text-muted">Klik tombol aksi untuk memindahkan status pesanan secara real-time.</p>
            </div>
            <a href="/admin?tab=antrean" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-paper border border-sand hover:bg-surface-high text-xs font-semibold text-espresso self-start">
              <span class="material-symbols-outlined text-[16px]">refresh</span>
              <span>Muat Ulang Antrean</span>
            </a>
          </div>

          <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">
            <!-- Kolom 1: Menunggu -->
            <div class="bg-surface-low border border-sand rounded-xl p-4 space-y-3">
              <div class="flex items-center justify-between pb-2 border-b border-sand">
                <h3 class="text-sm font-bold text-espresso flex items-center gap-2">
                  <span class="w-2.5 h-2.5 rounded-full bg-outline"></span>
                  1. Menunggu
                </h3>
                <span class="px-2.5 py-0.5 rounded-full bg-surface-high text-xs font-bold text-espresso">
                  <?= count($groupedQueue['Menunggu']) ?>
                </span>
              </div>

              <?php if (empty($groupedQueue['Menunggu'])): ?>
                <div class="py-8 text-center text-xs text-muted italic">Tidak ada antrean menunggu.</div>
              <?php else: ?>
                <div class="space-y-3">
                  <?php foreach ($groupedQueue['Menunggu'] as $ord): ?>
                    <div class="bg-white border border-sand rounded-lg p-4 space-y-2.5 shadow-sm">
                      <div class="flex items-start justify-between gap-2">
                        <div>
                          <span class="text-[11px] font-mono text-muted"><?= e($ord['kode_pesanan']) ?> • <?= e(formatJamSingkat((string) $ord['created_at'])) ?></span>
                          <h4 class="text-sm font-bold text-espresso">
                            <?= $ord['nama_pelanggan'] === 'Tamu Slow Bar' ? 'Pesanan Pengunjung' : e($ord['nomor_meja']) . ' <span class="font-normal text-muted">(' . e($ord['nama_pelanggan']) . ')</span>' ?>
                          </h4>
                        </div>
                        <span class="px-2 py-0.5 rounded bg-surface-mid text-[11px] font-semibold text-espresso shrink-0">
                          <?= e($ord['nama_metode']) ?> • <?= e($ord['tipe_sajian']) ?>
                        </span>
                      </div>

                      <div class="text-xs space-y-1">
                        <p class="font-semibold text-espresso flex items-center gap-1.5">
                          <span class="material-symbols-outlined text-[15px] text-terracotta">grain</span>
                          <?= e($ord['nama_kopi']) ?> (<?= (int) $ord['rasio_kopi_gram'] ?>g)
                        </p>
                        <?php if (!empty($ord['catatan'])): ?>
                          <p class="text-muted italic bg-surface-low px-2.5 py-1.5 rounded">“<?= e($ord['catatan']) ?>”</p>
                        <?php endif; ?>
                      </div>

                      <form method="POST" action="/admin?tab=antrean" class="pt-1">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
                        <input type="hidden" name="tab" value="antrean" />
                        <input type="hidden" name="action" value="update_status" />
                        <input type="hidden" name="order_id" value="<?= (int) $ord['id'] ?>" />
                        <input type="hidden" name="status" value="Sedang Diseduh" />
                        <button type="submit" class="w-full py-2 rounded-lg bg-espresso hover:bg-[#3D2C22] text-cream text-xs font-semibold flex items-center justify-center gap-1.5 transition-colors">
                          <span class="material-symbols-outlined text-[17px]">play_arrow</span>
                          <span>Mulai Seduh</span>
                        </button>
                      </form>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- Kolom 2: Sedang Diseduh -->
            <div class="bg-surface-low border border-[#E7B6A5] rounded-xl p-4 space-y-3">
              <div class="flex items-center justify-between pb-2 border-b border-[#E7B6A5]">
                <h3 class="text-sm font-bold text-[#8F3F27] flex items-center gap-2">
                  <span class="w-2.5 h-2.5 rounded-full bg-terracotta animate-pulse"></span>
                  2. Sedang Diseduh
                </h3>
                <span class="px-2.5 py-0.5 rounded-full bg-terracotta-soft text-xs font-bold text-[#8F3F27]">
                  <?= count($groupedQueue['Sedang Diseduh']) ?>
                </span>
              </div>

              <?php if (empty($groupedQueue['Sedang Diseduh'])): ?>
                <div class="py-8 text-center text-xs text-muted italic">Belum ada seduhan aktif saat ini.</div>
              <?php else: ?>
                <div class="space-y-3">
                  <?php foreach ($groupedQueue['Sedang Diseduh'] as $ord): ?>
                    <div class="bg-white border-l-4 border-l-terracotta border border-sand rounded-lg p-4 space-y-3 shadow-sm">
                      <div class="flex items-start justify-between gap-2">
                        <div>
                          <span class="text-[11px] font-mono text-terracotta font-bold"><?= e($ord['kode_pesanan']) ?> • Sedang Ekstraksi</span>
                          <h4 class="text-sm font-bold text-espresso">
                            <?= $ord['nama_pelanggan'] === 'Tamu Slow Bar' ? 'Pesanan Pengunjung' : e($ord['nomor_meja']) . ' <span class="font-normal text-muted">(' . e($ord['nama_pelanggan']) . ')</span>' ?>
                          </h4>
                        </div>
                        <span class="px-2 py-0.5 rounded bg-terracotta-soft text-[11px] font-semibold text-[#8F3F27] shrink-0">
                          <?= e($ord['nama_metode']) ?> • <?= e($ord['tipe_sajian']) ?>
                        </span>
                      </div>

                      <div class="grid grid-cols-3 gap-2 p-2.5 rounded bg-surface-low text-center text-xs">
                        <div>
                          <span class="block text-[10px] text-muted uppercase">Dosis</span>
                          <strong class="text-espresso tabular-nums"><?= (int) $ord['rasio_kopi_gram'] ?>g</strong>
                        </div>
                        <div>
                          <span class="block text-[10px] text-muted uppercase">Target</span>
                          <strong class="text-espresso tabular-nums"><?= (int) $ord['estimasi_menit'] ?> mnt</strong>
                        </div>
                        <div>
                          <span class="block text-[10px] text-muted uppercase">Masuk</span>
                          <strong class="text-terracotta tabular-nums"><?= e(formatJamSingkat((string) $ord['created_at'])) ?></strong>
                        </div>
                      </div>

                      <p class="text-xs font-semibold text-espresso"><?= e($ord['nama_kopi']) ?></p>
                      <?php if (!empty($ord['catatan'])): ?>
                        <p class="text-xs text-muted italic">Catatan: “<?= e($ord['catatan']) ?>”</p>
                      <?php endif; ?>

                      <div class="flex items-center gap-2 pt-1">
                        <form method="POST" action="/admin?tab=antrean" class="flex-1">
                          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
                          <input type="hidden" name="tab" value="antrean" />
                          <input type="hidden" name="action" value="update_status" />
                          <input type="hidden" name="order_id" value="<?= (int) $ord['id'] ?>" />
                          <input type="hidden" name="status" value="Selesai" />
                          <button type="submit" class="w-full py-2 rounded-lg bg-terracotta hover:bg-terracotta-dark text-white text-xs font-semibold flex items-center justify-center gap-1.5 transition-colors">
                            <span class="material-symbols-outlined text-[17px]">check_circle</span>
                            <span>Selesai Seduh</span>
                          </button>
                        </form>
                        <form method="POST" action="/admin?tab=antrean">
                          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
                          <input type="hidden" name="tab" value="antrean" />
                          <input type="hidden" name="action" value="update_status" />
                          <input type="hidden" name="order_id" value="<?= (int) $ord['id'] ?>" />
                          <input type="hidden" name="status" value="Menunggu" />
                          <button type="submit" title="Kembalikan ke Menunggu" class="p-2 rounded-lg bg-surface-mid hover:bg-sand text-muted hover:text-espresso transition-colors">
                            <span class="material-symbols-outlined text-[17px]">undo</span>
                          </button>
                        </form>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- Kolom 3: Selesai Hari Ini -->
            <div class="bg-surface-low border border-sand rounded-xl p-4 space-y-3">
              <div class="flex items-center justify-between pb-2 border-b border-sand">
                <h3 class="text-sm font-bold text-sage-text flex items-center gap-2">
                  <span class="w-2.5 h-2.5 rounded-full bg-sage-text"></span>
                  3. Selesai (Hari Ini)
                </h3>
                <span class="px-2.5 py-0.5 rounded-full bg-sage-soft text-xs font-bold text-sage-text">
                  <?= count($groupedQueue['Selesai']) ?>
                </span>
              </div>

              <?php if (empty($groupedQueue['Selesai'])): ?>
                <div class="py-8 text-center text-xs text-muted italic">Belum ada pesanan selesai hari ini.</div>
              <?php else: ?>
                <div class="space-y-2.5">
                  <?php foreach ($groupedQueue['Selesai'] as $ord): ?>
                    <div class="bg-white border border-sand rounded-lg p-3.5 space-y-1.5">
                      <div class="flex items-center justify-between gap-2">
                        <span class="text-xs font-bold text-espresso tabular-nums"><?= e($ord['kode_pesanan']) ?> • <?= e($ord['nomor_meja']) ?></span>
                        <span class="px-2 py-0.5 rounded-full bg-sage-soft text-sage-text text-[11px] font-semibold">Selesai</span>
                      </div>
                      <p class="text-xs font-semibold text-espresso"><?= e($ord['nama_kopi']) ?> • <?= e($ord['nama_metode']) ?> (<?= e($ord['tipe_sajian']) ?>)</p>
                      <div class="flex items-center justify-between text-[11px] text-muted">
                        <span>Tamu: <?= e($ord['nama_pelanggan']) ?></span>
                        <span class="font-semibold text-espresso tabular-nums"><?= e(formatRupiah((int) $ord['total_harga'])) ?></span>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </section>

      <!-- ================================================================ -->
      <!-- TAB 2: CRUD MASTER DATA BIJI KOPI                                -->
      <!-- ================================================================ -->
      <?php elseif ($activeTab === 'biji'): ?>
        <section class="space-y-5">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
              <h2 class="font-editorial text-2xl text-espresso font-medium">Master Data Biji Kopi &amp; Stok Gramasi</h2>
              <p class="text-xs text-muted">Tambah varietas baru, perbarui stok gramasi, atau edit harga seduh per cup &amp; harga beans per kg.</p>
            </div>
            <button type="button" onclick="openBeanModal(null)" class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-lg bg-espresso hover:bg-[#3D2C22] text-cream text-xs font-semibold transition-colors shadow-sm self-start">
              <span class="material-symbols-outlined text-[18px]">add_circle</span>
              <span>Tambah Biji Kopi</span>
            </button>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <?php foreach (getAllProcessProfiles() as $pKey => $pDetail): ?>
              <div class="bg-paper border border-sand rounded-xl p-3.5 space-y-1">
                <span class="inline-block px-2.5 py-0.5 rounded-full bg-surface-mid text-espresso text-[11px] font-bold"><?= e($pDetail['judul']) ?></span>
                <p class="text-[11px] text-espresso font-medium leading-snug"><?= e($pDetail['cara_proses']) ?></p>
                <p class="text-[11px] text-muted leading-snug"><strong class="text-terracotta">Rasa:</strong> <?= e($pDetail['karakter_rasa']) ?></p>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="bg-paper border border-sand rounded-xl overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
              <table class="w-full text-left border-collapse">
                <thead>
                  <tr class="bg-surface-high text-muted text-[11px] font-bold uppercase tracking-wider border-b border-sand">
                    <th class="py-3 px-4">Biji Kopi &amp; Origin</th>
                    <th class="py-3 px-4">Proses</th>
                    <th class="py-3 px-4">Tasting Notes</th>
                    <th class="py-3 px-4">Stok Gramasi</th>
                    <th class="py-3 px-4">Harga (Cup &amp; Beans/kg)</th>
                    <th class="py-3 px-4 text-right">Aksi</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-sand bg-white">
                  <?php foreach ($beans as $bean): ?>
                    <?php
                      $bId = (int) $bean['id'];
                      $bStok = (int) $bean['stok_gram'];
                      $isCritical = $bStok < 100;
                      $beanJsonAttr = e(json_encode([
                          'id'            => $bId,
                          'nama_kopi'     => $bean['nama_kopi'],
                          'origin'        => $bean['origin'],
                          'proses'        => $bean['proses'],
                          'tasting_notes' => $bean['tasting_notes'],
                          'stok_gram'     => $bStok,
                          'harga'         => (int) $bean['harga'],
                          'harga_per_kg'  => (int) ($bean['harga_per_kg'] ?? 250000),
                          'gambar_url'    => $bean['gambar_url'],
                      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    ?>
                    <tr class="hover:bg-surface-low transition-colors">
                      <td class="py-3.5 px-4">
                        <div class="flex items-center gap-3">
                          <img src="<?= e(resolveBeanImageUrl((string) $bean['gambar_url'])) ?>" alt="" class="w-11 h-11 rounded object-cover bg-surface-mid shrink-0" onerror="this.style.display='none'" />
                          <div>
                            <span class="font-semibold text-sm text-espresso block"><?= e($bean['nama_kopi']) ?></span>
                            <span class="text-xs text-muted"><?= e($bean['origin']) ?></span>
                          </div>
                        </div>
                      </td>
                      <td class="py-3.5 px-4 max-w-[270px]">
                        <?php $beanProcessProfile = getProcessProfile((string) $bean['proses']); ?>
                        <span class="inline-block px-2.5 py-0.5 rounded-full bg-surface-mid text-espresso text-xs font-semibold">
                          <?= e($beanProcessProfile['judul']) ?>
                        </span>
                        <p class="text-[11px] text-espresso/90 leading-snug mt-1">
                          <?= e($beanProcessProfile['cara_proses']) ?>
                        </p>
                        <p class="text-[11px] text-muted leading-snug mt-0.5">
                          <strong class="text-terracotta">Rasa:</strong> <?= e($beanProcessProfile['karakter_rasa']) ?>
                        </p>
                      </td>
                      <td class="py-3.5 px-4 text-xs text-muted max-w-[220px]">
                        <?= e($bean['tasting_notes']) ?>
                      </td>
                      <td class="py-3.5 px-4">
                        <div class="flex items-center gap-2">
                          <span class="text-sm font-bold tabular-nums <?= $isCritical ? 'text-[#93000A]' : 'text-espresso' ?>">
                            <?= number_format($bStok, 0, ',', '.') ?>g
                          </span>
                          <?php if ($isCritical): ?>
                            <span class="px-2 py-0.5 rounded-full bg-[#FFEBEE] text-[#93000A] text-[10px] font-bold uppercase">Kritis &lt; 100g</span>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td class="py-3.5 px-4 text-sm font-semibold text-espresso tabular-nums">
                        <span class="block"><?= e(formatRupiah((int) $bean['harga'])) ?> <span class="text-[11px] font-normal text-muted">/ cup</span></span>
                        <span class="block text-xs font-medium text-muted"><?= e(formatRupiah((int) ($bean['harga_per_kg'] ?? 250000))) ?> / kg beans</span>
                      </td>
                      <td class="py-3.5 px-4 text-right">
                        <div class="inline-flex items-center gap-1.5">
                          <button
                            type="button"
                            onclick="openRestockModal(<?= $bId ?>, <?= e(json_encode($bean['nama_kopi'])) ?>, <?= $bStok ?>)"
                            class="px-2.5 py-1.5 rounded bg-surface-mid hover:bg-sand text-xs font-semibold text-espresso transition-colors"
                            title="Update Stok Gramasi"
                          >
                            + Stok
                          </button>
                          <button
                            type="button"
                            onclick='openBeanModal(<?= $beanJsonAttr ?>)'
                            class="p-1.5 rounded hover:bg-surface-mid text-muted hover:text-espresso transition-colors"
                            title="Edit Biji Kopi"
                          >
                            <span class="material-symbols-outlined text-[18px]">edit</span>
                          </button>
                          <form method="POST" action="/admin?tab=biji" onsubmit="return confirm('Yakin ingin menghapus <?= e(addslashes((string) $bean['nama_kopi'])) ?>?');" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
                            <input type="hidden" name="tab" value="biji" />
                            <input type="hidden" name="action" value="delete_bean" />
                            <input type="hidden" name="id" value="<?= $bId ?>" />
                            <button type="submit" class="p-1.5 rounded hover:bg-[#FFEBEE] text-muted hover:text-[#93000A] transition-colors" title="Hapus">
                              <span class="material-symbols-outlined text-[18px]">delete</span>
                            </button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </section>

      <!-- ================================================================ -->
      <!-- TAB 3: CRUD MASTER METODE SEDUH                                  -->
      <!-- ================================================================ -->
      <?php elseif ($activeTab === 'metode'): ?>
        <section class="space-y-5">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
              <h2 class="font-editorial text-2xl text-espresso font-medium">Master Metode Seduh Manual</h2>
              <p class="text-xs text-muted">Atur takaran gramasi kopi per cangkir, estimasi waktu ekstraksi, dan biaya tambahan alat.</p>
            </div>
            <button type="button" onclick="openMethodModal(null)" class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-lg bg-espresso hover:bg-[#3D2C22] text-cream text-xs font-semibold transition-colors shadow-sm self-start">
              <span class="material-symbols-outlined text-[18px]">add_circle</span>
              <span>Tambah Metode Seduh</span>
            </button>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
            <?php foreach ($methods as $m): ?>
              <?php
                $mId = (int) $m['id'];
                $mStokPorsi = $m['stok_porsi'] !== null ? (int) $m['stok_porsi'] : null;
                $mJsonAttr = e(json_encode([
                    'id'              => $mId,
                    'nama_metode'     => $m['nama_metode'],
                    'rasio_kopi_gram' => (int) $m['rasio_kopi_gram'],
                    'estimasi_menit'  => (int) $m['estimasi_menit'],
                    'biaya_tambahan'  => (int) $m['biaya_tambahan'],
                    'stok_porsi'      => $mStokPorsi,
                ], JSON_UNESCAPED_UNICODE));
              ?>
              <div class="bg-paper border border-sand rounded-xl p-5 flex flex-col justify-between space-y-4">
                <div class="space-y-3">
                  <div class="flex items-start justify-between">
                    <div>
                      <span class="text-[10px] uppercase tracking-widest text-terracotta font-bold">Teknik Seduh</span>
                      <h3 class="font-editorial text-2xl font-medium text-espresso"><?= e($m['nama_metode']) ?></h3>
                    </div>
                    <div class="w-9 h-9 rounded-full bg-surface-high flex items-center justify-center text-espresso">
                      <span class="material-symbols-outlined text-[19px]">local_cafe</span>
                    </div>
                  </div>
                  <div class="space-y-1.5 text-xs text-muted bg-white border border-sand rounded-lg p-3">
                    <div class="flex justify-between">
                      <span>Takaran Kopi:</span>
                      <strong class="text-espresso tabular-nums"><?= (int) $m['rasio_kopi_gram'] ?> gram</strong>
                    </div>
                    <div class="flex justify-between">
                      <span>Estimasi Seduh:</span>
                      <strong class="text-espresso tabular-nums"><?= (int) $m['estimasi_menit'] ?> menit</strong>
                    </div>
                    <div class="flex justify-between">
                      <span>Biaya Tambahan:</span>
                      <strong class="text-espresso tabular-nums"><?= e(formatRupiah((int) $m['biaya_tambahan'])) ?></strong>
                    </div>
                    <div class="flex justify-between pt-1 border-t border-sand/70">
                      <span>Persediaan Batch:</span>
                      <?php if ($mStokPorsi !== null): ?>
                        <strong class="tabular-nums <?= $mStokPorsi <= 0 ? 'text-[#93000A]' : ($mStokPorsi <= 3 ? 'text-[#8F3F27]' : 'text-sage-text') ?>">
                          Terbatas (<?= $mStokPorsi ?> porsi)
                        </strong>
                      <?php else: ?>
                        <span class="text-muted">Mengikuti stok beans</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

                <div class="flex items-center justify-between gap-2 pt-2 border-t border-sand">
                  <?php if ($mStokPorsi !== null): ?>
                    <form method="POST" action="/admin?tab=metode" class="inline-flex items-center gap-1">
                      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
                      <input type="hidden" name="tab" value="metode" />
                      <input type="hidden" name="action" value="restock_method" />
                      <input type="hidden" name="id" value="<?= $mId ?>" />
                      <input type="hidden" name="restock_mode" value="add" />
                      <input type="hidden" name="porsi" value="5" />
                      <button type="submit" class="px-2.5 py-1.5 rounded bg-terracotta-soft hover:bg-terracotta text-[#8F3F27] hover:text-white text-xs font-semibold transition-colors">
                        +5 Porsi
                      </button>
                    </form>
                  <?php else: ?>
                    <span></span>
                  <?php endif; ?>
                  <div class="flex items-center gap-1.5">
                    <button
                      type="button"
                      onclick='openMethodModal(<?= $mJsonAttr ?>)'
                      class="px-3 py-1.5 rounded bg-surface-mid hover:bg-sand text-xs font-semibold text-espresso transition-colors"
                    >
                      Edit
                    </button>
                    <form method="POST" action="/admin?tab=metode" onsubmit="return confirm('Hapus metode seduh <?= e(addslashes((string) $m['nama_metode'])) ?>?');">
                      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
                      <input type="hidden" name="tab" value="metode" />
                      <input type="hidden" name="action" value="delete_method" />
                      <input type="hidden" name="id" value="<?= $mId ?>" />
                      <button type="submit" class="px-3 py-1.5 rounded hover:bg-[#FFEBEE] text-xs font-semibold text-[#93000A] transition-colors">
                        Hapus
                      </button>
                    </form>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

      <!-- ================================================================ -->
      <!-- TAB 4: LAPORAN TRANSAKSI & CETAK                                 -->
      <!-- ================================================================ -->
      <?php elseif ($activeTab === 'laporan'): ?>
        <section class="space-y-5 print-section">
          <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4 no-print">
            <div>
              <h2 class="font-editorial text-2xl text-espresso font-medium">Laporan Riwayat Transaksi</h2>
              <p class="text-xs text-muted">Filter transaksi berdasarkan rentang tanggal dan cetak sebagai laporan keuangan.</p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
              <a href="/admin?tab=laporan&dari_tanggal=<?= e($todayDate) ?>&sampai_tanggal=<?= e($todayDate) ?>" class="px-3 py-2 rounded-lg bg-paper border border-sand hover:bg-surface-high text-xs font-semibold text-espresso">Hari Ini</a>
              <a href="/admin?tab=laporan&dari_tanggal=<?= e(date('Y-m-d', strtotime('-6 days'))) ?>&sampai_tanggal=<?= e($todayDate) ?>" class="px-3 py-2 rounded-lg bg-paper border border-sand hover:bg-surface-high text-xs font-semibold text-espresso">7 Hari</a>
              <a href="/admin?tab=laporan&dari_tanggal=<?= e($defaultStartDate) ?>&sampai_tanggal=<?= e($todayDate) ?>" class="px-3 py-2 rounded-lg bg-paper border border-sand hover:bg-surface-high text-xs font-semibold text-espresso">Bulan Ini</a>
              <button
                type="button"
                onclick="window.print()"
                class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-espresso hover:bg-[#3D2C22] text-cream text-xs font-semibold transition-colors shadow-sm"
              >
                <span class="material-symbols-outlined text-[17px]">print</span>
                <span>Cetak Laporan</span>
              </button>
            </div>
          </div>

          <!-- Form Filter Tanggal -->
          <form method="GET" action="/admin" class="filter-form bg-paper border border-sand rounded-xl p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end no-print">
            <input type="hidden" name="tab" value="laporan" />
            <div class="space-y-1">
              <label for="dari_tanggal" class="block text-xs font-semibold text-espresso">Dari Tanggal</label>
              <input id="dari_tanggal" name="dari_tanggal" type="date" value="<?= e($dariTanggal) ?>" class="w-full h-10 px-3 rounded bg-white border border-sand text-sm text-espresso" />
            </div>
            <div class="space-y-1">
              <label for="sampai_tanggal" class="block text-xs font-semibold text-espresso">Sampai Tanggal</label>
              <input id="sampai_tanggal" name="sampai_tanggal" type="date" value="<?= e($sampaiTanggal) ?>" class="w-full h-10 px-3 rounded bg-white border border-sand text-sm text-espresso" />
            </div>
            <div class="space-y-1">
              <label for="status_filter" class="block text-xs font-semibold text-espresso">Status Pesanan</label>
              <select id="status_filter" name="status_filter" class="w-full h-10 px-3 rounded bg-white border border-sand text-sm text-espresso">
                <option value="Semua" <?= $filterStatus === 'Semua' ? 'selected' : '' ?>>Semua Status</option>
                <?php foreach ($allowedStatuses as $st): ?>
                  <option value="<?= e($st) ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="h-10 px-4 rounded bg-terracotta hover:bg-terracotta-dark text-white text-xs font-semibold transition-colors flex items-center justify-center gap-1.5">
              <span class="material-symbols-outlined text-[17px]">filter_alt</span>
              <span>Terapkan Filter</span>
            </button>
          </form>

          <!-- Ringkasan Omzet Periode -->
          <div class="grid grid-cols-2 gap-4">
            <div class="bg-paper border border-sand rounded-xl p-4 flex items-center justify-between">
              <div>
                <span class="text-xs uppercase tracking-wider text-muted font-semibold block">Total Transaksi (Periode Terpilih)</span>
                <strong class="font-editorial text-2xl text-espresso tabular-nums"><?= count($reportOrders) ?> Transaksi</strong>
              </div>
              <span class="material-symbols-outlined text-[26px] text-outline no-print">receipt</span>
            </div>
            <div class="bg-paper border border-sand rounded-xl p-4 flex items-center justify-between">
              <div>
                <span class="text-xs uppercase tracking-wider text-muted font-semibold block">Total Omzet (Periode Terpilih)</span>
                <strong class="font-editorial text-2xl text-terracotta tabular-nums"><?= e(formatRupiah($reportOmzet)) ?></strong>
              </div>
              <span class="material-symbols-outlined text-[26px] text-terracotta no-print">account_balance_wallet</span>
            </div>
          </div>

          <!-- Tabel Transaksi -->
          <div class="bg-white border border-sand rounded-xl overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
              <table class="w-full text-left border-collapse">
                <thead>
                  <tr class="bg-surface-high text-muted text-[11px] font-bold uppercase tracking-wider border-b border-sand">
                    <th class="py-3 px-4">Kode</th>
                    <th class="py-3 px-4">Waktu (WIB)</th>
                    <th class="py-3 px-4">Pelanggan &amp; Meja</th>
                    <th class="py-3 px-4">Biji Kopi</th>
                    <th class="py-3 px-4">Metode &amp; Sajian</th>
                    <th class="py-3 px-4">Status</th>
                    <th class="py-3 px-4 text-right">Total</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-sand text-sm">
                  <?php if (empty($reportOrders)): ?>
                    <tr>
                      <td colspan="7" class="py-8 text-center text-xs text-muted italic">
                        Tidak ada transaksi pada rentang tanggal <?= e($dariTanggal) ?> sampai <?= e($sampaiTanggal) ?>.
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($reportOrders as $ro): ?>
                      <tr class="hover:bg-surface-low">
                        <td class="py-3 px-4 font-mono text-xs font-bold text-espresso"><?= e($ro['kode_pesanan']) ?></td>
                        <td class="py-3 px-4 text-xs text-muted tabular-nums"><?= e(formatWaktuIndo((string) $ro['created_at'])) ?></td>
                        <td class="py-3 px-4">
                          <span class="font-semibold text-espresso block"><?= e($ro['nama_pelanggan']) ?></span>
                          <span class="text-xs text-muted"><?= e($ro['nomor_meja']) ?></span>
                        </td>
                        <td class="py-3 px-4 text-xs font-medium text-espresso"><?= e($ro['nama_kopi']) ?></td>
                        <td class="py-3 px-4 text-xs text-muted">
                          <?= e($ro['nama_metode']) ?> • <?= e($ro['tipe_sajian']) ?> (<?= (int) $ro['rasio_kopi_gram'] ?>g)
                        </td>
                        <td class="py-3 px-4">
                          <?php
                            $stClass = match ($ro['status']) {
                                'Selesai'        => 'bg-sage-soft text-sage-text',
                                'Sedang Diseduh' => 'bg-terracotta-soft text-[#8F3F27]',
                                default          => 'bg-surface-mid text-espresso',
                            };
                          ?>
                          <span class="px-2.5 py-0.5 rounded-full text-[11px] font-semibold <?= $stClass ?>">
                            <?= e($ro['status']) ?>
                          </span>
                        </td>
                        <td class="py-3 px-4 text-right font-semibold text-espresso tabular-nums">
                          <?= e(formatRupiah((int) $ro['total_harga'])) ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
                <?php if (!empty($reportOrders)): ?>
                  <tfoot>
                    <tr class="bg-surface-low border-t-2 border-sand font-bold">
                      <td colspan="6" class="py-3.5 px-4 text-right text-xs uppercase tracking-wider text-espresso">
                        Total Omzet Keseluruhan
                      </td>
                      <td class="py-3.5 px-4 text-right text-base text-terracotta tabular-nums">
                        <?= e(formatRupiah($reportOmzet)) ?>
                      </td>
                    </tr>
                  </tfoot>
                <?php endif; ?>
              </table>
            </div>
          </div>
        </section>
      <?php endif; ?>
    </main>
  </div>

  <!-- ====================================================================== -->
  <!-- MODAL: Tambah / Edit Biji Kopi                                         -->
  <!-- ====================================================================== -->
  <div id="bean-modal" class="fixed inset-0 z-50 bg-espresso-dark/50 backdrop-blur-sm hidden items-center justify-center p-4 no-print modal-backdrop">
    <div class="bg-surface border border-sand rounded-xl w-full max-w-xl shadow-2xl overflow-hidden">
      <div class="px-6 py-4 bg-surface-low border-b border-sand flex items-center justify-between">
        <h3 id="bean-modal-title" class="font-editorial text-2xl text-espresso font-medium">Tambah Biji Kopi Baru</h3>
        <button type="button" onclick="closeModal('bean-modal')" class="p-1 rounded-full hover:bg-sand text-muted">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>
      <form method="POST" action="/admin?tab=biji" class="p-6 space-y-4">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
        <input type="hidden" name="tab" value="biji" />
        <input type="hidden" name="action" id="bean-form-action" value="add_bean" />
        <input type="hidden" name="id" id="bean-form-id" value="" />

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="space-y-1">
            <label class="block text-xs font-semibold text-espresso">Nama Biji Kopi *</label>
            <input id="bean-nama" name="nama_kopi" type="text" required placeholder="Contoh: Kerinci Kayu Aro" class="w-full h-10 px-3 rounded bg-white border border-sand text-sm" />
          </div>
          <div class="space-y-1">
            <label class="block text-xs font-semibold text-espresso">Origin *</label>
            <input id="bean-origin" name="origin" type="text" required placeholder="Contoh: Jambi • 1.600 mdpl" class="w-full h-10 px-3 rounded bg-white border border-sand text-sm" />
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="space-y-1">
            <label class="block text-xs font-semibold text-espresso">Proses *</label>
            <select id="bean-proses" name="proses" required onchange="updateBeanProcessHint()" class="w-full h-10 px-3 rounded bg-white border border-sand text-sm">
              <?php foreach ($allowedProses as $pr): ?>
                <option value="<?= e($pr) ?>"><?= e($pr) ?></option>
              <?php endforeach; ?>
            </select>
            <p id="bean-proses-hint" class="text-[11px] text-muted leading-snug"></p>
          </div>
          <div class="space-y-1">
            <label class="block text-xs font-semibold text-espresso">Stok (Gram) *</label>
            <input id="bean-stok" name="stok_gram" type="number" min="0" required value="500" class="w-full h-10 px-3 rounded bg-white border border-sand text-sm" />
          </div>
          <div class="space-y-1">
            <label class="block text-xs font-semibold text-espresso">Harga Kopi Seduh / Cup (Rp) *</label>
            <input id="bean-harga" name="harga" type="number" min="0" step="500" required value="22000" class="w-full h-10 px-3 rounded bg-white border border-sand text-sm" />
          </div>
          <div class="space-y-1">
            <label class="block text-xs font-semibold text-espresso">Harga Beans / kg (Rp) *</label>
            <input id="bean-harga-kg" name="harga_per_kg" type="number" min="0" step="5000" required value="280000" class="w-full h-10 px-3 rounded bg-white border border-sand text-sm" />
          </div>
        </div>

        <div class="space-y-1">
          <label class="block text-xs font-semibold text-espresso">Tasting Notes *</label>
          <input id="bean-notes" name="tasting_notes" type="text" required placeholder="Contoh: Mandarin Orange, Jasmine, Honey" class="w-full h-10 px-3 rounded bg-white border border-sand text-sm" />
        </div>

        <div class="space-y-1">
          <label class="block text-xs font-semibold text-espresso">URL Gambar Unsplash (Opsional)</label>
          <input id="bean-gambar" name="gambar_url" type="url" placeholder="<?= e($defaultCoffeeImg) ?>" class="w-full h-10 px-3 rounded bg-white border border-sand text-sm" />
        </div>

        <div class="pt-3 flex items-center justify-end gap-2 border-t border-sand">
          <button type="button" onclick="closeModal('bean-modal')" class="px-4 py-2 rounded-lg bg-surface-mid hover:bg-sand text-xs font-semibold text-espresso">Batal</button>
          <button type="submit" class="px-4 py-2 rounded-lg bg-espresso hover:bg-[#3D2C22] text-cream text-xs font-semibold">Simpan Data Biji</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ====================================================================== -->
  <!-- MODAL: Update Cepat Stok Gramasi                                       -->
  <!-- ====================================================================== -->
  <div id="restock-modal" class="fixed inset-0 z-50 bg-espresso-dark/50 backdrop-blur-sm hidden items-center justify-center p-4 no-print modal-backdrop">
    <div class="bg-surface border border-sand rounded-xl w-full max-w-md shadow-2xl overflow-hidden">
      <div class="px-6 py-4 bg-surface-low border-b border-sand flex items-center justify-between">
        <h3 class="font-editorial text-xl text-espresso font-medium">Update Stok Gramasi</h3>
        <button type="button" onclick="closeModal('restock-modal')" class="p-1 rounded-full hover:bg-sand text-muted">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>
      <form method="POST" action="/admin?tab=biji" class="p-6 space-y-4">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
        <input type="hidden" name="tab" value="biji" />
        <input type="hidden" name="action" value="restock_bean" />
        <input type="hidden" name="id" id="restock-bean-id" value="" />

        <div class="p-3 rounded-lg bg-surface-low border border-sand text-xs space-y-1">
          <strong class="text-sm text-espresso block" id="restock-bean-name">-</strong>
          <span class="text-muted">Stok saat ini: <strong class="text-espresso tabular-nums" id="restock-current-gram">0g</strong></span>
        </div>

        <div class="space-y-1">
          <label class="block text-xs font-semibold text-espresso">Mode Pembaruan</label>
          <select name="restock_mode" class="w-full h-10 px-3 rounded bg-white border border-sand text-sm">
            <option value="add">Tambah Stok Masuk (+ Gram)</option>
            <option value="set">Atur Ulang Total Stok (= Gram)</option>
          </select>
        </div>

        <div class="space-y-1">
          <label class="block text-xs font-semibold text-espresso">Jumlah Gram *</label>
          <input name="gram" type="number" min="0" required value="500" class="w-full h-10 px-3 rounded bg-white border border-sand text-sm" />
        </div>

        <div class="pt-3 flex items-center justify-end gap-2 border-t border-sand">
          <button type="button" onclick="closeModal('restock-modal')" class="px-4 py-2 rounded-lg bg-surface-mid text-xs font-semibold">Batal</button>
          <button type="submit" class="px-4 py-2 rounded-lg bg-terracotta hover:bg-terracotta-dark text-white text-xs font-semibold">Perbarui Stok</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ====================================================================== -->
  <!-- MODAL: Tambah / Edit Metode Seduh                                      -->
  <!-- ====================================================================== -->
  <div id="method-modal" class="fixed inset-0 z-50 bg-espresso-dark/50 backdrop-blur-sm hidden items-center justify-center p-4 no-print modal-backdrop">
    <div class="bg-surface border border-sand rounded-xl w-full max-w-md shadow-2xl overflow-hidden">
      <div class="px-6 py-4 bg-surface-low border-b border-sand flex items-center justify-between">
        <h3 id="method-modal-title" class="font-editorial text-2xl text-espresso font-medium">Tambah Metode Seduh</h3>
        <button type="button" onclick="closeModal('method-modal')" class="p-1 rounded-full hover:bg-sand text-muted">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>
      <form method="POST" action="/admin?tab=metode" class="p-6 space-y-4">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
        <input type="hidden" name="tab" value="metode" />
        <input type="hidden" name="action" id="method-form-action" value="add_method" />
        <input type="hidden" name="id" id="method-form-id" value="" />

        <div class="space-y-1">
          <label class="block text-xs font-semibold text-espresso">Nama Metode Seduh *</label>
          <input id="method-nama" name="nama_metode" type="text" required placeholder="Contoh: Origami Dripper" class="w-full h-10 px-3 rounded bg-white border border-sand text-sm" />
        </div>
        <div class="grid grid-cols-3 gap-3">
          <div class="space-y-1">
            <label class="block text-xs font-semibold text-espresso">Rasio (g) *</label>
            <input id="method-rasio" name="rasio_kopi_gram" type="number" min="1" required value="15" class="w-full h-10 px-3 rounded bg-white border border-sand text-sm" />
          </div>
          <div class="space-y-1">
            <label class="block text-xs font-semibold text-espresso">Menit *</label>
            <input id="method-estimasi" name="estimasi_menit" type="number" min="1" required value="4" class="w-full h-10 px-3 rounded bg-white border border-sand text-sm" />
          </div>
          <div class="space-y-1">
            <label class="block text-xs font-semibold text-espresso">Biaya + (Rp)</label>
            <input id="method-biaya" name="biaya_tambahan" type="number" min="0" step="500" required value="0" class="w-full h-10 px-3 rounded bg-white border border-sand text-sm" />
          </div>
        </div>

        <div class="space-y-1">
          <label class="block text-xs font-semibold text-espresso">Persediaan Terbatas / Stok Batch (Porsi)</label>
          <input id="method-stok-porsi" name="stok_porsi" type="number" min="0" placeholder="Kosongkan jika mengikuti stok beans (tanpa batas batch)" class="w-full h-10 px-3 rounded bg-white border border-sand text-sm" />
          <p class="text-[11px] text-muted">Isi angka bila ingin membatasi jumlah porsi per batch; kosongkan untuk seduhan fresh mengikuti stok gramasi beans.</p>
        </div>

        <div class="pt-3 flex items-center justify-end gap-2 border-t border-sand">
          <button type="button" onclick="closeModal('method-modal')" class="px-4 py-2 rounded-lg bg-surface-mid text-xs font-semibold">Batal</button>
          <button type="submit" class="px-4 py-2 rounded-lg bg-espresso hover:bg-[#3D2C22] text-cream text-xs font-semibold">Simpan Metode</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ====================================================================== -->
  <!-- MODAL: Catat Pesanan Walk-in (Memanggil /proses-order)                 -->
  <!-- ====================================================================== -->
  <div id="walkin-modal" class="fixed inset-0 z-50 bg-espresso-dark/50 backdrop-blur-sm hidden items-center justify-center p-4 no-print modal-backdrop">
    <div class="bg-surface border border-sand rounded-xl w-full max-w-lg shadow-2xl overflow-hidden">
      <div class="px-6 py-4 bg-surface-low border-b border-sand flex items-center justify-between">
        <div>
          <h3 class="font-editorial text-2xl text-espresso font-medium">Catat Pesanan Walk-in</h3>
          <p class="text-xs text-muted">Otomatis memvalidasi dan memotong stok gramasi biji kopi.</p>
        </div>
        <button type="button" onclick="closeModal('walkin-modal')" class="p-1 rounded-full hover:bg-sand text-muted">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>
      <form method="POST" action="/proses-order" class="p-6 space-y-4">
        <input type="hidden" name="from" value="admin" />
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="space-y-1">
            <label class="block text-xs font-semibold text-espresso">Nama Pelanggan (Opsional)</label>
            <input name="nama_pelanggan" type="text" placeholder="Default: Tamu Slow Bar" class="w-full h-10 px-3 rounded bg-white border border-sand text-sm" />
          </div>
          <div class="space-y-1">
            <label class="block text-xs font-semibold text-espresso">Nomor Meja / Bar (Opsional)</label>
            <input name="nomor_meja" type="text" placeholder="Default: Slow Bar" class="w-full h-10 px-3 rounded bg-white border border-sand text-sm" />
          </div>
        </div>

        <div class="space-y-1">
          <label class="block text-xs font-semibold text-espresso">Pilih Biji Kopi *</label>
          <select name="biji_kopi_id" required class="w-full h-10 px-3 rounded bg-white border border-sand text-sm">
            <?php foreach ($beans as $b): ?>
              <option value="<?= (int) $b['id'] ?>">
                <?= e($b['nama_kopi']) ?> — Cup <?= e(formatRupiah((int) $b['harga'])) ?> • Beans <?= e(formatRupiah((int) ($b['harga_per_kg'] ?? 250000))) ?>/kg (Stok <?= (int) $b['stok_gram'] ?>g)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div class="space-y-1">
            <label class="block text-xs font-semibold text-espresso">Metode Seduh *</label>
            <select name="metode_seduh_id" required class="w-full h-10 px-3 rounded bg-white border border-sand text-sm">
              <?php foreach ($methods as $m): ?>
                <?php $wStok = $m['stok_porsi'] !== null ? (int) $m['stok_porsi'] : null; ?>
                <option value="<?= (int) $m['id'] ?>" <?= ($wStok !== null && $wStok <= 0) ? 'disabled' : '' ?>>
                  <?= e($m['nama_metode']) ?> (<?= (int) $m['rasio_kopi_gram'] ?>g • +<?= e(formatRupiah((int) $m['biaya_tambahan'])) ?><?= $wStok !== null ? ' • ' . ($wStok > 0 ? "Sisa {$wStok} porsi" : 'HABIS') : '' ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="space-y-1">
            <label class="block text-xs font-semibold text-espresso">Tipe Sajian *</label>
            <select name="tipe_sajian" required class="w-full h-10 px-3 rounded bg-white border border-sand text-sm">
              <option value="Hot">Hot</option>
              <option value="Iced">Iced</option>
            </select>
          </div>
        </div>

        <div class="space-y-1">
          <label class="block text-xs font-semibold text-espresso">Catatan Barista</label>
          <input name="catatan" type="text" placeholder="Opsional" class="w-full h-10 px-3 rounded bg-white border border-sand text-sm" />
        </div>

        <div class="pt-3 flex items-center justify-end gap-2 border-t border-sand">
          <button type="button" onclick="closeModal('walkin-modal')" class="px-4 py-2 rounded-lg bg-surface-mid text-xs font-semibold">Batal</button>
          <button type="submit" class="px-4 py-2 rounded-lg bg-terracotta hover:bg-terracotta-dark text-white text-xs font-semibold">Kirim ke Antrean</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function toggleSidebar(show) {
      const sidebar = document.getElementById('admin-sidebar');
      const backdrop = document.getElementById('mobile-sidebar-backdrop');
      if (!sidebar || !backdrop) return;
      if (show) {
        sidebar.classList.remove('-translate-x-full');
        backdrop.classList.remove('hidden');
      } else {
        sidebar.classList.add('-translate-x-full');
        backdrop.classList.add('hidden');
      }
    }

    function openModal(id) {
      const el = document.getElementById(id);
      if (!el) return;
      el.classList.remove('hidden');
      el.classList.add('flex');
    }

    function closeModal(id) {
      const el = document.getElementById(id);
      if (!el) return;
      el.classList.add('hidden');
      el.classList.remove('flex');
    }

    function openBeanModal(bean) {
      const title = document.getElementById('bean-modal-title');
      const action = document.getElementById('bean-form-action');
      const idEl = document.getElementById('bean-form-id');

      if (bean) {
        title.textContent = 'Edit Biji Kopi';
        action.value = 'edit_bean';
        idEl.value = bean.id;
        document.getElementById('bean-nama').value = bean.nama_kopi || '';
        document.getElementById('bean-origin').value = bean.origin || '';
        document.getElementById('bean-proses').value = bean.proses || 'Washed';
        document.getElementById('bean-stok').value = bean.stok_gram ?? 0;
        document.getElementById('bean-harga').value = bean.harga ?? 22000;
        document.getElementById('bean-harga-kg').value = bean.harga_per_kg ?? 250000;
        document.getElementById('bean-notes').value = bean.tasting_notes || '';
        document.getElementById('bean-gambar').value = bean.gambar_url || '';
      } else {
        title.textContent = 'Tambah Biji Kopi Baru';
        action.value = 'add_bean';
        idEl.value = '';
        document.getElementById('bean-nama').value = '';
        document.getElementById('bean-origin').value = '';
        document.getElementById('bean-proses').value = 'Washed';
        document.getElementById('bean-stok').value = '500';
        document.getElementById('bean-harga').value = '22000';
        document.getElementById('bean-harga-kg').value = '280000';
        document.getElementById('bean-notes').value = '';
        document.getElementById('bean-gambar').value = '';
      }
      openModal('bean-modal');
      updateBeanProcessHint();
    }

    const PROCESS_HINTS = <?= json_encode(
        array_map(static fn(array $p): string => $p['cara_proses'] . ' (' . $p['karakter_rasa'] . ')', getAllProcessProfiles()),
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
    ) ?>;

    function updateBeanProcessHint() {
      const sel = document.getElementById('bean-proses');
      const hintEl = document.getElementById('bean-proses-hint');
      if (!sel || !hintEl) return;
      hintEl.textContent = PROCESS_HINTS[sel.value] || '';
    }

    function openRestockModal(id, name, currentGram) {
      document.getElementById('restock-bean-id').value = id;
      document.getElementById('restock-bean-name').textContent = name;
      document.getElementById('restock-current-gram').textContent = currentGram + ' gram';
      openModal('restock-modal');
    }

    function openMethodModal(method) {
      const title = document.getElementById('method-modal-title');
      const action = document.getElementById('method-form-action');
      const idEl = document.getElementById('method-form-id');

      if (method) {
        title.textContent = 'Edit Metode Seduh';
        action.value = 'edit_method';
        idEl.value = method.id;
        document.getElementById('method-nama').value = method.nama_metode || '';
        document.getElementById('method-rasio').value = method.rasio_kopi_gram ?? 15;
        document.getElementById('method-estimasi').value = method.estimasi_menit ?? 4;
        document.getElementById('method-biaya').value = method.biaya_tambahan ?? 0;
        document.getElementById('method-stok-porsi').value = method.stok_porsi !== null && method.stok_porsi !== undefined ? method.stok_porsi : '';
      } else {
        title.textContent = 'Tambah Metode Seduh';
        action.value = 'add_method';
        idEl.value = '';
        document.getElementById('method-nama').value = '';
        document.getElementById('method-rasio').value = '15';
        document.getElementById('method-estimasi').value = '4';
        document.getElementById('method-biaya').value = '0';
        document.getElementById('method-stok-porsi').value = '';
      }
      openModal('method-modal');
    }

    function openWalkinModal() {
      openModal('walkin-modal');
    }
  </script>
</body>
</html>
