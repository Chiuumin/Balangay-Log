const officerApi = 'api/officer_console.php';
const pageNotice = document.getElementById('pageNotice');
const navLinks = [...document.querySelectorAll('.admin-nav-link[data-view]')];

const viewTitles = {
    dashboard: 'Desk Operations',
    blotter: 'Official Blotter Records',
    triage: 'Incoming Triage Queue',
    emergency: 'Urgent & Emergency Incidents',
    lupon: 'Lupon / Mediation Registry',
    dispatch: 'Tanod Dispatch Board',
    settings: 'Profile & Settings'
};

const state = {
    cases: [], triageQueue: [], urgentCases: [], luponCases: [], tanods: [],
    notifications: [], criticalCase: null, filter: 'ALL', lastNotifCount: 0
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
    if (p === 'moderate' || p === 'medium') return `<span class="pill pill-pending">Moderate</span>`;
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
    if (!pageNotice) return;
    pageNotice.textContent = msg;
    pageNotice.classList.toggle('is-error', isError);
    pageNotice.hidden = false;
    clearTimeout(showNotice.timeout);
    showNotice.timeout = setTimeout(() => { pageNotice.hidden = true; }, 4500);
}
window.showNotice = showNotice;

const csrfToken = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

async function requestOfficer(action, payload = {}, options = {}) {
    const headers = { 'X-CSRF-Token': csrfToken() };
    if (!options.formData) headers['Content-Type'] = 'application/json';
    const response = await fetch(officerApi, {
        method: action ? 'POST' : 'GET',
        credentials: 'same-origin',
        headers,
        body: action ? (options.formData || JSON.stringify({ action, ...payload })) : undefined
    });
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'Operation failed.');
    return result;
}

function setView(view) {
    navLinks.forEach(link => link.classList.toggle('is-active', link.dataset.view === view));
    document.querySelectorAll('.admin-view').forEach(sec => { sec.hidden = sec.id !== `view-${view}`; });
    const viewTitle = document.getElementById('viewTitle');
    if (viewTitle && viewTitles[view]) viewTitle.textContent = viewTitles[view];
    history.replaceState(null, '', `#${view}`);
}

const setText = (id, text) => { const el = document.getElementById(id); if (el) el.textContent = text; };
const setHtml = (id, html) => { const el = document.getElementById(id); if (el) el.innerHTML = html; };

function refLink(ref, extraStyle = '') {
    return `<span class="queue-id" data-trigger-review="true" data-ref="${escapeHtml(ref)}" title="Click to open the full case review"
                  style="cursor: pointer; text-decoration: underline; color: var(--teal); font-weight: 800; white-space: nowrap !important; word-break: keep-all !important; ${extraStyle}">${escapeHtml(ref)}</span>`;
}

function renderTriageQueue() {
    const container = document.getElementById('triageQueueContainer');
    const dedicated = document.getElementById('dedicatedTriageContainer');
    const queue = state.triageQueue;

    const triagePill = document.getElementById('triageQueuePill');
    if (triagePill) triagePill.textContent = `${queue.length} pending`;
    const triageBadge = document.getElementById('triageNavBadge');
    if (triageBadge) triageBadge.textContent = queue.length;

    if (queue.length === 0) {
        const emptyHtml = '<p class="empty-message">No pending citizen reports in triage queue.</p>';
        if (container) container.innerHTML = emptyHtml;
        if (dedicated) dedicated.innerHTML = emptyHtml;
        return;
    }

    const html = queue.map(item => `
        <div class="queue-item-card" data-ref="${escapeHtml(item.reference_number)}">
            <div class="queue-meta-block">
                <div class="queue-header-tag">
                    ${refLink(item.reference_number)}
                    ${priorityPill(item.priority_level)}
                    <span class="pill pill-pending">Review Pending</span>
                </div>
                <h3 class="queue-title" style="margin-top: 4px; cursor: default;">${escapeHtml(item.incident_type)}</h3>
                <p class="queue-details">
                    <strong>${escapeHtml(item.complainant_name)}</strong> •
                    ${escapeHtml(item.complainant_phone || 'No phone')} •
                    ${escapeHtml(item.purok)} •
                    ${timeAgo(item.created_at)}
                </p>
                <p style="margin: 6px 0 0; font-size: 11.5px; color: var(--muted); line-height: 1.4;">${escapeHtml(item.narrative_description)}</p>
            </div>
            <div class="queue-action-group">
                <button class="btn-action-primary" data-action="accept" data-ref="${escapeHtml(item.reference_number)}">Accept &amp; Blotter</button>
                <button class="btn-action-quiet" data-action="dispatch" data-ref="${escapeHtml(item.reference_number)}">Dispatch</button>
                <button class="btn-action-quiet" data-action="endorse_lupon" data-ref="${escapeHtml(item.reference_number)}">Endorse Lupon</button>
                <button class="btn-action-danger" data-action="reject" data-ref="${escapeHtml(item.reference_number)}">Flag / Reject</button>
            </div>
        </div>
    `).join('');

    if (container) container.innerHTML = html;
    if (dedicated) dedicated.innerHTML = html;
}

