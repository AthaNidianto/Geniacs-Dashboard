<?php
namespace App;

use RouterOS\Client;
use RouterOS\Query;

/**
 * Backup konfigurasi router MikroTik.
 *
 * Alur: API RouterOS membuat file (/export -> .rsc dan/atau /system/backup/save -> .backup),
 * file diambil lewat FTP (curl), disimpan di server, lalu file di router dihapus.
 * User router cukup policy: api, ftp, read, test (lihat penjelasan di README deploy).
 */
class RouterBackup
{
    private $conn;

    public static function backupDir(): string
    {
        $dir = defined('ROUTER_BACKUP_DIR') ? ROUTER_BACKUP_DIR : (getenv('ROUTER_BACKUP_DIR') ?: '/var/backups/gacs-routers');
        return rtrim($dir, '/');
    }

    public function __construct($conn)
    {
        $this->conn = $conn;
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->conn->query("CREATE TABLE IF NOT EXISTS `router_backup_targets` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `device_id` varchar(191) NULL DEFAULT NULL,
            `name` varchar(100) NOT NULL,
            `host` varchar(255) NOT NULL,
            `api_port` int(11) NOT NULL DEFAULT 8728,
            `ftp_port` int(11) NOT NULL DEFAULT 21,
            `username` varchar(100) NOT NULL,
            `password` varchar(255) NOT NULL,
            `format` enum('rsc','backup','both') NOT NULL DEFAULT 'both',
            `keep_count` int(11) NOT NULL DEFAULT 14,
            `show_sensitive` tinyint(1) NOT NULL DEFAULT 0,
            `last_backup_at` datetime NULL DEFAULT NULL,
            `last_status` varchar(10) NULL DEFAULT NULL,
            `last_message` varchar(255) NULL DEFAULT NULL,
            `created_at` timestamp NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_device` (`device_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // tabel lama (kalau sudah terlanjur dibuat sebelum kolom ini ada)
        $c = $this->conn->query("SHOW COLUMNS FROM router_backup_targets LIKE 'device_id'");
        if ($c && $c->num_rows === 0) {
            $this->conn->query("ALTER TABLE router_backup_targets ADD `device_id` varchar(191) NULL DEFAULT NULL AFTER `id`, ADD UNIQUE KEY `uniq_device` (`device_id`)");
        }
        $c = $this->conn->query("SHOW COLUMNS FROM router_backup_targets LIKE 'show_sensitive'");
        if ($c && $c->num_rows === 0) {
            $this->conn->query("ALTER TABLE router_backup_targets ADD `show_sensitive` tinyint(1) NOT NULL DEFAULT 0 AFTER `keep_count`");
        }
    }

    public static function slug(string $name): string
    {
        $s = strtolower(preg_replace('/[^A-Za-z0-9_-]+/', '-', $name));
        $s = trim($s, '-');
        return $s !== '' ? $s : 'router';
    }

    private function prefix(array $t): string
    {
        return self::slug($t['name']) . '-' . (int)$t['id'];
    }

    public function listTargets(): array
    {
        $rows = [];
        $res = $this->conn->query("SELECT id,device_id,name,host,api_port,ftp_port,username,format,keep_count,show_sensitive,last_backup_at,last_status,last_message FROM router_backup_targets ORDER BY name");
        while ($res && ($r = $res->fetch_assoc())) $rows[] = $r;
        return $rows;
    }

    public function getTarget(int $id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM router_backup_targets WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        return $r ?: null;
    }

    /** Setting backup milik satu device GenieACS (tanpa password). */
    public function getByDevice(string $deviceId): ?array
    {
        $stmt = $this->conn->prepare("SELECT id,device_id,name,host,api_port,ftp_port,username,format,keep_count,show_sensitive,last_backup_at,last_status,last_message FROM router_backup_targets WHERE device_id = ?");
        $stmt->bind_param('s', $deviceId);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        return $r ?: null;
    }

    public function saveTarget(array $d): array
    {
        $deviceId = trim($d['device_id'] ?? '');
        if ($deviceId === '') return ['success' => false, 'message' => 'device_id wajib'];
        $existing = $this->getByDevice($deviceId);
        $id = $existing ? (int)$existing['id'] : 0;
        $name = trim($d['name'] ?? '');
        $host = trim($d['host'] ?? '');
        $user = trim($d['username'] ?? '');
        $pass = (string)($d['password'] ?? '');
        $apiPort = (int)($d['api_port'] ?? 8728) ?: 8728;
        $ftpPort = (int)($d['ftp_port'] ?? 21) ?: 21;
        $format = in_array($d['format'] ?? 'both', ['rsc', 'backup', 'both'], true) ? $d['format'] : 'both';
        $keep = max(1, min(100, (int)($d['keep_count'] ?? 14)));
        $sens = !empty($d['show_sensitive']) ? 1 : 0;

        if ($name === '' || $host === '' || $user === '') {
            return ['success' => false, 'message' => 'Nama, host, dan username wajib diisi'];
        }
        if (!preg_match('/^[A-Za-z0-9.\-]+$/', $host)) {
            return ['success' => false, 'message' => 'Host tidak valid'];
        }

        if ($id > 0) {
            $old = $this->getTarget($id);
            if (!$old) return ['success' => false, 'message' => 'Router tidak ditemukan'];
            if ($pass === '') $pass = $old['password'];   // kosong = password tidak diubah
            $stmt = $this->conn->prepare("UPDATE router_backup_targets SET name=?,host=?,api_port=?,ftp_port=?,username=?,password=?,format=?,keep_count=?,show_sensitive=? WHERE id=?");
            $stmt->bind_param('ssiisssiii', $name, $host, $apiPort, $ftpPort, $user, $pass, $format, $keep, $sens, $id);
        } else {
            if ($pass === '') return ['success' => false, 'message' => 'Password wajib diisi'];
            $stmt = $this->conn->prepare("INSERT INTO router_backup_targets (name,host,api_port,ftp_port,username,password,format,keep_count,show_sensitive,device_id) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('ssiisssiis', $name, $host, $apiPort, $ftpPort, $user, $pass, $format, $keep, $sens, $deviceId);
        }
        if (!$stmt->execute()) return ['success' => false, 'message' => 'Gagal menyimpan'];
        return ['success' => true, 'message' => 'Router disimpan'];
    }

    public function deleteTarget(int $id): bool
    {
        $stmt = $this->conn->prepare("DELETE FROM router_backup_targets WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    /** Daftar file backup milik satu router (terbaru dulu). */
    public function listFiles(int $targetId): array
    {
        $t = $this->getTarget($targetId);
        if (!$t) return [];
        $files = [];
        foreach (glob(self::backupDir() . '/' . $this->prefix($t) . '-????????-??????.*') ?: [] as $f) {
            if (!is_file($f)) continue;
            $files[] = ['name' => basename($f), 'size' => filesize($f), 'time' => date('Y-m-d H:i:s', filemtime($f))];
        }
        usort($files, fn($a, $b) => strcmp($b['time'], $a['time']) ?: strcmp($b['name'], $a['name']));
        return $files;
    }

    /** Jalankan backup satu router. Return ['success'=>bool,'message'=>string,'files'=>[]] */
    public function run(int $id): array
    {
        $t = $this->getTarget($id);
        if (!$t) return ['success' => false, 'message' => 'Router tidak ditemukan'];

        $result = $this->doBackup($t);

        $status = $result['success'] ? 'ok' : 'fail';
        $msg = mb_substr($result['message'], 0, 250);
        $stmt = $this->conn->prepare("UPDATE router_backup_targets SET last_backup_at=NOW(), last_status=?, last_message=? WHERE id=?");
        $stmt->bind_param('ssi', $status, $msg, $id);
        $stmt->execute();

        if ($result['success']) $this->prune($t);
        return $result;
    }

    private function doBackup(array $t): array
    {
        $dir = self::backupDir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            return ['success' => false, 'message' => "Folder backup $dir tidak bisa dibuat. Buat manual: mkdir -p $dir && chown www-data:www-data $dir && chmod 750 $dir"];
        }
        if (!is_writable($dir)) {
            return ['success' => false, 'message' => "Folder $dir tidak bisa ditulis oleh web server (chown www-data:www-data $dir)"];
        }

        $base = $this->prefix($t) . '-' . date('Ymd-His');
        $remote = 'gacs-' . $base;            // nama file sementara di router
        $want = [];
        if (in_array($t['format'], ['rsc', 'both'], true)) $want[] = 'rsc';
        if (in_array($t['format'], ['backup', 'both'], true)) $want[] = 'backup';

        try {
            $client = new Client([
                'host' => $t['host'], 'user' => $t['username'], 'pass' => $t['password'],
                'port' => (int)$t['api_port'], 'timeout' => 10,
            ]);

            if (in_array('rsc', $want, true)) {
                // /export adalah perintah konsol: lewat API paling andal dijalankan via script sementara di router
                $cmd = '/export file=' . $remote;
                $major = $this->majorVersion($client);
                // v6: export biasa menampilkan password (hide-sensitive untuk menyembunyikan)
                // v7: export biasa menyembunyikan password (show-sensitive untuk menampilkan)
                if ($major >= 7 && !empty($t['show_sensitive'])) $cmd = '/export show-sensitive file=' . $remote;
                if ($major < 7 && empty($t['show_sensitive'])) $cmd = '/export hide-sensitive file=' . $remote;

                $err = $this->runConsole($client, $cmd, !empty($t['show_sensitive']), $remote);
                if ($err) return ['success' => false, 'message' => "Router menolak /export: $err"];
            }
            if (in_array('backup', $want, true)) {
                $err = $this->trapMessage($client->query((new Query('/system/backup/save'))->equal('name', $remote))->read());
                if ($err) return ['success' => false, 'message' => "Router menolak /system backup save: $err"];
            }

            // pastikan file benar-benar sudah ada di router sebelum diambil lewat FTP
            foreach ($want as $ext) {
                if (!$this->waitForFile($client, "$remote.$ext")) {
                    return ['success' => false, 'message' => "Router tidak membuat file $ext. Biasanya policy user router kurang (butuh: api, ftp, read, test" . (!empty($t['show_sensitive']) ? ', sensitive' : '') . ", dan mungkin write/policy). Coba jalankan manual di terminal router: /export file=tes"];
                }
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'API router gagal: ' . $e->getMessage()];
        }

        $saved = [];
        $errors = [];
        foreach ($want as $ext) {
            $remoteName = "$remote.$ext";
            $local = "$dir/$base.$ext";
            $ok = false;
            // file dibuat router sedikit tertunda, coba beberapa kali
            for ($i = 0; $i < 6 && !$ok; $i++) {
                if ($i > 0) sleep(2);
                $ok = $this->ftpDownload($t, $remoteName, $local, $err);
            }
            if ($ok) { chmod($local, 0640); $saved[] = basename($local); }
            else $errors[] = "$ext: $err";
        }

        // bersihkan file sementara di router (abaikan kalau gagal)
        try {
            foreach ($want as $ext) {
                $found = $client->query((new Query('/file/print'))->where('name', "$remote.$ext"))->read();
                foreach ($found as $f) {
                    if (isset($f['.id'])) $client->query((new Query('/file/remove'))->equal('.id', $f['.id']))->read();
                }
            }
        } catch (\Throwable $e) { /* diabaikan */ }

        if ($errors) {
            return ['success' => false, 'message' => 'Download FTP gagal (' . implode('; ', $errors) . ')', 'files' => $saved];
        }
        return ['success' => true, 'message' => 'Backup berhasil: ' . implode(', ', $saved), 'files' => $saved];
    }

    /** Jalankan perintah konsol lewat script sementara. Return pesan error atau null. */
    private function runConsole(Client $client, string $command, bool $sensitive, string $tag): ?string
    {
        $scriptName = 'gacs-tmp-' . $tag;
        $policy = 'read,write,policy,test' . ($sensitive ? ',sensitive' : '');
        $id = null;
        try {
            $r = $client->query((new Query('/system/script/add'))
                ->equal('name', $scriptName)->equal('source', $command)->equal('policy', $policy))->read();
            if ($e = $this->trapMessage($r)) return $e;
            $id = $r['after']['ret'] ?? ($r[0]['ret'] ?? null);
            if (!$id) return 'gagal membuat script sementara di router';

            $r = $client->query((new Query('/system/script/run'))->equal('number', $id))->read();
            if ($e = $this->trapMessage($r)) return $e;
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        } finally {
            if ($id) {
                try { $client->query((new Query('/system/script/remove'))->equal('.id', $id))->read(); } catch (\Throwable $e) { /* diabaikan */ }
            }
        }
    }

    /** Ambil pesan error dari respons API RouterOS (!trap), atau null kalau sukses. */
    private function trapMessage($response): ?string
    {
        if (is_array($response)) {
            if (isset($response['after']['message'])) return (string)$response['after']['message'];
            if (isset($response['message']) && is_string($response['message'])) return $response['message'];
            foreach ($response as $row) {
                if (is_array($row) && isset($row['message']) && is_string($row['message'])) return $row['message'];
            }
        }
        return null;
    }

    private function waitForFile(Client $client, string $name): bool
    {
        for ($i = 0; $i < 15; $i++) {
            $found = $client->query((new Query('/file/print'))->where('name', $name))->read();
            if (!empty($found) && isset($found[0]['name'])) return true;
            sleep(1);
        }
        return false;
    }

    private function majorVersion(Client $client): int
    {
        try {
            $r = $client->query(new Query('/system/resource/print'))->read();
            return (int)($r[0]['version'] ?? 7);   // contoh "7.14.3 (stable)"
        } catch (\Throwable $e) {
            return 7;
        }
    }

    private function ftpDownload(array $t, string $remoteName, string $localPath, &$error): bool
    {
        $fh = @fopen($localPath, 'wb');
        if (!$fh) { $error = 'tidak bisa menulis file lokal'; return false; }

        $ch = curl_init('ftp://' . $t['host'] . ':' . (int)$t['ftp_port'] . '/' . rawurlencode($remoteName));
        curl_setopt_array($ch, [
            CURLOPT_USERPWD => $t['username'] . ':' . $t['password'],
            CURLOPT_FILE => $fh,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FTP_USE_EPSV => false,
            CURLOPT_FAILONERROR => true,
        ]);
        $ok = curl_exec($ch);
        $error = $ok ? '' : curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if (!$ok || filesize($localPath) === 0) {
            @unlink($localPath);
            if ($ok) $error = 'file kosong';
            return false;
        }
        return true;
    }

    /** Sisakan keep_count backup terbaru (per ekstensi). */
    private function prune(array $t): void
    {
        $slug = $this->prefix($t);
        foreach (['rsc', 'backup'] as $ext) {
            $files = glob(self::backupDir() . "/$slug-????????-??????.$ext") ?: [];
            rsort($files);   // nama berisi tanggal, jadi urut nama = urut waktu
            foreach (array_slice($files, max(1, (int)$t['keep_count'])) as $old) @unlink($old);
        }
    }
}