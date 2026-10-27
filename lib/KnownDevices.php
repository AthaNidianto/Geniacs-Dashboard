<?php
namespace App;

/**
 * Daftar ONU yang sudah pernah terlihat (tabel known_devices, diisi cron/auto-summon-new.php).
 *
 * Status:
 *   new      ONU baru, ditahan: belum di-summon, menunggu dilihat/diputuskan admin (tampil "BARU" di tabel Devices)
 *   seen     ONU baru yang sudah ditandai "dilihat" tanpa di-summon
 *   ok       sudah di-summon dan membalas
 *   pending  menunggu auto-summon (hanya mode --auto)
 *   fail     auto-summon belum dibalas ONU
 *   seeded   sudah ada sebelum fitur ini aktif
 *   skipped  bukan ONU (router MikroTik)
 */
class KnownDevices
{
    public static function ensureTable($conn): void
    {
        $conn->query("CREATE TABLE IF NOT EXISTS `known_devices` (
            `device_id` varchar(191) NOT NULL,
            `first_seen` datetime NOT NULL,
            `status` varchar(10) NOT NULL DEFAULT 'pending',
            `attempts` int(11) NOT NULL DEFAULT 0,
            `last_attempt` datetime NULL DEFAULT NULL,
            `summoned_at` datetime NULL DEFAULT NULL,
            `note` varchar(150) NULL DEFAULT NULL,
            PRIMARY KEY (`device_id`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** ONU baru yang ditahan (belum di-summon), yang terbaru di atas. */
    public static function listNew($conn, int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        $out = [];
        $res = $conn->query("SELECT device_id, first_seen FROM known_devices WHERE status = 'new' ORDER BY first_seen DESC LIMIT " . $limit);
        while ($res && ($row = $res->fetch_assoc())) {
            $out[] = ['device_id' => $row['device_id'], 'first_seen' => $row['first_seen']];
        }
        return $out;
    }

    /** Sudah di-summon dan membalas: ONU baru / pending / gagal menjadi 'ok'. Mengembalikan jumlah baris yang berubah. */
    public static function markSummoned($conn, array $ids): int
    {
        return self::update(
            $conn, $ids,
            "UPDATE known_devices SET status='ok', summoned_at=NOW(), note=NULL
              WHERE status IN ('new','pending','fail') AND device_id IN (%s)"
        );
    }

    /** Tandai "sudah dilihat" tanpa summon: hanya ONU berstatus 'new' yang berubah. */
    public static function acknowledge($conn, array $ids): int
    {
        return self::update(
            $conn, $ids,
            "UPDATE known_devices SET status='seen', note='ditandai sudah dilihat'
              WHERE status = 'new' AND device_id IN (%s)"
        );
    }

    private static function update($conn, array $ids, string $sqlTemplate): int
    {
        $ids = array_values(array_unique(array_filter($ids, function ($id) {
            return is_string($id) && $id !== '';
        })));
        if (!$ids) {
            return 0;
        }
        self::ensureTable($conn);

        $changed = 0;
        foreach (array_chunk($ids, 400) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $conn->prepare(sprintf($sqlTemplate, $marks));
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param(str_repeat('s', count($chunk)), ...$chunk);
            $stmt->execute();
            $changed += max(0, (int)$stmt->affected_rows);
            $stmt->close();
        }
        return $changed;
    }
}