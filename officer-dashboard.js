const officerApi = 'api/officer_console.php';
const pageNotice = document.getElementById('pageNotice');
const navLinks = [...document.querySelectorAll('.admin-nav-link[data-view]')];

const viewTitles = {
    dashboard: 'Desk Operations',
    blotter: 'Official Blotter Records',
    'case-detail': 'Case Details',
    triage: 'Incoming Triage Queue',
    emergency: 'Urgent & Emergency Incidents',
    lupon: 'Lupon / Mediation Registry',
    dispatch: 'Tanod Dispatch Board',
    settings: 'Profile & Settings'
};

const state = {
    cases: [],
    triageQueue: [],
    urgentCases: [],
    luponCases: [],
    tanods: [],
    dispatchQueue: [],
    dispatchMetrics: { on_duty: 0, available: 0, assigned: 0, off_duty: 0 },
    dispatchTrackingReady: false,
    notifications: [],
    criticalCase: null,
    filter: 'ALL',
    lastNotifCount: 0,
    currentCaseReference: null,
    pendingResolutionPayload: null,
    currentUserRole: '',
    currentUserName: '',
    currentCaseFindings: [],
    currentCaseResolution: null
};

const escapeHtml = (val) => String(val ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
}[c]));

const formatDate = (val) => val ? new Date(String(val).replace(' ', 'T')).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '—';
const formatDateTime = (val) => val ? new Date(String(val).replace(' ', 'T')).toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : 'Not scheduled';

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
    const s = String(status || '').toUpperCase().replace(/\s+/g, '_');
    const statuses = {
        PENDING: ['pending', 'Pending Triage'],
        UNDER_REVIEW: ['pending', 'Pending Triage'],
        CRITICAL: ['pending', 'Pending Triage'],
        IN_PROGRESS: ['in-progress', 'In Progress'],
        DISPATCHED: ['dispatched', 'Dispatched'],
        FOR_RESOLUTION: ['resolution', 'Awaiting Resolution'],
        LUPON: ['resolution', 'Awaiting Resolution'],
        RESOLVED: ['resolved', 'Resolved'],
        CLOSED: ['resolved', 'Resolved']
    };
    const [className, label] = statuses[s] || ['unknown', s.replace(/_/g, ' ') || 'Unknown'];
    return `<span class="status-badge status-badge--${className}">${escapeHtml(label)}</span>`;
}

function caseStatusLabel(status) {
    const normalized = String(status || '').toUpperCase().replace(/\s+/g, '_');
    const labels = {
        PENDING: 'Pending Triage',
        UNDER_REVIEW: 'Pending Triage',
        CRITICAL: 'Pending Triage',
        IN_PROGRESS: 'In Progress',
        DISPATCHED: 'Dispatched',
        FOR_RESOLUTION: 'Awaiting Resolution',
        LUPON: 'Awaiting Resolution',
        RESOLVED: 'Resolved',
        CLOSED: 'Resolved',
        TRIAGE: 'Triage Assessment',
        PRIORITY_UPDATED: 'Priority Updated',
        REFERRAL: 'Referral',
        ENDORSED: 'Endorsement Completed',
        CAPTAIN_ENDORSED: 'Captain Endorsed'
    };
    return labels[normalized] || normalized.replace(/_/g, ' ') || 'Case Activity';
}

