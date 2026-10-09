<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';

// Check if GenieACS is configured
$genieacsConfigured = isGenieACSConfigured();

include __DIR__ . '/views/layouts/header.php';
?>

<?php if (!$genieacsConfigured): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i>
        GenieACS belum dikonfigurasi. Silakan konfigurasi terlebih dahulu di
        <a href="/configuration.php">halaman Configuration</a>.
    </div>
<?php else: ?>
    <!-- Stats Cards -->
    <div class="stats-grid" id="stats-container">
        <a class="stat-card primary" style="text-decoration: none; color: inherit; cursor: pointer;" onclick="window.location.href='/devices.php'">
            <div class="stat-info">
                <h3 id="stat-total">-</h3>
                <p>Total Devices</p>
            </div>
            <div class="stat-icon">
                <i class="bi bi-router"></i>
            </div>
        </a>

        <a class="stat-card success" style="text-decoration: none; color: inherit; cursor: pointer;" onclick="window.location.href='/devices.php?status=online'">
            <div class="stat-info">
                <h3 id="stat-online">-</h3>
                <p>Online</p>
            </div>
            <div class="stat-icon">
                <i class="bi bi-check-circle"></i>
            </div>
        </a>

        <a class="stat-card danger" style="text-decoration: none; color: inherit; cursor: pointer;" onclick="window.location.href='/devices.php?status=offline'">
            <div class="stat-info">
                <h3 id="stat-offline">-</h3>
                <p>Offline</p>
            </div>
            <div class="stat-icon">
                <i class="bi bi-x-circle"></i>
            </div>
        </a>

        <div class="stat-card warning">
            <div class="stat-info">
                <h3 id="stat-uptime">-</h3>
                <p>Avg Uptime</p>
            </div>
            <div class="stat-icon">
                <i class="bi bi-clock-history"></i>
            </div>
        </div>
    </div>

<!-- Riwayat CPE Online/Offline (garis + area) -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-end gap-3 mb-1" style="font-size: 12px;">
                    <span><i class="bi bi-circle-fill" style="color: rgb(28, 180, 120); font-size: 9px;"></i> Online</span>
                    <span><i class="bi bi-circle-fill" style="color: rgb(231, 74, 59); font-size: 9px;"></i> Offline</span>
                </div>
                <div id="historyChartWrap" style="position: relative; width: 100%; height: 320px;">
                    <canvas id="historyChart"></canvas>
                    <div id="historyEmpty" class="text-muted text-center" style="display:none; position:absolute; inset:0; padding-top:110px;">
                        <i class="bi bi-hourglass-split"></i> Riwayat baru mulai dikumpulkan.<br>
                        <small>Grafik terisi seiring waktu (satu titik tiap 30 menit).</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>


<!-- Summon Confirmation Modal -->
<div class="modal fade" id="summonModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-lightning-charge"></i> Konfirmasi Summon Device
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <i class="bi bi-exclamation-triangle" style="font-size: 3rem; color: var(--warning-color);"></i>
                <h5 class="mt-3">Summon Device?</h5>
                <p class="text-muted mb-0">Apakah Anda yakin ingin melakukan connection request ke device ini?</p>
                <p class="text-muted mb-0"><small>Device ID: <strong id="summon-device-id"></strong></small></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> Batal
                </button>
                <button type="button" class="btn btn-primary" onclick="confirmSummon()">
                    <i class="bi bi-lightning-charge"></i> Ya, Summon
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Not In Map Alert Modal -->
<div class="modal fade" id="notInMapModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-circle"></i> ONU Belum Terdaftar
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <i class="bi bi-map" style="font-size: 3rem; color: var(--secondary-color);"></i>
                <h5 class="mt-3">ONU Belum Terdaftar di Map</h5>
                <p class="text-muted mb-2">Device dengan Serial Number <strong id="not-in-map-serial"></strong> belum terdaftar di Network Map.</p>
                <p class="text-muted mb-0"><small>Silakan tambahkan ONU ini ke map terlebih dahulu untuk melihat lokasi topologi.</small></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> Tutup
                </button>
                <button type="button" class="btn btn-primary" onclick="window.open('/map.php', '_blank')">
                    <i class="bi bi-map"></i> Buka Network Map
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
let dashboardFetchInProgress = false;
let recentDevicesFetchInProgress = false;
let historyChart = null;
const historyRange = '10d'; // data per 30 menit, maksimal 10 hari terakhir
let historyFetchInProgress = false;

