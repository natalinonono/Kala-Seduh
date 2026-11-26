<?php
/**
 * Halaman Login Barista & Admin — Kala Seduh Slow Bar.
 * Menggunakan autentikasi stateless signed cookie (HMAC-SHA256) untuk Vercel.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

if (getCurrentUser() !== null) {
    redirectTo('/admin');
}

$flash = consumeFlashMessage();
$errorMessage = '';
$usernameValue = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $usernameValue = $username;

    if ($username === '' || $password === '') {
        $errorMessage = 'Username dan password wajib diisi.';
    } else {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare(
                'SELECT id, username, password, nama_lengkap, role FROM users WHERE username = :username LIMIT 1'
            );
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, (string) $user['password'])) {
                setAuthCookie([
                    'id'           => (int) $user['id'],
                    'username'     => (string) $user['username'],
                    'nama_lengkap' => (string) $user['nama_lengkap'],
                    'role'         => (string) $user['role'],
                ]);
                setFlashMessage('success', 'Selamat bertugas, ' . $user['nama_lengkap'] . '.');
                redirectTo('/admin');
            } else {
                $errorMessage = 'Username atau password tidak sesuai.';
            }
        } catch (RuntimeException $e) {
            error_log('[Login Error] ' . $e->getMessage());
            $errorMessage = $e->getMessage();
        } catch (Throwable $e) {
            error_log('[Login Error] ' . $e->getMessage());
            $errorMessage = 'Terjadi gangguan pada server saat login. Silakan coba sesaat lagi.';
        }
    }
}

$pageTitle = 'Login Barista & Admin — Kala Seduh';
require __DIR__ . '/partials/head.php';
?>
<body class="min-h-screen bg-cream text-espresso flex flex-col justify-between">
  <!-- Header Ringkas -->
  <header class="w-full border-b border-sand bg-surface/90 backdrop-blur-md">
    <div class="max-w-6xl mx-auto px-5 md:px-10 h-16 flex items-center justify-between">
      <a href="/" class="flex items-center gap-3 group">
        <div class="w-9 h-9 rounded bg-espresso text-cream flex items-center justify-center shadow-sm">
          <span class="material-symbols-outlined text-[20px]">coffee_maker</span>
        </div>
        <div>
          <span class="font-editorial text-xl font-semibold text-espresso block leading-none">Kala Seduh</span>
          <span class="text-[10px] uppercase tracking-[0.18em] text-muted font-semibold">Slow Bar &amp; Ritual</span>
        </div>
      </a>
      <a href="/" class="inline-flex items-center gap-1.5 text-sm font-medium text-muted hover:text-espresso transition-colors">
        <span class="material-symbols-outlined text-[18px]">arrow_back</span>
        <span>Kembali ke Katalog</span>
      </a>
    </div>
  </header>

  <!-- Form Login Terpusat -->
  <main class="flex-1 flex items-center justify-center px-5 py-12">
    <div class="w-full max-w-md bg-paper border border-sand rounded-lg shadow-ambient p-8 space-y-6">
      <div class="space-y-2 text-center">
        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-terracotta-soft text-terracotta text-[11px] font-semibold uppercase tracking-widest">
          <span class="w-1.5 h-1.5 rounded-full bg-terracotta"></span>
          Portal Barista &amp; Admin
        </span>
        <h1 class="font-editorial text-3xl font-medium text-espresso">Masuk ke Meja Bar</h1>
        <p class="text-sm text-muted">
          Kelola antrean seduh manual, stok gramasi biji kopi, dan rekap penjualan harian.
        </p>
      </div>

      <?php if ($flash !== null): ?>
        <?php
          $flashStyle = match ($flash['type']) {
              'success' => 'bg-sage-soft border-[#C2D6CD] text-sage-text',
              'warning' => 'bg-terracotta-soft border-[#F3D1C4] text-[#8F3F27]',
              default   => 'bg-surface-mid border-sand text-espresso',
          };
        ?>
        <div class="px-4 py-3 rounded border text-sm font-medium <?= $flashStyle ?>">
          <?= e($flash['message']) ?>
        </div>
      <?php endif; ?>

      <?php if ($errorMessage !== ''): ?>
        <div class="px-4 py-3 rounded border bg-[#FFEBEE] border-[#F5C6CB] text-[#93000A] text-sm flex items-start gap-2">
          <span class="material-symbols-outlined text-[18px] mt-0.5 shrink-0">error</span>
          <span><?= e($errorMessage) ?></span>
        </div>
      <?php endif; ?>

      <form method="POST" action="/login" class="space-y-4">
        <div class="space-y-1.5">
          <label for="username" class="block text-xs font-semibold uppercase tracking-wider text-espresso">
            Username
          </label>
          <input
            id="username"
            name="username"
            type="text"
            required
            autocomplete="username"
            value="<?= e($usernameValue) ?>"
            placeholder="admin"
            class="w-full h-11 px-3.5 rounded bg-white border border-sand text-espresso text-sm focus:outline-none focus:border-espresso focus:ring-1 focus:ring-espresso transition"
          />
        </div>

        <div class="space-y-1.5">
          <label for="password" class="block text-xs font-semibold uppercase tracking-wider text-espresso">
            Password
          </label>
          <div class="relative">
            <input
              id="password"
              name="password"
              type="password"
              required
              autocomplete="current-password"
              placeholder="••••••••"
              class="w-full h-11 pl-3.5 pr-11 rounded bg-white border border-sand text-espresso text-sm focus:outline-none focus:border-espresso focus:ring-1 focus:ring-espresso transition"
            />
            <button
              type="button"
              id="togglePasswordBtn"
              aria-label="Tampilkan atau sembunyikan password"
              class="absolute right-2.5 top-1/2 -translate-y-1/2 p-1 text-outline hover:text-espresso transition-colors"
            >
              <span class="material-symbols-outlined text-[20px]" id="togglePasswordIcon">visibility</span>
            </button>
          </div>
        </div>

        <button
          type="submit"
          class="w-full h-11 rounded bg-espresso hover:bg-[#3D2C22] text-cream font-semibold text-sm tracking-wide transition-colors flex items-center justify-center gap-2 shadow-sm"
        >
          <span>Masuk ke Dashboard</span>
          <span class="material-symbols-outlined text-[18px]">login</span>
        </button>
      </form>

      <div class="pt-4 border-t border-sand text-xs text-muted space-y-1">
        <p class="font-semibold text-espresso">Akun Demo Bawaan:</p>
        <p>Admin: <code class="px-1.5 py-0.5 rounded bg-surface-mid text-espresso font-mono">admin</code> / <code class="px-1.5 py-0.5 rounded bg-surface-mid text-espresso font-mono">admin123</code></p>
        <p>Barista: <code class="px-1.5 py-0.5 rounded bg-surface-mid text-espresso font-mono">barista</code> / <code class="px-1.5 py-0.5 rounded bg-surface-mid text-espresso font-mono">barista123</code></p>
      </div>
    </div>
  </main>

  <footer class="py-5 text-center text-xs text-muted border-t border-sand bg-surface-low">
    © <?= date('Y') ?> Kala Seduh Slow Bar • Sistem Autentikasi Stateless Serverless
  </footer>

  <script>
    const toggleBtn = document.getElementById('togglePasswordBtn');
    const pwdInput = document.getElementById('password');
    const toggleIcon = document.getElementById('togglePasswordIcon');
    if (toggleBtn && pwdInput && toggleIcon) {
      toggleBtn.addEventListener('click', () => {
        const isPwd = pwdInput.type === 'password';
        pwdInput.type = isPwd ? 'text' : 'password';
        toggleIcon.textContent = isPwd ? 'visibility_off' : 'visibility';
      });
    }
  </script>
</body>
</html>
