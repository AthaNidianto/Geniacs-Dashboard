<?php
/**
 * Gabungkan device lama (offline) ke device baru yang menggantikannya.
 *
 * Dipakai tombol "Gabungkan" di halaman Devices untuk kasus ONU diganti unit lain
 * (SN beda, PPPoE sama). Yang lama dihapus dari GenieACS dan dashboard, tag-nya
 * dipindah ke yang baru.
 *
 * Semua syarat dicek ulang di server (jangan percaya data dari browser):
 *  - POST, old_id dan new_id berbeda, keduanya ada di GenieACS
 *  - bukan router MikroTik
 *  - PPPoE username sama persis (tanpa beda huruf besar/kecil), tidak kosong
 *  - yang baru online (inform < 10 menit), yang lama offline (> 10 menit)
 */
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

use App\GenieACS;
use App\GenieACS_Fast;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method harus POST'], 405);
}

if (!isGenieACSConfigured()) {
    jsonResponse(['success' => false, 'message' => 'GenieACS belum dikonfigurasi']);
}

$input = json_decode(file_get_contents('php://input'), true);
$oldId = trim((string)($input['old_id'] ?? ''));
$newId = trim((string)($input['new_id'] ?? ''));

if ($oldId === '' || $newId === '' || $oldId === $newId) {
    jsonResponse(['success' => false, 'message' => 'old_id dan new_id wajib diisi dan harus berbeda']);
}

const MERGE_STALE_SEC = 600;   // sama dengan cron auto-summon-new.php

$conn = getDBConnection();
$result = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$credentials = $result->fetch_assoc();
if (!$credentials) {
    jsonResponse(['success' => false, 'message' => 'GenieACS tidak terhubung']);
}

$genieacs = new GenieACS($credentials['host'], $credentials['port'], $credentials['username'], $credentials['password']);

$oldRes = $genieacs->getDevice($oldId);
$newRes = $genieacs->getDevice($newId);
if (empty($oldRes['success']) || empty($newRes['success'])) {
    jsonResponse(['success' => false, 'message' => 'Salah satu device tidak ditemukan di GenieACS (mungkin sudah dihapus). Refresh halaman.']);
}

$oldDoc = $oldRes['data'];
$newDoc = $newRes['data'];

$isRouter = function ($doc) {
    $m = strtolower(($doc['_deviceId']['_Manufacturer'] ?? '') . ' ' . ($doc['_deviceId']['_ProductClass'] ?? ''));
    return strpos($m, 'mikrotik') !== false || strpos($m, 'routerboard') !== false;
};
if ($isRouter($oldDoc) || $isRouter($newDoc)) {
    jsonResponse(['success' => false, 'message' => 'Router MikroTik tidak bisa digabungkan.']);
}

$oldP = GenieACS_Fast::parseDeviceDataFast($oldDoc);
$newP = GenieACS_Fast::parseDeviceDataFast($newDoc);

$norm = function ($u) {
    $u = strtolower(trim((string)$u));
    return ($u === '' || $u === 'n/a') ? '' : $u;
};
$oldUser = $norm($oldP['pppoe_username'] ?? '');
$newUser = $norm($newP['pppoe_username'] ?? '');
if ($oldUser === '' || $oldUser !== $newUser) {
    jsonResponse(['success' => false, 'message' => 'PPPoE kedua device tidak sama (atau kosong). Gabungan dibatalkan.']);
}

$now = time();
$age = function ($doc) use ($now) {
    $t = isset($doc['_lastInform']) ? strtotime($doc['_lastInform']) : 0;
    return $t ? ($now - $t) : PHP_INT_MAX;
};
if ($age($newDoc) > MERGE_STALE_SEC) {
    jsonResponse(['success' => false, 'message' => 'Device baru belum online (inform terakhir > 10 menit). Gabungan dibatalkan.']);
}
if ($age($oldDoc) <= MERGE_STALE_SEC) {
    jsonResponse(['success' => false, 'message' => 'Device lama masih online (inform < 10 menit). Gabungan dibatalkan.']);
}

// Pindahkan tag lama yang belum ada di yang baru
$oldTags = is_array($oldDoc['_tags'] ?? null) ? $oldDoc['_tags'] : [];
$newTags = is_array($newDoc['_tags'] ?? null) ? $newDoc['_tags'] : [];
$movedTags = 0;
foreach (array_diff($oldTags, $newTags) as $tag) {
    $r = $genieacs->addTag($newId, $tag);
    if (!empty($r['success'])) $movedTags++;
}

$del = $genieacs->deleteDevice($oldId);
if (empty($del['success'])) {
    jsonResponse([
        'success' => false,
        'message' => 'Gagal menghapus device lama dari GenieACS (HTTP ' . ($del['http_code'] ?? '?') . '). Tag sudah disalin, tidak ada yang hilang.'
    ]);
}

// Bersihkan catatan dashboard
$oldSn = (string)($oldP['serial_number'] ?? '');
$newSn = (string)($newP['serial_number'] ?? '');
$stmt = $conn->prepare("DELETE FROM known_devices WHERE device_id = ?");
$stmt->bind_param('s', $oldId);
$stmt->execute();

$note = 'Menggantikan ONU ' . ($oldSn !== '' ? $oldSn : $oldId) . ' (PPPoE sama: ' . $newUser . ')';
$stmt = $conn->prepare("INSERT IGNORE INTO known_devices (device_id, first_seen, status, note) VALUES (?, NOW(), 'ok', ?)");
$stmt->bind_param('ss', $newId, $note);
$stmt->execute();

jsonResponse([
    'success' => true,
    'message' => "ONU $oldSn digabung ke $newSn" . ($movedTags ? " ($movedTags tag dipindah)" : ''),
    'old_sn' => $oldSn,
    'new_sn' => $newSn,
    'moved_tags' => $movedTags
]);