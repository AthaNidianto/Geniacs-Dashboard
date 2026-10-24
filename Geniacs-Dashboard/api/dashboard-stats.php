<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

// Check if GenieACS is configured
if (!isGenieACSConfigured()) {
    jsonResponse(['success' => false, 'message' => 'GenieACS belum dikonfigurasi']);
}

// Get GenieACS credentials
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

// Ambil statistik dasar (Total, Online, Offline)
$stats = $genieacs->getDeviceStats();

if ($stats['success']) {
    $dashboardData = $stats['data'];

    // Inisialisasi wadah penampung data untuk grafik
    $manufacturers = [];
    $ponTypes = [
        'GPON' => 0,
        'EPON' => 0,
        'Router/Switch' => 0,
        'Lainnya' => 0
    ];

    // Ambil data seluruh device untuk dihitung detailnya (dibatasi 5000 biar aman)
    $devicesResult = $genieacs->getDevicesLite([], 5000, 0);

    if ($devicesResult['success'] && isset($devicesResult['data'])) {
        foreach ($devicesResult['data'] as $device) {
            $parsed = GenieACS_Fast::parseDeviceDataFast($device);

            // ==========================================
            // 1. KATEGORISASI MEREK (MANUFACTURER)
            // ==========================================
            $mfgRaw = $parsed['manufacturer'] ?? 'Unknown';
            $mfgUpper = strtoupper($mfgRaw);

            // Satukan nama OEM pabrik (HWTC jadi Huawei, CIOT jadi ZTE, dst)
            if (strpos($mfgUpper, 'ZTE') !== false || strpos($mfgUpper, 'CIOT') !== false || strpos($mfgUpper, 'RTEG') !== false) {
                $mfg = 'ZTE';
            } elseif (strpos($mfgUpper, 'HUAWEI') !== false || strpos($mfgUpper, 'HWTC') !== false) {
                $mfg = 'Huawei';
            } elseif (strpos($mfgUpper, 'FIBERHOME') !== false) {
                $mfg = 'FiberHome';
            } elseif (strpos($mfgUpper, 'MIKROTIK') !== false || $mfgUpper === 'ROUTERBOARD') {
                $mfg = 'MikroTik';
            } elseif (strpos($mfgUpper, 'NOKIA') !== false || strpos($mfgUpper, 'ALCATEL') !== false) {
                $mfg = 'Nokia/Alcatel';
            } elseif ($mfgRaw === 'N/A' || $mfgRaw === '' || strpos($mfgUpper, 'DISCOVERY') !== false) {
                // Buang layanan palsu seperti DISCOVERYSERVICE
                $mfg = 'Unknown';
            } else {
                $mfg = $mfgRaw;
            }

            // Masukkan ke array
            if ($mfg !== 'Unknown') {
                if (!isset($manufacturers[$mfg])) $manufacturers[$mfg] = 0;
                $manufacturers[$mfg]++;
            }

            // ==========================================
            // 2. KATEGORISASI TIPE PON (SINKRON DENGAN GENIEACS)
            // ==========================================
            // GenieACS biasanya menyimpan status PON pada VirtualParameters atau jalur EPON/GPON interface
            $ponTypeRaw = 'Lainnya';

            // Cek apakah perangkat terdeteksi sebagai Ethernet/Router
            $productClass = strtoupper($parsed['product_class'] ?? '');
            if ($mfg === 'MikroTik' || strpos($productClass, 'RB') === 0 || strpos($productClass, 'CCR') === 0) {
                $ponTypeRaw = 'Router/Switch';
            } else {
                // Cek parameter spesifik PON yang biasa dikirim GenieACS
                // (Mengecek path VirtualParameters atau parameter EPON/GPON config di data mentah device)
                $hasEpon = isset($device['InternetGatewayDevice']['WANDevice']['1']['X_CT-COM_EponInterfaceConfig']) ||
                    strpos(json_encode($device), 'Epon') !== false ||
                    strpos($productClass, 'F66') !== false; // Mayoritas F663 adalah EPON

                $hasGpon = isset($device['InternetGatewayDevice']['WANDevice']['1']['X_HW_GponInterfaceConfig']) ||
                    strpos(json_encode($device), 'Gpon') !== false;

                if ($hasEpon && !$hasGpon) {
                    $ponTypeRaw = 'EPON';
                } elseif ($hasGpon) {
                    $ponTypeRaw = 'GPON';
                } else {
                    // Fallback jika tidak terbaca spesifik, ikuti product class
                    if (strpos($productClass, 'F66') !== false) {
                        $ponTypeRaw = 'EPON';
                    } else {
                        $ponTypeRaw = 'GPON'; // Default jika kedepannya nambah GPON
                    }
                }
            }

            if (!isset($ponTypes[$ponTypeRaw])) {
                $ponTypes[$ponTypeRaw] = 0;
            }
            $ponTypes[$ponTypeRaw]++;
        }
    }

    // Filter kategori yang nilainya 0 agar tidak muncul di chart (bikin jelek UI)
    $dashboardData['manufacturers'] = array_filter($manufacturers, function ($val) {
        return $val > 0;
    });
    $dashboardData['pon_types'] = array_filter($ponTypes, function ($val) {
        return $val > 0;
    });

    // Kirim balikan ke Javascript di dashboard.php
    jsonResponse([
        'success' => true,
        'stats' => $dashboardData
    ]);
} else {
    jsonResponse(['success' => false, 'message' => 'Gagal mengambil statistik']);
}
