<!-- Router Backup Modal (hanya dipakai untuk MikroTik) -->
<div class="modal fade" id="rbModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cloud-download"></i> Backup Konfigurasi Router</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">
                    Dashboard login ke router lewat API, membuat file backup, lalu mengambilnya lewat FTP.
                    Pakai user router khusus (policy <code>api, ftp, read, test</code>) yang dibatasi ke IP server dashboard.
                    Hasil disimpan di <code id="rb-dir">-</code>.
                </p>

                <div class="row g-2 mb-2">
                    <div class="col-md-6"><label class="form-label">Host / IP router</label><input id="rb-host" class="form-control" placeholder="10.10.10.1"></div>
                    <div class="col-md-3"><label class="form-label">Port API</label><input id="rb-api-port" class="form-control" value="8728"></div>
                    <div class="col-md-3"><label class="form-label">Port FTP</label><input id="rb-ftp-port" class="form-control" value="21"></div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-md-6"><label class="form-label">Username</label><input id="rb-user" class="form-control" autocomplete="off"></div>
                    <div class="col-md-6"><label class="form-label">Password</label><input id="rb-pass" type="password" class="form-control" autocomplete="new-password" placeholder="(kosong = tidak diubah)"></div>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="rb-sens">
                    <label class="form-check-label" for="rb-sens">Sertakan password di file .rsc <span class="text-muted small">(setara <code>export show-sensitive</code>; user router perlu policy <code>sensitive</code>)</span></label>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Format</label>
                        <select id="rb-format" class="form-select">
                            <option value="both">.rsc + .backup</option>
                            <option value="rsc">.rsc (teks)</option>
                            <option value="backup">.backup (biner)</option>
                        </select>
                    </div>
                    <div class="col-md-6"><label class="form-label">Simpan berapa backup</label><input id="rb-keep" type="number" min="1" max="100" class="form-control" value="14"></div>
                </div>

                <div class="d-flex gap-2 mb-3 align-items-center">
                    <button class="btn btn-primary" onclick="rbSave()"><i class="bi bi-save"></i> Simpan</button>
                    <button class="btn btn-success" id="rb-run-btn" onclick="rbRun()"><i class="bi bi-play-fill"></i> Backup Sekarang</button>
                    <span id="rb-status" class="small text-muted ms-2"></span>
                </div>

                <h6>File backup</h6>
                <div id="rb-files"><p class="text-muted mb-0">Memuat...</p></div>
            </div>
        </div>
    </div>
</div>