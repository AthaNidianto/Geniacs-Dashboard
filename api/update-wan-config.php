<?php
/**
 * Update WAN Connection Configuration (vendor-aware: Huawei HG8145V5 & ZTE F663NV3A)
 *
 * Input (POST JSON):
 * {
 *     "device_id": "...",
 *     "connection_index": 1,        // slot WANConnectionDevice
 *     "connection_instance": 2,     // nomor WANPPPConnection / WANIPConnection (default 1)
 *     "connection_type": "ppp",     // "ppp" atau "ip"
 *     "parameters": {
 *         "Enable": true,
 *         "Username": "user@isp",
 *         "Password": "password",
 *         "NATEnabled": true,
 *         "X_CT-COM_VLANID": 30     // opsional
 *     }
 * }
 *
 * Huawei : X_CT-COM_VLANID -> X_HW_VLAN, X_CT-COM_ServiceList -> X_HW_SERVICELIST
 * ZTE    : X_CT-COM_* dipakai apa adanya
 * Smart Queuing: task diantrekan tanpa connection_request, hanya task terakhir yang membangunkan ONU.
 */

require_once __DIR__ . '/../config/config.php';
use App\GenieACS;

header('Content-Type: application/json');

// Require login
requireLogin();

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
$connectionInstance = max(1, intval($input['connection_instance'] ?? 1));
$connectionType = strtolower($input['connection_type']); // "ppp" or "ip"
$parameters = $input['parameters'];

if ($connectionIndex < 1 || $connectionIndex > 8) {
    wanResponse(false, 'Invalid connection index. Must be between 1 and 8.');
}
if ($connectionInstance > 8) {
    wanResponse(false, 'Invalid connection instance. Must be between 1 and 8.');
}
if (!in_array($connectionType, ['ppp', 'ip'])) {
    wanResponse(false, 'Invalid connection type. Must be "ppp" or "ip".');
}

$allowedKeys = [
    'Enable', 'ConnectionType', 'Username', 'Password', 'NATEnabled',
    'X_CT-COM_ServiceList', 'X_CT-COM_LanInterface', 'X_CT-COM_VLANID',
];
foreach ($parameters as $key => $value) {
    if (!in_array($key, $allowedKeys, true)) {
        wanResponse(false, "Invalid parameter: {$key}");
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

// Path WAN yang diedit
$wcdRoot = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice';
$subKey = ($connectionType === 'ppp') ? 'WANPPPConnection' : 'WANIPConnection';
$basePath = "{$wcdRoot}.{$connectionIndex}.{$subKey}.{$connectionInstance}";

// Deteksi vendor
$devRes = $genieacs->getDevices(['_id' => $deviceId], 1, 0, $wcdRoot);
$wcd = $devRes['data'][0]['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'] ?? [];
$isHuawei = (strpos(json_encode($wcd), 'X_HW_') !== false)
    || (bool)preg_match('/HG8|HUAWEI/i', rawurldecode($deviceId));

// Susun parameter sesuai vendor
$genieParams = [];
foreach ($parameters as $key => $value) {
    switch ($key) {
        case 'X_CT-COM_VLANID':
            if ($value === '' || $value === null || !is_numeric($value) || intval($value) < 1) {
                break; // VLAN opsional
            }
            $genieParams[$basePath . ($isHuawei ? '.X_HW_VLAN' : '.X_CT-COM_VLANID')] = intval($value);
            break;

        case 'X_CT-COM_ServiceList':
            if ($value === '' || $value === null) {
                break;
            }
            $genieParams[$basePath . ($isHuawei ? '.X_HW_SERVICELIST' : '.X_CT-COM_ServiceList')] = (string)$value;
            break;

        case 'X_CT-COM_LanInterface':
            if ($value === '' || $value === null) {
                break;
            }
            if ($isHuawei) {
                foreach (preg_split('/[,;\s]+/', (string)$value) as $iface) {
                    if (preg_match('/LANEthernetInterfaceConfig\.(\d+)/', $iface, $m)) {
                        $genieParams[$basePath . ".X_HW_LANBIND.Lan{$m[1]}Enable"] = true;
                    } elseif (preg_match('/WLANConfiguration\.(\d+)/', $iface, $m)) {
                        $genieParams[$basePath . ".X_HW_LANBIND.SSID{$m[1]}Enable"] = true;
                    }
                }
            } else {
                $genieParams[$basePath . '.X_CT-COM_LanInterface'] = (string)$value;
            }
            break;

        default:
            $genieParams[$basePath . '.' . $key] = $value;
    }
}

if (empty($genieParams)) {
    wanResponse(false, 'No valid parameters provided for update');
}

// Format yang benar: [[path, value, type], ...]
$boolLeaf = ['Enable', 'NATEnabled'];
$uintLeaf = ['X_HW_VLAN', 'X_CT-COM_VLANID'];
$values = [];
foreach ($genieParams as $fullPath => $val) {
    $leaf = substr($fullPath, strrpos($fullPath, '.') + 1);
    if (in_array($leaf, $boolLeaf, true) || preg_match('/^(Lan|SSID)\d+Enable$/', $leaf)) {
        $type = 'xsd:boolean';
        $val = filter_var($val, FILTER_VALIDATE_BOOLEAN);
    } elseif (in_array($leaf, $uintLeaf, true)) {
        $type = 'xsd:unsignedInt';
        $val = intval($val);
    } else {
        $type = 'xsd:string';
        $val = (string)$val;
    }
    $values[] = [$fullPath, $val, $type];
}

$tasks = [
    ['name' => 'setParameterValues', 'parameterValues' => $values],
    ['name' => 'refreshObject', 'objectName' => $wcdRoot],
];

$lastIndex = count($tasks) - 1;
foreach ($tasks as $i => $task) {
    $res = $genieacs->queueTask($deviceId, $task, $i === $lastIndex);

    error_log(sprintf(
        '[update-wan] %s %s task %d (%s) -> http=%s',
        $isHuawei ? 'huawei' : 'zte/other',
        $basePath,
        $i,
        $task['name'],
        $res['http_code'] ?? '-'
    ));

    if (empty($res['success'])) {
        $msg = $res['error'] ?? ('HTTP ' . ($res['http_code'] ?? '?'));
        wanResponse(false, 'Gagal mengantrikan task update WAN: ' . $msg);
    }
}

wanResponse(true, 'Perintah update WAN dikirim ke ONU. Perubahan tampil setelah ONU memproses (sekitar 1 menit).', [
    'task_status' => 'queued',
    'vendor' => $isHuawei ? 'huawei' : 'zte',
    'parameters_updated' => count($genieParams),
    'connection_path' => $basePath
]);