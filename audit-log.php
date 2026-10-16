<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'Audit Log';
$currentPage = 'audit';

$conn = getDBConnection();
\App\AuditLog::ensureTable($conn);

// ---- Filter dari query string ----
$fUser   = trim($_GET['user'] ?? '');
$fAction = trim($_GET['action'] ?? '');
$fIp     = trim($_GET['ip'] ?? '');
$fQ      = trim($_GET['q'] ?? '');
$fStatus = $_GET['status'] ?? '';
$fFrom   = $_GET['from'] ?? '';
$fTo     = $_GET['to'] ?? '';
$validDate = function ($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1; };

$where = [];
$types = '';
$args = [];
if ($fUser !== '')   { $where[] = 'username = ?';            $types .= 's'; $args[] = $fUser; }
if ($fAction !== '') { $where[] = 'action LIKE ?';           $types .= 's'; $args[] = $fAction . '%'; }
if ($fIp !== '')     { $where[] = 'ip LIKE ?';               $types .= 's'; $args[] = $fIp . '%'; }
if ($fQ !== '')      { $where[] = '(target LIKE ? OR detail LIKE ? OR message LIKE ?)'; $types .= 'sss'; $like = '%' . $fQ . '%'; array_push($args, $like, $like, $like); }
if ($fStatus === 'ok')   { $where[] = 'success = 1'; }
if ($fStatus === 'fail') { $where[] = 'success = 0'; }
if ($validDate($fFrom)) { $where[] = 'created_at >= ?'; $types .= 's'; $args[] = $fFrom . ' 00:00:00'; }
if ($validDate($fTo))   { $where[] = 'created_at <= ?'; $types .= 's'; $args[] = $fTo . ' 23:59:59'; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$run = function ($sql, $types, $args) use ($conn) {
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$args);
    }
    $stmt->execute();
    return $stmt->get_result();
};

// ---- Paginasi ----
$perPage = 50;
$total = (int)$run("SELECT COUNT(*) c FROM audit_logs $whereSql", $types, $args)->fetch_assoc()['c'];
$pages = max(1, (int)ceil($total / $perPage));
$page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;

$rows = $run("SELECT * FROM audit_logs $whereSql ORDER BY id DESC LIMIT $perPage OFFSET $offset", $types, $args)->fetch_all(MYSQLI_ASSOC);

// Opsi dropdown
$users = array_column($conn->query("SELECT DISTINCT username FROM audit_logs WHERE username <> '' ORDER BY username")->fetch_all(MYSQLI_ASSOC), 'username');
$actions = array_column($conn->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetch_all(MYSQLI_ASSOC), 'action');

$e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$qs = function (array $over = []) {
    $q = array_merge($_GET, $over);
    unset($q['page']);
    if (isset($over['page'])) {
        $q['page'] = $over['page'];
    }
    return '?' . http_build_query(array_filter($q, function ($v) { return $v !== '' && $v !== null; }));
};

include __DIR__ . '/views/layouts/header.php';
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div><i class="bi bi-journal-text"></i> Audit Log
            <span class="badge bg-secondary ms-2"><?php echo number_format($total); ?> catatan</span>
        </div>
        <small class="text-muted">Disimpan 180 hari. Password tidak pernah dicatat.</small>
    </div>
    <div class="card-body">
        <form method="get" class="row g-2 mb-3">
            <div class="col-6 col-md-2">
                <select name="user" class="form-select form-select-sm">
                    <option value="">Semua user</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo $e($u); ?>" <?php echo $fUser === $u ? 'selected' : ''; ?>><?php echo $e($u); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="action" class="form-select form-select-sm">
                    <option value="">Semua aksi</option>
                    <?php foreach ($actions as $a): ?>
                        <option value="<?php echo $e($a); ?>" <?php echo $fAction === $a ? 'selected' : ''; ?>><?php echo $e($a); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2"><input name="ip" value="<?php echo $e($fIp); ?>" class="form-control form-control-sm" placeholder="IP (awalan)"></div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">Semua hasil</option>
                    <option value="ok" <?php echo $fStatus === 'ok' ? 'selected' : ''; ?>>Berhasil</option>
                    <option value="fail" <?php echo $fStatus === 'fail' ? 'selected' : ''; ?>>Gagal</option>
                </select>
            </div>
            <div class="col-6 col-md-2"><input type="date" name="from" value="<?php echo $e($fFrom); ?>" class="form-control form-control-sm" title="Dari tanggal"></div>
            <div class="col-6 col-md-2"><input type="date" name="to" value="<?php echo $e($fTo); ?>" class="form-control form-control-sm" title="Sampai tanggal"></div>
            <div class="col-12 col-md-8"><input name="q" value="<?php echo $e($fQ); ?>" class="form-control form-control-sm" placeholder="Cari di target / detail / pesan (misal serial ONU)"></div>
            <div class="col-12 col-md-4 d-flex gap-2">
                <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Filter</button>
                <a class="btn btn-sm btn-secondary" href="/audit-log.php"><i class="bi bi-x-lg"></i> Reset</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead>
                    <tr><th>Waktu</th><th>User</th><th>IP</th><th>Aksi</th><th>Target</th><th>Hasil</th><th>Detail</th></tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Belum ada catatan.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="text-nowrap"><?php echo $e($r['created_at']); ?></td>
                        <td><?php echo $e($r['username'] ?: '-'); ?><?php if ($r['role']): ?> <small class="text-muted">(<?php echo $e($r['role']); ?>)</small><?php endif; ?></td>
                        <td class="text-nowrap" title="<?php echo $e($r['user_agent']); ?>"><a href="<?php echo $e($qs(['ip' => $r['ip']])); ?>"><?php echo $e($r['ip'] ?: '-'); ?></a></td>
                        <td><?php echo $e($r['action']); ?></td>
                        <td style="max-width:220px; word-break:break-all;"><?php echo $e($r['target'] ?: '-'); ?></td>
                        <td>
                            <?php if ($r['success']): ?>
                                <span class="badge bg-success">Berhasil</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Gagal</span>
                            <?php endif; ?>
                            <?php if ($r['message']): ?><div class="small text-muted"><?php echo $e($r['message']); ?></div><?php endif; ?>
                        </td>
                        <td style="max-width:260px;">
                            <?php if ($r['detail']): ?>
                                <details><summary class="small text-primary" style="cursor:pointer;">lihat</summary>
                                    <pre class="small mb-0" style="white-space:pre-wrap; word-break:break-all;"><?php echo $e($r['detail']); ?></pre>
                                </details>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <nav><ul class="pagination pagination-sm justify-content-center mb-0">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $e($qs(['page' => $page - 1])); ?>">&laquo;</a></li>
                <li class="page-item disabled"><span class="page-link">Halaman <?php echo $page; ?> / <?php echo $pages; ?></span></li>
                <li class="page-item <?php echo $page >= $pages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $e($qs(['page' => $page + 1])); ?>">&raquo;</a></li>
            </ul></nav>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/views/layouts/footer.php'; ?>