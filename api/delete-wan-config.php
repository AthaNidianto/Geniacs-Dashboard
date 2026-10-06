<?php
/**
 * Delete WAN Connection Configuration
 *
 * Deletes a WAN connection on ONU via GenieACS TR-069
 * Includes TR069 connection protection with confirmation requirement
 *
 * Input (POST JSON):
 * {
 *     "device_id": "A4F33B-ZX%2DF663NV3a%20XPON-ZICG295C078F",
 *     "connection_index": 2,
 *     "connection_type": "ppp",  // "ppp" or "ip"
 *     "connection_name": "2_INTERNET_R_VID_30",
 *     "service_list": "INTERNET",
 *     "confirm_tr069_delete": false  // Must be true for TR069 connections
 * }
 *
 * Output (TR069 Blocked):
 * {
 *     "success": false,
 *     "error": "TR069 connection deletion blocked",
 *     "requires_confirmation": true,
 *     "is_tr069": true
 * }
 *
 * Output (Success):
 * {
 *     "success": true,
 *     "message": "WAN connection deleted successfully",
 *     "task_status": "queued"
 * }
 */

require_once __DIR__ . '/../config/config.php';
use App\GenieACS;

header('Content-Type: application/json');

// Require login
requireLogin();

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['device_id']) || !isset($input['connection_index']) || !isset($input['connection_type'])) {
    jsonResponse(false, 'Missing required fields: device_id, connection_index, connection_type');
}

$deviceId = $input['device_id'];
$connectionIndex = intval($input['connection_index']);
$connectionInstance = max(1, intval($input['connection_instance'] ?? 1));
$connectionType = strtolower($input['connection_type']); // "ppp" or "ip"
$connectionName = $input['connection_name'] ?? '';
$serviceList = $input['service_list'] ?? '';
$confirmTR069Delete = $input['confirm_tr069_delete'] ?? false;

// Validate connection index (1-8)
if ($connectionIndex < 1 || $connectionIndex > 8) {
    jsonResponse(false, 'Invalid connection index. Must be between 1 and 8.');
}

// Validate connection type
if (!in_array($connectionType, ['ppp', 'ip'])) {
    jsonResponse(false, 'Invalid connection type. Must be "ppp" or "ip".');
}

/**
 * Check if this is a TR069 connection
 * Detection logic: Check if service name or connection name contains TR069/CWMP
 */
function isTR069Connection($connectionName, $serviceList) {
    $serviceName = strtoupper($serviceList);
    $connName = strtoupper($connectionName);

    return strpos($serviceName, 'TR069') !== false ||
           strpos($serviceName, 'CWMP') !== false ||
           strpos($connName, 'TR069') !== false ||
           strpos($connName, 'CWMP') !== false;
}

// Check if this is a TR069 connection
$isTR069 = isTR069Connection($connectionName, $serviceList);

// Block TR069 deletion if not confirmed
if ($isTR069 && !$confirmTR069Delete) {
    jsonResponse(false, 'TR069 connection deletion blocked', [
        'requires_confirmation' => true,
        'is_tr069' => true,
        'connection_name' => $connectionName,
        'service_list' => $serviceList,
        'warning' => 'Deleting this connection will break device communication with GenieACS'
    ]);
}

// Build TR-069 parameter path
$basePath = "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$connectionIndex}";
if ($connectionType === 'ppp') {
    $basePath .= ".WANPPPConnection.{$connectionInstance}";
} else {
    $basePath .= ".WANIPConnection.{$connectionInstance}";
}

// Get GenieACS credentials
$db = getDBConnection();
$stmt = $db->prepare("SELECT host, port, username, password FROM genieacs_credentials LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();
$genieConfig = $result->fetch_assoc();

if (!$genieConfig) {
    jsonResponse(false, 'GenieACS credentials not configured');
}

// Initialize GenieACS client
$genieacs = new GenieACS(
    $genieConfig['host'],
    $genieConfig['port'],
    $genieConfig['username'],
    $genieConfig['password']
);

// Hapus instance koneksi lewat TR-069 DeleteObject.
// Smart Queuing: semua task diantre dulu, hanya task terakhir yang membangunkan ONU,
// jadi dashboard membalas dalam hitungan detik (tidak menunggu ONU / tidak timeout).
$tasks = [
    ['name' => 'deleteObject',  'objectName' => $basePath],
    ['name' => 'refreshObject', 'objectName' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice'],
];
$lastIndex = count($tasks) - 1;

foreach ($tasks as $idx => $task) {
    $res = $genieacs->queueTask($deviceId, $task, $idx === $lastIndex);
    if (empty($res['success'])) {
        error_log("[delete-wan] gagal task #{$idx} {$task['name']}: " . json_encode($res));
        jsonResponse(false, 'Gagal mengirim perintah hapus ke GenieACS: ' . ($res['error'] ?? ('HTTP ' . ($res['http_code'] ?? '?'))));
    }
}

jsonResponse(true, 'Perintah hapus WAN dikirim ke ONU. WAN akan hilang setelah ONU merespons (bisa 1-3 menit).', [
    'task_status'     => 'queued',
    'connection_path' => $basePath,
    'is_tr069'        => $isTR069,
    'method'          => 'deleteObject',
]);