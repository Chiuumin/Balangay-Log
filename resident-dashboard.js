const residentApi = 'api/resident_console.php';
const pageNotice = document.getElementById('pageNotice');
const navLinks = [...document.querySelectorAll('.admin-nav-link')];
const viewTitles = {
    overview: 'Resident Dashboard',
    reports: 'My Filed Reports',
    hotlines: 'Emergency Hotlines',
    profile: 'Profile & Settings'
};

const state = {
    reports: [],
    filter: 'all',
    selectedReport: null
};

const escapeHtml = (val) => String(val ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
}[c]));

const formatDate = (val) => val ? new Date(String(val).replace(' ', 'T')).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '—';

function statusPill(status) {
    const s = String(status || '').toUpperCase().trim();
    if (s === 'RESOLVED' || s === 'CLOSED') {
        return `<span class="pill pill-active">Resolved</span>`;
    }
    if (s === 'DISPATCHED' || s === 'UNIT DISPATCHED') {
        return `<span class="pill pill-blue">Unit Dispatched</span>`;
    }
    if (s === 'IN_PROGRESS' || s === 'IN PROGRESS') {
        return `<span class="pill pill-blue">In Progress</span>`;
    }
    if (s === 'FOR_RESOLUTION' || s === 'LUPON') {
        return `<span class="pill pill-pending">Lupon Mediation</span>`;
    }
    return `<span class="pill pill-pending">Under Review</span>`;
}

function showNotice(msg, isError = false) {
    pageNotice.textContent = msg;
    pageNotice.classList.toggle('is-error', isError);
    pageNotice.hidden = false;
    clearTimeout(showNotice.timeout);
    showNotice.timeout = setTimeout(() => { pageNotice.hidden = true; }, 4500);
}

async function requestResident(action, payload = {}, options = {}) {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const headers = { 
        'X-CSRF-Token': csrfMeta ? csrfMeta.content : '' 
    };

    let body;

    if (options.formData) {
        body = options.formData;
        if (action && !body.has('action')) {
            body.append('action', action);
        }
    } else {
        headers['Content-Type'] = 'application/json';
        body = action ? JSON.stringify({ action, ...payload }) : undefined;
    }

    const response = await fetch(residentApi, {
        method: action ? 'POST' : 'GET',
        credentials: 'same-origin',
        headers,
        body
    });

    const result = await response.json();
    if (!response.ok || !result.success) {
        throw new Error(result.message || 'The request could not be completed.');
    }
    return result;
}

function setView(view) {
    if (!viewTitles[view]) return;
    navLinks.forEach((link) => link.classList.toggle('is-active', link.dataset.view === view));
    document.querySelectorAll('.admin-view').forEach((sec) => { sec.hidden = sec.id !== `view-${view}`; });
    document.getElementById('viewTitle').textContent = viewTitles[view];
    history.replaceState(null, '', `#${view}`);
}

function renderOverview() {
    const total = state.reports.length;
    const review = state.reports.filter(r => ['PENDING', 'UNDER_REVIEW', 'UNDER REVIEW'].includes(String(r.status).toUpperCase().trim())).length;
    const progress = state.reports.filter(r => ['IN_PROGRESS', 'IN PROGRESS', 'DISPATCHED', 'UNIT DISPATCHED', 'FOR_RESOLUTION'].includes(String(r.status).toUpperCase().trim())).length;
    const resolved = state.reports.filter(r => ['RESOLVED', 'CLOSED'].includes(String(r.status).toUpperCase().trim())).length;

    document.getElementById('metricTotal').textContent = total;
    document.getElementById('metricReview').textContent = review;
    document.getElementById('metricProgress').textContent = progress;
    document.getElementById('metricResolved').textContent = resolved;

    const recents = state.reports.slice(0, 4);
    const tbody = document.getElementById('overviewRecentRows');
    tbody.innerHTML = recents.map(r => `
        <tr>
            <td><strong style="font-family: monospace;">${escapeHtml(r.reference_number || r.tracking_code || 'TRK-' + r.id)}</strong></td>
            <td><strong>${escapeHtml(r.incident_type)}</strong></td>
            <td>${escapeHtml(r.purok || r.location || '—')}</td>
            <td>${escapeHtml(formatDate(r.incident_datetime || r.created_at))}</td>
            <td>${statusPill(r.status)}</td>
            <td class="table-secondary">${escapeHtml(r.ai_recommendation || r.remarks || 'Pending desk review')}</td>
            <td><div class="row-actions"><button class="small-button" data-view-report="${r.id}">View</button></div></td>
        </tr>
    `).join('');
    document.getElementById('overviewRecentEmpty').hidden = recents.length > 0;
}

