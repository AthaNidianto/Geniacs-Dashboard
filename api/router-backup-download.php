<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
$name = basename($_GET['file'] ?? '');
if ($id <= 0 || !preg_match('/^backup_\d{4}-\d{2}-\d{2}_[A-Za-z]+_\d{6}_[A-Za-z0-9_-]+\.(rsc|backup)$/', $name)) {
    http_response_code(400); exit('File tidak valid');
}
$path = App\RouterBackup::targetDir($id) . '/' . $name;
if (!is_file($path)) { http_response_code(404); exit('File tidak ditemukan'); }

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);