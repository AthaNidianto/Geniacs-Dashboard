<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

requireLogin();

$data = json_decode(file_get_contents('php://input'), true);
$deviceId = $data['device_id'] ?? '';

if (empty($deviceId) || !is_string($deviceId)) {
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

// Tunggu balasan ONU maksimal 8 detik. Kalau ONU tidak membalas, reboot dibatalkan (bukan ditunda).
$res = $genieacs->rebootDevice($deviceId, 8000);

if (!empty($res['success'])) {
    jsonResponse(['success' => true, 'message' => 'Perintah reboot diterima ONU. ONU akan restart (sekitar 1-3 menit).']);
} elseif (!empty($res['no_response'])) {
    jsonResponse(['success' => false, 'no_response' => true, 'message' => 'ONU tidak membalas (offline?), reboot dibatalkan']);
}

$msg = 'Gagal reboot ONU';
if (isset($res['error'])) {
    $msg .= ': ' . $res['error'];
} elseif (isset($res['http_code'])) {
    $msg .= ' (HTTP ' . $res['http_code'] . ')';
}
jsonResponse(['success' => false, 'message' => $msg]);