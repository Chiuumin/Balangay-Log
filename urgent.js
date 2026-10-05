(function enforceAuthAndHistory() {
    const token = sessionStorage.getItem('authToken');
    const user = sessionStorage.getItem('currentUser');

    if (!token || !user || token === 'undefined' || user === 'undefined' || token === 'null' || user === 'null') {
        window.location.replace('index.html');
        return;
    }

    history.pushState(null, '', location.href);
    window.addEventListener('popstate', function () {
        history.pushState(null, '', location.href);
    });
})();

document.addEventListener('DOMContentLoaded', async () => {
    const liveClockDisplay = document.getElementById('liveClockDisplay');
    const userNameDisplay = document.getElementById('userNameDisplay');
    const userRoleDisplay = document.getElementById('userRoleDisplay');
    const userAvatar = document.getElementById('userAvatar');
    const urgentCaseList = document.getElementById('urgentCaseList');
    const sidebarUrgentBadge = document.getElementById('sidebarUrgentBadge');
    const urgentActionFeedback = document.getElementById('urgentActionFeedback');
    const quickDispatchDialog = document.getElementById('quickDispatchDialog');
    const quickDispatchForm = document.getElementById('quickDispatchForm');
    const dispatchResponder = document.getElementById('dispatchResponder');
    const dispatchCaseReference = document.getElementById('dispatchCaseReference');
    const dispatchFeedback = document.getElementById('dispatchFeedback');
    const confirmDispatch = document.getElementById('confirmDispatch');
    const caseDetailsDrawer = document.getElementById('caseDetailsDrawer');
    const drawerBackdrop = document.getElementById('drawerBackdrop');
    const drawerTimeline = document.getElementById('drawerTimeline');
    let activeDispatchReference = '';
    let activeDrawerCase = null;

    function updateClock() {
        if (!liveClockDisplay) return;
        const now = new Date();
        const dateStr = now.toLocaleDateString('en-US', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
        const timeStr = now.toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        });
        liveClockDisplay.innerText = `${dateStr} · ${timeStr}`;
    }
    updateClock();
    setInterval(updateClock, 1000);

    try {
        const currentUser = JSON.parse(sessionStorage.getItem('currentUser'));
        const name = currentUser?.full_name || currentUser?.username || 'User';
        const role = (currentUser?.role || 'OFFICER').toUpperCase();

        if (userNameDisplay) userNameDisplay.innerText = name;
        if (userRoleDisplay) {
            userRoleDisplay.innerText = role === 'OFFICER' ? 'Desk Officer' : role.charAt(0) + role.slice(1).toLowerCase();
            userRoleDisplay.className = `user-role-pill role-${role.toLowerCase()}`;
        }
        if (userAvatar) {
            const initials = name
                .split(' ')
                .filter(word => word.length)
                .map(word => word[0].toUpperCase())
                .slice(0, 2)
                .join('');
            userAvatar.innerText = initials || 'BL';
        }
    } catch (error) {
        console.warn('Session parse error:', error);
    }

    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            try {
                await fetch('api/logout.php', { method: 'POST', credentials: 'same-origin' });
            } catch (error) {
                console.warn('Logout request failed:', error);
            }
            sessionStorage.clear();
            localStorage.clear();
            window.location.replace('index.html');
        });
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (character) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        })[character]);
    }

    function renderUrgentCards(cases) {
        if (!urgentCaseList) return;

        const categories = [
            { key: 'critical', title: 'Critical', cases: [] },
            { key: 'high', title: 'High Priority', cases: [] },
            { key: 'standard', title: 'Standard', cases: [] }
        ];

        cases.forEach((item) => {
            const category = item.status === 'CRITICAL' || item.priority_level === 'Critical'
                ? 'critical'
                : item.priority_level === 'High'
                    ? 'high'
                    : 'standard';
            categories.find((group) => group.key === category).cases.push(item);
        });

        urgentCaseList.innerHTML = categories.map((category) => {
            const cards = category.cases.length ? category.cases.map((item) => {
                const status = (item.status || 'PENDING').replaceAll('_', ' ');
                const priority = item.priority_level || 'Moderate';
                const responding = item.status === 'IN_PROGRESS';
                const badgeClass = category.key === 'critical' ? 'critical' : category.key;

                return `
                    <article class="urgent-case-card priority-card-${category.key}">
                        <div class="case-row-top">
                            <div class="case-meta-left">
                                <span class="case-id">${escapeHtml(item.reference_number)}</span>
                                <span class="status-badge">${escapeHtml(status)}</span>
                            </div>
                            <span class="priority-badge ${badgeClass}">${escapeHtml(priority)}</span>
                        </div>

                        <h3>${escapeHtml(item.incident_type || 'Incident')}</h3>

                        <div class="details-action-row">
                            <div class="case-details-grid">
                                <div class="detail-item">
                                    <span>Emergency Type</span>
                                    <strong>${escapeHtml(item.incident_type || 'Not recorded')}</strong>
                                </div>
                                <div class="detail-item">
                                    <span>Location</span>
                                    <strong>${escapeHtml(item.purok || 'Not recorded')}</strong>
                                </div>
                                <div class="detail-item">
                                    <span>Reporter</span>
                                    <strong>${escapeHtml(item.complainant_name || 'Not recorded')}</strong>
                                </div>
                                <div class="detail-item">
                                    <span>Responder</span>
                                    <strong>${escapeHtml(item.assigned_officer_name || item.deployed_unit || 'Unassigned')}</strong>
                                </div>
                            </div>

                            <div class="case-actions">
                                <button class="primary-btn" type="button" data-action="dispatch" data-reference="${escapeHtml(item.reference_number)}" ${responding ? 'disabled' : ''}>${responding ? 'Responding' : 'Acknowledge / Respond'}</button>
                                <button class="secondary-btn" type="button" data-action="details" data-reference="${escapeHtml(item.reference_number)}">View Details</button>
                            </div>
                        </div>
                    </article>
                `;
            }).join('') : '<p class="priority-empty-state">No cases in this category.</p>';

            return `
                <section class="priority-group priority-group-${category.key}" aria-labelledby="${category.key}CasesHeading">
                    <h3 class="priority-group-title" id="${category.key}CasesHeading"><span class="priority-group-dot" aria-hidden="true"></span>${category.title}</h3>
                    <div class="priority-group-list">${cards}</div>
                </section>
            `;
        }).join('');
    }

    function formatDate(value) {
        if (!value) return 'Not recorded';
        const parsed = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString();
    }

    function setText(elementId, value, fallback = 'Not recorded') {
        const element = document.getElementById(elementId);
        if (element) element.textContent = value || fallback;
    }

    function renderTimeline(caseRecord, milestones) {
        if (!drawerTimeline) return;
        drawerTimeline.replaceChildren();
        const events = Array.isArray(milestones) && milestones.length ? milestones : [{
            status_snapshot: caseRecord.status,
            action_note: 'Case created',
            created_at: caseRecord.created_at
        }];

        events.forEach((milestone) => {
            const entry = document.createElement('li');
            const time = document.createElement('time');
            const title = document.createElement('strong');
            const note = document.createElement('span');
            time.textContent = formatDate(milestone.created_at);
            title.textContent = milestone.officer_in_charge
                ? `${milestone.status_snapshot || 'Update'} · ${milestone.officer_in_charge}`
                : (milestone.status_snapshot || 'Case Update').replaceAll('_', ' ');
            note.textContent = milestone.action_note || 'Status updated.';
            entry.append(time, title, note);
            drawerTimeline.append(entry);
        });
    }

    function renderDrawerCase(caseRecord, milestones) {
        activeDrawerCase = caseRecord;
        setText('drawerCaseReference', caseRecord.reference_number);
        setText('drawerPriority', caseRecord.priority_level || 'Standard');
        setText('drawerIncidentType', caseRecord.incident_type, 'Incident');
        setText('drawerAddress', caseRecord.purok ? `${caseRecord.purok}, Barangay Duale` : 'Not recorded');
        setText('drawerReporter', caseRecord.complainant_name);
        setText('drawerPhone', caseRecord.complainant_phone);
        setText('drawerTimeReported', formatDate(caseRecord.created_at));
        setText('drawerOfficer', caseRecord.assigned_officer_name, 'Unassigned');
        setText('drawerUnit', caseRecord.deployed_unit, 'Unassigned');
        setText('drawerNarrative', caseRecord.narrative_description);
        setText('drawerMapLocation', `${caseRecord.purok || 'Location not recorded'}${caseRecord.latitude && caseRecord.longitude ? ` · ${caseRecord.latitude}, ${caseRecord.longitude}` : ''}`);
        const statusSelect = document.getElementById('drawerStatusSelect');
        if (statusSelect) statusSelect.value = caseRecord.status;
        renderTimeline(caseRecord, milestones);
    }

    async function loadCaseDetails(referenceNumber) {
        const response = await fetch(`api/track_case.php?tracking_id=${encodeURIComponent(referenceNumber)}`);
        const result = await response.json();
        if (!response.ok || !result.success || !result.case) {
            throw new Error(result.message || 'Unable to load case details.');
        }
        if (caseDetailsDrawer?.classList.contains('is-open')) {
            renderDrawerCase(result.case, result.milestones);
        }
    }

    async function openCaseDrawer(referenceNumber) {
        if (!caseDetailsDrawer || !drawerBackdrop) return;
        setText('drawerCaseReference', referenceNumber);
        setText('drawerIncidentType', 'Loading case details...');
        setText('drawerTimeline', 'Loading case history...');
        const feedback = document.getElementById('drawerFeedback');
        if (feedback) feedback.textContent = '';
        caseDetailsDrawer.inert = false;
        caseDetailsDrawer.setAttribute('aria-hidden', 'false');
        caseDetailsDrawer.classList.add('is-open');
        drawerBackdrop.hidden = false;
        document.body.classList.add('drawer-open');
        requestAnimationFrame(() => drawerBackdrop.classList.add('is-open'));
        document.getElementById('closeCaseDrawer')?.focus();

        try {
            await loadCaseDetails(referenceNumber);
        } catch (error) {
            if (feedback) feedback.textContent = error.message;
        }
    }

    function closeCaseDrawer() {
        if (!caseDetailsDrawer || !drawerBackdrop) return;
        caseDetailsDrawer.classList.remove('is-open');
        caseDetailsDrawer.setAttribute('aria-hidden', 'true');
        caseDetailsDrawer.inert = true;
        drawerBackdrop.classList.remove('is-open');
        document.body.classList.remove('drawer-open');
        window.setTimeout(() => { drawerBackdrop.hidden = true; }, 240);
        activeDrawerCase = null;
    }

    async function loadUrgentCases() {
        if (urgentActionFeedback) urgentActionFeedback.textContent = '';
        const response = await fetch('api/get_dashboard_stats.php');
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Unable to load urgent incidents.');

        const cases = Array.isArray(result.case_queue)
            ? result.case_queue
            : (Array.isArray(result.urgent_cases) ? result.urgent_cases : []);
        if (sidebarUrgentBadge) {
            const urgentCases = Array.isArray(result.urgent_cases) ? result.urgent_cases : [];
            sidebarUrgentBadge.innerText = String(urgentCases.length);
            sidebarUrgentBadge.style.display = urgentCases.length > 0 ? 'inline-block' : 'none';
        }

        renderUrgentCards(cases);
        return cases;
    }

    urgentCaseList?.addEventListener('click', async (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) return;

        const referenceNumber = button.dataset.reference;
        const selectedCase = (window.urgentCases || []).find((item) => item.reference_number === referenceNumber);
        if (button.dataset.action === 'details') {
            openCaseDrawer(referenceNumber);
            return;
        }

        if (button.dataset.action === 'dispatch' && selectedCase && quickDispatchDialog) {
            activeDispatchReference = referenceNumber;
            dispatchCaseReference.textContent = referenceNumber;
            dispatchResponder.value = '';
            dispatchFeedback.textContent = '';
            quickDispatchDialog.showModal();
        }
    });

    quickDispatchForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!activeDispatchReference || !dispatchResponder.value) return;

        const [responderType, responderName] = dispatchResponder.value.split('|');
        confirmDispatch.disabled = true;
        confirmDispatch.textContent = 'Dispatching...';
        dispatchFeedback.textContent = '';
        try {
            const response = await fetch('api/update_case_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    reference_number: activeDispatchReference,
                    new_status: 'IN_PROGRESS',
                    assigned_officer_name: responderType === 'officer' ? responderName : null,
                    deployed_unit: responderType === 'unit' ? responderName : null,
                    action_note: `Quick dispatch confirmed. ${responderName} assigned to respond.`
                })
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Dispatch could not be confirmed.');

            const referenceNumber = activeDispatchReference;
            quickDispatchDialog.close();
            window.urgentCases = await loadUrgentCases();
            if (activeDrawerCase?.reference_number === referenceNumber) await loadCaseDetails(referenceNumber);
            if (urgentActionFeedback) urgentActionFeedback.textContent = `${referenceNumber} acknowledged. ${responderName} assigned.`;
        } catch (error) {
            dispatchFeedback.textContent = error.message;
        } finally {
            confirmDispatch.disabled = false;
            confirmDispatch.textContent = 'Confirm Dispatch';
        }
    });

    function closeDispatchDialog() {
        quickDispatchDialog?.close();
        activeDispatchReference = '';
    }

    document.getElementById('closeDispatchDialog')?.addEventListener('click', closeDispatchDialog);
    document.getElementById('cancelDispatch')?.addEventListener('click', closeDispatchDialog);
    document.getElementById('closeCaseDrawer')?.addEventListener('click', closeCaseDrawer);
    drawerBackdrop?.addEventListener('click', closeCaseDrawer);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && caseDetailsDrawer?.classList.contains('is-open')) closeCaseDrawer();
    });

    async function updateDrawerStatus(nextStatus, actionNote) {
        if (!activeDrawerCase) return;
        const referenceNumber = activeDrawerCase.reference_number;
        const feedback = document.getElementById('drawerFeedback');
        if (feedback) feedback.textContent = '';
        try {
            const response = await fetch('api/update_case_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    reference_number: referenceNumber,
                    new_status: nextStatus,
                    assigned_officer_name: activeDrawerCase.assigned_officer_name,
                    deployed_unit: activeDrawerCase.deployed_unit,
                    action_note: actionNote
                })
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Unable to update case status.');
            window.urgentCases = await loadUrgentCases();
            await loadCaseDetails(referenceNumber);
            if (feedback) feedback.textContent = result.message;
        } catch (error) {
            if (feedback) feedback.textContent = error.message;
        }
    }

    document.getElementById('updateDrawerStatus')?.addEventListener('click', () => {
        const nextStatus = document.getElementById('drawerStatusSelect')?.value;
        if (nextStatus) updateDrawerStatus(nextStatus, `Status updated to ${nextStatus.replaceAll('_', ' ')} from the case details drawer.`);
    });

    document.getElementById('closeDrawerCase')?.addEventListener('click', () => {
        if (!activeDrawerCase || !window.confirm(`Close case ${activeDrawerCase.reference_number} as Resolved?`)) return;
        updateDrawerStatus('RESOLVED', 'Case closed as resolved from the case details drawer.');
    });

    document.getElementById('addDrawerNote')?.addEventListener('click', async () => {
        if (!activeDrawerCase) return;
        const noteInput = document.getElementById('drawerNoteInput');
        const noteText = noteInput?.value.trim();
        const feedback = document.getElementById('drawerFeedback');
        if (!noteText) {
            if (feedback) feedback.textContent = 'Enter a note before adding it to the timeline.';
            noteInput?.focus();
            return;
        }

        const currentUser = JSON.parse(sessionStorage.getItem('currentUser') || '{}');
        try {
            const response = await fetch('api/add_case_note.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    reference_number: activeDrawerCase.reference_number,
                    action_note: noteText,
                    officer_in_charge: currentUser.full_name || currentUser.username || 'Dispatcher'
                })
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Unable to add note.');
            noteInput.value = '';
            await loadCaseDetails(activeDrawerCase.reference_number);
            if (feedback) feedback.textContent = 'Note added to the case timeline.';
        } catch (error) {
            if (feedback) feedback.textContent = error.message;
        }
    });

    try {
        window.urgentCases = await loadUrgentCases();
    } catch (error) {
        console.error('Urgent page load failed:', error);
        if (urgentCaseList) {
            urgentCaseList.innerHTML = `
                <div class="empty-state-box">
                    <p>Unable to load urgent cases.</p>
                    <span>${escapeHtml(error.message || 'Please refresh the page or check the server connection.')}</span>
                </div>
            `;
        }
    }
});
