<?php
/**
 * Konfigurasi koneksi PostgreSQL (Supabase) menggunakan PDO pgsql.
 * Kompatibel dengan Supabase Transaction Pooler (port 6543) & Vercel Serverless.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Membuat / mengembalikan instance PDO ke PostgreSQL Supabase.
 *
 * @throws RuntimeException jika variabel lingkungan belum lengkap atau koneksi gagal.
 */
function getDbConnection(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = envValue('DB_HOST');
    $port = envValue('DB_PORT', '6543');
    $name = envValue('DB_NAME', 'postgres');
    $user = envValue('DB_USER');
    $pass = envValue('DB_PASS');

    // Dukungan tambahan apabila pengguna menyediakan DATABASE_URL lengkap
    $databaseUrl = envValue('DATABASE_URL');
    if ((!$host || !$user || $pass === null) && $databaseUrl) {
        $parts = parse_url($databaseUrl);
        if (is_array($parts)) {
            $host = $host ?: ($parts['host'] ?? null);
            $port = $port ?: (string) ($parts['port'] ?? '6543');
            $name = $name ?: (isset($parts['path']) ? ltrim($parts['path'], '/') : 'postgres');
            $user = $user ?: (isset($parts['user']) ? urldecode($parts['user']) : null);
            $pass = $pass ?? (isset($parts['pass']) ? urldecode($parts['pass']) : null);
        }
    }

    if (!$host || !$port || !$name || !$user || $pass === null || $pass === '') {
        throw new RuntimeException(
            'Konfigurasi database Supabase belum lengkap. Pastikan DB_HOST, DB_PORT, DB_NAME, DB_USER, dan DB_PASS sudah diatur pada Environment Variables.'
        );
    }

    if (!extension_loaded('pdo_pgsql')) {
        throw new RuntimeException(
            'Ekstensi PHP pdo_pgsql tidak tersedia pada runtime ini.'
        );
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
        $host,
        $port,
        $name
    );

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Wajib true saat terhubung melalui Supabase Transaction Pooler (port 6543)
            PDO::ATTR_EMULATE_PREPARES   => true,
            PDO::ATTR_TIMEOUT            => 10,
        ]);
        $pdo->exec("SET TIME ZONE 'Asia/Jakarta'");
    } catch (PDOException $e) {
        error_log('[Kala Seduh DB Error] ' . $e->getMessage());
        throw new RuntimeException(
            'Gagal terhubung ke database PostgreSQL Supabase. Periksa kredensial dan status server database Anda.',
            0,
            $e
        );
    }

    return $pdo;
}
