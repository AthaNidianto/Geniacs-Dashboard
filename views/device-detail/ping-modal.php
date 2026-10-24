<!-- Ping Diagnostic Modal (jalan di ONU lewat TR-069, bukan dari server dashboard) -->
<div class="modal fade" id="pingModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-activity"></i> Ping Diagnostic</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">
                    Ping dijalankan <strong>dari ONU</strong> (lewat TR-069), jadi hasilnya menunjukkan kondisi koneksi ONU ke tujuan, bukan dari server dashboard.
                </p>

                <div class="row g-2 mb-2">
                    <div class="col-md-6">
                        <label class="form-label">Tujuan (IP / domain)</label>
                        <input id="ping-host" class="form-control" placeholder="8.8.8.8" autocomplete="off">
                        <div class="mt-1">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-ping-host="8.8.8.8">8.8.8.8</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-ping-host="1.1.1.1">1.1.1.1</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-ping-host="google.com">google.com</button>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Jumlah</label>
                        <input id="ping-count" type="number" min="1" max="10" value="4" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Ukuran (B)</label>
                        <input id="ping-size" type="number" min="32" max="1400" value="64" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Timeout (ms)</label>
                        <input id="ping-timeout" type="number" min="500" max="10000" value="2000" class="form-control">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Interface ONU <span class="text-muted small">(opsional, kosongkan = pakai rute default ONU)</span></label>
                    <input id="ping-interface" class="form-control" placeholder="InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1" autocomplete="off">
                </div>

                <div id="ping-status" class="mb-2 small text-muted"></div>

                <div id="ping-result" style="display:none;">
                    <div class="row g-2 text-center">
                        <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="text-muted small">Terkirim / Balas</div><div class="fs-5 fw-bold" id="ping-r-sent">-</div></div></div>
                        <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="text-muted small">Packet loss</div><div class="fs-5 fw-bold" id="ping-r-loss">-</div></div></div>
                        <div class="col-4 col-md-2"><div class="border rounded p-2"><div class="text-muted small">Min</div><div class="fs-5 fw-bold" id="ping-r-min">-</div></div></div>
                        <div class="col-4 col-md-2"><div class="border rounded p-2"><div class="text-muted small">Rata-rata</div><div class="fs-5 fw-bold" id="ping-r-avg">-</div></div></div>
                        <div class="col-4 col-md-2"><div class="border rounded p-2"><div class="text-muted small">Max</div><div class="fs-5 fw-bold" id="ping-r-max">-</div></div></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Tutup</button>
                <button type="button" class="btn btn-primary" id="ping-start-btn" onclick="startPingDiag()"><i class="bi bi-play-fill"></i> Mulai Ping</button>
            </div>
        </div>
    </div>
</div>