async function loadDashboardData() {
    // Prevent concurrent requests
    if (dashboardFetchInProgress) {
        console.debug('[DASHBOARD] Stats fetch already in progress, skipping...');
        return;
    }

    dashboardFetchInProgress = true;
    try {
        const result = await fetchAPI('/api/dashboard-stats.php', { timeout: 25000 });

        if (result && result.success) {
            const stats = result.stats;

            document.getElementById('stat-total').textContent = stats.total;
            document.getElementById('stat-online').textContent = stats.online;
            document.getElementById('stat-offline').textContent = stats.offline;

            // Calculate percentage
            const onlinePercentage = stats.total > 0 ? Math.round((stats.online / stats.total) * 100) : 0;
            document.getElementById('stat-uptime').textContent = onlinePercentage + '%';

        } else {
            if (result && result.error !== 'timeout') {
                showToast('Gagal memuat data dashboard', 'danger');
            }
        }
    } catch (error) {
        if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
            console.error('Error loading dashboard:', error);
        }
    } finally {
        dashboardFetchInProgress = false;
    }
}

// ---------------------------------------------------------------------------
// GRAFIK RIWAYAT: garis halus + area hijau (online) dan merah (offline)
// ---------------------------------------------------------------------------
const DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const HISTORY_STEP = 1800;               // satu titik data tiap 30 menit (jam genap)
const HISTORY_LABEL_PX = 72;             // lebar minimal per label X supaya tidak rapat
const HISTORY_MIN_SPAN = 24 * 3600;      // jendela minimal 24 jam (sisi kiri kosong kalau data belum ada)
const HISTORY_MAX_SLOTS = 480;           // batas tampilan 10 hari
const HISTORY_TICK_STEPS = [4, 8, 12, 24, 48].map(h => h * 3600);  // label tiap 4 jam, melebar kalau data panjang

let historyTickStep = HISTORY_TICK_STEPS[0];
let historyPoints = [];

function pad2(n) { return String(n).padStart(2, '0'); }

// Label sumbu dan tooltip: "Fri 08:30" (24 jam)
function historyTickLabel(value) {
    const d = new Date(value * 1000);
    return DAY_NAMES[d.getDay()] + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
}

// Jendela waktu: ujung kanan = slot 30 menit saat ini, jadi grafik bergeser ke kiri seiring waktu.
// Lebar jendela = 24 jam, atau selebar data kalau sudah lebih panjang (maks 10 hari).
function historyWindow(points, width) {
    const nowSlot = Math.floor(Date.now() / 1000 / HISTORY_STEP) * HISTORY_STEP;
    const max = Math.max(nowSlot, Math.floor(points[points.length - 1].t / HISTORY_STEP) * HISTORY_STEP);
    const dataSpan = Math.min(max - points[0].t + HISTORY_STEP, HISTORY_MAX_SLOTS * HISTORY_STEP);
    const span = Math.max(dataSpan, HISTORY_MIN_SPAN);
    const fit = Math.max(Math.floor(width / HISTORY_LABEL_PX), 3);   // jumlah label yang muat tanpa rapat
    let step = HISTORY_TICK_STEPS[HISTORY_TICK_STEPS.length - 1];
    for (let i = 0; i < HISTORY_TICK_STEPS.length; i++) {
        if (span / HISTORY_TICK_STEPS[i] <= fit) { step = HISTORY_TICK_STEPS[i]; break; }
    }
    return { min: max - span, max: max, step: step };
}

