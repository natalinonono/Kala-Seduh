<?php
/**
 * Router lokal untuk pengujian menggunakan PHP built-in server:
 *   php -S localhost:8000 router.php
 * Meniru perilaku rewrites & static serving di vercel.json.
 */

declare(strict_types=1);

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Sajikan file statis dari folder /public pada root URL (meniru Vercel)
$publicFile = __DIR__ . '/public' . $uriPath;
if ($uriPath !== '/' && is_file($publicFile)) {
    $ext = strtolower(pathinfo($publicFile, PATHINFO_EXTENSION));
    $mimeTypes = [
        'css'  => 'text/css; charset=utf-8',
        'js'   => 'application/javascript; charset=utf-8',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'ico'  => 'image/x-icon',
        'json' => 'application/json; charset=utf-8',
    ];
    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
    }
    readfile($publicFile);
    return true;
}

$routes = [
    '/'             => __DIR__ . '/api/index.php',
    '/index.php'    => __DIR__ . '/api/index.php',
    '/login'        => __DIR__ . '/api/login.php',
    '/logout'       => __DIR__ . '/api/logout.php',
    '/admin'        => __DIR__ . '/api/admin.php',
    '/proses-order' => __DIR__ . '/api/proses_order.php',
    '/antrean'      => __DIR__ . '/api/antrean.php',
];

if (isset($routes[$uriPath])) {
    require $routes[$uriPath];
    return true;
}

// Izinkan akses langsung ke /api/*.php (kecuali /api/config/*)
if (preg_match('#^/api/([a-zA-Z0-9_]+\.php)$#', $uriPath, $matches)) {
    $target = __DIR__ . '/api/' . $matches[1];
    if (is_file($target)) {
        require $target;
        return true;
    }
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html lang="id"><body style="font-family:sans-serif;padding:2rem;background:#F5F0E6;color:#2C1E16;"><h1>404 - Halaman Tidak Ditemukan</h1><p><a href="/">Kembali ke Kala Seduh</a></p></body></html>';
return true;
