<?php
/**
 * Aktifkan / nonaktifkan satu jaringan WiFi (SSID) di ONU, misalnya mematikan 5GHz saja.
 *
 * - Menulis parameter Enable milik WLAN itu saja:
 *     TR-098: InternetGatewayDevice.LANDevice.1.WLANConfiguration.{i}.Enable
 *     TR-181: Device.WiFi.SSID.{i}.Enable
 * - Hanya jalan kalau parameter itu memang ada di data ONU (beda vendor beda path).
 * - Kalau nilainya sudah sama, tidak ada task yang dikirim.
 * - Setelah set, refreshObject supaya status terbaru terbaca; hanya task terakhir yang
 *   membangunkan ONU (smart queuing, sama seperti update-wifi-config.php).
 */
require_once __DIR__ . '/../config/config.php';
requireLogin();

use App\GenieACS;

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed']);
}

$input = json_decode(file_get_contents('php://input'), true);
$deviceId = trim((string)($input['device_id'] ?? ''));
$wlanIndex = isset($input['wlan_index']) ? intval($input['wlan_index']) : 0;

if ($deviceId === '') {
    jsonResponse(['success' => false, 'message' => 'Device ID is required']);
}
if ($wlanIndex < 1 || $wlanIndex > 8) {
    jsonResponse(['success' => false, 'message' => 'WLAN index tidak valid']);
}
if (!array_key_exists('enable', (array)$input) || !is_bool($input['enable'])) {
    jsonResponse(['success' => false, 'message' => 'Parameter enable harus true atau false']);
}
$enable = $input['enable'];

try {
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT host, port, username, password FROM genieacs_credentials LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        jsonResponse(['success' => false, 'message' => 'GenieACS not configured. Please configure it first.']);
    }
    $config = $result->fetch_assoc();
    $stmt->close();

    $genieacs = new GenieACS($config['host'], $config['port'], $config['username'], $config['password']);

    $deviceResult = $genieacs->getDevice($deviceId);
    if (empty($deviceResult['success'])) {
        jsonResponse(['success' => false, 'message' => 'Device tidak ditemukan di GenieACS']);
    }
    $device = $deviceResult['data'];

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

    $isTr181 = !isset($device['InternetGatewayDevice']) && isset($device['Device']);
    $enablePath = $isTr181
        ? "Device.WiFi.SSID.{$wlanIndex}.Enable"
        : "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$wlanIndex}.Enable";

    $cur = $readParam($enablePath);
    if (!$cur['exists']) {
        jsonResponse(['success' => false, 'message' => "ONU ini tidak punya parameter Enable untuk WLAN {$wlanIndex}, jadi tidak bisa diubah dari sini."]);
    }

    $curBool = ($cur['value'] === true || $cur['value'] === 1 || $cur['value'] === '1'
        || (is_string($cur['value']) && strtolower($cur['value']) === 'true'));
    if ($curBool === $enable) {
        jsonResponse(['success' => true, 'message' => 'Tidak ada perubahan: WLAN ' . $wlanIndex . ' sudah ' . ($enable ? 'aktif' : 'nonaktif') . '.']);
    }

    $tasks = [
        ['name' => 'setParameterValues', 'parameterValues' => [[$enablePath, $enable, 'xsd:boolean']]],
        ['name' => 'refreshObject', 'objectName' => $isTr181 ? 'Device.WiFi' : "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$wlanIndex}"],
    ];

    $lastIndex = count($tasks) - 1;
    foreach ($tasks as $i => $task) {
        $res = $genieacs->queueTask($deviceId, $task, $i === $lastIndex);
        error_log(sprintf('[toggle-wifi] wlan=%d enable=%s task %d (%s) -> http=%s',
            $wlanIndex, $enable ? 'true' : 'false', $i, $task['name'], $res['http_code'] ?? '-'));
        if (empty($res['success'])) {
            $msg = $res['error'] ?? ('HTTP ' . ($res['http_code'] ?? '?'));
            jsonResponse(['success' => false, 'message' => 'Gagal mengantrikan task WiFi: ' . $msg]);
        }
    }

    jsonResponse([
        'success' => true,
        'message' => 'Perintah ' . ($enable ? 'aktifkan' : 'nonaktifkan') . " WLAN {$wlanIndex} dikirim ke ONU. Statusnya berubah setelah ONU memproses (sekitar 1 menit).",
        'data' => ['device_id' => $deviceId, 'wlan_index' => $wlanIndex, 'enable' => $enable, 'path' => $enablePath]
    ]);

} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}