// Penanda X pada jam genap waktu lokal (00:00, 04:00, 08:00, ...), jadi label ikut bergeser bersama waktu
function buildHistoryTicks(scale) {
    const step = historyTickStep;
    const tzOff = -new Date(scale.max * 1000).getTimezoneOffset() * 60;
    const ticks = [];
    for (let t = Math.ceil((scale.min + tzOff) / step) * step - tzOff; t <= scale.max; t += step) {
        ticks.push({ value: t });
    }
    scale.ticks = ticks;
}

// Tanpa garis bantu vertikal (hanya tanda kecil di sumbu); pergantian hari diberi garis tipis
function historyGridColor(ctx) {
    if (!ctx.tick) return 'rgba(0,0,0,0)';
    return new Date(ctx.tick.value * 1000).getHours() === 0 ? 'rgba(100,116,139,0.35)' : 'rgba(0,0,0,0)';
}

// Garis vertikal mengikuti titik yang sedang disorot, supaya jelas waktu mana yang sedang dibaca
const historyCrosshairPlugin = {
    id: 'historyCrosshair',
    afterDatasetsDraw(chart) {
        const active = chart.tooltip && chart.tooltip.getActiveElements ? chart.tooltip.getActiveElements() : [];
        if (!active.length) return;
        const x = active[0].element.x;
        const area = chart.chartArea;
        const ctx = chart.ctx;
        ctx.save();
        ctx.beginPath();
        ctx.moveTo(x, area.top);
        ctx.lineTo(x, area.bottom);
        ctx.lineWidth = 1;
        ctx.strokeStyle = 'rgba(100,116,139,0.55)';
        ctx.setLineDash([4, 3]);
        ctx.stroke();
        ctx.restore();
    }
};

// Gradasi dari warna pekat di garis ke transparan di dasar, supaya terlihat "fill" halus
function makeAreaGradient(context, rgb) {
    const chart = context.chart;
    const area = chart.chartArea;
    if (!area) return 'rgba(' + rgb + ', 0.25)';
    const g = chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
    g.addColorStop(0, 'rgba(' + rgb + ', 0.55)');
    g.addColorStop(1, 'rgba(' + rgb + ', 0.04)');
    return g;
}

async function loadStatusHistory() {
    if (historyFetchInProgress) return;
    historyFetchInProgress = true;
    try {
        const result = await fetchAPI('/api/get-status-history.php?range=' + historyRange, { timeout: 25000 });
        if (result && result.success) {
            updateHistoryChart(result.points || []);
        }
    } catch (e) {
        console.debug('[DASHBOARD] history error', e);
    } finally {
        historyFetchInProgress = false;
    }
}

// Hover tanpa menghitung koordinat kursor sama sekali.
// Style body memakai "zoom: 80%" sehingga koordinat kursor (clientX/offsetX) meleset di browser dan Chart.js
// (kursor di kanan, data yang tampil di kiri). Jadi di atas grafik dipasang zona transparan, satu per titik data,
// dan browser sendiri yang menentukan zona mana yang sedang di bawah kursor (hit-test DOM selalu benar).
function historyZoneSignature(chart) {
    const a = chart.chartArea, x = chart.scales.x;
    return [a.left, a.right, a.top, a.bottom, historyPoints.length,
            historyPoints.length ? historyPoints[historyPoints.length - 1].t : 0, x.min, x.max].join('|');
}

