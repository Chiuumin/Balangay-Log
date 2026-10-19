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

const formatDate = (val) => (val && val !== '—') ? new Date(String(val).replace(' ', 'T')).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '—';
const formatDateTime = (val) => (val && val !== '—') ? new Date(String(val).replace(' ', 'T')).toLocaleString() : '—';

function statusPill(status) {
    const s = String(status || '').toUpperCase().trim();
    if (s === 'CLOSED' || s === 'RESOLVED') {
        return `<span class="pill pill-active">Resolved</span>`;
    }
    if (s === 'DISPATCHED' || s === 'UNIT DISPATCHED') {
        return `<span class="pill pill-blue">Dispatched</span>`;
    }
    if (s === 'IN_PROGRESS' || s === 'IN PROGRESS') {
        return `<span class="pill pill-blue">In Progress</span>`;
    }
    if (s === 'FOR_RESOLUTION' || s === 'LUPON') {
        return `<span class="pill pill-pending">Awaiting Resolution (Lupon)</span>`;
    }
    if (s === 'PENDING' || s === 'UNDER_REVIEW' || s === 'UNDER REVIEW' || !s) {
        return `<span class="pill pill-pending">Pending Triage</span>`;
    }
    return `<span class="pill pill-pending">${escapeHtml(status)}</span>`;
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
            if (state.filter === 'UNDER_REVIEW') {
                return st === 'PENDING' || st === 'UNDER_REVIEW' || st === 'UNDER REVIEW';
            }
            if (state.filter === 'IN_PROGRESS') {
                return st === 'IN_PROGRESS' || st === 'IN PROGRESS' || st === 'DISPATCHED' || st === 'UNIT DISPATCHED' || st === 'FOR_RESOLUTION';
            }
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

// AI Narrative Inspection Engine matching Officer Console
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
        if (statusContainer) statusContainer.textContent = 'AI inspection failed.';
        if (reasoningBox) reasoningBox.textContent = err.message;
    }
}

document.addEventListener('input', (e) => {
    if (e.target && (e.target.matches('#incidentForm textarea[name="narrative"]') || e.target.id === 'narrativeDescriptionInput')) {
        clearTimeout(triageDebounceTimer);
        triageDebounceTimer = setTimeout(() => {
            inspectNarrativeWithAI(e.target.value);
        }, 600);
    }
});

document.getElementById('incidentForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const submitBtn = e.currentTarget.querySelector('button[type="submit"]');
    if (submitBtn.disabled) return;

    submitBtn.disabled = true;
    submitBtn.textContent = 'Filing Incident...';

    const form = e.currentTarget;
    const formData = new FormData(form);
    
    const locationParts = [formData.get('street_name')].filter(Boolean).join(', ');
    const narrativeText = formData.get('narrative');
    const fullNarrative = locationParts ? `${narrativeText}\n[Landmark/Street: ${locationParts}]` : narrativeText;

    const payload = {
        action: 'incident_create',
        complainant_name: formData.get('complainant_name'),
        contact_number: formData.get('contact_number'),
        category: document.getElementById('aiGeneratedCategoryInput')?.value || 'General Incident',
        purok: formData.get('purok'),
        street_name: formData.get('street_name'),
        narrative: fullNarrative,
        priority: document.getElementById('aiGeneratedPriorityInput')?.value || 'High'
    };

    try {
        const res = await requestResident('incident_create', payload);
        document.getElementById('reportDialog').close();
        showNotice(res.message || 'Incident case encoded and filed successfully.');
        await refreshData();
    } catch (err) {
        showNotice(err.message, true);
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Encode & Log Case';
    }
});

