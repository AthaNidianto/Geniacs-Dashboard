<div class="card mt-3">
    <div class="card-header">
        <i class="bi bi-cloud-download"></i> Backup Konfigurasi Router MikroTik
        <button class="btn btn-sm btn-primary float-end" onclick="rbEdit()"><i class="bi bi-plus-lg"></i> Tambah Router</button>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Dashboard login ke router lewat API, membuat file backup, lalu mengambilnya lewat FTP.
            Pakai user router khusus (policy: <code>api, ftp, read, test</code>) dan batasi dengan <code>address=IP server dashboard</code>.
            Hasil disimpan di <code id="rb-dir">-</code>.
        </p>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle text-center mb-0">
                <thead>
                    <tr><th>Nama</th><th>Host</th><th>Format</th><th>Backup terakhir</th><th>Status</th><th>Aksi</th></tr>
                </thead>
                <tbody id="rb-body"><tr><td colspan="6" class="text-muted">Memuat...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal tambah/edit -->
<div class="modal fade" id="rbEditModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Router Backup</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="rb-id">
                <div class="mb-2"><label class="form-label">Nama</label><input id="rb-name" class="form-control" placeholder="RB-Pusat"></div>
                <div class="row g-2 mb-2">
                    <div class="col-6"><label class="form-label">Host / IP</label><input id="rb-host" class="form-control" placeholder="10.10.10.1"></div>
                    <div class="col-3"><label class="form-label">Port API</label><input id="rb-api-port" class="form-control" value="8728"></div>
                    <div class="col-3"><label class="form-label">Port FTP</label><input id="rb-ftp-port" class="form-control" value="21"></div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6"><label class="form-label">Username</label><input id="rb-user" class="form-control" autocomplete="off"></div>
                    <div class="col-6"><label class="form-label">Password</label><input id="rb-pass" type="password" class="form-control" autocomplete="new-password" placeholder="(kosong = tidak diubah)"></div>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="rb-sens">
                    <label class="form-check-label" for="rb-sens">Sertakan password di file .rsc <span class="text-muted small">(setara <code>export show-sensitive</code>; user router perlu policy <code>sensitive</code>)</span></label>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label">Format</label>
                        <select id="rb-format" class="form-select">
                            <option value="both">.rsc + .backup</option>
                            <option value="rsc">.rsc (teks)</option>
                            <option value="backup">.backup (biner)</option>
                        </select>
                    </div>
                    <div class="col-6"><label class="form-label">Simpan berapa backup</label><input id="rb-keep" type="number" min="1" max="100" class="form-control" value="14"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button class="btn btn-primary" onclick="rbSave()">Simpan</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal daftar file -->
<div class="modal fade" id="rbFilesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="rb-files-title">File backup</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="rb-files-body"></div>
        </div>
    </div>
</div>