function caseResolutionOutcomeLabel(outcome) {
    const labels = {
        RESOLVED_THROUGH_INTERVENTION: 'Resolved Through Intervention',
        SETTLED_THROUGH_MEDIATION: 'Settled Through Mediation',
        REFERRED_TO_ANOTHER_AUTHORITY: 'Referred to Another Authority',
        WITHDRAWN: 'Withdrawn',
        OTHER: 'Other'
    };
    return labels[String(outcome || '').toUpperCase()] || String(outcome || 'Outcome not recorded');
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
                    <span class="queue-id" data-trigger-review="true" data-ref="${escapeHtml(item.reference_number)}" style="cursor: pointer; text-decoration: underline; color: var(--teal); font-weight: 800;">
                        ${escapeHtml(item.reference_number)}
                    </span>
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
        <div class="queue-item-card lupon-case-card" data-ref="${escapeHtml(c.reference_number)}">
            <div class="queue-meta-block">
                <div class="queue-header-tag">
                    ${refLink(c.reference_number)}
                    ${statusPill(c.status)}
                </div>
                <h3 class="queue-title" style="cursor: default;">${escapeHtml(c.incident_type)}</h3>
                <p class="queue-details">
                    Complainant: <strong>${escapeHtml(c.complainant_name)}</strong> • 
                    Location: ${escapeHtml(c.purok)} • 
                    Mediator: <strong>${escapeHtml(c.assigned_officer || 'Barangay Captain / Secretary')}</strong> •
                    Hearing: <strong>${escapeHtml(formatDateTime(c.next_hearing))}</strong>
                </p>
                <p style="margin: 4px 0 0; font-size: 11.5px; color: var(--muted);">${escapeHtml(c.narrative_description)}</p>
            </div>
            <div class="queue-action-group lupon-case-actions">
                <button class="btn-action-primary" data-action="complete_mediation" data-ref="${escapeHtml(c.reference_number)}">Complete Mediation</button>
                <button class="btn-action-quiet" data-action="reschedule_lupon" data-ref="${escapeHtml(c.reference_number)}">Hearing Schedule</button>
            </div>
        </div>
    `).join('');
}

function populateDispatchDropdown(selectedTanodIds = []) {
    const unitSelect = document.getElementById('dispatchUnitSelect');
    if (!unitSelect) return;

    unitSelect.innerHTML = state.tanods
        .filter(t => String(t.status || '').trim().toUpperCase() === 'AVAILABLE')
        .map(t => `<option value="${escapeHtml(t.id)}" ${selectedTanodIds.includes(String(t.id)) ? 'selected' : ''}>${escapeHtml(t.name)} · ${escapeHtml(t.station || 'Patrol Beat')}</option>`)
        .join('');
}

function renderTanodBoard() {
    const container = document.getElementById('tanodRosterContainer');
    const tanods = state.tanods;
    const countEl = document.getElementById('activeTanodCount');
    if (countEl) countEl.textContent = `${state.dispatchMetrics.available} Available`;
    document.getElementById('dispatchCountOnDuty').textContent = state.dispatchMetrics.on_duty;
    document.getElementById('dispatchCountAvailable').textContent = state.dispatchMetrics.available;
    document.getElementById('dispatchCountAssigned').textContent = state.dispatchMetrics.assigned;
    document.getElementById('dispatchCountOffDuty').textContent = state.dispatchMetrics.off_duty;
    document.getElementById('dispatchMigrationNotice').hidden = state.dispatchTrackingReady;

    populateDispatchDropdown();
    if (container && tanods.length === 0) {
        container.innerHTML = '<p class="empty-message">No responders found in roster.</p>';
    }
    if (container && tanods.length > 0) container.innerHTML = tanods.map(t => {
        const rawStatus = String(t.status || 'AVAILABLE').trim().toUpperCase();
        const currentStatus = rawStatus === 'IN_USE' ? 'DEPLOYED' : rawStatus;
        const indicatorClass = currentStatus === 'DEPLOYED' ? 'deployed' : currentStatus === 'OFF_DUTY' ? 'off_duty' : 'available';
        const statusLabel = currentStatus === 'DEPLOYED' ? 'Assigned' : currentStatus.replace('_', ' ');
        const assignment = t.assigned_reference
            ? `${t.assigned_reference}${t.current_assignment ? ` · ${t.current_assignment}` : ''}`
            : (t.current_assignment || 'None');
        return `
            <div class="tanod-card">
                <div class="tanod-card-header">
                    <span class="tanod-indicator ${indicatorClass}"></span>
                    <div>
                        <strong style="display: block; font-size: 13.5px; color: var(--ink);">${escapeHtml(t.name)}</strong>
                        <small style="color: var(--muted);">${escapeHtml(t.station || 'Patrol Beat')} · ${escapeHtml(statusLabel)}</small>
                    </div>
                </div>
                <div class="tanod-card__details">
                    <span>Contact: ${escapeHtml(t.contact_number || 'Not recorded')}</span>
                    <span>Current assignment: ${escapeHtml(assignment)}</span>
                </div>
                <div class="tanod-card__actions">
                    <select class="tanod-availability-select" data-tanod-availability="${escapeHtml(t.id)}" aria-label="Availability for ${escapeHtml(t.name)}" ${currentStatus === 'DEPLOYED' ? 'disabled' : ''}>
                        <option value="AVAILABLE" ${currentStatus === 'AVAILABLE' ? 'selected' : ''}>Available</option>
                        <option value="OFF_DUTY" ${currentStatus === 'OFF_DUTY' ? 'selected' : ''}>Off Duty</option>
                    </select>
                    <button class="button button-quiet" type="button" data-edit-responder="${escapeHtml(t.id)}">Edit profile</button>
                </div>
            </div>
        `;
    }).join('');

    renderDispatchQueue();
}

function renderDispatchQueue() {
    const container = document.getElementById('dispatchQueueContainer');
    const queue = state.dispatchQueue;
    const count = document.getElementById('dispatchQueueCount');
    if (count) count.textContent = `${queue.length} ${queue.length === 1 ? 'case' : 'cases'}`;
    if (!container) return;
    if (!queue.length) {
        container.innerHTML = '<p class="empty-message">No cases are awaiting responder assignment or field response.</p>';
        return;
    }
    const labels = {
        DISPATCHED: 'Dispatched · awaiting acknowledgement',
        ACKNOWLEDGED: 'Assignment acknowledged',
        ON_SCENE: 'Responder on scene',
        RESPONSE_SUBMITTED: 'Field response submitted'
    };
    container.innerHTML = queue.map(item => {
        const tracked = Boolean(item.dispatch_id);
        const progress = String(item.dispatch_status || '').toUpperCase();
        const assigned = tracked ? (item.responders || item.dispatch_unit || 'Assigned responder') : (item.deployed_unit || 'Not assigned');
        const canAssign = !tracked || progress === 'RESPONSE_SUBMITTED';
        let progressActions = '';
        if (!canAssign && progress === 'DISPATCHED') {
            progressActions = `<button class="button button-quiet" type="button" data-response-step="ACKNOWLEDGE" data-ref="${escapeHtml(item.reference_number)}">Record Acknowledgement</button>`;
        } else if (!canAssign && progress === 'ACKNOWLEDGED') {
            progressActions = `<button class="button button-quiet" type="button" data-response-step="ARRIVE" data-ref="${escapeHtml(item.reference_number)}">Record Arrival</button>`;
        } else if (!canAssign && progress === 'ON_SCENE') {
            progressActions = `<form class="dispatch-response-form" data-response-form="${escapeHtml(item.reference_number)}">
                <label>Field findings<textarea name="findings" rows="2" maxlength="5000" required></textarea></label>
                <label>Action taken<textarea name="action_taken" rows="2" maxlength="5000" required></textarea></label>
                <button class="button button-primary" type="submit">Submit Field Response</button>
            </form>`;
        }
        const progressHtml = tracked
            ? `<div class="dispatch-case-card__progress">
                <strong>${escapeHtml(labels[progress] || progress)}</strong>
                <span>Responders: ${escapeHtml(assigned)}</span>
                <span>Dispatched: ${escapeHtml(formatDateTime(item.dispatched_at))}${item.dispatched_by_name ? ` · by ${escapeHtml(item.dispatched_by_name)}` : ''}</span>
                ${item.directive_notes ? `<span>Directive: ${escapeHtml(item.directive_notes)}</span>` : ''}
                ${item.acknowledged_at ? `<span>Acknowledged: ${escapeHtml(formatDateTime(item.acknowledged_at))}${item.acknowledged_by_name ? ` · by ${escapeHtml(item.acknowledged_by_name)}` : ''}</span>` : ''}
                ${item.arrived_at ? `<span>Arrived: ${escapeHtml(formatDateTime(item.arrived_at))}${item.arrived_by_name ? ` · by ${escapeHtml(item.arrived_by_name)}` : ''}</span>` : ''}
                ${item.findings ? `<span>Findings: ${escapeHtml(item.findings)}</span>` : ''}
                ${item.action_taken ? `<span>Action taken: ${escapeHtml(item.action_taken)}</span>` : ''}
                ${item.updated_by_name ? `<small>Recorded by ${escapeHtml(item.updated_by_name)}${item.updated_at ? ` · ${escapeHtml(formatDateTime(item.updated_at))}` : ''}</small>` : ''}
            </div>`
            : `<div class="dispatch-case-card__progress"><strong>${escapeHtml(item.status === 'DISPATCHED' ? 'Previously dispatched · tracking not available' : 'Awaiting responder assignment')}</strong><span>${escapeHtml(assigned)}</span></div>`;
        return `<article class="dispatch-case-card">
            <div class="dispatch-case-card__top">
                <div class="dispatch-case-card__meta">
                    <strong>${escapeHtml(item.reference_number)} · ${escapeHtml(item.incident_type || 'Incident')}</strong>
                    <span>${escapeHtml(item.complainant_name || 'Unknown complainant')} · ${escapeHtml(item.purok || 'Location not recorded')}</span>
                    <span>${escapeHtml(caseStatusLabel(item.status))} · ${escapeHtml(item.priority_level || 'Priority not recorded')}</span>
                </div>
                <small>Reported ${escapeHtml(formatDateTime(item.created_at))}</small>
            </div>
            ${progressHtml}
            <div class="dispatch-case-card__actions">
                ${canAssign ? `<button class="button button-primary" type="button" data-assign-case="${escapeHtml(item.reference_number)}" ${state.dispatchTrackingReady ? '' : 'disabled'}>${tracked ? 'Assign Responders Again' : 'Assign / Dispatch'}</button>` : progressActions}
                <button class="button button-quiet" type="button" data-view-case="${escapeHtml(item.reference_number)}">View Case Details</button>
            </div>
        </article>`;
    }).join('');
}

function renderBlotterTable() {
    const search = document.getElementById('blotterSearchInput')?.value.trim().toLowerCase() || '';
    const caseStatus = c => String(c.status || '').toUpperCase().replace(/\s+/g, '_');
    const countAll = document.getElementById('blotterCountAll');
    const countNeedsAction = document.getElementById('blotterCountNeedsAction');
    const countInProgress = document.getElementById('blotterCountInProgress');
    const countResolved = document.getElementById('blotterCountResolved');
    const isNeedsAction = c => ['PENDING', 'UNDER_REVIEW', 'CRITICAL'].includes(caseStatus(c));
    const isResolved = c => ['RESOLVED', 'CLOSED'].includes(caseStatus(c));
    if (countAll) countAll.textContent = state.cases.length;
    if (countNeedsAction) countNeedsAction.textContent = state.cases.filter(isNeedsAction).length;
    if (countInProgress) countInProgress.textContent = state.cases.filter(c => caseStatus(c) === 'IN_PROGRESS').length;
    if (countResolved) countResolved.textContent = state.cases.filter(isResolved).length;

    let cases = state.cases.filter(c => {
        if (!search) return true;
        return [c.reference_number, c.complainant_name, c.incident_type, c.purok]
            .some(v => String(v || '').toLowerCase().includes(search));
    });

    const matchesFilter = {
        ALL: () => true,
        NEEDS_ACTION: isNeedsAction,
        PENDING: c => ['PENDING', 'UNDER_REVIEW'].includes(caseStatus(c)),
        IN_PROGRESS: c => caseStatus(c) === 'IN_PROGRESS',
        DISPATCHED: c => caseStatus(c) === 'DISPATCHED',
        FOR_RESOLUTION: c => ['FOR_RESOLUTION', 'LUPON'].includes(caseStatus(c)),
        RESOLVED: isResolved
    }[state.filter] || (() => true);
    cases = cases.filter(matchesFilter);

    const tbody = document.getElementById('blotterTableRows');
    if (!tbody) return;

    tbody.innerHTML = cases.map(c => {
        const reference = escapeHtml(c.reference_number);
        return `
        <tr>
            <td><button type="button" class="blotter-reference-link" data-trigger-review="true" data-ref="${reference}">${reference}</button></td>
            <td class="blotter-cell--truncate" title="${escapeHtml(c.complainant_name)}">${escapeHtml(c.complainant_name)}</td>
            <td class="blotter-cell--truncate" title="${escapeHtml(c.incident_type)}">${escapeHtml(c.incident_type)}</td>
            <td class="blotter-cell--truncate" title="${escapeHtml(c.purok)}">${escapeHtml(c.purok)}</td>
            <td>${priorityPill(c.priority_level)}</td>
            <td>${statusPill(c.status)}</td>
            <td class="blotter-cell--truncate" title="${escapeHtml(c.deployed_unit || 'Desk')}">${escapeHtml(c.deployed_unit || 'Desk')}</td>
            <td>${formatDate(c.created_at)}</td>
            <td><button type="button" class="small-button" data-trigger-review="true" data-ref="${reference}">View Case</button></td>
        </tr>
        `;
    }).join('');
    const emptyMsg = document.getElementById('blotterEmptyMsg');
    if (emptyMsg) emptyMsg.hidden = cases.length > 0;
    document.querySelectorAll('[data-blotter-filter]').forEach(button => {
        button.classList.toggle('is-selected', button.dataset.blotterFilter === state.filter);
    });
    document.querySelectorAll('[data-blotter-summary-filter]').forEach(button => {
        button.setAttribute('aria-pressed', String(button.dataset.blotterSummaryFilter === state.filter));
    });
}

function detailField(label, value) {
    return `<div class="case-detail-field"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value || 'Not recorded')}</strong></div>`;
}

function renderCaseDetail(data) {
    const c = data.case;
    const timeline = Array.isArray(data.timeline) ? [...data.timeline] : [];
    const status = String(c.status || '').toUpperCase().replace(/\s+/g, '_');
    const isResolved = status === 'RESOLVED';
    const isAdministrativelyClosed = status === 'CLOSED';
    const isDispatched = status === 'DISPATCHED';
    const role = String(state.currentUserRole).toUpperCase();
    const canAct = ['OFFICER', 'CAPTAIN', 'ADMIN', 'SYSTEM_ADMIN'].includes(role);
    const canClose = ['CAPTAIN', 'ADMIN', 'SYSTEM_ADMIN'].includes(role);
    const canReopen = canClose;
    const workflowReady = data.workflow_ready === true;
    const assignedOfficer = c.assigned_officer_name || '';
    const assignedUnit = c.deployed_unit || '';
    const endorsements = Array.isArray(data.endorsements) ? data.endorsements : [];
    const captainEndorsement = [...endorsements].reverse().find(item => item.type === 'CAPTAIN_ENDORSEMENT');
    const captainEndorsementStatus = !captainEndorsement
        ? 'Not referred'
        : String(captainEndorsement.status || '').toUpperCase() === 'ENDORSED'
        ? `Captain Endorsed${captainEndorsement.endorsed_by ? ` by ${captainEndorsement.endorsed_by}` : ''}${captainEndorsement.endorsed_at ? ` on ${formatDateTime(captainEndorsement.endorsed_at)}` : ''}`
        : `Awaiting Captain Endorsement${captainEndorsement.referred_at ? ` · Referred ${formatDateTime(captainEndorsement.referred_at)}` : ''}`;
    const captainDestination = document.querySelector('#luponDestinationSelect option[value="CAPTAIN"]');
    if (captainDestination) {
        captainDestination.disabled = Boolean(captainEndorsement);
        captainDestination.textContent = captainEndorsement
            ? 'Barangay Captain (Already referred)'
            : 'Barangay Captain';
    }

    if (!timeline.some(item => /(initial report|report submitted|report logged)/i.test(item.action || ''))) {
        timeline.unshift({
            action: 'Incident report submitted.',
            status: 'PENDING',
            user: c.scenario_type === 'ONLINE_PORTAL' ? 'Resident Portal' : 'System Desk',
            created_at: c.created_at,
            type: 'report_submission'
        });
    }
    timeline.sort((a, b) => String(a.created_at || '').localeCompare(String(b.created_at || '')));

    document.getElementById('caseDetailTitle').textContent = c.incident_type || 'Incident report';
    document.getElementById('caseDetailSubtitle').textContent = `${c.reference_number} · Reported ${formatDateTime(c.created_at)}`;
    document.getElementById('caseDetailStatus').innerHTML = statusPill(status);
    document.getElementById('caseIncidentFields').innerHTML = [
        detailField('Reference Number', c.reference_number),
        detailField('Incident Title', c.incident_type),
        detailField('Category', c.incident_type),
        detailField('Purok', c.purok),
        detailField('Date Reported', formatDateTime(c.created_at)),
        detailField('Incident Date', formatDateTime(c.incident_datetime)),
        detailField('Priority', c.priority_level),
        detailField('Status', caseStatusLabel(status)),
        detailField('Captain Endorsement', captainEndorsementStatus),
        `<div class="case-detail-field case-detail-field--wide"><span>Description</span><p>${escapeHtml(c.narrative_description || 'No description recorded.')}</p></div>`
    ].join('');

    const people = [
        detailField('Complainant', c.complainant_name),
        detailField('Complainant Contact', c.complainant_phone)
    ];
    if (data.lupon?.parties) people.push(detailField('Other Parties', data.lupon.parties));
    document.getElementById('casePeopleFields').innerHTML = people.join('');

    const assignmentFields = [
        detailField('Assigned Officer', assignedOfficer || 'Not assigned'),
        detailField('Assigned Personnel / Unit', assignedUnit || 'Not assigned')
    ];
    if (data.lupon?.next_hearing) assignmentFields.push(detailField('Next Lupon Hearing', formatDateTime(data.lupon.next_hearing)));
    document.getElementById('caseAssignmentFields').innerHTML = assignmentFields.join('');

    document.getElementById('caseTimeline').innerHTML = timeline.length
        ? timeline.map(item => `
            <li class="case-timeline-entry">
                <div class="case-timeline-entry__heading">
                    <strong>${escapeHtml(caseStatusLabel(item.status))}</strong>
                    <time>${escapeHtml(formatDateTime(item.created_at))}</time>
                </div>
                <p>${escapeHtml(item.action || 'Case activity recorded.')}</p>
                <small>By ${escapeHtml(item.user || 'System')}${item.assigned_to ? ` · Assigned to ${escapeHtml(item.assigned_to)}` : ''}</small>
            </li>
        `).join('')
        : '<li class="empty-message">No case activity is recorded yet.</li>';

    const findingEntries = Array.isArray(data.findings) ? data.findings : [];
    state.currentCaseFindings = findingEntries;
    const findingsSection = document.getElementById('caseFindingsSection');
    const canRecordFindings = canAct && !isAdministrativelyClosed && !isResolved
        && workflowReady
        && ['PENDING', 'UNDER_REVIEW', 'CRITICAL', 'IN_PROGRESS', 'DISPATCHED', 'FOR_RESOLUTION'].includes(status);
    findingsSection.hidden = !findingEntries.length && !canRecordFindings;
    document.getElementById('caseFindingsList').innerHTML = findingEntries.length
        ? `<ul class="case-findings-list">${findingEntries.map(item => `<li><strong>${escapeHtml(formatDateTime(item.created_at))}</strong><p><b>Observations:</b> ${escapeHtml(item.observations)}${item.persons_contacted ? `<br><b>Persons contacted:</b> ${escapeHtml(item.persons_contacted)}` : ''}<br><b>Action taken:</b> ${escapeHtml(item.action_taken)}<br><b>Findings:</b> ${escapeHtml(item.findings)}${item.recommended_next_steps ? `<br><b>Recommended next steps:</b> ${escapeHtml(item.recommended_next_steps)}` : ''}</p><small>By ${escapeHtml(item.submitted_by_name || 'System')}</small></li>`).join('')}</ul>`
        : '<p class="case-detail-empty">No findings or evidence recorded yet.</p>';
    const findingForm = document.getElementById('caseFindingForm');
    findingForm.hidden = !canRecordFindings;
    findingForm.dataset.referenceNumber = c.reference_number;

    const resolutionEntries = Array.isArray(data.resolutions) ? data.resolutions : [];
    const closureEntries = Array.isArray(data.closures) ? data.closures : [];
    const resolutionSection = document.getElementById('caseResolutionSection');
    const canResolve = canAct && !isAdministrativelyClosed && !isResolved
        && workflowReady
        && ['IN_PROGRESS', 'DISPATCHED', 'FOR_RESOLUTION'].includes(status)
        && findingEntries.length > 0;
    const canClosePending = workflowReady && canClose && ['PENDING', 'UNDER_REVIEW', 'CRITICAL'].includes(status) && findingEntries.length > 0;
    const canFinalize = canClose && (isResolved || canClosePending);
    resolutionSection.hidden = workflowReady
        ? !resolutionEntries.length && !closureEntries.length && !canResolve && !canFinalize
        : false;
    document.getElementById('caseResolutionContent').innerHTML = !workflowReady
        ? '<p class="notice">Case viewing is available, but findings and resolution actions need the database setup. Apply <code>case_workflow_migration.sql</code> to enable them.</p>'
        : resolutionEntries.length || closureEntries.length
        ? `<ul class="case-findings-list">${resolutionEntries.map(item => `<li><strong>${escapeHtml(formatDateTime(item.resolved_at))} · ${escapeHtml(caseResolutionOutcomeLabel(item.outcome))}</strong><p><b>Conclusion:</b> ${escapeHtml(item.conclusion)}${item.settlement_terms ? `<br><b>Settlement terms:</b> ${escapeHtml(item.settlement_terms)}` : ''}${item.remaining_obligations ? `<br><b>Remaining obligations:</b> ${escapeHtml(item.remaining_obligations)}` : ''}${item.follow_up_at ? `<br><b>Follow-up:</b> ${escapeHtml(formatDateTime(item.follow_up_at))}` : ''}</p><small>By ${escapeHtml(item.resolved_by_name || 'System')}; status: ${escapeHtml(caseStatusLabel(item.resulting_status))}</small></li>`).join('')}${closureEntries.map(item => `<li><strong>${escapeHtml(formatDateTime(item.closed_at))} · Resolution finalized</strong><p><b>Outcome:</b> ${escapeHtml(caseResolutionOutcomeLabel(item.outcome))}<br><b>Findings reviewed:</b> ${escapeHtml(item.findings_reviewed_summary)}<br><b>Conclusion:</b> ${escapeHtml(item.conclusion)}</p><small>Finalized by ${escapeHtml(item.closed_by_name || 'System')}</small></li>`).join('')}</ul>`
        : '<p class="case-detail-empty">No resolution has been recorded.</p>';
    const resolutionForm = document.getElementById('caseResolutionForm');
    const latestResolution = resolutionEntries.length ? resolutionEntries[resolutionEntries.length - 1] : null;
    state.currentCaseResolution = latestResolution;
    const closeFromSavedResolution = canFinalize
        && isResolved
        && latestResolution?.resulting_status === 'RESOLVED'
        && !latestResolution.remaining_obligations
        && !latestResolution.follow_up_at;
    resolutionForm.hidden = !workflowReady || isResolved || (!canResolve && !canFinalize);
    resolutionForm.dataset.referenceNumber = c.reference_number;
    const closeOnly = canFinalize && !canResolve;
    resolutionForm.dataset.closeOnly = String(closeOnly);
    document.getElementById('caseCloseNowLabel').hidden = !canClose || closeOnly;
    document.getElementById('caseCloseNow').checked = closeOnly;
    document.getElementById('caseCloseNow').disabled = closeOnly;
    document.getElementById('caseResolutionReview').hidden = true;
    document.getElementById('caseResolutionInputs').hidden = false;
    document.getElementById('caseResolutionReviewBtn').hidden = false;
    const closureConfirmation = document.getElementById('caseClosureConfirmation');
    closureConfirmation.hidden = !closeFromSavedResolution || isAdministrativelyClosed;
    document.getElementById('caseClosureActor').textContent = state.currentUserName || 'Current user';
    document.getElementById('caseClosureDate').textContent = formatDateTime(new Date().toISOString());
    document.getElementById('caseClosureConfirm').dataset.referenceNumber = c.reference_number;
    document.getElementById('caseResolutionOutcome').value = latestResolution?.outcome || '';
    document.getElementById('caseResolutionConclusion').value = isResolved ? latestResolution?.conclusion || '' : '';
    document.getElementById('caseSettlementTerms').value = isResolved ? latestResolution?.settlement_terms || '' : '';
    document.getElementById('caseRemainingObligations').value = isResolved ? latestResolution?.remaining_obligations || '' : '';
    document.getElementById('caseFollowUpAt').value = isResolved && latestResolution?.follow_up_at ? String(latestResolution.follow_up_at).replace(' ', 'T').slice(0, 16) : '';
    document.getElementById('caseFindingsReviewed').checked = false;
    document.getElementById('caseResolutionReviewBtn').replaceChildren(document.createTextNode(
        document.getElementById('caseResolutionForm').dataset.closeOnly === 'true'
            ? 'Review resolution'
            : 'Submit Resolution'
    ));

    const nextStep = status === 'PENDING' || status === 'CRITICAL' || status === 'UNDER_REVIEW'
        ? assignedOfficer ? 'Complete triage, then move the case into active handling or dispatch.' : 'Assign an officer and review the report.'
        : status === 'IN_PROGRESS'
            ? assignedUnit ? 'Record field findings after dispatch, or refer the case for mediation.' : 'Assign / dispatch responding personnel.'
            : status === 'DISPATCHED'
                ? findingEntries.length ? 'Review field findings, then continue handling or refer the case.' : 'Record field findings from the responding unit.'
                : status === 'FOR_RESOLUTION'
                    ? data.lupon?.next_hearing ? 'Complete mediation and document its outcome.' : 'Schedule the Lupon hearing, then document its outcome.'
                    : status === 'RESOLVED'
                        ? 'An authorized Captain or administrator can finalize this resolution.'
                        : canReopen ? 'This resolution is finalized. Reopening requires an authorized reason.' : 'This resolution is finalized and read-only.';
    const nextStepElement = document.getElementById('caseNextStep');
    if (nextStepElement) nextStepElement.textContent = nextStep;

    const actions = document.getElementById('caseActionButtons');
    const actionButtons = [];
    if (canAct && !isAdministrativelyClosed) {
        if (!isAdministrativelyClosed) {
            if (['PENDING', 'UNDER_REVIEW', 'CRITICAL'].includes(status)) {
                actionButtons.push(['accept', 'Accept into Blotter', 'btn-action-primary']);
                actionButtons.push(['dispatch', 'Assign / Dispatch', 'btn-action-quiet']);
            } else if (status === 'IN_PROGRESS') {
                actionButtons.push(['dispatch', assignedUnit ? 'Update / Dispatch Unit' : 'Assign / Dispatch', 'btn-action-primary']);
                actionButtons.push(['refer', 'Refer / Escalate', 'btn-action-quiet']);
            } else if (status === 'DISPATCHED') {
                actionButtons.push(['refer', 'Refer / Escalate', 'btn-action-quiet']);
            }
            if (status === 'FOR_RESOLUTION') {
                actionButtons.push(['reschedule_lupon', 'Reschedule Lupon Hearing', 'btn-action-quiet']);
            }
            if (canResolve && status !== 'FOR_RESOLUTION') actionButtons.push(['resolve_case', 'Resolve Case', 'btn-action-primary']);
            if (canResolve && status === 'FOR_RESOLUTION') actionButtons.push(['resolve_case', 'Complete Mediation', 'btn-action-primary']);
        }
        if (canFinalize) actionButtons.push(['close_case', 'Finalize Resolution', 'btn-action-primary']);
    } else if (isAdministrativelyClosed && canReopen) {
        actionButtons.push(['reopen_case', 'Reopen Case', 'btn-action-quiet']);
    }
    actions.innerHTML = actionButtons.map(([action, label, className]) =>
        `<button type="button" class="${className}" data-action="${action}" data-ref="${escapeHtml(c.reference_number)}">${escapeHtml(label)}</button>`
    ).join('');
    document.getElementById('caseActionHint').textContent = !canAct
        ? 'Your account does not have permission to change this case.'
        : !workflowReady ? 'Case details remain viewable. Apply the case workflow database migration to enable findings, resolution, and finalization actions.'
        : isAdministrativelyClosed ? canReopen ? 'Reopening is available only to an authorized Captain or administrator and requires a reason.' : 'This finalized resolution can only be viewed.'
        : isResolved ? canClose ? 'This resolved case can be finalized by an authorized Captain or administrator.' : 'This case is resolved and read-only.'
        : isDispatched ? 'Record findings below, or continue the case workflow.'
        : 'Actions are shown based on the current case status and your role.';

    document.getElementById('caseDetailLoading').hidden = true;
    document.getElementById('caseDetailContent').hidden = false;
    setView('case-detail');
}

async function openCaseDetails(referenceNumber) {
    const loading = document.getElementById('caseDetailLoading');
    const content = document.getElementById('caseDetailContent');
    loading.hidden = false;
    content.hidden = true;
    setView('case-detail');
    try {
        const data = await requestOfficer('get_case_review_details', { reference_number: referenceNumber });
        state.currentCaseReference = referenceNumber;
        renderCaseDetail(data);
    } catch (err) {
        loading.hidden = true;
        state.currentCaseReference = null;
        showNotice(`Unable to load case details: ${err.message}`, true);
        setView('blotter');
    }
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
    const refInput = document.getElementById('dispatchRefInput');
    if (refInput) refInput.value = ref;
    populateDispatchDropdown();
    document.getElementById('dispatchModal')?.showModal();
});

document.addEventListener('click', (e) => {
    if (!e.target.closest('.notif-container')) notifDropdown?.classList.remove('is-active');
});

async function refreshOperationsData() {
    try {
        const data = await requestOfficer();
        state.cases = data.all_cases || [];
        state.triageQueue = data.triage_queue || [];
        state.urgentCases = data.urgent_cases || [];
        state.luponCases = data.lupon_cases || [];
        state.tanods = data.tanods || [];
        state.dispatchQueue = data.dispatch_queue || [];
        state.dispatchMetrics = data.dispatch_metrics || { on_duty: 0, available: 0, assigned: 0, off_duty: 0 };
        state.dispatchTrackingReady = data.dispatch_tracking_ready === true;
        state.criticalCase = data.critical_case;
        state.notifications = data.notifications || [];
        state.currentUserRole = data.officer?.role || '';
        state.currentUserName = data.officer?.full_name || '';

        const mPending = document.getElementById('metricPendingTriage');
        if (mPending) mPending.textContent = data.metrics?.pending_triage ?? 0;
        const mDispatch = document.getElementById('metricActiveDispatched');
        if (mDispatch) mDispatch.textContent = data.metrics?.active_dispatched ?? 0;
        const mInvestigate = document.getElementById('metricUnderInvestigation');
        if (mInvestigate) mInvestigate.textContent = data.metrics?.under_investigation ?? 0;
        const mResolved = document.getElementById('metricResolvedToday');
        if (mResolved) mResolved.textContent = data.metrics?.resolved_today ?? 0;

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

let triageDebounceTimer = null;
async function inspectNarrativeWithAI(narrativeText) {
    const trimmed = String(narrativeText || '').trim();
    const statusContainer = document.getElementById('aiTriageStatus');
    const badgeContainer = document.getElementById('detectedKeywordsContainer');
    const reasoningBox = document.getElementById('aiReasoningBox');
    const scoreBadge = document.getElementById('aiUrgencyScoreBadge');
    const categoryBadge = document.getElementById('aiAssessedCategoryBadge');
    
    const hiddenCategory = document.getElementById('aiGeneratedCategoryInput');
    const hiddenPriority = document.getElementById('aiGeneratedPriorityInput');

    if (!trimmed || trimmed.length < 15) {
        if (badgeContainer) badgeContainer.innerHTML = '<span style="font-size: 11.5px; color: var(--muted);">Type narrative details above to start AI classification...</span>';
        if (reasoningBox) reasoningBox.textContent = 'Waiting for narrative facts to evaluate jurisdiction and emergency level...';
        if (scoreBadge) {
            scoreBadge.textContent = 'Priority: Low (0%)';
            scoreBadge.style.background = '';
            scoreBadge.style.color = '';
        }
        if (categoryBadge) categoryBadge.textContent = 'Category: Pending';
        return;
    }

    if (statusContainer) {
        statusContainer.textContent = 'AI is evaluating narrative...';
        statusContainer.classList.add('pulse');
    }

    try {
        const response = await fetch('api/encode_case.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': (document.querySelector('meta[name="csrf-token"]') || {}).content || ''
            },
            body: JSON.stringify({
                action: 'inspect_triage',
                narrative_description: trimmed
            })
        });

        const result = await response.json();

        if (statusContainer) {
            statusContainer.textContent = 'AI Classification Applied';
            statusContainer.classList.remove('pulse');
        }

        if (!result.success) {
            if (reasoningBox) reasoningBox.textContent = result.error || 'Failed to inspect narrative.';
            return;
        }

        if (result.incident_type) {
            if (hiddenCategory) hiddenCategory.value = result.incident_type;
            if (categoryBadge) categoryBadge.textContent = `Category: ${result.incident_type}`;
        }

        if (result.priority_level) {
            if (hiddenPriority) hiddenPriority.value = result.priority_level;
            const pct = Math.round(Number(result.ai_urgency_score || 0) * 100);
            if (scoreBadge) {
                scoreBadge.textContent = `${result.priority_level} (${pct}%)`;
                if (result.priority_level === 'Critical') {
                    scoreBadge.style.background = 'var(--coral)';
                    scoreBadge.style.color = '#fff';
                } else if (result.priority_level === 'High') {
                    scoreBadge.style.background = '#d97706';
                    scoreBadge.style.color = '#fff';
                } else if (result.priority_level === 'Moderate') {
                    scoreBadge.style.background = '#0284c7';
                    scoreBadge.style.color = '#fff';
                } else {
                    scoreBadge.style.background = '#059669';
                    scoreBadge.style.color = '#fff';
                }
            }
        }

        if (badgeContainer) {
            const keywords = Array.isArray(result.detected_keywords) ? result.detected_keywords : [];
            if (keywords.length === 0) {
                badgeContainer.innerHTML = '<span style="font-size: 11px; color: var(--muted);">No immediate threat indicators identified.</span>';
            } else {
                badgeContainer.innerHTML = keywords.map(kw => `
                    <span class="pill" style="background: rgba(239, 68, 68, 0.2); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.4); font-weight: 700; margin-right: 4px; margin-bottom: 4px;">
                        🔍 ${escapeHtml(kw)}
                    </span>
                `).join('');
            }
        }

        if (reasoningBox) {
            reasoningBox.innerHTML = `
                <div style="font-size: 12.5px; line-height: 1.5; color: var(--ink);">
                    <strong>Evaluation:</strong> ${escapeHtml(result.reasoning || 'Evaluated under Katarungang Pambarangay scope.')}
                    <div style="margin-top: 6px; color: var(--teal); font-weight: 700;">
                        Recommended Action: ${escapeHtml(result.ai_recommendation || 'Proceed with standard desk logging.')}
                    </div>
                </div>
            `;
        }
    } catch (err) {
        if (seq !== aiSeq) return;
        if (statusEl) { statusEl.textContent = 'Rules analysis updated (AI check failed)'; statusEl.classList.remove('pulse'); }
    }
}

document.addEventListener('click', async (e) => {
    const idTrigger = e.target.closest('[data-trigger-review="true"]');
    if (idTrigger) {
        e.preventDefault();
        const ref = idTrigger.dataset.ref;
        if (!ref) return;

        await openCaseDetails(ref);
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
            if (state.currentCaseReference === ref) await openCaseDetails(ref);
        } else if (action === 'reject') {
            document.getElementById('caseReviewModal')?.close();
            await openCaseDetails(ref);
            showNotice('Record findings and a written conclusion before closing this case.');
        } else if (action === 'resolve_case') {
            document.getElementById('caseResolutionForm')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else if (action === 'close_case') {
            const closePanel = document.getElementById('caseClosureConfirmation');
            if (closePanel && !closePanel.hidden) {
                closePanel.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else {
                document.getElementById('caseResolutionForm')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        } else if (action === 'complete_mediation') {
            await openCaseDetails(ref);
            const target = document.getElementById('caseResolutionForm')?.hidden
                ? document.getElementById('caseFindingForm')
                : document.getElementById('caseResolutionForm');
            target?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else if (action === 'dispatch') {
            document.getElementById('caseReviewModal')?.close();
            document.getElementById('dispatchRefInput').value = ref;
            populateDispatchDropdown();
            document.getElementById('dispatchModal').showModal();
        } else if (action === 'refer') {
            document.getElementById('caseReviewModal')?.close();
            document.getElementById('luponRefInput').value = ref;
            document.getElementById('luponActionMode').value = 'refer';
            document.getElementById('luponModalTitle').textContent = `Refer / Escalate Case #${ref}`;
            document.getElementById('luponDestinationFields').style.display = 'block';
            document.getElementById('luponReasonFields').style.display = 'block';
            document.getElementById('luponDestinationSelect').value = 'LUPON';
            document.getElementById('luponEndorseFields').style.display = 'block';
            document.getElementById('luponReasonInput').value = '';
            document.getElementById('luponReasonInput').required = true;
            document.getElementById('luponScheduleInput').value = '';
            document.getElementById('luponMediatorInput').required = true;
            document.getElementById('luponScheduleInput').required = true;
            document.getElementById('luponSubmitBtn').textContent = 'Confirm Referral';
            document.getElementById('luponModal').showModal();
        } else if (action === 'reschedule_lupon') {
            const caseData = state.luponCases.find(item => item.reference_number === ref);
            document.getElementById('luponRefInput').value = ref;
            document.getElementById('luponActionMode').value = 'reschedule';
            document.getElementById('luponModalTitle').textContent = `Reschedule Hearing for #${ref}`;
            document.getElementById('luponDestinationFields').style.display = 'none';
            document.getElementById('luponReasonFields').style.display = 'none';
            document.getElementById('luponReasonInput').required = false;
            document.getElementById('luponEndorseFields').style.display = 'block';
            document.getElementById('luponMediatorInput').value = caseData?.assigned_officer || 'Barangay Lupon Panel';
            document.getElementById('luponScheduleInput').value = caseData?.next_hearing ? String(caseData.next_hearing).replace(' ', 'T').slice(0, 16) : '';
            document.getElementById('luponMediatorInput').required = true;
            document.getElementById('luponScheduleInput').required = true;
            document.getElementById('luponSubmitBtn').textContent = 'Save New Hearing Schedule';
            document.getElementById('luponModal').showModal();
        } else if (action === 'reopen_case') {
            const reason = prompt(`Enter the authorized reason for reopening case #${ref}:`);
            if (reason === null) return;
            const res = await requestOfficer('reopen_case', { reference_number: ref, reason });
            showNotice(res.message);
            await refreshOperationsData();
            if (state.currentCaseReference === ref) await openCaseDetails(ref);
        }
    } catch (err) {
        showNotice(err.message, true);
    }
});

