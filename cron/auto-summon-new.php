#!/usr/bin/env php
<?php
// Auto-summon ONU baru.
//
// Tiap kali dijalankan: ambil daftar device dari GenieACS, bandingkan dengan tabel known_devices.
// Device yang belum pernah terlihat dianggap BARU -> otomatis di-summon (ONU harus membalas),
// supaya data SSID, client, dan admin credentials langsung terisi tanpa klik manual.
//
// - Jalan pertama kali: semua device yang sudah ada dicatat sebagai "dikenal" (TIDAK di-summon massal).
// - ONU yang belum membalas dicoba lagi tiap 10 menit, maksimal 6 kali.
// - Router MikroTik tidak ikut di-summon.
//
// Jalankan tiap 2 menit lewat cron:
//   */2 * * * * www-data /usr/bin/php /var/www/gacs-dashboard/cron/auto-summon-new.php >> /var/log/gacs-auto-summon.log 2>&1

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../config/config.php';

use App\GenieACS;

const MAX_ATTEMPTS = 6;       // percobaan summon per ONU baru
const RETRY_GAP_MIN = 10;     // jeda antar percobaan (menit)
const BATCH_LIMIT = 30;       // maksimal ONU yang di-summon per eksekusi
const SUMMON_TIMEOUT_MS = 8000;

function logln($msg) { echo '[' . date('Y-m-d H:i:s') . "] $msg\n"; }

// Satu proses saja pada satu waktu
$lock = fopen(sys_get_temp_dir() . '/gacs-auto-summon.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

$conn = getDBConnection();
$res = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$cfg = $res ? $res->fetch_assoc() : null;
if (!$cfg) { logln('GenieACS belum dikonfigurasi, keluar.'); exit; }

$conn->query("CREATE TABLE IF NOT EXISTS `known_devices` (
    `device_id` varchar(191) NOT NULL,
    `first_seen` datetime NOT NULL,
    `status` varchar(10) NOT NULL DEFAULT 'pending',
    `attempts` int(11) NOT NULL DEFAULT 0,
    `last_attempt` datetime NULL DEFAULT NULL,
    `summoned_at` datetime NULL DEFAULT NULL,
    `note` varchar(150) NULL DEFAULT NULL,
    PRIMARY KEY (`device_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$genieacs = new GenieACS($cfg['host'], $cfg['port'], $cfg['username'], $cfg['password']);

// 1) Daftar device saat ini (hanya field kecil)
$r = $genieacs->getDevices([], 0, 0, '_id,_deviceId._Manufacturer,_deviceId._ProductClass');
if (empty($r['success']) || !is_array($r['data'] ?? null)) {
    logln('Gagal ambil daftar device dari GenieACS.');
    exit(1);
}
$current = [];
foreach ($r['data'] as $d) {
    if (empty($d['_id'])) continue;
    $maker = strtolower($d['_deviceId']['_Manufacturer'] ?? '');
    $model = strtolower($d['_deviceId']['_ProductClass'] ?? '');
    $isRouter = strpos($maker, 'mikrotik') !== false || strpos($maker, 'routerboard') !== false || strpos($model, 'rb') === 0;
    $current[$d['_id']] = $isRouter;
}
if (!$current) { logln('Daftar device kosong, dilewati.'); exit; }

// 2) Device yang sudah dikenal
$known = [];
$q = $conn->query("SELECT device_id FROM known_devices");
while ($q && ($row = $q->fetch_assoc())) $known[$row['device_id']] = true;

$new = array_diff_key($current, $known);
// Jalan pertama, --seed, atau lonjakan device baru yang tidak wajar (>100, mis. data GenieACS di-reset):
// dicatat sebagai dikenal saja, tidak di-summon massal.
$firstRun = empty($known) || in_array('--seed', $argv ?? [], true) || count($new) > 100;

if ($new) {
    $stmt = $conn->prepare("INSERT IGNORE INTO known_devices (device_id, first_seen, status, note) VALUES (?, NOW(), ?, ?)");
    foreach ($new as $id => $isRouter) {
        if ($firstRun) { $st = 'seeded'; $note = 'sudah ada sebelum auto-summon aktif'; }
        elseif ($isRouter) { $st = 'skipped'; $note = 'router MikroTik'; }
        else { $st = 'pending'; $note = null; }
        $stmt->bind_param('sss', $id, $st, $note);
        $stmt->execute();
    }
    if ($firstRun) {
        logln('Seed: ' . count($new) . ' device dicatat sebagai dikenal (tidak di-summon massal).');
        exit;
    }
    logln('Device baru terdeteksi: ' . count($new));
}

// 3) Summon yang masih menunggu / perlu dicoba lagi
$sql = sprintf(
    "SELECT device_id FROM known_devices
      WHERE status IN ('pending','fail') AND attempts < %d
        AND (last_attempt IS NULL OR last_attempt < (NOW() - INTERVAL %d MINUTE))
      ORDER BY first_seen ASC LIMIT %d",
    MAX_ATTEMPTS, RETRY_GAP_MIN, BATCH_LIMIT
);
$ids = [];
$q = $conn->query($sql);
while ($q && ($row = $q->fetch_assoc())) $ids[] = $row['device_id'];
if (!$ids) exit;

$out = $genieacs->bulkSummonVerify($ids, SUMMON_TIMEOUT_MS);
$failed = [];
foreach ($out['failed'] ?? [] as $f) $failed[$f['id']] = $f['reason'] ?? 'gagal';

$upd = $conn->prepare("UPDATE known_devices SET status=?, attempts=attempts+1, last_attempt=NOW(),
                       summoned_at = IF(? = 'ok', NOW(), summoned_at), note=? WHERE device_id=?");
foreach ($ids as $id) {
    if (isset($failed[$id])) {
        $st = 'fail';
        $note = $failed[$id] === 'no_response' ? 'ONU tidak membalas' : 'error saat summon';
    } else {
        $st = 'ok';
        $note = null;
    }
    $upd->bind_param('ssss', $st, $st, $note, $id);
    $upd->execute();
}

logln(sprintf('Auto-summon: %d dicoba, %d berhasil, %d belum membalas (akan dicoba lagi).',
    count($ids), $out['success_count'] ?? 0, $out['fail_count'] ?? 0));