<?php
/**
 * Endpoint Logout — Menghapus signed auth cookie dan mengarahkan ke halaman login.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

clearAuthCookie();
setFlashMessage('info', 'Anda telah keluar dari sesi Workspace Barista.');
redirectTo('/login');
