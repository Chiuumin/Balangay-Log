const officerApi = 'api/officer_console.php';
const pageNotice = document.getElementById('pageNotice');
const navLinks = [...document.querySelectorAll('.admin-nav-link[data-view]')];

const state = {
    cases: [],
    triageQueue: [],
    urgentCases: [],
    luponCases: [],
    tanods: [],
    notifications: [],
    criticalCase: null,
    filter: 'ALL',
    lastNotifCount: 0
};

const escapeHtml = (val) => String(val ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
}[c]));

const formatDate = (val) => val ? new Date(String(val).replace(' ', 'T')).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '—';

function timeAgo(dateString) {
    if (!dateString) return 'just now';
    const seconds = Math.floor((new Date() - new Date(String(dateString).replace(' ', 'T'))) / 1000);
    if (seconds < 60) return `${Math.max(1, seconds)}s ago`;
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    return formatDate(dateString);
}

function priorityPill(priority) {
    const p = String(priority || 'Low').toLowerCase();
    if (p === 'critical' || p === 'urgent') return `<span class="pill pill-rejected" style="font-weight: 800;">Critical</span>`;
    if (p === 'high') return `<span class="pill" style="background: #fdf0ed; color: #d63c22; font-weight: 800;">High</span>`;
    if (p === 'moderate' || p === 'medium') return `<span class="pill pill-pending">Medium</span>`;
    return `<span class="pill" style="background: #eef2f2; color: #52676f;">Low</span>`;
}

function statusPill(status) {
    const s = String(status || '').toUpperCase();
    if (s === 'RESOLVED') return `<span class="pill pill-active">Resolved</span>`;
    if (s === 'DISPATCHED') return `<span class="pill pill-blue">Dispatched</span>`;
    if (s === 'IN_PROGRESS') return `<span class="pill pill-blue">In Progress</span>`;
    if (s === 'CRITICAL') return `<span class="pill pill-rejected">Critical</span>`;
    if (s === 'FOR_RESOLUTION') return `<span class="pill pill-pending">Lupon</span>`;
    return `<span class="pill pill-pending">Pending Triage</span>`;
}

function showNotice(msg, isError = false) {
    pageNotice.textContent = msg;
    pageNotice.classList.toggle('is-error', isError);
    pageNotice.hidden = false;
    clearTimeout(showNotice.timeout);
    showNotice.timeout = setTimeout(() => { pageNotice.hidden = true; }, 4500);
}

async function requestOfficer(action, payload = {}) {
    const headers = { 
        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
        'Content-Type': 'application/json'
    };
    const response = await fetch(officerApi, {
        method: action ? 'POST' : 'GET',
        credentials: 'same-origin',
        headers,
        body: action ? JSON.stringify({ action, ...payload }) : undefined
    });
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'Operation failed.');
    return result;
}

function setView(view) {
    navLinks.forEach(link => link.classList.toggle('is-active', link.dataset.view === view));
    document.querySelectorAll('.admin-view').forEach(sec => { sec.hidden = sec.id !== `view-${view}`; });
    history.replaceState(null, '', `#${view}`);
}

// -------------------------------------------------------------
// TAB RENDERERS (Deep Green-Slate Component Mapping)
// -------------------------------------------------------------

