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

// Pembungkus: jsonResponse() di helpers.php menerima array, bukan (sukses, pesan, data)
function wanResponse($success, $message, $extra = []) {
    jsonResponse(array_merge(['success' => $success, 'message' => $message], $extra));
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['device_id']) || !isset($input['connection_index']) || !isset($input['connection_type']) || !isset($input['parameters'])) {
    wanResponse(false, 'Missing required fields: device_id, connection_index, connection_type, parameters');
}

$deviceId = $input['device_id'];
$connectionIndex = intval($input['connection_index']);
$connectionType = strtolower($input['connection_type']); // "ppp" or "ip"
$connectionName = $input['name'] ?? '';
$parameters = $input['parameters'];

// Validate connection index (1-8)
if ($connectionIndex < 1 || $connectionIndex > 8) {
    wanResponse(false, 'Invalid connection index. Must be between 1 and 8.');
}

// Validate connection type
if (!in_array($connectionType, ['ppp', 'ip'])) {
    wanResponse(false, 'Invalid connection type. Must be "ppp" or "ip".');
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
    'X_CT-COM_ServiceList' => 'X_HW_SERVICELIST',
    'X_CT-COM_LanInterface' => 'X_CT-COM_LanInterface',
    'X_CT-COM_VLANID' => 'X_HW_VLAN',
];

// Build parameter array for GenieACS
$genieParams = [];

// Add connection name if provided
if (!empty($connectionName)) {
    $genieParams[$basePath . '.Name'] = $connectionName;
}

foreach ($parameters as $key => $value) {
    if (!isset($allowedParams[$key])) {
        wanResponse(false, "Invalid parameter: {$key}");
    }

    if ($key === 'X_CT-COM_LanInterface') {
        foreach (preg_split('/[,;\s]+/', (string)$value) as $iface) {
            if (preg_match('/LANEthernetInterfaceConfig\.(\d+)/', $iface, $m)) {
                $genieParams[$basePath . ".X_HW_LANBIND.Lan{$m[1]}Enable"] = true;
            } elseif (preg_match('/WLANConfiguration\.(\d+)/', $iface, $m)) {
                $genieParams[$basePath . ".X_HW_LANBIND.SSID{$m[1]}Enable"] = true;
            }
        }
        continue;
    }

    $fullPath = $basePath . '.' . $allowedParams[$key];
    $genieParams[$fullPath] = $value;
}

// Validate required parameters for new connection
$requiredParams = ['ConnectionType'];
foreach ($requiredParams as $param) {
    if (!isset($parameters[$param]) && $param !== 'Name') {
        wanResponse(false, "Missing required parameter: {$param}");
    }
}

// For PPPoE connections, username and password are required
if ($connectionType === 'ppp') {
    if (empty($parameters['Username']) || empty($parameters['Password'])) {
        wanResponse(false, 'Username and Password are required for PPPoE connections');
    }
}

// Get GenieACS credentials
$db = getDBConnection();
$stmt = $db->prepare("SELECT host, port, username, password FROM genieacs_credentials LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();
$genieConfig = $result->fetch_assoc();

if (!$genieConfig) {
    wanResponse(false, 'GenieACS credentials not configured');
}

// Initialize GenieACS client
$genieacs = new GenieACS(
    $genieConfig['host'],
    $genieConfig['port'],
    $genieConfig['username'],
    $genieConfig['password']
);

$tStart = microtime(true);

// Baca instance WAN yang sudah ada di ONU (dari data yang tersimpan di GenieACS)
$subKey = ($connectionType === 'ppp') ? 'WANPPPConnection' : 'WANIPConnection';
$devRes = $genieacs->getDevices(['_id' => $deviceId], 1, 0, 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice');
$wcd = $devRes['data'][0]['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'] ?? [];

error_log(sprintf(
    '[add-wan] getDevices %.2fs, hasDevice=%s hasConn=%s maxIdx=%d',
    microtime(true) - $tStart,
    var_export($hasDevice ?? null, true),
    var_export($hasConn ?? null, true),
    $maxIdx ?? -1
));

$existingIdx = array_values(array_filter(array_keys($wcd), function ($k) {
    return ctype_digit((string)$k);
}));
$maxIdx = $existingIdx ? max(array_map('intval', $existingIdx)) : 0;
$hasDevice = isset($wcd[(string)$connectionIndex]);
$hasConn = $hasDevice && isset($wcd[(string)$connectionIndex][$subKey]['1']);

// ONU yang menentukan nomor instance baru (selalu nomor berikutnya), jadi index harus pas
if (!$hasDevice && $connectionIndex !== $maxIdx + 1) {
    wanResponse(false, 'Index tidak valid. Untuk WAN baru gunakan index ' . ($maxIdx + 1) . '.');
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
$uintParams = ['X_HW_VLAN'];
$values = [];
foreach ($genieParams as $fullPath => $val) {
    $leaf = substr($fullPath, strrpos($fullPath, '.') + 1);
    if (in_array($leaf, $boolParams, true) || preg_match('/^(Lan|SSID)\d+Enable$/', $leaf)) {
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
$tasks[] = [
    'name' => 'refreshObject',
    'objectName' => 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice'
];

$lastIndex = count($tasks) - 1;
foreach ($tasks as $i => $task) {
    $res = $genieacs->queueTask($deviceId, $task, $i === $lastIndex);

    error_log(sprintf(
        '[add-wan] task %d (%s) -> http=%s %.2fs note=%s',
        $i,
        $task['name'],
        $res['http_code'] ?? '-',
        microtime(true) - $tStart,
        $res['note'] ?? ''
    ));
    if (!$res['success']) {
        $msg = $res['error'] ?? ('HTTP ' . ($res['http_code'] ?? '?'));
        wanResponse(false, 'Gagal mengantrikan task WAN: ' . $msg);
    }
}

wanResponse(true, 'Perintah WAN dikirim ke ONU. Data akan muncul setelah ONU memproses (sekitar 1 menit).', [
    'task_status' => 'queued',
    'connection_index' => $connectionIndex,
    'connection_type' => $connectionType,
    'tasks_queued' => count($tasks),
    'connection_path' => $basePath
]);