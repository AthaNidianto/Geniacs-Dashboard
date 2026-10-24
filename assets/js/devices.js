// Helper anti-XSS. Didefinisikan juga di sini supaya halaman tetap jalan walau main.js versi lama
// masih ter-cache di browser (main.js memuat versi yang sama).
if (typeof window.escapeHtml !== 'function') {
    window.escapeHtml = function (v) {
        return String(v === null || v === undefined ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };
}
if (typeof window.escapeAttrJson !== 'function') {
    window.escapeAttrJson = function (obj) { return window.escapeHtml(JSON.stringify(obj)); };
}

/**
 * devices.js
 * Main JavaScript for devices page
 * Global state variables are defined in devices-state.js
 */

// State tambahan (khusus file ini)
let currentStatusFilter = 'all'; // Filter status aktif: all | online | offline
let bulkBusy = false;            // true selama bulk action jalan (auto-refresh di-pause)
let currentRxFilter = 'all';     // Filter redaman aktif: all | bagus | warning | kritis
let currentBrandFilter = 'all';  // Filter merek aktif: all | huawei | zte | fiberhome | lainnya

// ---------------------------------------------------------------------------
// JENIS PERANGKAT: ONU vs MikroTik (RouterBoard) -- keduanya ada di daftar GenieACS yang sama
// ---------------------------------------------------------------------------
// MikroTik dikenali dari manufacturer ("MikroTik") atau dari awalan product class
// (RB4011iGS+, CCR2004, CRS326, hAP ax2, hEX, cAP, wAP, SXT, LHG, ...).
function isMikrotik(device) {
    const manufacturer = (device.manufacturer || '').toLowerCase();
    if (manufacturer.includes('mikrotik') || manufacturer.includes('routerboard')) return true;
    return /^(RB\d|RBM|CCR\d|CRS\d|hAP|hEX|cAP|wAP|mAP|SXT|LHG|NetMetal|PowerBox)/i.test((device.product_class || '').trim());
}

// Tab yang isinya device GenieACS (bukan item peta)
function isDeviceTab(type) {
    return type === 'onu' || type === 'mikrotik';
}

// Device milik tab yang sedang aktif (ONU atau MikroTik)
function getScopeDevices(type) {
    const scope = type || currentFilterType;
    return scope === 'mikrotik' ? allDevices.filter(isMikrotik) : allDevices.filter(d => !isMikrotik(d));
}

// Kelompok redaman (threshold sama dengan warna badge Rx di tabel):
//  bagus   : -15.00 s/d -24.99 dBm (hijau)
//  warning : -13.00 s/d -14.99 dan -25.00 s/d -28.00 dBm (kuning)
//  kritis  : > -13.00 atau < -28.00 dBm (merah)
// Device offline / tanpa nilai Rx valid tidak masuk kelompok mana pun.
function getRxCategory(device) {
    const rx = parseFloat(device.rx_power);
    if (device.status !== 'online' || isNaN(rx) || rx === -999) return null;
    if (rx > -13.00) return 'kritis';
    if (rx > -15.00) return 'warning';
    if (rx > -25.00) return 'bagus';
    if (rx >= -28.00) return 'warning';
    return 'kritis';
}

// / Kunci merek dari field manufacturer GenieACS.
// "Huawei Technologies Co., Ltd" dan "HUAWEI" sama-sama jadi "huawei"; "ZTE Corporation" jadi "zte".
function getBrandKey(device) {
    const m = (device.manufacturer || '').trim();
    if (!m || m === 'N/A') return 'lainnya';
    return m.split(/[\s,]+/)[0].toLowerCase();
}

function formatBrandLabel(key) {
    if (key === 'lainnya') return 'Lainnya';
    // Singkatan pendek (ZTE) huruf besar semua, lainnya kapital di huruf pertama (Huawei)
    return key.length <= 4 ? key.toUpperCase() : key.charAt(0).toUpperCase() + key.slice(1);
}

// Isi dropdown merek. Daftar merek dari seluruh data; angka mengikuti RB yang dipilih.
function updateBrandOptions(rbFilteredDevices) {
    const select = document.getElementById('brandFilter');
    if (!select) return;

    const counts = {};
    getScopeDevices().forEach(d => { counts[getBrandKey(d)] = 0; });
    rbFilteredDevices.forEach(d => { counts[getBrandKey(d)]++; });

    const keys = Object.keys(counts).sort((a, b) => {
        if (a === 'lainnya') return 1;   // "Lainnya" selalu paling bawah
        if (b === 'lainnya') return -1;
        return a.localeCompare(b);
    });

    select.innerHTML =
        '<option value="all">Semua Merek</option>' +
        keys.map(k => `<option value="${k}">${formatBrandLabel(k)} (${counts[k]})</option>`).join('');

    // Kembalikan pilihan user setelah opsi dibangun ulang
    select.value = (currentBrandFilter === 'all' || counts[currentBrandFilter] !== undefined) ? currentBrandFilter : 'all';
}

function filterByBrand(value) {
    currentBrandFilter = value;
    applyRbFilter();
}

// ---------------------------------------------------------------------------
// LOAD DATA
// ---------------------------------------------------------------------------
async function loadDevices(isAutoRefresh = false) {
    // Pause auto-refresh selama bulk action (supaya server nggak dobel beban)
    if (isAutoRefresh && bulkBusy) return;

    // Save scroll position before refresh (for auto-refresh)
    if (isAutoRefresh) {
        savedScrollPosition = window.pageYOffset || document.documentElement.scrollTop;
    }

    const tbody = document.getElementById('devices-tbody');

    // Don't show spinner on auto-refresh to avoid flickering
    if (!isAutoRefresh) {
        tbody.innerHTML = '<tr><td colspan="12" class="text-center"><div class="spinner"></div><div style="margin-top: 10px;">Loading devices...</div></td></tr>';
    }

    // Backup data lama: kalau auto-refresh gagal (timeout), tabel nggak dikosongin
    const previousDevices = allDevices;

    // Progressive loading: Load devices in chunks
    allDevices = [];
    let skip = 0;
    const chunkSize = 1000;
    const parallelChunks = 1;   // 1 = berurutan. Paralel (3) terbukti lebih lambat: server (PHP/GenieACS) memproses serentak dengan melambat
    let hasMore = true;

    try {
        // Load first chunk with map data in parallel
        const [firstChunk, mapCountsResult, mapItemsResult] = await Promise.all([
            fetchAPI(`/api/get-devices.php?limit=${chunkSize}&skip=${skip}`),
            fetchAPI('/api/get-map-counts.php'),
            fetchAPI('/api/map-get-items.php')
        ]);

        if (!firstChunk || !firstChunk.success) {
            throw new Error(firstChunk?.message || 'Failed to load devices');
        }

        allDevices = firstChunk.devices || [];
        hasMore = firstChunk.hasMore;
        skip += chunkSize;

        // Update UI with first chunk immediately
        if (!isAutoRefresh) {
            tbody.innerHTML = '<tr><td colspan="12" class="text-center"><div class="spinner"></div><div style="margin-top: 10px;">Loading devices... (' + allDevices.length + ' loaded)</div></td></tr>';
        }

        // Load remaining chunks: diminta paralel per gelombang (urutan hasil tetap terjaga)
        while (hasMore) {
            const skips = [];
            for (let i = 0; i < parallelChunks; i++) skips.push(skip + i * chunkSize);

            const results = await Promise.all(
                skips.map(s => fetchAPI(`/api/get-devices.php?limit=${chunkSize}&skip=${s}`))
            );

            let failed = false;
            for (const chunk of results) {
                if (!chunk || !chunk.success) { failed = true; break; }
                allDevices = allDevices.concat(chunk.devices || []);
                if (!chunk.hasMore) { hasMore = false; break; }   // chunk terakhir (tidak penuh)
            }
            if (failed) break;
            skip += parallelChunks * chunkSize;

            // Update loading indicator
            if (!isAutoRefresh) {
                tbody.innerHTML = '<tr><td colspan="12" class="text-center"><div class="spinner"></div><div style="margin-top: 10px;">Loading devices... (' + allDevices.length + ' loaded)</div></td></tr>';
            }
        }

        // Process loaded devices
        if (allDevices.length === 0) {
            tbody.innerHTML = '<tr><td colspan="12" class="text-center">No devices found</td></tr>';
            updateDeviceCount(0, 0);
            return;
        }

        // Only reset sort state on manual refresh, maintain on auto-refresh
        if (!isAutoRefresh) {
            currentSortColumn = null;
            currentSortDirection = 'asc';
            resetSortIcons();

            // Generate initial table header for "ONU" tab (default)
            generateTableHeader(currentFilterType || 'onu');

            // Placeholder pencarian mengikuti tab yang sedang aktif
            updateSearchPlaceholder(currentFilterType || 'onu');
        }

        // Update tab counts dulu (nulis total mentah)...
        if (mapCountsResult && mapCountsResult.success) {
            updateDeviceTypeCountsFromMap(allDevices, mapCountsResult.counts);
        } else {
            updateDeviceTypeCountsFromMap(allDevices, {});
        }

        // ...baru render dengan filter RB/status/search/sort, supaya badge terakhir
        // ditulis oleh hasil filter
        if (isDeviceTab(currentFilterType)) {
            applyRbFilter(true);
        } else {
            renderMapItems(currentFilterType);
            updateDeviceStats([], false);
        }

        // Restore scroll position and sort icons after auto-refresh
        if (isAutoRefresh) {
            if (savedScrollPosition > 0) {
                setTimeout(() => {
                    window.scrollTo(0, savedScrollPosition);
                }, 100); // Small delay to ensure DOM is updated
            }

            // Restore sort icons if sorting is active
            if (currentSortColumn) {
                setTimeout(() => {
                    updateSortIcons(currentSortColumn, currentSortDirection);
                }, 50);
            }
        }
    } catch (error) {
        // Auto-refresh gagal (mis. timeout saat server sibuk): pakai data lama, jangan kosongin tabel
        if (isAutoRefresh && previousDevices && previousDevices.length > 0) {
            console.warn('Auto-refresh gagal, pakai data lama:', error.message);
            allDevices = previousDevices;
            return;
        }

        console.error('Error loading devices:', error);
        tbody.innerHTML = '<tr><td colspan="12" class="text-center text-danger">Failed to load devices: ' + error.message + '</td></tr>';
        updateDeviceCount(0, 0);
        updateDeviceStats([]);
        updateDeviceTypeCountsFromMap([], {}); // Reset counts
    }
}

// ---------------------------------------------------------------------------
// RENDER
// ---------------------------------------------------------------------------
async function renderDevices(devices) {
    const tbody = document.getElementById('devices-tbody');
    tbody.innerHTML = '';

    // Pastikan header tabel selalu cocok dengan tab aktif (ONU / MikroTik), apa pun jalur render-nya
    const headerEl = document.getElementById('table-header');
    if (headerEl && headerEl.dataset.type !== currentFilterType) {
        generateTableHeader(currentFilterType);
    }

    // Determine appropriate colspan based on current filter type
    const colspan = currentFilterType === 'mikrotik' ? 10 : (currentFilterType === 'onu' ? 12 : 6);

    if (devices.length === 0) {
        // If showing infrastructure items, show map items instead
        if (!isDeviceTab(currentFilterType)) {
            renderMapItems(currentFilterType);
            return;
        }
        tbody.innerHTML = `<tr><td colspan="${colspan}" class="text-center">No devices found</td></tr>`;
        updatePaginationUI(0);
        updateBulkActionButtons();
        return;
    }

    // Store total for pagination
    totalDevices = devices.length;

    // Apply pagination (slice devices array)
    let devicesToRender = devices;
    if (itemsPerPage > 0) {
        const startIndex = (currentPage - 1) * itemsPerPage;
        const endIndex = startIndex + itemsPerPage;
        devicesToRender = devices.slice(startIndex, endIndex);
    }

    // Update pagination UI
    updatePaginationUI(totalDevices);

    // Fetch map status for all devices on current page using BATCH API
    const serialNumbers = devicesToRender.map(device => device.serial_number);

    let mapStatusMap = {};

    try {
        const batchResult = await fetchAPI('/api/get-onu-location-batch.php', {
            method: 'POST',
            body: JSON.stringify({ serial_numbers: serialNumbers })
        });

        if (batchResult && batchResult.success && batchResult.locations) {
            // Convert batch result to map status format
            Object.keys(batchResult.locations).forEach(serial => {
                const location = batchResult.locations[serial];
                mapStatusMap[serial] = {
                    inMap: location.found || false,
                    itemType: location.item_type || 'onu',
                    itemId: location.onu?.id || location.server?.id || null
                };
            });
        }
    } catch (error) {
        console.error('Batch map status fetch failed:', error);
        // Fallback: all devices marked as not in map
        devicesToRender.forEach(device => {
            mapStatusMap[device.serial_number] = {
                inMap: false,
                itemType: 'onu',
                itemId: null
            };
        });
    }

    // Tabel bisa dirender ulang selagi menunggu batch API; bersihkan lagi biar nggak dobel baris
    tbody.innerHTML = '';

    devicesToRender.forEach(device => {
        const row = document.createElement('tr');
        const ipAddress = extractIP(device.ip_tr069);
        const mapInfo = mapStatusMap[device.serial_number] || { inMap: false, itemType: 'onu', itemId: null };
        const isInMap = mapInfo.inMap;

        // Create clickable IP link if IP is valid
        let ipDisplay;
        if (ipAddress !== 'N/A' && ipAddress !== '') {
            ipDisplay = `<a href="http://${ipAddress}" target="_blank" rel="noopener noreferrer" title="Open ${ipAddress} in new tab">${ipAddress}</a>`;
        } else {
            ipDisplay = ipAddress;
        }

        // Connected clients count with badge
        const clientsCount = device.connected_devices_count || 0;
        let clientsBadge = '';
        if (clientsCount > 0) {
            clientsBadge = `<span class="badge bg-primary">${clientsCount}</span>`;
        } else {
            clientsBadge = `<span class="badge bg-secondary">0</span>`;
        }

        // RX Power badge with color based on signal strength
        const rxPower = parseFloat(device.rx_power);
        let rxBadgeClass = 'bg-secondary'; // Default
        let rxDisplay = 'N/A';

        // 1. Paksa abu-abu kalau device offline atau nilai error (-999)
        if (device.status !== 'online' || isNaN(rxPower) || rxPower === -999) {
            rxBadgeClass = 'bg-secondary';
            if (!isNaN(rxPower) && rxPower !== -999) {
                rxDisplay = `<span class="badge ${rxBadgeClass}">${escapeHtml(device.rx_power)} dBm</span>`;
            } else {
                rxDisplay = `<span class="badge ${rxBadgeClass}">N/A</span>`;
            }
        }
        // 2. Logic 4 warna menggunakan tangga ke bawah (agar tidak ada celah desimal)
        else {
            if (rxPower > -13.00) {
                // Lebih besar dari -13 (misal -12, -10) -> Merah
                rxBadgeClass = 'bg-danger';
            } else if (rxPower > -15.00) {
                // Dari -13.00 sampai -14.99 -> Kuning
                rxBadgeClass = 'bg-warning text-dark';
            } else if (rxPower > -25.00) {
                // Dari -15.00 sampai -24.99 -> Hijau
                rxBadgeClass = 'bg-success';
            } else if (rxPower >= -28.00) {
                // Dari -25.00 sampai -28.00 -> Kuning
                rxBadgeClass = 'bg-warning text-dark';
            } else {
                // Di bawah -28.00 -> Merah
                rxBadgeClass = 'bg-danger';
            }
            rxDisplay = `<span class="badge ${rxBadgeClass}">${escapeHtml(device.rx_power)} dBm</span>`;
        }

        // Map button - conditional based on registration status
        // let mapButton;
        // if (isInMap) {
        //     // Green button - opens map in new tab
        //     let mapUrl;
        //     if (mapInfo.itemType === 'mikrotik') {
        //         // For MikroTik devices, focus on server
        //         mapUrl = `/map.php?focus_type=server&focus_id=${mapInfo.itemId}`;
        //     } else {
        //         // For ONU devices, focus on ONU
        //         mapUrl = `/map.php?focus_type=onu&focus_serial=${encodeURIComponent(device.serial_number)}`;
        //     }
        //     mapButton = `<button class="btn btn-sm btn-success me-1" onclick="window.open('${mapUrl}', '_blank')" title="View on Map">
        //         <i class="bi bi-map"></i>
        //     </button>`;
        // } else {
        //     // Gray button - shows alert
        //     mapButton = `<button class="btn btn-sm btn-secondary me-1" onclick="showNotInMapAlert('${encodeURIComponent(device.serial_number)}')" title="Not Registered in Map">
        //         <i class="bi bi-map"></i>
        //     </button>`;
        // }

        // Status badge with ping
        let statusDisplay;
        if (device.status === 'online') {
            const ping = device.ping || '-';
            statusDisplay = `<span class="badge online">ON [${ping}ms]</span>`;
        } else {
            statusDisplay = `<span class="badge offline" title="Terakhir terlihat: ${escapeHtml(device.last_inform || 'N/A')}">OFF [-]</span>`;
        }

        // Tags display - show as badges
        let tagsDisplay = '';
        let tagsSortValue = '';
        if (device.tags && Array.isArray(device.tags) && device.tags.length > 0) {
            tagsDisplay = device.tags.map(tag => `<span class="badge bg-info me-1">${escapeHtml(tag)}</span>`).join('');
            tagsSortValue = device.tags.join(', '); // For sorting: join tags as string
        } else {
            tagsDisplay = '<span class="text-muted">-</span>';
            tagsSortValue = ''; // Empty for sorting (will be sorted to bottom)
        }

        // Check tags column visibility state for consistent display
        const tagsColumnDisplay = tagsColumnVisible ? '' : 'none';

        if (currentFilterType === 'mikrotik') {
            // MikroTik: tanpa kolom SSID, PPPoE, Rx, Temp (tidak relevan untuk router)
            row.innerHTML = `
                <td>
                    <input type="checkbox" class="device-checkbox" value="${encodeURIComponent(device.device_id)}" onchange="updateBulkActionButtons()">
                </td>
                <td><a href="/device-detail.php?id=${encodeURIComponent(device.device_id)}">${escapeHtml(device.serial_number)}</a></td>
                <td data-sort-value="${escapeHtml(device.identity || '')}">${device.identity ? '<strong>' + escapeHtml(device.identity) + '</strong>' : '<span class="text-muted">-</span>'}</td>
                <td>${escapeHtml(device.mac_address)}</td>
                <td data-sort-value="${escapeHtml(device.product_class || '')}">${escapeHtml(device.product_class || 'N/A')}</td>
                <td data-sort-value="${ipAddress}">${ipDisplay}</td>
                <td data-sort-value="${clientsCount}" class="text-center">${clientsBadge}</td>
                <td data-sort-value="${escapeHtml(device.status)}">${statusDisplay}</td>
                <td class="tags-column" data-sort-value="${escapeHtml(tagsSortValue)}" style="display: ${tagsColumnDisplay};">${tagsDisplay}</td>
                <td>
                    <button class="btn btn-sm btn-primary" data-id="${escapeHtml(device.device_id)}" onclick="summonDeviceQuick(this.dataset.id)" title="Summon Device">
                        <i class="bi bi-lightning-charge"></i>
                    </button>
                </td>
            `;
            tbody.appendChild(row);
            return;
        }

        row.innerHTML = `
            <td>
                <input type="checkbox" class="device-checkbox" value="${encodeURIComponent(device.device_id)}" onchange="updateBulkActionButtons()">
            </td>
            <td><a href="/device-detail.php?id=${encodeURIComponent(device.device_id)}">${escapeHtml(device.serial_number)}</a></td>
            <td>${escapeHtml(device.mac_address)}</td>
            <td data-sort-value="${escapeHtml(device.product_class || '')}">${escapeHtml(device.product_class || 'N/A')}</td>
            <td data-sort-value="${ipAddress}">${ipDisplay}</td>
            <td data-sort-value="${escapeHtml(device.wifi_ssid)}">${escapeHtml(device.wifi_ssid)}</td>
            <td data-sort-value="${escapeHtml(device.pppoe_username || '')}">${escapeHtml(device.pppoe_username || 'N/A')}</td>
            <td data-sort-value="${parseFloat(device.rx_power) || -999}">${rxDisplay}</td>
            <td data-sort-value="${parseFloat(device.temperature) || -999}">${escapeHtml(device.temperature)}°C</td>
            <td data-sort-value="${clientsCount}" class="text-center">${clientsBadge}</td>
            <td data-sort-value="${escapeHtml(device.status)}">${statusDisplay}</td>
            <td class="tags-column" data-sort-value="${escapeHtml(tagsSortValue)}" style="display: ${tagsColumnDisplay};">${tagsDisplay}</td>
            <td>
                <button class="btn btn-sm btn-primary" data-id="${escapeHtml(device.device_id)}" onclick="summonDeviceQuick(this.dataset.id)" title="Summon Device">
                    <i class="bi bi-lightning-charge"></i>
                </button>
                <button class="btn btn-sm btn-danger" data-id="${escapeHtml(device.device_id)}" onclick="rebootDeviceQuick(this.dataset.id)" title="Reboot ONU">
                    <i class="bi bi-arrow-repeat"></i>
                </button>
            </td>
        `;
        tbody.appendChild(row);
    });

    // Sinkronkan bar bulk-action dengan checkbox yang baru dirender
    updateBulkActionButtons();
}

// Render map items (for infrastructure: Server, OLT, ODC, ODP)
function renderMapItems(itemType) {
    const tbody = document.getElementById('devices-tbody');
    tbody.innerHTML = '';

    let items = [];

    if (itemType === 'olt') {
        // OLT stored in Server properties, not as separate items
        // Extract OLT info from Servers that have olt_link configured
        allMapItems.forEach(item => {
            if (item.item_type === 'server' && item.properties && item.properties.olt_link) {
                items.push({
                    id: item.id,
                    name: item.properties.olt_link || 'OLT',
                    item_type: 'olt',
                    latitude: item.latitude,
                    longitude: item.longitude,
                    status: item.status,
                    server_name: item.name
                });
            }
        });
    } else {
        // Filter map items by type for other infrastructure
        items = allMapItems.filter(item => item.item_type === itemType);
    }

    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center">No items found</td></tr>';
        updateDeviceCount(0, 0);
        return;
    }

    items.forEach(item => {
        const row = document.createElement('tr');

        // Get status badge
        const status = item.status || 'unknown';
        let statusBadge = '';
        if (status === 'online') {
            statusBadge = '<span class="badge online">Online</span>';
        } else if (status === 'offline') {
            statusBadge = '<span class="badge offline">Offline</span>';
        } else {
            statusBadge = '<span class="badge bg-secondary">Unknown</span>';
        }

        // Format coordinates
        const lat = parseFloat(item.latitude).toFixed(6);
        const lng = parseFloat(item.longitude).toFixed(6);

        // For OLT, show server name in parentheses
        const displayName = itemType === 'olt' ? `${escapeHtml(item.name)} (${escapeHtml(item.server_name)})` : escapeHtml(item.name);

        row.innerHTML = `
            <td>${displayName}</td>
            <td><span class="badge bg-primary">${itemType.toUpperCase()}</span></td>
            <td>${lat}</td>
            <td>${lng}</td>
            <td>${statusBadge}</td>
            <td>
                <button class="btn btn-sm btn-success" onclick="window.open('/map.php?focus_type=server&focus_id=${parseInt(item.id, 10) || 0}', '_blank')" title="View on Map">
                    <i class="bi bi-map"></i>
                </button>
            </td>
        `;
        tbody.appendChild(row);
    });

    updateDeviceCount(items.length, items.length);
}