document.getElementById('criticalDispatchBtn')?.addEventListener('click', (e) => {
    const ref = e.currentTarget.getAttribute('data-ref');
    if (ref) {
        document.getElementById('dispatchRefInput').value = ref;
        populateDispatchDropdown();
        document.getElementById('dispatchModal').showModal();
    }
});

document.getElementById('dispatchForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.currentTarget;
    const payload = {
        reference_number: document.getElementById('dispatchRefInput').value,
        responder_ids: [...document.getElementById('dispatchUnitSelect').selectedOptions].map(option => Number(option.value)),
        dispatch_notes: document.getElementById('dispatchNotesTextarea').value.trim()
    };
    try {
        const res = await requestOfficer('dispatch_tanod', payload);
        document.getElementById('dispatchModal').close();
        showNotice(res.message);
        await refreshOperationsData();
        if (state.currentCaseReference === payload.reference_number) await openCaseDetails(payload.reference_number);
    } catch (err) {
        showNotice(err.message, true);
    }
});

document.getElementById('addResponderBtn')?.addEventListener('click', () => {
    document.getElementById('responderForm').reset();
    document.getElementById('responderIdInput').value = '';
    document.getElementById('responderModalTitle').textContent = 'Add Responder';
    document.getElementById('responderModal').showModal();
});

