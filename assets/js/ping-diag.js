// Ping diagnostic dari ONU (TR-069 IPPingDiagnostics).
// Alur: start -> ONU menerima perintah -> poll hasil tiap 3 detik sampai state bukan "Requested".
(function () {
    const $ = id => document.getElementById(id);
    const api = (body, timeout) => fetchAPI('/api/ping-diag.php', {
        method: 'POST', body: JSON.stringify({ device_id: deviceId, ...body }), timeout: timeout || 20000
    });

    let polling = false;     // true selama ada ping yang sedang ditunggu
    let runToken = 0;        // menandai run aktif; modal ditutup / run baru -> run lama berhenti

    // Dipanggil dari loadDeviceDetail: tombol Ping tidak ditampilkan untuk MikroTik (TR-069 MikroTik tidak punya diagnostic ping)
    window.initPingDiag = function (device) {
        const isMikrotik = /mikrotik|routerboard/i.test((device.manufacturer || '') + ' ' + (device.product_class || ''));
        const btn = $('btn-ping');
        if (btn) btn.style.display = isMikrotik ? 'none' : '';
    };

    window.openPingDiag = function () {
        const modalEl = $('pingModal');
        if (!modalEl.dataset.bound) {
            modalEl.dataset.bound = '1';
            modalEl.querySelectorAll('[data-ping-host]').forEach(b => {
                b.addEventListener('click', () => { $('ping-host').value = b.dataset.pingHost; });
            });
            modalEl.addEventListener('hidden.bs.modal', () => { runToken++; polling = false; setBusy(false); });
        }
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    };

    function setBusy(busy) {
        const btn = $('ping-start-btn');
        if (!btn) return;
        btn.disabled = busy;
        btn.innerHTML = busy
            ? '<span class="spinner-border spinner-border-sm"></span> Berjalan...'
            : '<i class="bi bi-play-fill"></i> Mulai Ping';
    }

    function setStatus(text, cls) {
        const el = $('ping-status');
        el.className = 'mb-2 small ' + (cls || 'text-muted');
        el.textContent = text || '';
    }

    function showResult(r) {
        $('ping-result').style.display = '';
        const sent = r.sent, ok = r.success, fail = r.failed;
        $('ping-r-sent').textContent = (sent != null && ok != null) ? `${sent} / ${ok}` : '-';
        // Packet loss dihitung dari jumlah yang dikirim, bukan dari FailureCount saja
        let loss = '-';
        if (sent && ok != null) loss = Math.round(((sent - ok) / sent) * 100) + '%';
        else if (ok != null && fail != null && (ok + fail) > 0) loss = Math.round((fail / (ok + fail)) * 100) + '%';
        $('ping-r-loss').textContent = loss;
        const ms = v => (v == null ? '-' : v + ' ms');
        $('ping-r-min').textContent = ok ? ms(r.min_ms) : '-';
        $('ping-r-avg').textContent = ok ? ms(r.avg_ms) : '-';
        $('ping-r-max').textContent = ok ? ms(r.max_ms) : '-';
    }

    const sleep = ms => new Promise(res => setTimeout(res, ms));

    window.startPingDiag = async function () {
        if (polling) return;
        const host = $('ping-host').value.trim();
        if (!host) { setStatus('Isi tujuan ping dulu.', 'text-danger'); return; }

        const token = ++runToken;
        polling = true;
        setBusy(true);
        $('ping-result').style.display = 'none';
        setStatus('Mengirim perintah ke ONU...');

        const start = await api({
            action: 'start',
            host,
            count: parseInt($('ping-count').value, 10) || 4,
            size: parseInt($('ping-size').value, 10) || 64,
            timeout_ms: parseInt($('ping-timeout').value, 10) || 2000,
            interface: $('ping-interface').value.trim(),
        }, 20000);

        if (token !== runToken) return;
        if (!start || !start.success) {
            setStatus((start && start.message) || 'Gagal memulai ping', 'text-danger');
            polling = false; setBusy(false);
            return;
        }

        setStatus('ONU sedang ping ke ' + host + '...');
        const waitSec = start.expected_seconds || 10;
        const deadline = Date.now() + (waitSec + 25) * 1000;   // batas toleransi di atas perkiraan
        await sleep(Math.min(3000, waitSec * 1000));

        let lastOk = null;
        while (token === runToken && Date.now() < deadline) {
            const r = await api({ action: 'result' }, 20000);
            if (token !== runToken) return;

            if (r && r.success) {
                lastOk = r;
                if (r.done) {
                    if (r.state === 'Complete') {
                        showResult(r.result);
                        const ok = r.result.success;
                        setStatus(ok ? 'Selesai.' : 'Selesai, tapi tidak ada balasan dari ' + host + '.', ok ? 'text-success' : 'text-warning');
                    } else {
                        setStatus(r.message || ('ONU melaporkan: ' + r.state), 'text-danger');
                    }
                    polling = false; setBusy(false);
                    return;
                }
            }
            await sleep(3000);
        }

        if (token !== runToken) return;
        setStatus(lastOk
            ? 'Hasil belum keluar dari ONU (masih Requested). Coba lagi atau kurangi jumlah / timeout ping.'
            : 'Tidak bisa mengambil hasil dari ONU. Coba lagi.', 'text-warning');
        polling = false; setBusy(false);
    };
})();