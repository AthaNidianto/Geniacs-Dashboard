<?php
/**
 * Update WiFi Configuration (SSID, Password, Security Mode)
 *
 * - Hanya menulis parameter yang BENAR-BENAR ada di data ONU (beda vendor beda path).
 * - Hanya menulis parameter yang nilainya berubah.
 * - Password boleh dikosongkan: artinya password lama dipertahankan.
 * - SSID, password, dan setelan keamanan dikirim sebagai task terpisah, jadi satu parameter
 *   yang ditolak ONU tidak membatalkan yang lain.
 * - Smart Queuing: semua task diantrekan, hanya task terakhir yang membangunkan ONU.
 */
require_once __DIR__ . '/../config/config.php';
requireLogin();

use App\GenieACS;

header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed']);
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['device_id'])) {
    jsonResponse(['success' => false, 'message' => 'Device ID is required']);
}
if (empty($input['wifi_ssid'])) {
    jsonResponse(['success' => false, 'message' => 'WiFi SSID is required']);
}

$deviceId = $input['device_id'];
// SSID/password tidak boleh lewat clean() (htmlspecialchars merusak karakter seperti & ' ")
$wifiSsid = trim((string)$input['wifi_ssid']);
$securityMode = isset($input['security_mode']) ? clean($input['security_mode']) : 'WPA2PSK';
$wifiPassword = isset($input['wifi_password']) ? (string)$input['wifi_password'] : '';
$wlanIndex = isset($input['wlan_index']) ? intval($input['wlan_index']) : 1;

