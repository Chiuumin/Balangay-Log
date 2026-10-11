const captainApi = 'api/captain_console.php';
const pageNotice = document.getElementById('pageNotice');
const navLinks = [...document.querySelectorAll('.admin-nav-link')];
const viewTitles = {
    overview: 'Executive Overview',
    registry: 'Master Blotter Registry',
    endorsements: 'Awaiting Captain Endorsement',
    lupon: 'Lupon / Mediation Registry',
    heatmap: 'Purok Incident Heatmaps',
    analytics: 'Analytics & Official Reports'
};
const state = { cases: [], endorsements: [], lupon: [], trend: [], byPurok: [], byPurokAll: [], byCategory: [], puroks: [], selected: null };

const escapeHtml = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const parseDate = (v) => new Date(String(v).replace(' ', 'T'));
const formatDate = (v) => (v && !isNaN(parseDate(v))) ? parseDate(v).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '—';
const statusLabel = (v) => String(v || 'UNKNOWN').replaceAll('_', ' ').toLowerCase().replace(/\b\w/g, (l) => l.toUpperCase());
const statusPill = (v) => `<span class="pill pill-${escapeHtml(String(v || '').toLowerCase())}">${escapeHtml(statusLabel(v))}</span>`;
const endorseTypeLabel = (v) => ({ LUPON_CERTIFICATION: 'Lupon certification', PNP_TRANSMITTAL: 'PNP transmittal' }[v] || statusLabel(v));

function showNotice(message, isError = false) {
    pageNotice.textContent = message;
    pageNotice.classList.toggle('is-error', isError);
    pageNotice.setAttribute('role', isError ? 'alert' : 'status');
    pageNotice.hidden = false;
    clearTimeout(showNotice.timeout);
    showNotice.timeout = setTimeout(() => { pageNotice.hidden = true; }, 4500);
}

const csrf = () => document.querySelector('meta[name="csrf-token"]').content;

async function requestCaptain(action, payload = {}) {
    const response = await fetch(captainApi, {
        method: action ? 'POST' : 'GET',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': csrf(), 'Content-Type': 'application/json' },
        body: action ? JSON.stringify({ action, ...payload }) : undefined,
    });
    if (response.status === 401 || response.status === 403) { window.location.replace('index.html'); throw new Error('Your session has ended. Please sign in again.'); }
    let result;
    try { result = await response.json(); } catch { throw new Error('The server sent an unexpected response.'); }
    if (!response.ok || !result.success) throw new Error(result.message || 'The request could not be completed.');
    return result;
}

function setView(view) {
    if (!viewTitles[view]) return;
    navLinks.forEach((link) => {
        const active = link.dataset.view === view;
        link.classList.toggle('is-active', active);
        if (active) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
    });
    document.querySelectorAll('.admin-view').forEach((s) => { s.hidden = s.id !== `view-${view}`; });
    document.getElementById('viewTitle').textContent = viewTitles[view];
    history.replaceState(null, '', `#${view}`);
}

/* ---------- charts (inline SVG, no libraries) ---------- */
function trendSvg(points) {
    if (!points.length) return '<p class="empty-message">No trend data yet.</p>';
    const W = 480, H = 170, pl = 28, pr = 12, pt = 12, pb = 26;
    const max = Math.max(1, ...points.map((p) => Number(p.count)));
    const x = (i) => pl + (points.length === 1 ? (W - pl - pr) / 2 : i * (W - pl - pr) / (points.length - 1));
    const y = (v) => pt + (H - pt - pb) * (1 - v / max);
    const line = points.map((p, i) => `${x(i)},${y(Number(p.count))}`).join(' ');
    const area = `${x(0)},${H - pb} ${line} ${x(points.length - 1)},${H - pb}`;
    const grid = [0, .5, 1].map((t) => `<line class="chart-grid" x1="${pl}" x2="${W - pr}" y1="${y(max * t)}" y2="${y(max * t)}"/><text x="${pl - 5}" y="${y(max * t) + 3}" text-anchor="end">${Math.round(max * t)}</text>`).join('');
    const labels = points.map((p, i) => `<text x="${x(i)}" y="${H - 8}" text-anchor="middle">${escapeHtml(p.label)}</text>`).join('');
    const dots = points.map((p, i) => `<circle class="chart-dot" cx="${x(i)}" cy="${y(Number(p.count))}" r="3.5"><title>${escapeHtml(p.label)}: ${Number(p.count)}</title></circle>`).join('');
    return `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Incident trend by month">${grid}<polygon class="chart-area" points="${area}"/><polyline class="chart-line" points="${line}"/>${dots}${labels}</svg>`;
}

