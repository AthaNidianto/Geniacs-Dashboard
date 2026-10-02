<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

// Satu proses PHP mengirim ratusan task paralel; beri waktu cukup
set_time_limit(120);
ini_set('max_execution_time', 120);

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
if (count($deviceIds) > 1000) {
    jsonResponse(['success' => false, 'message' => 'Maksimal 1000 device per request']);
}

$genieacs = new GenieACS(
    $credentials['host'],
    $credentials['port'],
    $credentials['username'],
    $credentials['password']
);

try {
    // Concurrency 40 = jumlah koneksi paralel ke NBI GenieACS. Turunkan kalau NBI mulai error/timeout.
    $result = $genieacs->bulkSummonParallel($deviceIds, 40);
    jsonResponse($result);
} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ], 500);
}