async function openReportDetails(reportId) {
    const report = state.reports.find(r => String(r.id) === String(reportId));
    if (!report) return;

    const ref = report.reference_number || 'TRK-' + report.id;
    document.getElementById('detailDialogTitle').textContent = `Tracking #${ref}`;

    const stepReported = document.getElementById('step-reported');
    const stepReview = document.getElementById('step-review');
    const stepAction = document.getElementById('step-action');
    const stepResolved = document.getElementById('step-resolved');
    const stepInProgress = document.getElementById('step-in-progress');
    const stepAwaitingResolution = document.getElementById('step-awaiting-resolution');
    const stepFindings = document.getElementById('step-findings');
    const timelineEl = document.getElementById('trackingTimeline');

    let milestones = [];
    let endorsements = [];
    let dispatchLogs = [];
    let findings = [];
    let resolutions = [];
    let closures = [];
    let caseData = report;

    try {
        const res = await requestResident('get_milestones', { reference_number: ref });
        milestones = res.milestones || [];
        endorsements = res.endorsements || [];
        dispatchLogs = res.dispatch_logs || [];
        findings = res.findings || [];
        resolutions = res.resolutions || [];
        closures = res.closures || [];
        if (res.case) caseData = { ...report, ...res.case };
    } catch (err) {
        console.error('Milestone fetch error:', err);
    }

    const isPnpEscalated = endorsements.some(e => e.type === 'PNP_TRANSMITTAL') || 
                           milestones.some(m => String(m.status_snapshot).includes('ESCALATED') || String(m.action_note).includes('PNP'));
    
    const currentStatus = String(caseData.status || report.status || 'PENDING').toUpperCase().trim();
    const steps = [stepReported, stepReview, stepAction, stepResolved, stepInProgress, stepAwaitingResolution, stepFindings];
    steps.forEach(s => {
        if (s) s.className = 'stepper-step';
    });
    if (stepReported) stepReported.classList.add('is-done');

    const isPending = ['PENDING', 'UNDER_REVIEW', 'UNDER REVIEW'].includes(currentStatus);
    const isInProgress = ['IN_PROGRESS', 'IN PROGRESS'].includes(currentStatus);
    const isDispatched = ['DISPATCHED', 'UNIT DISPATCHED', 'ESCALATED_PNP'].includes(currentStatus);
    const isLupon = ['FOR_RESOLUTION', 'LUPON'].includes(currentStatus);
    const isResolved = currentStatus === 'RESOLVED' || currentStatus === 'CLOSED';
    const isConcluded = isResolved;
    const hasFindings = findings.length > 0;
    const isPastTriage = !isPending;
    const hasDispatch = isDispatched
        || dispatchLogs.length > 0
        || milestones.some(m => ['DISPATCHED', 'UNIT DISPATCHED', 'ESCALATED_PNP'].includes(String(m.status_snapshot || '').toUpperCase()));
    const isPastProgress = hasDispatch || isLupon || isConcluded;
    const hasMediation = isLupon
        || milestones.some(m => ['FOR_RESOLUTION', 'LUPON'].includes(String(m.status_snapshot || '').toUpperCase()))
        || endorsements.some(item => `${item.type || ''} ${item.status || ''}`.toUpperCase().includes('LUPON'))
        || resolutions.some(item => String(item.outcome || '').toUpperCase() === 'SETTLED_THROUGH_MEDIATION');

    const setStepState = (step, reached, active = false) => {
        if (!step) return;
        if (reached) step.classList.add('is-done');
        if (active) {
            step.classList.remove('is-done');
            step.classList.add('is-active');
        }
    };
    setStepState(stepReview, isPastTriage, isPending);
    setStepState(stepInProgress, isInProgress || isPastProgress, isInProgress);
    setStepState(stepAction, hasDispatch, isDispatched);
    setStepState(stepAwaitingResolution, hasMediation, isLupon);
    setStepState(stepFindings, hasFindings, hasFindings && !isConcluded);
    setStepState(stepResolved, isConcluded);

    const pnpBadge = isPnpEscalated ? `<span class="pill pill-critical" style="margin-left: 6px;">Escalated to PNP</span>` : '';
    const fields = [
        ['Incident Type', escapeHtml(caseData.incident_type) + pnpBadge],
        ['Purok / Location', escapeHtml(caseData.purok || '—')],
        ['Date Filed', formatDate(caseData.incident_datetime || caseData.created_at)],
        ['Priority Level', caseData.priority_level === 'High' || caseData.priority_level === 'Urgent' 
            ? `<span style="color: #d15c49; font-weight: 800;">${escapeHtml(caseData.priority_level)}</span>` 
            : escapeHtml(caseData.priority_level || 'Moderate')],
        ['Current Status', statusPill(currentStatus)],
        ['Recommendation', escapeHtml(caseData.ai_recommendation || 'Under administrative assessment.')],
        ['Narrative Notes', escapeHtml(caseData.narrative_description || 'No description provided.')]
    ];

    document.getElementById('reportDetailContent').innerHTML = fields.map(([label, value]) => `
        <div class="detail-field ${label === 'Narrative Notes' || label === 'Recommendation' ? 'detail-field--wide' : ''}">
            <span>${label}</span>
            <strong>${value}</strong>
        </div>
    `).join('');

    const findMilestone = (statuses) => milestones.find(m => statuses.includes(String(m.status_snapshot).toUpperCase()));

    const allStages = [
        {
            id: 'stage-reported',
            title: '1. Reported',
            badge: 'Report Submitted',
            badgeClass: 'pill-active',
            date: formatDate(caseData.created_at),
            isReached: true,
            residentMessage: 'Your incident report has been registered in the system and queued for frontline verification.',
            details: [
                ['Tracking Reference', ref],
                ['Reporting Method', 'Resident Online Portal'],
                ['Initial Statement', caseData.narrative_description || '—']
            ]
        },
        {
            id: 'stage-review',
            title: '2. Desk Review',
            badge: isPastTriage ? 'Reviewed' : 'Pending Triage',
            badgeClass: isPastTriage ? 'pill-active' : 'pill-pending',
            date: formatDate(findMilestone(['IN_PROGRESS', 'DESK_REVIEW'])?.created_at || caseData.created_at),
            isReached: !isPending,
            residentMessage: isPastTriage
                ? 'Report screened and verified by barangay desk. Prioritized for action.'
                : 'Awaiting desk officer assessment and priority confirmation.',
            details: [
                ['Desk Assessment', 'Verified under Barangay Jurisdiction'],
                ['Assigned Officer (Transparency)', findMilestone(['IN_PROGRESS', 'DISPATCHED', 'RESOLVED'])?.officer_in_charge || caseData.assigned_officer_name || 'Duty Desk Officer']
            ]
        },
        {
            id: 'stage-dispatch',
            title: '3. Unit Dispatched',
            badge: hasDispatch ? 'Dispatched' : 'Pending Deployment',
            badgeClass: hasDispatch ? 'pill-blue' : 'pill-pending',
            date: findMilestone(['DISPATCHED', 'CRITICAL', 'ESCALATED_PNP'])?.created_at ? formatDate(findMilestone(['DISPATCHED', 'CRITICAL', 'ESCALATED_PNP']).created_at) : '—',
            isReached: hasDispatch,
            residentMessage: hasDispatch
                ? `Response team (${caseData.deployed_unit || 'Barangay Tanod Unit'}) has been deployed to the location.`
                : 'Response unit will be mobilized following desk review clearance.',
            details: [
                ['Deployed Unit', caseData.deployed_unit || 'Tanod Patrol Team'],
                ['Target Area', caseData.purok || '—'],
                ['Action Officer', findMilestone(['DISPATCHED'])?.officer_in_charge || caseData.assigned_officer_name || 'Assigned Lead Officer']
            ]
        },
        {
            id: 'stage-resolved',
            title: '4. Resolved',
            badge: isResolved ? 'Resolved' : (isLupon ? 'Mediation Scheduled' : 'Pending Resolution'),
            badgeClass: isConcluded ? 'pill-active' : 'pill-pending',
            date: (closures[closures.length - 1]?.closed_at || resolutions[resolutions.length - 1]?.resolved_at || findMilestone(['RESOLVED', 'CLOSED'])?.created_at)
                ? formatDate(closures[closures.length - 1]?.closed_at || resolutions[resolutions.length - 1]?.resolved_at || findMilestone(['RESOLVED', 'CLOSED']).created_at)
                : '—',
            isReached: isConcluded,
            residentMessage: isResolved
                ? 'A resolution has been recorded for this case.'
                : (isLupon ? 'Case endorsed to Lupong Tagapamayapa for amicable conciliation hearing.' : 'Final disposition pending on-site report submission.'),
            details: [
                ['Final Disposition', isConcluded ? 'See the resolution details below.' : 'Pending Action'],
                ['Handling Officer', findMilestone(['RESOLVED', 'CLOSED'])?.officer_in_charge || caseData.assigned_officer_name || 'Desk Officer']
            ]
        }
    ];

    const activeStages = allStages.filter(st => st.isReached);

    timelineEl.innerHTML = activeStages.map(st => `
        <details class="milestone-accordion" open style="margin-bottom: 10px; border: 1px solid var(--line); border-radius: 6px; overflow: hidden; background: var(--surface);">
            <summary style="padding: 10px 14px; cursor: pointer; display: flex; align-items: center; justify-content: space-between; font-weight: 700; font-size: 13px; background: rgba(0,0,0,0.02); user-select: none;">
                <span style="display: flex; align-items: center; gap: 8px;">
                    <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--teal, #19766c);"></span>
                    ${st.title}
                </span>
                <span style="display: flex; align-items: center; gap: 8px;">
                    <span class="pill ${st.badgeClass}">${st.badge}</span>
                    <small style="color: var(--muted); font-size: 11px;">${st.date}</small>
                    <span class="chevron" style="font-size: 11px; color: var(--muted);">▼</span>
                </span>
            </summary>
            <div style="padding: 12px 14px; border-top: 1px solid var(--line); font-size: 12.5px; line-height: 1.5;">
                <p style="margin: 0 0 10px; font-weight: 600; color: var(--ink);">${st.residentMessage}</p>
                <div style="display: grid; gap: 6px; background: rgba(0,0,0,0.02); padding: 8px 10px; border-radius: 4px;">
                    ${st.details.map(([lbl, val]) => `
                        <div style="display: flex; justify-content: space-between; gap: 12px; font-size: 11.5px;">
                            <span style="color: var(--muted); font-weight: 600;">${lbl}:</span>
                            <span style="color: var(--ink); text-align: right; font-weight: 500;">${escapeHtml(val)}</span>
                        </div>
                    `).join('')}
                </div>
            </div>
        </details>
    `).join('');

    const outcomeLabel = (outcome) => ({
        RESOLVED_THROUGH_INTERVENTION: 'Resolved Through Intervention',
        SETTLED_THROUGH_MEDIATION: 'Settled Through Mediation',
        REFERRED_TO_ANOTHER_AUTHORITY: 'Referred to Another Authority',
        WITHDRAWN: 'Withdrawn',
        OTHER: 'Other'
    }[String(outcome || '').toUpperCase()] || String(outcome || 'Case update').replaceAll('_', ' '));
    const statusLabel = (status) => ({
        PENDING: 'Pending Triage',
        UNDER_REVIEW: 'Pending Triage',
        IN_PROGRESS: 'In Progress',
        DISPATCHED: 'Dispatched',
        FOR_RESOLUTION: 'Awaiting Resolution',
        RESOLVED: 'Resolved',
        CLOSED: 'Resolved'
    }[String(status || '').toUpperCase()] || String(status || 'Case update').replaceAll('_', ' '));

    const activityEvents = [];
    milestones.forEach(m => {
        const status = String(m.status_snapshot || '').toUpperCase();
        activityEvents.push({
            title: statusLabel(status),
            date: m.created_at,
            actor: m.officer_in_charge || 'Barangay personnel',
            details: [m.action_note, m.deployed_unit ? `Assigned unit: ${m.deployed_unit}` : ''].filter(Boolean)
        });
    });
    dispatchLogs.forEach(log => {
        if (log.dispatched_at) {
            activityEvents.push({
                title: 'Unit Dispatched',
                date: log.dispatched_at,
                actor: log.dispatched_by_name || 'Barangay personnel',
                details: [log.unit ? `Assigned unit: ${log.unit}` : '', log.status ? `Status: ${log.status}` : ''].filter(Boolean)
            });
        }
        if (log.acknowledged_at) {
            activityEvents.push({
                title: 'Dispatch Acknowledged',
                date: log.acknowledged_at,
                actor: log.acknowledged_by_name || 'Barangay personnel',
                details: [log.unit ? `Assigned unit: ${log.unit}` : ''].filter(Boolean)
            });
        }
        if (log.arrived_at) {
            activityEvents.push({
                title: 'Unit Arrival Recorded',
                date: log.arrived_at,
                actor: log.arrived_by_name || 'Barangay personnel',
                details: [log.unit ? `Assigned unit: ${log.unit}` : ''].filter(Boolean)
            });
        }
        if (log.findings || log.action_taken) {
            activityEvents.push({
                title: 'Field Response Submitted',
                date: log.updated_at || log.arrived_at || log.dispatched_at,
                actor: log.updated_by_name || 'Barangay personnel',
                details: [
                    log.unit ? `Assigned unit: ${log.unit}` : '',
                    log.findings ? `Findings: ${log.findings}` : '',
                    log.action_taken ? `Action taken: ${log.action_taken}` : ''
                ].filter(Boolean)
            });
        }
    });
    endorsements.forEach(item => {
        activityEvents.push({
            title: `Referral: ${String(item.type || item.status || 'Case referred').replaceAll('_', ' ')}`,
            date: item.referred_at || item.endorsed_at,
            actor: 'Barangay personnel',
            details: [item.status ? `Status: ${item.status}` : ''].filter(Boolean)
        });
    });
    findings.forEach(item => {
        activityEvents.push({
            title: 'Officer Findings Recorded',
            date: item.created_at,
            actor: item.submitted_by_name || 'Barangay officer',
            details: [
                item.observations ? `Observations: ${item.observations}` : '',
                item.action_taken ? `Action taken: ${item.action_taken}` : '',
                item.findings ? `Findings: ${item.findings}` : '',
                item.recommended_next_steps ? `Recommended next steps: ${item.recommended_next_steps}` : ''
            ].filter(Boolean)
        });
    });
    resolutions.forEach(item => {
        activityEvents.push({
            title: `Resolution: ${outcomeLabel(item.outcome)}`,
            date: item.resolved_at,
            actor: item.resolved_by_name || 'Barangay officer',
            details: [
                item.conclusion || '',
                item.follow_up_at ? `Follow-up: ${formatDateTime(item.follow_up_at)}` : '',
                item.resulting_status ? `Status recorded: ${statusLabel(item.resulting_status)}` : ''
            ].filter(Boolean)
        });
    });
    closures.forEach(item => {
        activityEvents.push({
            title: 'Resolution Finalized',
            date: item.closed_at,
            actor: item.closed_by_name || 'Barangay officer',
            details: [outcomeLabel(item.outcome), item.conclusion].filter(Boolean)
        });
    });
    if (currentStatus && !milestones.some(m => String(m.status_snapshot || '').toUpperCase() === currentStatus)) {
        activityEvents.push({
            title: `Current status: ${statusLabel(currentStatus)}`,
            date: caseData.updated_at || caseData.created_at,
            actor: caseData.assigned_officer_name || 'Barangay personnel',
            details: ['Current status from the official case record.']
        });
    }

    activityEvents.sort((a, b) => {
        const aTime = a.date ? new Date(String(a.date).replace(' ', 'T')).getTime() : 0;
        const bTime = b.date ? new Date(String(b.date).replace(' ', 'T')).getTime() : 0;
        return aTime - bTime;
    });
    timelineEl.insertAdjacentHTML('beforeend', activityEvents.map(event => `
        <details class="milestone-accordion" style="margin-bottom: 10px; border: 1px solid var(--line); border-radius: 6px; overflow: hidden; background: var(--surface);">
            <summary style="padding: 10px 14px; cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 12px; font-weight: 700; font-size: 13px; background: rgba(0,0,0,0.02); user-select: none;">
                <span>${escapeHtml(event.title)}</span>
                <span style="display: flex; align-items: center; gap: 8px;">
                    <small style="color: var(--muted); font-size: 11px;">${escapeHtml(formatDateTime(event.date))}</small>
                    <span class="chevron" style="font-size: 11px; color: var(--muted);">▼</span>
                </span>
            </summary>
            <div style="padding: 12px 14px; border-top: 1px solid var(--line); font-size: 12.5px; line-height: 1.5;">
                <p style="margin: 0 0 8px; color: var(--muted);">Recorded by ${escapeHtml(event.actor)}</p>
                ${event.details.map(detail => `<p style="margin: 0 0 6px; color: var(--ink); white-space: pre-wrap;">${escapeHtml(detail)}</p>`).join('')}
            </div>
        </details>
    `).join(''));

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

const initialView = location.hash.slice(1);
if (viewTitles[initialView]) setView(initialView);
refreshData();