function updateDeviceCount(shown, total) {
    const countElement = document.getElementById('device-count');

    // If using pagination, show range
    if (itemsPerPage > 0 && total > itemsPerPage) {
        const startIndex = (currentPage - 1) * itemsPerPage + 1;
        const endIndex = Math.min(currentPage * itemsPerPage, total);
        countElement.textContent = `Showing ${startIndex}-${endIndex} of ${total} item${total !== 1 ? 's' : ''}`;
    } else if (shown === total) {
        countElement.textContent = `Showing ${total} item${total !== 1 ? 's' : ''}`;
    } else {
        countElement.textContent = `Showing ${shown} of ${total} item${total !== 1 ? 's' : ''}`;
    }
}

function filterByStatus(status) {
    currentStatusFilter = status;
    applyRbFilter(); // Panggil fungsi master filter
}

function updateDeviceStats(devices, showStats = true) {
    const statsContainer = document.getElementById('device-stats-badges');

    if (!showStats) {
        statsContainer.innerHTML = '';
        return;
    }

    const total = devices.length;
    const online = devices.filter(d => d.status === 'online').length;
    const offline = total - online;

    // Hitung kelompok redaman (mengikuti RB yang dipilih) lalu tulis ke label dropdown
    const rx = { bagus: 0, warning: 0, kritis: 0 };
    devices.forEach(d => {
        const c = getRxCategory(d);
        if (c) rx[c]++;
    });
    const rxSelect = document.getElementById('rxFilter');
    if (rxSelect) {
        // Cuma ganti teks option, jadi pilihan user tidak ke-reset
        rxSelect.querySelector('option[value="bagus"]').textContent   = `Bagus (${rx.bagus})`;
        rxSelect.querySelector('option[value="warning"]').textContent = `Warning (${rx.warning})`;
        rxSelect.querySelector('option[value="kritis"]').textContent  = `Kritis (${rx.kritis})`;
        updateBrandOptions(devices);
    }

    // Badge status: ukuran seragam, yang aktif penuh, yang lain redup (tanpa ring/border)
    const chip = (label, count, cls, active, onclick) =>
        `<span class="badge ${cls}" onclick="${onclick}"
            style="display:inline-flex; align-items:center; justify-content:center; box-sizing:border-box;
                   width:108px; height:28px; padding:0 8px; font-family:inherit; font-size:11px; font-weight:600;
                   line-height:1; letter-spacing:0.3px; white-space:nowrap; cursor:pointer;
                   opacity:${active ? '1' : '0.4'}; transition:0.2s;">${label} [${count}]</span>`;

    statsContainer.style.cssText = 'display:flex; flex-wrap:wrap; align-items:center; gap:8px;';
    statsContainer.innerHTML =
        chip('TOTAL',   total,   'bg-secondary', currentStatusFilter === 'all',     "filterByStatus('all')") +
        chip('ONLINE',  online,  'bg-success',   currentStatusFilter === 'online',  "filterByStatus('online')") +
        chip('OFFLINE', offline, 'bg-danger',    currentStatusFilter === 'offline', "filterByStatus('offline')");
}

