<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

$name = basename($_GET['file'] ?? '');
if (!preg_match('/^[a-z0-9_-]+-\d+-\d{8}-\d{6}\.(rsc|backup)$/', $name)) {
    http_response_code(400); exit('File tidak valid');
}
$path = App\RouterBackup::backupDir() . '/' . $name;
if (!is_file($path)) { http_response_code(404); exit('File tidak ditemukan'); }

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);