function barsHtml(items, labelKey) {
    const rows = [...items].sort((a, b) => Number(b.count) - Number(a.count));
    const max = Math.max(1, ...rows.map((r) => Number(r.count)));
    return rows.map((r) => `<div class="bar-row"><span>${escapeHtml(r[labelKey])}</span><div class="bar-track"><div class="bar-fill" style="width:${(Number(r.count) / max) * 100}%"></div></div><strong>${Number(r.count)}</strong></div>`).join('') || '<p class="empty-message">No incidents recorded.</p>';
}

function ringSvg(rate) {
    const pct = Math.max(0, Math.min(100, Number(rate) || 0)), r = 54, c = 2 * Math.PI * r;
    return `<svg viewBox="0 0 140 140" role="img" aria-label="Resolution rate ${pct}%"><circle class="ring-track" cx="70" cy="70" r="${r}"/><circle class="ring-fill" cx="70" cy="70" r="${r}" stroke-dasharray="${(c * pct) / 100} ${c}"/><text x="70" y="78" text-anchor="middle">${pct}%</text></svg>`;
}

function renderHeatmap() {
    const period = document.getElementById('heatPeriod').value;
    const items = period === 'all' ? state.byPurokAll : state.byPurok;
    const max = Math.max(1, ...items.map((i) => Number(i.count)));
    document.getElementById('heatGrid').innerHTML = items.map((i) => {
        const level = Number(i.count) / max;
        return `<div class="heat-tile ${level > .6 ? 'is-hot' : ''}" style="--heat:${(0.08 + level * 0.82).toFixed(2)}"><span>${escapeHtml(i.purok)}</span><strong>${Number(i.count)}</strong><span>${Number(i.count) === 1 ? 'incident' : 'incidents'}</span></div>`;
    }).join('');
    document.getElementById('heatEmpty').hidden = items.some((i) => Number(i.count) > 0);
}

/* ---------- tables ---------- */
const endorseRow = (e) => `<tr>
    <td><strong>${escapeHtml(e.case_no)}</strong><span class="table-secondary">${escapeHtml(e.title || '')}</span></td>
    <td>${escapeHtml(endorseTypeLabel(e.type))}</td><td>${escapeHtml(e.purok || '—')}</td>
    <td>${escapeHtml(e.referred_by || '—')}</td><td>${escapeHtml(formatDate(e.referred_at))}</td>
    <td><div class="row-actions"><button class="small-button" data-view-case="${Number(e.case_id)}">View</button><button class="small-button" data-endorse="${Number(e.id)}">Endorse / Sign</button></div></td></tr>`;

function renderEndorsements() {
    document.getElementById('overviewEndorseRows').innerHTML = state.endorsements.slice(0, 5).map(endorseRow).join('');
    document.getElementById('overviewEndorseEmpty').hidden = state.endorsements.length > 0;
    document.getElementById('endorseRows').innerHTML = state.endorsements.map(endorseRow).join('');
    document.getElementById('endorseEmpty').hidden = state.endorsements.length > 0;
    const badge = document.getElementById('navEndorseCount');
    badge.textContent = state.endorsements.length;
    badge.hidden = state.endorsements.length === 0;
}

function renderCases() {
    const search = document.getElementById('caseSearch').value.trim().toLowerCase();
    const status = document.getElementById('caseStatusFilter').value;
    const rows = state.cases.filter((c) => (!status || c.status === status)
        && (!search || [c.case_no, c.title, c.purok, c.severity, c.category].some((v) => String(v || '').toLowerCase().includes(search))));
    document.getElementById('caseRows').innerHTML = rows.map((c) => `<tr>
        <td><strong>${escapeHtml(c.case_no)}</strong></td>
        <td>${escapeHtml(c.title)}<span class="table-secondary">${escapeHtml(c.complainant || '')}</span></td>
        <td>${escapeHtml(c.purok || '—')}</td><td>${statusPill(c.severity)}</td><td>${statusPill(c.status)}</td>
        <td>${escapeHtml(formatDate(c.incident_datetime))}</td>
        <td><div class="row-actions"><button class="small-button" data-view-case="${Number(c.id)}">View</button></div></td></tr>`).join('');
    document.getElementById('caseEmpty').hidden = rows.length > 0;
}

