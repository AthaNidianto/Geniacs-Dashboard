<?php
/**
 * Riwayat jumlah CPE online/offline (untuk grafik garis di dashboard).
 *
 * Satu baris = satu snapshot (waktu unix, total, online, offline).
 * Snapshot ditulis oleh:
 *   - cron/record-status-history.php (disarankan, jalan tiap 5 menit walau dashboard tidak dibuka)
 *   - api/dashboard-stats.php (cadangan: tiap dashboard dibuka, dibatasi minimal 4 menit sekali)
 */

const STATUS_HISTORY_RETENTION_DAYS = 90;

function ensureStatusHistoryTable($conn) {
    static $done = false;
    if ($done) {
        return;
    }
    $conn->query("CREATE TABLE IF NOT EXISTS `device_status_history` (
        `ts` int(11) NOT NULL COMMENT 'Waktu snapshot (unix timestamp)',
        `total` int(11) NOT NULL DEFAULT 0,
        `online` int(11) NOT NULL DEFAULT 0,
        `offline` int(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (`ts`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    COMMENT='Snapshot jumlah CPE online/offline untuk grafik dashboard'");
    $done = true;
}

/**
 * Simpan snapshot. Dilewati kalau snapshot terakhir lebih baru dari $minInterval detik.
 * Mengembalikan true kalau baris baru ditulis.
 */
function recordStatusSnapshot($conn, $total, $online, $offline, $minInterval = 240) {
    ensureStatusHistoryTable($conn);

    $now = time();
    $res = $conn->query("SELECT MAX(ts) AS last_ts FROM device_status_history");
    $row = $res ? $res->fetch_assoc() : null;
    if ($row && $row['last_ts'] !== null && ($now - (int)$row['last_ts']) < $minInterval) {
        return false;
    }

    $stmt = $conn->prepare(
        "INSERT INTO device_status_history (ts, total, online, offline) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE total = VALUES(total), online = VALUES(online), offline = VALUES(offline)"
    );
    $total = (int)$total;
    $online = (int)$online;
    $offline = (int)$offline;
    $stmt->bind_param('iiii', $now, $total, $online, $offline);
    $ok = $stmt->execute();
    $stmt->close();

    // Hapus data lama
    $cutoff = $now - (STATUS_HISTORY_RETENTION_DAYS * 86400);
    $del = $conn->prepare("DELETE FROM device_status_history WHERE ts < ?");
    $del->bind_param('i', $cutoff);
    $del->execute();
    $del->close();

    return $ok;
}

/**
 * Rata-ratakan baris ke dalam keranjang waktu ($bucket detik) supaya titik grafik tidak kebanyakan.
 * $rows: [['ts'=>..,'total'=>..,'online'=>..,'offline'=>..], ...] urut naik menurut ts.
 */
function bucketStatusRows(array $rows, $bucket) {
    if ($bucket <= 1) {
        return array_map(function ($r) {
            return [
                't' => (int)$r['ts'],
                'total' => (int)$r['total'],
                'online' => (int)$r['online'],
                'offline' => (int)$r['offline'],
            ];
        }, $rows);
    }

    $groups = [];
    foreach ($rows as $r) {
        $key = intdiv((int)$r['ts'], $bucket) * $bucket;
        if (!isset($groups[$key])) {
            $groups[$key] = ['n' => 0, 'total' => 0, 'online' => 0, 'offline' => 0];
        }
        $groups[$key]['n']++;
        $groups[$key]['total'] += (int)$r['total'];
        $groups[$key]['online'] += (int)$r['online'];
        $groups[$key]['offline'] += (int)$r['offline'];
    }

    ksort($groups);
    $out = [];
    foreach ($groups as $key => $g) {
        $out[] = [
            't' => $key + intdiv($bucket, 2), // titik di tengah keranjang
            'total' => (int)round($g['total'] / $g['n']),
            'online' => (int)round($g['online'] / $g['n']),
            'offline' => (int)round($g['offline'] / $g['n']),
        ];
    }
    return $out;
}
