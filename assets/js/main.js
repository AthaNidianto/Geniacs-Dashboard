
// Keamanan XSS: data dari ONU/CPE (SSID, hostname, versi firmware, nama WAN, dll) TIDAK boleh dimasukkan
// mentah ke innerHTML. Selalu lewat escapeHtml() (teks / atribut) atau escapeAttrJson() (JSON di dalam atribut).
function escapeHtml(v) {
    return String(v === null || v === undefined ? '' : v)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// JSON untuk dipakai di dalam atribut HTML, misal onclick='fn(${escapeAttrJson(obj)})'.
// Browser men-decode entity sebelum JS jalan, jadi JSON-nya tetap utuh tapi tidak bisa keluar dari atribut.
function escapeAttrJson(obj) {
    return escapeHtml(JSON.stringify(obj));
}

// Toast Notification
function showToast(message, type = 'info', duration = 3000) {
    const toast = document.createElement('div');
    toast.className = `alert alert-${type}`;
    toast.style.position = 'fixed';
    toast.style.top = '20px';
    toast.style.right = '20px';
    toast.style.zIndex = '9999';
    toast.style.minWidth = '300px';
    toast.style.maxWidth = '500px';
    toast.style.animation = 'slideInRight 0.3s ease';
    toast.textContent = message;

    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

// AJAX Helper
async function fetchAPI(url, options = {}) {
    try {
        // Add timeout support (default 15 seconds)
        const timeout = options.timeout || 15000;
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);

        const response = await fetch(url, {
            ...options,
            signal: controller.signal,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                ...options.headers
            }
        });

        clearTimeout(timeoutId);

        // Check if response is OK
        if (!response.ok) {
            console.error('HTTP Error:', response.status, response.statusText, 'URL:', url);
        }

        // Get content type
        const contentType = response.headers.get('content-type');

        // Check if response is JSON
        if (contentType && contentType.includes('application/json')) {
            const data = await response.json();
            return data;
        } else {
            // Response is not JSON (probably HTML error page or PHP error)
            const text = await response.text();
            console.error('Non-JSON response from', url, ':', text.substring(0, 500));

            // Show first 200 chars of error in toast for debugging
            const errorPreview = text.substring(0, 200).replace(/<[^>]*>/g, ''); // Remove HTML tags
            showToast('Server error: ' + errorPreview, 'danger');
            return null;
        }
    } catch (error) {
        // Check if error is due to abort (timeout or user navigated away)
        if (error.name === 'AbortError') {
            // Log but don't show toast for timeout/abort (will be handled by calling code)
            console.debug('Request aborted for', url);
            return { success: false, error: 'timeout', message: 'Request timeout' };
        }

        // Log other fetch errors
        console.error('Fetch error for', url, ':', error);

        // Only show toast for non-abort errors
        if (!url.includes('/api/get-hotspot-traffic.php')) {
            // Don't show toast for hotspot API errors (handled separately)
            showToast('Terjadi kesalahan koneksi: ' + error.message, 'danger');
        }
        return { success: false, error: error.name, message: error.message };
    }
}

// Format timestamp
function formatTimestamp(timestamp) {
    const date = new Date(timestamp);
    return date.toLocaleString('id-ID');
}

// Confirm dialog
function confirmAction(message) {
    return confirm(message);
}

// Loading overlay
function showLoading() {
    const overlay = document.createElement('div');
    overlay.id = 'loading-overlay';
    overlay.style.position = 'fixed';
    overlay.style.top = '0';
    overlay.style.left = '0';
    overlay.style.width = '100%';
    overlay.style.height = '100%';
    overlay.style.background = 'rgba(0, 0, 0, 0.5)';
    overlay.style.zIndex = '9998';
    overlay.style.display = 'flex';
    overlay.style.justifyContent = 'center';
    overlay.style.alignItems = 'center';

    overlay.innerHTML = '<div class="spinner"></div>';
    document.body.appendChild(overlay);
}

function hideLoading() {
    const overlay = document.getElementById('loading-overlay');
    if (overlay) {
        overlay.remove();
    }
}

// Toggle sidebar for mobile
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
        sidebar.classList.toggle('active');
    }
}

// Auto refresh data
function autoRefresh(callback, interval = 30000) {
    setInterval(callback, interval);
}

// Copy to clipboard
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showToast('Berhasil disalin!', 'success');
    }).catch(() => {
        showToast('Gagal menyalin', 'danger');
    });
}

// Format uptime
function formatUptime(seconds) {
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    let result = '';
    if (days > 0) result += days + ' hari ';
    if (hours > 0) result += hours + ' jam ';
    if (minutes > 0) result += minutes + ' menit';

    return result || '0 menit';
}

// Add animation styles
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @keyframes slideOutRight {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);

// Sidebar: di desktop bisa diciutkan (tersimpan di localStorage); di HP/tablet (<= 991px) jadi laci geser
// yang dibuka dengan tombol menu di topbar dan ditutup lewat layar gelap, tombol Esc, atau memilih menu.
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const menuToggle = document.getElementById('menuToggle');
    const backdrop = document.getElementById('sidebarBackdrop');

    if (!sidebar || !mainContent) {
        return;
    }

    const mobileMq = window.matchMedia('(max-width: 991.98px)');
    const toggleIcon = sidebarToggle ? sidebarToggle.querySelector('i') : null;

    function setIcon(collapsed) {
        if (!toggleIcon) return;
        toggleIcon.classList.toggle('bi-chevron-right', collapsed);
        toggleIcon.classList.toggle('bi-chevron-left', !collapsed);
    }

    function storedCollapsed() {
        try { return localStorage.getItem('sidebarCollapsed') === 'true'; } catch (e) { return false; }
    }

    function setDrawer(open) {
        sidebar.classList.toggle('active', open);
        if (backdrop) backdrop.classList.toggle('show', open);
        document.body.classList.toggle('nav-open', open);
        if (menuToggle) {
            menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            menuToggle.setAttribute('aria-label', open ? 'Tutup menu' : 'Buka menu');
        }
    }

    // Pasang tampilan sesuai ukuran layar: HP selalu menu penuh (tidak diciutkan), desktop ikut pilihan tersimpan
    function applyLayout() {
        if (mobileMq.matches) {
            sidebar.classList.remove('collapsed');
            mainContent.classList.remove('collapsed');
            setIcon(false);
        } else {
            setDrawer(false);
            const collapsed = storedCollapsed();
            sidebar.classList.toggle('collapsed', collapsed);
            mainContent.classList.toggle('collapsed', collapsed);
            setIcon(collapsed);
        }
    }
    applyLayout();
    if (mobileMq.addEventListener) {
        mobileMq.addEventListener('change', applyLayout);
    } else if (mobileMq.addListener) {
        mobileMq.addListener(applyLayout);
    }

    // Desktop: ciutkan / lebarkan sidebar
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function(e) {
            e.preventDefault();
            if (mobileMq.matches) {
                setDrawer(false);
                return;
            }
            const collapsed = sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('collapsed', collapsed);
            setIcon(collapsed);
            try { localStorage.setItem('sidebarCollapsed', collapsed ? 'true' : 'false'); } catch (e2) {}
        });
    }

    // HP/tablet: buka / tutup laci menu
    if (menuToggle) {
        menuToggle.addEventListener('click', function() {
            setDrawer(!sidebar.classList.contains('active'));
        });
    }
    if (backdrop) {
        backdrop.addEventListener('click', function() { setDrawer(false); });
    }
    sidebar.querySelectorAll('.sidebar-menu a').forEach(function(a) {
        a.addEventListener('click', function() { setDrawer(false); });
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') setDrawer(false);
    });
});