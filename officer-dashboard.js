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

// Expose showNotice to global window scope for officer-ai.js
window.showNotice = showNotice;

async function requestOfficer(action, payload = {}, options = {}) {
    const headers = { 
        'X-CSRF-Token': (document.querySelector('meta[name="csrf-token"]') || {}).content || ''
    };
    if (!options.formData) {
        headers['Content-Type'] = 'application/json';
    }
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

// -------------------------------------------------------------
// TAB RENDERERS
// -------------------------------------------------------------
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
            <div class="queue-meta-block" style="flex: 1; max-width: 65%;">
                <div class="queue-header-tag">
                    <!-- ONLY THE CASE IDENTIFIER IS CLICKABLE TO OPEN FULL REVIEW POPUP -->
                    <span class="queue-id" 
                          data-trigger-review="true" 
                          data-ref="${escapeHtml(item.reference_number)}" 
                          title="Click reference code to open full case details" 
                          style="cursor: pointer; text-decoration: underline; color: var(--teal); font-weight: 800;">
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
                <p style="margin: 6px 0 0; font-size: 11.5px; color: var(--muted); line-height: 1.4;">
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
                    <span class="queue-id" data-trigger-review="true" data-ref="${escapeHtml(c.reference_number)}" style="cursor: pointer; text-decoration: underline; color: var(--teal); font-weight: 800;">
                        ${escapeHtml(c.reference_number)}
                    </span>
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
                    <span class="queue-id" data-trigger-review="true" data-ref="${escapeHtml(c.reference_number)}" style="cursor: pointer; text-decoration: underline; color: var(--teal); font-weight: 800;">
                        ${escapeHtml(c.reference_number)}
                    </span>
                    ${statusPill(c.status)}
                </div>
                <h3 class="queue-title" style="cursor: default;">${escapeHtml(c.incident_type)}</h3>
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

function renderTanodBoard() {
    const container = document.getElementById('tanodRosterContainer');
    const tanods = state.tanods;
    const available = tanods.filter(t => t.status === 'AVAILABLE').length;
    const countEl = document.getElementById('activeTanodCount');
    if (countEl) countEl.textContent = `${available} Available`;

    if (!container) return;
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
    if (!tbody) return;

    tbody.innerHTML = cases.map(c => `
        <tr>
            <td>
                <strong class="queue-id" data-trigger-review="true" data-ref="${escapeHtml(c.reference_number)}" style="font-family: monospace; cursor: pointer; text-decoration: underline; color: var(--teal);">
                    ${escapeHtml(c.reference_number)}
                </strong>
            </td>
            <td><strong>${escapeHtml(c.complainant_name)}</strong></td>
            <td>${escapeHtml(c.incident_type)}</td>
            <td>${escapeHtml(c.purok)}</td>
            <td>${priorityPill(c.priority_level)}</td>
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
    notifDropdown?.classList.remove('is-active');
    const refInput = document.getElementById('dispatchRefInput');
    if (refInput) refInput.value = ref;
    document.getElementById('dispatchModal')?.showModal();
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
                const head = document.getElementById('criticalBannerHeading');
                if (head) head.textContent = `Critical Case (${state.criticalCase.reference_number}) Needs Attention: ${state.criticalCase.incident_type}`;
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
            const nameEl = document.getElementById('officerDisplayName');
            if (nameEl) nameEl.textContent = officerName;

            const settingsName = document.getElementById('settingsName');
            if (settingsName) settingsName.value = officerName;
            const settingsProfileName = document.getElementById('settingsProfileName');
            if (settingsProfileName) settingsProfileName.textContent = officerName;

            const settingsContact = document.getElementById('settingsContact');
            if (settingsContact) settingsContact.value = data.officer.contact_number || '';

            const initials = officerName.split(/\s+/).filter(Boolean).slice(0, 2).map(p => p[0].toUpperCase()).join('') || 'OF';
            const initialsEl = document.getElementById('officerInitials');
            if (initialsEl) initialsEl.textContent = initials;
            const cardAvatar = document.getElementById('settingsAvatarInitials');
            if (cardAvatar) cardAvatar.textContent = initials;

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

// Expose refresh to window so officer-ai.js can trigger re-renders after override
window.refreshOperationsData = refreshOperationsData;

// Background polling every 10 seconds
setInterval(refreshOperationsData, 10000);

// -------------------------------------------------------------
// REAL-TIME AI NARRATIVE INSPECTION (WALK-IN MODAL)
// -------------------------------------------------------------
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

        // 1. Assign AI-determined Category to Hidden Input & UI Badge
        if (result.incident_type) {
            if (hiddenCategory) hiddenCategory.value = result.incident_type;
            if (categoryBadge) categoryBadge.textContent = `Category: ${result.incident_type}`;
        }

        // 2. Assign AI-determined Priority to Hidden Input & Score Badge
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

        // 3. Render Threat Keywords
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

        // 4. Render Step-by-Step AI Reasoning & Action Guidance
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
        if (statusContainer) statusContainer.textContent = 'AI inspection failed.';
        if (reasoningBox) reasoningBox.textContent = err.message;
    }
}

// -------------------------------------------------------------
// EVENT DELEGATION: POPUP REVIEW (STRICTLY ON REFERENCE ID)
// -------------------------------------------------------------
document.addEventListener('click', async (e) => {
    // Check if clicked element is the reference code trigger
    const idTrigger = e.target.closest('[data-trigger-review="true"]');
    if (idTrigger) {
        e.preventDefault();
        const ref = idTrigger.dataset.ref;
        if (!ref) return;

        try {
            const res = await requestOfficer('get_case_review_details', { reference_number: ref });
            if (!res.success) return showNotice(res.message, true);

            const c = res.case;
            const ai = res.ai_analysis || {};
            const duplicates = res.duplicates || [];
            const history = res.history_matches || [];

            // 1. Basic Resident & Case Info
            document.getElementById('modalReviewRef').textContent = c.reference_number;
            document.getElementById('modalReviewCategory').textContent = c.incident_type;
            document.getElementById('modalComplainantName').textContent = c.complainant_name || 'Walk-in Resident';
            document.getElementById('modalComplainantPhone').textContent = c.complainant_phone || 'None provided';
            document.getElementById('modalLocationPurok').textContent = c.purok || 'Barangay Duale';
            document.getElementById('modalNarrativeText').textContent = c.narrative_description || 'No narrative facts recorded.';

            // Priority Badge
            const pBadge = document.getElementById('modalPriorityBadge');
            pBadge.textContent = c.priority_level;
            pBadge.className = 'pill ' + (c.priority_level === 'Critical' ? 'pill-rejected' : c.priority_level === 'High' ? 'pill-pending' : 'pill-active');

            // 2. Multi-Agent System: Statutory Jurisdiction (Agent 1)
            const routingTitle = document.getElementById('modalLegalRoutingTitle');
            const routingDesc = document.getElementById('modalLegalRoutingDesc');
            const routingBanner = document.getElementById('modalLegalRoutingBanner');
            const vawcPill = document.getElementById('modalVawcStatusPill');

            routingTitle.textContent = ai.legal_routing || 'Standard Desk Blotter Recording';
            routingDesc.textContent = ai.legal_routing_desc || ai.ai_recommendation || 'Proceed with regular desk intake.';

            if (ai.vawc_minor_flag) {
                routingBanner.style.background = 'rgba(239, 68, 68, 0.15)';
                routingBanner.style.borderColor = 'var(--coral)';
                routingTitle.style.color = 'var(--coral)';
                vawcPill.textContent = '🛑 RA 9262 RESTRICTION';
                vawcPill.className = 'pill pill-rejected';
            } else {
                routingBanner.style.background = 'rgba(25, 118, 108, 0.1)';
                routingBanner.style.borderColor = 'var(--teal)';
                routingTitle.style.color = 'var(--teal)';
                vawcPill.textContent = 'KP Competent';
                vawcPill.className = 'pill pill-active';
            }

            // 3. Multi-Agent System: Safety Entities & Beat (Agent 2)
            const wpEl = document.getElementById('modalWeaponFlag');
            wpEl.textContent = ai.weapon_flag !== 'NONE' ? `⚠️ ${ai.weapon_flag}` : 'NONE DETECTED';
            wpEl.style.color = ai.weapon_flag !== 'NONE' ? 'var(--coral)' : 'var(--ink)';

            document.getElementById('modalMedicalFlag').textContent = ai.medical_flag || 'No acute trauma mentioned.';
            document.getElementById('modalSubstanceFlag').textContent = ai.substance_flag || 'No intoxication reported.';
            document.getElementById('modalBeatRecommendation').textContent = `${c.purok} Sector Patrol Beat`;

            // Reasoning & Keywords
            document.getElementById('modalAiReasoning').textContent = ai.reasoning || 'Triage evaluation completed.';
            const kwContainer = document.getElementById('modalDetectedKeywords');
            const kws = ai.detected_keywords || [];
            kwContainer.innerHTML = kws.map(kw => `
                <span class="pill" style="background: rgba(239, 68, 68, 0.2); color: #fca5a5; font-weight: 700;">
                    🔍 ${escapeHtml(kw)}
                </span>
            `).join('');

            // 4. Multi-Agent System: Duplicate Cluster Sentinel (Agent 3)
            const dupContainer = document.getElementById('modalDuplicateBox');
            if (duplicates.length === 0) {
                dupContainer.innerHTML = '<span style="color: var(--muted);">✓ No recent cluster or duplicate in this Purok within 2 hours.</span>';
            } else {
                dupContainer.innerHTML = duplicates.map(d => `
                    <div style="border-left: 2px solid var(--gold); padding-left: 6px; margin-bottom: 2px;">
                        <strong style="color: var(--gold);">${escapeHtml(d.reference_number)}</strong>: ${escapeHtml(d.incident_type)} (${timeAgo(d.created_at)})
                    </div>
                `).join('');
            }

            // 5. Multi-Agent System: Recidivism Pattern Graph (Agent 4)
            const histContainer = document.getElementById('modalHistoryList');
            if (history.length === 0) {
                histContainer.innerHTML = '<span style="color: var(--muted);">✓ No previous blotters on record for this complainant.</span>';
            } else {
                histContainer.innerHTML = history.map(h => `
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--line); padding-bottom: 2px;">
                        <span>${escapeHtml(h.reference_number)} • ${escapeHtml(h.incident_type)}</span>
                        <span class="pill pill-pending">${escapeHtml(h.status)}</span>
                    </div>
                `).join('');
            }

            // Bind Action Buttons inside the review modal
            ['modalBtnAccept', 'modalBtnDispatch', 'modalBtnLupon', 'modalBtnReject'].forEach(btnId => {
                const btn = document.getElementById(btnId);
                if (btn) btn.setAttribute('data-ref', c.reference_number);
            });

            // Prevent mediation endorsement if prohibited by law (e.g., VAWC or Minor)
            const luponBtn = document.getElementById('modalBtnLupon');
            if (luponBtn) {
                luponBtn.disabled = !!ai.vawc_minor_flag;
                luponBtn.title = ai.vawc_minor_flag ? 'Cannot mediate VAWC cases under RA 9262' : 'Endorse to Katarungang Pambarangay';
            }

            document.getElementById('caseReviewModal').showModal();

        } catch (err) {
            showNotice('Failed to open case review: ' + err.message, true);
        }
        return;
    }

    // Check for Walk-In Encode Modal Triggers
    const encodeTrigger = e.target.closest('#openEncodeModalBtn, #sidebarEncodeCaseBtn, [data-open-encode]');
    if (encodeTrigger) {
        e.preventDefault();
        const encodeDialog = document.getElementById('encodeCaseDialog');
        const form = document.getElementById('encodeCaseForm');

        if (form) form.reset();

        const badgeContainer = document.getElementById('detectedKeywordsContainer');
        if (badgeContainer) badgeContainer.innerHTML = '<span style="font-size: 11.5px; color: var(--muted);">Type narrative details above to start AI classification...</span>';
        const reasoningBox = document.getElementById('aiReasoningBox');
        if (reasoningBox) reasoningBox.textContent = 'Waiting for narrative facts to evaluate jurisdiction and emergency level...';
        const scoreBadge = document.getElementById('aiUrgencyScoreBadge');
        if (scoreBadge) {
            scoreBadge.textContent = 'Priority: Low (0%)';
            scoreBadge.style.background = '';
            scoreBadge.style.color = '';
        }
        const categoryBadge = document.getElementById('aiAssessedCategoryBadge');
        if (categoryBadge) categoryBadge.textContent = 'Category: Pending';

        if (encodeDialog && typeof encodeDialog.showModal === 'function') {
            encodeDialog.showModal();
        } else if (encodeDialog) {
            encodeDialog.setAttribute('open', '');
        }
        return;
    }

    // Table Row Actions (Accept, Dispatch, Lupon, Flag)
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
            document.getElementById('dispatchUnitSelect').value = `Tanod Patrol (${tanod})`;
            setView('blotter');
            showNotice(`Select a case to assign ${tanod}`);
        }
    } catch (err) {
        showNotice(err.message, true);
    }
});

// Banner quick dispatch button
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

// Encode Case Submission
document.getElementById('encodeCaseForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.currentTarget;
    const submitBtn = form.querySelector('button[type="submit"]');

    if (submitBtn) submitBtn.disabled = true;

    try {
        const formData = new FormData(form);
        const narrative = (formData.get('narrative_description') || '').trim();

        if (!narrative) {
            showNotice('Narrative description is required.', true);
            if (submitBtn) submitBtn.disabled = false;
            return;
        }

        const payload = {
            action: 'encode_case',
            complainant_name: (formData.get('complainant_name') || 'Walk-in Resident').trim(),
            complainant_phone: (formData.get('complainant_phone') || 'N/A').trim(),
            incident_type: (formData.get('incident_type') || 'General Incident').trim(),
            purok: (formData.get('purok') || 'Purok 1').trim(),
            street_address: (formData.get('street_address') || '').trim(),
            narrative_description: narrative,
            priority_level: formData.get('priority_level') || 'Low'
        };

        const res = await requestOfficer('encode_case', payload);
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

// Real-Time Debounce for Narrative Textarea in Encode Form
document.addEventListener('input', (e) => {
    if (e.target && (e.target.matches('#encodeCaseForm textarea[name="narrative_description"]') || e.target.id === 'narrativeDescriptionInput')) {
        clearTimeout(triageDebounceTimer);
        triageDebounceTimer = setTimeout(() => {
            inspectNarrativeWithAI(e.target.value);
        }, 600);
    }
});

// Profile and Password Form Submissions
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

// Blotter filters & search
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

// Logout Handler
document.getElementById('logoutBtn')?.addEventListener('click', async () => {
    try { 
        await fetch('api/logout.php', { method: 'POST', credentials: 'same-origin' }); 
    } catch {}
    sessionStorage.clear();
    localStorage.removeItem('currentUser');
    localStorage.removeItem('authToken');
    window.location.replace('index.html');
});

// Initialization & Route Execution
const initialView = location.hash.slice(1);
if (initialView && document.getElementById(`view-${initialView}`)) {
    setView(initialView);
}
refreshOperationsData();