function filterByRx(value) {
    // Klik badge yang sama lagi = matikan filter
    currentRxFilter = value;
    applyRbFilter();
}

// Update badge ONU di tab (mengikuti filter RB yang sedang dipilih)
function updateDeviceTypeCountsFromMap(devices, mapCounts) {
    refreshTypeBadges();
}

// Badge tab: ONU mengikuti filter RB, MikroTik jumlah total router.
// renderedCount (opsional) = jumlah hasil akhir (setelah semua filter) untuk tab yang sedang aktif.
function refreshTypeBadges(renderedCount) {
    const onuBadge = document.getElementById('count-onu');
    const mikrotikBadge = document.getElementById('count-mikrotik');
    if (onuBadge) {
        onuBadge.textContent = (currentFilterType === 'onu' && renderedCount !== undefined)
            ? renderedCount : getRbFilteredDevices('onu').length;
    }
    if (mikrotikBadge) {
        mikrotikBadge.textContent = (currentFilterType === 'mikrotik' && renderedCount !== undefined)
            ? renderedCount : getRbFilteredDevices('mikrotik').length;
    }
}

// Generate table header based on device type
function generateTableHeader(type) {
    const tableHeader = document.getElementById('table-header');
    tableHeader.dataset.type = type;   // dipakai renderDevices untuk mendeteksi header yang tidak sesuai

    if (type === 'mikrotik') {
        const tagsDisplayMk = tagsColumnVisible ? '' : 'none';
        // MikroTik (RouterBoard): kolom ringkas
        tableHeader.innerHTML = `
            <tr>
                <th style="width: 40px;">
                    <input type="checkbox" id="select-all-checkbox" onchange="toggleSelectAll()" title="Select All">
                </th>
                <th>SN</th>
                <th class="sortable" onclick="sortTable('identity')" style="cursor: pointer;">
                    Identity <i class="bi bi-chevron-expand sort-icon"></i>
                </th>
                <th>MAC</th>
                <th class="sortable" onclick="sortTable('product_class')" style="cursor: pointer;">
                    Model <i class="bi bi-chevron-expand sort-icon"></i>
                </th>
                <th class="sortable" onclick="sortTable('ip')" style="cursor: pointer;">
                    IP <i class="bi bi-chevron-expand sort-icon"></i>
                </th>
                <th class="sortable" onclick="sortTable('connected_clients')" style="cursor: pointer;">
                    Client <i class="bi bi-chevron-expand sort-icon"></i>
                </th>
                <th class="sortable" onclick="sortTable('status')" style="cursor: pointer;">
                    Status <i class="bi bi-chevron-expand sort-icon"></i>
                </th>
                <th class="tags-column sortable" onclick="sortTable('tags')" style="cursor: pointer; display: ${tagsDisplayMk};">
                    Tags <i class="bi bi-chevron-expand sort-icon"></i>
                </th>
                <th>Action</th>
            </tr>
        `;
    } else if (type === 'onu') {
        // Check current tags column visibility state
        const tagsDisplay = tagsColumnVisible ? '' : 'none';

        // ONU devices table header (GenieACS devices)
        tableHeader.innerHTML = `
            <tr>
                <th style="width: 40px;">
                    <input type="checkbox" id="select-all-checkbox" onchange="toggleSelectAll()" title="Select All">
                </th>
                <th>SN</th>
                <th>MAC</th>
                <th class="sortable" onclick="sortTable('product_class')" style="cursor: pointer;">
                    Tipe <i class="bi bi-chevron-expand sort-icon"></i>
                </th>
                <th class="sortable" onclick="sortTable('ip')" style="cursor: pointer;">
                    IP <i class="bi bi-chevron-expand sort-icon"></i>
                </th>
                <th class="sortable" onclick="sortTable('ssid')" style="cursor: pointer;">
                    SSID <i class="bi bi-chevron-expand sort-icon"></i>
                </th>
                <th class="sortable" onclick="sortTable('pppoe_username')" style="cursor: pointer;">
                    PPPoE <i class="bi bi-chevron-expand sort-icon"></i>
                </th>
                <th class="sortable" onclick="sortTable('rx_power')" style="cursor: pointer;">
                    Rx <i class="bi bi-chevron-expand sort-icon"></i>
                </th>
                <th class="sortable" onclick="sortTable('temperature')" style="cursor: pointer;">
                    Temp <i class="bi bi-chevron-expand sort-icon"></i>
                </th>
                <th class="sortable" onclick="sortTable('connected_clients')" style="cursor: pointer;">
                    Client <i class="bi bi-chevron-expand sort-icon"></i>
                </th>
                <th class="sortable" onclick="sortTable('status')" style="cursor: pointer;">
                    Status <i class="bi bi-chevron-expand sort-icon"></i>
                </th>
                <th class="tags-column sortable" onclick="sortTable('tags')" style="cursor: pointer; display: ${tagsDisplay};">
                    Tags <i class="bi bi-chevron-expand sort-icon"></i>
                </th>
                <th>Action</th>
            </tr>
        `;
    } else {
        // Infrastructure items table header (Map items: Server, OLT, ODC, ODP)
        tableHeader.innerHTML = `
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Latitude</th>
                <th>Longitude</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        `;
    }
}

