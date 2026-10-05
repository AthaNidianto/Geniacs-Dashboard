<?php
/**
 * Add New WAN Connection Configuration
 *
 * Creates a new WAN connection on ONU via GenieACS TR-069
 *
 * Input (POST JSON):
 * {
 *     "device_id": "A4F33B-ZX%2DF663NV3a%20XPON-ZICG295C078F",
 *     "connection_index": 4,
 *     "connection_type": "ppp",  // "ppp" or "ip"
 *     "name": "4_INTERNET_R_VID_100",
 *     "parameters": {
 *         "Enable": true,
 *         "ConnectionType": "IP_Routed",
 *         "Username": "user@isp",
 *         "Password": "password",
 *         "NATEnabled": true,
 *         "X_CT-COM_ServiceList": "INTERNET",
 *         "X_CT-COM_VLANID": 100
 *     }
 * }
 *
 * Output:
 * {
 *     "success": true,
 *     "message": "New WAN connection created successfully",
 *     "connection_index": 4,
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
if (!isset($input['device_id']) || !isset($input['connection_index']) || !isset($input['connection_type']) || !isset($input['parameters'])) {
    jsonResponse(false, 'Missing required fields: device_id, connection_index, connection_type, parameters');
}

$deviceId = $input['device_id'];
$connectionIndex = intval($input['connection_index']);
$connectionType = strtolower($input['connection_type']); // "ppp" or "ip"
$connectionName = $input['name'] ?? '';
$parameters = $input['parameters'];

// Validate connection index (1-8)
if ($connectionIndex < 1 || $connectionIndex > 8) {
    jsonResponse(false, 'Invalid connection index. Must be between 1 and 8.');
}

// Validate connection type
if (!in_array($connectionType, ['ppp', 'ip'])) {
    jsonResponse(false, 'Invalid connection type. Must be "ppp" or "ip".');
}

// Build TR-069 parameter path
$basePath = "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$connectionIndex}";
if ($connectionType === 'ppp') {
    $basePath .= ".WANPPPConnection.1";
} else {
    $basePath .= ".WANIPConnection.1";
}

// Map of allowed parameters to their TR-069 paths
$allowedParams = [
    'Name' => 'Name',
    'Enable' => 'Enable',
    'ConnectionType' => 'ConnectionType',
    'Username' => 'Username',
    'Password' => 'Password',
    'NATEnabled' => 'NATEnabled',
    'X_CT-COM_ServiceList' => 'X_CT-COM_ServiceList',
    'X_CT-COM_LanInterface' => 'X_CT-COM_LanInterface',
    'X_CT-COM_VLANID' => 'X_CT-COM_VLANID',
];

// Build parameter array for GenieACS
$genieParams = [];

// Add connection name if provided
if (!empty($connectionName)) {
    $genieParams[$basePath . '.Name'] = $connectionName;
}

foreach ($parameters as $key => $value) {
    if (!isset($allowedParams[$key])) {
        jsonResponse(false, "Invalid parameter: {$key}");
    }

    $fullPath = $basePath . '.' . $allowedParams[$key];
    $genieParams[$fullPath] = $value;
}

// Validate required parameters for new connection
$requiredParams = ['ConnectionType'];
foreach ($requiredParams as $param) {
    if (!isset($parameters[$param]) && $param !== 'Name') {
        jsonResponse(false, "Missing required parameter: {$param}");
    }
}

// For PPPoE connections, username and password are required
if ($connectionType === 'ppp') {
    if (empty($parameters['Username']) || empty($parameters['Password'])) {
        jsonResponse(false, 'Username and Password are required for PPPoE connections');
    }
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

// Baca instance WAN yang sudah ada di ONU (dari data yang tersimpan di GenieACS)
$subKey = ($connectionType === 'ppp') ? 'WANPPPConnection' : 'WANIPConnection';
$devRes = $genieacs->getDevices(['_id' => $deviceId], 1, 0, 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice');
$wcd = $devRes['data'][0]['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'] ?? [];

$existingIdx = array_values(array_filter(array_keys($wcd), function ($k) { return ctype_digit((string)$k); }));
$maxIdx = $existingIdx ? max(array_map('intval', $existingIdx)) : 0;
$hasDevice = isset($wcd[(string)$connectionIndex]);
$hasConn = $hasDevice && isset($wcd[(string)$connectionIndex][$subKey]['1']);

// ONU yang menentukan nomor instance baru (selalu nomor berikutnya), jadi index harus pas
if (!$hasDevice && $connectionIndex !== $maxIdx + 1) {
    jsonResponse(false, 'Index tidak valid. Untuk WAN baru gunakan index ' . ($maxIdx + 1) . '.');
}

// Susun daftar task
$tasks = [];

if (!$hasDevice) {
    $tasks[] = [
        'name' => 'addObject',
        'objectName' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice'
    ];
}
if (!$hasConn) {
    $tasks[] = [
        'name' => 'addObject',
        'objectName' => "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$connectionIndex}.{$subKey}"
    ];
}

// Format yang benar: [[path, value, type], ...]
$boolParams = ['Enable', 'NATEnabled'];
$uintParams = ['X_CT-COM_VLANID'];
$values = [];
foreach ($genieParams as $fullPath => $val) {
    $leaf = substr($fullPath, strrpos($fullPath, '.') + 1);
    if (in_array($leaf, $boolParams, true)) {
        $type = 'xsd:boolean';
        $val = filter_var($val, FILTER_VALIDATE_BOOLEAN);
    } elseif (in_array($leaf, $uintParams, true)) {
        $type = 'xsd:unsignedInt';
        $val = intval($val);
    } else {
        $type = 'xsd:string';
    }
    $values[] = [$fullPath, $val, $type];
}
$tasks[] = ['name' => 'setParameterValues', 'parameterValues' => $values];

// Smart Queuing: semua task masuk antrean tanpa menunggu, hanya task terakhir yang membangunkan ONU
$lastIndex = count($tasks) - 1;
foreach ($tasks as $i => $task) {
    $res = $genieacs->queueTask($deviceId, $task, $i === $lastIndex);
    if (!$res['success']) {
        $msg = $res['error'] ?? ('HTTP ' . ($res['http_code'] ?? '?'));
        jsonResponse(false, 'Gagal mengantrikan task WAN: ' . $msg);
    }
}

jsonResponse(true, 'Perintah WAN dikirim ke ONU. Data akan muncul setelah ONU memproses (sekitar 1 menit).', [
    'task_status' => 'queued',
    'connection_index' => $connectionIndex,
    'connection_type' => $connectionType,
    'tasks_queued' => count($tasks),
    'connection_path' => $basePath
]);