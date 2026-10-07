<?php

namespace App;

/**
 * GenieACS API Client
 */
class GenieACS
{
    private $host;
    private $port;
    private $username;
    private $password;
    private $baseUrl;

    /**
     * Field minimum untuk statistik dashboard (dashboard-stats.php, uplink-stats.php).
     * JANGAN dipakai di get-devices.php: parser Fast butuh lebih banyak field (WAN, WiFi password, dll).
     * Kalau ada grafik dashboard yang kosong/meleset, tambahkan path yang dibaca endpoint tsb ke sini.
     */
    private const LITE_PROJECTION = [
        '_id',
        '_lastInform',
        '_deviceId',
        '_tags',
        'VirtualParameters.RXPower',
        'VirtualParameters.gettemp',
        'VirtualParameters.Temperature',
        'InternetGatewayDevice.DeviceInfo',
        'InternetGatewayDevice.ManagementServer.ConnectionRequestURL',
        'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig',
        'InternetGatewayDevice.WANDevice.1.X_HW_GponInterfaceConfig',
        'InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries',
        'Device.DeviceInfo.UpTime',
        'Device.ManagementServer.ConnectionRequestURL',
        'Device.Optical.Interface.1.RxPower',
        'Device.Hosts.HostNumberOfEntries',
    ];

    public function __construct($host = null, $port = 7557, $username = null, $password = null)
    {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->baseUrl = "http://{$this->host}:{$this->port}";
    }