function renderStatusFilter() {
    const select = document.getElementById('caseStatusFilter'), current = select.value;
    const statuses = [...new Set(state.cases.map((c) => c.status))].sort();
    select.innerHTML = '<option value="">All statuses</option>' + statuses.map((s) => `<option value="${escapeHtml(s)}">${escapeHtml(statusLabel(s))}</option>`).join('');
    select.value = current;
}

function renderLupon() {
    document.getElementById('luponRows').innerHTML = state.lupon.map((l) => `<tr>
        <td><strong>${escapeHtml(l.case_no)}</strong><span class="table-secondary">${escapeHtml(l.title || '')}</span></td>
        <td>${escapeHtml(l.parties || '—')}</td><td>${escapeHtml(l.purok || '—')}</td>
        <td>${escapeHtml(statusLabel(l.stage))}</td><td>${escapeHtml(formatDate(l.next_hearing))}</td><td>${statusPill(l.status)}</td></tr>`).join('');
    document.getElementById('luponEmpty').hidden = state.lupon.length > 0;
}

function renderAll(data) {
    const m = data.overview || {};
    document.getElementById('metricIncidents').textContent = m.monthly_incidents ?? 0;
    document.getElementById('metricMediation').textContent = m.under_mediation ?? 0;
    document.getElementById('metricEndorse').textContent = m.endorsements_needed ?? state.endorsements.length;
    document.getElementById('metricRate').textContent = `${Number(m.resolution_rate ?? 0)}%`;
    const critical = Number(m.critical_incidents ?? 0);
    document.getElementById('criticalCount').textContent = critical;
    document.getElementById('criticalBanner').hidden = critical === 0;
    document.getElementById('trendChart').innerHTML = trendSvg(state.trend);
    document.getElementById('trendChart2').innerHTML = trendSvg(state.trend);
    document.getElementById('purokBars').innerHTML = barsHtml(state.byPurok, 'purok');
    document.getElementById('categoryBars').innerHTML = barsHtml(state.byCategory, 'category');
    document.getElementById('rateRing').innerHTML = ringSvg(m.resolution_rate);
    document.getElementById('advisoryPurok').innerHTML = '<option value="">Whole barangay</option>' + state.puroks.map((p) => `<option value="${Number(p.id)}">${escapeHtml(p.name)}</option>`).join('');
    renderEndorsements(); renderStatusFilter(); renderCases(); renderLupon(); renderHeatmap();

    if (data.captain) {
        const name = `Capt. ${data.captain.full_name || ''}`.trim();
        document.getElementById('captainName').textContent = name;
        document.getElementById('captainSideName').textContent = name;
        const initials = (data.captain.full_name || 'CP').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join('');
        document.getElementById('captainAvatar').firstChild.textContent = initials;
        const pic = document.getElementById('captainPicture');
        pic.hidden = !data.captain.profile_picture;
        if (data.captain.profile_picture) pic.src = data.captain.profile_picture;
    }
}

async function refreshData() {
    try {
        const data = await requestCaptain();
        state.cases = data.cases || []; state.endorsements = data.endorsements || []; state.lupon = data.lupon || [];
        state.trend = data.trend || []; state.byPurok = data.by_purok || []; state.byPurokAll = data.by_purok_all || data.by_purok || [];
        state.byCategory = data.by_category || []; state.puroks = data.puroks || [];
        renderAll(data);
    } catch (error) { showNotice(error.message, true); }
}

/* ---------- dialogs ---------- */
const detailField = (label, value, wide = false) => `<div class="detail-field ${wide ? 'detail-field--wide' : ''}"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value || '—')}</strong></div>`;