document.getElementById('tanodRosterContainer')?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-edit-responder]');
    if (!button) return;
    const responder = state.tanods.find(item => String(item.id) === button.dataset.editResponder);
    if (!responder) return;
    document.getElementById('responderForm').reset();
    document.getElementById('responderIdInput').value = responder.id;
    document.getElementById('responderNameInput').value = responder.name || '';
    document.getElementById('responderStationInput').value = responder.station || '';
    document.getElementById('responderContactInput').value = responder.contact_number || '';
    document.getElementById('responderModalTitle').textContent = 'Edit Responder Profile';
    document.getElementById('responderModal').showModal();
});

document.getElementById('tanodRosterContainer')?.addEventListener('change', async (event) => {
    const select = event.target.closest('[data-tanod-availability]');
    if (!select) return;
    try {
        const result = await requestOfficer('update_responder_availability', {
            tanod_id: Number(select.dataset.tanodAvailability),
            status: select.value
        });
        showNotice(result.message);
        await refreshOperationsData();
    } catch (error) {
        showNotice(error.message, true);
        await refreshOperationsData();
    }
});

document.getElementById('responderForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const payload = Object.fromEntries(new FormData(form).entries());
    const tanodId = Number(payload.tanod_id || 0);
    delete payload.tanod_id;
    try {
        const result = await requestOfficer(tanodId ? 'update_responder' : 'create_responder', {
            ...payload,
            ...(tanodId ? { tanod_id: tanodId } : {})
        });
        document.getElementById('responderModal').close();
        showNotice(result.message);
        await refreshOperationsData();
    } catch (error) {
        showNotice(error.message, true);
    }
});