function rebuildHistoryHoverZones(chart) {
    const wrap = document.getElementById('historyChartWrap');
    if (!wrap || !chart.chartArea) return;
    let box = document.getElementById('historyHover');
    if (!box) {
        box = document.createElement('div');
        box.id = 'historyHover';
        box.style.cssText = 'position:absolute; inset:0; z-index:2;';
        wrap.appendChild(box);
        box.addEventListener('mouseover', ev => {
            const i = ev.target && ev.target.dataset ? ev.target.dataset.i : undefined;
            if (i === undefined) { setHistoryHover(chart, -1); } else { setHistoryHover(chart, parseInt(i, 10)); }
        });
        box.addEventListener('mouseleave', () => setHistoryHover(chart, -1));
    }
    const sig = historyZoneSignature(chart);
    if (box.dataset.sig === sig) return;      // tidak berubah, jangan bangun ulang (supaya hover tidak berkedip)
    box.dataset.sig = sig;
    box.innerHTML = '';

    const pts = historyPoints;
    if (pts.length < 2) return;
    const area = chart.chartArea;
    const xs = chart.scales.x;
    const px = pts.map(p => xs.getPixelForValue(p.t));
    const frag = document.createDocumentFragment();
    for (let i = 0; i < pts.length; i++) {
        let l = i === 0 ? px[0] - (px[1] - px[0]) / 2 : (px[i - 1] + px[i]) / 2;
        let r = i === pts.length - 1 ? px[i] + (px[i] - px[i - 1]) / 2 : (px[i] + px[i + 1]) / 2;
        l = Math.max(l, area.left);
        r = Math.min(r, area.right);
        if (r <= l) continue;
        const d = document.createElement('div');
        d.dataset.i = i;
        d.style.cssText = 'position:absolute; top:' + area.top + 'px; height:' + (area.bottom - area.top) +
                          'px; left:' + l + 'px; width:' + (r - l) + 'px;';
        frag.appendChild(d);
    }
    box.appendChild(frag);
}

let historyHoverIndex = -1;
function setHistoryHover(chart, i) {
    if (i === historyHoverIndex) return;
    historyHoverIndex = i;
    if (i < 0) {
        chart.setActiveElements([]);
        chart.tooltip.setActiveElements([], { x: 0, y: 0 });
    } else {
        const els = chart.data.datasets.map((d, di) => ({ datasetIndex: di, index: i }));
        const el = chart.getDatasetMeta(0).data[i];
        chart.setActiveElements(els);
        chart.tooltip.setActiveElements(els, { x: el ? el.x : 0, y: el ? el.y : 0 });
    }
    chart.update('none');
}

const historyHoverZonesPlugin = {
    id: 'historyHoverZones',
    afterUpdate(chart) { rebuildHistoryHoverZones(chart); }
};

function applyHistoryWindow(chart) {
    if (!historyPoints.length) return;
    const w = historyWindow(historyPoints, chart.width || chart.canvas.parentNode.clientWidth);
    historyTickStep = w.step;
    chart.options.scales.x.min = w.min;
    chart.options.scales.x.max = w.max;
}