<script>
(function () {
    let targets = [];
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const modal = id => bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
    const api = (body, timeout) => fetchAPI('/api/router-backup.php', { method: 'POST', body: JSON.stringify(body), timeout: timeout || 15000 });
    const fmtSize = n => n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';

    async function load() {
        const r = await api({ action: 'list' });
        const body = document.getElementById('rb-body');
        if (!r || !r.success) { body.innerHTML = '<tr><td colspan="6" class="text-danger">Gagal memuat</td></tr>'; return; }
        targets = r.data;
        document.getElementById('rb-dir').textContent = r.dir;
        if (!targets.length) { body.innerHTML = '<tr><td colspan="6" class="text-muted">Belum ada router. Klik "Tambah Router".</td></tr>'; return; }
        body.innerHTML = targets.map(t => {
            const badge = t.last_status === 'ok' ? '<span class="badge bg-success">OK</span>'
                : t.last_status === 'fail' ? `<span class="badge bg-danger" title="${esc(t.last_message)}">Gagal</span>` : '<span class="badge bg-secondary">Belum</span>';
            return `<tr>
                <td>${esc(t.name)}</td><td>${esc(t.host)}</td><td>${esc(t.format)}</td>
                <td>${esc(t.last_backup_at || '-')}</td><td>${badge}</td>
                <td>
                    <button class="btn btn-sm btn-success" onclick="rbRun(${t.id}, this)"><i class="bi bi-play-fill"></i> Backup</button>
                    <button class="btn btn-sm btn-outline-primary" onclick="rbFiles(${t.id})"><i class="bi bi-folder2-open"></i> File</button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="rbEdit(${t.id})"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger" onclick="rbDelete(${t.id})"><i class="bi bi-trash"></i></button>
                </td></tr>`;
        }).join('');
    }

    window.rbEdit = function (id) {
        const t = targets.find(x => x.id == id) || {};
        document.getElementById('rb-id').value = t.id || '';
        document.getElementById('rb-name').value = t.name || '';
        document.getElementById('rb-host').value = t.host || '';
        document.getElementById('rb-api-port').value = t.api_port || 8728;
        document.getElementById('rb-ftp-port').value = t.ftp_port || 21;
        document.getElementById('rb-user').value = t.username || '';
        document.getElementById('rb-pass').value = '';
        document.getElementById('rb-format').value = t.format || 'both';
        document.getElementById('rb-keep').value = t.keep_count || 14;
        document.getElementById('rb-sens').checked = t.show_sensitive == 1;
        modal('rbEditModal').show();
    };

    window.rbSave = async function () {
        const r = await api({
            action: 'save', id: document.getElementById('rb-id').value,
            name: document.getElementById('rb-name').value, host: document.getElementById('rb-host').value,
            api_port: document.getElementById('rb-api-port').value, ftp_port: document.getElementById('rb-ftp-port').value,
            username: document.getElementById('rb-user').value, password: document.getElementById('rb-pass').value,
            format: document.getElementById('rb-format').value, keep_count: document.getElementById('rb-keep').value, show_sensitive: document.getElementById('rb-sens').checked
        });
        if (r && r.success) { modal('rbEditModal').hide(); showToast(r.message, 'success'); load(); }
        else showToast((r && r.message) || 'Gagal menyimpan', 'danger');
    };

    window.rbDelete = async function (id) {
        if (!confirm('Hapus router ini dari daftar backup? (file backup yang sudah ada tidak dihapus)')) return;
        const r = await api({ action: 'delete', id });
        if (r && r.success) { showToast('Dihapus', 'success'); load(); } else showToast('Gagal menghapus', 'danger');
    };

    window.rbRun = async function (id, btn) {
        btn.disabled = true;
        const old = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Proses...';
        const r = await api({ action: 'run', id }, 120000);
        btn.disabled = false; btn.innerHTML = old;
        showToast((r && r.message) || 'Tidak ada respon', r && r.success ? 'success' : 'danger');
        load();
    };

    window.rbFiles = async function (id) {
        const t = targets.find(x => x.id == id);
        document.getElementById('rb-files-title').textContent = 'File backup - ' + (t ? t.name : '');
        const r = await api({ action: 'files', id });
        const body = document.getElementById('rb-files-body');
        if (!r || !r.success || !r.data.length) body.innerHTML = '<p class="text-muted mb-0">Belum ada file backup.</p>';
        else body.innerHTML = '<table class="table table-sm align-middle mb-0"><thead><tr><th>File</th><th>Ukuran</th><th>Waktu</th><th></th></tr></thead><tbody>'
            + r.data.map(f => `<tr><td>${esc(f.name)}</td><td>${fmtSize(f.size)}</td><td>${esc(f.time)}</td>
                <td><a class="btn btn-sm btn-outline-primary" href="/api/router-backup-download.php?file=${encodeURIComponent(f.name)}"><i class="bi bi-download"></i></a></td></tr>`).join('')
            + '</tbody></table>';
        modal('rbFilesModal').show();
    };

    document.addEventListener('DOMContentLoaded', load);
})();
</script>