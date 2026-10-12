<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

requireLogin();

$data = json_decode(file_get_contents('php://input'), true);
$deviceId = $data['device_id'] ?? '';

if (empty($deviceId)) {
    jsonResponse(['success' => false, 'message' => 'Device ID required']);
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

// Batas waktu tunggu balasan ONU (ms); default 5 detik
$timeoutMs = isset($data['timeout_ms']) ? (int)$data['timeout_ms'] : 5000;
$timeoutMs = max(1000, min(30000, $timeoutMs));

// Summon + ambil admin credentials. Berhasil hanya kalau ONU benar-benar membalas.
$result = $genieacs->summonAndFetchAdminCredentials($deviceId, $timeoutMs);

if ($result['success']) {
    jsonResponse(['success' => true, 'message' => 'ONU membalas. Admin credentials sedang diambil...']);
} elseif (!empty($result['no_response'])) {
    jsonResponse([
        'success' => false,
        'no_response' => true,
        'message' => 'ONU tidak membalas dalam ' . round($timeoutMs / 1000) . ' detik (dinyatakan gagal)'
    ]);
} else {
    $errorMsg = 'Gagal summon device';
    if (isset($result['error'])) {
        $errorMsg .= ': ' . $result['error'];
    } elseif (isset($result['http_code'])) {
        $errorMsg .= ' (HTTP ' . $result['http_code'] . ')';
    }
    jsonResponse(['success' => false, 'message' => $errorMsg]);
}