// Filter devices by type using map data
function filterByType(type) {
    currentFilterType = type;

    // Filter merek & redaman khusus ONU: reset saat pindah tab supaya tab lain tidak kosong tanpa sebab
    currentBrandFilter = 'all';
    currentRxFilter = 'all';
    const rxSelectEl = document.getElementById('rxFilter');
    if (rxSelectEl) rxSelectEl.value = 'all';
    currentPage = 1;

    // Generate appropriate table header
    generateTableHeader(type);

    // Clear search box when switching tabs
    document.getElementById('search-input').value = '';

    // Update search placeholder based on tab
    updateSearchPlaceholder(type);

    // Reset sort state
    currentSortColumn = null;
    currentSortDirection = 'asc';
    resetSortIcons();

    if (isDeviceTab(type)) {
        // ONU / MikroTik: lewat master filter supaya filter RB/status tetap kepakai
        applyRbFilter();
    } else {
        // For ODP, ODC, OLT, Server: show map items
        renderMapItems(type);
        // Hide stats for infrastructure tabs
        updateDeviceStats([], false);
    }
}

function extractIP(ipString) {
    if (!ipString || ipString === 'N/A') return 'N/A';
    const match = ipString.match(/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/);
    return match ? match[1] : 'N/A';
}

// Update search placeholder based on current tab
function updateSearchPlaceholder(type) {
    const searchInput = document.getElementById('search-input');
    if (isDeviceTab(type)) {
        searchInput.placeholder = type === 'mikrotik' ? 'Search by Serial Number, Identity, MAC Address, or Tags...' : 'Search by Serial Number, MAC Address, or Tags...';
    } else {
        searchInput.placeholder = 'Search by Name...';
    }
}

// Search functionality
function filterDevices() {
    applyRbFilter(); // Re-apply master filter to include status and type filters
}

function clearSearch() {
    document.getElementById('search-input').value = '';
    filterDevices();
}

// Apply sorting to devices array
function applySorting(devices, column, direction) {
    const sortedDevices = [...devices];

    sortedDevices.sort((a, b) => {
        let valueA, valueB;

        switch (column) {
            case 'identity':
                valueA = (a.identity || '').toLowerCase();
                valueB = (b.identity || '').toLowerCase();
                break;
            case 'product_class':
                valueA = (a.product_class || '').toLowerCase();
                valueB = (b.product_class || '').toLowerCase();
                break;
            case 'ip':
                valueA = extractIP(a.ip_tr069);
                valueB = extractIP(b.ip_tr069);
                // Convert IP to comparable format
                valueA = valueA === 'N/A' ? '' : valueA.split('.').map(n => n.padStart(3, '0')).join('.');
                valueB = valueB === 'N/A' ? '' : valueB.split('.').map(n => n.padStart(3, '0')).join('.');
                break;
            case 'ssid':
                valueA = (a.wifi_ssid || '').toLowerCase();
                valueB = (b.wifi_ssid || '').toLowerCase();
                break;
            case 'pppoe_username':
                valueA = (a.pppoe_username || '').toLowerCase();
                valueB = (b.pppoe_username || '').toLowerCase();
                break;
            case 'rx_power':
                valueA = parseFloat(a.rx_power) || -999;
                valueB = parseFloat(b.rx_power) || -999;
                break;
            case 'temperature':
                valueA = parseFloat(a.temperature) || -999;
                valueB = parseFloat(b.temperature) || -999;
                break;
            case 'connected_clients':
                valueA = parseInt(a.connected_devices_count) || 0;
                valueB = parseInt(b.connected_devices_count) || 0;
                break;
            case 'status':
                valueA = a.status || '';
                valueB = b.status || '';
                break;
            case 'tags':
                // Sort by tags: join array to string, empty array goes to bottom
                valueA = (a.tags && Array.isArray(a.tags) && a.tags.length > 0) ? a.tags.join(', ').toLowerCase() : 'zzz';
                valueB = (b.tags && Array.isArray(b.tags) && b.tags.length > 0) ? b.tags.join(', ').toLowerCase() : 'zzz';
                break;
            default:
                return 0;
        }

        let comparison = 0;
        if (valueA > valueB) comparison = 1;
        if (valueA < valueB) comparison = -1;

        return direction === 'asc' ? comparison : -comparison;
    });

    return sortedDevices;
}

// Sorting functionality (filter RB/status/search + sorting ditangani applyRbFilter)
function sortTable(column) {
    // Toggle sort direction if clicking same column
    if (currentSortColumn === column) {
        currentSortDirection = currentSortDirection === 'asc' ? 'desc' : 'asc';
    } else {
        currentSortColumn = column;
        currentSortDirection = 'asc';
    }

    applyRbFilter(true);
    updateSortIcons(column, currentSortDirection);
}

