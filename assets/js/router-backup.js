// Backup konfigurasi router MikroTik dari halaman detail device.
// Tombol #btn-backup hanya ditampilkan untuk device MikroTik (lihat loadDeviceDetail).
(function () {
    let current = { host: '' };

    const $ = id => document.getElementById(id);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const api = (body, timeout) => fetchAPI('/api/router-backup.php', {
        method: 'POST', body: JSON.stringify({ device_id: deviceId, ...body }), timeout: timeout || 15000
    });
    const fmtSize = n => n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';

    // Dipanggil dari loadDeviceDetail dengan data device
    window.initRouterBackup = function (device) {
        const isMikrotik = /mikrotik|routerboard/i.test((device.manufacturer || '') + ' ' + (device.product_class || ''));
        $('btn-backup').style.display = isMikrotik ? '' : 'none';
        const ip = (typeof extractIP === 'function' ? extractIP(device.ip_address) : device.ip_address) || '';
        current.host = /^[0-9.]+$/.test(ip) ? ip : '';
        current.name = device.serial_number || deviceId;
    };

    async function loadFiles() {
        const r = await api({ action: 'files' });
        const box = $('rb-files');
        if (!r || !r.success || !r.data.length) { box.innerHTML = '<p class="text-muted mb-0">Belum ada file backup.</p>'; return; }
        box.innerHTML = '<table class="table table-sm align-middle mb-0"><thead><tr><th>File</th><th>Ukuran</th><th>Waktu</th><th></th></tr></thead><tbody>'
            + r.data.map(f => `<tr><td>${esc(f.name)}</td><td>${fmtSize(f.size)}</td><td>${esc(f.time)}</td>
                <td><a class="btn btn-sm btn-outline-primary" href="/api/router-backup-download.php?id=${f.tid}&file=${encodeURIComponent(f.name)}"><i class="bi bi-download"></i></a></td></tr>`).join('')
            + '</tbody></table>';
    }

    function showStatus(t) {
        if (t && t.last_backup_at) {
            $('rb-status').innerHTML = `Terakhir: ${esc(t.last_backup_at)} - ` +
                (t.last_status === 'ok' ? '<span class="text-success">berhasil</span>' : `<span class="text-danger">gagal (${esc(t.last_message || '')})</span>`);
        } else $('rb-status').textContent = '';
    }

    window.openRouterBackup = async function () {
        bootstrap.Modal.getOrCreateInstance($('rbModal')).show();
        const r = await api({ action: 'get' });
        const t = r && r.success ? r.data : null;
        if (r && r.dir) $('rb-dir').textContent = r.dir;
        $('rb-host').value = t ? t.host : current.host;
        $('rb-api-port').value = t ? t.api_port : 8728;
        $('rb-ftp-port').value = t ? t.ftp_port : 21;
        $('rb-user').value = t ? t.username : '';
        $('rb-pass').value = '';
        $('rb-sens').checked = !!(t && t.show_sensitive == 1);
        $('rb-format').value = t ? t.format : 'both';
        $('rb-keep').value = t ? t.keep_count : 14;
        showStatus(t);
        loadFiles();
    };

    async function save() {
        const r = await api({
            action: 'save', name: current.name,
            host: $('rb-host').value, api_port: $('rb-api-port').value, ftp_port: $('rb-ftp-port').value,
            username: $('rb-user').value, password: $('rb-pass').value,
            format: $('rb-format').value, keep_count: $('rb-keep').value, show_sensitive: $('rb-sens').checked
        });
        if (!(r && r.success)) showToast((r && r.message) || 'Gagal menyimpan', 'danger');
        return !!(r && r.success);
    }

    window.rbSave = async function () {
        if (await save()) { showToast('Pengaturan backup disimpan', 'success'); $('rb-pass').value = ''; }
    };

    window.rbRun = async function () {
        const btn = $('rb-run-btn');
        if (!(await save())) return;
        btn.disabled = true;
        const old = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Proses...';
        const r = await api({ action: 'run' }, 120000);
        btn.disabled = false; btn.innerHTML = old;
        showToast((r && r.message) || 'Tidak ada respon', r && r.success ? 'success' : 'danger');
        const g = await api({ action: 'get' });
        showStatus(g && g.success ? g.data : null);
        loadFiles();
    };
})();