document.getElementById('dispatchQueueContainer')?.addEventListener('click', async (event) => {
    const assignButton = event.target.closest('[data-assign-case]');
    const viewButton = event.target.closest('[data-view-case]');
    const stepButton = event.target.closest('[data-response-step]');
    if (assignButton) {
        const ref = assignButton.dataset.assignCase;
        document.getElementById('dispatchRefInput').value = ref;
        populateDispatchDropdown();
        document.getElementById('dispatchModal').showModal();
        return;
    }
    if (viewButton) {
        await openCaseDetails(viewButton.dataset.viewCase);
        return;
    }
    if (stepButton) {
        try {
            const result = await requestOfficer('dispatch_response_update', {
                reference_number: stepButton.dataset.ref,
                step: stepButton.dataset.responseStep
            });
            showNotice(result.message);
            await refreshOperationsData();
        } catch (error) {
            showNotice(error.message, true);
        }
    }
});

document.getElementById('dispatchQueueContainer')?.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-response-form]');
    if (!form) return;
    event.preventDefault();
    const data = new FormData(form);
    try {
        const result = await requestOfficer('dispatch_response_update', {
            reference_number: form.dataset.responseForm,
            step: 'SUBMIT',
            findings: String(data.get('findings') || '').trim(),
            action_taken: String(data.get('action_taken') || '').trim()
        });
        showNotice(result.message);
        await refreshOperationsData();
    } catch (error) {
        showNotice(error.message, true);
    }
});

