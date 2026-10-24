<?php
// General Configuration
define('APP_NAME', 'GACS Dashboard');
define('APP_URL', 'https://157.20.207.246/');
define('ASSETS_URL', APP_URL . '/assets');

// Session Configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);
// Cookie sesi tidak ikut terkirim pada POST dari situs lain (lapisan pertama anti-CSRF)
ini_set('session.cookie_samesite', 'Lax');
// Cookie hanya dikirim lewat HTTPS kalau halaman dibuka dengan HTTPS
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', 1);
}
// Sesi disimpan lama di server (user monitor tidak boleh timeout). Cookie user biasa tetap berakhir saat browser ditutup.
ini_set('session.gc_maxlifetime', 31536000);
session_start();

// Timezone
date_default_timezone_set('Asia/Jakarta');

// Error Reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Disabled to prevent breaking JSON responses
ini_set('log_errors', 1);

// Autoload Composer
require_once __DIR__ . '/../vendor/autoload.php';

// Load Database Config
require_once __DIR__ . '/database.php';

// Helper Functions
require_once __DIR__ . '/../lib/helpers.php';

// Header keamanan dasar (di Nginx boleh ditambah lagi)
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
}

// Tolak POST dari situs lain (CSRF)
enforceSameOrigin();

// Batasi akses user role monitor (hanya Dashboard)
enforceRoleAccess();

// Audit log: catat aksi yang mengubah data (siapa, IP, hasil). Tidak berpengaruh ke request lain.
\App\AuditLog::autoStart();