// 1. Triage Queue (Desk + Dedicated)
function renderTriageQueue() {
    const container = document.getElementById('triageQueueContainer');
    const dedicated = document.getElementById('dedicatedTriageContainer');
    const queue = state.triageQueue;

    document.getElementById('triageQueuePill').textContent = `${queue.length} pending`;
    document.getElementById('triageNavBadge').textContent = queue.length;

    if (queue.length === 0) {
        const emptyHtml = '<p class="empty-message">No pending citizen reports in triage queue.</p>';
        container.innerHTML = emptyHtml;
        if (dedicated) dedicated.innerHTML = emptyHtml;
        return;
    }

    const html = queue.map(item => `
        <div class="queue-item-card" data-ref="${escapeHtml(item.reference_number)}">
            <div class="queue-meta-block">
                <div class="queue-header-tag">
                    <span class="queue-id">${escapeHtml(item.reference_number)}</span>
                    ${priorityPill(item.priority_level)}
                    <span class="pill pill-pending">Review Pending</span>
                </div>
                <h3 class="queue-title">${escapeHtml(item.incident_type)}</h3>
                <p class="queue-details">
                    <strong>${escapeHtml(item.complainant_name)}</strong> • 
                    ${escapeHtml(item.complainant_phone || 'No phone')} • 
                    ${escapeHtml(item.purok)} • 
                    ${timeAgo(item.created_at)}
                </p>
                <p style="margin: 4px 0 0; font-size: 11.5px; color: var(--muted); line-height: 1.4;">
                    ${escapeHtml(item.narrative_description)}
                </p>
            </div>
            <div class="queue-action-group">
                <button class="btn-action-primary" data-action="accept" data-ref="${escapeHtml(item.reference_number)}">Accept &amp; Blotter</button>
                <button class="btn-action-quiet" data-action="dispatch" data-ref="${escapeHtml(item.reference_number)}">Dispatch</button>
                <button class="btn-action-quiet" data-action="endorse_lupon" data-ref="${escapeHtml(item.reference_number)}">Endorse Lupon</button>
                <button class="btn-action-danger" data-action="reject" data-ref="${escapeHtml(item.reference_number)}">Flag / Reject</button>
            </div>
        </div>
    `).join('');

    container.innerHTML = html;
    if (dedicated) dedicated.innerHTML = html;
}

// 2. Urgent & Emergency Cases Tab
function renderUrgentCases() {
    const container = document.getElementById('emergencyCardsContainer');
    const cases = state.urgentCases;
    document.getElementById('emergencyNavBadge').textContent = cases.length;

    if (cases.length === 0) {
        container.innerHTML = '<p class="empty-message">No critical or emergency incidents pending.</p>';
        return;
    }

    container.innerHTML = cases.map(c => `
        <div class="queue-item-card" style="border-left: 4px solid var(--coral);" data-ref="${escapeHtml(c.reference_number)}">
            <div class="queue-meta-block">
                <div class="queue-header-tag">
                    <span class="queue-id">${escapeHtml(c.reference_number)}</span>
                    ${priorityPill(c.priority_level)}
                    ${statusPill(c.status)}
                </div>
                <h3 class="queue-title">${escapeHtml(c.incident_type)}</h3>
                <p class="queue-details">
                    <strong>${escapeHtml(c.complainant_name)}</strong> • 
                    ${escapeHtml(c.purok)} • 
                    Reported: ${timeAgo(c.created_at)} • 
                    Responder: <strong>${escapeHtml(c.deployed_unit || 'Unassigned')}</strong>
                </p>
                <p style="margin: 4px 0 0; font-size: 11.5px; color: var(--muted);">
                    ${escapeHtml(c.narrative_description)}
                </p>
            </div>
            <div class="queue-action-group">
                <button class="button button-danger" data-action="dispatch" data-ref="${escapeHtml(c.reference_number)}">
                    ${c.deployed_unit ? 'Reassign Dispatch' : 'Acknowledge / Dispatch'}
                </button>
                <a href="tel:${escapeHtml(c.complainant_phone)}" class="btn-action-quiet" style="text-decoration: none;">Call Complainant</a>
            </div>
        </div>
    `).join('');
}