function updateHistoryChart(points) {
    const canvas = document.getElementById('historyChart');
    const empty = document.getElementById('historyEmpty');
    const wrap = document.getElementById('historyChartWrap');
    if (!canvas || !wrap) return;

    if (!points || points.length < 2) {
        if (historyChart) { historyChart.destroy(); historyChart = null; }
        historyPoints = [];
        historyHoverIndex = -1;
        const hz = document.getElementById('historyHover');
        if (hz) { hz.innerHTML = ''; hz.dataset.sig = ''; }
        canvas.style.display = 'none';
        if (empty) empty.style.display = 'block';
        return;
    }
    canvas.style.display = 'block';
    if (empty) empty.style.display = 'none';

    historyPoints = points;
    const online = points.map(p => ({ x: p.t, y: p.online }));
    const offline = points.map(p => ({ x: p.t, y: p.offline }));

    if (historyChart) {
        historyChart.data.datasets[0].data = online;
        historyChart.data.datasets[1].data = offline;
        applyHistoryWindow(historyChart);
        historyChart.update('none');
        return;
    }

    const win = historyWindow(points, wrap.clientWidth);
    historyTickStep = win.step;

    historyChart = new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: {
            datasets: [
                {
                    label: 'Online',
                    data: online,
                    borderColor: 'rgb(28, 180, 120)',
                    backgroundColor: 'rgba(76, 175, 80, 0.22)',
                    fill: true,
                    tension: 0.4,           // garis halus
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 4
                },
                {
                    label: 'Offline',
                    data: offline,
                    borderColor: 'rgb(231, 74, 59)',
                    backgroundColor: 'rgba(231, 74, 59, 0.18)',
                    fill: true,
                    tension: 0.4,
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            parsing: false,
            normalized: true,
            events: [],   // hover diurus lewat zona DOM (historyHoverZonesPlugin) karena body memakai CSS zoom 80%
            layout: { padding: { right: 8 } },
            // Layar berubah ukuran: hitung ulang jendela supaya label tetap tidak rapat
            onResize(chart) { applyHistoryWindow(chart); },
            interaction: { mode: 'nearest', axis: 'x', intersect: false },
            scales: {
                x: {
                    type: 'linear',
                    min: win.min,
                    max: win.max,
                    afterBuildTicks: buildHistoryTicks,
                    grid: { color: historyGridColor, drawTicks: true, tickColor: '#94a3b8', tickLength: 6 },
                    ticks: { autoSkip: false, maxRotation: 0, minRotation: 0, font: { size: 11 }, callback: historyTickLabel }
                },
                y: { beginAtZero: true, ticks: { precision: 0, font: { size: 11 } }, grid: { color: 'rgba(148,163,184,0.2)' } }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    position: 'nearest',     // kotak tooltip di dekat kursor, bukan di tengah antar dua garis
                    caretPadding: 10,
                    callbacks: {
                        title(items) { return historyTickLabel(items[0].parsed.x); },
                        footer(items) {
                            const ds = items[0].chart.data.datasets;
                            const i = items[0].dataIndex;
                            return 'Total: ' + (ds[0].data[i].y + ds[1].data[i].y);
                        }
                    }
                }
            }
        },
        plugins: [historyCrosshairPlugin, historyHoverZonesPlugin]
    });
}

function extractIP(ipString) {
    if (!ipString || ipString === 'N/A') return 'N/A';
    const match = ipString.match(/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/);
    return match ? match[1] : 'N/A';
}

// async function loadRecentDevices() {
//     // Prevent concurrent requests
//     if (recentDevicesFetchInProgress) {
//         console.debug('[DASHBOARD] Recent devices fetch already in progress, skipping...');
//         return;
//     }

//     const container = document.getElementById('recent-devices');
//     container.innerHTML = '<div class="spinner"></div>';

//     recentDevicesFetchInProgress = true;
//     try {
//         const result = await fetchAPI('/api/recent-devices.php', { timeout: 25000 });

//         if (result && result.success) {
//             const devices = result.devices;

//             if (devices.length === 0) {
//                 container.innerHTML = '<p class="text-center text-muted">No recent device activity</p>';
//                 return;
//             }

//             // Fetch map status for all devices in parallel
//             const mapStatusPromises = devices.map(device =>
//                 fetchAPI('/api/get-onu-location.php?serial_number=' + encodeURIComponent(device.serial_number))
//                     .then(result => ({
//                         serial: device.serial_number,
//                         inMap: result && result.success && result.location && result.location.found,
//                         itemType: result?.location?.item_type || 'onu',
//                         itemId: result?.location?.onu?.id || result?.location?.server?.id || null
//                     }))
//                     .catch(() => ({ serial: device.serial_number, inMap: false, itemType: 'onu', itemId: null }))
//             );

//             const mapStatuses = await Promise.all(mapStatusPromises);
//             const mapStatusMap = {};
//             mapStatuses.forEach(status => {
//                 mapStatusMap[status.serial] = {
//                     inMap: status.inMap,
//                     itemType: status.itemType,
//                     itemId: status.itemId
//                 };
//             });