function renderReports() {
    const search = document.getElementById('reportSearch').value.trim().toLowerCase();
    let reports = state.reports.filter(r => {
        if (!search) return true;
        return [r.reference_number, r.incident_type, r.purok, r.narrative_description, r.status]
            .some(v => String(v || '').toLowerCase().includes(search));
    });

    if (state.filter !== 'all') {
        reports = reports.filter(r => {
            const st = String(r.status).toUpperCase().trim();
            // "Under Review" filter matches PENDING or UNDER_REVIEW
            if (state.filter === 'UNDER_REVIEW') {
                return st === 'PENDING' || st === 'UNDER_REVIEW' || st === 'UNDER REVIEW';
            }
            // "In Progress" filter matches active investigation, dispatched units, and Lupon
            if (state.filter === 'IN_PROGRESS') {
                return st === 'IN_PROGRESS' || st === 'IN PROGRESS' || st === 'DISPATCHED' || st === 'UNIT DISPATCHED' || st === 'FOR_RESOLUTION';
            }
            // "Resolved" filter matches RESOLVED and CLOSED
            if (state.filter === 'RESOLVED') {
                return st === 'RESOLVED' || st === 'CLOSED';
            }
            return st === state.filter;
        });
    }

    const tbody = document.getElementById('allReportRows');
    tbody.innerHTML = reports.map(r => `
        <tr>
            <td><strong style="font-family: monospace;">${escapeHtml(r.reference_number || r.tracking_code || 'TRK-' + r.id)}</strong></td>
            <td><strong>${escapeHtml(r.incident_type)}</strong></td>
            <td>${escapeHtml(r.purok || r.location || '—')}</td>
            <td>${escapeHtml(formatDate(r.incident_datetime || r.created_at))}</td>
            <td>${statusPill(r.status)}</td>
            <td class="table-secondary">${escapeHtml(r.ai_recommendation || r.remarks || 'Pending desk review')}</td>
            <td><div class="row-actions"><button class="small-button" data-view-report="${r.id}">Details</button></div></td>
        </tr>
    `).join('');
    document.getElementById('allReportsEmpty').hidden = reports.length > 0;
}

// Double-Submission Lock on Submit
document.getElementById('incidentForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const submitBtn = e.currentTarget.querySelector('button[type="submit"]');
    if (submitBtn.disabled) return;

    submitBtn.disabled = true;
    submitBtn.textContent = 'Filing Incident...';

    const payload = Object.fromEntries(new FormData(e.currentTarget).entries());
    try {
        const res = await requestResident('incident_create', payload);
        document.getElementById('reportDialog').close();
        showNotice(res.message || 'Incident case encoded and filed successfully.');
        await refreshData();
    } catch (err) {
        showNotice(err.message, true);
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Encode & File Case';
    }
});

