<?php
namespace App;

/**
 * Audit log: mencatat siapa melakukan apa, dari IP mana, kapan, dan hasilnya.
 *
 * Pencatatan otomatis dipasang sekali di config.php (AuditLog::autoStart()). Setiap request ke
 * endpoint API di daftar $ACTIONS (hanya yang mengubah data) dicatat, lengkap dengan hasil
 * (berhasil/gagal) yang dibaca dari balasan JSON endpoint tersebut. Login, login gagal, dan logout
 * dicatat dari login.php / logout.php.
 *
 * Password dan semua field rahasia TIDAK pernah dicatat (lihat redact()).
 */
class AuditLog
{
    /**
     * IP proxy yang dipercaya. Header X-Forwarded-For / CF-Connecting-IP hanya dipakai kalau request
     * datang dari salah satu IP ini; kalau tidak, header itu bisa dipalsukan siapa saja.
     * Ubah lewat konstanta AUDIT_TRUSTED_PROXIES (array) di config kalau dashboard ada di belakang
     * reverse proxy / Cloudflare / Nginx lain.
     */
    private static function trustedProxies(): array
    {
        // constant() dipakai supaya editor (Intelephense) tidak menandai konstanta opsional ini sebagai error
        if (defined('AUDIT_TRUSTED_PROXIES')) {
            $list = constant('AUDIT_TRUSTED_PROXIES');
            if (is_array($list)) {
                return $list;
            }
        }
        return ['127.0.0.1', '::1'];
    }

    /** Nama skrip API (tanpa .php) => label aksi. Hanya aksi yang mengubah sesuatu. */
    private static $ACTIONS = [
        'reboot-device'          => 'Reboot ONU',
        'summon-device'          => 'Summon device',
        'summon-multiple'        => 'Summon banyak device',
        'summon-all'             => 'Summon semua device',
        'update-wifi-config'     => 'Ubah WiFi',
        'update-lan-port'        => 'Ubah LAN port',
        'update-wan-config'      => 'Ubah WAN',
        'add-wan-config'         => 'Tambah WAN',
        'delete-wan-config'      => 'Hapus WAN',
        'update-dhcp-config'     => 'Ubah DHCP',
        'bulk-delete-devices'    => 'Hapus device (massal)',
        'bulk-tag'               => 'Ubah tag (massal)',
        'update-password'        => 'Ubah akun dashboard',
        'save-genieacs'          => 'Simpan konfigurasi GenieACS',
        'save-mikrotik'          => 'Simpan konfigurasi MikroTik',
        'save-telegram'          => 'Simpan konfigurasi Telegram',
        'map-add-item'           => 'Map: tambah item',
        'map-update-item'        => 'Map: ubah item',
        'map-delete-item'        => 'Map: hapus item',
        'map-save-waypoints'     => 'Map: simpan jalur',
        'map-update-server-links' => 'Map: ubah link server',
        'clear-client-log'       => 'Hapus log client',
        'recalculate-odp-power'  => 'Hitung ulang power ODP',
        // Endpoint dengan banyak action: hanya action di bawah ini yang dicatat
        'router-backup'          => ['label' => 'Backup router', 'only' => ['save' => 'simpan pengaturan', 'run' => 'jalankan backup', 'delete' => 'hapus pengaturan']],
        'ping-diag'              => ['label' => 'Ping diagnostic', 'only' => ['start' => 'mulai']],
    ];

    /** Key payload yang nilainya disensor. */
    private static $SECRET_KEY = '/pass|pwd|secret|token|api[_-]?key|authorization|credential/i';

    private static $captured = '';
    private static $ctx = null;


    private static function cut(string $s, int $start, int $len): string
    {
        return function_exists('mb_substr') ? mb_substr($s, $start, $len) : substr($s, $start, $len);
    }

    private static function len(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
    }

    // ------------------------------------------------------------------
    // IP client
    // ------------------------------------------------------------------

    public static function clientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($remote === '') {
            return '';
        }

