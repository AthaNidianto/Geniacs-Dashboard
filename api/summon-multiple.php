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

foreach ($deviceIds as $deviceId) {
    // Gunakan fungsi summon yang sudah kita modifikasi dengan fitur TR-098 & TR-181 sebelumnya
    $res = $genieacs->summonAndFetchAdminCredentials($deviceId);
    
    if ($res['success']) {
        $successCount++;
    } else {
        $failCount++;
    }
    
    // Jeda 50ms per device agar server GenieACS tidak nge-hang karena dibombardir ratusan request sekaligus
    usleep(50000); 
}

jsonResponse([
    'success' => true,
    'message' => 'Proses massal selesai',
    'success_count' => $successCount,
    'fail_count' => $failCount
]);