// 3. Lupon / Mediation Tab
function renderLuponCases() {
    const container = document.getElementById('luponCardsContainer');
    const cases = state.luponCases;
    document.getElementById('luponNavBadge').textContent = cases.length;

    if (cases.length === 0) {
        container.innerHTML = '<p class="empty-message">No cases currently scheduled for Lupon mediation.</p>';
        return;
    }

    container.innerHTML = cases.map(c => `
        <div class="queue-item-card" data-ref="${escapeHtml(c.reference_number)}">
            <div class="queue-meta-block">
                <div class="queue-header-tag">
                    <span class="queue-id">${escapeHtml(c.reference_number)}</span>
                    ${statusPill(c.status)}
                </div>
                <h3 class="queue-title">${escapeHtml(c.incident_type)}</h3>
                <p class="queue-details">
                    Complainant: <strong>${escapeHtml(c.complainant_name)}</strong> • 
                    Location: ${escapeHtml(c.purok)} • 
                    Mediator: <strong>${escapeHtml(c.assigned_officer || 'Barangay Captain / Secretary')}</strong>
                </p>
                <p style="margin: 4px 0 0; font-size: 11.5px; color: var(--muted);">
                    ${escapeHtml(c.narrative_description)}
                </p>
            </div>
            <div class="queue-action-group">
                <button class="btn-action-primary" data-action="settle_lupon" data-ref="${escapeHtml(c.reference_number)}">Mark as Settled</button>
                <button class="btn-action-quiet" data-action="reschedule_lupon" data-ref="${escapeHtml(c.reference_number)}">Hearing Schedule</button>
            </div>
        </div>
    `).join('');
}

// 4. Tanod Dispatch Board Tab
function renderTanodBoard() {
    const container = document.getElementById('tanodRosterContainer');
    const tanods = state.tanods;
    const available = tanods.filter(t => t.status === 'AVAILABLE').length;
    document.getElementById('activeTanodCount').textContent = `${available} Available`;

    container.innerHTML = tanods.map(t => {
        const isAvail = t.status === 'AVAILABLE';
        return `
            <div class="tanod-card">
                <div class="tanod-card-header">
                    <span class="tanod-indicator ${t.status.toLowerCase()}"></span>
                    <div>
                        <strong style="display: block; font-size: 13.5px; color: var(--ink);">${escapeHtml(t.name)}</strong>
                        <small style="color: var(--muted);">${escapeHtml(t.station)}</small>
                    </div>
                </div>
                <div>
                    ${isAvail 
                        ? `<button class="button button-primary" style="width: 100%; justify-content: center;" data-action="assign_tanod" data-tanod="${escapeHtml(t.name)}">Assign to Incident</button>`
                        : `<button class="button button-quiet" style="width: 100%; justify-content: center;" disabled>${t.status === 'DEPLOYED' ? 'Currently Deployed' : 'Off Duty'}</button>`
                    }
                </div>
            </div>
        `;
    }).join('');
}

// 5. All Blotter Records Table
function renderBlotterTable() {
    const search = document.getElementById('blotterSearchInput')?.value.trim().toLowerCase() || '';
    let cases = state.cases.filter(c => {
        if (!search) return true;
        return [c.reference_number, c.complainant_name, c.incident_type, c.purok, c.status]
            .some(v => String(v || '').toLowerCase().includes(search));
    });

    if (state.filter !== 'ALL') {
        cases = cases.filter(c => String(c.status).toUpperCase() === state.filter);
    }

    const tbody = document.getElementById('blotterTableRows');
    tbody.innerHTML = cases.map(c => `
        <tr>
            <td><strong style="font-family: monospace;">${escapeHtml(c.reference_number)}</strong></td>
            <td><strong>${escapeHtml(c.complainant_name)}</strong></td>
            <td>${escapeHtml(c.incident_type)}</td>
            <td>${escapeHtml(c.purok)}</td>
            <td>${priorityPill(c.priority_level)}</td>
            <td>${statusPill(c.status)}</td>
            <td>${escapeHtml(c.deployed_unit || 'Desk')}</td>
            <td>${formatDate(c.created_at)}</td>
            <td>
                <div class="row-actions">
                    <button class="small-button" data-action="dispatch" data-ref="${escapeHtml(c.reference_number)}">Dispatch</button>
                    <button class="small-button" data-action="endorse_lupon" data-ref="${escapeHtml(c.reference_number)}">Lupon</button>
                </div>
            </td>
        </tr>
    `).join('');
    document.getElementById('blotterEmptyMsg').hidden = cases.length > 0;
}