document.querySelector('[data-case-back]')?.addEventListener('click', () => {
    state.currentCaseReference = null;
    setView('blotter');
});

document.getElementById('caseFindingForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.currentTarget;
    const referenceNumber = form.dataset.referenceNumber;
    if (!referenceNumber) return;

    try {
        const result = await requestOfficer('record_case_findings', {
            reference_number: referenceNumber,
            observations: document.getElementById('caseFindingObservations').value.trim(),
            persons_contacted: document.getElementById('caseFindingPersons').value.trim(),
            action_taken: document.getElementById('caseFindingAction').value.trim(),
            findings: document.getElementById('caseFindingSummary').value.trim(),
            recommended_next_steps: document.getElementById('caseFindingNextSteps').value.trim()
        });
        form.reset();
        showNotice(result.message || 'Field findings recorded.');
        await openCaseDetails(referenceNumber);
    } catch (error) {
        showNotice(error.message, true);
    }
});

document.getElementById('caseResolutionForm')?.addEventListener('submit', (e) => {
    e.preventDefault();
    const form = e.currentTarget;
    const formData = new FormData(form);
    const closeRequested = form.dataset.closeOnly === 'true' || formData.get('close_case') === '1';
    const payload = {
        reference_number: form.dataset.referenceNumber,
        outcome: formData.get('outcome'),
        conclusion: String(formData.get('conclusion') || '').trim(),
        settlement_terms: String(formData.get('settlement_terms') || '').trim(),
        remaining_obligations: String(formData.get('remaining_obligations') || '').trim(),
        follow_up_at: formData.get('follow_up_at') || '',
        findings_reviewed: formData.get('findings_reviewed') === '1' ? 1 : 0,
        close_case: closeRequested ? 1 : 0
    };
    if (payload.outcome === 'SETTLED_THROUGH_MEDIATION' && !payload.settlement_terms) {
        showNotice('Record the agreed settlement terms before finalizing mediation.', true);
        document.getElementById('caseSettlementTerms').focus();
        return;
    }
    if (payload.conclusion.length < 30) {
        showNotice('Enter a written conclusion of at least 30 characters.', true);
        document.getElementById('caseResolutionConclusion').focus();
        return;
    }
    const outcomeLabel = caseResolutionOutcomeLabel(payload.outcome);
    const closeLabel = 'Resolved';
    const findingsSummary = state.currentCaseFindings.map(item =>
        `Recorded ${formatDateTime(item.created_at)} by ${item.submitted_by_name || 'System'}: `
        + `Observations: ${item.observations}; Persons contacted: ${item.persons_contacted || 'Not recorded'}; `
        + `Action taken: ${item.action_taken}; Findings: ${item.findings}; `
        + `Recommended next steps: ${item.recommended_next_steps || 'Not recorded'}`
    ).join('\n\n');
    if (!closeRequested) {
        saveCaseResolution(payload);
        return;
    }

    state.pendingResolutionPayload = payload;
    document.getElementById('caseResolutionReviewSummary').innerHTML = `
        <dl class="case-resolution-summary">
            <dt>Outcome</dt><dd>${escapeHtml(outcomeLabel)}</dd>
            <dt>Findings reviewed</dt><dd>${escapeHtml(findingsSummary || 'No recorded findings')}</dd>
            <dt>Conclusion</dt><dd>${escapeHtml(payload.conclusion)}</dd>
            ${payload.settlement_terms ? `<dt>Settlement terms</dt><dd>${escapeHtml(payload.settlement_terms)}</dd>` : ''}
            ${payload.remaining_obligations ? `<dt>Remaining obligations</dt><dd>${escapeHtml(payload.remaining_obligations)}</dd>` : ''}
            ${payload.follow_up_at ? `<dt>Follow-up</dt><dd>${escapeHtml(formatDateTime(payload.follow_up_at))}</dd>` : ''}
            <dt>Final status</dt><dd>${escapeHtml(closeLabel)}</dd>
            ${closeRequested ? `<dt>Responsible officer</dt><dd>${escapeHtml(state.currentUserName || 'Current user')}</dd><dt>Finalized</dt><dd>${escapeHtml(formatDateTime(new Date().toISOString()))} (server timestamp saved on confirmation)</dd>` : ''}
        </dl>`;
    document.getElementById('caseResolutionInputs').hidden = true;
    document.getElementById('caseResolutionReviewBtn').hidden = true;
    document.getElementById('caseResolutionReview').hidden = false;
});

