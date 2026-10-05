// ==========================================
// AI TRIAGE & OVERRIDE AUDIT STATE
// ==========================================
window.currentAiSuggested = 'Low';
window.savedOverrideJustification = '';
let pendingPriorityChange = '';

// ==========================================
// 0. TOP-LEVEL AUTH & HISTORY TRAP
// Must run OUTSIDE DOMContentLoaded for bfcache support
// ==========================================
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

    window.addEventListener('pageshow', function (event) {
        const activeToken = sessionStorage.getItem('authToken');
        const activeUser = sessionStorage.getItem('currentUser');
        if (!activeToken || !activeUser || activeToken === 'undefined' || activeUser === 'undefined' || activeToken === 'null' || activeUser === 'null') {
            window.location.replace('index.html');
        }
    });

    window.addEventListener('storage', function () {
        if (!sessionStorage.getItem('authToken') || !sessionStorage.getItem('currentUser')) {
            window.location.replace('index.html');
        }
    });
})();

document.addEventListener('DOMContentLoaded', () => {

    // ==========================================
    // 1. LIVE CLOCK & GREETING
    // ==========================================
    function updateClock() {
        const clockEl = document.getElementById('liveClockDisplay');
        if (!clockEl) return;

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

        clockEl.innerText = `${dateStr} · ${timeStr}`;
    }
    updateClock();
    setInterval(updateClock, 1000);

    async function loadSystemParameters() {
        try {
            const response = await fetch('api/get_system_parameters.php', { credentials: 'same-origin' });
            const result = await response.json();
            if (!response.ok || !result.success) return;

            const options = [
                ['purokSelect', 'Select Purok', result.puroks],
                ['incidentCategory', 'Select Category', result.categories]
            ];
            options.forEach(([elementId, placeholder, values]) => {
                const select = document.getElementById(elementId);
                if (!select) return;
                const selectedValue = select.value;
                select.replaceChildren(new Option(placeholder, ''));
                values.forEach((value) => select.add(new Option(value, value)));
                if (values.includes(selectedValue)) select.value = selectedValue;
            });
        } catch (error) {
            console.warn('Could not load managed system parameters:', error);
        }
    }
    loadSystemParameters();

    // ==========================================
    // 2. LEAFLET MAP INITIALIZATION
    // ==========================================
    let mapInstance = null;
    let markerInstance = null;

    function initLeafletPicker() {
        const defaultLat = 14.5453;
        const defaultLng = 120.5739;

        const mapContainer = document.getElementById('incidentMapPicker');
        if (!mapContainer) return;

        if (!mapInstance && typeof L !== 'undefined') {
            mapInstance = L.map('incidentMapPicker').setView([defaultLat, defaultLng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(mapInstance);

            markerInstance = L.marker([defaultLat, defaultLng], { draggable: true }).addTo(mapInstance);

            const latInput = document.getElementById('incidentLat');
            const lngInput = document.getElementById('incidentLng');

            if (latInput) latInput.value = defaultLat.toFixed(6);
            if (lngInput) lngInput.value = defaultLng.toFixed(6);

            markerInstance.on('dragend', function () {
                const pos = markerInstance.getLatLng();
                if (latInput) latInput.value = pos.lat.toFixed(6);
                if (lngInput) lngInput.value = pos.lng.toFixed(6);
            });

            mapInstance.on('click', function (e) {
                markerInstance.setLatLng(e.latlng);
                if (latInput) latInput.value = e.latlng.lat.toFixed(6);
                if (lngInput) lngInput.value = e.latlng.lng.toFixed(6);
            });
        }

        setTimeout(() => {
            if (mapInstance) mapInstance.invalidateSize();
        }, 200);
    }

    // ==========================================
    // 3. ENCODE CASE MODAL CONTROLS
    // ==========================================
    const encodeModal = document.getElementById('encodeModal');
    const btnOpenEncode = document.querySelector('.btn-encode-case');
    const btnQuickEncode = document.getElementById('btnQuickEncode');
    const btnCloseModal = document.getElementById('btnCloseModal');
    const btnCancelModal = document.getElementById('btnCancelModal');

    function openModal() {
        if (encodeModal) {
            encodeModal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            initLeafletPicker();
        }
    }

    function closeModal() {
        if (encodeModal) {
            encodeModal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }

    const btnQuickUrgent = document.getElementById('btnQuickUrgent');
    const urgentPageLink = document.querySelector('.alert-action-link');

    if (btnOpenEncode) btnOpenEncode.addEventListener('click', (e) => { e.preventDefault(); openModal(); });
    if (btnQuickEncode) btnQuickEncode.addEventListener('click', (e) => { e.preventDefault(); openModal(); });
    if (btnQuickUrgent) btnQuickUrgent.addEventListener('click', (e) => { e.preventDefault(); window.location.href = 'urgent.html'; });
    if (urgentPageLink) urgentPageLink.addEventListener('click', (e) => { e.preventDefault(); window.location.href = 'urgent.html'; });
    if (btnCloseModal) btnCloseModal.addEventListener('click', closeModal);
    if (btnCancelModal) btnCancelModal.addEventListener('click', closeModal);

    // ==========================================
    // 4. ENCODE FORM DIRECT SUBMISSION
    // ==========================================
    const encodeForm = document.getElementById('encodeCaseForm');
    const totalCasesSubtext = document.getElementById('totalCasesSubtext');
    const countPending = document.getElementById('countPending');
    const countCritical = document.getElementById('countCritical');
    const caseListContainer = document.getElementById('caseListContainer');
    const activityTimelineContainer = document.getElementById('activityTimelineContainer');
    const urgentBanner = document.getElementById('urgentBanner');

    if (encodeForm) {
        encodeForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const nameVal           = document.getElementById('complainantName')?.value.trim() || '';
            const phoneVal          = document.getElementById('complainantPhone')?.value.trim() || '';
            const purokVal          = document.getElementById('purokSelect')?.value || '';
            const houseVal          = document.getElementById('houseNumber')?.value.trim() || '';
            const streetVal         = document.getElementById('streetAddress')?.value.trim() || '';
            const categoryVal       = document.getElementById('incidentCategory')?.value || '';
            const titleVal          = document.getElementById('incidentTitle')?.value.trim() || '';
            const narrativeVal      = document.getElementById('incidentNarrative')?.value.trim() || '';
            const intakeChannelVal  = document.getElementById('intakeChannel')?.value || 'Walk-In Desk';

            if (!nameVal || nameVal.length < 3) {
                alert('Please enter a valid Complainant Full Name (at least 3 characters).');
                document.getElementById('complainantName')?.focus();
                return;
            }

            if (!/^09\d{9}$/.test(phoneVal)) {
                alert('Please enter a valid 11-digit Philippine mobile number starting with 09 (e.g., 09171234567).');
                document.getElementById('complainantPhone')?.focus();
                return;
            }

            if (!purokVal || purokVal === 'Select Purok') {
                alert('Please select a valid Purok / Zone from the dropdown.');
                document.getElementById('purokSelect')?.focus();
                return;
            }

            if (!houseVal) {
                alert('Please enter the House / Building Number.');
                document.getElementById('houseNumber')?.focus();
                return;
            }

            if (!streetVal) {
                alert('Please enter the Street Name.');
                document.getElementById('streetAddress')?.focus();
                return;
            }

            if (!categoryVal || categoryVal === 'General Incident') {
                alert('Please select an Incident Category.');
                document.getElementById('incidentCategory')?.focus();
                return;
            }

            if (!titleVal || titleVal.length < 5) {
                alert('Please provide a descriptive Incident Summary / Title (minimum 5 characters).');
                document.getElementById('incidentTitle')?.focus();
                return;
            }

            if (!narrativeVal || narrativeVal.length < 20) {
                alert('Please enter substantial blotter notes / narrative (minimum 20 characters).');
                document.getElementById('incidentNarrative')?.focus();
                return;
            }

            const fullAddress = `${houseVal} ${streetVal}`.trim();
            const latVal = parseFloat(document.getElementById('incidentLat')?.value) || 14.545300;
            const lngVal = parseFloat(document.getElementById('incidentLng')?.value) || 120.573900;

            const priorityVal = document.getElementById('priorityLevel')?.value || 'Low';
            const aiSuggested = window.currentAiSuggested || 'Low';
            const isOverridden = (aiSuggested === 'Critical' && priorityVal !== 'Critical') ? 1 : 0;
            const finalJustification = window.savedOverrideJustification || '';

            if (isOverridden && !finalJustification.trim()) {
                alert('Officer justification is required when downgrading a Critical incident.');
                const downgradeModal = document.getElementById('downgradeModal');
                const targetLabel = document.getElementById('targetPriorityLabel');
                if (targetLabel) targetLabel.innerText = priorityVal;
                if (downgradeModal) downgradeModal.style.display = 'flex';
                return;
            }

            const payload = {
                complainantName: nameVal,
                complainantPhone: phoneVal,
                purok: purokVal,
                streetAddress: fullAddress,
                incidentTitle: titleVal,
                incidentCategory: categoryVal,
                priorityLevel: priorityVal,
                aiDetectedPriority: aiSuggested,
                isPriorityOverridden: isOverridden,
                overrideJustification: isOverridden ? finalJustification : null,
                incidentNarrative: narrativeVal,
                intakeChannel: intakeChannelVal,
                lat: latVal,
                lng: lngVal
            };

            const btnSubmit = document.getElementById('btnSubmitCase') || encodeForm.querySelector('button[type="submit"]');
            if (btnSubmit) {
                btnSubmit.disabled = true;
                btnSubmit.innerText = 'Filing Report...';
            }

            try {
                const response = await fetch('api/encode_case.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                const text = await response.text();
                let result;
                try {
                    result = JSON.parse(text);
                } catch (jsonErr) {
                    console.error('Server Raw Response:', text);
                    alert('Backend Response Error: ' + text);
                    return;
                }

                if (result.success) {
                    const item = result.data;

                    let curPending = parseInt(countPending?.innerText || '0', 10);
                    let curCritical = parseInt(countCritical?.innerText || '0', 10);

                    if (item.priority === 'Critical') {
                        if (countCritical) countCritical.innerText = curCritical + 1;
                        if (urgentBanner) urgentBanner.style.display = 'flex';
                    } else {
                        if (countPending) countPending.innerText = curPending + 1;
                    }

                    if (caseListContainer) {
                        const emptyCases = caseListContainer.querySelector('.empty-state-box');
                        if (emptyCases) emptyCases.remove();

                        const priorityClass = item.priority.toLowerCase();
                        const newCaseHtml = `
                            <div class="case-item">
                                <div class="case-meta">
                                    <h4>${item.title}</h4>
                                    <span class="case-subtext">${item.reference_number} · ${item.complainant}</span>
                                </div>
                                <div class="case-tags">
                                    <span class="badge badge-${priorityClass}">${item.priority}</span>
                                    <span class="pill pill-pending">Pending</span>
                                    <button class="btn-suggest">💡 Suggest</button>
                                </div>
                            </div>
                        `;
                        caseListContainer.insertAdjacentHTML('afterbegin', newCaseHtml);
                    }

                    if (activityTimelineContainer) {
                        const emptyActivity = activityTimelineContainer.querySelector('.empty-state-box');
                        if (emptyActivity) emptyActivity.remove();

                        const dotClass = item.priority === 'Critical' ? 'act-red' : 'act-blue';
                        const newActivityHtml = `
                            <div class="activity-entry">
                                <span class="act-dot ${dotClass}"></span>
                                <div class="act-details">
                                    <p class="act-text"><strong>${item.reference_number}</strong> encoded — ${item.category}</p>
                                    <span class="act-time">Just now</span>
                                </div>
                            </div>
                        `;
                        activityTimelineContainer.insertAdjacentHTML('afterbegin', newActivityHtml);
                    }

                    encodeForm.reset();
                    window.currentAiSuggested = 'Low';
                    window.savedOverrideJustification = '';
                    const triageHint = document.getElementById('aiTriageHint');
                    if (triageHint) triageHint.innerText = '';
                    closeModal();

                    if (typeof loadDashboardData === 'function') loadDashboardData();
                    if (typeof fetchNotifications === 'function') fetchNotifications();
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (err) {
                console.error('Fetch Error:', err);
                alert('Could not connect to server: ' + err.message);
            } finally {
                if (btnSubmit) {
                    btnSubmit.disabled = false;
                    btnSubmit.innerText = 'Encode & File Case';
                }
            }
        });
    }

    // ==========================================
    // 5. AI SUGGEST ENGINE & ACTION EXECUTION
    // ==========================================
    const suggestModal = document.getElementById('suggestModal');
    const btnCloseSuggestModal = document.getElementById('btnCloseSuggestModal');
    const btnDismissSuggest = document.getElementById('btnDismissSuggest');
    const btnApplyAction = document.getElementById('btnApplyAction');
    const suggestCaseRef = document.getElementById('suggestCaseRef');
    const suggestClassification = document.getElementById('suggestClassification');
    const suggestPriority = document.getElementById('suggestPriority');
    const suggestRecommendation = document.getElementById('suggestRecommendation');
    const sopList = document.getElementById('sopList');

    let currentSuggestRef = '';
    let currentClassification = '';

    function closeSuggest() {
        if (suggestModal) suggestModal.style.display = 'none';
    }

    if (btnCloseSuggestModal) btnCloseSuggestModal.addEventListener('click', closeSuggest);
    if (btnDismissSuggest) btnDismissSuggest.addEventListener('click', closeSuggest);

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-suggest');
        if (!btn) return;

        const caseItem = btn.closest('.case-item');
        if (!caseItem) return;

        const title = caseItem.querySelector('h4')?.innerText || 'Incident';
        const subtext = caseItem.querySelector('.case-subtext')?.innerText || '';
        const refNumber = subtext.split('·')[0].trim();
        const badge = caseItem.querySelector('.badge')?.innerText || 'Medium';

        currentSuggestRef = refNumber;

        let recommendation = '';
        let sops = [];
        let classificationTag = 'RESOLVABLE AT BRGY';

        if (title.toLowerCase().includes('altercation') || badge === 'Critical' || badge === 'High') {
            classificationTag = 'PEACE & ORDER / DISPATCH';
            recommendation = 'Incident requires immediate Tanod field verification and blotter documentation. If weapons or severe injury are present, transfer to PNP.';
            sops = [
                'Dispatch 2 Duty Barangay Tanods to secure area.',
                'Record complainant and respondent statements in blotter.',
                'Check resident registry for prior infractions.'
            ];
        } else if (title.toLowerCase().includes('dispute') || title.toLowerCase().includes('noise')) {
            classificationTag = 'LUPON MEDIATION';
            recommendation = 'Eligible for Amicable Settlement under Katarungang Pambarangay. Issue notice to summon respondent for First Hearing.';
            sops = [
                'Issue Form KP 7 (Notice of Hearing) to both parties.',
                'Schedule conciliation with Punong Barangay / Lupon.',
                'Mediate within 15 days before issuing Certificate to File Action.'
            ];
        } else {
            classificationTag = 'STANDARD INTAKE';
            recommendation = 'Log under monitoring. Route to respective barangay committee for administrative review.';
            sops = [
                'Verify complainant contact information.',
                'Assign case tracking officer.',
                'Set reminder for 48-hour follow-up.'
            ];
        }

        currentClassification = classificationTag;

        if (suggestCaseRef) suggestCaseRef.innerText = `${refNumber} — ${title}`;
        if (suggestClassification) suggestClassification.innerText = classificationTag;
        if (suggestPriority) suggestPriority.innerText = `Priority: ${badge}`;
        if (suggestRecommendation) suggestRecommendation.innerText = recommendation;
        if (sopList) sopList.innerHTML = sops.map(s => `<li>${s}</li>`).join('');

        if (suggestModal) suggestModal.style.display = 'flex';
    });

    if (btnApplyAction) {
        btnApplyAction.addEventListener('click', async () => {
            if (!currentSuggestRef) return;

            btnApplyAction.disabled = true;
            btnApplyAction.innerText = 'Executing...';

            try {
                const response = await fetch('api/execute_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        reference_number: currentSuggestRef,
                        action_type: currentClassification.includes('LUPON') ? 'LUPON' : 'DISPATCH'
                    })
                });

                const result = await response.json();

                if (result.success) {
                    const allCases = document.querySelectorAll('.case-item');
                    allCases.forEach(item => {
                        if (item.innerText.includes(currentSuggestRef)) {
                            const pill = item.querySelector('.pill');
                            if (pill) {
                                pill.className = `pill pill-${result.new_status.toLowerCase().replace('_', '-')}`;
                                   pill.innerText = result.new_status.replace('_', ' ');
                            }
                        }
                    });

                    if (activityTimelineContainer) {
                        const emptyBox = activityTimelineContainer.querySelector('.empty-state-box');
                        if (emptyBox) emptyBox.remove();

                        const logHtml = `
                            <div class="activity-entry">
                                <span class="act-dot act-purple"></span>
                                <div class="act-details">
                                    <p class="act-text"><strong>${currentSuggestRef}</strong> action executed — dispatched tanods</p>
                                    <span class="act-time">Just now</span>
                                </div>
                            </div>
                        `;
                        activityTimelineContainer.insertAdjacentHTML('afterbegin', logHtml);
                    }

                    closeSuggest();
                    loadDashboardData();
                } else {
                    alert('Action failed: ' + result.message);
                }
            } catch (err) {
                console.error('Execute action error:', err);
                alert('Server connection error.');
            } finally {
                btnApplyAction.disabled = false;
                btnApplyAction.innerText = 'Execute Action';
            }
        });
    }

 // Dismiss modals when clicking on background
    window.addEventListener('click', (e) => {
        if (e.target === encodeModal) closeModal();
        if (e.target === suggestModal) closeSuggest();
        if (e.target === statusModal) closeStatusModal();
    });
    
    // ==========================================
    // NOTIFICATION BELL & SIDEBAR BADGE SYNC
    // ==========================================
    const bellBtn = document.getElementById('bellBtn');
    const bellDot = document.getElementById('bellDot');
    const notificationDropdown = document.getElementById('notificationDropdown');
    const notificationList = document.getElementById('notificationList');
    const notifBadgeSummary = document.getElementById('notifBadgeSummary');
    const sidebarUrgentBadge = document.getElementById('sidebarUrgentBadge');
    const sidebarSpamBadge = document.getElementById('sidebarSpamBadge');

    if (bellBtn && notificationDropdown) {
        bellBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const isVisible = notificationDropdown.style.display === 'block';
            notificationDropdown.style.display = isVisible ? 'none' : 'block';
        });

        document.addEventListener('click', (e) => {
            if (!notificationDropdown.contains(e.target) && e.target !== bellBtn) {
                notificationDropdown.style.display = 'none';
            }
        });
    }

    async function fetchNotifications() {
        try {
            const res = await fetch('api/get_notifications.php');
            const data = await res.json();
            if (!data.success) return;

            const counts = data.counts;

            if (bellDot) {
                if (counts.total > 0) {
                    bellDot.innerText = counts.total;
                    bellDot.style.display = 'inline-flex';
                } else {
                    bellDot.style.display = 'none';
                }
            }

            if (notifBadgeSummary) {
                notifBadgeSummary.innerText = `${counts.total} Total`;
            }

            if (sidebarUrgentBadge) {
                const urgentTotal = counts.critical + counts.high;
                sidebarUrgentBadge.innerText = urgentTotal;
                sidebarUrgentBadge.style.display = urgentTotal > 0 ? 'inline-block' : 'none';
            }
            if (sidebarSpamBadge) {
                sidebarSpamBadge.innerText = counts.spam;
                sidebarSpamBadge.style.display = counts.spam > 0 ? 'inline-block' : 'none';
            }

            if (notificationList) {
                if (!data.notifications || data.notifications.length === 0) {
                    notificationList.innerHTML = `
                        <div style="text-align: center; padding: 24px 12px; color: #94a3b8;">
                            <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 6px;"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                            <p style="font-size: 0.8rem; margin: 0;">All caught up! No active alerts.</p>
                        </div>
                    `;
                    return;
                }

                notificationList.innerHTML = '';
                data.notifications.forEach(item => {
                    const row = document.createElement('div');
                    row.className = 'notif-card-item';
                    row.style.cssText = `
                        padding: 10px 12px;
                        border-radius: 8px;
                        cursor: pointer;
                        background: #f8fafc;
                        border-left: 4px solid ${item.border_color};
                        border-top: 1px solid #f1f5f9;
                        border-right: 1px solid #f1f5f9;
                        border-bottom: 1px solid #f1f5f9;
                        transition: all 0.2s ease;
                        display: flex;
                        flex-direction: column;
                        gap: 4px;
                    `;

                    row.innerHTML = `
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 0.65rem; font-weight: 800; color: ${item.badge_color}; letter-spacing: 0.5px;">${item.category_label}</span>
                            <span style="font-size: 0.72rem; color: #94a3b8;">Click to view &rarr;</span>
                        </div>
                        <div style="font-size: 0.84rem; font-weight: 700; color: #0f172a; line-height: 1.2;">${item.title}</div>
                        <div style="font-size: 0.74rem; color: #64748b;">${item.subtext}</div>
                    `;

                    row.addEventListener('mouseenter', () => {
                        row.style.background = '#f1f5f9';
                        row.style.transform = 'translateX(2px)';
                    });
                    row.addEventListener('mouseleave', () => {
                        row.style.background = '#f8fafc';
                        row.style.transform = 'none';
                    });

                    row.addEventListener('click', () => {
                        notificationDropdown.style.display = 'none';

                        const targetUrl = item.target_url || 'urgent.html';
                        window.location.href = targetUrl;

                        const hash = String(targetUrl).split('#')[1];
                        if (hash) {
                            const targetEl = document.getElementById(hash);
                            if (targetEl) {
                                targetEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                targetEl.style.transition = 'box-shadow 0.3s ease';
                                targetEl.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.4)';
                                setTimeout(() => { targetEl.style.boxShadow = 'none'; }, 2000);
                            }
                        }
                    });

                    notificationList.appendChild(row);
                });
            }
        } catch (err) {
            console.warn('Notifications fetch skipped:', err);
        }
    }

    fetchNotifications();   
    setInterval(fetchNotifications, 8000);

    // ==========================================
    // STATUS MODAL & PROGRESSION ENGINE
    // ==========================================
    const statusModal          = document.getElementById('statusModal');
    const btnCloseStatusModal  = document.getElementById('btnCloseStatusModal');
    const btnCancelStatus      = document.getElementById('btnCancelStatus');
    const btnConfirmStatus     = document.getElementById('btnConfirmStatus');
    const statusModalCaseRef   = document.getElementById('statusModalCaseRef');
    const targetStatusSelect   = document.getElementById('targetStatusSelect');
    const statusActionNote     = document.getElementById('statusActionNote');

    let activeStatusTargetRef = '';

    function closeStatusModal() {
        if (statusModal) statusModal.style.display = 'none';
        activeStatusTargetRef = '';
    }

    if (btnCloseStatusModal) btnCloseStatusModal.addEventListener('click', closeStatusModal);
    if (btnCancelStatus) btnCancelStatus.addEventListener('click', closeStatusModal);

    // Clicking any status pill in Recent Cases opens the transition modal
    document.addEventListener('click', (e) => {
        const pill = e.target.closest('.pill');
        if (!pill) return;

        const caseItem = pill.closest('.case-item');
        if (!caseItem) return;

        const subtext = caseItem.querySelector('.case-subtext')?.innerText || '';
        const refMatch = subtext.match(/TRK-\d{4}-\d{3}/);
        const refNumber = refMatch ? refMatch[0] : subtext.split('·')[0].trim();

        activeStatusTargetRef = refNumber;
        if (statusModalCaseRef) statusModalCaseRef.innerText = refNumber;
        if (statusActionNote) statusActionNote.value = '';
        if (statusModal) statusModal.style.display = 'flex';
    });

    if (btnConfirmStatus) {
        btnConfirmStatus.addEventListener('click', async () => {
            if (!activeStatusTargetRef) return;

            const selectedStatus = targetStatusSelect?.value || 'IN_PROGRESS';
            const noteText       = statusActionNote?.value.trim() || '';

            btnConfirmStatus.disabled = true;
            btnConfirmStatus.innerText = 'Updating...';

            try {
                const response = await fetch('api/update_case_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        reference_number: activeStatusTargetRef,
                        new_status: selectedStatus,
                        action_note: noteText
                    })
                });

                const result = await response.json();

                if (result.success) {
                    closeStatusModal();
                    loadDashboardData();
                    if (typeof fetchNotifications === 'function') fetchNotifications();
                } else {
                    alert('Update failed: ' + result.message);
                }
            } catch (err) {
                console.error('Status transition error:', err);
                alert('Connection error while updating status.');
            } finally {
                btnConfirmStatus.disabled = false;
                btnConfirmStatus.innerText = 'Update Status';
            }
        });
    }

    // ==========================================
    // 6. SESSION, USER PROFILE & LOGOUT
    // ==========================================
    try {
        const currentUser = JSON.parse(sessionStorage.getItem('currentUser'));
        const authToken = sessionStorage.getItem('authToken');

        if (!currentUser || !authToken) {
            window.location.replace('index.html');
            return;
        }

        const nameDisplay = document.getElementById('userNameDisplay');
        const roleDisplay = document.getElementById('userRoleDisplay');
        const avatar = document.getElementById('userAvatar');
        const welcomeHeading = document.getElementById('welcomeHeading');

        const fullName = currentUser.full_name || currentUser.username || 'User';
        const role = (currentUser.role || 'OFFICER').toUpperCase();

        if (nameDisplay) nameDisplay.innerText = fullName;
        if (roleDisplay) {
            roleDisplay.innerText = role === 'OFFICER' ? 'Desk Officer' : role.charAt(0) + role.slice(1).toLowerCase();
            roleDisplay.className = `user-role-pill role-${role.toLowerCase()}`;
        }
        if (avatar) {
            const initials = fullName
                .split(' ')
                .filter(w => w.length > 0)
                .map(w => w[0].toUpperCase())
                .slice(0, 2)
                .join('');
            avatar.innerText = initials || 'BL';
        }

        if (welcomeHeading) {
            const currentHour = new Date().getHours();
            let greeting = 'Good evening';
            if (currentHour >= 5 && currentHour < 12) {
                greeting = 'Good morning';
            } else if (currentHour >= 12 && currentHour < 18) {
                greeting = 'Good afternoon';
            }
            const firstName = fullName.split(' ')[0] || 'Official';
            welcomeHeading.innerText = `${greeting}, ${firstName}`;
        }

    } catch (e) {
        console.warn('Session parse error:', e);
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

    // ==========================================
    // 7. INCIDENT TREND CHART
    // ==========================================
    try {
        const ctx = document.getElementById('incidentTrendChart');
        if (ctx && typeof Chart !== 'undefined') {
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: ['Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov'],
                    datasets: [
                        {
                            label: 'Total Reports',
                            data: [0, 0, 0, 0, 0, 0],
                            borderColor: '#0b1528',
                            backgroundColor: 'transparent',
                            tension: 0.4,
                            borderWidth: 2.5,
                            pointRadius: 3,
                            pointBackgroundColor: '#0b1528'
                        },
                        {
                            label: 'Resolved',
                            data: [0, 0, 0, 0, 0, 0],
                            borderColor: '#10b981',
                            backgroundColor: 'transparent',
                            tension: 0.4,
                            borderWidth: 2,
                            pointRadius: 3,
                            pointBackgroundColor: '#10b981'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 11 } } },
                        y: { min: 0, max: 10, ticks: { stepSize: 2, color: '#94a3b8', font: { size: 11 } }, grid: { color: '#f1f5f9' } }
                    }
                }
            });
        }
    } catch (chartErr) {
        console.warn('Chart initialization skipped:', chartErr);
    }

    // ==========================================
    // 8. INITIAL DATA LOADER FROM DATABASE
    // ==========================================
    async function loadDashboardData() {
        try {
            const response = await fetch('api/get_dashboard_stats.php');
            const result = await response.json();

            if (!result.success) return;

            const counts = result.counts;

            const countPendingEl = document.getElementById('countPending');
            const countProgressEl = document.getElementById('countProgress');
            const countResolutionEl = document.getElementById('countResolution');
            const countResolvedEl = document.getElementById('countResolved');
            const countCriticalEl = document.getElementById('countCritical');

            if (countPendingEl) countPendingEl.innerText = counts.pending;
            if (countProgressEl) countProgressEl.innerText = counts.in_progress;
            if (countResolutionEl) countResolutionEl.innerText = counts.for_resolution;
            if (countResolvedEl) countResolvedEl.innerText = counts.resolved;
            if (countCriticalEl) countCriticalEl.innerText = counts.critical;

            if (totalCasesSubtext) {
                totalCasesSubtext.innerText = `${counts.total} total cases · Brgy. Duale`;
            }

            if (counts.critical > 0 && urgentBanner) {
                urgentBanner.style.display = 'flex';
                const bannerTitle = document.getElementById('urgentBannerTitle');
                if (bannerTitle) bannerTitle.innerText = `${counts.critical} critical case${counts.critical > 1 ? 's' : ''} require immediate attention`;
            } else if (urgentBanner) {
                urgentBanner.style.display = 'none';
            }

            if (caseListContainer && result.recent_cases.length > 0) {
                caseListContainer.innerHTML = '';
                result.recent_cases.forEach(item => {
                    const statusClass = item.status.toLowerCase().replace('_', '-');
                    const isCritical = item.status === 'CRITICAL';
                    const badgeClass = isCritical ? 'badge-critical' : 'badge-medium';
                    const badgeLabel = isCritical ? 'Critical' : 'Standard';

                    const html = `
                        <div class="case-item">
                            <div class="case-meta">
                                <h4>${item.incident_type}</h4>
                                <span class="case-subtext">${item.reference_number} · ${item.purok || 'Sitio Masaya'}</span>
                            </div>
                            <div class="case-tags">
                                <span class="badge ${badgeClass}">${badgeLabel}</span>
                                <span class="pill pill-${statusClass}">${item.status.replace('_', ' ')}</span>
                                <button class="btn-suggest">💡 Suggest</button>
                            </div>
                        </div>
                    `;
                    caseListContainer.insertAdjacentHTML('beforeend', html);
                });
            }
        } catch (error) {
            console.error('Failed to load dashboard stats:', error);
        }
    }

    // ==========================================
    // 9. LIVE AI TRIAGE (UNIVERSAL BILINGUAL HARMS & STEM ENGINE)
    // ==========================================
    const STOP_WORDS = new Set([
        // Filipino Stop Words
        'ang', 'mga', 'ng', 'sa', 'si', 'sina', 'ni', 'nina', 'kay', 'kina',
        'at', 'o', 'pero', 'dahil', 'kung', 'para', 'na', 'pa', 'ba', 'eh',
        'may', 'meron', 'wala', 'po', 'opo', 'ito', 'iyan', 'iyon', 'dito', 'doon',
        'isang', 'parte', 'gilid', 'loob', 'labas', 'harap', 'tapat', 'kanto',
        'raw', 'daw', 'din', 'rin', 'lang', 'naman', 'kasi', 'yata', 'pala',
        
        // English Stop Words
        'the', 'a', 'an', 'is', 'are', 'was', 'were', 'in', 'on', 'at', 'to',
        'for', 'with', 'and', 'or', 'of', 'by', 'it', 'this', 'that', 'from',
        'there', 'here', 'some', 'has', 'have', 'had', 'been', 'being',
        
        // Pronouns
        'ako', 'ikaw', 'ka', 'siya', 'kami', 'tayo', 'kayo', 'sila',
        'ko', 'mo', 'niya', 'namin', 'natin', 'ninyo', 'nila',
        'akin', 'iyo', 'kaniya', 'kanya', 'amin', 'atin', 'inyo', 'kanila',
        'he', 'she', 'they', 'them', 'their', 'we', 'us', 'our', 'you', 'your', 'my', 'me',
        
        // Non-threat noise terms
        'nagpapatugtog', 'nagpapatutog', 'patugtog', 'tugtog', 'kanta', 'musika',
        'narinig', 'dinig', 'tingin', 'sabi', 'usap', 'tanong', 'heard', 'talking'
    ]);

    const THREAT_ROOTS = {
        critical: [
            // Lethal / Fatalities / Severe Trauma
            'patay', 'bangkay', 'laslas', 'pugot', 'bitay', 'lunod', 'sakal', 'lason',
            'dead', 'die', 'dying', 'kill', 'killer', 'murder', 'murderer', 'corpse', 'fatal',
            'drown', 'strangle', 'poison', 'choke', 'hang',

            // Bladed Weapons & Stabbing
            'saksak', 'naksak', 'tarak', 'taga', 'itak', 'kutsilyo', 'patalim', 'balisong', 'gulok', 'kampit',
            'stab', 'knife', 'blade', 'machete', 'slashed',

            // Firearms & Explosives
            'baril', 'maril', 'putok', 'armas', 'sumpak', 'rebolber', 'pistola', 'bala',
            'shoot', 'gun', 'firearm', 'bullet', 'rifle', 'shotgun', 'pistol',
            'sunog', 'sabog', 'granada', 'molotov', 'bomba', 'apoy',
            'burn', 'arson', 'bomb', 'fire', 'explode', 'explosion',

            // Crisis & Felonies
            'hostage', 'gahasa', 'halay', 'kidnap', 'dukot', 'ambush',
            'rape', 'abduct', 'kidnapping'
        ],

        high: [
            // Physical Altercations & Injuries
            'suntok', 'nuntok', 'bugbog', 'away', 'hampas', 'palo', 'tadyak', 'sipa', 'sakitan',
            'buno', 'sampal', 'sabunot', 'bato', 'basag', 'kagulo', 'eskandalo', 'rambol',
            'bali', 'pasa', 'dugo', 'sugat', 'lapnos',
            'fight', 'assault', 'punch', 'beat', 'hit', 'brawl', 'strike', 'slap', 'kick',
            'injury', 'injured', 'wound', 'bleeding', 'fracture', 'bruise', 'attack',

            // Property Crimes & Theft
            'nakaw', 'holdap', 'kulimbat', 'agaw', 'snatch', 'kupit', 'nanloob', 'pitas', 'umakyat',
            'loob ng bahay', 'tinakas',
            'steal', 'theft', 'rob', 'robbery', 'burglary', 'loot', 'stolen', 'thief',

            // Threats, Harassment & Substances
            'banta', 'pananakot', 'haras', 'tutok', 'hamon', 'droga', 'shabu', 'marijuana',
            'threat', 'threaten', 'harass', 'extort', 'blackmail', 'drugs'
        ]
    };

    function getLevenshteinDistance(a, b) {
        if (a.length === 0) return b.length;
        if (b.length === 0) return a.length;
        const matrix = [];
        for (let i = 0; i <= b.length; i++) matrix[i] = [i];
        for (let j = 0; j <= a.length; j++) matrix[0][j] = j;

        for (let i = 1; i <= b.length; i++) {
            for (let j = 1; j <= a.length; j++) {
                if (b.charAt(i - 1) === a.charAt(j - 1)) {
                    matrix[i][j] = matrix[i - 1][j - 1];
                } else {
                    matrix[i][j] = Math.min(
                        matrix[i - 1][j - 1] + 1,
                        matrix[i][j - 1] + 1,
                        matrix[i - 1][j] + 1
                    );
                }
            }
        }
        return matrix[b.length][a.length];
    }

    function extractStem(word) {
        let clean = word.toLowerCase().trim();

        // 1. Tagalog Affix & Reduplication Stripping
        clean = clean.replace(/^(pananana|panana|pamama|panunu|manana|mamama|manunu)/, '');
        clean = clean.replace(/^(ipagpapatuloy|ipagpapa|nanghihi|manghihi|pagkaka)/, '');
        clean = clean.replace(/^(nagsasa|magsasa|nagpa|magpa|pinag|ipag|nagsi|magsi|naga|maga)/, '');
        clean = clean.replace(/^(ipina|pinag|pina|hina|tina|bina|sina|kinal|napa|papa)/, '');
        clean = clean.replace(/^(pang|mang|pam|mam|pan|man|pag|nag|mag|umu|uma|ina|nam|pum)/, '');

        if (clean.length >= 6 && clean.slice(0, 2) === clean.slice(2, 4)) {
            clean = clean.slice(2);
        }

        if (clean.startsWith('naksak')) clean = clean.replace('naksak', 'saksak');
        if (clean.startsWith('maril')) clean = clean.replace('maril', 'baril');
        if (clean.startsWith('nuntok')) clean = clean.replace('nuntok', 'suntok');

        clean = clean.replace(/(an|han|in|hin|hon|on)$/, '');

        // 2. English Suffixes
        clean = clean.replace(/(ing|ed|ers|er|es|s)$/, '');

        return clean;
    }

    function matchesRootList(token, rootList) {
        if (token.length < 3) {
            return null;
        }

        const stem = extractStem(token);

        for (const root of rootList) {
            if (token === root || stem === root) {
                return root;
            }

            if (token.includes(root) || stem.includes(root)) {
                return root;
            }

            if (root.length >= 5 && Math.abs(stem.length - root.length) <= 1) {
                if (getLevenshteinDistance(stem, root) <= 1) {
                    return root;
                }
            }
        }
        return null;
    }

    function setupLiveTriage() {
        const narrativeInput = document.getElementById('incidentNarrative');
        const categorySelect = document.getElementById('incidentCategory');
        const prioritySelect = document.getElementById('priorityLevel');
        const triageHint = document.getElementById('aiTriageHint');

        const downgradeModal = document.getElementById('downgradeModal');
        const targetPriorityLabel = document.getElementById('targetPriorityLabel');
        const popupReasonSelect = document.getElementById('popupOverrideReason');
        const popupCustomGroup = document.getElementById('popupCustomReasonGroup');
        const popupCustomText = document.getElementById('popupCustomReasonText');
        const btnConfirmDowngrade = document.getElementById('btnConfirmDowngrade');
        const btnCancelDowngrade = document.getElementById('btnCancelDowngrade');

        if (!narrativeInput || !prioritySelect) return;

        let triageDebounce;

        const evaluateTriage = () => {
            const rawNarrative = narrativeInput.value.trim();
            const categoryVal = categorySelect?.value || '';

            if (rawNarrative.length < 3) {
                if (triageHint) triageHint.innerText = '';
                window.currentAiSuggested = 'Low';
                prioritySelect.value = 'Low';
                return;
            }

            const tokens = rawNarrative.toLowerCase()
                .replace(/[^a-zA-ZñÑ0-9\s-]/g, ' ')
                .split(/\s+/)
                .filter(word => word.length >= 3 && !STOP_WORDS.has(word));

            let matchedCritical = [];
            let matchedHigh = [];

            tokens.forEach(token => {
                const critMatch = matchesRootList(token, THREAT_ROOTS.critical);
                if (critMatch && !matchedCritical.includes(critMatch)) {
                    matchedCritical.push(critMatch);
                }

                const highMatch = matchesRootList(token, THREAT_ROOTS.high);
                if (highMatch && !matchedHigh.includes(highMatch)) {
                    matchedHigh.push(highMatch);
                }
            });

            let suggested = 'Low';
            const allMatches = Array.from(new Set([...matchedCritical, ...matchedHigh]));

            if (matchedCritical.length > 0) {
                suggested = 'Critical';
            } else if (matchedHigh.length > 0 || categoryVal === 'Physical Altercation' || categoryVal === 'Theft') {
                suggested = 'High';
            }

            window.currentAiSuggested = suggested;
            prioritySelect.value = suggested;

            if (triageHint) {
                if (allMatches.length > 0) {
                    triageHint.innerHTML = `⚡ AI Detected Actions (${allMatches.join(', ')}): <strong>${suggested}</strong>`;
                } else if (categoryVal === 'Physical Altercation' || categoryVal === 'Theft') {
                    triageHint.innerHTML = `⚡ AI Category Assessment: <strong>${suggested}</strong>`;
                } else {
                    triageHint.innerHTML = `⚡ AI Suggested: <strong>${suggested}</strong> (Standard Monitoring)`;
                }
            }
        };

        narrativeInput.addEventListener('input', () => {
            clearTimeout(triageDebounce);
            triageDebounce = setTimeout(evaluateTriage, 180);
        });

        if (categorySelect) {
            categorySelect.addEventListener('change', evaluateTriage);
        }

        prioritySelect.addEventListener('change', () => {
            const chosen = prioritySelect.value;

            if (window.currentAiSuggested === 'Critical' && chosen !== 'Critical') {
                pendingPriorityChange = chosen;
                if (targetPriorityLabel) targetPriorityLabel.innerText = chosen;

                if (popupReasonSelect) popupReasonSelect.value = '';
                if (popupCustomText) popupCustomText.value = '';
                if (popupCustomGroup) popupCustomGroup.style.display = 'none';

                if (downgradeModal) downgradeModal.style.display = 'flex';
            } else {
                window.savedOverrideJustification = '';
            }
        });

        if (popupReasonSelect && popupCustomGroup) {
            popupReasonSelect.addEventListener('change', () => {
                popupCustomGroup.style.display = popupReasonSelect.value === 'Other' ? 'block' : 'none';
            });
        }

        if (btnCancelDowngrade) {
            btnCancelDowngrade.addEventListener('click', () => {
                prioritySelect.value = 'Critical';
                window.savedOverrideJustification = '';
                if (downgradeModal) downgradeModal.style.display = 'none';
            });
        }

        if (btnConfirmDowngrade) {
            btnConfirmDowngrade.addEventListener('click', () => {
                const standardReason = popupReasonSelect?.value || '';
                const customReason = popupCustomText?.value.trim() || '';
                const finalReason = standardReason === 'Other' ? customReason : standardReason;

                if (!finalReason) {
                    alert('Please select or specify a reason before proceeding.');
                    return;
                }

                window.savedOverrideJustification = finalReason;
                prioritySelect.value = pendingPriorityChange;
                if (downgradeModal) downgradeModal.style.display = 'none';
            });
        }
    }

    setupLiveTriage();
    loadDashboardData();
}); 