//             let html = '<div class="table-responsive"><table class="table table-hover"><thead><tr>';
//             html += '<th>SN</th>';
//             html += '<th>MAC</th>';
//             html += '<th>Tipe</th>';
//             html += '<th>IP</th>';
//             html += '<th>SSID</th>';
//             html += '<th>PPPoE</th>';
//             html += '<th>Rx</th>';
//             html += '<th>Temp</th>';
//             html += '<th>Client</th>';
//             html += '<th>Status</th>';
//             html += '<th>Action</th>';
//             html += '</tr></thead><tbody>';

//             devices.forEach(device => {
//                 const mapInfo = mapStatusMap[device.serial_number] || { inMap: false, itemType: 'onu', itemId: null };
//                 const isInMap = mapInfo.inMap;
//                 const ipAddress = extractIP(device.ip_tr069);

//                 // Create clickable IP link if IP is valid
//                 let ipDisplay;
//                 if (ipAddress !== 'N/A' && ipAddress !== '') {
//                     ipDisplay = `<a href="http://${ipAddress}" target="_blank" rel="noopener noreferrer" title="Open ${ipAddress} in new tab">${ipAddress}</a>`;
//                 } else {
//                     ipDisplay = ipAddress;
//                 }

//                 // Connected clients count with badge
//                 const clientsCount = device.connected_devices_count || 0;
//                 let clientsBadge = '';
//                 if (clientsCount > 0) {
//                     clientsBadge = `<span class="badge bg-primary">${clientsCount}</span>`;
//                 } else {
//                     clientsBadge = `<span class="badge bg-secondary">0</span>`;
//                 }

//                 // RX Power badge with color based on signal strength
//                 const rxPower = parseFloat(device.rx_power);
//                 let rxBadgeClass = 'bg-secondary'; // Default for N/A
//                 let rxDisplay = device.rx_power;

//                 if (!isNaN(rxPower) && rxPower !== -999) {
//                     if (rxPower > -20.00) {
//                         rxBadgeClass = 'bg-success'; // Green: Good signal (above -20 dBm)
//                     } else if (rxPower >= -23.00) {
//                         rxBadgeClass = 'bg-warning'; // Yellow: Moderate signal (-20 to -23 dBm)
//                     } else {
//                         rxBadgeClass = 'bg-danger'; // Red: Weak signal (below -23 dBm)
//                     }
//                     rxDisplay = `<span class="badge ${rxBadgeClass}">${device.rx_power} dBm</span>`;
//                 } else {
//                     rxDisplay = `<span class="badge ${rxBadgeClass}">N/A</span>`;
//                 }

//                 // Status badge with ping
//                 let statusBadge;
//                 if (device.status === 'online') {
//                     const ping = device.ping || '-';
//                     statusBadge = `<span class="badge online">ON [${ping}ms]</span>`;
//                 } else {
//                     statusBadge = `<span class="badge offline">OFF [-]</span>`;
//                 }

//                 // Map button - conditional based on registration status
//                 let mapButton;
//                 if (isInMap) {
//                     // Green button - opens map in new tab
//                     let mapUrl;
//                     if (mapInfo.itemType === 'mikrotik') {
//                         // For MikroTik devices, focus on server
//                         mapUrl = `/map.php?focus_type=server&focus_id=${mapInfo.itemId}`;
//                     } else {
//                         // For ONU devices, focus on ONU
//                         mapUrl = `/map.php?focus_type=onu&focus_serial=${encodeURIComponent(device.serial_number)}`;
//                     }
//                     mapButton = `<button class="btn btn-sm btn-success me-1" onclick="window.open('${mapUrl}', '_blank')" title="View on Map"><i class="bi bi-map"></i></button>`;
//                 } else {
//                     // Gray button - shows alert
//                     mapButton = `<button class="btn btn-sm btn-secondary me-1" onclick="showNotInMapAlert('${encodeURIComponent(device.serial_number)}')" title="Not Registered in Map"><i class="bi bi-map"></i></button>`;
//                 }