function openCase(caseId) {
    const c = state.cases.find((i) => Number(i.id) === Number(caseId));
    if (!c) { showNotice('That case is not in the loaded registry. Refresh and try again.', true); return; }
    document.getElementById('caseDialogTitle').textContent = c.case_no;
    document.getElementById('caseDetails').innerHTML = [
        detailField('Incident', c.title), detailField('Complainant', c.complainant), detailField('Purok', c.purok),
        detailField('Priority', statusLabel(c.severity)), detailField('Status', statusLabel(c.status)), detailField('Reported', formatDate(c.incident_datetime)),
        detailField('Narrative', c.narrative || c.description, true),
    ].join('');
    document.getElementById('caseDialog').showModal();
}

function openEndorse(id) {
    const e = state.endorsements.find((i) => Number(i.id) === Number(id));
    if (!e) return;
    state.selected = e;
    const form = document.getElementById('endorseForm');
    form.reset(); form.elements.id.value = e.id;
    document.getElementById('endorseTitle').textContent = `${endorseTypeLabel(e.type)} for ${e.case_no}`;
    document.getElementById('endorseDetails').innerHTML = [detailField('Case', e.title), detailField('Purok', e.purok), detailField('Referred by', e.referred_by), detailField('Date referred', formatDate(e.referred_at))].join('');
    document.getElementById('endorseDialog').showModal();
}

async function submitting(form, work) {
    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    try { await work(); } catch (error) { showNotice(error.message, true); } finally { button.disabled = false; }
}

/* ---------- events ---------- */
navLinks.forEach((link) => link.addEventListener('click', () => setView(link.dataset.view)));
document.querySelectorAll('[data-open-view]').forEach((b) => b.addEventListener('click', () => setView(b.dataset.openView)));
document.querySelectorAll('[data-refresh]').forEach((b) => b.addEventListener('click', refreshData));
document.querySelectorAll('[data-close-dialog]').forEach((b) => b.addEventListener('click', () => b.closest('dialog').close()));
document.querySelectorAll('[data-open-advisory]').forEach((b) => b.addEventListener('click', () => { document.getElementById('advisoryForm').reset(); document.getElementById('advisoryDialog').showModal(); }));
document.getElementById('caseSearch').addEventListener('input', renderCases);
document.getElementById('caseStatusFilter').addEventListener('change', renderCases);
document.getElementById('heatPeriod').addEventListener('change', renderHeatmap);
document.getElementById('criticalReview').addEventListener('click', () => {
    document.getElementById('caseSearch').value = 'critical'; renderCases(); setView('registry');
});

document.addEventListener('click', (event) => {
    const t = event.target.closest('button');
    if (!t) return;
    if (t.dataset.viewCase) openCase(t.dataset.viewCase);
    if (t.dataset.endorse) openEndorse(t.dataset.endorse);
});

document.querySelectorAll('[data-generate-report]').forEach((button) => button.addEventListener('click', async () => {
    button.disabled = true;
    try {
        const result = await requestCaptain('report_generate', { month: new Date().toISOString().slice(0, 7) });
        showNotice(result.message || 'Monthly DILG report is ready.');
        if (result.url) window.open(result.url, '_blank', 'noopener');
    } catch (error) { showNotice(error.message, true); } finally { button.disabled = false; }
}));

document.getElementById('advisoryForm').addEventListener('submit', (event) => {
    event.preventDefault();
    const form = event.currentTarget, payload = Object.fromEntries(new FormData(form).entries());
    submitting(form, async () => {
        const result = await requestCaptain('advisory_create', payload);
        document.getElementById('advisoryDialog').close(); showNotice(result.message || 'Advisory published.');
    });
});

document.getElementById('endorseForm').addEventListener('submit', (event) => {
    event.preventDefault();
    const form = event.currentTarget, payload = Object.fromEntries(new FormData(form).entries());
    submitting(form, async () => {
        const result = await requestCaptain('case_endorse', payload);
        document.getElementById('endorseDialog').close(); showNotice(result.message || 'Case endorsed.'); await refreshData();
    });
});

document.getElementById('logoutBtn').addEventListener('click', async () => {
    try { await fetch('api/logout.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf() } }); } catch { /* still leave the page */ }
    sessionStorage.clear();
    localStorage.removeItem('currentUser');
    localStorage.removeItem('authToken');
    window.location.replace('index.html');
});

const initialView = location.hash.slice(1);
if (viewTitles[initialView]) setView(initialView);
refreshData();