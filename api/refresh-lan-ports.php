<?php
/**
 * Refresh status port LAN (Ethernet) dari ONU.
 * Antrekan refreshObject untuk LANEthernetInterfaceConfig (TR-098) atau Device.Ethernet.Interface (TR-181),
 * ONU dibangunkan lewat connection request. Hasilnya muncul di GenieACS setelah ONU merespons,
 * lalu halaman memuat ulang data.
 */
require_once __DIR__ . '/../config/config.php';
requireLogin();

use App\GenieACS;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$deviceId = $input['device_id'] ?? '';
if ($deviceId === '') {
    jsonResponse(['success' => false, 'message' => 'Device ID required']);
}

try {
    $db = getDBConnection();
    $result = $db->query("SELECT host, port, username, password FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
    $config = $result ? $result->fetch_assoc() : null;
    if (!$config) {
        jsonResponse(['success' => false, 'message' => 'GenieACS belum dikonfigurasi']);
    }

    $genieacs = new GenieACS($config['host'], $config['port'], $config['username'], $config['password']);

    $deviceResult = $genieacs->getDevice($deviceId);
    if (empty($deviceResult['success'])) {
        jsonResponse(['success' => false, 'message' => 'Device tidak ditemukan di GenieACS']);
    }
    $device = $deviceResult['data'];
    $isTr181 = !isset($device['InternetGatewayDevice']) && isset($device['Device']);

    $objectName = $isTr181
        ? 'Device.Ethernet.Interface'
        : 'InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig';

    $res = $genieacs->queueTask($deviceId, ['name' => 'refreshObject', 'objectName' => $objectName], true);
    error_log(sprintf('[refresh-lan] %s -> http=%s', $objectName, $res['http_code'] ?? '-'));

    if (empty($res['success'])) {
        $msg = $res['error'] ?? ('HTTP ' . ($res['http_code'] ?? '?'));
        jsonResponse(['success' => false, 'message' => 'Gagal mengantrikan refresh: ' . $msg]);
    }

    // 200 = ONU sudah menjawab, 202 = task diantrekan (ONU belum menjawab)
    $done = (($res['http_code'] ?? 0) == 200);
    jsonResponse([
        'success' => true,
        'message' => $done ? 'Status port diperbarui' : 'Perintah dikirim, ONU belum menjawab (coba lagi sebentar)',
        'data' => ['done' => $done]
    ]);
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}