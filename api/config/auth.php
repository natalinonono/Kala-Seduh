<?php
/**
 * Manajemen Autentikasi Stateless berbasis Signed Cookie (HMAC-SHA256).
 * Dirancang untuk arsitektur serverless Vercel yang tidak menyimpan session di disk.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const AUTH_COOKIE_NAME = 'kala_seduh_auth';
const AUTH_TTL_SECONDS = 86400; // 24 jam

/**
 * Mengambil secret key untuk signing cookie autentikasi & token CSRF.
 */
function getAuthSecret(): string
{
    $secret = envValue('APP_SECRET');
    if ($secret !== null && strlen($secret) >= 16) {
        return $secret;
    }

    // Fallback deterministik berbasis kredensial server agar tetap konsisten antar-invokasi serverless
    $seed = implode('|', [
        'kala-seduh-slow-bar-v1',
        envValue('DB_HOST', 'localhost'),
        envValue('DB_USER', 'postgres'),
        envValue('DB_PASS', 'local-dev-secret'),
    ]);

    return hash('sha256', $seed);
}

/**
 * Encode Base64 URL-safe tanpa padding.
 */
function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Decode Base64 URL-safe.
 */
function base64UrlDecode(string $data): string|false
{
    $remainder = strlen($data) % 4;
    if ($remainder > 0) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'), true);
}

/**
 * Membuat signed token untuk user terautentikasi.
 *
 * @param array{id: int, username: string, nama_lengkap: string, role: string} $user
 */
function createAuthToken(array $user): string
{
    $now = time();
    $payload = [
        'uid'          => (int) $user['id'],
        'username'     => (string) $user['username'],
        'nama_lengkap' => (string) $user['nama_lengkap'],
        'role'         => (string) $user['role'],
        'iat'          => $now,
        'exp'          => $now + AUTH_TTL_SECONDS,
    ];

    $encodedPayload = base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    $signature = base64UrlEncode(hash_hmac('sha256', $encodedPayload, getAuthSecret(), true));

    return $encodedPayload . '.' . $signature;
}

/**
 * Memverifikasi signed token dan mengembalikan data user jika valid.
 *
 * @return array{id: int, username: string, nama_lengkap: string, role: string, exp: int}|null
 */
function verifyAuthToken(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return null;
    }

    [$encodedPayload, $providedSig] = $parts;
    if ($encodedPayload === '' || $providedSig === '') {
        return null;
    }

    $expectedSig = base64UrlEncode(hash_hmac('sha256', $encodedPayload, getAuthSecret(), true));
    if (!hash_equals($expectedSig, $providedSig)) {
        return null;
    }

    $rawJson = base64UrlDecode($encodedPayload);
    if ($rawJson === false) {
        return null;
    }

    $payload = json_decode($rawJson, true);
    if (!is_array($payload)) {
        return null;
    }

    $exp = (int) ($payload['exp'] ?? 0);
    if ($exp < time()) {
        return null;
    }

    if (!isset($payload['uid'], $payload['username'], $payload['nama_lengkap'], $payload['role'])) {
        return null;
    }

    return [
        'id'           => (int) $payload['uid'],
        'username'     => (string) $payload['username'],
        'nama_lengkap' => (string) $payload['nama_lengkap'],
        'role'         => (string) $payload['role'],
        'exp'          => $exp,
    ];
}

/**
 * Menyimpan cookie autentikasi HttpOnly & SameSite=Lax.
 *
 * @param array{id: int, username: string, nama_lengkap: string, role: string} $user
 */
function setAuthCookie(array $user): string
{
    $token = createAuthToken($user);
    setcookie(AUTH_COOKIE_NAME, $token, [
        'expires'  => time() + AUTH_TTL_SECONDS,
        'path'     => '/',
        'secure'   => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[AUTH_COOKIE_NAME] = $token;

    return $token;
}

/**
 * Menghapus cookie autentikasi (logout).
 */
function clearAuthCookie(): void
{
    setcookie(AUTH_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[AUTH_COOKIE_NAME]);
}

/**
 * Mengambil user yang sedang login dari signed cookie, atau null jika belum login.
 *
 * @return array{id: int, username: string, nama_lengkap: string, role: string, exp: int}|null
 */
function getCurrentUser(): ?array
{
    $token = $_COOKIE[AUTH_COOKIE_NAME] ?? '';
    if (!is_string($token) || $token === '') {
        return null;
    }
    return verifyAuthToken($token);
}

/**
 * Mewajibkan autentikasi untuk halaman Admin/Barista.
 *
 * @return array{id: int, username: string, nama_lengkap: string, role: string, exp: int}
 */
function requireAuth(): array
{
    $user = getCurrentUser();
    if ($user === null) {
        setFlashMessage('warning', 'Silakan login terlebih dahulu untuk mengakses Workspace Barista & Admin.');
        redirectTo('/login');
    }
    return $user;
}

/**
 * Menghasilkan token CSRF stateless yang terikat dengan sesi cookie pengguna saat ini.
 */
function generateCsrfToken(): string
{
    $sessionCookie = (string) ($_COOKIE[AUTH_COOKIE_NAME] ?? 'guest');
    return hash_hmac('sha256', 'csrf|' . $sessionCookie, getAuthSecret());
}

/**
 * Memverifikasi token CSRF pada request POST.
 */
function verifyCsrfToken(?string $token): bool
{
    if (!is_string($token) || $token === '') {
        return false;
    }
    return hash_equals(generateCsrfToken(), $token);
}
