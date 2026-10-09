<?php
/**
 * Data grafik garis CPE online/offline.
 * GET ?range=24h|7d|10d|30d
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/status-history.php';

header('Content-Type: application/json');
requireLogin();

$range = $_GET['range'] ?? '24h';
$ranges = [
    // range => [lama dalam detik, ukuran keranjang detik]
    '24h' => [86400, 300],      // titik tiap 5 menit
    '7d'  => [7 * 86400, 300],  // titik tiap 5 menit (data asli, tanpa dirata-rata)
    '10d' => [10 * 86400, 1800], // rata-rata tiap 30 menit (dipakai dashboard)
    '30d' => [30 * 86400, 7200] // rata-rata tiap 2 jam
];
if (!isset($ranges[$range])) {
    $range = '24h';
}
list($seconds, $bucket) = $ranges[$range];

try {
    $conn = getDBConnection();
    ensureStatusHistoryTable($conn);

    $since = time() - $seconds;
    $stmt = $conn->prepare("SELECT ts, total, online, offline FROM device_status_history WHERE ts >= ? ORDER BY ts ASC");
    $stmt->bind_param('i', $since);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $stmt->close();

    $points = bucketStatusRows($rows, $bucket);

    jsonResponse([
        'success' => true,
        'range' => $range,
        'points' => $points,
        'count' => count($points),
    ]);
} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Gagal memuat riwayat: ' . $e->getMessage()]);
}