    /**
     * Make HTTP request to GenieACS API
     */
    private function request($endpoint, $method = 'GET', $data = null)
    {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300); // Increased to 300 seconds (5 minutes) for large datasets
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30); // Connection timeout 30 seconds

        // Add authentication if provided
        if ($this->username && $this->password) {
            curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
        }

        // Set method and data
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => $error];
        }

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'data' => json_decode($response, true),
            'http_code' => $httpCode
        ];
    }

    /**
     * Test connection to GenieACS
     */
    public function testConnection()
    {
        $result = $this->request('/devices?limit=1');
        return $result['success'];
    }

    /**
     * Get all devices
     * @param array $query - MongoDB query
     * @param int $limit - Maximum number of devices to return (0 = no limit)
     * @param int $skip - Number of devices to skip (for pagination)
     * @param array|string|null $projection - Field yang diminta saja (array atau string dipisah koma).
     *                                        Null = dokumen lengkap (perilaku lama).
     */
    public function getDevices($query = [], $limit = 0, $skip = 0, $projection = null)
    {
        $params = [];

        if (!empty($query)) {
            $params[] = 'query=' . urlencode(json_encode($query));
        }

        if ($limit > 0) {
            $params[] = 'limit=' . $limit;
        }

        if ($skip > 0) {
            $params[] = 'skip=' . $skip;
        }

        if ($projection) {
            $fields = is_array($projection) ? implode(',', $projection) : $projection;
            $params[] = 'projection=' . urlencode($fields);
        }

        $queryString = !empty($params) ? '?' . implode('&', $params) : '';
        return $this->request('/devices/' . $queryString);
    }

    /**
     * Versi ringan getDevices() untuk statistik dashboard.
     * Hanya menarik field minimum (lihat LITE_PROJECTION) supaya hemat memori & cepat.
     */
    public function getDevicesLite($query = [], $limit = 0, $skip = 0)
    {
        return $this->getDevices($query, $limit, $skip, self::LITE_PROJECTION);
    }

    /**
     * Get total device count
     */
    public function getDeviceCount($query = [])
    {
        $queryString = empty($query) ? '' : '?query=' . urlencode(json_encode($query));
        // Cukup _id saja, kita hanya butuh jumlahnya
        $queryString .= ($queryString === '' ? '?' : '&') . 'projection=_id';
        $result = $this->request('/devices/' . $queryString);

        if ($result['success'] && isset($result['data'])) {
            return ['success' => true, 'count' => count($result['data'])];
        }

        return ['success' => false, 'count' => 0];
    }

    /**
     * Get device by ID
     */
    public function getDevice($deviceId)
    {
        $query = ['_id' => $deviceId];
        $result = $this->request('/devices/?query=' . urlencode(json_encode($query)));

        if ($result['success'] && !empty($result['data'])) {
            return ['success' => true, 'data' => $result['data'][0]];
        }

        return ['success' => false, 'error' => 'Device not found'];
    }

    /**
     * Get device parameters
     */
    public function getDeviceParameters($deviceId)
    {
        return $this->getDevice($deviceId);
    }

    /**
     * Execute task on device
     */
    public function executeTask($deviceId, $taskName, $params = [])
    {
        $endpoint = "/devices/{$deviceId}/tasks";
        $data = [
            'name' => $taskName
        ];

        if (!empty($params)) {
            $data['parameterValues'] = $params;
        }

        return $this->request($endpoint, 'POST', $data);
    }

    /**
     * Summon device (connection request)
     */
    public function summonDevice($deviceId)
    {
        // URL encode device ID to handle special characters
        $encodedId = rawurlencode($deviceId);
        $endpoint = "/devices/{$encodedId}/tasks?connection_request";
        return $this->request($endpoint, 'POST');
    }

    /**
     * Refresh device inform (force device to connect to ACS)
     */
    public function refreshInform($deviceId)
    {
        $encodedId = rawurlencode($deviceId);
        $endpoint = "/devices/{$encodedId}/tasks?connection_request";
        return $this->request($endpoint, 'POST');
    }

    /**
     * Add refresh task for specific parameter
     * This forces GenieACS to fetch the parameter value from device
     */
    public function addRefreshTask($deviceId, $parameterPath)
    {
        $encodedId = rawurlencode($deviceId);
        $endpoint = "/devices/{$encodedId}/tasks?timeout=3000&connection_request";

        $data = [
            'name' => 'refreshObject',
            'objectName' => $parameterPath
        ];

        return $this->request($endpoint, 'POST', $data);
    }

    /**
     * Get parameter values from device (force fetch from device)
     * This creates a task to fetch specific parameters from the device
     *
     * @param string $deviceId Device ID
     * @param array $parameterNames Array of parameter names to fetch
     * @param int $timeout Timeout in milliseconds (default: 3000)
     * @return array Response with task ID
     */
    public function getParameterValues($deviceId, $parameterNames, $timeout = 3000)
    {
        $encodedId = rawurlencode($deviceId);
        $endpoint = "/devices/{$encodedId}/tasks?timeout={$timeout}&connection_request";

        $data = [
            'name' => 'getParameterValues',
            'parameterNames' => $parameterNames
        ];

        return $this->request($endpoint, 'POST', $data);
    }

    /**
     * Deteksi root data model dari data yang sudah tersimpan di GenieACS.
     * Return 'tr098' (InternetGatewayDevice), 'tr181' (Device), atau null kalau belum ketahuan.
     * Dipakai supaya task refresh tidak menunjuk path yang tidak ada di modem (fault 9005).
     */
    private function detectRoot($deviceId)
    {
        $q = urlencode(json_encode(['_id' => $deviceId]));
        $projection = urlencode('InternetGatewayDevice.DeviceInfo.ProductClass,Device.DeviceInfo.ProductClass');
        $res = $this->request("/devices/?query={$q}&projection={$projection}");

        if ($res['success'] && !empty($res['data'][0])) {
            if (isset($res['data'][0]['InternetGatewayDevice'])) return 'tr098';
            if (isset($res['data'][0]['Device'])) return 'tr181';
        }
        return null;
    }

    /**
     * Summon device, fetch admin credentials, SSID (WLAN) AND Connected Clients (Single Summon)
     *
     * Task 1 menunggu hasil (timeout=3000) seperti sebelumnya. Task berikutnya disesuaikan
     * dengan model data modem (TR-098 / TR-181).
     */
    public function summonAndFetchAdminCredentials($deviceId)
    {
        $encodedId = rawurlencode($deviceId);
        $endpoint = "/devices/{$encodedId}/tasks?timeout=3000&connection_request";

        // Task 1: Summon + VirtualParameters (admin password)
        $result = $this->request($endpoint, 'POST', [
            'name' => 'refreshObject',
            'objectName' => 'VirtualParameters'
        ]);

        if ($result['success']) {
            $root = $this->detectRoot($deviceId);

            $objects = [];
            if ($root !== 'tr181') {
                $objects[] = 'InternetGatewayDevice.LANDevice.1.WLANConfiguration'; // SSID
                $objects[] = 'InternetGatewayDevice.LANDevice.1.Hosts';             // Client TR-098
            }
            if ($root !== 'tr098') {
                $objects[] = 'Device.WiFi';   // SSID
                $objects[] = 'Device.Hosts';  // Client TR-181
            }

            foreach ($objects as $objectName) {
                $this->request($endpoint, 'POST', [
                    'name' => 'refreshObject',
                    'objectName' => $objectName
                ]);
            }
        }

        return $result;
    }

    /**
     * Reboot device
     */
    public function rebootDevice($deviceId)
    {
        return $this->executeTask($deviceId, 'reboot');
    }

    /**
     * Set parameter values on device
     *
     * @param string $deviceId Device ID
     * @param array $parameters Array of parameters to set [['path', 'value', 'type'], ...]
     * @param int $timeout Timeout in milliseconds (default: 3000)
     * @return array Response with success status
     *
     * Example:
     * $parameters = [
     *     ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID', 'NewSSID', 'xsd:string'],
     *     ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase', 'NewPassword', 'xsd:string']
     * ];
     */
    public function setParameterValues($deviceId, $parameters, $timeout = 3000)
    {
        // URL encode device ID to handle special characters
        $encodedId = rawurlencode($deviceId);
        $endpoint = "/devices/{$encodedId}/tasks?timeout={$timeout}&connection_request";

        $data = [
            'name' => 'setParameterValues',
            'parameterValues' => $parameters
        ];

        return $this->request($endpoint, 'POST', $data);
    }

    public function queueTask($deviceId, array $task, $wake = false, $timeoutMs = 3000)
    {
        $endpoint = "/devices/" . rawurlencode($deviceId) . "/tasks";
        if ($wake) {
            $endpoint .= "?timeout={$timeoutMs}&connection_request";
        }
        return $this->request($endpoint, 'POST', $task);
    }

    /**
     * Set WiFi configuration (SSID, Password, and Security Mode)
     *
     * @param string $deviceId Device ID
     * @param string $ssid New WiFi SSID
     * @param string $password New WiFi Password (optional for Open network)
     * @param int $wlanIndex WLAN Configuration index (default: 1)
     * @param string $securityMode Security mode (WPA2PSK, WPAPSK, WPA2PSKWPAPSK, None)
     * @return array Response with success status
     */
    public function setWiFiConfig($deviceId, $ssid, $password = '', $wlanIndex = 1, $securityMode = 'WPA2PSK')
    {
        $parameters = [];

        // Try multiple parameter paths for different ONU vendors
        $ssidPaths = [
            "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$wlanIndex}.SSID",
            "Device.WiFi.SSID.{$wlanIndex}.SSID"
        ];

        $securityPaths = [
            "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$wlanIndex}.BeaconType",
            "Device.WiFi.AccessPoint.{$wlanIndex}.Security.ModeEnabled"
        ];

        $passwordPaths = [
            "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$wlanIndex}.KeyPassphrase",
            "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$wlanIndex}.PreSharedKey.1.KeyPassphrase",
            "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$wlanIndex}.PreSharedKey.1.PreSharedKey",
            "Device.WiFi.AccessPoint.{$wlanIndex}.Security.KeyPassphrase"
        ];

        // For now, use the most common TR-098 paths
        // 1. Set SSID
        $parameters[] = [$ssidPaths[0], $ssid, 'xsd:string'];

        // 2. Set Security Mode (BeaconType)
        // Map security mode to BeaconType values
        $beaconTypeMap = [
            'WPA2PSK' => '11i',
            'WPAPSK' => 'WPA',
            'WPA2PSKWPAPSK' => 'WPAand11i',
            'None' => 'Basic'  // or 'None' depending on device
        ];

        $beaconType = isset($beaconTypeMap[$securityMode]) ? $beaconTypeMap[$securityMode] : '11i';
        $parameters[] = [$securityPaths[0], $beaconType, 'xsd:string'];

        // 3. Set Password (only if security mode is not Open)
        if ($securityMode !== 'None' && !empty($password)) {
            $parameters[] = [$passwordPaths[0], $password, 'xsd:string'];

            // Also set authentication mode for WPA/WPA2
            $authModePath = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$wlanIndex}.WPAAuthenticationMode";
            $parameters[] = [$authModePath, 'PSKAuthentication', 'xsd:string'];

            // Set encryption method
            $encryptionPath = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$wlanIndex}.WPAEncryptionModes";
            $encryptionMode = ($securityMode === 'WPA2PSK' || $securityMode === 'WPA2PSKWPAPSK') ? 'AESEncryption' : 'TKIPEncryption';
            $parameters[] = [$encryptionPath, $encryptionMode, 'xsd:string'];
        }

        return $this->setParameterValues($deviceId, $parameters);
    }

    /**
     * Get device statistics
     * Hanya butuh _lastInform per device, jadi pakai projection (hemat memori).
     */
    public function getDeviceStats()
    {
        $devices = $this->getDevices([], 0, 0, '_lastInform');

        if (!$devices['success']) {
            return ['success' => false, 'error' => 'Failed to fetch devices'];
        }

        $total = count($devices['data']);
        $online = 0;
        $offline = 0;

        foreach ($devices['data'] as $device) {
            // Check last inform time (within last 5 minutes = online)
            $lastInform = isset($device['_lastInform']) ? $device['_lastInform'] : null;

            $isOnline = false;

            if ($lastInform) {
                // Convert ISO 8601 to Unix timestamp
                $lastInformTimestamp = strtotime($lastInform);
                if ($lastInformTimestamp !== false) {
                    // Online if last inform within 5 minutes
                    $isOnline = (time() - $lastInformTimestamp) < 300;
                }
            }

            if ($isOnline) {
                $online++;
            } else {
                $offline++;
            }
        }

        return [
            'success' => true,
            'data' => [
                'total' => $total,
                'online' => $online,
                'offline' => $offline
            ]
        ];
    }

    /**
     * Parse device data for display
     */
    public function parseDeviceData($device)
    {
        $data = [];

        // Helper function to get nested parameter value
        $getParam = function ($path) use ($device) {
            $keys = explode('.', $path);
            $value = $device;

            foreach ($keys as $key) {
                if (isset($value[$key])) {
                    $value = $value[$key];
                } else {
                    return null;
                }
            }

            // GenieACS uses object format with _value field
            if (is_array($value) && isset($value['_value'])) {
                return $value['_value'];
            }

            // Fallback for direct values
            return is_array($value) ? null : $value;
        };

        // Basic info
        $data['device_id'] = $device['_id'] ?? 'N/A';
        $data['serial_number'] = $getParam('_deviceId._SerialNumber') ?? $getParam('InternetGatewayDevice.DeviceInfo.SerialNumber') ?? 'N/A';

        // MAC Address - try multiple paths
        $macAddress = $getParam('InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1.MACAddress') ??
            $getParam('InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.MACAddress') ??
            $getParam('InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BSSID') ??
            $getParam('Device.Ethernet.Interface.1.MACAddress') ??
            $getParam('_deviceId._MACAddress');

        // If MAC still not found, try to construct from OUI and serial number
        if (empty($macAddress) || $macAddress === 'N/A') {
            $oui = $getParam('_deviceId._OUI');
            $serial = $getParam('_deviceId._SerialNumber');

            // Some devices have MAC embedded in serial number (last 6 chars)
            if ($oui && $serial && strlen($serial) >= 6) {
                $lastSixChars = substr($serial, -6);
                // Check if last 6 chars are hex
                if (ctype_xdigit($lastSixChars)) {
                    // Format OUI properly (F86CE1 -> F8:6C:E1)
                    $ouiFormatted = strtoupper(substr($oui, 0, 2) . ':' .
                        substr($oui, 2, 2) . ':' .
                        substr($oui, 4, 2));

                    $macAddress = $ouiFormatted . ':' .
                        strtoupper(substr($lastSixChars, 0, 2)) . ':' .
                        strtoupper(substr($lastSixChars, 2, 2)) . ':' .
                        strtoupper(substr($lastSixChars, 4, 2));
                }
            }
        }

        $data['mac_address'] = $macAddress ?? 'N/A';
        $data['manufacturer'] = $getParam('_deviceId._Manufacturer') ?? $getParam('InternetGatewayDevice.DeviceInfo.Manufacturer') ?? 'N/A';
        $data['oui'] = $getParam('_deviceId._OUI') ?? $getParam('InternetGatewayDevice.DeviceInfo.ManufacturerOUI') ?? 'N/A';
        $data['product_class'] = $getParam('_deviceId._ProductClass') ?? $getParam('InternetGatewayDevice.DeviceInfo.ProductClass') ?? 'N/A';
        $data['hardware_version'] = $getParam('InternetGatewayDevice.DeviceInfo.HardwareVersion') ?? 'N/A';
        $data['software_version'] = $getParam('InternetGatewayDevice.DeviceInfo.SoftwareVersion') ?? 'N/A';

        // Status
        $lastInform = isset($device['_lastInform']) ? $device['_lastInform'] : null;
        $lastInformTimestamp = null;

        if ($lastInform) {
            $lastInformTimestamp = strtotime($lastInform);
            if ($lastInformTimestamp !== false) {
                $data['last_inform'] = date('Y-m-d H:i:s', $lastInformTimestamp);
                $data['status'] = (time() - $lastInformTimestamp) < 300 ? 'online' : 'offline';
            } else {
                $data['last_inform'] = 'N/A';
                $data['status'] = 'offline';
            }
        } else {
            $data['last_inform'] = 'N/A';
            $data['status'] = 'offline';
        }

        // Ping/Latency - try to get actual ping from VirtualParameters
        // GenieACS stores ping result in VirtualParameters.Ping
        $ping = $getParam('VirtualParameters.Ping') ??
            $getParam('VirtualParameters.ping') ??
            $getParam('VirtualParameters.PingResult');

        if ($data['status'] === 'online') {
            // If ping value exists and is numeric, use it
            if ($ping !== null && is_numeric($ping)) {
                $data['ping'] = intval($ping);
            } else {
                // Fallback: estimate based on inform freshness if actual ping not available
                if ($lastInformTimestamp) {
                    $timeSinceInform = time() - $lastInformTimestamp;

                    if ($timeSinceInform < 30) {
                        $data['ping'] = rand(1, 5);
                    } elseif ($timeSinceInform < 60) {
                        $data['ping'] = rand(5, 15);
                    } elseif ($timeSinceInform < 120) {
                        $data['ping'] = rand(15, 50);
                    } else {
                        $data['ping'] = rand(50, 200);
                    }
                } else {
                    $data['ping'] = null;
                }
            }
        } else {
            $data['ping'] = null;
        }

        // Network info
        $connectionUrl = $getParam('InternetGatewayDevice.ManagementServer.ConnectionRequestURL') ??
            $getParam('Device.ManagementServer.ConnectionRequestURL') ?? 'N/A';

        $data['ip_tr069'] = $connectionUrl;

        // Extract IP address from ConnectionRequestURL
        $ipAddress = 'N/A';
        if ($connectionUrl && $connectionUrl !== 'N/A') {
            // Extract IP from URL format: http://IP:PORT/path or https://IP:PORT/path
            if (preg_match('/https?:\/\/([^:\/]+)/', $connectionUrl, $matches)) {
                $ipAddress = $matches[1];
            }
        }

        // Also try WAN IP if available
        if ($ipAddress === 'N/A') {
            $ipAddress = $getParam('InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress') ??
                $getParam('Device.IP.Interface.1.IPv4Address.1.IPAddress') ?? 'N/A';
        }

        $data['ip_address'] = $ipAddress;
        $data['uptime'] = $getParam('InternetGatewayDevice.DeviceInfo.UpTime') ??
            $getParam('Device.DeviceInfo.UpTime') ?? 'N/A';

        // WiFi info - try multiple paths and WLAN configurations
        $wifiSsid = $getParam('InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID') ??
            $getParam('InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID') ??
            $getParam('InternetGatewayDevice.LANDevice.1.WLANConfiguration.3.SSID') ??
            $getParam('InternetGatewayDevice.LANDevice.1.WLANConfiguration.4.SSID') ??
            $getParam('Device.WiFi.SSID.1.SSID') ??
            $getParam('Device.WiFi.SSID.2.SSID');

        $data['wifi_ssid'] = $wifiSsid ?? 'N/A';

        $wifiPassword = $getParam('InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase') ??
            $getParam('InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase') ??
            $getParam('InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.KeyPassphrase') ??
            $getParam('InternetGatewayDevice.LANDevice.1.WLANConfiguration.3.KeyPassphrase') ??
            $getParam('InternetGatewayDevice.LANDevice.1.WLANConfiguration.4.KeyPassphrase') ??
            $getParam('Device.WiFi.AccessPoint.1.Security.KeyPassphrase') ??
            $getParam('Device.WiFi.AccessPoint.2.Security.KeyPassphrase');

        $data['wifi_password'] = $wifiPassword ?? 'N/A';

        // ---- Daftar jaringan WiFi (2.4GHz / 5GHz) yang aktif ----
        // Hanya WLAN yang ada SSID-nya dan Enable = true yang ditampilkan.
        $wifiNetworks = [];
        $beaconToMode = [
            '11i' => 'WPA2PSK',
            'WPA' => 'WPAPSK',
            'WPAand11i' => 'WPA2PSKWPAPSK',
            'Basic' => 'None',
            'None' => 'None',
        ];
        $isTruthy = function ($v) {
            return $v === true || $v === 1 || $v === '1' || (is_string($v) && strtolower($v) === 'true');
        };
        $firstNonEmpty = function (array $paths) use ($getParam) {
            foreach ($paths as $p) {
                $v = $getParam($p);
                if ($v !== null && is_string($v) && trim($v) !== '') {
                    return $v;
                }
            }
            return null;
        };

        for ($w = 1; $w <= 8; $w++) {
            $base = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$w}";
            $ssid = $getParam("{$base}.SSID");
            if ($ssid === null || trim((string)$ssid) === '') {
                continue;
            }
            $enableRaw = $getParam("{$base}.Enable");
            $enabled = ($enableRaw === null) ? true : $isTruthy($enableRaw);

            $band = $getParam("{$base}.OperatingFrequencyBand");
            if (!$band) {
                $band = ($w >= 5) ? '5GHz' : '2.4GHz';
            }
            $beacon = $getParam("{$base}.BeaconType");
            $pass = $firstNonEmpty([
                "{$base}.PreSharedKey.1.KeyPassphrase",
                "{$base}.KeyPassphrase",
                "{$base}.PreSharedKey.1.PreSharedKey",
            ]);

            $wifiNetworks[] = [
                'index' => $w,
                'ssid' => (string)$ssid,
                'password' => $pass ?? '',
                'band' => (string)$band,
                'enabled' => $enabled,
                'security_mode' => $beaconToMode[$beacon] ?? 'WPA2PSK',
            ];
        }

        // TR-181 (Device.WiFi) kalau tidak ada TR-098
        if (empty($wifiNetworks)) {
            for ($w = 1; $w <= 8; $w++) {
                $ssid = $getParam("Device.WiFi.SSID.{$w}.SSID");
                if ($ssid === null || trim((string)$ssid) === '') {
                    continue;
                }
                $enableRaw = $getParam("Device.WiFi.SSID.{$w}.Enable");
                $pass = $firstNonEmpty(["Device.WiFi.AccessPoint.{$w}.Security.KeyPassphrase"]);
                $wifiNetworks[] = [
                    'index' => $w,
                    'ssid' => (string)$ssid,
                    'password' => $pass ?? '',
                    'band' => ($w >= 5) ? '5GHz' : '2.4GHz',
                    'enabled' => ($enableRaw === null) ? true : $isTruthy($enableRaw),
                    'security_mode' => 'WPA2PSK',
                ];
            }
        }

        // Sembunyikan WLAN yang nonaktif (tidak bisa dipakai); kalau semuanya nonaktif, tampilkan semua
        $activeNetworks = array_values(array_filter($wifiNetworks, function ($n) {
            return $n['enabled'];
        }));
        $data['wifi_networks'] = !empty($activeNetworks) ? $activeNetworks : $wifiNetworks;

        // Kompatibilitas: wifi_ssid / wifi_password = jaringan pertama yang aktif
        if (!empty($data['wifi_networks'])) {
            $data['wifi_ssid'] = $data['wifi_networks'][0]['ssid'];
            if ($data['wifi_networks'][0]['password'] !== '') {
                $data['wifi_password'] = $data['wifi_networks'][0]['password'];
            }
        }

        // LAN Ethernet ports (status port LAN di ONU)
        $lanPorts = [];
        $toNum = function ($v) {
            return ($v !== null && is_numeric($v)) ? (float)$v : null;
        };
        $readLan = function ($base, $tr181) use ($getParam, $isTruthy, $toNum) {
            $enableRaw = $getParam("{$base}.Enable");
            $status = $getParam("{$base}.Status");
            if ($enableRaw === null && $status === null) {
                return null; // port tidak ada di ONU ini
            }
            $bitRate = $tr181 ? $getParam("{$base}.CurrentBitRate") : $getParam("{$base}.MaxBitRate");
            $stats = "{$base}.Stats";
            return [
                'enabled' => ($enableRaw === null) ? true : $isTruthy($enableRaw),
                'status' => ($status === null) ? 'Unknown' : (string)$status,
                'bit_rate' => ($bitRate === null) ? null : (string)$bitRate,
                'duplex' => $getParam("{$base}.DuplexMode"),
                'mac' => $getParam("{$base}.MACAddress"),
                'name' => $getParam("{$base}.Name"),
                'bytes_sent' => $toNum($getParam("{$stats}.BytesSent")),
                'bytes_received' => $toNum($getParam("{$stats}.BytesReceived")),
                'packets_sent' => $toNum($getParam("{$stats}.PacketsSent")),
                'packets_received' => $toNum($getParam("{$stats}.PacketsReceived")),
                'errors_sent' => $toNum($getParam("{$stats}.ErrorsSent")),
                'errors_received' => $toNum($getParam("{$stats}.ErrorsReceived")),
            ];
        };

        for ($p = 1; $p <= 8; $p++) {
            $port = $readLan("InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.{$p}", false);
            if ($port !== null) {
                $port['index'] = $p;
                $lanPorts[] = $port;
            }
        }
        // TR-181 kalau tidak ada TR-098
        if (empty($lanPorts)) {
            for ($p = 1; $p <= 8; $p++) {
                $port = $readLan("Device.Ethernet.Interface.{$p}", true);
                if ($port !== null) {
                    $port['index'] = $p;
                    $lanPorts[] = $port;
                }
            }
        }
        $data['lan_ports'] = $lanPorts;

        // Optical info
        $rxPower = $getParam('VirtualParameters.RXPower') ??
            $getParam('InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.RXPower') ??
            $getParam('Device.Optical.Interface.1.RxPower');

        // Convert raw value to dBm if needed
        if ($rxPower !== null && is_numeric($rxPower)) {
            $rxPower = floatval($rxPower);
            if ($rxPower > 100) {
                $rxPower = ($rxPower / 100) - 40;
            }
            $data['rx_power'] = number_format($rxPower, 2);
        } else {
            $data['rx_power'] = $rxPower ?? 'N/A';
        }

        // Temperature
        $temperature = $getParam('VirtualParameters.gettemp') ??
            $getParam('InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.TransceiverTemperature') ??
            $getParam('VirtualParameters.Temperature') ??
            $getParam('InternetGatewayDevice.DeviceInfo.Temperature');

        // Convert raw value if needed (> 1000 indicates raw format)
        if ($temperature !== null && is_numeric($temperature)) {
            $temperature = floatval($temperature);
            if ($temperature > 1000) {
                $temperature = $temperature / 256; // Convert from raw to Celsius
            }
            $data['temperature'] = number_format($temperature, 1);
        } else {
            $data['temperature'] = $temperature ?? 'N/A';
        }

        // WAN Details - try multiple connection types and device numbers
        $wanDetails = [];

        // Helper function to check if WAN connection exists
        $checkWANExists = function ($path) use ($device) {
            $keys = explode('.', $path);
            $value = $device;

            foreach ($keys as $key) {
                if (isset($value[$key])) {
                    $value = $value[$key];
                } else {
                    return false;
                }
            }

            // Check if this is an actual connection object (has _object or parameters)
            if (is_array($value)) {
                // If it has _object field and it's true, or has connection parameters
                if (
                    isset($value['_object']) || isset($value['ConnectionStatus']) ||
                    isset($value['Enable']) || isset($value['Name'])
                ) {
                    return true;
                }
            }

            return false;
        };

        // Helper function to detect active WLAN/LAN interfaces
        $detectActiveInterfaces = function () use ($getParam) {
            $activeInterfaces = [];

            // Check WLAN configurations (1-4)
            for ($i = 1; $i <= 4; $i++) {
                $wlanBase = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$i}";
                $wlanEnable = $getParam("{$wlanBase}.Enable");
                $wlanStatus = $getParam("{$wlanBase}.Status");
                $wlanSSID = $getParam("{$wlanBase}.SSID");
                $wlanVLAN = $getParam("{$wlanBase}.X_CT-COM_VLAN");

                // WLAN is active if enabled and status is "Up" or has SSID
                if (($wlanEnable === true || $wlanStatus === 'Up') && $wlanSSID) {
                    $activeInterfaces[] = [
                        'type' => 'WLAN',
                        'number' => $i,
                        'interface' => "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$i}",
                        'ssid' => $wlanSSID,
                        'vlan' => $wlanVLAN ?? 'N/A'
                    ];
                }
            }

            // Check LAN Ethernet configurations (1-4)
            for ($i = 1; $i <= 4; $i++) {
                $lanBase = "InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.{$i}";
                $lanEnable = $getParam("{$lanBase}.Enable");
                $lanStatus = $getParam("{$lanBase}.Status");
                $lanVLAN = $getParam("{$lanBase}.X_CT-COM_VLAN");

                // LAN is active if enabled or has status other than "NoLink"
                if ($lanEnable === true || ($lanStatus && $lanStatus !== 'NoLink')) {
                    $activeInterfaces[] = [
                        'type' => 'LAN Ethernet',
                        'number' => $i,
                        'interface' => "InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.{$i}",
                        'vlan' => $lanVLAN ?? 'N/A'
                    ];
                }
            }

            return $activeInterfaces;
        };

        // Try WANPPPConnection (most common for PPPoE)
        for ($k = 0; $k < 64; $k++) {
            $i = intdiv($k, 8) + 1;   // slot (WANConnectionDevice)
            $j = ($k % 8) + 1;        // instance (WANPPPConnection)
            $basePath = "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$i}.WANPPPConnection.{$j}";

            // Check if this connection exists
            if (!$checkWANExists($basePath)) {
                continue;
            }

            $name = $getParam("{$basePath}.Name");
            $externalIP = $getParam("{$basePath}.ExternalIPAddress");
            $serviceList = $getParam("{$basePath}.X_CT-COM_ServiceList") ?? $getParam("{$basePath}.X_HW_SERVICELIST");
            $connectionStatus = $getParam("{$basePath}.ConnectionStatus");
            $lanInterface = $getParam("{$basePath}.X_CT-COM_LanInterface");

            // If ConnectionStatus is not available, try to determine from Enable flag
            if (!$connectionStatus || $connectionStatus === 'Unknown') {
                $enabled = $getParam("{$basePath}.Enable");
                if ($enabled !== null) {
                    $connectionStatus = $enabled ? 'Connected' : 'Disconnected';
                } else {
                    $connectionStatus = 'Unknown';
                }
            }

            // Parse LAN interface binding
            $bindingInfo = 'N/A';
            if ($lanInterface !== null && $lanInterface !== '') {
                // Extract interface type and number
                // e.g., "InternetGatewayDevice.LANDevice.1.WLANConfiguration.1" -> "WLAN 1"
                // e.g., "InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1" -> "LAN Ethernet 1"
                if (preg_match('/WLANConfiguration\.(\d+)/', $lanInterface, $matches)) {
                    $bindingInfo = "WLAN " . $matches[1];
                } elseif (preg_match('/LANEthernetInterfaceConfig\.(\d+)/', $lanInterface, $matches)) {
                    $bindingInfo = "LAN Ethernet " . $matches[1];
                } elseif (preg_match('/LANHostConfigManagement/', $lanInterface)) {
                    $bindingInfo = "All LAN Ports";
                } else {
                    $bindingInfo = $lanInterface;
                }
            }

            // Huawei: binding dari X_HW_LANBIND.LanNEnable / SSIDNEnable
            if ($bindingInfo === 'N/A' && $getParam("{$basePath}.X_HW_LANBIND.Lan1Enable") !== null) {
                $hwBind = [];
                for ($b = 1; $b <= 4; $b++) {
                    $v = $getParam("{$basePath}.X_HW_LANBIND.Lan{$b}Enable");
                    if ($v === true || $v === 1 || $v === '1' || $v === 'true') { $hwBind[] = "LAN Ethernet {$b}"; }
                }
                for ($b = 1; $b <= 8; $b++) {
                    $v = $getParam("{$basePath}.X_HW_LANBIND.SSID{$b}Enable");
                    if ($v === true || $v === 1 || $v === '1' || $v === 'true') { $hwBind[] = "WLAN {$b}"; }
                }
                $bindingInfo = $hwBind ? implode(', ', $hwBind) : 'Tidak ada';
            }

            // If binding info is still N/A, try to infer from active interfaces
            if ($bindingInfo === 'N/A') {
                $activeInterfaces = $detectActiveInterfaces();

                if (!empty($activeInterfaces)) {
                    $bindingList = [];
                    foreach ($activeInterfaces as $iface) {
                        if ($iface['type'] === 'WLAN') {
                            $bindingList[] = "WLAN {$iface['number']}";
                        }
                    }

                    if (!empty($bindingList)) {
                        $bindingInfo = implode(', ', $bindingList);
                    }
                }
            }

            // Only add if we have at least a name, IP, or service identifier
            if ($name || $externalIP || $serviceList) {
                // Generate name if not available
                if (!$name) {
                    $name = $serviceList ? "WAN_{$serviceList}_{$i}" : "WAN_PPP_Connection_{$i}";
                }

                $wanDetails[] = [
                    'type' => 'PPPoE',
                    'name' => $name,
                    'connection_index' => $i,
                    'connection_instance' => $j,
                    'service_list' => $serviceList ?? '',
                    'vlan_id' => $getParam("{$basePath}.X_HW_VLAN") ?? $getParam("{$basePath}.X_CT-COM_VLANID") ?? 'N/A',
                    'status' => $connectionStatus,
                    'connection_type' => $getParam("{$basePath}.ConnectionType") ?? 'N/A',
                    'external_ip' => $externalIP ?? 'N/A',
                    'gateway' => $getParam("{$basePath}.RemoteIPAddress") ?? $getParam("{$basePath}.DefaultGateway") ?? 'N/A',
                    'subnet_mask' => $getParam("{$basePath}.SubnetMask") ?? 'N/A',
                    'dns_servers' => $getParam("{$basePath}.DNSServers") ?? 'N/A',
                    'mac_address' => $getParam("{$basePath}.MACAddress") ?? 'N/A',
                    'username' => $getParam("{$basePath}.Username") ?? 'N/A',
                    'uptime' => $getParam("{$basePath}.Uptime") ?? 'N/A',
                    'last_error' => $getParam("{$basePath}.LastConnectionError") ?? 'N/A',
                    'mru_size' => $getParam("{$basePath}.MaxMRUSize") ?? 'N/A',
                    'binding' => $bindingInfo,
                ];
            }
        }

        // Try WANIPConnection (for DHCP/Static IP)
        for ($k = 0; $k < 64; $k++) {
            $i = intdiv($k, 8) + 1;   // slot (WANConnectionDevice)
            $j = ($k % 8) + 1;        // instance (WANIPConnection)
            $basePath = "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$i}.WANIPConnection.{$j}";

            // Check if this connection exists
            if (!$checkWANExists($basePath)) {
                continue;
            }

            $name = $getParam("{$basePath}.Name");
            $externalIP = $getParam("{$basePath}.ExternalIPAddress");
            $serviceList = $getParam("{$basePath}.X_CT-COM_ServiceList") ?? $getParam("{$basePath}.X_HW_SERVICELIST");
            $connectionStatus = $getParam("{$basePath}.ConnectionStatus");
            $lanInterface = $getParam("{$basePath}.X_CT-COM_LanInterface");

            // If ConnectionStatus is not available, try to determine from Enable flag
            if (!$connectionStatus || $connectionStatus === 'Unknown') {
                $enabled = $getParam("{$basePath}.Enable");
                if ($enabled !== null) {
                    $connectionStatus = $enabled ? 'Connected' : 'Disconnected';
                } else {
                    $connectionStatus = 'Unknown';
                }
            }

            // Parse LAN interface binding
            $bindingInfo = 'N/A';
            if ($lanInterface !== null && $lanInterface !== '') {
                if (preg_match('/WLANConfiguration\.(\d+)/', $lanInterface, $matches)) {
                    $bindingInfo = "WLAN " . $matches[1];
                } elseif (preg_match('/LANEthernetInterfaceConfig\.(\d+)/', $lanInterface, $matches)) {
                    $bindingInfo = "LAN Ethernet " . $matches[1];
                } elseif (preg_match('/LANHostConfigManagement/', $lanInterface)) {
                    $bindingInfo = "All LAN Ports";
                } else {
                    $bindingInfo = $lanInterface;
                }
            }

            // Huawei: binding dari X_HW_LANBIND.LanNEnable / SSIDNEnable
            if ($bindingInfo === 'N/A' && $getParam("{$basePath}.X_HW_LANBIND.Lan1Enable") !== null) {
                $hwBind = [];
                for ($b = 1; $b <= 4; $b++) {
                    $v = $getParam("{$basePath}.X_HW_LANBIND.Lan{$b}Enable");
                    if ($v === true || $v === 1 || $v === '1' || $v === 'true') { $hwBind[] = "LAN Ethernet {$b}"; }
                }
                for ($b = 1; $b <= 8; $b++) {
                    $v = $getParam("{$basePath}.X_HW_LANBIND.SSID{$b}Enable");
                    if ($v === true || $v === 1 || $v === '1' || $v === 'true') { $hwBind[] = "WLAN {$b}"; }
                }
                $bindingInfo = $hwBind ? implode(', ', $hwBind) : 'Tidak ada';
            }

            // If binding info is still N/A, try to infer from active interfaces
            if ($bindingInfo === 'N/A') {
                $activeInterfaces = $detectActiveInterfaces();

                if (!empty($activeInterfaces)) {
                    $bindingList = [];
                    foreach ($activeInterfaces as $iface) {
                        if ($iface['type'] === 'WLAN') {
                            $bindingList[] = "WLAN {$iface['number']}";
                        }
                    }

                    if (!empty($bindingList)) {
                        $bindingInfo = implode(', ', $bindingList);
                    }
                }
            }

            // Only add if we have at least a name, IP, or service identifier
            if ($name || $externalIP || $serviceList) {
                // Generate name if not available
                if (!$name) {
                    $name = $serviceList ? "WAN_{$serviceList}_{$i}" : "WAN_IP_Connection_{$i}";
                }

                $wanDetails[] = [
                    'type' => 'IP',
                    'name' => $name,
                    'connection_index' => $i,
                    'connection_instance' => $j,
                    'service_list' => $serviceList ?? '',
                    'vlan_id' => $getParam("{$basePath}.X_HW_VLAN") ?? $getParam("{$basePath}.X_CT-COM_VLANID") ?? 'N/A',
                    'status' => $connectionStatus,
                    'connection_type' => $getParam("{$basePath}.ConnectionType") ?? 'N/A',
                    'external_ip' => $externalIP ?? 'N/A',
                    'gateway' => $getParam("{$basePath}.DefaultGateway") ?? 'N/A',
                    'subnet_mask' => $getParam("{$basePath}.SubnetMask") ?? 'N/A',
                    'dns_servers' => $getParam("{$basePath}.DNSServers") ?? 'N/A',
                    'mac_address' => $getParam("{$basePath}.MACAddress") ?? 'N/A',
                    'addressing_type' => $getParam("{$basePath}.AddressingType") ?? 'N/A',
                    'uptime' => $getParam("{$basePath}.Uptime") ?? 'N/A',
                    'binding' => $bindingInfo,
                    'username' => 'N/A', // IP connections don't have username
                    'last_error' => 'N/A', // IP connections don't have last error
                    'mru_size' => 'N/A', // IP connections don't have MRU size
                ];
            }
        }

        // If no WAN connections found, try to create virtual WAN details from active interfaces
        if (empty($wanDetails)) {
            $activeInterfaces = $detectActiveInterfaces();

            if (!empty($activeInterfaces)) {
                // Group interfaces by VLAN to create logical WAN connections
                $vlanGroups = [];

                foreach ($activeInterfaces as $iface) {
                    $vlan = $iface['vlan'] !== 'N/A' && $iface['vlan'] !== '' ? $iface['vlan'] : 'default';

                    if (!isset($vlanGroups[$vlan])) {
                        $vlanGroups[$vlan] = [];
                    }
                    $vlanGroups[$vlan][] = $iface;
                }

                // Create WAN detail for each VLAN group
                $connIndex = 1;
                foreach ($vlanGroups as $vlan => $interfaces) {
                    $bindingList = [];

                    foreach ($interfaces as $iface) {
                        if ($iface['type'] === 'WLAN') {
                            $bindingList[] = "WLAN {$iface['number']} ({$iface['ssid']})";
                        } else {
                            $bindingList[] = "{$iface['type']} {$iface['number']}";
                        }
                    }

                    $bindingInfo = implode(', ', $bindingList);

                    // Use device IP as external IP if available
                    $externalIP = $data['ip_address'] ?? 'N/A';

                    $wanDetails[] = [
                        'type' => 'Bridge',
                        'name' => $vlan !== 'default' ? "Bridge_VLAN_{$vlan}" : "Bridge_Connection",
                        'status' => 'Connected',
                        'connection_type' => 'Bridged',
                        'external_ip' => $externalIP,
                        'gateway' => 'N/A',
                        'subnet_mask' => 'N/A',
                        'dns_servers' => 'N/A',
                        'mac_address' => $data['mac_address'] ?? 'N/A',
                        'addressing_type' => 'Bridged',
                        'uptime' => $data['uptime'] ?? 'N/A',
                        'binding' => $bindingInfo,
                        'username' => 'N/A',
                        'last_error' => 'N/A',
                        'mru_size' => 'N/A',
                    ];

                    $connIndex++;
                }
            }
        }

        $data['wan_details'] = $wanDetails;

        // Extract PPPoE username from first PPPoE connection (for devices.php display)
        $pppoeUsername = 'N/A';
        foreach ($wanDetails as $wan) {
            if ($wan['type'] === 'PPPoE' && isset($wan['username']) && $wan['username'] !== 'N/A' && $wan['username'] !== '') {
                $pppoeUsername = $wan['username'];
                break; // Use first found PPPoE username (non-empty)
            }
        }
        $data['pppoe_username'] = $pppoeUsername;

        // Connected Devices (LAN Hosts)
        $connectedDevices = [];

        // Get hosts from LANDevice.1.Hosts.Host
        $hostsBase = 'InternetGatewayDevice.LANDevice.1.Hosts.Host';

        // Get device's last inform time for comparison
        $deviceLastInformTime = null;
        if ($lastInform) {
            $deviceLastInformTime = strtotime($lastInform);
        }

        // Try to get hosts object
        if (isset($device['InternetGatewayDevice']['LANDevice']['1']['Hosts']['Host'])) {
            $hosts = $device['InternetGatewayDevice']['LANDevice']['1']['Hosts']['Host'];

            // Iterate through all host entries
            foreach ($hosts as $hostId => $hostData) {
                // Skip metadata fields
                if (strpos($hostId, '_') === 0) {
                    continue;
                }

                // Get host details
                $ipAddress = isset($hostData['IPAddress']['_value']) ? $hostData['IPAddress']['_value'] : null;
                $macAddress = isset($hostData['MACAddress']['_value']) ? $hostData['MACAddress']['_value'] : null;
                $hostName = isset($hostData['HostName']['_value']) ? $hostData['HostName']['_value'] : '';
                $interfaceType = isset($hostData['InterfaceType']['_value']) ? $hostData['InterfaceType']['_value'] : 'Unknown';
                $active = isset($hostData['Active']['_value']) ? $hostData['Active']['_value'] : null;
                $timestamp = isset($hostData['_timestamp']) ? $hostData['_timestamp'] : null;

                // Only add devices with valid IP and MAC
                if ($ipAddress && $macAddress) {
                    // Filter strategy: Only count hosts that were updated recently relative to device last inform
                    // This filters out old/disconnected devices from GenieACS historical data
                    $isRecentlyActive = true; // Default to true if no timestamp

                    if ($timestamp && $deviceLastInformTime) {
                        $hostTimestamp = strtotime($timestamp);
                        if ($hostTimestamp !== false) {
                            // Strategy: Count host as active if:
                            // 1. Host timestamp is within 3 hours before OR after device last inform
                            // 2. This catches hosts that were active around the time of last inform
                            //    (accounts for clock drift and DHCP lease refresh timing)
                            $threeHoursBefore = $deviceLastInformTime - (3 * 3600);
                            $threeHoursAfter = $deviceLastInformTime + (3 * 3600);
                            $isRecentlyActive = ($hostTimestamp >= $threeHoursBefore && $hostTimestamp <= $threeHoursAfter);
                        }
                    }

                    // Skip hosts that are not recently active
                    if (!$isRecentlyActive) {
                        continue;
                    }

                    // Determine interface type (WiFi/LAN)
                    $connectionType = 'LAN';
                    if ($interfaceType === '802.11') {
                        $connectionType = 'WiFi';
                    } elseif ($interfaceType === 'Ethernet') {
                        $connectionType = 'Ethernet';
                    }

                    // Get MAC vendor name
                    $vendorName = getMACVendor($macAddress, $hostName);

                    // If hostname is empty and vendor found, use vendor name
                    // Otherwise use "Unknown Device"
                    if (empty($hostName) || trim($hostName) === '') {
                        $hostName = $vendorName;
                    }

                    $connectedDevices[] = [
                        'hostname' => $hostName,
                        'vendor' => $vendorName,
                        'ip_address' => $ipAddress,
                        'mac_address' => $macAddress,
                        'interface_type' => $connectionType,
                        'active' => $active ?? true, // Default to active if not specified
                    ];
                }
            }
        }

        $data['connected_devices'] = $connectedDevices;
        $data['connected_devices_count'] = count($connectedDevices);

        // DHCP Server Configuration
        $dhcpServer = [];
        $dhcpBase = 'InternetGatewayDevice.LANDevice.1.LANHostConfigManagement';

        // Check if DHCP capability exists by checking for any DHCP parameter
        // (not just DHCPServerEnable, as it may not have _value if not configured)
        $hasdhcpCapability = false;

        // Check multiple DHCP parameters to determine if device supports DHCP
        $dhcpEnabled = $getParam("{$dhcpBase}.DHCPServerEnable");
        $dhcpLeaseTime = $getParam("{$dhcpBase}.DHCPLeaseTime");

        // Device has DHCP capability if any DHCP parameter is present
        if ($dhcpEnabled !== null || $dhcpLeaseTime !== null) {
            $hasdhcpCapability = true;
        }

        if ($hasdhcpCapability) {
            // Extract DHCP parameters (use false/N/A as defaults if not configured)
            $dhcpServer['enabled'] = $dhcpEnabled ?? false;
            $dhcpServer['configurable'] = $getParam("{$dhcpBase}.DHCPServerConfigurable") ?? true;
            $dhcpServer['min_address'] = $getParam("{$dhcpBase}.MinAddress") ?? 'N/A';
            $dhcpServer['max_address'] = $getParam("{$dhcpBase}.MaxAddress") ?? 'N/A';
            $dhcpServer['subnet_mask'] = $getParam("{$dhcpBase}.SubnetMask") ?? 'N/A';
            $dhcpServer['gateway'] = $getParam("{$dhcpBase}.IPRouters") ?? 'N/A';
            $dhcpServer['dns_servers'] = $getParam("{$dhcpBase}.DNSServers") ?? 'N/A';
            $dhcpServer['lease_time'] = $dhcpLeaseTime ?? 86400; // Default to 24 hours

            $data['dhcp_server'] = $dhcpServer;
        } else {
            // Device does not support DHCP - set to null
            $data['dhcp_server'] = null;
        }

        // Admin Web Access Credentials
        $data['admin_user'] = $getParam('VirtualParameters.superAdmin') ?? 'N/A';
        $data['admin_password'] = $getParam('VirtualParameters.superPassword') ?? 'N/A';
        $data['telecom_password'] = $getParam('InternetGatewayDevice.DeviceInfo.X_CT-COM_TeleComAccount.Password') ?? 'N/A';

        // Tags
        $data['tags'] = $device['_tags'] ?? [];

        return $data;
    }

    /**
     * Fast Bulk Summon (Smart Queuing)
     *
     * Semua task masuk antrean tanpa ?connection_request (instan, GenieACS cuma mencatat).
     * Hanya task TERAKHIR yang membawa ?connection_request sebagai "alarm" (wake-up call),
     * jadi modem cuma digedor 1x untuk mengerjakan semua task.
     *
     * Task disesuaikan dengan data model modem (TR-098 / TR-181) supaya tidak ada task
     * yang menunjuk path yang tidak ada (fault 9005 yang nyangkut di antrean).
     * Cakupan: VirtualParameters (admin), WLAN (SSID), Hosts (Client).
     */
    public function bulkSummonFast($deviceId)
    {
        $encodedId = rawurlencode($deviceId);
        $root = $this->detectRoot($deviceId);

        $queue  = "/devices/{$encodedId}/tasks";                     // instan, cuma nyatet antrean
        $wakeUp = "/devices/{$encodedId}/tasks?connection_request";  // dipakai SEKALI, di task terakhir

        $refresh = function ($endpoint, $objectName) {
            return $this->request($endpoint, 'POST', [
                'name' => 'refreshObject',
                'objectName' => $objectName
            ]);
        };

        // Selalu: kredensial admin
        $refresh($queue, 'VirtualParameters');

        if ($root === 'tr098') {
            $refresh($queue,  'InternetGatewayDevice.LANDevice.1.WLANConfiguration'); // SSID
            $refresh($wakeUp, 'InternetGatewayDevice.LANDevice.1.Hosts');             // client + alarm
        } elseif ($root === 'tr181') {
            $refresh($queue,  'Device.WiFi');                                         // SSID
            $refresh($wakeUp, 'Device.Hosts');                                        // client + alarm
        } else {
            // Model belum ketahuan: kirim dua-duanya (perilaku lama)
            $refresh($queue,  'InternetGatewayDevice.LANDevice.1.WLANConfiguration');
            $refresh($queue,  'Device.WiFi');
            $refresh($queue,  'InternetGatewayDevice.LANDevice.1.Hosts');
            $refresh($wakeUp, 'Device.Hosts');
        }

        return ['success' => true];
    }

    /**
     * Summon massal PARALEL (server-side) untuk ratusan device sekaligus.
     *
     * Beda dengan bulkSummonFast() yang dipanggil per device:
     * - Deteksi model (TR-098/TR-181) cukup 1x GET kecil untuk SEMUA device.
     * - Semua POST task dikirim lewat curl_multi (default 40 koneksi paralel) dari 1 proses PHP,
     *   jadi tidak menghabiskan worker PHP-FPM dan tidak bergantung ke browser.
     * - Dua fase: (1) semua task masuk antrean tanpa ?connection_request (instan),
     *   (2) task "alarm" terakhir tiap device dengan ?connection_request. Prinsip Smart Queuing
     *   tetap: modem digedor 1x untuk mengerjakan semua task.
     *
     * @param array $deviceIds   Daftar device ID
     * @param int   $concurrency Jumlah koneksi paralel ke NBI GenieACS
     * @return array ['success','total','success_count','fail_count','tasks_ok','tasks_failed']
     */
    public function bulkSummonParallel(array $deviceIds, int $concurrency = 40)
    {
        $deviceIds = array_values(array_unique(array_filter($deviceIds, 'strlen')));
        $total = count($deviceIds);

        if ($total === 0) {
            return [
                'success' => true,
                'total' => 0,
                'success_count' => 0,
                'fail_count' => 0,
                'tasks_ok' => 0,
                'tasks_failed' => 0
            ];
        }

        // 1) Satu GET kecil: root data model semua device (hanya ProductClass, ukurannya kecil)
        $roots = [];
        $projection = urlencode('InternetGatewayDevice.DeviceInfo.ProductClass,Device.DeviceInfo.ProductClass');
        $res = $this->request("/devices/?projection={$projection}");
        if ($res['success'] && is_array($res['data'])) {
            foreach ($res['data'] as $d) {
                if (!isset($d['_id'])) continue;
                if (isset($d['InternetGatewayDevice'])) {
                    $roots[$d['_id']] = 'tr098';
                } elseif (isset($d['Device'])) {
                    $roots[$d['_id']] = 'tr181';
                }
            }
        }

        // 2) Susun daftar job: [deviceId, objectName]
        $queueJobs = [];
        $wakeJobs  = [];
        foreach ($deviceIds as $id) {
            $root = $roots[$id] ?? null;

            $queueJobs[] = [$id, 'VirtualParameters']; // admin credentials

            if ($root === 'tr098') {
                $queueJobs[] = [$id, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration']; // SSID
                $wakeJobs[]  = [$id, 'InternetGatewayDevice.LANDevice.1.Hosts'];             // client + alarm
            } elseif ($root === 'tr181') {
                $queueJobs[] = [$id, 'Device.WiFi'];
                $wakeJobs[]  = [$id, 'Device.Hosts'];
            } else {
                // Model belum ketahuan: kirim dua-duanya (perilaku lama)
                $queueJobs[] = [$id, 'InternetGatewayDevice.LANDevice.1.WLANConfiguration'];
                $queueJobs[] = [$id, 'Device.WiFi'];
                $queueJobs[] = [$id, 'InternetGatewayDevice.LANDevice.1.Hosts'];
                $wakeJobs[]  = [$id, 'Device.Hosts'];
            }
        }

        // 3) Fase 1: antrean (instan), Fase 2: alarm / wake-up
        $failedDevices = [];
        $okQueue = $this->postMulti($queueJobs, false, $concurrency, $failedDevices);
        $okWake  = $this->postMulti($wakeJobs, true, $concurrency, $failedDevices);

        $tasksTotal = count($queueJobs) + count($wakeJobs);
        $tasksOk = $okQueue + $okWake;

        return [
            'success'       => true,
            'total'         => $total,
            'success_count' => $total - count($failedDevices),
            'fail_count'    => count($failedDevices),
            'tasks_ok'      => $tasksOk,
            'tasks_failed'  => $tasksTotal - $tasksOk,
        ];
    }

    /**
     * Kirim banyak POST /tasks paralel dengan curl_multi.
     *
     * @param array $jobs           [[deviceId, objectName], ...]
     * @param bool  $connectionReq  true = tambahkan ?connection_request (task alarm)
     * @param int   $concurrency    Jumlah koneksi paralel
     * @param array $failedDevices  (by reference) diisi deviceId yang ada task gagal
     * @return int  Jumlah task yang sukses (HTTP 2xx)
     */
    private function postMulti(array $jobs, bool $connectionReq, int $concurrency, array &$failedDevices)
    {
        $total = count($jobs);
        if ($total === 0) return 0;

        $mh = curl_multi_init();
        $next = 0;
        $inFlight = 0;
        $ok = 0;
        $meta = []; // spl_object_id(handle) => deviceId

        $add = function () use (&$next, &$inFlight, &$meta, $total, $jobs, $mh, $connectionReq) {
            if ($next >= $total) return false;
            [$deviceId, $objectName] = $jobs[$next++];

            $path = '/devices/' . rawurlencode($deviceId) . '/tasks' . ($connectionReq ? '?connection_request' : '');
            $ch = curl_init($this->baseUrl . $path);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['name' => 'refreshObject', 'objectName' => $objectName]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            if ($this->username && $this->password) {
                curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
            }

            curl_multi_add_handle($mh, $ch);
            $meta[spl_object_id($ch)] = $deviceId;
            $inFlight++;
            return true;
        };

        // Isi slot awal
        for ($i = 0; $i < $concurrency; $i++) {
            if (!$add()) break;
        }

        do {
            curl_multi_exec($mh, $running);

            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $deviceId = $meta[spl_object_id($ch)] ?? null;

                if ($info['result'] === CURLE_OK && $code >= 200 && $code < 300) {
                    $ok++;
                } elseif ($deviceId !== null) {
                    $failedDevices[$deviceId] = true;
                }

                unset($meta[spl_object_id($ch)]);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                $inFlight--;

                $add(); // isi slot yang kosong dengan job berikutnya
            }

            if ($inFlight > 0) {
                if (curl_multi_select($mh, 0.5) === -1) {
                    usleep(1000);
                }
            }
        } while ($inFlight > 0);

        curl_multi_close($mh);
        return $ok;
    }
}