function updateSortIcons(column, direction) {
    // Reset all sort icons
    document.querySelectorAll('.sort-icon').forEach(icon => {
        icon.className = 'bi bi-chevron-expand sort-icon';
    });

    // Update active sort icon (indices shifted +1 due to checkbox column)
    // Urutan kolom MikroTik beda dari ONU: SN, Identity, MAC, Model, IP, Client, Status, Tags
    const columnMap = currentFilterType === 'mikrotik' ? {
        'identity': 3,
        'product_class': 5,
        'ip': 6,
        'connected_clients': 7,
        'status': 8,
        'tags': 9
    } : {
        'product_class': 4,
        'ip': 5,
        'ssid': 6,
        'pppoe_username': 7,
        'rx_power': 8,
        'temperature': 9,
        'connected_clients': 10,
        'status': 11,
        'tags': 12
    };

    const columnIndex = columnMap[column];
    if (columnIndex) {
        const header = document.querySelector(`thead tr th:nth-child(${columnIndex}) .sort-icon`);
        if (header) {
            header.className = direction === 'asc' ? 'bi bi-chevron-up sort-icon' : 'bi bi-chevron-down sort-icon';
        }
    }
}

function resetSortIcons() {
    document.querySelectorAll('.sort-icon').forEach(icon => {
        icon.className = 'bi bi-chevron-expand sort-icon';
    });
}

// ---------------------------------------------------------------------------
// SINGLE SUMMON
// ---------------------------------------------------------------------------
// currentSummonDeviceId defined in devices-state.js

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
        showToast(result?.message || 'Gagal summon device', 'danger');
    }

    currentSummonDeviceId = null;
}

// ---------------------------------------------------------------------------
// REBOOT ONU (per device)
//   ONU harus membalas dulu. Kalau offline / tidak membalas, reboot dibatalkan (tidak ditunda).
// ---------------------------------------------------------------------------
let currentRebootDeviceId = null;

function ensureRebootModal() {
    if (document.getElementById('rebootModal')) return;
    const wrap = document.createElement('div');
    wrap.innerHTML = `
<div class="modal fade" id="rebootModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-arrow-repeat"></i> Reboot ONU</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center py-4">
        <i class="bi bi-exclamation-triangle" style="font-size: 3rem; color: var(--danger-color);"></i>
        <h5 class="mt-3">Reboot ONU ini?</h5>
        <p class="mb-1"><strong id="reboot-sn"></strong> <span class="text-muted" id="reboot-ip"></span></p>
        <p class="text-muted mb-0">ONU akan restart sekitar 1-3 menit dan internet pelanggan ikut putus selama itu.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Batal</button>
        <button type="button" class="btn btn-danger" id="reboot-confirm-btn" onclick="confirmReboot()"><i class="bi bi-arrow-repeat"></i> Ya, Reboot</button>
      </div>
    </div>
  </div>
</div>`;
    document.body.appendChild(wrap.firstElementChild);
}

function rebootDeviceQuick(deviceId) {
    ensureRebootModal();
    currentRebootDeviceId = deviceId;
    const d = (typeof allDevices !== 'undefined' ? allDevices : []).find(x => x.device_id === deviceId);
    document.getElementById('reboot-sn').textContent = d ? (d.serial_number || deviceId) : deviceId;
    document.getElementById('reboot-ip').textContent = d && (d.ip_tr069 || d.ip_address) ? '(' + (d.ip_tr069 || d.ip_address) + ')' : '';
    const btn = document.getElementById('reboot-confirm-btn');
    btn.disabled = false;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('rebootModal')).show();
}

async function confirmReboot() {
    if (!currentRebootDeviceId) return;
    const deviceId = currentRebootDeviceId;
    const btn = document.getElementById('reboot-confirm-btn');
    btn.disabled = true;               // cegah klik ganda
    const original = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Menunggu balasan ONU...';

    const result = await fetchAPI('/api/reboot-device.php', {
        method: 'POST',
        timeout: 30000,
        body: JSON.stringify({ device_id: deviceId })
    });

    btn.innerHTML = original;
    const m = bootstrap.Modal.getInstance(document.getElementById('rebootModal'));
    if (m) m.hide();

    if (result && result.success) {
        showToast(result.message || 'Perintah reboot diterima ONU', 'success', 5000);
    } else {
        showToast((result && result.message) || 'Gagal reboot ONU', 'danger', 5000);
    }
    currentRebootDeviceId = null;
}

// ---------------------------------------------------------------------------
// PAGINATION
// ---------------------------------------------------------------------------
function updatePaginationUI(total) {
    const paginationContainer = document.getElementById('pagination-container');
    const paginationInfo = document.getElementById('pagination-info');
    const paginationFirst = document.getElementById('pagination-first');
    const paginationPrev = document.getElementById('pagination-prev');
    const paginationNext = document.getElementById('pagination-next');
    const paginationLast = document.getElementById('pagination-last');

    // Hide pagination if showing all or no items
    if (itemsPerPage === 0 || total === 0) {
        paginationContainer.style.display = 'none';
        return;
    }

    const totalPages = Math.ceil(total / itemsPerPage);

    // Show pagination only if more than 1 page
    if (totalPages <= 1) {
        paginationContainer.style.display = 'none';
        return;
    }

    paginationContainer.style.display = 'block';

    // Update page info
    paginationInfo.textContent = `Page ${currentPage} of ${totalPages}`;

    // Update button states
    if (currentPage <= 1) {
        paginationFirst.classList.add('disabled');
        paginationPrev.classList.add('disabled');
    } else {
        paginationFirst.classList.remove('disabled');
        paginationPrev.classList.remove('disabled');
    }

    if (currentPage >= totalPages) {
        paginationNext.classList.add('disabled');
        paginationLast.classList.add('disabled');
    } else {
        paginationNext.classList.remove('disabled');
        paginationLast.classList.remove('disabled');
    }
}

function goToPage(page) {
    const totalPages = Math.ceil(totalDevices / itemsPerPage);

    if (page < 1 || page > totalPages) return;
    if (page === currentPage) return;

    currentPage = page;

    if (isDeviceTab(currentFilterType)) {
        applyRbFilter(true);
    } else {
        filterByType(currentFilterType);
    }

    // Scroll to top of table
    document.getElementById('devices-table').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function changeItemsPerPage() {
    const selector = document.getElementById('items-per-page');
    itemsPerPage = parseInt(selector.value);

    // Reset to page 1 when changing items per page
    currentPage = 1;

    if (isDeviceTab(currentFilterType)) {
        applyRbFilter(true);
    } else {
        filterByType(currentFilterType);
    }
}

// Toggle Tags column visibility
function toggleTagsColumn() {
    tagsColumnVisible = !tagsColumnVisible;

    const tagColumns = document.querySelectorAll('.tags-column');
    const toggleBtn = document.getElementById('toggle-tags-btn');

    if (tagsColumnVisible) {
        // Show tags column
        tagColumns.forEach(col => {
            col.style.display = '';
        });
        toggleBtn.innerHTML = '<i class="bi bi-tags-fill"></i> Hide Tags';
        toggleBtn.classList.remove('btn-secondary');
        toggleBtn.classList.add('btn-primary');
    } else {
        // Hide tags column
        tagColumns.forEach(col => {
            col.style.display = 'none';
        });
        toggleBtn.innerHTML = '<i class="bi bi-tags"></i> Show Tags';
        toggleBtn.classList.remove('btn-primary');
        toggleBtn.classList.add('btn-secondary');
    }
}

// ---------------------------------------------------------------------------
// BULK SELECTION
// ---------------------------------------------------------------------------
function toggleSelectAll() {
    const selectAllCheckbox = document.getElementById('select-all-checkbox');
    const deviceCheckboxes = document.querySelectorAll('.device-checkbox');

    deviceCheckboxes.forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });

    updateBulkActionButtons();
}

function updateBulkActionButtons() {
    const selectedCheckboxes = document.querySelectorAll('.device-checkbox:checked');
    const bulkActionButtons = document.getElementById('bulk-action-buttons');
    const selectedCount = document.getElementById('selected-count');

    if (bulkActionButtons) {
        if (selectedCheckboxes.length > 0) {
            bulkActionButtons.style.display = 'inline-block';
            if (selectedCount) selectedCount.textContent = `${selectedCheckboxes.length} selected`;
        } else {
            bulkActionButtons.style.display = 'none';
        }
    }

    // Update select-all checkbox state
    const selectAllCheckbox = document.getElementById('select-all-checkbox');
    const allCheckboxes = document.querySelectorAll('.device-checkbox');
    if (selectAllCheckbox && allCheckboxes.length > 0) {
        selectAllCheckbox.checked = selectedCheckboxes.length === allCheckboxes.length;
    }
}

function getSelectedDeviceIds() {
    const selectedCheckboxes = document.querySelectorAll('.device-checkbox:checked');
    return Array.from(selectedCheckboxes).map(cb => decodeURIComponent(cb.value));
}

