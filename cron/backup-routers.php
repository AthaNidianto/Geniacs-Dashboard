#!/usr/bin/env php
<?php
// Hanya boleh dijalankan dari CLI (cron) atau dari server ini sendiri, jangan bisa dipicu dari internet.
if (PHP_SAPI !== 'cli' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
// Backup semua router MikroTik yang terdaftar (Configuration > Router Backup).
// Jalankan harian, contoh jam 02:00:
//   0 2 * * * www-data /usr/bin/php /var/www/gacs-dashboard/cron/backup-routers.php >> /var/log/gacs-router-backup.log 2>&1

require_once __DIR__ . '/../config/config.php';

$rb = new App\RouterBackup(getDBConnection());
foreach ($rb->listTargets() as $t) {
    $r = $rb->run((int)$t['id']);
    echo '[' . date('Y-m-d H:i:s') . '] ' . $t['name'] . ' (' . $t['host'] . '): ' . ($r['success'] ? 'OK' : 'GAGAL') . ' - ' . $r['message'] . "\n";
}