// 6. Dynamic Notifications
function renderNotifications() {
    const notifs = state.notifications || [];
    const notifBadge = document.getElementById('topbarNotifCount');
    const dropdownBadge = document.getElementById('notifDropdownBadge');
    const dropdownList = document.getElementById('notifDropdownList');

    const totalCount = notifs.length;
    dropdownBadge.textContent = `${totalCount} Pending / Urgent`;

    if (totalCount > 0) {
        notifBadge.textContent = totalCount > 99 ? '99+' : totalCount;
        notifBadge.style.display = 'grid';
        if (totalCount > state.lastNotifCount) {
            notifBadge.classList.add('pulse');
            setTimeout(() => notifBadge.classList.remove('pulse'), 3000);
        }
    } else {
        notifBadge.style.display = 'none';
    }
    state.lastNotifCount = totalCount;

    if (notifs.length === 0) {
        dropdownList.innerHTML = '<p class="empty-message" style="margin: 24px 0;">No active alerts.</p>';
        return;
    }

    dropdownList.innerHTML = notifs.map(n => {
        const isCrit = n.priority_level === 'Critical' || n.status === 'CRITICAL';
        return `
            <div class="notif-item" data-ref="${escapeHtml(n.reference_number)}">
                <div class="notif-item-top">
                    <span class="notif-item-title" style="${isCrit ? 'color: var(--coral);' : ''}">
                        ${isCrit ? '🚨' : '📝'} ${escapeHtml(n.reference_number)} • ${escapeHtml(n.incident_type)}
                    </span>
                    <span class="notif-item-time">${timeAgo(n.created_at)}</span>
                </div>
                <p class="notif-item-desc">
                    Reported by <strong>${escapeHtml(n.complainant_name)}</strong> in ${escapeHtml(n.purok)}
                </p>
            </div>
        `;
    }).join('');
}

// Notification Popover listeners
const notifBellBtn = document.getElementById('notifBellBtn');
const notifDropdown = document.getElementById('notifDropdown');

notifBellBtn?.addEventListener('click', (e) => {
    e.stopPropagation();
    notifDropdown?.classList.toggle('is-active');
});

document.getElementById('notifDropdownList')?.addEventListener('click', (e) => {
    const item = e.target.closest('.notif-item');
    if (!item) return;
    const ref = item.dataset.ref;
    notifDropdown.classList.remove('is-active');
    document.getElementById('dispatchRefInput').value = ref;
    document.getElementById('dispatchModal').showModal();
});

document.addEventListener('click', (e) => {
    if (!e.target.closest('.notif-container')) {
        notifDropdown?.classList.remove('is-active');
    }
});

// -------------------------------------------------------------
// REFRESH & BACKGROUND POLLING
// -------------------------------------------------------------
async function refreshOperationsData() {
    try {
        const data = await requestOfficer();
        state.cases = data.all_cases || [];
        state.triageQueue = data.triage_queue || [];
        state.urgentCases = data.urgent_cases || [];
        state.luponCases = data.lupon_cases || [];
        state.tanods = data.tanods || [];
        state.criticalCase = data.critical_case;
        state.notifications = data.notifications || [];

        // Real-Time Metrics
        document.getElementById('metricPendingTriage').textContent = data.metrics.pending_triage ?? 0;
        document.getElementById('metricActiveDispatched').textContent = data.metrics.active_dispatched ?? 0;
        document.getElementById('metricUnderInvestigation').textContent = data.metrics.under_investigation ?? 0;
        document.getElementById('metricResolvedToday').textContent = data.metrics.resolved_today ?? 0;

        // Banner Check
        const banner = document.getElementById('criticalBanner');
        if (state.criticalCase) {
            banner.hidden = false;
            document.getElementById('criticalBannerHeading').textContent = `Critical Case (${state.criticalCase.reference_number}) Needs Attention: ${state.criticalCase.incident_type}`;
            document.getElementById('criticalDispatchBtn').setAttribute('data-ref', state.criticalCase.reference_number);
        } else {
            banner.hidden = true;
        }

        renderNotifications();
        renderTriageQueue();
        renderUrgentCases();
        renderLuponCases();
        renderTanodBoard();
        renderBlotterTable();
    } catch (err) {
        showNotice(err.message, true);
    }
}

