const captainApi = 'api/captain_console.php';
const pageNotice = document.getElementById('pageNotice');
const navLinks = [...document.querySelectorAll('.admin-nav-link')];
const viewTitles = {
    overview: 'Executive Overview',
    registry: 'Master Blotter Registry',
    endorsements: 'Awaiting Captain Endorsement',
    lupon: 'Lupon / Mediation Registry',
    heatmap: 'Purok Incident Heatmaps',
    analytics: 'Analytics & Official Reports',
    settings: 'Settings'
};
const state = {
    cases: [], endorsements: [], lupon: [], trend: [], byPurok: [], byPurokAll: [], byCategory: [], puroks: [], selected: null,
    analyticsRecords: [], analyticsOptions: {}, yearlyComparison: [], pendingEndorsements: 0,
    reportRows: [], reportPage: 0, reportSortIndex: -1, reportSortDirection: 1, reportPreviewData: null, reportSummaryFilter: ''
};

const escapeHtml = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const parseDate = (v) => new Date(String(v).replace(' ', 'T'));
const formatDate = (v) => (v && !isNaN(parseDate(v))) ? parseDate(v).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '—';
const statusLabel = (v) => String(v || 'UNKNOWN').replaceAll('_', ' ').toLowerCase().replace(/\b\w/g, (l) => l.toUpperCase());
const statusPill = (v) => `<span class="pill pill-${escapeHtml(String(v || '').toLowerCase())}">${escapeHtml(statusLabel(v))}</span>`;
const endorseTypeLabel = (v) => ({ LUPON_CERTIFICATION: 'Lupon certification', PNP_TRANSMITTAL: 'PNP transmittal' }[v] || statusLabel(v));

function showNotice(message, isError = false) {
    if (!pageNotice) return;
    pageNotice.textContent = message;
    pageNotice.classList.toggle('is-error', isError);
    pageNotice.setAttribute('role', isError ? 'alert' : 'status');
    pageNotice.hidden = false;
    clearTimeout(showNotice.timeout);
    showNotice.timeout = setTimeout(() => { pageNotice.hidden = true; }, 4500);
}

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

async function requestCaptain(action, payload = {}, options = {}) {
    const headers = { 'X-CSRF-Token': csrf() };
    if (!options.formData) headers['Content-Type'] = 'application/json';
    const response = await fetch(captainApi, {
        method: action ? 'POST' : 'GET',
        credentials: 'same-origin',
        headers,
        body: action ? (options.formData || JSON.stringify({ action, ...payload })) : undefined,
    });
    if (response.status === 401 || response.status === 403) { 
        window.location.replace('index.html'); 
        throw new Error('Your session has ended. Please sign in again.'); 
    }
    
    const rawText = await response.text();
    let result;
    try { 
        result = JSON.parse(rawText); 
    } catch { 
        const cleanMsg = rawText.replace(/<[^>]*>?/gm, ' ').replace(/\s+/g, ' ').trim();
        throw new Error(cleanMsg.slice(0, 160) || 'The server sent an unexpected response.'); 
    }
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
    const titleEl = document.getElementById('viewTitle');
    if (titleEl) titleEl.textContent = viewTitles[view];
    history.replaceState(null, '', `#${view}`);
}

