<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

requireLogin();

$data = json_decode(file_get_contents('php://input'), true);
$deviceIds = $data['device_ids'] ?? [];

if (empty($deviceIds) || !is_array($deviceIds)) {
    jsonResponse(['success' => false, 'message' => 'Daftar Device ID kosong']);
}

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

$genieacs = new GenieACS(
    $credentials['host'],
    $credentials['port'],
    $credentials['username'],
    $credentials['password']
);

// Tingkatkan batas waktu eksekusi PHP agar tidak timeout saat summon ratusan modem
set_time_limit(300); 

$successCount = 0;
$failCount = 0;

$successCount = 0;
$failCount = 0;

foreach ($deviceIds as $deviceId) {
    // Gunakan fungsi Fast Bulk Summon (ngebut tanpa nunggu)
    $res = $genieacs->bulkSummonFast($deviceId);
    
    if ($res['success']) {
        $successCount++;
    } else {
        $failCount++;
    }
    // Tidak perlu usleep (jeda) lagi karena perintah dikirim ke antrean GenieACS dengan aman
}

jsonResponse([
    'success' => true,
    'message' => 'Proses massal selesai',
    'success_count' => $successCount,
    'fail_count' => $failCount
]);