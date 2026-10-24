<?php
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? '';
$deviceId = trim($data['device_id'] ?? '');
$rb = new App\RouterBackup(getDBConnection());

if ($deviceId === '') {
    jsonResponse(['success' => false, 'message' => 'device_id wajib'], 400);
}
$target = $rb->getByDevice($deviceId);

switch ($action) {
    case 'get':
        jsonResponse(['success' => true, 'data' => $target, 'dir' => App\RouterBackup::backupDir()]);
    case 'save':
        $res = $rb->saveTarget($data);
        if (!empty($res['success'])) {
            // Ambil identity router supaya muncul di daftar MikroTik (gagal = diabaikan)
            $saved = $rb->getByDevice($deviceId);
            if ($saved) $res['identity'] = $rb->refreshIdentity((int)$saved['id']);
        }
        jsonResponse($res);
    case 'delete':
        jsonResponse(['success' => $target ? $rb->deleteTarget((int)$target['id']) : true]);
    case 'run':
        if (!$target) jsonResponse(['success' => false, 'message' => 'Simpan pengaturan backup dulu']);
        set_time_limit(120);
        jsonResponse($rb->run((int)$target['id']));
    case 'files':
        jsonResponse(['success' => true, 'data' => $target ? $rb->listFiles((int)$target['id']) : []]);
    default:
        jsonResponse(['success' => false, 'message' => 'Aksi tidak dikenal'], 400);
}