async function saveCaseResolution(payload) {
    try {
        const action = payload.close_case ? 'close_case' : 'resolve_case';
        const result = await requestOfficer(action, payload);
        state.pendingResolutionPayload = null;
        showNotice(result.message);
        await refreshOperationsData();
        await openCaseDetails(payload.reference_number);
    } catch (error) {
        showNotice(error.message, true);
    }
}

document.getElementById('caseResolutionEdit')?.addEventListener('click', () => {
    state.pendingResolutionPayload = null;
    document.getElementById('caseResolutionInputs').hidden = false;
    document.getElementById('caseResolutionReview').hidden = true;
    document.getElementById('caseResolutionReviewBtn').hidden = false;
});

document.getElementById('caseResolutionConfirm')?.addEventListener('click', async () => {
    const payload = state.pendingResolutionPayload;
    if (!payload) return;
    await saveCaseResolution(payload);
});

document.getElementById('caseClosureBack')?.addEventListener('click', () => {
    document.getElementById('caseClosureConfirmation').hidden = true;
});

document.getElementById('caseClosureConfirm')?.addEventListener('click', async (e) => {
    const referenceNumber = e.currentTarget.dataset.referenceNumber;
    if (!referenceNumber) return;
    e.currentTarget.disabled = true;
    try {
        const result = await requestOfficer('close_case', { reference_number: referenceNumber });
        showNotice(result.message || 'Resolution finalized.');
        await refreshOperationsData();
        await openCaseDetails(referenceNumber);
    } catch (error) {
        showNotice(error.message, true);
    } finally {
        e.currentTarget.disabled = false;
    }
});

