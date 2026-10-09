#!/usr/bin/env php
<?php
// Catat snapshot jumlah CPE online/offline untuk grafik riwayat di dashboard.
//
// Jalankan tiap 5 menit lewat crontab (ganti path sesuai server):
//   crontab -e
//   tambahkan baris: menit "*/5" lalu "* * * *" diikuti perintah:
//   /usr/bin/php /var/www/gacs-dashboard/cron/record-status-history.php >> /var/log/gacs-status-history.log 2>&1
//
// Baris lengkapnya ada di README/penjelasan: */5 * * * * /usr/bin/php .../record-status-history.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/status-history.php';

use App\GenieACS;

$conn = getDBConnection();
$result = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$cfg = $result ? $result->fetch_assoc() : null;

if (!$cfg) {
    echo "[" . date('Y-m-d H:i:s') . "] GenieACS belum dikonfigurasi, keluar.\n";
    exit;
}

$genieacs = new GenieACS($cfg['host'], $cfg['port'], $cfg['username'], $cfg['password']);
$stats = $genieacs->getDeviceStats();

if (empty($stats['success'])) {
    echo "[" . date('Y-m-d H:i:s') . "] Gagal ambil statistik dari GenieACS.\n";
    exit(1);
}

$d = $stats['data'];
$written = recordStatusSnapshot($conn, $d['total'], $d['online'], $d['offline'], 200);

echo sprintf(
    "[%s] total=%d online=%d offline=%d %s\n",
    date('Y-m-d H:i:s'), $d['total'], $d['online'], $d['offline'],
    $written ? '(disimpan)' : '(dilewati, snapshot masih baru)'
);