// Kosongkan semua pilihan + sembunyikan bar Add Tag/Untag/Summon/Delete
function resetSelection() {
    document.querySelectorAll('.device-checkbox').forEach(cb => cb.checked = false);

    const selectAll = document.getElementById('select-all-checkbox');
    if (selectAll) selectAll.checked = false;

    if (typeof selectedDevices !== 'undefined' && selectedDevices && selectedDevices.clear) {
        selectedDevices.clear();
    }

    updateBulkActionButtons();
}

// ---------------------------------------------------------------------------
// BULK ADD TAG
// ---------------------------------------------------------------------------
function showBulkAddTagModal() {
    const selectedIds = getSelectedDeviceIds();
    document.getElementById('add-tag-count').textContent = selectedIds.length;
    document.getElementById('new-tag-name').value = '';

    const modal = new bootstrap.Modal(document.getElementById('bulkAddTagModal'), {
        backdrop: false
    });
    modal.show();
}

async function confirmBulkAddTag() {
    const selectedIds = getSelectedDeviceIds();
    const tagName = document.getElementById('new-tag-name').value.trim();

    if (!tagName) {
        showToast('Please enter a tag name', 'warning');
        return;
    }

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('bulkAddTagModal'));
    modal.hide();

    showLoading();

    const result = await fetchAPI('/api/bulk-tag.php', {
        method: 'POST',
        body: JSON.stringify({
            action: 'add',
            device_ids: selectedIds,
            tag: tagName
        })
    });

    hideLoading();

    console.log('Bulk Add Tag Response:', result);

    // Show detailed debug info if available
    if (result && result.debug) {
        console.table(result.debug);
    }

    if (result && result.success) {
        showToast(`Tag "${tagName}" added to ${result.success_count || selectedIds.length} device(s)`, 'success');

        if (result.fail_count && result.fail_count > 0) {
            console.warn('Some devices failed:', result.errors);
            console.warn('Debug info for failures:', result.debug);
            showToast(`Warning: ${result.fail_count} device(s) failed`, 'warning');
        }

        resetSelection();
        loadDevices(); // Reload devices to show updated tags
    } else {
        console.error('Add tag failed:', result);
        if (result && result.debug) {
            console.error('Debug details:', result.debug);
        }
        showToast(result?.message || 'Failed to add tags', 'error');
    }
}

// ---------------------------------------------------------------------------
// BULK UNTAG
// ---------------------------------------------------------------------------
function showBulkUntagModal() {
    const selectedIds = getSelectedDeviceIds();
    document.getElementById('untag-count').textContent = selectedIds.length;

    // Clear input field
    const inputField = document.getElementById('remove-tag-name');
    inputField.value = '';

    const modal = new bootstrap.Modal(document.getElementById('bulkUntagModal'), {
        backdrop: false
    });
    modal.show();
}

async function confirmBulkUntag() {
    const selectedIds = getSelectedDeviceIds();
    const tagName = document.getElementById('remove-tag-name').value.trim();

    if (!tagName) {
        showToast('Please enter a tag name to remove', 'warning');
        return;
    }

    console.log('Bulk Untag Request:', {
        action: 'remove',
        device_ids: selectedIds,
        tag: tagName
    });

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('bulkUntagModal'));
    modal.hide();

    showLoading();

    const result = await fetchAPI('/api/bulk-tag.php', {
        method: 'POST',
        body: JSON.stringify({
            action: 'remove',
            device_ids: selectedIds,
            tag: tagName
        })
    });

    hideLoading();

    console.log('Bulk Untag Response:', result);

    // Show detailed debug info if available
    if (result && result.debug) {
        console.table(result.debug);
    }

    if (result && result.success) {
        showToast(`Tag "${tagName}" removed from ${result.success_count || selectedIds.length} device(s)`, 'success');

        if (result.fail_count && result.fail_count > 0) {
            console.warn('Some devices failed:', result.errors);
            console.warn('Debug info for failures:', result.debug);
            showToast(`Warning: ${result.fail_count} device(s) failed`, 'warning');
        }

        resetSelection();
        loadDevices(); // Reload devices to show updated tags
    } else {
        console.error('Untag failed:', result);
        if (result && result.debug) {
            console.error('Debug details:', result.debug);
        }
        showToast(result?.message || 'Failed to remove tags', 'error');
    }
}

// ---------------------------------------------------------------------------
// BULK DELETE
// ---------------------------------------------------------------------------
function showBulkDeleteModal() {
    const selectedIds = getSelectedDeviceIds();
    document.getElementById('delete-count').textContent = selectedIds.length;

    const modal = new bootstrap.Modal(document.getElementById('bulkDeleteModal'), {
        backdrop: false
    });
    modal.show();
}

async function confirmBulkDelete() {
    const selectedIds = getSelectedDeviceIds();

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('bulkDeleteModal'));
    modal.hide();

    showLoading();

    const result = await fetchAPI('/api/bulk-delete-devices.php', {
        method: 'POST',
        body: JSON.stringify({
            device_ids: selectedIds
        })
    });

    hideLoading();

    if (result && result.success) {
        showToast(`${selectedIds.length} device(s) deleted successfully`, 'success');
        resetSelection();
        loadDevices(); // Reload devices
    } else {
        showToast(result?.message || 'Failed to delete devices', 'error');
    }
}

// ---------------------------------------------------------------------------
// INIT & AUTO-REFRESH
// ---------------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', function() {

    const statusParam = new URLSearchParams(window.location.search).get('status');
    if (statusParam === 'online' || statusParam === 'offline') {
        currentStatusFilter = statusParam;
    }

    if (window.GENIEACS_CONFIGURED) loadDevices(); // Initial load (manual)

    // Auto-refresh dimatikan: data hanya dimuat saat halaman dibuka / tombol refresh ditekan

    // Keyboard shortcuts for pagination (Left/Right arrow keys)
    document.addEventListener('keydown', function(e) {
        // Only work if not typing in input field
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
            return;
        }

        const totalPages = Math.ceil(totalDevices / itemsPerPage);

        if (e.key === 'ArrowLeft' && currentPage > 1) {
            goToPage(currentPage - 1);
        } else if (e.key === 'ArrowRight' && currentPage < totalPages) {
            goToPage(currentPage + 1);
        }
    });
});

// Cleanup: Stop auto-refresh when user navigates away
window.addEventListener('beforeunload', function() {
    if (autoRefreshTimer) {
        clearInterval(autoRefreshTimer);
        autoRefreshTimer = null;
    }
});

// ---------------------------------------------------------------------------
// MASTER FILTER (RB + Status + Search + Sort + Pagination)
// ---------------------------------------------------------------------------
function getRbFilteredDevices(type) {
    const scopeType = type || currentFilterType;
    const scopeDevices = getScopeDevices(scopeType);
    // Router MikroTik tidak ikut filter RB (IP-nya di luar segmen RB ONU)
    if (scopeType === 'mikrotik') return scopeDevices;

    const rbElement = document.getElementById('rbFilter');
    const rbFilter = rbElement ? rbElement.value.toLowerCase() : 'all';
    if (rbFilter === 'all') return scopeDevices;

    const prefixMap = { 
        '56c': '10.123.', 
        'klaling': '10.124.', 
        'sosok': '10.125.', 
        'payaman': '10.126.', 
        'bram': '10.127.', 
        'cendono' : '10.128.',
        'buyutan' : '10.129.',
    };
    const prefix = prefixMap[rbFilter];
    if (!prefix) return [];

    return scopeDevices.filter(d => extractIP(d.ip_tr069).startsWith(prefix));
}

