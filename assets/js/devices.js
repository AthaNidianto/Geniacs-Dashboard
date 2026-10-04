/**
 * devices.js
 * Main JavaScript for devices page
 * Global state variables are defined in devices-state.js
 */

// State tambahan (khusus file ini)
let currentStatusFilter = 'all'; // Filter status aktif: all | online | offline
let bulkBusy = false;            // true selama bulk action jalan (auto-refresh di-pause)
let currentRxFilter = 'all';     // Filter redaman aktif: all | bagus | warning | kritis
let currentFilterType = 'all'    // Filter type aktif: onu | pon

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
    allDevices.forEach(d => { counts[getBrandKey(d)] = 0; });
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
    const chunkSize = 500;
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

        // Load remaining chunks
        while (hasMore) {
            const chunk = await fetchAPI(`/api/get-devices.php?limit=${chunkSize}&skip=${skip}`);

            if (!chunk || !chunk.success) break;

            allDevices = allDevices.concat(chunk.devices || []);
            hasMore = chunk.hasMore;
            skip += chunkSize;

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
            generateTableHeader('onu');

            // Update search placeholder for ONU tab (default)
            updateSearchPlaceholder('onu');
        }

        // Update tab counts dulu (nulis total mentah)...
        if (mapCountsResult && mapCountsResult.success) {
            updateDeviceTypeCountsFromMap(allDevices, mapCountsResult.counts);
        } else {
            updateDeviceTypeCountsFromMap(allDevices, {});
        }

        // ...baru render dengan filter RB/status/search/sort, supaya badge terakhir
        // ditulis oleh hasil filter
        if (currentFilterType === 'onu') {
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

    // Determine appropriate colspan based on current filter type
    const colspan = (currentFilterType === 'onu') ? 12 : 6;

    if (devices.length === 0) {
        // If showing infrastructure items, show map items instead
        if (currentFilterType !== 'onu') {
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
                rxDisplay = `<span class="badge ${rxBadgeClass}">${device.rx_power} dBm</span>`;
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
            rxDisplay = `<span class="badge ${rxBadgeClass}">${device.rx_power} dBm</span>`;
        }

        // Map button - conditional based on registration status
        let mapButton;
        if (isInMap) {
            // Green button - opens map in new tab
            let mapUrl;
            if (mapInfo.itemType === 'mikrotik') {
                // For MikroTik devices, focus on server
                mapUrl = `/map.php?focus_type=server&focus_id=${mapInfo.itemId}`;
            } else {
                // For ONU devices, focus on ONU
                mapUrl = `/map.php?focus_type=onu&focus_serial=${encodeURIComponent(device.serial_number)}`;
            }
            mapButton = `<button class="btn btn-sm btn-success me-1" onclick="window.open('${mapUrl}', '_blank')" title="View on Map">
                <i class="bi bi-map"></i>
            </button>`;
        } else {
            // Gray button - shows alert
            mapButton = `<button class="btn btn-sm btn-secondary me-1" onclick="showNotInMapAlert('${encodeURIComponent(device.serial_number)}')" title="Not Registered in Map">
                <i class="bi bi-map"></i>
            </button>`;
        }

        // Status badge with ping
        let statusDisplay;
        if (device.status === 'online') {
            const ping = device.ping || '-';
            statusDisplay = `<span class="badge online">ON [${ping}ms]</span>`;
        } else {
            statusDisplay = `<span class="badge offline">OFF [-]</span>`;
        }

        // Tags display - show as badges
        let tagsDisplay = '';
        let tagsSortValue = '';
        if (device.tags && Array.isArray(device.tags) && device.tags.length > 0) {
            tagsDisplay = device.tags.map(tag => `<span class="badge bg-info me-1">${tag}</span>`).join('');
            tagsSortValue = device.tags.join(', '); // For sorting: join tags as string
        } else {
            tagsDisplay = '<span class="text-muted">-</span>';
            tagsSortValue = ''; // Empty for sorting (will be sorted to bottom)
        }

        // Check tags column visibility state for consistent display
        const tagsColumnDisplay = tagsColumnVisible ? '' : 'none';

        row.innerHTML = `
            <td>
                <input type="checkbox" class="device-checkbox" value="${encodeURIComponent(device.device_id)}" onchange="updateBulkActionButtons()">
            </td>
            <td><a href="/device-detail.php?id=${encodeURIComponent(device.device_id)}">${device.serial_number}</a></td>
            <td>${device.mac_address}</td>
            <td data-sort-value="${device.product_class || ''}">${device.product_class || 'N/A'}</td>
            <td data-sort-value="${ipAddress}">${ipDisplay}</td>
            <td data-sort-value="${device.wifi_ssid}">${device.wifi_ssid}</td>
            <td data-sort-value="${device.pppoe_username || ''}">${device.pppoe_username || 'N/A'}</td>
            <td data-sort-value="${parseFloat(device.rx_power) || -999}">${rxDisplay}</td>
            <td data-sort-value="${parseFloat(device.temperature) || -999}">${device.temperature}°C</td>
            <td data-sort-value="${clientsCount}" class="text-center">${clientsBadge}</td>
            <td data-sort-value="${device.status}">${statusDisplay}</td>
            <td class="tags-column" data-sort-value="${tagsSortValue}" style="display: ${tagsColumnDisplay};">${tagsDisplay}</td>
            <td>
                ${mapButton}
                <button class="btn btn-sm btn-primary" onclick="summonDeviceQuick('${device.device_id}')" title="Summon Device">
                    <i class="bi bi-lightning-charge"></i>
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
        const displayName = itemType === 'olt' ? `${item.name} (${item.server_name})` : item.name;

        row.innerHTML = `
            <td>${displayName}</td>
            <td><span class="badge bg-primary">${itemType.toUpperCase()}</span></td>
            <td>${lat}</td>
            <td>${lng}</td>
            <td>${statusBadge}</td>
            <td>
                <button class="btn btn-sm btn-success" onclick="window.open('/map.php?focus_type=server&focus_id=${item.id}', '_blank')" title="View on Map">
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
    const onuBadge = document.getElementById('count-onu');
    if (onuBadge) {
        onuBadge.textContent = getRbFilteredDevices().length;
    }
}

// Generate table header based on device type
function generateTableHeader(type) {
    const tableHeader = document.getElementById('table-header');

    if (type === 'onu') {
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

    if (type === 'onu') {
        // ONU: lewat master filter supaya filter RB/status tetap kepakai
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
    if (type === 'onu') {
        searchInput.placeholder = 'Search by Serial Number, MAC Address, or Tags...';
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
    const columnMap = {
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

    if (currentFilterType === 'onu') {
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

    if (currentFilterType === 'onu') {
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

    // Start auto-refresh timer
    if (window.GENIEACS_CONFIGURED) autoRefreshTimer = setInterval(() => loadDevices(true), 60000); // Auto-refresh every 60 seconds

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

// Also cleanup on page visibility change (when tab becomes hidden)
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        // Page is hidden, stop auto-refresh to save resources
        if (autoRefreshTimer) {
            clearInterval(autoRefreshTimer);
            autoRefreshTimer = null;
        }
    } else {
        // Page is visible again, restart auto-refresh
        if (!autoRefreshTimer) {
            if (window.GENIEACS_CONFIGURED) autoRefreshTimer = setInterval(() => loadDevices(true), 60000);
        }
    }
});

// ---------------------------------------------------------------------------
// MASTER FILTER (RB + Status + Search + Sort + Pagination)
// ---------------------------------------------------------------------------
function getRbFilteredDevices() {
    const rbElement = document.getElementById('rbFilter');
    const rbFilter = rbElement ? rbElement.value.toLowerCase() : 'all';
    if (rbFilter === 'all') return allDevices;

    const prefixMap = { '56c': '10.123.', 'klaling': '10.124.', 'sosok': '10.125.', 'payaman': '10.126.' };
    const prefix = prefixMap[rbFilter];
    if (!prefix) return [];

    return allDevices.filter(d => extractIP(d.ip_tr069).startsWith(prefix));
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
            let tagsMatch = false;
            if (device.tags && Array.isArray(device.tags) && device.tags.length > 0) {
                tagsMatch = device.tags.some(tag => tag.toLowerCase().includes(searchTerm));
            }
            return serialNumber.includes(searchTerm) || macAddress.includes(searchTerm) || pppoe_username.includes(searchTerm) || tagsMatch;
        });
    }

    // 4. Sorting (kalau sedang aktif)
    if (currentSortColumn) {
        devicesToRender = applySorting(devicesToRender, currentSortColumn, currentSortDirection);
    }

    // 5. Jaga-jaga halaman melebihi jumlah halaman yang ada
    if (itemsPerPage > 0) {
        const maxPage = Math.max(1, Math.ceil(devicesToRender.length / itemsPerPage));
        if (currentPage > maxPage) currentPage = maxPage;
    }

    // 6. Render
    renderDevices(devicesToRender);
    updateDeviceCount(devicesToRender.length, allDevices.length);

    const onuBadge = document.getElementById('count-onu');
    if (onuBadge) onuBadge.textContent = devicesToRender.length;
}

// ---------------------------------------------------------------------------
// AUTO-TAGGING berdasarkan segmen IP
// ---------------------------------------------------------------------------
async function syncRbTags() {
    if (!allDevices || allDevices.length === 0) {
        showToast('Tidak ada data device untuk disinkronisasi', 'warning');
        return;
    }

    // Siapkan penampung untuk mengelompokkan ID perangkat yang belum punya tag
    const rbMapping = {
        'RB_56c': { prefix: '10.123.', ids: [] },
        'RB_Klaling': { prefix: '10.124.', ids: [] },
        'RB_Sosok': { prefix: '10.125.', ids: [] },
        'RB_Payaman': { prefix: '10.126.', ids: [] } 
    };



    // Scan semua perangkat
    allDevices.forEach(device => {
        const ip = extractIP(device.ip_tr069);
        if (ip === 'N/A') return;

        // Cek apakah perangkat sudah memiliki tag RB (mengurangi beban API)
        const tags = device.tags || [];
        const hasRbTag = tags.some(t => ['rb_56c', 'rb_klaling', 'rb_sosok'].includes(t.toLowerCase()));

        if (!hasRbTag) {
            // Kelompokkan ID berdasarkan awalan IP
            if (ip.startsWith(rbMapping['RB_56c'].prefix)) {
                rbMapping['RB_56c'].ids.push(device.device_id);
            } else if (ip.startsWith(rbMapping['RB_Klaling'].prefix)) {
                rbMapping['RB_Klaling'].ids.push(device.device_id);
            } else if (ip.startsWith(rbMapping['RB_Sosok'].prefix)) {
                rbMapping['RB_Sosok'].ids.push(device.device_id);
            } else if (ip.startsWith(rbMapping['RB_Payaman'].prefix)) {
                rbMapping['RB_Payaman'].ids.push(device.device_id);
            }
        }
    });

    // Hitung total device yang butuh di-tag
    const totalToSync = rbMapping['RB_56c'].ids.length + rbMapping['RB_Klaling'].ids.length + rbMapping['RB_Sosok'].ids.length;

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
        // Eksekusi API bulk-tag untuk setiap kelompok RB
        for (const [tagName, data] of Object.entries(rbMapping)) {
            if (data.ids.length > 0) {
                await fetchAPI('/api/bulk-tag.php', {
                    method: 'POST',
                    body: JSON.stringify({
                        action: 'add',
                        device_ids: data.ids,
                        tag: tagName
                    })
                });
            }
        }

        hideLoading();
        showToast(`Berhasil menempelkan tag pada ${totalToSync} device!`, 'success');
        loadDevices(); // Muat ulang tabel agar badge birunya langsung muncul

    } catch (error) {
        hideLoading();
        console.error('Error saat sync tags:', error);
        showToast('Terjadi kesalahan sistem saat sinkronisasi tag.', 'danger');
    }
}

// ---------------------------------------------------------------------------
// BULK SUMMON (Smart Queuing di backend + chunking 5 device di frontend)
// ---------------------------------------------------------------------------
async function summonSelectedDevices() {
    const deviceIds = getSelectedDeviceIds().filter(id => id && id.trim() !== '');

    if (deviceIds.length === 0) {
        showToast('Pilih setidaknya satu device di tabel untuk disummon!', 'warning');
        return;
    }

    if (!confirm(`Yakin ingin melakukan Summon massal pada ${deviceIds.length} device?`)) {
        return;
    }

    bulkBusy = true; // pause auto-refresh selama proses
    const btn = document.getElementById('btn-bulk-summon');
    const btnOriginal = btn ? btn.innerHTML : '';
    showLoading();

    // Pengiriman task dilakukan PARALEL di server (curl_multi), jadi browser cukup kirim
    // beberapa request besar. 300 device/request aman dari timeout; turunkan kalau muncul "Request timeout".
    const batchSize = 300;
    let successTotal = 0;
    let failTotal = 0;
    let doneCount = 0;
    const t0 = performance.now();

    try {
        for (let i = 0; i < deviceIds.length; i += batchSize) {
            const batch = deviceIds.slice(i, i + batchSize);

            const result = await fetchAPI('/api/summon-all.php', {
                method: 'POST',
                body: JSON.stringify({ device_ids: batch })
            });

            if (result && result.success) {
                successTotal += (result.success_count || 0);
                failTotal += (result.fail_count || 0);
            } else {
                failTotal += batch.length;
            }

            doneCount += batch.length;
            if (btn) btn.textContent = `Summon ${doneCount}/${deviceIds.length}`;
        }

        const secs = ((performance.now() - t0) / 1000).toFixed(1);
        showToast(
            `Perintah summon terkirim ke ${successTotal} device dalam ${secs} detik${failTotal ? `, gagal ${failTotal}` : ''}. Data modem akan terisi bertahap (1-3 menit).`,
            failTotal ? 'warning' : 'success'
        );
    } catch (error) {
        console.error('Error saat summon massal:', error);
        showToast('Terjadi kesalahan sistem di tengah proses summon.', 'danger');
    } finally {
        hideLoading();
        if (btn) btn.innerHTML = btnOriginal;
        resetSelection();
        bulkBusy = false;
        // Modem butuh waktu menjawab; refresh data setelah jeda
        setTimeout(() => loadDevices(true), 30000);
    }
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