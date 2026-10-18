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
    const timelineEl = document.getElementById('trackingTimeline');

    let milestones = [];
    let endorsements = [];
    let caseData = report;

    try {
        const res = await requestResident('get_milestones', { reference_number: ref });
        milestones = res.milestones || [];
        endorsements = res.endorsements || [];
        if (res.case) caseData = { ...report, ...res.case };
    } catch (err) {
        console.error('Milestone fetch error:', err);
    }

    const isPnpEscalated = endorsements.some(e => e.type === 'PNP_TRANSMITTAL') || 
                           milestones.some(m => String(m.status_snapshot).includes('ESCALATED') || String(m.action_note).includes('PNP'));
    
    const latestSnapshot = milestones.length > 0 ? milestones[milestones.length - 1].status_snapshot : caseData.status;
    const currentStatus = String(latestSnapshot || caseData.status || '').toUpperCase().trim();

    [stepReported, stepReview, stepAction, stepResolved].forEach(s => {
        if (s) s.className = 'stepper-step';
    });
    if (stepReported) stepReported.classList.add('is-done');

    const isPending = ['PENDING', 'UNDER_REVIEW', 'UNDER REVIEW'].includes(currentStatus);
    const isInProgress = ['IN_PROGRESS', 'IN PROGRESS'].includes(currentStatus);
    const isDispatched = ['DISPATCHED', 'UNIT DISPATCHED', 'CRITICAL', 'ESCALATED_PNP'].includes(currentStatus);
    const isLupon = ['FOR_RESOLUTION', 'LUPON'].includes(currentStatus);
    const isResolved = ['RESOLVED', 'CLOSED'].includes(currentStatus);

    if (isPending) {
        if (stepReview) stepReview.classList.add('is-active');
    } else if (isInProgress) {
        if (stepReview) stepReview.classList.add('is-done');
        if (stepAction) stepAction.classList.add('is-active');
    } else if (isDispatched) {
        if (stepReview) stepReview.classList.add('is-done');
        if (stepAction) stepAction.classList.add('is-active'); 
    } else if (isLupon) {
        if (stepReview) stepReview.classList.add('is-done');
        if (stepAction) stepAction.classList.add('is-done');
        if (stepResolved) stepResolved.classList.add('is-active');
    } else if (isResolved) {
        if (stepReview) stepReview.classList.add('is-done');
        if (stepAction) stepAction.classList.add('is-done');
        if (stepResolved) stepResolved.classList.add('is-done');
    }

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
            badge: (isInProgress || isDispatched || isLupon || isResolved) ? 'Verified' : 'Under Review',
            badgeClass: (isInProgress || isDispatched || isLupon || isResolved) ? 'pill-active' : 'pill-pending',
            date: formatDate(findMilestone(['IN_PROGRESS', 'DESK_REVIEW'])?.created_at || caseData.created_at),
            isReached: !isPending,
            residentMessage: (isInProgress || isDispatched || isLupon || isResolved)
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
            badge: (isDispatched || isLupon || isResolved) ? 'Dispatched' : 'Pending Deployment',
            badgeClass: (isDispatched || isLupon || isResolved) ? 'pill-blue' : 'pill-pending',
            date: findMilestone(['DISPATCHED', 'CRITICAL', 'ESCALATED_PNP'])?.created_at ? formatDate(findMilestone(['DISPATCHED', 'CRITICAL', 'ESCALATED_PNP']).created_at) : '—',
            isReached: isDispatched || isLupon || isResolved,
            residentMessage: (isDispatched || isLupon || isResolved)
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
            badge: isResolved ? 'Case Closed' : (isLupon ? 'Mediation Scheduled' : 'Pending Closure'),
            badgeClass: isResolved ? 'pill-active' : 'pill-pending',
            date: findMilestone(['RESOLVED', 'CLOSED'])?.created_at ? formatDate(findMilestone(['RESOLVED', 'CLOSED']).created_at) : '—',
            isReached: isResolved,
            residentMessage: isResolved
                ? 'The incident has been verified resolved on-site by responding personnel.'
                : (isLupon ? 'Case endorsed to Lupong Tagapamayapa for amicable conciliation hearing.' : 'Final disposition pending on-site report submission.'),
            details: [
                ['Final Disposition', isResolved ? 'Amicably Settled / Peace Restored' : 'Pending Action'],
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