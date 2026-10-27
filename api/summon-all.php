<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

// Satu proses PHP mengirim ratusan task paralel; beri waktu cukup
set_time_limit(180);
ini_set('max_execution_time', 180);

if (!isGenieACSConfigured()) {
    jsonResponse(['success' => false, 'message' => 'GenieACS belum dikonfigurasi']);
}

$conn = getDBConnection();
$result = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$credentials = $result->fetch_assoc();

if (!$credentials) {
    jsonResponse(['success' => false, 'message' => 'GenieACS tidak terhubung']);
}

use App\GenieACS;

$input = json_decode(file_get_contents('php://input'), true);
$deviceIds = isset($input['device_ids']) && is_array($input['device_ids']) ? $input['device_ids'] : [];

// Bersihkan input: hanya string tak kosong
$deviceIds = array_values(array_filter(array_map(function ($id) {
    return is_string($id) ? trim($id) : '';
}, $deviceIds), 'strlen'));

if (count($deviceIds) === 0) {
    jsonResponse(['success' => false, 'message' => 'Tidak ada device yang dipilih']);
}

// Batas pengaman per request
if (count($deviceIds) > 400) {
    jsonResponse(['success' => false, 'message' => 'Maksimal 400 device per request']);
}

// Batas waktu tunggu balasan ONU (ms). ONU yang tidak membalas dalam waktu ini dinyatakan gagal.
$timeoutMs = isset($input['timeout_ms']) ? (int)$input['timeout_ms'] : 5000;
$timeoutMs = max(1000, min(30000, $timeoutMs));

$genieacs = new GenieACS(
    $credentials['host'],
    $credentials['port'],
    $credentials['username'],
    $credentials['password']
);

try {
    // Concurrency 40 = jumlah koneksi paralel ke NBI GenieACS. Turunkan kalau NBI mulai error/timeout.
    // Berhasil = ONU membalas dalam $timeoutMs; selain itu gagal (lihat result['failed']).
    $result = $genieacs->bulkSummonVerify($deviceIds, $timeoutMs, 40);

    // ONU yang membalas (bukan yang gagal) tidak lagi bertanda BARU
    try {
        $failedIds = [];
        foreach (($result['failed'] ?? []) as $f) {
            if (isset($f['id'])) $failedIds[$f['id']] = true;
        }
        $okIds = array_values(array_filter($deviceIds, function ($id) use ($failedIds) { return !isset($failedIds[$id]); }));
        \App\KnownDevices::markSummoned($conn, $okIds);
    } catch (\Throwable $e) {
        error_log('[known_devices] ' . $e->getMessage());
    }

    jsonResponse($result);
} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ], 500);
}