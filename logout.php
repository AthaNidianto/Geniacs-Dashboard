<?php
require_once __DIR__ . '/config/config.php';

// Catat logout sebelum sesi dihapus (butuh username di sesi)
if (isLoggedIn()) {
    \App\AuditLog::log('Logout', '', null, true);
}

// Destroy session
session_destroy();

// Redirect to login
redirect('/login.php');