// Parcel-Style Milestone Detail Viewer
async function openReportDetails(reportId) {
    const report = state.reports.find(r => String(r.id) === String(reportId));
    if (!report) return;

    const ref = report.reference_number || 'TRK-' + report.id;
    document.getElementById('detailDialogTitle').textContent = `Tracking #${ref}`;

    const stepReported = document.getElementById('step-reported');
    const stepReview = document.getElementById('step-review');
    const stepAction = document.getElementById('step-action');
    const stepResolved = document.getElementById('step-resolved');
    const timelineEl = document.getElementById('trackingTimeline');

    // Fetch live milestones from database
    let milestones = [];
    try {
        const res = await requestResident('get_milestones', { reference_number: ref });
        milestones = res.milestones || [];
    } catch (err) {
        console.error('Milestone fetch error:', err);
    }

    // Determine current status: latest milestone snapshot takes precedence
    const latestSnapshot = milestones.length > 0 ? milestones[0].status_snapshot : report.status;
    const currentStatus = String(latestSnapshot || report.status || '').toUpperCase().trim();

    // Reset stepper
    [stepReported, stepReview, stepAction, stepResolved].forEach(s => {
        if (s) s.className = 'stepper-step';
    });

    if (stepReported) stepReported.classList.add('is-done');

    if (currentStatus === 'PENDING' || currentStatus === 'UNDER_REVIEW' || currentStatus === 'UNDER REVIEW') {
        if (stepReview) stepReview.classList.add('is-active');
    } else if (currentStatus === 'IN_PROGRESS' || currentStatus === 'IN PROGRESS') {
        if (stepReview) stepReview.classList.add('is-done');
        if (stepAction) stepAction.classList.add('is-active');
    } else if (currentStatus === 'DISPATCHED' || currentStatus === 'UNIT DISPATCHED') {
        // Dispatched marks both review and field action as active/done
        if (stepReview) stepReview.classList.add('is-done');
        if (stepAction) stepAction.classList.add('is-done');
    } else if (currentStatus === 'FOR_RESOLUTION' || currentStatus === 'LUPON') {
        if (stepReview) stepReview.classList.add('is-done');
        if (stepAction) stepAction.classList.add('is-done');
        if (stepResolved) stepResolved.classList.add('is-active');
    } else if (currentStatus === 'RESOLVED' || currentStatus === 'CLOSED') {
        if (stepReview) stepReview.classList.add('is-done');
        if (stepAction) stepAction.classList.add('is-done');
        if (stepResolved) stepResolved.classList.add('is-done');
    } else {
        if (stepReview) stepReview.classList.add('is-active');
    }

    // Details view
    const fields = [
        ['Incident Type', report.incident_type],
        ['Purok / Location', report.purok || '—'],
        ['Date Filed', formatDate(report.incident_datetime || report.created_at)],
        ['Priority Level', report.priority_level || 'Moderate'],
        ['Current Status', statusPill(currentStatus)],
        ['Recommendation', report.ai_recommendation || 'Under administrative assessment.'],
        ['Narrative Notes', report.narrative_description || 'No description provided.']
    ];

    document.getElementById('reportDetailContent').innerHTML = fields.map(([label, value]) => `
        <div class="detail-field ${label === 'Narrative Notes' || label === 'Recommendation' ? 'detail-field--wide' : ''}">
            <span>${escapeHtml(label)}</span>
            <strong>${value}</strong>
        </div>
    `).join('');

    // Timeline entries
    if (milestones.length === 0) {
        timelineEl.innerHTML = `
            <div class="timeline-event">
                <span class="timeline-node"></span>
                <div class="timeline-heading">
                    <span class="pill pill-pending">Intake Logged</span>
                    <span class="timeline-time">${formatDate(report.created_at)}</span>
                </div>
                <p class="timeline-note">Initial case intake completed and queued for verification.</p>
            </div>
        `;
    } else {
        timelineEl.innerHTML = milestones.map(m => `
            <div class="timeline-event">
                <span class="timeline-node"></span>
                <div class="timeline-heading">
                    ${statusPill(m.status_snapshot)}
                    <span class="timeline-time">${formatDate(m.created_at)}</span>
                </div>
                <p class="timeline-note">${escapeHtml(m.action_note)}</p>
            </div>
        `).join('');
    }

    document.getElementById('reportDetailDialog').showModal();
}

async function refreshData() {
    try {
        const data = await requestResident();
        state.reports = data.reports || [];
        renderOverview();
        renderReports();

        if (data.resident) {
            const residentName = data.resident.full_name || 'Resident';
            const nameEl = document.getElementById('residentName');
            if (nameEl) nameEl.textContent = residentName;

            const nameInput = document.getElementById('profileName');
            if (nameInput) nameInput.value = residentName;

            const contactInput = document.getElementById('profileContact');
            if (contactInput) contactInput.value = data.resident.contact_number || '';

            const initials = residentName.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join('') || 'JD';
            const cardAvatar = document.getElementById('settingsAvatarInitials');
            if (cardAvatar) cardAvatar.textContent = initials;

            const pic = data.resident.profile_picture;
            const sidebarPic = document.getElementById('residentSidebarPic');
            if (sidebarPic) {
                sidebarPic.hidden = !pic;
                if (pic) {
                    sidebarPic.src = pic + '?v=' + Date.now();
                    sidebarPic.style.display = 'block';
                } else {
                    sidebarPic.style.display = 'none';
                }
            }

            const settingsPreview = document.getElementById('residentAvatarPreview');
            if (settingsPreview) {
                settingsPreview.hidden = !pic;
                if (pic) {
                    settingsPreview.src = pic + '?v=' + Date.now();
                    settingsPreview.style.display = 'block';
                    if (cardAvatar) cardAvatar.style.display = 'none';
                } else {
                    settingsPreview.style.display = 'none';
                    if (cardAvatar) cardAvatar.style.display = 'block';
                }
            }
        }
    } catch (err) {
        showNotice(err.message, true);
    }
}