//                 html += '<tr>';
//                 html += `<td><a href="/device-detail.php?id=${encodeURIComponent(device.device_id)}">${device.serial_number}</a></td>`;
//                 html += `<td>${device.mac_address}</td>`;
//                 html += `<td>${device.product_class || 'N/A'}</td>`;
//                 html += `<td>${ipDisplay}</td>`;
//                 html += `<td>${device.wifi_ssid}</td>`;
//                 html += `<td>${device.pppoe_username || 'N/A'}</td>`;
//                 html += `<td>${rxDisplay}</td>`;
//                 html += `<td>${device.temperature}°C</td>`;
//                 html += `<td class="text-center">${clientsBadge}</td>`;
//                 html += `<td>${statusBadge}</td>`;
//                 html += `<td>`;
//                 html += mapButton;
//                 html += `<button class="btn btn-sm btn-primary" onclick="summonDeviceQuick('${device.device_id}')" title="Summon Device"><i class="bi bi-lightning-charge"></i></button>`;
//                 html += `</td>`;
//                 html += '</tr>';
//             });

//             html += '</tbody></table></div>';
//             container.innerHTML = html;
//         } else {
//             if (result && result.error !== 'timeout') {
//                 container.innerHTML = '<p class="text-center text-danger">Failed to load recent devices</p>';
//             } else {
//                 container.innerHTML = '<p class="text-center text-warning">Request timeout - please refresh</p>';
//             }
//         }
//     } catch (error) {
//         if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
//             console.error('Error loading recent devices:', error);
//         }
//         container.innerHTML = '<p class="text-center text-danger">Error loading data</p>';
//     } finally {
//         recentDevicesFetchInProgress = false;
//     }
// }

let currentSummonDeviceId = null;

function summonDeviceQuick(deviceId) {
    currentSummonDeviceId = deviceId;
    document.getElementById('summon-device-id').textContent = deviceId;
    const modal = new bootstrap.Modal(document.getElementById('summonModal'), {
        backdrop: false
    });
    modal.show();
}

function showNotInMapAlert(serialNumber) {
    document.getElementById('not-in-map-serial').textContent = decodeURIComponent(serialNumber);
    const modal = new bootstrap.Modal(document.getElementById('notInMapModal'), {
        backdrop: false
    });
    modal.show();
}

async function confirmSummon() {
    if (!currentSummonDeviceId) return;

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('summonModal'));
    modal.hide();

    showLoading();

    const result = await fetchAPI('/api/summon-device.php', {
        method: 'POST',
        body: JSON.stringify({ device_id: currentSummonDeviceId })
    });

    hideLoading();

    if (result && result.success) {
        showToast('Device summon berhasil!', 'success');
    } else {
        showToast(result.message || 'Gagal summon device', 'danger');
    }

    currentSummonDeviceId = null;
}

// Load data on page load
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($genieacsConfigured): ?>
        loadDashboardData();
        // Riwayat dimuat sedikit terlambat supaya snapshot terbaru dari loadDashboardData() ikut terbaca
        setTimeout(loadStatusHistory, 4000);
        loadStatusHistory();

        // Auto refresh dashboard tiap 5 menit (kartu angka + grafik)
        setInterval(() => {
            loadDashboardData();
            setTimeout(loadStatusHistory, 4000);
        }, 300000);

        // Geser grafik tepat saat slot 30 menit berganti (tanpa menunggu refresh 5 menit)
        let lastHistorySlot = Math.floor(Date.now() / 1000 / HISTORY_STEP);
        setInterval(() => {
            const slot = Math.floor(Date.now() / 1000 / HISTORY_STEP);
            if (slot !== lastHistorySlot && historyChart) {
                lastHistorySlot = slot;
                applyHistoryWindow(historyChart);
                historyChart.update('none');
            }
        }, 20000);
    <?php endif; ?>
});
</script>

<?php include __DIR__ . '/views/layouts/footer.php'; ?>