function applyRbFilter(keepPage = false) {
    if (!keepPage) currentPage = 1;

    // 1. Filter RB
    const rbFilteredDevices = getRbFilteredDevices();

    // Badge TOTAL/ONLINE/OFFLINE ikut RB yang dipilih (sebelum dipotong status)
    updateDeviceStats(rbFilteredDevices, true);

    let devicesToRender = rbFilteredDevices;

    // 2. Filter status
    if (currentStatusFilter !== 'all') {
        devicesToRender = devicesToRender.filter(device => (device.status || 'offline') === currentStatusFilter);
    }

    // 2b. Filter redaman
    if (currentRxFilter !== 'all') {
        devicesToRender = devicesToRender.filter(device => getRxCategory(device) === currentRxFilter);
    }
    // 2c. Filter merek
    if (currentBrandFilter !== 'all') {
        devicesToRender = devicesToRender.filter(device => getBrandKey(device) === currentBrandFilter);
    }

    // 3. Filter search
    const searchInput = document.getElementById('search-input');
    const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
    if (searchTerm !== '') {
        devicesToRender = devicesToRender.filter(device => {
            const serialNumber = (device.serial_number || '').toLowerCase();
            const macAddress = (device.mac_address || '').toLowerCase();
            const pppoe_username = (device.pppoe_username || '').toLowerCase();
            const identity = (device.identity || '').toLowerCase();
            let tagsMatch = false;
            if (device.tags && Array.isArray(device.tags) && device.tags.length > 0) {
                tagsMatch = device.tags.some(tag => tag.toLowerCase().includes(searchTerm));
            }
            return serialNumber.includes(searchTerm) || macAddress.includes(searchTerm) || pppoe_username.includes(searchTerm) || identity.includes(searchTerm) || tagsMatch;
        });
    }

    // 4. Sorting (kalau sedang aktif)
    if (currentSortColumn) {
        devicesToRender = applySorting(devicesToRender, currentSortColumn, currentSortDirection);
    }

    // 4b. Perangkat offline selalu di bawah perangkat online (urutan di dalam tiap grup tidak berubah).
    // Dilewati kalau user sengaja mengurutkan kolom Status, supaya urutan pilihannya dihormati.
    if (currentSortColumn !== 'status') {
        const onlineList = [], offlineList = [];
        devicesToRender.forEach(d => (d.status === 'online' ? onlineList : offlineList).push(d));
        // Tanpa pengurutan kolom dari user: yang BARU saja offline (terakhir terlihat paling baru) di atas grup offline,
        // yang sudah lama mati di paling bawah. Tanpa data last_inform dianggap paling lama.
        // last_inform berformat "YYYY-MM-DD HH:MM:SS" jadi bisa dibandingkan sebagai teks.
        if (!currentSortColumn) {
            const seen = d => (d.last_inform && d.last_inform !== 'N/A') ? d.last_inform : '';
            offlineList.sort((a, b) => (seen(a) < seen(b) ? 1 : (seen(a) > seen(b) ? -1 : 0)));
        }
        devicesToRender = onlineList.concat(offlineList);
    }

    // 5. Jaga-jaga halaman melebihi jumlah halaman yang ada
    if (itemsPerPage > 0) {
        const maxPage = Math.max(1, Math.ceil(devicesToRender.length / itemsPerPage));
        if (currentPage > maxPage) currentPage = maxPage;
    }

    // 6. Render
    renderDevices(devicesToRender);
    updateDeviceCount(devicesToRender.length, getScopeDevices().length);

    refreshTypeBadges(devicesToRender.length);
}

// ---------------------------------------------------------------------------
// AUTO-TAGGING berdasarkan segmen IP
// ---------------------------------------------------------------------------
async function syncRbTags() {
    if (!allDevices || allDevices.length === 0) {
        showToast('Tidak ada data device untuk disinkronisasi', 'warning');
        return;
    }

    // Daftar RouterBoard: nama tag -> awalan IP TR-069. Tambah RB baru cukup di sini.
    const rbMapping = {
        'RB_56c':     { prefix: '10.123.', ids: [] },
        'RB_Klaling': { prefix: '10.124.', ids: [] },
        'RB_Sosok':   { prefix: '10.125.', ids: [] },
        'RB_Payaman': { prefix: '10.126.', ids: [] },
        'RB_Bram':    { prefix: '10.127.', ids: [] },
        'RB_Cendono': { prefix: '10.128.', ids: [] },
        'RB_Buyutan': { prefix: '10.129.', ids: [] }
    };
    const rbTagNames = Object.keys(rbMapping).map(n => n.toLowerCase());

    // Scan semua perangkat
    allDevices.forEach(device => {
        const ip = extractIP(device.ip_tr069);
        if (ip === 'N/A') return;

        // Lewati perangkat yang sudah punya salah satu tag RB
        const tags = device.tags || [];
        const hasRbTag = tags.some(t => rbTagNames.includes(String(t).toLowerCase()));
        if (hasRbTag) return;

        for (const [tagName, data] of Object.entries(rbMapping)) {
            if (ip.startsWith(data.prefix)) {
                data.ids.push(device.device_id);
                break;
            }
        }
    });

    const totalToSync = Object.values(rbMapping).reduce((sum, d) => sum + d.ids.length, 0);

    if (totalToSync === 0) {
        showToast('Semua device sudah memiliki Tag RB. Tidak ada yang perlu disinkronisasi.', 'info');
        return;
    }

    // Minta konfirmasi dari user sebelum eksekusi massal
    if (!confirm(`Ditemukan ${totalToSync} ONU baru tanpa Tag RB. Lanjutkan proses Auto-Tagging?`)) {
        return;
    }

    showLoading();

    try {
        let okTotal = 0;
        let failTotal = 0;
        const failedTags = [];

        // Eksekusi API bulk-tag untuk setiap kelompok RB
        for (const [tagName, data] of Object.entries(rbMapping)) {
            if (data.ids.length === 0) continue;

            const res = await fetchAPI('/api/bulk-tag.php', {
                method: 'POST',
                body: JSON.stringify({
                    action: 'add',
                    device_ids: data.ids,
                    tag: tagName
                }),
                timeout: 120000
            });

            if (res && res.success) {
                okTotal += (res.success_count !== undefined ? res.success_count : data.ids.length);
                failTotal += (res.fail_count || 0);
            } else {
                failTotal += data.ids.length;
                failedTags.push(tagName);
                console.error('[AUTO-TAG] Gagal untuk', tagName, res);
            }
        }

        hideLoading();

        if (failTotal === 0) {
            showToast(`Berhasil menempelkan tag pada ${okTotal} device!`, 'success');
        } else {
            showToast(`Tag terpasang di ${okTotal} device, gagal ${failTotal}` +
                (failedTags.length ? ` (${failedTags.join(', ')})` : ''), 'warning', 6000);
        }
        loadDevices(); // Muat ulang tabel agar badge tag langsung muncul

    } catch (error) {
        hideLoading();
        console.error('Error saat sync tags:', error);
        showToast('Terjadi kesalahan sistem saat sinkronisasi tag.', 'danger');
    }
}

// ---------------------------------------------------------------------------
// BULK SUMMON dengan verifikasi balasan ONU
//   Berhasil = ONU membalas dalam batas waktu yang dipilih.
//   Gagal    = tidak ada balasan dalam batas waktu (atau error). Hasil ditampilkan: berapa berhasil, berapa gagal.
// ---------------------------------------------------------------------------
let svState = null; // state proses summon yang sedang jalan / terakhir selesai

function svEsc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function svDeviceInfo(deviceId) {
    const d = (typeof allDevices !== 'undefined' ? allDevices : []).find(x => x.device_id === deviceId);
    return {
        sn: d ? (d.serial_number || deviceId) : deviceId,
        ip: d ? (d.ip_tr069 || d.ip_address || '') : ''
    };
}

function ensureSummonVerifyModal() {
    if (document.getElementById('summonVerifyModal')) return;
    const wrap = document.createElement('div');
    wrap.innerHTML = `
<div class="modal fade" id="summonVerifyModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-lightning-charge"></i> Summon Massal</h5>
        <button type="button" class="btn-close" id="sv-close-x" onclick="svClose()"></button>
      </div>
      <div class="modal-body">
        <!-- 1) konfirmasi -->
        <div id="sv-confirm">
          <p class="mb-2">Summon <strong id="sv-count"></strong> device. ONU yang <strong>membalas</strong> dinyatakan berhasil, yang <strong>tidak membalas</strong> dalam batas waktu dinyatakan gagal.</p>
          <label for="sv-timeout" class="form-label mb-1">Batas waktu balasan ONU</label>
          <select id="sv-timeout" class="form-select form-select-sm" style="max-width:260px">
            <option value="3000">3 detik (cepat)</option>
            <option value="5000" selected>5 detik (disarankan)</option>
            <option value="10000">10 detik</option>
            <option value="15000">15 detik (ONU lambat)</option>
          </select>
          <div class="form-text">Makin besar batas waktu, makin lama prosesnya kalau banyak ONU yang mati.</div>
        </div>
        <!-- 2) progres -->
        <div id="sv-progress" style="display:none">
          <div class="d-flex justify-content-between mb-1"><span id="sv-progress-text">Memproses...</span><span id="sv-progress-pct">0%</span></div>
          <div class="progress mb-3"><div class="progress-bar" id="sv-bar" style="width:0%"></div></div>
          <div class="d-flex gap-3">
            <span class="badge bg-success">Berhasil: <span id="sv-live-ok">0</span></span>
            <span class="badge bg-danger">Gagal: <span id="sv-live-fail">0</span></span>
            <span class="badge bg-secondary">Sisa: <span id="sv-live-left">0</span></span>
          </div>
        </div>
        <!-- 3) hasil -->
        <div id="sv-result" style="display:none">
          <div class="row text-center g-2 mb-3">
            <div class="col-4"><div class="border rounded p-2"><div class="text-muted small">Total</div><div class="fs-4 fw-bold" id="sv-res-total">0</div></div></div>
            <div class="col-4"><div class="border rounded p-2"><div class="text-success small">Berhasil (ONU membalas)</div><div class="fs-4 fw-bold text-success" id="sv-res-ok">0</div></div></div>
            <div class="col-4"><div class="border rounded p-2"><div class="text-danger small">Gagal (tidak membalas)</div><div class="fs-4 fw-bold text-danger" id="sv-res-fail">0</div></div></div>
          </div>
          <div id="sv-res-note" class="small text-muted mb-2"></div>
          <div id="sv-failed-wrap" style="display:none">
            <div class="fw-semibold mb-1">Daftar ONU gagal</div>
            <div style="max-height:260px; overflow:auto" class="border rounded">
              <table class="table table-sm table-striped mb-0">
                <thead><tr><th>SN</th><th>IP</th><th>Keterangan</th></tr></thead>
                <tbody id="sv-failed-body"></tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" id="sv-btn-cancel" onclick="svClose()">Batal</button>
        <button type="button" class="btn btn-primary" id="sv-btn-start" onclick="svStart()"><i class="bi bi-lightning-charge"></i> Mulai Summon</button>
        <button type="button" class="btn btn-outline-secondary" id="sv-btn-copy" style="display:none" onclick="svCopyFailed()"><i class="bi bi-clipboard"></i> Salin SN gagal</button>
        <button type="button" class="btn btn-warning" id="sv-btn-retry" style="display:none" onclick="svRetryFailed()"><i class="bi bi-arrow-repeat"></i> Coba lagi yang gagal</button>
        <button type="button" class="btn btn-secondary" id="sv-btn-done" style="display:none" onclick="svClose()">Tutup</button>
      </div>
    </div>
  </div>
</div>`;
    document.body.appendChild(wrap.firstElementChild);
}