// Background auto-refresh polling every 10 seconds
setInterval(refreshOperationsData, 10000);

// -------------------------------------------------------------
// ACTION EVENT LISTENERS
// -------------------------------------------------------------
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-action]');
    if (!btn) return;
    const action = btn.dataset.action;
    const ref = btn.dataset.ref;

    try {
        if (action === 'accept') {
            const res = await requestOfficer('accept_blotter', { reference_number: ref });
            showNotice(res.message);
            await refreshOperationsData();
        } else if (action === 'reject') {
            if (confirm(`Flag and close case #${ref}?`)) {
                const res = await requestOfficer('flag_reject', { reference_number: ref });
                showNotice(res.message);
                await refreshOperationsData();
            }
        } else if (action === 'dispatch') {
            document.getElementById('dispatchRefInput').value = ref;
            document.getElementById('dispatchModal').showModal();
        } else if (action === 'endorse_lupon') {
            document.getElementById('luponRefInput').value = ref;
            document.getElementById('luponActionMode').value = 'endorse';
            document.getElementById('luponModalTitle').textContent = `Endorse #${ref} to Lupon Mediation`;
            document.getElementById('luponEndorseFields').style.display = 'block';
            document.getElementById('luponSettleFields').style.display = 'none';
            document.getElementById('luponSubmitBtn').textContent = 'Confirm Lupon Endorsement';
            document.getElementById('luponModal').showModal();
        } else if (action === 'settle_lupon') {
            document.getElementById('luponRefInput').value = ref;
            document.getElementById('luponActionMode').value = 'settle';
            document.getElementById('luponModalTitle').textContent = `Record Amicable Settlement for #${ref}`;
            document.getElementById('luponEndorseFields').style.display = 'none';
            document.getElementById('luponSettleFields').style.display = 'block';
            document.getElementById('luponSubmitBtn').textContent = 'Confirm Settlement';
            document.getElementById('luponModal').showModal();
        } else if (action === 'assign_tanod') {
            const tanod = btn.dataset.tanod;
            document.getElementById('dispatchUnitSelect').value = `Tanod Patrol (${tanod})`;
            setView('blotter');
            showNotice(`Select a case to assign ${tanod}`);
        }
    } catch (err) {
        showNotice(err.message, true);
    }
});

// Banner quick dispatch
document.getElementById('criticalDispatchBtn')?.addEventListener('click', (e) => {
    const ref = e.currentTarget.getAttribute('data-ref');
    if (ref) {
        document.getElementById('dispatchRefInput').value = ref;
        document.getElementById('dispatchModal').showModal();
    }
});

// Dispatch Form Submission
document.getElementById('dispatchForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const payload = Object.fromEntries(new FormData(e.currentTarget).entries());
    try {
        const res = await requestOfficer('dispatch_tanod', payload);
        document.getElementById('dispatchModal').close();
        showNotice(res.message);
        await refreshOperationsData();
    } catch (err) {
        showNotice(err.message, true);
    }
});

// Lupon Form Submission
document.getElementById('luponForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const mode = document.getElementById('luponActionMode').value;
    const ref = document.getElementById('luponRefInput').value;
    const formData = new FormData(e.currentTarget);

    try {
        if (mode === 'endorse') {
            const res = await requestOfficer('endorse_lupon', {
                reference_number: ref,
                mediator: formData.get('mediator'),
                schedule: formData.get('schedule')
            });
            showNotice(res.message);
        } else {
            const res = await requestOfficer('settle_lupon', {
                reference_number: ref,
                notes: formData.get('settlement_notes')
            });
            showNotice(res.message);
        }
        document.getElementById('luponModal').close();
        await refreshOperationsData();
    } catch (err) {
        showNotice(err.message, true);
    }
});

