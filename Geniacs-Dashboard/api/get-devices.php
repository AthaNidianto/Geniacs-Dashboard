<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

// Set time limit for this script to handle large datasets
set_time_limit(300); // Increased to 5 minutes for large datasets
ini_set('max_execution_time', 300);

if (!isGenieACSConfigured()) {
    jsonResponse(['success' => false, 'message' => 'GenieACS belum dikonfigurasi']);
}

$conn = getDBConnection();
$result = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$credentials = $result->fetch_assoc();

if (!$credentials) {
    jsonResponse(['success' => false, 'message' => 'GenieACS tidak terhubung']);
}

use App\GenieACS;
use App\GenieACS_Fast;

$genieacs = new GenieACS(
    $credentials['host'],
    $credentials['port'],
    $credentials['username'],
    $credentials['password']
);

// Get pagination parameters (optional)
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100; // Default limit to 100 for performance
$skip = isset($_GET['skip']) ? (int)$_GET['skip'] : 0;

// Check if client wants all devices (chunked parameter)
$chunked = isset($_GET['chunked']) && $_GET['chunked'] === 'true';

// Parser selection: 'fast' (default for performance) or 'full' (for complete data)
$parser = isset($_GET['parser']) ? $_GET['parser'] : 'fast';
$useFastParser = ($parser === 'fast');

try {
        // Hanya field yang dibaca GenieACS_Fast::parseDeviceDataFast (jauh lebih ringan dari data lengkap)
    $projection = [
        '_id', '_deviceId', '_lastInform', '_tags',
        // TR-098
        'InternetGatewayDevice.DeviceInfo.SerialNumber',
        'InternetGatewayDevice.DeviceInfo.HardwareVersion',
        'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
        'InternetGatewayDevice.DeviceInfo.UpTime',
        'InternetGatewayDevice.DeviceInfo.Temperature',
        'InternetGatewayDevice.ManagementServer.ConnectionRequestURL',
        'InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1.MACAddress',
        'InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.3.SSID',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.4.SSID',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BSSID',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.KeyPassphrase',
        'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.MACAddress',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.Username',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.3.WANPPPConnection.1.Username',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.4.WANPPPConnection.1.Username',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.5.WANPPPConnection.1.Username',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.6.WANPPPConnection.1.Username',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.7.WANPPPConnection.1.Username',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.8.WANPPPConnection.1.Username',
        // TR-181
        'Device.ManagementServer.ConnectionRequestURL',
        'Device.DeviceInfo.UpTime',
        'Device.WiFi.SSID.1.SSID',
        'Device.Optical.Interface.1.RxPower',
        'Device.Hosts.HostNumberOfEntries',
        // Virtual parameters
        'VirtualParameters.RXPower',
        'VirtualParameters.gettemp',
        'VirtualParameters.Temperature',
    ];

    $devicesResult = $genieacs->getDevices([], $limit, $skip, $projection);

    if ($devicesResult['success']) {
        $devices = [];

        // Use selected parser
        foreach ($devicesResult['data'] as $device) {
            if ($useFastParser) {
                // Fast parser - optimized for performance (10x faster)
                $parsed = GenieACS_Fast::parseDeviceDataFast($device);
            } else {
                // Full parser - complete data extraction
                $parsed = $genieacs->parseDeviceData($device);
            }
            $devices[] = $parsed;
        }

        $response = [
            'success' => true,
            'devices' => $devices,
            'count' => count($devices),
            'total' => count($devices),
            'hasMore' => count($devices) === $limit,
            'pagination' => [
                'limit' => $limit,
                'skip' => $skip,
                'returned' => count($devices),
                'nextSkip' => $skip + $limit
            ]
        ];

        jsonResponse($response);
    } else {
        jsonResponse([
            'success' => false,
            'message' => 'Gagal mengambil data devices',
            'error' => $devicesResult['error'] ?? 'Unknown error'
        ]);
    }
} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ], 500);
}