// Navigation & Filters
navLinks.forEach((link) => link.addEventListener('click', () => setView(link.dataset.view)));

document.querySelectorAll('[data-open-view]').forEach(btn => {
    btn.addEventListener('click', () => setView(btn.dataset.openView));
});

document.querySelectorAll('#openReportModalBtn, #openReportModalBtn2').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('incidentForm').reset();
        document.getElementById('reportDialog').showModal();
    });
});

document.querySelectorAll('[data-close-dialog]').forEach(btn => {
    btn.addEventListener('click', () => btn.closest('dialog').close());
});

document.querySelectorAll('dialog').forEach(dlg => {
    dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
});

document.querySelectorAll('[data-report-filter]').forEach(tab => {
    tab.addEventListener('click', () => {
        state.filter = tab.dataset.reportFilter;
        document.querySelectorAll('[data-report-filter]').forEach(t => t.classList.toggle('is-selected', t === tab));
        renderReports();
    });
});

document.getElementById('reportSearch').addEventListener('input', renderReports);

document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-view-report]');
    if (btn) openReportDetails(btn.dataset.viewReport);
});

document.getElementById('passwordForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const payload = Object.fromEntries(new FormData(e.currentTarget).entries());
    try {
        const res = await requestResident('password_update', payload);
        e.currentTarget.reset();
        showNotice(res.message || 'Password changed successfully.');
    } catch (err) {
        showNotice(err.message, true);
    }
});

document.getElementById('logoutBtn')?.addEventListener('click', async () => {
    try { await fetch('api/logout.php', { method: 'POST', credentials: 'same-origin' }); } catch {}
    sessionStorage.clear();
    localStorage.removeItem('currentUser');
    localStorage.removeItem('authToken');
    window.location.replace('index.html');
});

// Theme handling
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

// Profile upload
const photoInput = document.getElementById('residentPhotoInput');
const avatarPreview = document.getElementById('residentAvatarPreview');
const avatarInitials = document.getElementById('settingsAvatarInitials');

if (photoInput) {
    photoInput.addEventListener('change', function () {
        const file = this.files && this.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = function (e) {
            if (avatarPreview) {
                avatarPreview.src = e.target.result;
                avatarPreview.style.display = 'block';
            }
            if (avatarInitials) {
                avatarInitials.style.display = 'none';
            }
        };
        reader.readAsDataURL(file);
    });
}

document.getElementById('profileForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.currentTarget);
    formData.set('action', 'profile_update');

    const photoInput = document.getElementById('residentPhotoInput');
    if (photoInput && photoInput.files && photoInput.files[0]) {
        formData.set('profile_picture', photoInput.files[0]);
    }

    try {
        const res = await requestResident('profile_update', {}, { formData });
        showNotice(res.message || 'Profile updated successfully.');
        await refreshData();
    } catch (err) {
        showNotice(err.message, true);
    }
});

// Off-canvas mobile drawer
const mobileMenuBtn = document.getElementById('mobileMenuBtn');
const sidebarCloseBtn = document.getElementById('sidebarCloseBtn');
const sidebarBackdrop = document.getElementById('sidebarBackdrop');
const adminSidebar = document.getElementById('adminSidebar');

function openDrawer() {
    if (adminSidebar) adminSidebar.classList.add('is-open');
    if (sidebarBackdrop) sidebarBackdrop.classList.add('is-open');
    document.body.style.overflow = 'hidden';
}

function closeDrawer() {
    if (adminSidebar) adminSidebar.classList.remove('is-open');
    if (sidebarBackdrop) sidebarBackdrop.classList.remove('is-open');
    document.body.style.overflow = '';
}

mobileMenuBtn?.addEventListener('click', openDrawer);
sidebarCloseBtn?.addEventListener('click', closeDrawer);
sidebarBackdrop?.addEventListener('click', closeDrawer);

document.querySelectorAll('.admin-nav-link').forEach(link => {
    link.addEventListener('click', () => {
        if (window.innerWidth <= 768) {
            closeDrawer();
        }
    });
});

const initialView = location.hash.slice(1);
if (viewTitles[initialView]) setView(initialView);
refreshData();