// Encode Walk-In Modal
const encodeDialog = document.getElementById('encodeCaseDialog');
document.querySelectorAll('#openEncodeModalBtn, #sidebarEncodeCaseBtn').forEach(btn => {
    btn?.addEventListener('click', () => {
        document.getElementById('encodeCaseForm').reset();
        encodeDialog.showModal();
    });
});

document.getElementById('encodeCaseForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const payload = Object.fromEntries(new FormData(e.currentTarget).entries());
    try {
        const res = await requestOfficer('encode_case', payload);
        encodeDialog.close();
        showNotice(res.message);
        await refreshOperationsData();
    } catch (err) {
        showNotice(err.message, true);
    }
});

// Blotter filters & refresh
document.querySelectorAll('[data-blotter-filter]').forEach(tab => {
    tab.addEventListener('click', () => {
        state.filter = tab.dataset.blotterFilter;
        document.querySelectorAll('[data-blotter-filter]').forEach(t => t.classList.toggle('is-selected', t === tab));
        renderBlotterTable();
    });
});

document.getElementById('blotterSearchInput')?.addEventListener('input', renderBlotterTable);
document.querySelector('[data-refresh-cases]')?.addEventListener('click', refreshOperationsData);
document.querySelectorAll('[data-close-dialog]').forEach(btn => btn.addEventListener('click', () => btn.closest('dialog').close()));

// View Switching
navLinks.forEach(link => link.addEventListener('click', () => setView(link.dataset.view)));

// Mobile Drawer
const mobileMenuBtn = document.getElementById('mobileMenuBtn');
const sidebarCloseBtn = document.getElementById('sidebarCloseBtn');
const sidebarBackdrop = document.getElementById('sidebarBackdrop');
const adminSidebar = document.getElementById('adminSidebar');

function toggleDrawer(open) {
    if (adminSidebar) adminSidebar.classList.toggle('is-open', open);
    if (sidebarBackdrop) sidebarBackdrop.classList.toggle('is-open', open);
    document.body.style.overflow = open ? 'hidden' : '';
}

mobileMenuBtn?.addEventListener('click', () => toggleDrawer(true));
sidebarCloseBtn?.addEventListener('click', () => toggleDrawer(false));
sidebarBackdrop?.addEventListener('click', () => toggleDrawer(false));

// Theme Toggle
const themeToggleBtn = document.getElementById('themeToggleBtn');
const themeToggleLabel = document.getElementById('themeToggleLabel');

function applyTheme(theme) {
    if (theme === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
        localStorage.setItem('balangay_theme', 'dark');
        if (themeToggleLabel) themeToggleLabel.textContent = 'Light Mode';
    } else {
        document.documentElement.removeAttribute('data-theme');
        localStorage.setItem('balangay_theme', 'light');
        if (themeToggleLabel) themeToggleLabel.textContent = 'Dark Mode';
    }
}
if (localStorage.getItem('balangay_theme') === 'dark') applyTheme('dark');
themeToggleBtn?.addEventListener('click', () => {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    applyTheme(isDark ? 'light' : 'dark');
});

// Logout
document.getElementById('logoutBtn')?.addEventListener('click', async () => {
    try { await fetch('api/logout.php', { method: 'POST', credentials: 'same-origin' }); } catch {}
    sessionStorage.clear();
    localStorage.removeItem('currentUser');
    localStorage.removeItem('authToken');
    window.location.replace('index.html');
});

const initialView = location.hash.slice(1);
if (initialView && document.getElementById(`view-${initialView}`)) setView(initialView);
refreshOperationsData();