/* ---------- charts (inline SVG, no libraries) ---------- */
function trendSvg(points) {
    if (!points || !points.length) return '<p class="empty-message">No trend data yet.</p>';
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

const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const workflowStatuses = ['PENDING', 'IN_PROGRESS', 'FOR_RESOLUTION', 'RESOLVED', 'CRITICAL'];

function getFilterValues(prefix) {
    return {
        year: document.getElementById(`${prefix}Year`)?.value || '',
        month: document.getElementById(`${prefix}Month`)?.value || '',
        from: document.getElementById(`${prefix}From`)?.value || '',
        to: document.getElementById(`${prefix}To`)?.value || '',
        category: document.getElementById(`${prefix}Category`)?.value || '',
        purok: document.getElementById(`${prefix}Purok`)?.value || '',
        status: document.getElementById(`${prefix}Status`)?.value || ''
    };
}

function fillSelect(id, values, label, currentValue = null) {
    const select = document.getElementById(id);
    if (!select) return;
    const selected = currentValue === null ? select.value : currentValue;
    select.innerHTML = `<option value="">${escapeHtml(label)}</option>` + values.map((value) => {
        const item = typeof value === 'object' ? value.value : value;
        const text = typeof value === 'object' ? value.label : value;
        return `<option value="${escapeHtml(item)}">${escapeHtml(text)}</option>`;
    }).join('');
    if ([...select.options].some((option) => option.value === selected)) select.value = selected;
}

function validReportingYears(years) {
    const currentYear = new Date().getFullYear();
    return [...new Set(years.map(Number).filter((year) =>
        Number.isInteger(year) && year >= 2000 && year <= currentYear
    ))].sort((a, b) => b - a);
}

function applyAnalyticsOptions(options = {}) {
    const firstLoad = Object.keys(state.analyticsOptions).length === 0;
    state.analyticsOptions = options;
    const years = validReportingYears(options.years || []);
    const categories = options.categories || [];
    const puroks = options.puroks || [];
    const statuses = (options.statuses || workflowStatuses).map((status) => ({ value: status, label: statusLabel(status) }));
    ['analyticsYear', 'reportYear'].forEach((id) => fillSelect(id, years, id === 'reportYear' ? 'Select year' : 'All years'));
    ['analyticsCategory', 'reportCategory'].forEach((id) => fillSelect(id, categories, 'All categories'));
    ['analyticsPurok', 'reportPurok'].forEach((id) => fillSelect(id, puroks, 'All puroks'));
    ['analyticsStatus', 'reportStatus'].forEach((id) => fillSelect(id, statuses, 'All statuses'));
    const defaultYear = years.includes(new Date().getFullYear()) ? String(new Date().getFullYear()) : String(years[0] || '');
    if (firstLoad && !document.getElementById('analyticsYear')?.value && defaultYear) document.getElementById('analyticsYear').value = defaultYear;
    if (!document.getElementById('reportYear')?.value && defaultYear) document.getElementById('reportYear').value = defaultYear;
}

async function fetchAnalytics(filters = getFilterValues('analytics')) {
    const loading = document.getElementById('analyticsLoading');
    if (loading) loading.hidden = false;
    try {
        if (Object.keys(state.analyticsOptions).length === 0 && !filters.year) {
            filters = { ...filters, year: String(new Date().getFullYear()) };
        }
        const data = await requestCaptain('analytics_data', { filters });
        state.analyticsRecords = data.records || [];
        state.pendingEndorsements = Number(data.pending_endorsements) || 0;
        state.yearlyComparison = data.yearly_comparison || [];
        applyAnalyticsOptions(data.options || {});
        const availableYears = validReportingYears(data.options?.years || []);
        if (filters.year && availableYears.length && !availableYears.includes(Number(filters.year))) {
            const latestYear = String(availableYears[0]);
            const yearSelect = document.getElementById('analyticsYear');
            if (yearSelect) yearSelect.value = latestYear;
            await fetchAnalytics({ ...filters, year: latestYear });
            return;
        }
        const note = document.getElementById('coverageNote');
        if (note) note.textContent = data.coverage_note || '';
        renderAnalytics();
        renderReportTable();
    } catch (error) {
        showNotice(error.message, true);
        throw error;
    } finally {
        if (loading) loading.hidden = true;
    }
}

function groupCounts(records, key) {
    const counts = new Map();
    records.forEach((record) => {
        const name = String(record[key] || 'Unknown');
        counts.set(name, (counts.get(name) || 0) + 1);
    });
    return [...counts.entries()].map(([label, count]) => ({ label, count }));
}

function makeMonthTrend(records, yearValue) {
    const year = Number(yearValue) || new Date().getFullYear();
    const counts = Array(12).fill(0);
    records.forEach((record) => {
        const date = String(record.reported_at || '');
        const recordYear = Number(date.slice(0, 4));
        const month = Number(date.slice(5, 7));
        if (recordYear === year && month >= 1 && month <= 12) counts[month - 1] += 1;
    });
    return counts.map((count, index) => ({ label: monthNames[index].slice(0, 3), count }));
}

function renderAnalytics() {
    const records = state.analyticsRecords;
    const total = records.length;
    const resolved = records.filter((record) => record.status === 'RESOLVED').length;
    const ongoing = records.filter((record) => ['IN_PROGRESS', 'FOR_RESOLUTION'].includes(record.status)).length;
    const priority = records.filter((record) => record.status !== 'RESOLVED' && ['CRITICAL', 'HIGH'].includes(String(record.priority || '').toUpperCase())).length;
    const counts = {
        analyticsTotal: total,
        analyticsPending: state.pendingEndorsements,
        analyticsOngoing: ongoing,
        analyticsResolved: resolved,
        analyticsCritical: priority
    };
    Object.entries(counts).forEach(([id, value]) => {
        const element = document.getElementById(id);
        if (element) element.textContent = String(value);
    });

    const trend = makeMonthTrend(records, document.getElementById('analyticsYear')?.value);
    const trendContainer = document.getElementById('trendChart2');
    if (trendContainer) trendContainer.innerHTML = total ? trendSvg(trend) : '';
    const categories = groupCounts(records, 'category').sort((a, b) => b.count - a.count).map((item) => ({ category: item.label, count: item.count }));
    const puroks = groupCounts(records, 'purok').sort((a, b) => b.count - a.count).map((item) => ({ purok: item.label, count: item.count }));
    const statusValues = [...new Set([...(state.analyticsOptions.statuses || workflowStatuses), ...records.map((record) => record.status).filter(Boolean)])];
    const statuses = statusValues.map((status) => ({ status, count: records.filter((record) => record.status === status).length }));
    const categoryBars = document.getElementById('categoryBars');
    if (categoryBars) categoryBars.innerHTML = categories.length ? barsHtml(categories, 'category') : '';
    const purokBars = document.getElementById('analyticsPurokBars');
    if (purokBars) purokBars.innerHTML = puroks.length ? barsHtml(puroks, 'purok') : '';
    const statusBars = document.getElementById('statusBars');
    if (statusBars) statusBars.innerHTML = total ? barsHtml(statuses, 'status') : '';
    const comparison = (state.yearlyComparison || []).map((item) => ({ year: String(item.year), count: Number(item.total) }));
    const comparisonBars = document.getElementById('yearComparison');
    if (comparisonBars) comparisonBars.innerHTML = comparison.some((item) => item.count > 0) ? barsHtml(comparison, 'year') : '';

    [
        ['trendEmpty', total],
        ['categoryEmpty', total],
        ['statusEmpty', total],
        ['purokEmpty', total],
        ['yearComparisonEmpty', comparison.reduce((sum, item) => sum + item.count, 0)]
    ].forEach(([id, count]) => {
        const empty = document.getElementById(id);
        if (empty) empty.hidden = Number(count) > 0;
    });
}

function reportTableDefinition(type) {
    const records = state.analyticsRecords;
    const makeRow = (cells, values = cells) => ({ cells, values });
    if (type === 'monthly') {
        const monthCounts = new Map();
        const years = new Set();
        records.forEach((record) => {
            const date = String(record.reported_at || '');
            const key = date.slice(0, 7);
            if (!key) return;
            years.add(date.slice(0, 4));
            if (!monthCounts.has(key)) monthCounts.set(key, []);
            monthCounts.get(key).push(record);
        });
        const periods = [...years].sort().flatMap((year) => monthNames.map((_, index) => `${year}-${String(index + 1).padStart(2, '0')}`)).sort((a, b) => b.localeCompare(a));
        const rows = periods.map((key) => {
            const items = monthCounts.get(key) || [];
            const date = new Date(`${key}-01T00:00:00`);
            const pending = items.filter((item) => item.status === 'PENDING').length;
            const ongoing = items.filter((item) => ['IN_PROGRESS', 'FOR_RESOLUTION'].includes(item.status)).length;
            const resolved = items.filter((item) => item.status === 'RESOLVED').length;
            const endorsed = items.filter((item) => item.endorsed_at || item.endorsement_type).length;
            const month = `${monthNames[date.getMonth()]} ${date.getFullYear()}`;
            return makeRow([month, items.length, pending, ongoing, resolved, endorsed], [key, items.length, pending, ongoing, resolved, endorsed]);
        });
        return { title: 'Monthly Incident Summary', headers: ['Month', 'Total incidents', 'Pending', 'Ongoing', 'Resolved', 'Endorsed'], rows };
    }
    if (type === 'yearly') {
        const byYear = new Map();
        records.forEach((record) => {
            const year = String(record.reported_at || '').slice(0, 4) || 'Unknown';
            if (!byYear.has(year)) byYear.set(year, []);
            byYear.get(year).push(record);
        });
        const rows = [...byYear.entries()].sort(([a], [b]) => b.localeCompare(a)).map(([year, items]) => {
            const resolved = items.filter((item) => item.status === 'RESOLVED').length;
            const categories = groupCounts(items, 'category').sort((a, b) => b.count - a.count).map((item) => `${item.label} (${item.count})`).join(', ') || '—';
            const unresolved = items.length - resolved;
            const rate = items.length ? `${Math.round(resolved / items.length * 100)}%` : '0%';
            return makeRow([year, items.length, categories, resolved, unresolved, rate], [year, items.length, categories, resolved, unresolved, parseInt(rate, 10)]);
        });
        return { title: 'Yearly Incident Summary', headers: ['Year', 'Total incidents', 'Incidents by category', 'Resolved', 'Unresolved', 'Resolution rate'], rows };
    }
    if (type === 'purok') {
        const rows = [...grouped('purok').entries()].map(([name, items]) => {
            const common = groupCounts(items, 'category').sort((a, b) => b.count - a.count)[0]?.label || '—';
            const pending = items.filter((item) => item.status === 'PENDING').length;
            const resolved = items.filter((item) => item.status === 'RESOLVED').length;
            return makeRow([name, items.length, common, pending, resolved], [name, items.length, common, pending, resolved]);
        }).sort((a, b) => b.values[1] - a.values[1]);
        return { title: 'Purok Incident Summary', headers: ['Purok', 'Total incidents', 'Most common category', 'Pending', 'Resolved'], rows };
    }
    if (type === 'resolution') {
        const rows = records.filter((record) => record.status === 'RESOLVED' || record.endorsed_at || record.endorsement_type).map((record) => {
            const eventDate = record.resolved_at || record.endorsed_at || '';
            const destination = record.receiving_office || record.endorsement_type || '—';
            return makeRow([record.case_no, formatDate(record.reported_at), statusLabel(record.status), formatDate(eventDate), destination],
                [record.case_no, record.reported_at || '', record.status, eventDate, destination]);
        });
        return { title: 'Case Resolution & Endorsement Report', headers: ['Incident reference', 'Date reported', 'Current status', 'Date resolved / endorsed', 'Receiving office / endorsement'], rows };
    }
    const rows = records.map((record) => makeRow([
        record.case_no, formatDate(record.reported_at), record.category, record.purok, statusLabel(record.priority), statusLabel(record.status), formatDate(record.resolved_at)
    ], [record.case_no, record.reported_at || '', record.category, record.purok, record.priority, record.status, record.resolved_at || '']));
    return { title: 'Incident Case Register', headers: ['Incident reference', 'Date reported', 'Category', 'Purok', 'Priority', 'Current status', 'Date resolved'], rows };
}

function renderReportTable() {
    const type = document.getElementById('tableReportType')?.value || 'monthly';
    const definition = reportTableDefinition(type);
    const title = document.getElementById('tableReportTitle');
    const filterTitles = { pending: 'Pending / Open Cases', ongoing: 'Ongoing Cases', resolved: 'Resolved Cases', priority: 'High-Priority Cases' };
    if (title) title.textContent = `${definition.title}${filterTitles[state.reportSummaryFilter] ? ` · ${filterTitles[state.reportSummaryFilter]}` : ''}`;
    const query = document.getElementById('reportTableSearch')?.value.trim().toLowerCase() || '';
    let rows = definition.rows.filter((row) => !query || row.cells.join(' ').toLowerCase().includes(query));
    if (state.reportSummaryFilter === 'pending') rows = rows.filter((row) => ['PENDING', 'OPEN', 'IN_PROGRESS', 'FOR_RESOLUTION'].includes(String(row.values[5] || '').toUpperCase()));
    if (state.reportSummaryFilter === 'ongoing') rows = rows.filter((row) => ['IN_PROGRESS', 'FOR_RESOLUTION'].includes(String(row.values[5] || '').toUpperCase()));
    if (state.reportSummaryFilter === 'resolved') rows = rows.filter((row) => String(row.values[5] || '').toUpperCase() === 'RESOLVED');
    if (state.reportSummaryFilter === 'priority') rows = rows.filter((row) => String(row.values[5] || '').toUpperCase() !== 'RESOLVED' && ['CRITICAL', 'HIGH'].includes(String(row.values[4] || '').toUpperCase()));
    if (state.reportSortIndex >= 0) {
        const index = state.reportSortIndex;
        rows = rows.sort((a, b) => String(a.values[index] ?? '').localeCompare(String(b.values[index] ?? ''), undefined, { numeric: true }) * state.reportSortDirection);
    }
    state.reportRows = rows;
    const pageSize = 20;
    const pages = Math.max(1, Math.ceil(rows.length / pageSize));
    state.reportPage = Math.min(state.reportPage, pages - 1);
    const start = state.reportPage * pageSize;
    const table = document.getElementById('reportTable');
    if (table) {
        table.querySelector('thead').innerHTML = `<tr>${definition.headers.map((header, index) => `<th scope="col" data-sort-index="${index}" aria-sort="${state.reportSortIndex === index ? (state.reportSortDirection === 1 ? 'ascending' : 'descending') : 'none'}"><button type="button" class="sort-button">${escapeHtml(header)} <span aria-hidden="true">↕</span></button></th>`).join('')}</tr>`;
        table.querySelector('tbody').innerHTML = rows.slice(start, start + pageSize).map((row) => `<tr>${row.cells.map((cell) => `<td>${escapeHtml(cell)}</td>`).join('')}</tr>`).join('');
    }
    const empty = document.getElementById('reportTableEmpty');
    if (empty) empty.hidden = rows.length > 0;
    const resultCount = document.getElementById('reportResultCount');
    if (resultCount) resultCount.textContent = `${rows.length} ${rows.length === 1 ? 'row' : 'rows'}`;
    const pageLabel = document.getElementById('reportPageLabel');
    if (pageLabel) pageLabel.textContent = `Page ${state.reportPage + 1} of ${pages}`;
    const previous = document.getElementById('reportPrev');
    const next = document.getElementById('reportNext');
    if (previous) previous.disabled = state.reportPage === 0;
    if (next) next.disabled = state.reportPage >= pages - 1;
}

const reportTitles = {
    monthly: 'Monthly Incident Report',
    annual: 'Annual Incident Report',
    category: 'Incident Summary by Category',
    purok: 'Incident Summary by Purok',
    resolution: 'Case Resolution Report',
    endorsement: 'Case Endorsement Report',
    custom: 'Custom Date Range Report',
    dilg: 'Monthly Incident Report'
};

function selectedReportPeriod(filters) {
    if (filters.from || filters.to) return `${filters.from || 'Beginning'} to ${filters.to || 'Present'}`;
    if (filters.year && filters.month) return `${monthNames[Number(filters.month) - 1]} ${filters.year}`;
    if (filters.year) return filters.year;
    return 'All available records';
}

function renderReportPreview(data, type, filters) {
    const preview = document.getElementById('reportPreview');
    const printButton = document.getElementById('printReport');
    if (!preview || !printButton) return;
    const sourceRecords = data.records || [];
    const records = type === 'resolution'
        ? sourceRecords.filter((record) => record.status === 'RESOLVED')
        : type === 'endorsement'
            ? sourceRecords.filter((record) => Boolean(record.endorsed_at || record.endorsement_type))
            : sourceRecords;
    state.reportPreviewData = { records, type, filters };
    if (!records.length) {
        preview.innerHTML = `<p class="empty-message">No records found for the selected reporting period.</p>`;
        preview.hidden = false;
        printButton.disabled = true;
        return;
    }
    const savedRecords = state.analyticsRecords;
    state.analyticsRecords = records;
    const tableType = ['monthly', 'dilg'].includes(type) ? 'monthly'
        : type === 'annual' ? 'yearly'
        : type === 'category' ? 'purok'
            : type === 'purok' ? 'purok'
                : ['resolution', 'endorsement'].includes(type) ? 'resolution'
                    : 'register';
    const definition = reportTableDefinition(tableType);
    state.analyticsRecords = savedRecords;
    const resolved = records.filter((record) => record.status === 'RESOLVED').length;
    const unresolved = records.length - resolved;
    const critical = records.filter((record) => record.status !== 'RESOLVED' && ['CRITICAL', 'HIGH'].includes(String(record.priority || '').toUpperCase())).length;
    let tableHeaders = definition.headers;
    let tableRows = definition.rows;
    if (type === 'category') {
        const grouped = groupCounts(records, 'category').sort((a, b) => b.count - a.count);
        tableHeaders = ['Incident category', 'Total incidents'];
        tableRows = grouped.map((item) => ({ cells: [item.label, item.count] }));
    }
    if (type === 'purok') {
        const grouped = groupCounts(records, 'purok').sort((a, b) => b.count - a.count);
        tableHeaders = ['Purok', 'Total incidents'];
        tableRows = grouped.map((item) => ({ cells: [item.label, item.count] }));
    }
    const tableHtml = `<div class="table-scroll"><table class="print-report-table"><thead><tr>${tableHeaders.map((header) => `<th>${escapeHtml(header)}</th>`).join('')}</tr></thead><tbody>${tableRows.map((row) => `<tr>${row.cells.map((cell) => `<td>${escapeHtml(cell)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
    const summary = `<div class="report-summary"><div><span>Total records included</span><strong>${records.length}</strong></div><div><span>Resolved</span><strong>${resolved}</strong></div><div><span>Unresolved</span><strong>${unresolved}</strong></div><div><span>Critical / high priority</span><strong>${critical}</strong></div></div>`;
    preview.innerHTML = `<header class="report-preview-header"><p>System-generated report</p><h2>${escapeHtml(reportTitles[type] || 'Incident Report')}</h2><div><span>Reporting period</span><strong>${escapeHtml(selectedReportPeriod(filters))}</strong></div><div><span>Date generated</span><strong>${escapeHtml(new Date().toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' }))}</strong></div></header><section><h3>Summary</h3>${summary}</section><section><h3>${escapeHtml(definition.title)}</h3>${tableHtml}</section><footer class="report-preview-footer">Report data is based on available system records; historical completeness is not tracked.</footer>`;
    preview.hidden = false;
    printButton.disabled = false;
}

function setReportTab(name) {
    document.querySelectorAll('[data-report-tab]').forEach((tab) => {
        const active = tab.dataset.reportTab === name;
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
        tab.tabIndex = active ? 0 : -1;
    });
    document.querySelectorAll('.analytics-tab-panel').forEach((panel) => {
        panel.hidden = panel.id !== `${name}Panel`;
    });
}

function renderHeatmap() {
    const period = document.getElementById('heatPeriod')?.value || 'month';
    const items = period === 'all' ? state.byPurokAll : state.byPurok;
    const max = Math.max(1, ...items.map((i) => Number(i.count)));
    const heatGrid = document.getElementById('heatGrid');
    if (heatGrid) {
        heatGrid.innerHTML = items.map((i) => {
            const level = Number(i.count) / max;
            return `<div class="heat-tile ${level > .6 ? 'is-hot' : ''}" style="--heat:${(0.08 + level * 0.82).toFixed(2)}"><span>${escapeHtml(i.purok)}</span><strong>${Number(i.count)}</strong><span>${Number(i.count) === 1 ? 'incident' : 'incidents'}</span></div>`;
        }).join('');
    }
    const heatEmpty = document.getElementById('heatEmpty');
    if (heatEmpty) heatEmpty.hidden = items.some((i) => Number(i.count) > 0);
}

/* ---------- tables ---------- */
const endorseRow = (e) => `<tr>
    <td><strong>${escapeHtml(e.case_no)}</strong><span class="table-secondary">${escapeHtml(e.title || '')}</span></td>
    <td>${escapeHtml(endorseTypeLabel(e.type))}</td><td>${escapeHtml(e.purok || '—')}</td>
    <td>${escapeHtml(e.referred_by || '—')}</td><td>${escapeHtml(formatDate(e.referred_at))}</td>
    <td><div class="row-actions"><button class="small-button" data-view-case="${Number(e.case_id)}">View</button><button class="small-button" data-endorse="${Number(e.id)}">Endorse / Sign</button></div></td></tr>`;

function renderEndorsements() {
    const ov = document.getElementById('overviewEndorseRows');
    if (ov) ov.innerHTML = state.endorsements.slice(0, 5).map(endorseRow).join('');
    const ovEmp = document.getElementById('overviewEndorseEmpty');
    if (ovEmp) ovEmp.hidden = state.endorsements.length > 0;

    const en = document.getElementById('endorseRows');
    if (en) en.innerHTML = state.endorsements.map(endorseRow).join('');
    const enEmp = document.getElementById('endorseEmpty');
    if (enEmp) enEmp.hidden = state.endorsements.length > 0;

    const badge = document.getElementById('navEndorseCount');
    if (badge) {
        badge.textContent = state.endorsements.length;
        badge.hidden = state.endorsements.length === 0;
    }
}

function renderCases() {
    const search = document.getElementById('caseSearch')?.value.trim().toLowerCase() || '';
    const status = document.getElementById('caseStatusFilter')?.value || '';
    const rows = state.cases.filter((c) => (!status || c.status === status)
        && (!search || [c.case_no, c.title, c.purok, c.severity, c.category, c.status].some((v) => String(v || '').toLowerCase().includes(search))));
    const tbody = document.getElementById('caseRows');
    if (tbody) {
        tbody.innerHTML = rows.map((c) => `<tr>
            <td><strong>${escapeHtml(c.case_no)}</strong></td>
            <td>${escapeHtml(c.title)}<span class="table-secondary">${escapeHtml(c.complainant || '')}</span></td>
            <td>${escapeHtml(c.purok || '—')}</td><td>${statusPill(c.severity)}</td><td>${statusPill(c.status)}</td>
            <td>${escapeHtml(formatDate(c.incident_datetime))}</td>
            <td><div class="row-actions"><button class="small-button" data-view-case="${Number(c.id)}">View</button></div></td></tr>`).join('');
    }
    const emptyEl = document.getElementById('caseEmpty');
    if (emptyEl) emptyEl.hidden = rows.length > 0;
}

function renderStatusFilter() {
    const select = document.getElementById('caseStatusFilter');
    if (!select) return;
    const current = select.value;
    const statuses = [...new Set(state.cases.map((c) => c.status))].sort();
    select.innerHTML = '<option value="">All statuses</option>' + statuses.map((s) => `<option value="${escapeHtml(s)}">${escapeHtml(statusLabel(s))}</option>`).join('');
    select.value = current;
}

function renderLupon() {
    const tbody = document.getElementById('luponRows');
    if (tbody) {
        tbody.innerHTML = state.lupon.map((l) => `<tr>
            <td><strong>${escapeHtml(l.case_no)}</strong><span class="table-secondary">${escapeHtml(l.title || '')}</span></td>
            <td>${escapeHtml(l.parties || '—')}</td><td>${escapeHtml(l.purok || '—')}</td>
            <td>${escapeHtml(statusLabel(l.stage))}</td><td>${escapeHtml(formatDate(l.next_hearing))}</td><td>${statusPill(l.status)}</td></tr>`).join('');
    }
    const emptyEl = document.getElementById('luponEmpty');
    if (emptyEl) emptyEl.hidden = state.lupon.length > 0;
}

function renderAll(data) {
    const m = data.overview || {};
    const mi = document.getElementById('metricIncidents');
    if (mi) mi.textContent = m.monthly_incidents ?? 0;
    const mm = document.getElementById('metricMediation');
    if (mm) mm.textContent = m.under_mediation ?? 0;
    const me = document.getElementById('metricEndorse');
    if (me) me.textContent = m.endorsements_needed ?? state.endorsements.length;
    const mr = document.getElementById('metricRate');
    if (mr) mr.textContent = `${Number(m.resolution_rate ?? 0)}%`;

    const critical = Number(m.critical_incidents ?? 0);
    const critEl = document.getElementById('criticalCount');
    if (critEl) critEl.textContent = critical;
    const critBanner = document.getElementById('criticalBanner');
    if (critBanner) critBanner.hidden = critical === 0;

    const t1 = document.getElementById('trendChart');
    if (t1) t1.innerHTML = trendSvg(state.trend);
    const t2 = document.getElementById('trendChart2');
    if (t2) t2.innerHTML = trendSvg(state.trend);

    const pb = document.getElementById('purokBars');
    if (pb) pb.innerHTML = barsHtml(state.byPurok, 'purok');
    const cb = document.getElementById('categoryBars');
    if (cb) cb.innerHTML = barsHtml(state.byCategory, 'category');
    const rr = document.getElementById('rateRing');
    if (rr) rr.innerHTML = ringSvg(m.resolution_rate);

    const advPur = document.getElementById('advisoryPurok');
    if (advPur) {
        advPur.innerHTML = '<option value="">Whole barangay</option>' + state.puroks.map((p) => `<option value="${Number(p.id)}">${escapeHtml(p.name)}</option>`).join('');
    }

    renderEndorsements();
    renderStatusFilter();
    renderCases();
    renderLupon();
    renderHeatmap();

    if (data.captain) {
        const rawName = data.captain.full_name || '';
        const name = `Capt. ${rawName}`.trim();
        const cName = document.getElementById('captainName');
        if (cName) cName.textContent = name;
        const cSide = document.getElementById('captainSideName');
        if (cSide) cSide.textContent = name;

        const settingsProfileName = document.getElementById('settingsProfileName');
        if (settingsProfileName) settingsProfileName.textContent = name;
        const settingsName = document.getElementById('settingsName');
        if (settingsName) settingsName.value = rawName;
        const settingsContact = document.getElementById('settingsContact');
        if (settingsContact) settingsContact.value = data.captain.contact_number || '';

        const initials = (rawName || 'CP').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join('');
        const avatar = document.getElementById('captainAvatar');
        if (avatar && avatar.firstChild) avatar.firstChild.textContent = initials;
        const cardAvatar = document.getElementById('settingsAvatarInitials');
        if (cardAvatar) cardAvatar.textContent = initials;

        const pic = data.captain.profile_picture;
        const sidePic = document.getElementById('captainPicture');
        if (sidePic) {
            sidePic.hidden = !pic;
            if (pic) sidePic.src = pic + '?v=' + Date.now();
        }

        const settingsPreview = document.getElementById('settingsAvatarPreview');
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
}

async function refreshData() {
    try {
        const data = await requestCaptain();
        state.cases = data.cases || []; 
        state.endorsements = data.endorsements || []; 
        state.lupon = data.lupon || [];
        state.trend = data.trend || []; 
        state.byPurok = data.by_purok || []; 
        state.byPurokAll = data.by_purok_all || data.by_purok || [];
        state.byCategory = data.by_category || []; 
        state.puroks = data.puroks || [];
        renderAll(data);
        await fetchAnalytics();
    } catch (error) { 
        showNotice(error.message, true); 
    }
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
document.getElementById('caseSearch')?.addEventListener('input', renderCases);
document.getElementById('caseStatusFilter')?.addEventListener('change', renderCases);
document.getElementById('heatPeriod')?.addEventListener('change', renderHeatmap);
document.getElementById('analyticsFilters')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    state.reportPage = 0;
    state.reportSortIndex = -1;
    state.reportSummaryFilter = '';
    await fetchAnalytics(getFilterValues('analytics'));
});
document.getElementById('resetAnalyticsFilters')?.addEventListener('click', async () => {
    document.getElementById('analyticsFilters')?.reset();
    document.getElementById('reportTableSearch').value = '';
    state.reportPage = 0;
    state.reportSortIndex = -1;
    state.reportSummaryFilter = '';
    await fetchAnalytics(getFilterValues('analytics'));
});
document.querySelectorAll('[data-report-tab]').forEach((tab) => {
    tab.addEventListener('click', () => setReportTab(tab.dataset.reportTab));
    tab.addEventListener('keydown', (event) => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const tabs = [...document.querySelectorAll('[data-report-tab]')];
        const current = tabs.indexOf(tab);
        const nextIndex = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1
            : (current + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length;
        tabs[nextIndex].focus();
        setReportTab(tabs[nextIndex].dataset.reportTab);
    });
});
document.getElementById('tableReportType')?.addEventListener('change', () => { state.reportPage = 0; state.reportSortIndex = -1; state.reportSummaryFilter = ''; renderReportTable(); });
document.getElementById('reportTableSearch')?.addEventListener('input', () => { state.reportPage = 0; state.reportSummaryFilter = ''; renderReportTable(); });
document.getElementById('reportPrev')?.addEventListener('click', () => { state.reportPage = Math.max(0, state.reportPage - 1); renderReportTable(); });
document.getElementById('reportNext')?.addEventListener('click', () => { state.reportPage += 1; renderReportTable(); });
document.getElementById('reportTable')?.addEventListener('click', (event) => {
    const header = event.target.closest('th[data-sort-index]');
    if (!header) return;
    const index = Number(header.dataset.sortIndex);
    if (state.reportSortIndex === index) state.reportSortDirection *= -1;
    else { state.reportSortIndex = index; state.reportSortDirection = 1; }
    state.reportPage = 0;
    renderReportTable();
});
document.querySelectorAll('[data-summary-filter]').forEach((card) => card.addEventListener('click', () => {
    const filter = card.dataset.summaryFilter;
    setView('analytics');
    setReportTab('records');
    const tableType = document.getElementById('tableReportType');
    const search = document.getElementById('reportTableSearch');
    if (tableType) tableType.value = 'register';
    if (search) search.value = '';
    state.reportSummaryFilter = filter;
    state.reportPage = 0;
    renderReportTable();
}));
document.getElementById('criticalReview')?.addEventListener('click', () => {
    const cs = document.getElementById('caseSearch');
    if (cs) cs.value = 'critical'; 
    renderCases(); 
    setView('registry');
});

document.addEventListener('click', (event) => {
    const t = event.target.closest('button');
    if (!t) return;
    if (t.dataset.viewCase) openCase(t.dataset.viewCase);
    if (t.dataset.endorse) openEndorse(t.dataset.endorse);
});

document.querySelectorAll('[data-generate-report]').forEach((button) => button.addEventListener('click', async () => {
    const now = new Date();
    const type = document.getElementById('reportType');
    if (type) type.value = 'dilg';
    document.getElementById('reportYear').value = String(now.getFullYear());
    document.getElementById('reportMonth').value = String(now.getMonth() + 1);
    document.getElementById('reportFrom').value = '';
    document.getElementById('reportTo').value = '';
    const preview = document.getElementById('reportPreview');
    if (preview) { preview.hidden = true; preview.innerHTML = ''; }
    document.getElementById('printReport').disabled = true;
    setView('analytics');
    setReportTab('reports');
}));
document.getElementById('reportType')?.addEventListener('change', (event) => {
    if (event.currentTarget.value === 'custom') {
        document.getElementById('reportYear').value = '';
        document.getElementById('reportMonth').value = '';
    } else if (event.currentTarget.value === 'annual') {
        document.getElementById('reportMonth').value = '';
        document.getElementById('reportFrom').value = '';
        document.getElementById('reportTo').value = '';
    } else if (['monthly', 'dilg'].includes(event.currentTarget.value)) {
        const now = new Date();
        document.getElementById('reportYear').value = String(now.getFullYear());
        document.getElementById('reportMonth').value = String(now.getMonth() + 1);
        document.getElementById('reportFrom').value = '';
        document.getElementById('reportTo').value = '';
    }
});
document.getElementById('reportForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const type = document.getElementById('reportType').value;
    const filters = getFilterValues('report');
    const preview = document.getElementById('reportPreview');
    preview.hidden = true;
    preview.innerHTML = '';
    state.reportPreviewData = null;
    document.getElementById('printReport').disabled = true;
    if (['monthly', 'dilg'].includes(type) && (!filters.year || !filters.month)) {
        showNotice('Select a year and month for a monthly report.', true);
        return;
    }
    if (type === 'annual' && !filters.year) {
        showNotice('Select a year for the annual report.', true);
        return;
    }
    if (type === 'custom' && (!filters.from || !filters.to || filters.from > filters.to)) {
        showNotice('Enter a valid start and end date for the custom report.', true);
        return;
    }
    const submit = form.querySelector('[type="submit"]');
    submit.disabled = true;
    try {
        const result = await requestCaptain('analytics_data', { filters });
        renderReportPreview(result, type, filters);
    } catch (error) {
        preview.hidden = false;
        preview.innerHTML = '<p class="empty-message">The report could not be generated. Please try again.</p>';
    } finally {
        submit.disabled = false;
    }
});
document.getElementById('reportForm')?.addEventListener('change', () => {
    const preview = document.getElementById('reportPreview');
    preview.hidden = true;
    preview.innerHTML = '';
    state.reportPreviewData = null;
    document.getElementById('printReport').disabled = true;
});
document.getElementById('printReport')?.addEventListener('click', () => {
    if (state.reportPreviewData?.records?.length) window.print();
});

document.getElementById('advisoryForm')?.addEventListener('submit', (event) => {
    event.preventDefault();
    const form = event.currentTarget, payload = Object.fromEntries(new FormData(form).entries());
    submitting(form, async () => {
        const result = await requestCaptain('advisory_create', payload);
        document.getElementById('advisoryDialog').close(); showNotice(result.message || 'Advisory published.');
    });
});

document.getElementById('endorseForm')?.addEventListener('submit', (event) => {
    event.preventDefault();
    const form = event.currentTarget, payload = Object.fromEntries(new FormData(form).entries());
    submitting(form, async () => {
        const result = await requestCaptain('case_endorse', payload);
        document.getElementById('endorseDialog').close(); showNotice(result.message || 'Case endorsed.'); await refreshData();
    });
});

/* Settings profile & password handlers */
document.getElementById('profileForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(event.currentTarget);
    formData.set('action', 'profile_update');
    try {
        const result = await requestCaptain('profile_update', {}, { formData });
        showNotice(result.message || 'Profile updated.');
        await refreshData();
    } catch (error) { showNotice(error.message, true); }
});

document.getElementById('passwordForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const payload = Object.fromEntries(new FormData(event.currentTarget).entries());
    try {
        const result = await requestCaptain('password_update', payload);
        event.currentTarget.reset();
        showNotice(result.message || 'Password changed.');
    } catch (error) { showNotice(error.message, true); }
});

const photoInput = document.getElementById('settingsPhotoInput');
const avatarPreview = document.getElementById('settingsAvatarPreview');
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

/* Dark Theme Toggle */
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

if (localStorage.getItem('balangay_theme') === 'dark') {
    applyTheme('dark');
}

themeToggleBtn?.addEventListener('click', () => {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    applyTheme(isDark ? 'light' : 'dark');
});

document.getElementById('logoutBtn')?.addEventListener('click', async () => {
    try { await fetch('api/logout.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf() } }); } catch {}
    sessionStorage.clear();
    localStorage.removeItem('currentUser');
    localStorage.removeItem('authToken');
    window.location.replace('index.html');
});

const initialView = location.hash.slice(1);
if (viewTitles[initialView]) setView(initialView);
refreshData();
