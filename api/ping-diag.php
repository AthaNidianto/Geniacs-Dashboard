<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

requireLogin();

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? '';
$deviceId = $data['device_id'] ?? '';

if (empty($deviceId) || !is_string($deviceId)) {
    jsonResponse(['success' => false, 'message' => 'Device ID required']);
}

if (!isGenieACSConfigured()) {
    jsonResponse(['success' => false, 'message' => 'GenieACS belum dikonfigurasi']);
}

// Lepas kunci session supaya polling hasil ping tidak memblokir request lain dari user yang sama
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$conn = getDBConnection();
$result = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$credentials = $result->fetch_assoc();

if (!$credentials) {
    jsonResponse(['success' => false, 'message' => 'GenieACS tidak terhubung']);
}

use App\GenieACS;

$genieacs = new GenieACS(
    $credentials['host'],
    $credentials['port'],
    $credentials['username'],
    $credentials['password']
);

if ($action === 'start') {
    $host = trim((string)($data['host'] ?? ''));
    // Hanya IPv4 / hostname biasa. Tidak boleh spasi atau karakter aneh.
    $okHost = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        || preg_match('/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$/', $host);
    if (!$okHost) {
        jsonResponse(['success' => false, 'message' => 'Host tidak valid. Isi IPv4 (contoh 8.8.8.8) atau nama domain (contoh google.com).']);
    }

    $count = max(1, min(10, (int)($data['count'] ?? 4)));
    $size = max(32, min(1400, (int)($data['size'] ?? 64)));
    $timeoutPerPing = max(500, min(10000, (int)($data['timeout_ms'] ?? 2000)));

    // Interface opsional (path WAN di ONU), dibatasi ke karakter path TR-069
    $interface = trim((string)($data['interface'] ?? ''));
    if ($interface !== '' && !preg_match('/^[A-Za-z0-9_.]{1,256}$/', $interface)) {
        jsonResponse(['success' => false, 'message' => 'Format Interface tidak valid']);
    }

    $res = $genieacs->startPing($deviceId, $host, $count, $timeoutPerPing, $size, $interface);

    if (!empty($res['success'])) {
        jsonResponse([
            'success' => true,
            'message' => 'ONU menerima perintah ping',
            // Perkiraan lama ping (detik) supaya UI tahu berapa lama menunggu
            'expected_seconds' => (int)ceil($count * $timeoutPerPing / 1000) + 3,
        ]);
    }
    if (!empty($res['no_response'])) {
        jsonResponse(['success' => false, 'no_response' => true, 'message' => 'ONU tidak membalas (offline?), ping dibatalkan']);
    }
    $msg = $res['error'] ?? ('Gagal memulai ping' . (isset($res['http_code']) ? ' (HTTP ' . $res['http_code'] . ')' : ''));
    jsonResponse(['success' => false, 'message' => $msg]);
}

if ($action === 'result') {
    $res = $genieacs->fetchPingResult($deviceId);
    if (empty($res['success'])) {
        jsonResponse(['success' => false, 'message' => $res['error'] ?? 'Gagal mengambil hasil ping']);
    }

    $state = $res['state'];
    $message = '';
    if (strpos($state, 'Error_') === 0) {
        $map = [
            'Error_CannotResolveHostName' => 'ONU tidak bisa me-resolve nama host (cek DNS di ONU atau pakai IP langsung)',
            'Error_Internal'              => 'Error internal di ONU',
            'Error_Other'                 => 'ONU gagal menjalankan ping',
        ];
        $message = $map[$state] ?? ('ONU melaporkan: ' . $state);
    }

    jsonResponse([
        'success' => true,
        'state'   => $state,
        'done'    => $res['done'],
        'result'  => $res['result'],
        'message' => $message,
    ]);
}

jsonResponse(['success' => false, 'message' => 'Action tidak dikenal']);