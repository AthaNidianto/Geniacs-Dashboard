<?php
// ===== HEADER: samakan dengan file lama kamu =====
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/GenieACS.php';
use App\GenieACS;

requireLogin();
$genieacs = new GenieACS();   // <- pakai cara yang sama seperti di file lama (kalau ada argumen config, copy)
// =================================================

// jsonResponse() di helpers.php hanya menerima array, jadi pakai pembungkus ini
function wanResponse($success, $message, $extra = []) {
    jsonResponse(array_merge(['success' => $success, 'message' => $message], $extra));
}

// ---------- 1. Ambil input (JSON body atau form) ----------
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) { $input = $_POST; }

$deviceId        = trim($input['device_id'] ?? '');
$connectionIndex = (int)($input['connection_index'] ?? 0);
$connectionType  = $input['connection_type'] ?? '';
$parameters      = $input['parameters'] ?? [];

// ---------- 2. Validasi ----------
if ($deviceId === '') {
    wanResponse(false, 'device_id wajib diisi');
}
if ($connectionIndex < 1 || $connectionIndex > 8) {
    wanResponse(false, 'connection_index harus 1-8');
}
if (!in_array($connectionType, ['ppp', 'ip'], true)) {
    wanResponse(false, 'connection_type harus ppp atau ip');
}
if (!is_array($parameters)) {
    wanResponse(false, 'parameters tidak valid');
}

$allowed = ['Name', 'Enable', 'ConnectionType', 'Username', 'Password', 'NATEnabled',
            'X_CT-COM_ServiceList', 'X_CT-COM_LanInterface', 'X_CT-COM_VLANID'];
foreach (array_keys($parameters) as $k) {
    if (!in_array($k, $allowed, true)) {
        wanResponse(false, "Parameter tidak diizinkan: $k");
    }
}

// ---------- 3. Cek instance WANConnectionDevice yang sudah ada ----------
$wcdRoot = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice';
$devs = $genieacs->getDevices(['_id' => $deviceId], 1, 0, $wcdRoot);
$existing = [];
if (!empty($devs[0]['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'])) {
    foreach ($devs[0]['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'] as $k => $v) {
        if (ctype_digit((string)$k)) { $existing[] = (int)$k; }
    }
}
$maxIdx    = $existing ? max($existing) : 0;
$hasDevice = in_array($connectionIndex, $existing, true);

$connNode = $connectionType === 'ppp' ? 'WANPPPConnection' : 'WANIPConnection';
$hasConn  = $hasDevice && !empty($devs[0]['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'][(string)$connectionIndex][$connNode]['1']);

// ONU yang menentukan nomor instance baru = maxIdx+1, jadi index harus pas
if (!$hasDevice && $connectionIndex !== $maxIdx + 1) {
    wanResponse(false, "Index WAN berikutnya harus " . ($maxIdx + 1) . " (yang ada sekarang: " . ($maxIdx ?: 'kosong') . ")");
}
if ($hasConn) {
    wanResponse(false, "WAN index $connectionIndex sudah punya koneksi $connNode");
}

// ---------- 4. Terjemahkan parameter ZTE/CT-COM -> Huawei (X_HW_*) ----------
$hwExtra = [];   // [nama, nilai, tipe]
if (isset($parameters['X_CT-COM_VLANID']) && $parameters['X_CT-COM_VLANID'] !== '') {
    $hwExtra[] = ['X_HW_VLAN', (int)$parameters['X_CT-COM_VLANID'], 'xsd:unsignedInt'];
}
if (!empty($parameters['X_CT-COM_ServiceList'])) {
    $hwExtra[] = ['X_HW_SERVICELIST', (string)$parameters['X_CT-COM_ServiceList'], 'xsd:string'];
}
if (!empty($parameters['X_CT-COM_LanInterface'])) {
    foreach (preg_split('/[,;\s]+/', (string)$parameters['X_CT-COM_LanInterface']) as $iface) {
        if (preg_match('/LANEthernetInterfaceConfig\.(\d+)/', $iface, $m)) {
            $hwExtra[] = ["X_HW_LANBIND.Lan{$m[1]}Enable", true, 'xsd:boolean'];
        } elseif (preg_match('/WLANConfiguration\.(\d+)/', $iface, $m)) {
            $hwExtra[] = ["X_HW_LANBIND.SSID{$m[1]}Enable", true, 'xsd:boolean'];
        }
    }
}
unset($parameters['X_CT-COM_VLANID'], $parameters['X_CT-COM_ServiceList'], $parameters['X_CT-COM_LanInterface']);

// ---------- 5. Susun daftar task (urutan penting) ----------
$connectionPath = "$wcdRoot.$connectionIndex.$connNode.1";
$tasks = [];

if (!$hasDevice) {
    $tasks[] = ['name' => 'addObject', 'objectName' => $wcdRoot];
}
$tasks[] = ['name' => 'addObject', 'objectName' => "$wcdRoot.$connectionIndex.$connNode"];

$boolKeys = ['Enable', 'NATEnabled'];
$values = [];
foreach ($parameters as $k => $v) {
    if (in_array($k, $boolKeys, true)) {
        $values[] = ["$connectionPath.$k", filter_var($v, FILTER_VALIDATE_BOOLEAN), 'xsd:boolean'];
    } else {
        $values[] = ["$connectionPath.$k", (string)$v, 'xsd:string'];
    }
}
foreach ($hwExtra as [$name, $val, $type]) {
    $values[] = ["$connectionPath.$name", $val, $type];
}
if ($values) {
    $tasks[] = ['name' => 'setParameterValues', 'parameterValues' => $values];
}

// refresh di akhir supaya dashboard langsung dapat data WAN baru
$tasks[] = ['name' => 'refreshObject', 'objectName' => $wcdRoot];

// ---------- 6. Kirim (Smart Queuing: hanya task terakhir yang bangunkan ONU) ----------
$lastIndex = count($tasks) - 1;
$t0 = microtime(true);
foreach ($tasks as $i => $task) {
    $res = $genieacs->queueTask($deviceId, $task, $i === $lastIndex);
    if (empty($res['success'])) {
        error_log("[add-wan] gagal task #$i {$task['name']}: " . json_encode($res));
        wanResponse(false, "Gagal mengirim task {$task['name']} ke GenieACS", ['detail' => $res]);
    }
}
error_log(sprintf('[add-wan] %s index=%d type=%s tasks=%d %.2fs',
    $deviceId, $connectionIndex, $connectionType, count($tasks), microtime(true) - $t0));

wanResponse(true, 'Perintah WAN dikirim ke ONU. WAN akan muncul setelah ONU merespons (bisa 1-3 menit).', [
    'task_status'      => 'queued',
    'connection_index' => $connectionIndex,
    'connection_type'  => $connectionType,
    'tasks_queued'     => count($tasks),
    'connection_path'  => $connectionPath,
]);