function renderUrgentCases() {
    const container = document.getElementById('emergencyCardsContainer');
    const cases = state.urgentCases;
    const badge = document.getElementById('emergencyNavBadge');
    if (badge) badge.textContent = cases.length;
    if (!container) return;
    if (cases.length === 0) {
        container.innerHTML = '<p class="empty-message">No critical or emergency incidents pending.</p>';
        return;
    }
    container.innerHTML = cases.map(c => `
        <div class="queue-item-card" style="border-left: 4px solid var(--coral);" data-ref="${escapeHtml(c.reference_number)}">
            <div class="queue-meta-block">
                <div class="queue-header-tag">
                    ${refLink(c.reference_number)}
                    ${priorityPill(c.priority_level)}
                    ${statusPill(c.status)}
                </div>
                <h3 class="queue-title" style="cursor: default;">${escapeHtml(c.incident_type)}</h3>
                <p class="queue-details">
                    <strong>${escapeHtml(c.complainant_name)}</strong> •
                    ${escapeHtml(c.purok)} •
                    Reported: ${timeAgo(c.created_at)} •
                    Responder: <strong>${escapeHtml(c.deployed_unit || 'Unassigned')}</strong>
                </p>
                <p style="margin: 4px 0 0; font-size: 11.5px; color: var(--muted);">${escapeHtml(c.narrative_description)}</p>
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

function renderLuponCases() {
    const container = document.getElementById('luponCardsContainer');
    const cases = state.luponCases;
    const badge = document.getElementById('luponNavBadge');
    if (badge) badge.textContent = cases.length;
    if (!container) return;
    if (cases.length === 0) {
        container.innerHTML = '<p class="empty-message">No cases currently scheduled for Lupon mediation.</p>';
        return;
    }
    container.innerHTML = cases.map(c => `
        <div class="queue-item-card" data-ref="${escapeHtml(c.reference_number)}">
            <div class="queue-meta-block">
                <div class="queue-header-tag">
                    ${refLink(c.reference_number)}
                    ${statusPill(c.status)}
                </div>
                <h3 class="queue-title" style="cursor: default;">${escapeHtml(c.incident_type)}</h3>
                <p class="queue-details">
                    Complainant: <strong>${escapeHtml(c.complainant_name)}</strong> •
                    Location: ${escapeHtml(c.purok)} •
                    Mediator: <strong>${escapeHtml(c.assigned_officer || 'Barangay Captain / Secretary')}</strong>
                </p>
                <p style="margin: 4px 0 0; font-size: 11.5px; color: var(--muted);">${escapeHtml(c.narrative_description)}</p>
            </div>
            <div class="queue-action-group">
                <button class="btn-action-primary" data-action="settle_lupon" data-ref="${escapeHtml(c.reference_number)}">Mark as Settled</button>
                <button class="btn-action-quiet" data-action="reschedule_lupon" data-ref="${escapeHtml(c.reference_number)}">Hearing Schedule</button>
            </div>
        </div>
    `).join('');
}

function renderTanodBoard() {
    const container = document.getElementById('tanodRosterContainer');
    const tanods = state.tanods;
    const available = tanods.filter(t => t.status === 'AVAILABLE').length;
    const countEl = document.getElementById('activeTanodCount');
    if (countEl) countEl.textContent = `${available} Available`;
    if (!container) return;
    container.innerHTML = tanods.map(t => {
        const isAvail = t.status === 'AVAILABLE';
        const where = t.status === 'DEPLOYED' && t.case_ref ? `${escapeHtml(t.case_ref)} • ${escapeHtml(t.purok || '')}` : escapeHtml(t.station || '');
        return `
            <div class="tanod-card">
                <div class="tanod-card-header">
                    <span class="tanod-indicator ${String(t.status).toLowerCase()}"></span>
                    <div>
                        <strong style="display: block; font-size: 13.5px; color: var(--ink);">${escapeHtml(t.name)}</strong>
                        <small style="color: var(--muted);">${where}</small>
                    </div>
                </div>
                <div>
                    ${isAvail
                        ? `<button class="button button-primary" style="width: 100%; justify-content: center;" data-action="assign_tanod" data-tanod="${escapeHtml(t.name)}">Assign to Incident</button>`
                        : `<button class="button button-quiet" style="width: 100%; justify-content: center;" disabled>${t.status === 'DEPLOYED' ? 'Currently Deployed' : 'Off Duty'}</button>`}
                </div>
            </div>`;
    }).join('');
}

function renderBlotterTable() {
    const search = document.getElementById('blotterSearchInput')?.value.trim().toLowerCase() || '';
    let cases = state.cases.filter(c => {
        if (!search) return true;
        return [c.reference_number, c.complainant_name, c.incident_type, c.purok, c.status]
            .some(v => String(v || '').toLowerCase().includes(search));
    });
    if (state.filter !== 'ALL') cases = cases.filter(c => String(c.status).toUpperCase() === state.filter);

    const tbody = document.getElementById('blotterTableRows');
    if (!tbody) return;
    tbody.innerHTML = cases.map(c => `
        <tr>
            <td>${refLink(c.reference_number, 'font-family: monospace;')}</td>
            <td><strong>${escapeHtml(c.complainant_name)}</strong></td>
            <td>${escapeHtml(c.incident_type)}</td>
            <td>${escapeHtml(c.purok)}</td>
            <td>${priorityPill(c.priority_level)}${c.severity_review_status === 'PENDING_CAPTAIN' ? ' <span class="pill pill-pending" title="Downgrade or review pending Captain approval">Review</span>' : ''}</td>
            <td>${statusPill(c.status)}</td>
            <td>${escapeHtml(c.deployed_unit || 'Desk')}</td>
            <td>${formatDate(c.created_at)}</td>
            <td>
                <div class="row-actions">
                    <button class="small-button" data-action="change_severity" data-ref="${escapeHtml(c.reference_number)}" data-priority="${escapeHtml(c.priority_level)}">Severity</button>
                    <button class="small-button" data-action="dispatch" data-ref="${escapeHtml(c.reference_number)}">Dispatch</button>
                    <button class="small-button" data-action="endorse_lupon" data-ref="${escapeHtml(c.reference_number)}">Lupon</button>
                </div>
            </td>
        </tr>
    `).join('');
    const emptyMsg = document.getElementById('blotterEmptyMsg');
    if (emptyMsg) emptyMsg.hidden = cases.length > 0;
}

function renderNotifications() {
    const notifs = state.notifications || [];
    const notifBadge = document.getElementById('topbarNotifCount');
    const dropdownBadge = document.getElementById('notifDropdownBadge');
    const dropdownList = document.getElementById('notifDropdownList');

    const totalCount = notifs.length;
    if (dropdownBadge) dropdownBadge.textContent = `${totalCount} Pending / Urgent`;

    if (notifBadge) {
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
    }
    state.lastNotifCount = totalCount;

    if (!dropdownList) return;
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
                <p class="notif-item-desc">Reported by <strong>${escapeHtml(n.complainant_name)}</strong> in ${escapeHtml(n.purok)}</p>
            </div>`;
    }).join('');
}