        if (in_array($remote, self::trustedProxies(), true)) {
            $candidates = [];
            if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                $candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
            }
            if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                $candidates[] = $_SERVER['HTTP_X_REAL_IP'];
            }
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                // Format: client, proxy1, proxy2 -> ambil yang paling kiri
                $candidates[] = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            }
            foreach ($candidates as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $remote;
    }

    // ------------------------------------------------------------------
    // Tabel
    // ------------------------------------------------------------------

    public static function ensureTable($conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $conn->query("CREATE TABLE IF NOT EXISTS `audit_logs` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `created_at` datetime NOT NULL,
            `user_id` int(11) NULL DEFAULT NULL,
            `username` varchar(100) NOT NULL DEFAULT '',
            `role` varchar(20) NOT NULL DEFAULT '',
            `ip` varchar(45) NOT NULL DEFAULT '',
            `user_agent` varchar(255) NOT NULL DEFAULT '',
            `action` varchar(100) NOT NULL,
            `target` varchar(255) NOT NULL DEFAULT '',
            `detail` text NULL,
            `success` tinyint(1) NOT NULL DEFAULT 1,
            `message` varchar(255) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            KEY `idx_created` (`created_at`),
            KEY `idx_user` (`username`),
            KEY `idx_action` (`action`),
            KEY `idx_ip` (`ip`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    }

    // ------------------------------------------------------------------
    // Catat
    // ------------------------------------------------------------------

    /**
     * Catat satu kejadian. Tidak pernah melempar error (audit tidak boleh merusak request utama).
     *
     * @param string      $action   label aksi
     * @param string      $target   objek yang dikenai aksi (device id, dsb)
     * @param array|null  $detail   data pendukung (akan disensor)
     * @param bool        $success  hasil aksi
     * @param string      $message  pesan hasil singkat
     * @param string|null $username override username (untuk login gagal)
     */
    public static function log(string $action, string $target = '', ?array $detail = null, bool $success = true, string $message = '', ?string $username = null): void
    {
        try {
            if (!function_exists('getDBConnection')) {
                return;
            }
            $conn = getDBConnection();
            if (!$conn) {
                return;
            }
            self::ensureTable($conn);

            $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            $uname = $username ?? ($_SESSION['username'] ?? '');
            $role = $_SESSION['role'] ?? '';
            $ip = self::clientIp();
            $ua = self::cut((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $detailJson = $detail ? self::cut(json_encode(self::redact($detail), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 4000) : null;
            $target = self::cut($target, 0, 255);
            $message = self::cut($message, 0, 255);
            $uname = self::cut((string)$uname, 0, 100);
            $okInt = $success ? 1 : 0;
            $now = date('Y-m-d H:i:s');

            $stmt = $conn->prepare("INSERT INTO audit_logs
                (created_at, user_id, username, role, ip, user_agent, action, target, detail, success, message)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                return;
            }
            $stmt->bind_param('sisssssssis', $now, $userId, $uname, $role, $ip, $ua, $action, $target, $detailJson, $okInt, $message);
            $stmt->execute();
            $stmt->close();

            // Bersihkan log lama sesekali (sekitar 1 dari 200 insert)
            if (mt_rand(1, 200) === 1) {
                self::prune(180, $conn);
            }
        } catch (\Throwable $e) {
            error_log('AuditLog: ' . $e->getMessage());
        }
    }

    /**
     * Jumlah login gagal dari satu IP dalam N menit terakhir (dasar pembatasan brute force).
     * Memakai tabel audit_logs, jadi tidak perlu tabel baru.
     */
    public static function recentFailedLogins(string $ip, int $minutes = 15): int
    {
        try {
            $conn = getDBConnection();
            self::ensureTable($conn);
            $minutes = max(1, $minutes);
            $stmt = $conn->prepare("SELECT COUNT(*) c FROM audit_logs WHERE action = 'Login gagal' AND ip = ? AND created_at > (NOW() - INTERVAL {$minutes} MINUTE)");
            $stmt->bind_param('s', $ip);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return (int)($row['c'] ?? 0);
        } catch (\Throwable $e) {
            error_log('AuditLog recentFailedLogins: ' . $e->getMessage());
            return 0; // kalau gagal membaca, jangan mengunci user yang sah
        }
    }

    public static function prune(int $days = 180, $conn = null): void
    {
        try {
            $conn = $conn ?: getDBConnection();
            $days = max(7, $days);
            $conn->query("DELETE FROM audit_logs WHERE created_at < (NOW() - INTERVAL {$days} DAY)");
        } catch (\Throwable $e) {
            error_log('AuditLog prune: ' . $e->getMessage());
        }
    }

    /** Sensor nilai rahasia secara rekursif dan potong string yang terlalu panjang. */
    public static function redact($data)
    {
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                if (is_string($k) && preg_match(self::$SECRET_KEY, $k)) {
                    $out[$k] = '***';
                } else {
                    $out[$k] = self::redact($v);
                }
            }
            // Daftar panjang (misal 500 device id): ringkas supaya tabel tidak membengkak
            if (count($out) > 20 && array_keys($out) === range(0, count($out) - 1)) {
                $total = count($out);
                $out = array_slice($out, 0, 20);
                $out[] = '... (+' . ($total - 20) . ' lagi, total ' . $total . ')';
            }
            return $out;
        }
        if (is_string($data) && self::len($data) > 300) {
            return self::cut($data, 0, 300) . '...';
        }
        return $data;
    }

    // ------------------------------------------------------------------
    // Pencatatan otomatis untuk request API
    // ------------------------------------------------------------------

    /** Dipanggil dari config.php pada setiap request. Aman: tidak melakukan apa-apa kalau bukan aksi yang dicatat. */
    public static function autoStart(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $script = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
        $path = $_SERVER['SCRIPT_NAME'] ?? '';
        if (strpos($path, '/api/') !== 0 || !isset(self::$ACTIONS[$script])) {
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST ?: [];
        }

        $def = self::$ACTIONS[$script];
        $label = is_array($def) ? $def['label'] : $def;
        if (is_array($def)) {
            $sub = (string)($input['action'] ?? '');
            if (!isset($def['only'][$sub])) {
                return; // action read-only (misal 'files', 'result'), tidak dicatat
            }
            $label .= ': ' . $def['only'][$sub];
        }

        self::$ctx = [
            'label'  => $label,
            'target' => self::guessTarget($input),
            'detail' => $input,
        ];

        // Tangkap balasan JSON endpoint untuk tahu berhasil/gagal. Buffer tidak diubah.
        ob_start(function ($buffer, $phase) {
            self::$captured .= $buffer;
            if ($phase & PHP_OUTPUT_HANDLER_FINAL) {
                self::finish();
            }
            return $buffer;
        });
    }

    private static function guessTarget(array $in): string
    {
        foreach (['device_id', 'serial_number', 'id', 'name', 'host'] as $k) {
            if (!empty($in[$k]) && is_scalar($in[$k])) {
                return (string)$in[$k];
            }
        }
        foreach (['device_ids', 'ids', 'devices'] as $k) {
            if (!empty($in[$k]) && is_array($in[$k])) {
                return count($in[$k]) . ' device';
            }
        }
        return '';
    }

    private static function finish(): void
    {
        if (self::$ctx === null) {
            return;
        }
        $ctx = self::$ctx;
        self::$ctx = null;

        $success = true;
        $message = '';
        $json = json_decode(self::$captured, true);
        if (is_array($json)) {
            if (array_key_exists('success', $json)) {
                $success = (bool)$json['success'];
            }
            $message = (string)($json['message'] ?? '');
        } else {
            $code = http_response_code();
            $success = !$code || $code < 400;
        }
        $code = http_response_code();
        if ($code && $code >= 400) {
            $success = false;
        }

        self::log($ctx['label'], $ctx['target'], $ctx['detail'], $success, $message);
    }
}