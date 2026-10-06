<?php

/**
 * Add New WAN Connection Configuration (vendor-aware: Huawei HG8145V5 & ZTE F663NV3A)
 *
 * Huawei : setiap WAN punya slot WANConnectionDevice sendiri (slot baru = max+1, instance 1)
 *          VLAN  -> X_HW_VLAN, Service -> X_HW_SERVICELIST, Bind -> X_HW_LANBIND.*
 * ZTE    : semua WAN ada di slot 1, WAN baru = instance berikutnya di slot itu
 *          VLAN  -> X_CT-COM_VLANID, Service -> X_CT-COM_ServiceList, Bind -> X_CT-COM_LanInterface
 *
 * VLAN dan ServiceList opsional: kalau kosong tidak dikirim ke ONU.
 * Smart Queuing: semua task diantrekan tanpa connection_request, hanya task terakhir yang membangunkan ONU.
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
if (!isset($input['device_id']) || !isset($input['connection_type']) || !isset($input['parameters'])) {
    wanResponse(false, 'Missing required fields: device_id, connection_type, parameters');
}

$deviceId = $input['device_id'];
$requestedSlot = intval($input['connection_index'] ?? 0);
$connectionType = strtolower($input['connection_type']); // "ppp" or "ip"
$connectionName = $input['name'] ?? '';
$parameters = $input['parameters'];

// Validate connection type
if (!in_array($connectionType, ['ppp', 'ip'])) {
    wanResponse(false, 'Invalid connection type. Must be "ppp" or "ip".');
}

// Allowed parameters (nama logis dari frontend)
$allowedKeys = [
    'Name', 'Enable', 'ConnectionType', 'Username', 'Password', 'NATEnabled',
    'X_CT-COM_ServiceList', 'X_CT-COM_LanInterface', 'X_CT-COM_VLANID',
];
foreach ($parameters as $key => $value) {
    if (!in_array($key, $allowedKeys, true)) {
        wanResponse(false, "Invalid parameter: {$key}");
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

// Baca WAN yang sudah ada di ONU (dari data yang tersimpan di GenieACS)
$wcdRoot = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice';
$subKey = ($connectionType === 'ppp') ? 'WANPPPConnection' : 'WANIPConnection';
$devRes = $genieacs->getDevices(['_id' => $deviceId], 1, 0, $wcdRoot);
$wcd = $devRes['data'][0]['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'] ?? [];

// Deteksi vendor: Huawei punya parameter X_HW_*, atau ID perangkat HG8*/HUAWEI
$isHuawei = (strpos(json_encode($wcd), 'X_HW_') !== false)
    || (bool)preg_match('/HG8|HUAWEI/i', rawurldecode($deviceId));

// Slot (WANConnectionDevice) yang ada
$slots = array_values(array_filter(array_keys($wcd), function ($k) {
    return ctype_digit((string)$k);
}));
$slots = array_map('intval', $slots);
sort($slots);

$needNewSlot = false;
if ($isHuawei) {
    // Huawei: satu WAN per slot. Pakai slot yang diminta kalau belum punya koneksi tipe ini,
    // selain itu buat slot baru (max + 1).
    $hasConnInRequested = $requestedSlot > 0
        && isset($wcd[(string)$requestedSlot][$subKey])
        && is_array($wcd[(string)$requestedSlot][$subKey])
        && count(array_filter(array_keys($wcd[(string)$requestedSlot][$subKey]), 'ctype_digit')) > 0;

    if ($requestedSlot > 0 && in_array($requestedSlot, $slots, true) && !$hasConnInRequested) {
        $slot = $requestedSlot;
    } else {
        $slot = ($slots ? max($slots) : 0) + 1;
        $needNewSlot = true;
    }
    $instance = 1;
} else {
    // ZTE / lainnya: WAN baru = instance berikutnya di slot yang sudah ada
    $slot = $slots ? min($slots) : 1;
    if (!$slots) {
        $needNewSlot = true;
    }
    $existingInst = [];
    if (isset($wcd[(string)$slot][$subKey]) && is_array($wcd[(string)$slot][$subKey])) {
        $existingInst = array_map('intval', array_filter(array_keys($wcd[(string)$slot][$subKey]), 'ctype_digit'));
    }
    $instance = $existingInst ? max($existingInst) + 1 : 1;
}

$basePath = "{$wcdRoot}.{$slot}.{$subKey}.{$instance}";

// Susun parameter dengan nama sesuai vendor
$genieParams = [];

if (!empty($connectionName)) {
    $genieParams[$basePath . '.Name'] = $connectionName;
}

foreach ($parameters as $key => $value) {
    switch ($key) {
        case 'X_CT-COM_VLANID':
            // opsional
            if ($value === '' || $value === null || !is_numeric($value) || intval($value) < 1) {
                break;
            }
            $genieParams[$basePath . ($isHuawei ? '.X_HW_VLAN' : '.X_CT-COM_VLANID')] = intval($value);
            break;

        case 'X_CT-COM_ServiceList':
            // opsional
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

        case 'Name':
            // nama diatur lewat field "name" di atas
            if (empty($connectionName) && $value !== '') {
                $genieParams[$basePath . '.Name'] = (string)$value;
            }
            break;

        default:
            $genieParams[$basePath . '.' . $key] = $value;
    }
}

// Susun daftar task
$tasks = [];

if ($needNewSlot) {
    $tasks[] = ['name' => 'addObject', 'objectName' => $wcdRoot];
    // Slot baru dibuat ONU = nomor berikutnya
    if ($isHuawei || !$slots) {
        $newSlot = ($slots ? max($slots) : 0) + 1;
        if ($newSlot !== $slot) {
            wanResponse(false, 'Slot tidak konsisten, coba muat ulang halaman lalu ulangi.');
        }
    }
}
$tasks[] = ['name' => 'addObject', 'objectName' => "{$wcdRoot}.{$slot}.{$subKey}"];

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
$tasks[] = ['name' => 'setParameterValues', 'parameterValues' => $values];

// Refresh supaya dashboard langsung dapat data terbaru
$tasks[] = ['name' => 'refreshObject', 'objectName' => $wcdRoot];

// Smart Queuing: hanya task terakhir yang membangunkan ONU
$lastIndex = count($tasks) - 1;
foreach ($tasks as $i => $task) {
    $res = $genieacs->queueTask($deviceId, $task, $i === $lastIndex);

    error_log(sprintf(
        '[add-wan] %s slot=%d inst=%d task %d (%s) -> http=%s %.2fs',
        $isHuawei ? 'huawei' : 'zte/other',
        $slot,
        $instance,
        $i,
        $task['name'],
        $res['http_code'] ?? '-',
        microtime(true) - $tStart
    ));

    if (!$res['success']) {
        $msg = $res['error'] ?? ('HTTP ' . ($res['http_code'] ?? '?'));
        wanResponse(false, 'Gagal mengantrikan task WAN: ' . $msg);
    }
}

wanResponse(true, 'Perintah WAN dikirim ke ONU. Data akan muncul setelah ONU memproses (sekitar 1 menit).', [
    'task_status' => 'queued',
    'vendor' => $isHuawei ? 'huawei' : 'zte',
    'connection_index' => $slot,
    'connection_instance' => $instance,
    'connection_type' => $connectionType,
    'tasks_queued' => count($tasks),
    'connection_path' => $basePath
]);