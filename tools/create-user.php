<?php
// Buat / ubah user dashboard dari terminal (tidak bisa dibuka lewat web).
//
//   php tools/create-user.php <username> <password> [role]
//
// role: admin (semua menu, default) atau monitor (hanya Dashboard, sesi tidak timeout)
// Contoh: php /var/www/gacs-dashboard/tools/create-user.php monitor 'RahasiaKuat123' monitor

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/config.php';

[$self, $username, $password] = array_pad($argv, 3, null);
$role = $argv[3] ?? 'admin';

if (!$username || !$password || !in_array($role, ['admin', 'monitor'], true)) {
    fwrite(STDERR, "Pakai: php tools/create-user.php <username> <password> [admin|monitor]\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Password minimal 8 karakter\n");
    exit(1);
}

$conn = getDBConnection();
ensureUserRoleColumn($conn);

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE password = VALUES(password), role = VALUES(role)");
$stmt->bind_param('sss', $username, $hash, $role);

if ($stmt->execute()) {
    echo "User '$username' siap dipakai (role: $role)\n";
} else {
    fwrite(STDERR, "Gagal menyimpan: " . $conn->error . "\n");
    exit(1);
}