function svShow(stage) {
    document.getElementById('sv-confirm').style.display = stage === 'confirm' ? '' : 'none';
    document.getElementById('sv-progress').style.display = stage === 'progress' ? '' : 'none';
    document.getElementById('sv-result').style.display = stage === 'result' ? '' : 'none';
    document.getElementById('sv-btn-start').style.display = stage === 'confirm' ? '' : 'none';
    document.getElementById('sv-btn-cancel').style.display = stage === 'result' ? 'none' : '';
    document.getElementById('sv-btn-cancel').textContent = stage === 'progress' ? 'Hentikan' : 'Batal';
    document.getElementById('sv-btn-done').style.display = stage === 'result' ? '' : 'none';
    document.getElementById('sv-close-x').style.display = stage === 'progress' ? 'none' : '';
}

function summonSelectedDevices() {
    const deviceIds = getSelectedDeviceIds().filter(id => id && id.trim() !== '');

    if (deviceIds.length === 0) {
        showToast('Pilih setidaknya satu device di tabel untuk disummon!', 'warning');
        return;
    }
    openSummonVerify(deviceIds);
}

function openSummonVerify(deviceIds) {
    ensureSummonVerifyModal();
    svState = { ids: deviceIds, running: false, stop: false };
    document.getElementById('sv-count').textContent = deviceIds.length;
    svShow('confirm');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('summonVerifyModal')).show();
}

function svClose() {
    if (svState && svState.running) {      // tombol "Hentikan" saat proses jalan
        svState.stop = true;
        document.getElementById('sv-btn-cancel').disabled = true;
        document.getElementById('sv-btn-cancel').textContent = 'Menghentikan...';
        return;
    }
    const m = bootstrap.Modal.getInstance(document.getElementById('summonVerifyModal'));
    if (m) m.hide();
}

async function svStart() {
    if (!svState || svState.running) return;
    const timeoutMs = parseInt(document.getElementById('sv-timeout').value, 10) || 5000;
    const ids = svState.ids;

    // Satu request menangani N device dengan 40 koneksi paralel; hitung N supaya 1 request tidak lebih dari ~45 detik.
    const waves = Math.max(1, Math.floor(45000 / timeoutMs));
    const batchSize = Math.min(300, 40 * waves);

    svState.running = true;
    svState.stop = false;
    bulkBusy = true; // pause auto-refresh selama proses
    let ok = 0, fail = 0, done = 0, faults = 0, tVerify = 0, tFill = 0;
    const failed = [];

    svShow('progress');
    const btnCancel = document.getElementById('sv-btn-cancel');
    btnCancel.disabled = false;
    const upd = () => {
        const pct = Math.round(done / ids.length * 100);
        document.getElementById('sv-bar').style.width = pct + '%';
        document.getElementById('sv-progress-pct').textContent = pct + '%';
        document.getElementById('sv-progress-text').textContent = `Memproses ${done}/${ids.length} (menunggu balasan ONU maks. ${Math.round(timeoutMs / 1000)} detik)`;
        document.getElementById('sv-live-ok').textContent = ok;
        document.getElementById('sv-live-fail').textContent = fail;
        document.getElementById('sv-live-left').textContent = ids.length - done;
    };
    upd();

    const t0 = performance.now();
    try {
        for (let i = 0; i < ids.length; i += batchSize) {
            if (svState.stop) break;
            const batch = ids.slice(i, i + batchSize);

            const result = await fetchAPI('/api/summon-all.php', {
                method: 'POST',
                timeout: 150000,
                body: JSON.stringify({ device_ids: batch, timeout_ms: timeoutMs })
            });

            if (result && result.success) {
                ok += result.success_count || 0;
                fail += result.fail_count || 0;
                faults += result.fault_count || 0;
                tVerify += (result.timing && result.timing.verify_s) || 0;
                tFill += (result.timing && result.timing.fill_s) || 0;
                (result.failed || []).forEach(f => failed.push(f));
            } else {
                // Request satu batch gagal total: semua device di batch ini dihitung gagal
                const why = (result && result.message) || 'Request ke server gagal / timeout';
                batch.forEach(id => failed.push({ id: id, reason: 'error', detail: why }));
                fail += batch.length;
            }
            done += batch.length;
            upd();
        }
    } catch (e) {
        console.error('Error saat summon massal:', e);
        showToast('Terjadi kesalahan sistem di tengah proses summon.', 'danger');
    }

    const secs = ((performance.now() - t0) / 1000).toFixed(1);
    svState.running = false;
    svState.failed = failed;
    bulkBusy = false;
    svRenderResult({ total: ids.length, ok, fail, faults, notProcessed: ids.length - done, secs, timeoutMs, tVerify, tFill });
    resetSelection();

    // Modem butuh waktu mengisi data (SSID, client); refresh tabel setelah jeda
    if (ok > 0) setTimeout(() => loadDevices(true), 30000);
}

function svRenderResult(r) {
    svShow('result');
    document.getElementById('sv-res-total').textContent = r.total;
    document.getElementById('sv-res-ok').textContent = r.ok;
    document.getElementById('sv-res-fail').textContent = r.fail;

    const notes = [`Selesai dalam ${r.secs} detik (batas waktu balasan ${Math.round(r.timeoutMs / 1000)} detik).`];
    if (r.faults) notes.push(`${r.faults} ONU membalas tetapi mengembalikan fault (tetap dihitung berhasil).`);
    if (r.notProcessed > 0) notes.push(`Dihentikan: ${r.notProcessed} device belum diproses.`);
    if (r.tVerify) notes.push(`Rincian: tunggu balasan ONU ${r.tVerify.toFixed(1)} dtk, kirim task pengisian data ${r.tFill.toFixed(1)} dtk.`);
    if (r.ok > 0) notes.push('Data modem (SSID, client) terisi bertahap 1-3 menit.');
    document.getElementById('sv-res-note').textContent = notes.join(' ');

    const failed = svState.failed || [];
    const wrap = document.getElementById('sv-failed-wrap');
    const body = document.getElementById('sv-failed-body');
    body.innerHTML = failed.map(f => {
        const d = svDeviceInfo(f.id);
        const why = f.reason === 'no_response' ? 'Tidak ada balasan dari ONU' : 'Error: ' + (f.detail || 'tidak diketahui');
        return `<tr><td><a href="/device-detail.php?id=${encodeURIComponent(f.id)}" target="_blank">${svEsc(d.sn)}</a></td><td>${svEsc(d.ip)}</td><td>${svEsc(why)}</td></tr>`;
    }).join('');
    wrap.style.display = failed.length ? '' : 'none';
    document.getElementById('sv-btn-copy').style.display = failed.length ? '' : 'none';
    document.getElementById('sv-btn-retry').style.display = failed.length ? '' : 'none';
}

function svCopyFailed() {
    const text = (svState.failed || []).map(f => svDeviceInfo(f.id).sn).join('\n');
    const done = () => showToast(`${svState.failed.length} SN gagal disalin`, 'success');
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, () => showToast('Gagal menyalin', 'danger'));
    } else {
        const ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); done(); } catch (e) { showToast('Gagal menyalin', 'danger'); }
        ta.remove();
    }
}

function svRetryFailed() {
    const ids = (svState.failed || []).map(f => f.id);
    if (!ids.length) return;
    openSummonVerify(ids);   // kembali ke layar konfirmasi, batas waktu bisa dinaikkan
}

// ---------------------------------------------------------------------------
// SHIFT+KLIK: pilih rentang checkbox
// ---------------------------------------------------------------------------
let lastCheckedValue = null; // simpan ID device, bukan elemen (elemen hilang saat tabel dirender ulang)

document.addEventListener('click', function (e) {
    const cb = e.target;
    if (!cb || !cb.classList || !cb.classList.contains('device-checkbox')) return;

    const boxes = Array.from(document.querySelectorAll('.device-checkbox'));

    if (e.shiftKey && lastCheckedValue !== null) {
        const a = boxes.findIndex(b => b.value === lastCheckedValue);
        const b = boxes.indexOf(cb);

        if (a !== -1 && b !== -1) {
            const from = Math.min(a, b);
            const to = Math.max(a, b);
            for (let i = from; i <= to; i++) {
                boxes[i].checked = cb.checked;
            }
            updateBulkActionButtons();
        }
    }

    lastCheckedValue = cb.value;
});