const notifBellBtn = document.getElementById('notifBellBtn');
const notifDropdown = document.getElementById('notifDropdown');

notifBellBtn?.addEventListener('click', (e) => {
    e.stopPropagation();
    notifDropdown?.classList.toggle('is-active');
});

document.getElementById('notifDropdownList')?.addEventListener('click', (e) => {
    const item = e.target.closest('.notif-item');
    if (!item) return;
    notifDropdown?.classList.remove('is-active');
    openCaseReview(item.dataset.ref);
});

document.addEventListener('click', (e) => {
    if (!e.target.closest('.notif-container')) notifDropdown?.classList.remove('is-active');
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

        setText('metricPendingTriage', data.metrics?.pending_triage ?? 0);
        setText('metricActiveDispatched', data.metrics?.active_dispatched ?? 0);
        setText('metricUnderInvestigation', data.metrics?.under_investigation ?? 0);
        setText('metricResolvedToday', data.metrics?.resolved_today ?? 0);

        const banner = document.getElementById('criticalBanner');
        if (banner) {
            if (state.criticalCase) {
                banner.hidden = false;
                setText('criticalBannerHeading', `Critical Case (${state.criticalCase.reference_number}) Needs Attention: ${state.criticalCase.incident_type}`);
                document.getElementById('criticalDispatchBtn')?.setAttribute('data-ref', state.criticalCase.reference_number);
            } else {
                banner.hidden = true;
            }
        }

        renderNotifications();
        renderTriageQueue();
        renderUrgentCases();
        renderLuponCases();
        renderTanodBoard();
        renderBlotterTable();

        if (data.officer) {
            const officerName = data.officer.full_name || 'Barangay Officer';
            setText('officerDisplayName', officerName);
            const settingsName = document.getElementById('settingsName');
            if (settingsName) settingsName.value = officerName;
            setText('settingsProfileName', officerName);
            const settingsContact = document.getElementById('settingsContact');
            if (settingsContact) settingsContact.value = data.officer.contact_number || '';

            const initials = officerName.split(/\s+/).filter(Boolean).slice(0, 2).map(p => p[0].toUpperCase()).join('') || 'OF';
            setText('officerInitials', initials);
            setText('settingsAvatarInitials', initials);
            const cardAvatar = document.getElementById('settingsAvatarInitials');

            const pic = data.officer.profile_picture;
            const profilePicEl = document.getElementById('officerProfilePic');
            if (profilePicEl) {
                profilePicEl.hidden = !pic;
                if (pic) profilePicEl.src = pic + '?v=' + Date.now();
            }
            const settingsPreview = document.getElementById('settingsAvatarPreview');
            if (settingsPreview) {
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
window.refreshOperationsData = refreshOperationsData;
setInterval(refreshOperationsData, 10000);

// -------------------------------------------------------------
// LIVE ANALYSIS WHILE ENCODING
// -------------------------------------------------------------
let triageDebounceTimer = null;
let inspectSeq = 0;

const PRIORITY_BADGE_STYLE = {
    Critical: ['var(--coral, #d61818)', '#fff'],
    High: ['#d97706', '#fff'],
    Moderate: ['#0284c7', '#fff'],
    Low: ['#059669', '#fff']
};

function resetInspectPanel() {
    setHtml('detectedKeywordsContainer', '<span style="font-size: 11.5px; color: var(--muted);">Type narrative details above to start AI classification...</span>');
    setText('aiReasoningBox', 'Waiting for narrative facts to evaluate jurisdiction and emergency level...');
    const scoreBadge = document.getElementById('aiUrgencyScoreBadge');
    if (scoreBadge) { scoreBadge.textContent = 'Priority: Pending'; scoreBadge.style.background = ''; scoreBadge.style.color = ''; }
    setText('aiAssessedCategoryBadge', 'Category: Pending');
    const hc = document.getElementById('aiGeneratedCategoryInput'); if (hc) hc.value = '';
    const hp = document.getElementById('aiGeneratedPriorityInput'); if (hp) hp.value = '';
}

let aiTimer = null;
let aiSeq = 0;
let lastAiText = '';

async function postInspect(action, text) {
    const response = await fetch('api/encode_case.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
        body: JSON.stringify({ action, narrative_description: text })
    });
    return response.json();
}

const GUIDANCE_WARN_STYLE = 'margin: 0 0 8px; padding: 8px 10px; border-radius: 6px; background: #fef3c7; color: #92400e; font-size: 12px; font-weight: 700; line-height: 1.4;';

function guidanceListHtml(title, items, ordered, style) {
    if (!items.length) return '';
    const tag = ordered ? 'ol' : 'ul';
    return `<div style="margin-top: 8px;"><strong>${escapeHtml(title)}</strong>` +
        `<${tag} style="margin: 4px 0 0 18px; padding: 0; ${style}">${items.map(x => `<li>${escapeHtml(x)}</li>`).join('')}</${tag}></div>`;
}

function renderInspect(result, sourceLabel) {
    const cat = result.incident_type || 'Others';
    const prio = result.priority_level || 'Low';
    const hc = document.getElementById('aiGeneratedCategoryInput'); if (hc) hc.value = cat;
    const hp = document.getElementById('aiGeneratedPriorityInput'); if (hp) hp.value = prio;
    setText('aiAssessedCategoryBadge', `Category: ${cat}`);

    const pct = Math.round(Number(result.ai_urgency_score || 0) * 100);
    const scoreBadge = document.getElementById('aiUrgencyScoreBadge');
    if (scoreBadge) {
        scoreBadge.textContent = `Priority: ${prio} (${pct}%)`;
        const [bg, fg] = PRIORITY_BADGE_STYLE[prio] || PRIORITY_BADGE_STYLE.Low;
        scoreBadge.style.background = bg;
        scoreBadge.style.color = fg;
    }

    const kws = Array.isArray(result.detected_keywords) ? result.detected_keywords : [];
    setHtml('detectedKeywordsContainer', kws.length === 0
        ? '<span style="font-size: 11px; color: var(--muted);">No immediate threat indicators identified.</span>'
        : kws.map(kw => `<span class="pill" style="background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; font-weight: 700; margin-right: 4px; margin-bottom: 4px;">🔍 ${escapeHtml(kw)}</span>`).join(''));

    const list = (v) => (Array.isArray(v) ? v.filter(x => typeof x === 'string' && x.trim() !== '') : []);
    const rulesActions = list(result.recommended_actions).slice(0, 3);
    const aiSteps = list(result.ai_next_steps).slice(0, 5);
    const questions = list(result.questions_for_resident).slice(0, 5);
    const dis = (result.disagreement && result.disagreement.any) ? result.disagreement : null;

    const warn = dis
        ? `<div style="${GUIDANCE_WARN_STYLE}">⚠️ The rules and the AI disagree. ${escapeHtml(dis.message || '')}</div>`
        : '';
    const summary = (typeof result.summary === 'string' && result.summary.trim() !== '')
        ? `<div style="margin-bottom: 6px;"><strong>Summary:</strong> ${escapeHtml(result.summary)}</div>`
        : '';

    const stepsHtml = aiSteps.length
        ? guidanceListHtml('Proceed with the following actions:', rulesActions, true, 'color: var(--teal, #19766c); font-weight: 700;') +
          guidanceListHtml('Suggested by the AI (check before acting):', aiSteps, true, 'color: var(--ink);')
        : guidanceListHtml('Proceed with the following actions:', rulesActions, true, 'color: var(--teal, #19766c); font-weight: 700;');
    const questionsHtml = guidanceListHtml('Ask the resident:', questions, false, 'color: var(--ink);');

    setHtml('aiReasoningBox', `
        <div style="font-size: 12px; line-height: 1.5; color: var(--ink);">
            ${warn}
            <div style="font-size: 11px; color: var(--muted); margin-bottom: 4px;">Source: ${escapeHtml(sourceLabel)}</div>
            ${summary}
            <strong>Evaluation:</strong> ${escapeHtml(result.reasoning || '')}
            <div style="margin-top: 5px;"><strong>Jurisdiction:</strong> ${escapeHtml(result.legal_routing || '')}</div>
            ${stepsHtml}
            ${questionsHtml}
            <div style="margin-top: 8px; font-size: 10.5px; color: var(--muted);">Suggestions only. The desk officer makes the final decision.</div>
        </div>`);
}

async function inspectNarrativeWithAI(narrativeText) {
    const trimmed = String(narrativeText || '').trim();
    clearTimeout(aiTimer);
    if (trimmed.length < 3) { lastAiText = ''; resetInspectPanel(); return; }

    const seq = ++inspectSeq;
    const statusEl = document.getElementById('aiTriageStatus');
    if (statusEl) { statusEl.textContent = 'Analyzing narrative...'; statusEl.classList.add('pulse'); }

    try {
        const result = await postInspect('inspect_triage', trimmed);
        if (seq !== inspectSeq) return;

        if (statusEl) { statusEl.textContent = 'Rules analysis updated'; statusEl.classList.remove('pulse'); }
        if (!result.success) { setText('aiReasoningBox', result.error || result.message || 'Could not analyze the narrative.'); return; }

        renderInspect(result, 'Rules');
        scheduleAiInspect(trimmed);
    } catch (err) {
        if (seq !== inspectSeq) return;
        if (statusEl) { statusEl.textContent = 'Analysis failed.'; statusEl.classList.remove('pulse'); }
        setText('aiReasoningBox', err.message);
    }
}

function scheduleAiInspect(text) {
    clearTimeout(aiTimer);
    const isThreatWord = /\b(baril|namaril|binaril|patay|pinatay|saksak|sinaksak|sunog|banta|dugo)\b/i.test(text);
    if ((text.length < 15 && !isThreatWord) || text === lastAiText) return;
    aiTimer = setTimeout(() => inspectNarrativeAI(text), 900);
}

async function inspectNarrativeAI(text) {
    const seq = ++aiSeq;
    const rulesSeq = inspectSeq;
    const statusEl = document.getElementById('aiTriageStatus');
    if (statusEl) { statusEl.textContent = 'Checking with AI...'; statusEl.classList.add('pulse'); }

    try {
        const result = await postInspect('inspect_triage_ai', text);
        if (seq !== aiSeq || rulesSeq !== inspectSeq) return;
        if (statusEl) statusEl.classList.remove('pulse');
        if (!result.success) { if (statusEl) statusEl.textContent = 'Rules analysis updated'; return; }

        lastAiText = text;
        renderInspect(result, result.ai_used ? 'Rules + AI' : 'Rules only');
        if (statusEl) {
            statusEl.textContent = result.ai_used
                ? 'Rules + AI updated'
                : 'Rules analysis updated' + (result.ai_note ? ' (' + result.ai_note + ')' : '');
        }
    } catch (err) {
        if (seq !== aiSeq) return;
        if (statusEl) { statusEl.textContent = 'Rules analysis updated (AI check failed)'; statusEl.classList.remove('pulse'); }
    }
}

// -------------------------------------------------------------
// CASE REVIEW POPUP
// -------------------------------------------------------------
const PILL_STYLE = {
    Critical: 'background: var(--coral); color: #fff;',
    High: 'background: #d97706; color: #fff;',
    Moderate: 'background: #0284c7; color: #fff;',
    Low: 'background: #059669; color: #fff;'
};
const KP_STYLE = {
    EXEMPT:    { bg: 'rgba(239, 68, 68, 0.12)', border: 'var(--coral)', color: 'var(--coral)', pill: 'background:#fee2e2;color:#991b1b;' },
    VERIFY:    { bg: 'rgba(217, 119, 6, 0.12)', border: '#d97706', color: '#b45309', pill: 'background:#fef3c7;color:#92400e;' },
    COMPETENT: { bg: 'rgba(25, 118, 108, 0.10)', border: 'var(--teal)', color: 'var(--teal)', pill: 'background:#e6f3e9;color:#287048;' }
};

async function openCaseReview(ref) {
    if (!ref) return;
    try {
        const res = await requestOfficer('get_case_review_details', { reference_number: ref });
        const c = res.case;
        const a = res.analysis || {};
        const sev = res.severity || {};
        const dups = res.duplicates || [];
        const hist = res.history || { items: [], insights: [] };
        const beat = res.beat || {};

        setText('modalReviewRef', c.reference_number);
        setText('modalReviewCategory', c.incident_type || a.incident_type || 'Incident Review');
        setText('modalComplainantName', c.complainant_name || 'Walk-in Resident');
        setText('modalComplainantPhone', c.complainant_phone || 'None provided');
        setText('modalLocationPurok', [c.purok, c.street_address].filter(Boolean).join(' • ') || 'Barangay Duale');
        setText('modalNarrativeText', c.narrative_description || 'No narrative facts recorded.');

        const prio = c.priority_level || 'Low';
        const pBadge = document.getElementById('modalPriorityBadge');
        if (pBadge) { pBadge.textContent = prio; pBadge.className = 'pill'; pBadge.setAttribute('style', PILL_STYLE[prio] || PILL_STYLE.Low); }

        const routingBanner = document.getElementById('modalLegalRoutingBanner');
        let sevNotice = document.getElementById('modalSeverityNotice');
        if (!sevNotice && routingBanner) {
            sevNotice = document.createElement('div');
            sevNotice.id = 'modalSeverityNotice';
            routingBanner.parentNode.insertBefore(sevNotice, routingBanner);
        }
        if (sevNotice) {
            if (sev.auto_raised) {
                sevNotice.setAttribute('style', 'margin: 0 0 10px; padding: 8px 12px; border-radius: 6px; background: #fee2e2; color: #991b1b; font-size: 12px; font-weight: 700;');
                sevNotice.textContent = `Severity raised from ${sev.previous} to ${sev.record} by the automatic review (logged in the audit trail).`;
            } else if (c.severity_review_status === 'PENDING_CAPTAIN') {
                sevNotice.setAttribute('style', 'margin: 0 0 10px; padding: 8px 12px; border-radius: 6px; background: #fef3c7; color: #92400e; font-size: 12px; font-weight: 700;');
                sevNotice.textContent = 'A severity change is pending Captain approval. The case keeps its current severity until then.';
            } else {
                sevNotice.removeAttribute('style');
                sevNotice.textContent = '';
            }
        }

        const kp = a.kp_status || 'COMPETENT';
        const st = KP_STYLE[kp] || KP_STYLE.COMPETENT;
        setText('modalLegalRoutingTitle', a.legal_routing || 'Standard Desk Blotter Recording');
        setText('modalLegalRoutingDesc', a.legal_routing_desc || a.ai_recommendation || '');
        const routingTitle = document.getElementById('modalLegalRoutingTitle');
        if (routingTitle) routingTitle.style.color = st.color;
        if (routingBanner) { routingBanner.style.background = st.bg; routingBanner.style.border = `1px solid ${st.border}`; }
        const pill = document.getElementById('modalVawcStatusPill');
        if (pill) { pill.textContent = a.legal_pill || 'KP Competent'; pill.className = 'pill'; pill.setAttribute('style', st.pill); }

        const wp = document.getElementById('modalWeaponFlag');
        if (wp) {
            wp.innerHTML = (a.weapon_flag && a.weapon_flag !== 'NONE')
                ? `<span style="color: var(--coral); font-weight: 800;">⚠️ ${escapeHtml(a.weapon_flag)}</span>`
                : '<span style="color: var(--muted);">NONE DETECTED</span>';
        }
        setText('modalMedicalFlag', a.medical_flag || 'No injury or medical emergency indicated.');
        setText('modalSubstanceFlag', a.substance_flag || 'No intoxication or substance use indicated.');

        const ent = a.entities || {};
        const entLines = [];
        if ((ent.respondents || []).length) entLines.push(`<div><strong>Respondent(s):</strong> ${ent.respondents.map(escapeHtml).join(', ')}</div>`);
        if ((ent.aliases || []).length) entLines.push(`<div><strong>Alias:</strong> ${ent.aliases.map(escapeHtml).join(', ')}</div>`);
        if ((ent.vehicles || []).length) entLines.push(`<div><strong>Vehicles:</strong> ${ent.vehicles.map(escapeHtml).join(', ')}</div>`);
        if ((ent.plates || []).length) entLines.push(`<div><strong>Plates:</strong> ${ent.plates.map(escapeHtml).join(', ')}</div>`);
        const actions = Array.isArray(a.recommended_actions) ? a.recommended_actions : [];
        setHtml('modalAiReasoning', `
            <div style="line-height: 1.5;">
                <div><strong>Why:</strong> ${escapeHtml(a.reasoning || '')}</div>
                ${actions.length ? `<div style="margin-top: 6px;"><strong>Recommended actions:</strong><ol style="margin: 4px 0 0 18px; padding: 0;">${actions.map(x => `<li>${escapeHtml(x)}</li>`).join('')}</ol></div>` : ''}
                ${entLines.length ? `<div style="margin-top: 6px;">${entLines.join('')}</div>` : ''}
                <div style="margin-top: 6px; font-size: 10.5px; color: var(--muted);">Decision support only. The final legal determination rests with the Punong Barangay or the Lupon Secretary.</div>
            </div>`);

        const kws = Array.isArray(a.detected_keywords) ? a.detected_keywords : [];
        const neg = Array.isArray(a.negated_terms) ? a.negated_terms : [];
        setHtml('modalDetectedKeywords',
            (kws.length === 0 && neg.length === 0)
                ? '<span style="font-size: 11px; color: var(--muted);">No specific threat words flagged.</span>'
                : kws.map(kw => `<span class="pill" style="background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; font-weight: 800; font-size: 11.5px; padding: 3px 8px;">🔍 ${escapeHtml(kw)}</span>`).join(' ')
                  + neg.map(n => `<span class="pill" style="background: #eef2f2; color: #52676f; font-size: 11px;" title="Mentioned but ignored">ignored: ${escapeHtml(n)}</span>`).join(' '));

        setHtml('modalDuplicateBox', dups.length === 0
            ? '<span style="color: var(--muted);">✓ No duplicate or related report in this Purok within 4 hours.</span>'
            : dups.map(d => `
                <div style="border-left: 3px solid ${d.label === 'LIKELY DUPLICATE' ? 'var(--coral)' : 'var(--gold)'}; padding-left: 8px; margin-bottom: 6px;">
                    <div><strong>${escapeHtml(d.label)}</strong> • ${d.similarity}% keyword overlap • ${d.minutes_apart} min apart</div>
                    <div>${refLink(d.reference_number)} ${escapeHtml(d.incident_type)} • ${escapeHtml(d.status)}${d.shared_terms.length ? ` • shared: ${d.shared_terms.map(escapeHtml).join(', ')}` : ''}</div>
                    ${d.linked
                        ? '<span style="font-size: 11px; color: var(--muted);">Already linked</span>'
                        : `<button type="button" class="btn-action-quiet" style="margin-top: 4px;" data-action="link_duplicate" data-ref="${escapeHtml(d.reference_number)}" data-primary="${escapeHtml(c.reference_number)}">Link as duplicate report</button>`}
                </div>`).join(''));

        const insights = (hist.insights || []).map(i =>
            `<div style="margin-bottom: 4px; padding: 6px 8px; border-radius: 6px; font-size: 11.5px; ${i.level === 'high' ? 'background:#fee2e2;color:#991b1b;font-weight:700;' : 'background:#eef2f2;color:#40575f;'}">${i.level === 'high' ? '📊 ' : 'ℹ️ '}${escapeHtml(i.text)}</div>`).join('');
        const items = (hist.items || []).map(h => `
            <div style="display: flex; justify-content: space-between; gap: 8px; border-bottom: 1px solid var(--line); padding: 3px 0;">
                <span>${refLink(h.reference_number)} • ${escapeHtml(h.incident_type)} <span style="color: var(--muted);">(${escapeHtml((h.reasons || []).join(', '))})</span></span>
                <span class="pill ${h.priority_level === 'Critical' ? 'pill-rejected' : 'pill-pending'}">${escapeHtml(h.status)}</span>
            </div>`).join('');
        setHtml('modalHistoryList', (insights + items) || '<span style="color: var(--muted);">✓ No related earlier reports in the past 12 months.</span>');

        setText('modalBeatRecommendation', beat.summary || `${c.purok} sector patrol beat`);

        ['modalBtnAccept', 'modalBtnDispatch', 'modalBtnLupon', 'modalBtnReject'].forEach(id => {
            const b = document.getElementById(id);
            if (b) b.setAttribute('data-ref', c.reference_number);
        });
        const luponBtn = document.getElementById('modalBtnLupon');
        if (luponBtn) {
            const blocked = kp === 'EXEMPT';
            luponBtn.disabled = blocked;
            luponBtn.style.opacity = blocked ? '0.4' : '1';
            luponBtn.style.cursor = blocked ? 'not-allowed' : 'pointer';
            luponBtn.title = blocked ? (a.legal_routing || 'Exempt from Katarungang Pambarangay') : (kp === 'VERIFY' ? 'Verify eligibility before endorsing' : 'Endorse to Katarungang Pambarangay');
        }
        const rejectBtn = document.getElementById('modalBtnReject');
        if (rejectBtn) {
            const locked = prio === 'Critical' || prio === 'High';
            rejectBtn.disabled = locked;
            rejectBtn.style.opacity = locked ? '0.4' : '1';
            rejectBtn.title = locked ? 'High and Critical cases cannot be closed with the quick Flag button.' : '';
        }

        const dlg = document.getElementById('caseReviewModal');
        if (dlg && !dlg.open) dlg.showModal();
    } catch (err) {
        showNotice('Failed to open case review: ' + err.message, true);
    }
}

// -------------------------------------------------------------
// CLICK DELEGATION
// -------------------------------------------------------------
document.addEventListener('click', async (e) => {
    const idTrigger = e.target.closest('[data-trigger-review="true"]');
    if (idTrigger) {
        e.preventDefault();
        await openCaseReview(idTrigger.dataset.ref);
        return;
    }

    const encodeTrigger = e.target.closest('#openEncodeModalBtn, #sidebarEncodeCaseBtn, [data-open-encode]');
    if (encodeTrigger) {
        e.preventDefault();
        const encodeDialog = document.getElementById('encodeCaseDialog');
        const form = document.getElementById('encodeCaseForm');
        if (form) form.reset();
        inspectSeq++;
        resetInspectPanel();
        if (encodeDialog && typeof encodeDialog.showModal === 'function') {
            if (!encodeDialog.open) encodeDialog.showModal();
        } else if (encodeDialog) {
            encodeDialog.setAttribute('open', '');
        }
        return;
    }

    const btn = e.target.closest('button[data-action]');
    if (!btn) return;
    const action = btn.dataset.action;
    const ref = btn.dataset.ref;

    try {
        if (action === 'accept') {
            const res = await requestOfficer('accept_blotter', { reference_number: ref });
            document.getElementById('caseReviewModal')?.close();
            showNotice(res.message);
            await refreshOperationsData();
        } else if (action === 'reject') {
            if (confirm(`Flag and close case #${ref}?`)) {
                const res = await requestOfficer('flag_reject', { reference_number: ref });
                document.getElementById('caseReviewModal')?.close();
                showNotice(res.message);
                await refreshOperationsData();
            }
        } else if (action === 'dispatch') {
            document.getElementById('caseReviewModal')?.close();
            document.getElementById('dispatchRefInput').value = ref;
            document.getElementById('dispatchModal').showModal();
        } else if (action === 'endorse_lupon') {
            document.getElementById('caseReviewModal')?.close();
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
            const sel = document.getElementById('dispatchUnitSelect');
            if (sel) sel.value = `Tanod Patrol (${tanod})`;
            setView('blotter');
            showNotice(`Select a case to assign ${tanod}`);
        } else if (action === 'link_duplicate') {
            const res = await requestOfficer('link_duplicate', { reference_number: ref, primary_reference: btn.dataset.primary });
            showNotice(res.message);
            await openCaseReview(btn.dataset.primary);
        }
    } catch (err) {
        showNotice(err.message, true);
    }
});

document.getElementById('criticalDispatchBtn')?.addEventListener('click', (e) => {
    const ref = e.currentTarget.getAttribute('data-ref');
    if (ref) {
        document.getElementById('dispatchRefInput').value = ref;
        document.getElementById('dispatchModal').showModal();
    }
});

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

document.getElementById('luponForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const mode = document.getElementById('luponActionMode').value;
    const ref = document.getElementById('luponRefInput').value;
    const formData = new FormData(e.currentTarget);
    try {
        const res = mode === 'endorse'
            ? await requestOfficer('endorse_lupon', { reference_number: ref, mediator: formData.get('mediator'), schedule: formData.get('schedule') })
            : await requestOfficer('settle_lupon', { reference_number: ref, notes: formData.get('settlement_notes') });
        showNotice(res.message);
        document.getElementById('luponModal').close();
        await refreshOperationsData();
    } catch (err) {
        showNotice(err.message, true);
    }
});

// -------------------------------------------------------------
// CONTACT PHONE: exactly 11 digits (09XXXXXXXXX)
// -------------------------------------------------------------
const PHONE_MESSAGES = {
    limit: 'A Philippine mobile number has only 11 digits (09XXXXXXXXX), so no more digits can be added.',
    long: 'This number has more than 11 digits, so it might be wrong. Please re-enter the phone number (09XXXXXXXXX). The number you entered has been cleared.',
    short: 'This number has fewer digits than a Philippine mobile number, which has 11 digits (09XXXXXXXXX). Please re-enter the phone number. The number you typed has been cleared.',
    prefix: 'A Philippine mobile number starts with 09 (09XXXXXXXXX). Please re-enter the phone number. The number you typed has been cleared.'
};
const phoneInput = document.getElementById('complainantPhoneInput');
let phoneErrorEl = null;
let phoneLastGood = '';

function showPhoneError(kind, clearBox = true) {
    if (!phoneInput) return;
    if (!phoneErrorEl) {
        phoneErrorEl = document.createElement('p');
        phoneErrorEl.id = 'complainantPhoneError';
        phoneErrorEl.setAttribute('role', 'alert');
        phoneErrorEl.style.cssText = 'margin: 4px 0 0; font-size: 12px; font-weight: 700; color: #d61818; line-height: 1.4;';
        phoneInput.insertAdjacentElement('afterend', phoneErrorEl);
        phoneInput.setAttribute('aria-describedby', 'complainantPhoneError');
    }
    phoneErrorEl.textContent = PHONE_MESSAGES[kind];
    phoneErrorEl.hidden = false;
    if (clearBox) {
        phoneInput.value = '';
        phoneLastGood = '';
        phoneInput.setAttribute('aria-invalid', 'true');
    }
}

function hidePhoneError() {
    if (phoneErrorEl) phoneErrorEl.hidden = true;
    if (phoneInput) phoneInput.removeAttribute('aria-invalid');
}

function isSingleDigitInsertion(prev, cur) {
    if (prev.length !== 11 || cur.length !== 12) return false;
    for (let i = 0; i < cur.length; i++) {
        if (cur.slice(0, i) + cur.slice(i + 1) === prev) return true;
    }
    return false;
}

function validatePhoneField(focusOnError) {
    if (!phoneInput) return true;
    const digits = phoneInput.value.replace(/\D/g, '');
    if (digits === '') { phoneInput.value = ''; phoneLastGood = ''; return true; }
    let kind = null;
    if (digits.length > 11) kind = 'long';
    else if (digits.length < 11) kind = 'short';
    else if (!digits.startsWith('09')) kind = 'prefix';
    if (!kind) { phoneInput.value = digits; phoneLastGood = digits; hidePhoneError(); return true; }
    showPhoneError(kind);
    if (focusOnError) phoneInput.focus();
    return false;
}

if (phoneInput) {
    phoneInput.setAttribute('inputmode', 'numeric');
    phoneInput.addEventListener('focus', () => { phoneLastGood = phoneInput.value.replace(/\D/g, '').slice(0, 11); });
    phoneInput.addEventListener('input', () => {
        const digits = phoneInput.value.replace(/\D/g, '');
        if (digits.length > 11) {
            if (isSingleDigitInsertion(phoneLastGood, digits)) {
                const caret = Math.max(0, (phoneInput.selectionStart ?? 12) - 1);
                phoneInput.value = phoneLastGood;
                try { phoneInput.setSelectionRange(caret, caret); } catch (e) {}
                showPhoneError('limit', false);
            } else {
                showPhoneError('long');
            }
            return;
        }
        if (digits !== phoneInput.value) phoneInput.value = digits;
        phoneLastGood = digits;
        hidePhoneError();
    });
    phoneInput.addEventListener('blur', () => { validatePhoneField(false); });
    document.getElementById('encodeCaseDialog')?.addEventListener('close', hidePhoneError);
}

document.getElementById('encodeCaseForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.currentTarget;
    if (!validatePhoneField(true)) return;
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;
    try {
        const formData = new FormData(form);
        const narrative = String(formData.get('narrative_description') || '').trim();
        if (!narrative) {
            showNotice('Narrative description is required.', true);
            return;
        }
        const res = await requestOfficer('encode_case', {
            complainant_name: String(formData.get('complainant_name') || '').trim(),
            complainant_phone: String(formData.get('complainant_phone') || '').trim(),
            purok: String(formData.get('purok') || '').trim(),
            street_address: String(formData.get('street_address') || '').trim(),
            narrative_description: narrative,
            incident_type: String(formData.get('incident_type') || '').trim()
        });
        const encodeDialog = document.getElementById('encodeCaseDialog');
        if (encodeDialog) encodeDialog.close();
        showNotice(res.message || 'Case recorded successfully.');
        await refreshOperationsData();
    } catch (err) {
        showNotice(err.message, true);
    } finally {
        if (submitBtn) submitBtn.disabled = false;
    }
});

document.addEventListener('input', (e) => {
    if (e.target && (e.target.matches('#encodeCaseForm textarea[name="narrative_description"]') || e.target.id === 'narrativeDescriptionInput')) {
        clearTimeout(triageDebounceTimer);
        clearTimeout(aiTimer);
        triageDebounceTimer = setTimeout(() => inspectNarrativeWithAI(e.target.value), 450);
    }
});

// -------------------------------------------------------------
// PROFILE, PASSWORD, FILTERS, DRAWER, THEME, LOGOUT
// -------------------------------------------------------------
document.getElementById('profileForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(event.currentTarget);
    formData.set('action', 'profile_update');
    try {
        const result = await requestOfficer('profile_update', {}, { formData });
        showNotice(result.message || 'Profile updated successfully.');
        await refreshOperationsData();
    } catch (error) {
        showNotice(error.message, true);
    }
});

document.getElementById('passwordForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const payload = Object.fromEntries(new FormData(event.currentTarget).entries());
    try {
        const result = await requestOfficer('password_update', payload);
        event.currentTarget.reset();
        showNotice(result.message || 'Password updated successfully.');
    } catch (error) {
        showNotice(error.message, true);
    }
});

const photoInput = document.getElementById('settingsPhotoInput');
const avatarPreview = document.getElementById('settingsAvatarPreview');
const avatarInitials = document.getElementById('settingsAvatarInitials');
if (photoInput) {
    photoInput.addEventListener('change', function () {
        const file = this.files && this.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function (ev) {
            if (avatarPreview) { avatarPreview.src = ev.target.result; avatarPreview.style.display = 'block'; }
            if (avatarInitials) avatarInitials.style.display = 'none';
        };
        reader.readAsDataURL(file);
    });
}

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
navLinks.forEach(link => link.addEventListener('click', () => setView(link.dataset.view)));

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
    applyTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
});

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