if ($wlanIndex < 1 || $wlanIndex > 8) {
    jsonResponse(['success' => false, 'message' => 'WLAN index tidak valid']);
}
if (!in_array($securityMode, ['WPA2PSK', 'WPAPSK', 'WPA2PSKWPAPSK', 'None'], true)) {
    jsonResponse(['success' => false, 'message' => 'Security mode tidak valid']);
}
if (strlen($wifiSsid) < 1 || strlen($wifiSsid) > 32) {
    jsonResponse(['success' => false, 'message' => 'WiFi SSID must be between 1 and 32 characters']);
}
// Password opsional (kosong = tidak diubah), tapi kalau diisi harus 8-63 karakter
if ($securityMode !== 'None' && $wifiPassword !== '' && (strlen($wifiPassword) < 8 || strlen($wifiPassword) > 63)) {
    jsonResponse(['success' => false, 'message' => 'WiFi Password must be between 8 and 63 characters']);
}

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

    // Ambil data ONU untuk mengetahui path mana yang ada dan nilai saat ini
    $deviceResult = $genieacs->getDevice($deviceId);
    if (empty($deviceResult['success'])) {
        jsonResponse(['success' => false, 'message' => 'Device tidak ditemukan di GenieACS']);
    }
    $device = $deviceResult['data'];

    // Baca satu parameter dari dokumen device: ['exists' => bool, 'value' => mixed]
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
    $wlan = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$wlanIndex}";

    $taskSsid = [];
    $taskPassword = [];
    $taskSecurity = [];
    $skipped = [];

    // ---------- SSID ----------
    $ssidPath = $isTr181 ? "Device.WiFi.SSID.{$wlanIndex}.SSID" : "{$wlan}.SSID";
    $cur = $readParam($ssidPath);
    if (!$cur['exists'] || (string)$cur['value'] !== $wifiSsid) {
        $taskSsid[] = [$ssidPath, $wifiSsid, 'xsd:string'];
    }

    // ---------- Password ----------
    if ($securityMode !== 'None' && $wifiPassword !== '') {
        if ($isTr181) {
            $passCandidates = ["Device.WiFi.AccessPoint.{$wlanIndex}.Security.KeyPassphrase"];
        } else {
            $passCandidates = [
                "{$wlan}.PreSharedKey.1.KeyPassphrase",
                "{$wlan}.KeyPassphrase",
                "{$wlan}.PreSharedKey.1.PreSharedKey",
            ];
        }
        $passPath = null;
        foreach ($passCandidates as $cand) {
            if ($readParam($cand)['exists']) {
                $passPath = $cand;
                break;
            }
        }
        // Tidak ketemu di data ONU: pakai path standar TR-098
        if ($passPath === null) {
            $passPath = $passCandidates[0];
        }
        $taskPassword[] = [$passPath, $wifiPassword, 'xsd:string'];
    }

    // ---------- Security mode ----------
    if (!$isTr181) {
        $beaconMap = [
            'WPA2PSK' => '11i',
            'WPAPSK' => 'WPA',
            'WPA2PSKWPAPSK' => 'WPAand11i',
            'None' => 'Basic',
        ];
        $wanted = [];
        $wanted["{$wlan}.BeaconType"] = $beaconMap[$securityMode];

        if ($securityMode === 'WPA2PSK' || $securityMode === 'WPA2PSKWPAPSK') {
            $wanted["{$wlan}.IEEE11iAuthenticationMode"] = 'PSKAuthentication';
            $wanted["{$wlan}.IEEE11iEncryptionModes"] = ($securityMode === 'WPA2PSK') ? 'AESEncryption' : 'TKIPandAESEncryption';
        }
        if ($securityMode === 'WPAPSK' || $securityMode === 'WPA2PSKWPAPSK') {
            $wanted["{$wlan}.WPAAuthenticationMode"] = 'PSKAuthentication';
            $wanted["{$wlan}.WPAEncryptionModes"] = ($securityMode === 'WPAPSK') ? 'TKIPEncryption' : 'TKIPandAESEncryption';
        }

        foreach ($wanted as $path => $val) {
            $c = $readParam($path);
            if (!$c['exists']) {
                $skipped[] = $path; // parameter tidak ada di ONU ini
                continue;
            }
            if ((string)$c['value'] === (string)$val) {
                continue; // sudah sama
            }
            $taskSecurity[] = [$path, $val, 'xsd:string'];
        }
    }

    // ---------- Susun task ----------
    $tasks = [];
    if ($taskSsid) {
        $tasks[] = ['name' => 'setParameterValues', 'parameterValues' => $taskSsid];
    }
    if ($taskPassword) {
        $tasks[] = ['name' => 'setParameterValues', 'parameterValues' => $taskPassword];
    }
    if ($taskSecurity) {
        // satu per satu supaya yang tidak diterima ONU tidak membatalkan yang lain
        foreach ($taskSecurity as $p) {
            $tasks[] = ['name' => 'setParameterValues', 'parameterValues' => [$p]];
        }
    }

    if (empty($tasks)) {
        jsonResponse(['success' => true, 'message' => 'Tidak ada perubahan: SSID dan setelan sudah sama dengan di ONU.']);
    }

    $refreshPath = $isTr181 ? 'Device.WiFi' : "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$wlanIndex}";
    $tasks[] = ['name' => 'refreshObject', 'objectName' => $refreshPath];

    $lastIndex = count($tasks) - 1;
    foreach ($tasks as $i => $task) {
        $res = $genieacs->queueTask($deviceId, $task, $i === $lastIndex);

        $names = [];
        foreach (($task['parameterValues'] ?? []) as $pv) {
            $names[] = substr($pv[0], strrpos($pv[0], '.') + 1);
        }
        error_log(sprintf(
            '[update-wifi] wlan=%d task %d (%s %s) -> http=%s',
            $wlanIndex, $i, $task['name'], implode(',', $names), $res['http_code'] ?? '-'
        ));

        if (empty($res['success'])) {
            $msg = $res['error'] ?? ('HTTP ' . ($res['http_code'] ?? '?'));
            jsonResponse(['success' => false, 'message' => 'Gagal mengantrikan task WiFi: ' . $msg]);
        }
    }

    jsonResponse([
        'success' => true,
        'message' => 'Perintah WiFi dikirim ke ONU. Perubahan tampil setelah ONU memproses (sekitar 1 menit).',
        'data' => [
            'device_id' => $deviceId,
            'wifi_ssid' => $wifiSsid,
            'security_mode' => $securityMode,
            'wlan_index' => $wlanIndex,
            'password_changed' => !empty($taskPassword),
            'tasks_queued' => count($tasks),
            'skipped_params' => $skipped,
            'response_time' => 'queued'
        ]
    ]);

} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}