document.getElementById('luponForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const mode = document.getElementById('luponActionMode').value;
    const ref = document.getElementById('luponRefInput').value;
    const formData = new FormData(e.currentTarget);
    try {
        if (mode === 'refer') {
            const destination = formData.get('destination');
            const actionPayload = {
                reference_number: ref,
                destination,
                reason: formData.get('reason')
            };
            if (destination === 'LUPON') {
                actionPayload.mediator = formData.get('mediator');
                actionPayload.schedule = formData.get('schedule');
            }
            const res = await requestOfficer('refer_case', actionPayload);
            showNotice(res.message);
        } else if (mode === 'reschedule') {
            const res = await requestOfficer('reschedule_lupon', {
                reference_number: ref,
                mediator: formData.get('mediator'),
                schedule: formData.get('schedule')
            });
            showNotice(res.message);
        }
        document.getElementById('luponModal').close();
        await refreshOperationsData();
        if (state.currentCaseReference === ref) await openCaseDetails(ref);
    } catch (err) {
        showNotice(err.message, true);
    }
});

document.getElementById('luponDestinationSelect')?.addEventListener('change', (e) => {
    const isLupon = e.currentTarget.value === 'LUPON';
    document.getElementById('luponEndorseFields').style.display = isLupon ? 'block' : 'none';
    document.getElementById('luponMediatorInput').required = isLupon;
    document.getElementById('luponScheduleInput').required = isLupon;
    document.getElementById('luponReasonInput').required = true;
});

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
        renderBlotterTable();
    });
});

document.querySelectorAll('[data-blotter-summary-filter]').forEach(button => {
    button.addEventListener('click', () => {
        state.filter = button.dataset.blotterSummaryFilter;
        renderBlotterTable();
    });
});

document.getElementById('blotterSearchInput')?.addEventListener('input', renderBlotterTable);
document.querySelector('[data-refresh-cases]')?.addEventListener('click', refreshOperationsData);
document.querySelectorAll('[data-close-dialog]').forEach(btn => btn.addEventListener('click', () => btn.closest('dialog').close()));

navLinks.forEach(link => link.addEventListener('click', () => setView(link.dataset.view)));

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