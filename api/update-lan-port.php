<?php
/**
 * Update port LAN (Ethernet) di ONU: enable/disable port, kecepatan, duplex.
 *
 * - Hanya mengirim parameter yang BERUBAH dan benar-benar ada di data ONU.
 * - Tiap parameter jadi task sendiri, jadi satu yang ditolak ONU tidak membatalkan yang lain.
 * - Smart Queuing: semua task diantrekan, hanya task terakhir yang membangunkan ONU.
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
$portIndex = isset($input['port_index']) ? intval($input['port_index']) : 0;

if ($deviceId === '') {
    jsonResponse(['success' => false, 'message' => 'Device ID required']);
}
if ($portIndex < 1 || $portIndex > 8) {
    jsonResponse(['success' => false, 'message' => 'Nomor port tidak valid']);
}

// Nilai yang diminta (null = tidak diubah)
$wantEnable = array_key_exists('enabled', $input) ? filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
$wantSpeed = isset($input['speed']) && $input['speed'] !== '' ? (string)$input['speed'] : null;
$wantDuplex = isset($input['duplex']) && $input['duplex'] !== '' ? (string)$input['duplex'] : null;

if ($wantSpeed !== null && !in_array($wantSpeed, ['Auto', '10', '100', '1000'], true)) {
    jsonResponse(['success' => false, 'message' => 'Kecepatan tidak valid']);
}
if ($wantDuplex !== null && !in_array($wantDuplex, ['Auto', 'Half', 'Full'], true)) {
    jsonResponse(['success' => false, 'message' => 'Duplex tidak valid']);
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
    $base = $isTr181
        ? "Device.Ethernet.Interface.{$portIndex}"
        : "InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.{$portIndex}";

    // Baca satu parameter dari dokumen device
    $readParam = function ($path) use ($device) {
        $node = $device;
        foreach (explode('.', $path) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return ['exists' => false, 'value' => null];
            }
            $node = $node[$part];
        }
        if (is_array($node) && array_key_exists('_value', $node)) {
            return ['exists' => true, 'value' => $node['_value']];
        }
        return ['exists' => false, 'value' => null];
    };

    // Port harus ada di ONU
    if (!$readParam("{$base}.Enable")['exists'] && !$readParam("{$base}.Status")['exists']) {
        jsonResponse(['success' => false, 'message' => "Port {$portIndex} tidak ditemukan di ONU ini"]);
    }

    $isTrue = function ($v) {
        return $v === true || $v === 1 || $v === '1' || (is_string($v) && strtolower($v) === 'true');
    };

    $params = [];
    $skipped = [];

    if ($wantEnable !== null) {
        $cur = $readParam("{$base}.Enable");
        if (!$cur['exists'] || $isTrue($cur['value']) !== $wantEnable) {
            $params[] = [$base . '.Enable', $wantEnable, 'xsd:boolean'];
        }
    }

    if ($wantSpeed !== null) {
        $speedPath = $isTr181 ? "{$base}.MaxBitRate" : "{$base}.MaxBitRate";
        $cur = $readParam($speedPath);
        if (!$cur['exists']) {
            $skipped[] = 'MaxBitRate';
        } elseif (strtolower((string)$cur['value']) !== strtolower($wantSpeed)) {
            $params[] = [$speedPath, $wantSpeed, 'xsd:string'];
        }
    }

    if ($wantDuplex !== null) {
        $cur = $readParam("{$base}.DuplexMode");
        if (!$cur['exists']) {
            $skipped[] = 'DuplexMode';
        } elseif (strtolower((string)$cur['value']) !== strtolower($wantDuplex)) {
            $params[] = [$base . '.DuplexMode', $wantDuplex, 'xsd:string'];
        }
    }

    if (empty($params)) {
        $why = $skipped ? ' (parameter ' . implode(', ', $skipped) . ' tidak didukung ONU ini)' : '';
        jsonResponse(['success' => true, 'message' => 'Tidak ada perubahan untuk disimpan' . $why]);
    }

    // Satu task per parameter + refresh port, wake hanya di task terakhir
    $tasks = [];
    foreach ($params as $p) {
        $tasks[] = ['name' => 'setParameterValues', 'parameterValues' => [$p]];
    }
    $tasks[] = ['name' => 'refreshObject', 'objectName' => $base];

    $lastIndex = count($tasks) - 1;
    foreach ($tasks as $i => $task) {
        $res = $genieacs->queueTask($deviceId, $task, $i === $lastIndex);

        $label = isset($task['parameterValues'])
            ? substr($task['parameterValues'][0][0], strrpos($task['parameterValues'][0][0], '.') + 1)
            : 'refresh';
        error_log(sprintf('[update-lan] port=%d task %d (%s %s) -> http=%s', $portIndex, $i, $task['name'], $label, $res['http_code'] ?? '-'));

        if (empty($res['success'])) {
            $msg = $res['error'] ?? ('HTTP ' . ($res['http_code'] ?? '?'));
            jsonResponse(['success' => false, 'message' => 'Gagal mengantrikan task port LAN: ' . $msg]);
        }
    }

    jsonResponse([
        'success' => true,
        'message' => "Perintah untuk Port {$portIndex} dikirim ke ONU. Status tampil setelah ONU memproses.",
        'data' => [
            'port_index' => $portIndex,
            'tasks_queued' => count($tasks),
            'skipped_params' => $skipped
        ]
    ]);
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}