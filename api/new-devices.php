<?php
/**
 * ONU baru yang ditahan (belum di-summon).
 *   GET                                   -> daftar ONU baru {device_id, first_seen}
 *   POST {"action":"ack","device_ids":[]} -> tandai "sudah dilihat" tanpa summon
 * Hanya untuk admin (role monitor otomatis ditolak oleh enforceRoleAccess()).
 */
require_once __DIR__ . '/../config/config.php';

use App\KnownDevices;

header('Content-Type: application/json');
requireLogin();

$conn = getDBConnection();
KnownDevices::ensureTable($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || ($input['action'] ?? '') !== 'ack') {
        jsonResponse(['success' => false, 'message' => 'Action tidak dikenal'], 400);
    }

    $ids = isset($input['device_ids']) && is_array($input['device_ids']) ? $input['device_ids'] : [];
    $ids = array_values(array_filter(array_map(function ($id) {
        return is_string($id) ? trim($id) : '';
    }, $ids), 'strlen'));
    if (!$ids) {
        jsonResponse(['success' => false, 'message' => 'Tidak ada device yang dipilih']);
    }
    if (count($ids) > 400) {
        jsonResponse(['success' => false, 'message' => 'Maksimal 400 device per request']);
    }

    $n = KnownDevices::acknowledge($conn, $ids);
    jsonResponse(['success' => true, 'acknowledged' => $n, 'message' => $n . ' ONU ditandai sudah dilihat']);
}

$rows = KnownDevices::listNew($conn);
jsonResponse(['success' => true, 'count' => count($rows), 'devices' => $rows]);