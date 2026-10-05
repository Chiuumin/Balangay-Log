const adminApi = 'api/admin_console.php';
const pageNotice = document.getElementById('pageNotice');
const navLinks = [...document.querySelectorAll('.admin-nav-link')];
const viewTitles = {
    overview: 'System Overview',
    users: 'User Management',
    parameters: 'System Parameters',
    equipment: 'Equipment & Inventory',
    audit: 'System Audit Logs',
    settings: 'Settings'
};
const state = {
    users: [],
    pendingUsers: [],
    logs: [],
    puroks: [],
    categories: [],
    equipment: [],
    officials: [],
    userFilter: 'pending',
    selectedResident: null
};

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
}[character]));
const formatDate = (value, options = { year: 'numeric', month: 'short', day: 'numeric' }) => value
    ? new Date(String(value).replace(' ', 'T')).toLocaleDateString(undefined, options)
    : '—';
const formatDateTime = (value) => value
    ? new Date(String(value).replace(' ', 'T')).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
    : '—';
const statusLabel = (value) => String(value || 'UNKNOWN').replaceAll('_', ' ').toLowerCase().replace(/\b\w/g, (letter) => letter.toUpperCase());
const categoryLabel = (value) => ({ PATROL_EQUIPMENT: 'Patrol equipment', COMMUNICATION: 'Radios / communication', VEHICLE: 'Patrol vehicle' }[value] || statusLabel(value));
const statusPill = (value) => `<span class="pill pill-${escapeHtml(String(value || '').toLowerCase())}">${escapeHtml(statusLabel(value))}</span>`;

function showNotice(message, isError = false) {
    pageNotice.textContent = message;
    pageNotice.classList.toggle('is-error', isError);
    pageNotice.hidden = false;
    window.clearTimeout(showNotice.timeout);
    showNotice.timeout = window.setTimeout(() => { pageNotice.hidden = true; }, 4500);
}

async function requestAdmin(action, payload = {}, options = {}) {
    const headers = { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content };
    if (!options.formData) headers['Content-Type'] = 'application/json';
    const response = await fetch(adminApi, {
        method: action ? 'POST' : 'GET',
        credentials: 'same-origin',
        headers,
        body: action ? (options.formData || JSON.stringify({ action, ...payload })) : undefined,
    });
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'The request could not be completed.');
    return result;
}

function setView(view) {
    if (!viewTitles[view]) return;
    navLinks.forEach((link) => link.classList.toggle('is-active', link.dataset.view === view));
    document.querySelectorAll('.admin-view').forEach((section) => { section.hidden = section.id !== `view-${view}`; });
    document.getElementById('viewTitle').textContent = viewTitles[view];
    history.replaceState(null, '', `#${view}`);
}

function renderOverview(data) {
    const metrics = data.overview || {};
    document.getElementById('metricPending').textContent = metrics.pending_residents ?? 0;
    document.getElementById('metricResidents').textContent = metrics.residents ?? 0;
    document.getElementById('metricOfficials').textContent = metrics.officials ?? 0;
    document.getElementById('metricActive').textContent = metrics.active_users ?? 0;
    document.getElementById('metricEquipment').textContent = metrics.equipment_in_use ?? 0;

    const rows = document.getElementById('overviewPendingRows');
    rows.innerHTML = state.pendingUsers.map((user) => `<tr>
        <td><strong>${escapeHtml(user.full_name)}</strong><span class="table-secondary">${escapeHtml(user.username || '')}</span></td>
        <td>${escapeHtml(user.contact_number || '—')}</td>
        <td>${escapeHtml(user.purok || '—')}</td>
        <td>${escapeHtml(formatDate(user.created_at))}</td>
        <td>${statusPill(user.status || user.authorization_status)}</td>
        <td><div class="row-actions"><button class="small-button" data-view-resident="${user.id}">Review</button></div></td>
    </tr>`).join('');
    document.getElementById('overviewPendingEmpty').hidden = state.pendingUsers.length > 0;
}

function userMatches(user, search) {
    if (!search) return true;
    return [user.full_name, user.username, user.user_type, user.contact_number, user.purok, user.status, user.authorization_status]
        .some((value) => String(value || '').toLowerCase().includes(search));
}

function renderUsers() {
    const search = document.getElementById('userSearch').value.trim().toLowerCase();
    let users = state.users.filter((user) => userMatches(user, search));
    if (state.userFilter === 'pending') users = users.filter((user) => user.user_type === 'RESIDENT' && ['PENDING', 'PENDING_VERIFICATION'].includes(user.status) || user.user_type === 'RESIDENT' && ['PENDING', 'PENDING_VERIFICATION'].includes(user.authorization_status));
    if (state.userFilter === 'officials') users = users.filter((user) => ['OFFICER', 'CAPTAIN'].includes(user.user_type));

    const head = document.getElementById('userTableHead');
    const rows = document.getElementById('userTableRows');
    head.innerHTML = `<tr><th>Name</th><th>Role</th><th>Status</th><th>Contact</th><th>Date registered</th><th></th></tr>`;
    rows.innerHTML = users.map((user) => {
        const isPending = user.user_type === 'RESIDENT' && ['PENDING', 'PENDING_VERIFICATION'].includes(user.status || user.authorization_status);
        const canManageOfficial = ['OFFICER', 'CAPTAIN'].includes(user.user_type);
        const accountStatus = user.status || user.authorization_status;
        let actions = '';
        if (isPending) actions = `<button class="small-button" data-view-resident="${user.id}">View</button>`;
        else if (canManageOfficial) actions = `<button class="small-button" data-edit-official="${user.id}">Edit</button><button class="small-button" data-reset-password="${user.id}">Reset password</button><button class="small-button ${accountStatus === 'ACTIVE' ? 'is-danger' : ''}" data-toggle-account="${user.id}" data-next-status="${accountStatus === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'}">${accountStatus === 'ACTIVE' ? 'Deactivate' : 'Activate'}</button>`;
        else if (user.user_type === 'RESIDENT' && ['ACTIVE', 'AUTHORIZED'].includes(accountStatus)) actions = `<button class="small-button is-danger" data-toggle-account="${user.id}" data-next-status="INACTIVE">Flag / deactivate</button>`;
        return `<tr>
            <td><strong>${escapeHtml(user.full_name)}</strong><span class="table-secondary">${escapeHtml(user.username || '')}</span></td>
            <td>${escapeHtml(statusLabel(user.user_type))}</td>
            <td>${statusPill(accountStatus)}</td>
            <td>${escapeHtml(user.contact_number || user.email_or_phone || '—')}</td>
            <td>${escapeHtml(formatDate(user.created_at))}</td>
            <td><div class="row-actions">${actions}</div></td>
        </tr>`;
    }).join('');
    document.getElementById('usersEmpty').hidden = users.length > 0;
}

function renderParameters(type, items) {
    const target = document.getElementById(type === 'purok' ? 'purokList' : 'categoryList');
    target.innerHTML = items.map((item) => `<div class="parameter-row ${Number(item.is_active) ? '' : 'is-inactive'}">
        <input aria-label="${type === 'purok' ? 'Purok' : 'Category'} name" value="${escapeHtml(item.name)}" data-parameter-name="${item.id}" data-parameter-type="${type}" ${Number(item.is_active) ? '' : 'disabled'}>
        ${Number(item.usage_count) ? `<span class="table-secondary">${item.usage_count} cases</span>` : '<span></span>'}
        <div class="row-actions">${Number(item.is_active) ? `<button class="small-button" data-save-parameter="${item.id}" data-type="${type}">Save</button><button class="small-button is-danger" data-deactivate-parameter="${item.id}" data-type="${type}">Deactivate</button>` : statusPill('INACTIVE')}</div>
    </div>`).join('') || '<p class="empty-message">No active values.</p>';
}

function renderEquipment() {
    const search = document.getElementById('equipmentSearch').value.trim().toLowerCase();
    const category = document.getElementById('equipmentCategoryFilter').value;
    const equipment = state.equipment.filter((item) => (!category || item.category === category) && [item.name, item.serial_number, item.assigned_name, item.plate_number].some((value) => String(value || '').toLowerCase().includes(search)));
    const rows = document.getElementById('equipmentRows');
    rows.innerHTML = equipment.map((item) => {
        const assignment = item.assigned_name ? escapeHtml(item.assigned_name) : '—';
        const select = state.officials.length ? `<select class="assign-select" aria-label="Assign ${escapeHtml(item.name)}" data-assignee-for="${item.id}"><option value="">Assign to…</option>${state.officials.map((official) => `<option value="${official.id}" ${String(item.assigned_user_id) === String(official.id) ? 'selected' : ''}>${escapeHtml(official.full_name)}</option>`).join('')}</select>` : '';
        return `<tr>
            <td><strong>${escapeHtml(item.name)}</strong>${item.category === 'VEHICLE' && item.vehicle_type ? `<span class="table-secondary">${escapeHtml(item.vehicle_type)}</span>` : ''}</td>
            <td>${escapeHtml(categoryLabel(item.category))}<span class="table-secondary">${escapeHtml(item.category === 'VEHICLE' ? item.plate_number || 'No plate' : item.serial_number || 'No ID')}</span></td>
            <td>${escapeHtml(item.quantity)}</td><td>${escapeHtml(item.condition_label)}</td><td>${statusPill(item.status)}</td>
            <td>${assignment}${!item.assigned_user_id ? `<div class="assignment-control">${select}<button class="small-button" data-assign-equipment="${item.id}">Assign</button></div>` : ''}</td>
            <td>${escapeHtml(formatDate(item.acquired_at))}</td>
            <td><div class="row-actions"><button class="small-button" data-edit-equipment="${item.id}">Edit</button>${item.assigned_user_id ? `<button class="small-button" data-return-equipment="${item.id}">Return</button>` : ''}<button class="small-button" data-equipment-history="${item.id}">History</button></div></td>
        </tr>`;
    }).join('');
    document.getElementById('equipmentEmpty').hidden = equipment.length > 0;
}

function renderAudit() {
    const search = document.getElementById('auditSearch').value.trim().toLowerCase();
    const action = document.getElementById('auditActionFilter').value;
    const userId = document.getElementById('auditUserFilter')?.value || '';
    const from = document.getElementById('auditFrom').value;
    const to = document.getElementById('auditTo').value;
    const rows = state.logs.filter((log) => {
        const text = [log.actor_name, log.target_name, log.action, log.details].join(' ').toLowerCase();
        return (!search || text.includes(search)) && (!action || log.action === action) && (!userId || String(log.actor_user_id) === userId || String(log.target_user_id) === userId) && (!from || String(log.created_at).slice(0, 10) >= from) && (!to || String(log.created_at).slice(0, 10) <= to);
    });
    document.getElementById('auditRows').innerHTML = rows.map((log) => {
        let details = log.details || '';
        try { details = Object.entries(JSON.parse(details)).map(([key, value]) => `${statusLabel(key)}: ${value}`).join(' · '); } catch { /* Keep plain-text details. */ }
        return `<tr><td>${escapeHtml(formatDateTime(log.created_at))}</td><td>${escapeHtml(log.actor_name || 'System')}<span class="table-secondary">${log.target_name ? `Target: ${escapeHtml(log.target_name)}` : ''}</span></td><td><strong>${escapeHtml(statusLabel(log.action))}</strong></td><td>${escapeHtml(details || '—')}</td></tr>`;
    }).join('');
    document.getElementById('auditEmpty').hidden = rows.length > 0;
}

function renderAuditFilters() {
    const actionSelect = document.getElementById('auditActionFilter');
    const currentAction = actionSelect.value;
    const actions = [...new Set(state.logs.map((log) => log.action))].sort();
    actionSelect.innerHTML = '<option value="">All actions</option>' + actions.map((action) => `<option value="${escapeHtml(action)}">${escapeHtml(statusLabel(action))}</option>`).join('');
    actionSelect.value = currentAction;

    const userSelect = document.getElementById('auditUserFilter');
    if (!userSelect) return;
    const currentUser = userSelect.value;
    const loggedUsers = state.users.filter((user) => state.logs.some((log) => String(log.actor_user_id) === String(user.id) || String(log.target_user_id) === String(user.id)));
    userSelect.innerHTML = '<option value="">All users</option>' + loggedUsers.map((user) => `<option value="${user.id}">${escapeHtml(user.full_name)}</option>`).join('');
    userSelect.value = currentUser;
}

async function refreshData() {
    try {
        const data = await requestAdmin();
        state.users = data.users || [];
        state.pendingUsers = data.pending_users || [];
        state.logs = data.logs || [];
        state.puroks = data.puroks || [];
        state.categories = data.categories || [];
        state.equipment = data.equipment || [];
        state.officials = data.officials_list || [];
        renderOverview(data);
        renderUsers();
        renderParameters('purok', state.puroks);
        renderParameters('category', state.categories);
        renderEquipment();
        renderAuditFilters();
        renderAudit();
        if (data.admin) {
            document.getElementById('settingsName').value = data.admin.full_name || '';
            document.getElementById('settingsContact').value = data.admin.contact_number || '';
            document.getElementById('adminName').textContent = data.admin.full_name || 'System Administrator';
            const avatar = document.getElementById('adminAvatar');
            const initials = (data.admin.full_name || 'AD').split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0].toUpperCase()).join('');
            avatar.firstChild.textContent = initials;
            const profilePicture = document.getElementById('adminProfilePicture');
            profilePicture.hidden = !data.admin.profile_picture;
            if (data.admin.profile_picture) profilePicture.src = data.admin.profile_picture;
        }
    } catch (error) {
        showNotice(error.message, true);
    }
}

function openResident(userId) {
    const user = state.users.find((item) => Number(item.id) === Number(userId));
    if (!user) return;
    state.selectedResident = user;
    const proof = user.proof_of_residency
        ? `<a href="api/view_resident_proof.php?id=${encodeURIComponent(user.id)}" target="_blank" rel="noopener">Open submitted proof</a>`
        : 'No file submitted';
    const fields = [
        ['Full name', user.full_name], ['Username', user.username], ['Date of birth', formatDate(user.date_of_birth)],
        ['Contact number', user.contact_number], ['Purok', user.purok], ['Address', user.address],
        ['Registration date', formatDateTime(user.created_at)], ['Verification status', statusLabel(user.status || user.authorization_status)],
        ['Submitted ID / proof', proof],
    ];
    document.getElementById('residentDetailContent').innerHTML = fields.map(([label, value]) => `<div class="detail-field ${label === 'Address' || label === 'Submitted ID / proof' ? 'detail-field--wide' : ''}"><span>${escapeHtml(label)}</span><strong>${label === 'Submitted ID / proof' ? value : escapeHtml(value || '—')}</strong></div>`).join('');
    document.getElementById('detailDialog').showModal();
}

function openOfficial(user, isEdit = false) {
    const form = document.getElementById('officialForm');
    form.reset();
    form.elements.id.value = isEdit ? user.id : '';
    form.elements.full_name.value = isEdit ? user.full_name || '' : '';
    form.elements.username.value = isEdit ? user.username || '' : '';
    form.elements.contact_number.value = isEdit ? user.contact_number || '' : '';
    form.elements.role.value = isEdit ? user.user_type : 'OFFICER';
    form.elements.role.disabled = isEdit;
    form.elements.password.required = !isEdit;
    form.elements.password.value = '';
    document.getElementById('officialDialogTitle').textContent = isEdit ? 'Edit Official Account' : 'Add Officer / Captain';
    document.getElementById('temporaryPasswordLabel').hidden = isEdit;
    document.getElementById('accountDialogHelp').hidden = isEdit;
    document.getElementById('accountDialog').showModal();
}

function openEquipment(item = null) {
    const form = document.getElementById('equipmentForm');
    form.reset();
    form.elements.id.value = item?.id || '';
    form.elements.name.value = item?.name || '';
    form.elements.category.value = item?.category || 'PATROL_EQUIPMENT';
    form.elements.serial_number.value = item?.serial_number || '';
    form.elements.plate_number.value = item?.plate_number || '';
    form.elements.quantity.value = item?.quantity || 1;
    form.elements.condition_label.value = item?.condition_label || 'Good';
    form.elements.status.value = item?.status || 'AVAILABLE';
    form.elements.acquired_at.value = item?.acquired_at || '';
    form.elements.vehicle_type.value = item?.vehicle_type || '';
    form.elements.notes.value = item?.notes || '';
    updateEquipmentFields(form.elements.category.value);
    document.getElementById('equipmentDialogTitle').textContent = item ? 'Edit equipment' : 'Add equipment';
    document.getElementById('equipmentDialog').showModal();
}

function updateEquipmentFields(category) {
    const isVehicle = category === 'VEHICLE';
    document.querySelectorAll('#equipmentForm .vehicle-field').forEach((field) => { field.hidden = !isVehicle; });
    document.querySelector('#equipmentForm .serial-field').hidden = isVehicle;
}

function openReasonDialog(action, id, title, status = '') {
    const form = document.getElementById('reasonForm');
    form.reset();
    form.elements.action.value = action;
    form.elements.id.value = id;
    form.elements.status.value = status;
    document.getElementById('reasonDialogTitle').textContent = title;
    document.getElementById('reasonDialog').showModal();
}

navLinks.forEach((link) => link.addEventListener('click', () => setView(link.dataset.view)));
document.querySelectorAll('[data-open-view]').forEach((button) => button.addEventListener('click', () => {
    state.userFilter = 'pending';
    document.querySelectorAll('[data-user-filter]').forEach((tab) => tab.classList.toggle('is-selected', tab.dataset.userFilter === 'pending'));
    renderUsers();
    setView(button.dataset.openView);
}));
document.querySelectorAll('[data-refresh]').forEach((button) => button.addEventListener('click', refreshData));
document.querySelectorAll('[data-close-dialog]').forEach((button) => button.addEventListener('click', () => button.closest('dialog').close()));
document.querySelectorAll('dialog').forEach((dialog) => dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); }));
document.querySelectorAll('[data-user-filter]').forEach((button) => button.addEventListener('click', () => {
    state.userFilter = button.dataset.userFilter;
    document.querySelectorAll('[data-user-filter]').forEach((tab) => tab.classList.toggle('is-selected', tab === button));
    renderUsers();
}));
document.getElementById('userSearch').addEventListener('input', renderUsers);
document.getElementById('equipmentSearch').addEventListener('input', renderEquipment);
document.getElementById('equipmentCategoryFilter').addEventListener('change', renderEquipment);
document.getElementById('auditFilterForm').addEventListener('submit', (event) => { event.preventDefault(); renderAudit(); });
['auditSearch', 'auditActionFilter', 'auditUserFilter', 'auditFrom', 'auditTo'].forEach((id) => document.getElementById(id)?.addEventListener(id === 'auditSearch' ? 'input' : 'change', renderAudit));

document.getElementById('addOfficialBtn').addEventListener('click', () => openOfficial(null));
document.getElementById('addEquipmentBtn').addEventListener('click', () => openEquipment());
document.querySelector('#equipmentForm [name="category"]').addEventListener('change', (event) => updateEquipmentFields(event.currentTarget.value));
document.getElementById('approveResidentBtn').addEventListener('click', async () => {
    if (!state.selectedResident || !window.confirm(`Approve ${state.selectedResident.full_name}?`)) return;
    try { const result = await requestAdmin('resident_approve', { id: state.selectedResident.id }); document.getElementById('detailDialog').close(); showNotice(result.message); await refreshData(); }
    catch (error) { showNotice(error.message, true); }
});
document.getElementById('rejectResidentBtn').addEventListener('click', () => {
    if (!state.selectedResident) return;
    document.getElementById('detailDialog').close();
    openReasonDialog('resident_reject', state.selectedResident.id, 'Reject resident registration');
});

document.getElementById('officialForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const editing = Boolean(form.elements.id.value);
    const payload = Object.fromEntries(new FormData(form).entries());
    try {
        const result = await requestAdmin(editing ? 'official_update' : 'official_create', payload);
        document.getElementById('accountDialog').close(); showNotice(result.message); await refreshData();
    } catch (error) { showNotice(error.message, true); }
});
document.getElementById('equipmentForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const payload = Object.fromEntries(new FormData(event.currentTarget).entries());
    try { const result = await requestAdmin('equipment_save', payload); document.getElementById('equipmentDialog').close(); showNotice(result.message); await refreshData(); }
    catch (error) { showNotice(error.message, true); }
});
document.getElementById('reasonForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const payload = Object.fromEntries(new FormData(event.currentTarget).entries());
    const action = payload.action; delete payload.action;
    try { const result = await requestAdmin(action, payload); document.getElementById('reasonDialog').close(); showNotice(result.message); await refreshData(); }
    catch (error) { showNotice(error.message, true); }
});

async function addParameter(type, form) {
    const name = new FormData(form).get('name')?.toString().trim();
    if (!name) return;
    try { const result = await requestAdmin('parameter_add', { type, name }); form.reset(); showNotice(result.message); await refreshData(); }
    catch (error) { showNotice(error.message, true); }
}
document.getElementById('addPurokForm').addEventListener('submit', (event) => { event.preventDefault(); addParameter('purok', event.currentTarget); });
document.getElementById('addCategoryForm').addEventListener('submit', (event) => { event.preventDefault(); addParameter('category', event.currentTarget); });

document.addEventListener('click', async (event) => {
    const target = event.target.closest('button');
    if (!target) return;
    try {
        if (target.dataset.viewResident) openResident(target.dataset.viewResident);
        if (target.dataset.editOfficial) {
            const user = state.users.find((item) => Number(item.id) === Number(target.dataset.editOfficial));
            if (user) openOfficial(user, true);
        }
        if (target.dataset.resetPassword) {
            const user = state.users.find((item) => Number(item.id) === Number(target.dataset.resetPassword));
            const password = window.prompt(`Set a temporary password for ${user?.full_name || 'this account'} (at least 8 characters):`);
            if (password === null) return;
            const result = await requestAdmin('password_reset', { id: target.dataset.resetPassword, password });
            showNotice(result.message); await refreshData();
        }
        if (target.dataset.toggleAccount) openReasonDialog('user_set_status', target.dataset.toggleAccount, target.dataset.nextStatus === 'INACTIVE' ? 'Deactivate / flag account' : 'Reactivate account', target.dataset.nextStatus);
        if (target.dataset.saveParameter) {
            const id = target.dataset.saveParameter;
            const name = document.querySelector(`[data-parameter-name="${id}"]`).value.trim();
            const result = await requestAdmin('parameter_update', { id, type: target.dataset.type, name }); showNotice(result.message); await refreshData();
        }
        if (target.dataset.deactivateParameter && window.confirm('Deactivate this value? Existing cases will keep their saved value.')) {
            const result = await requestAdmin('parameter_deactivate', { id: target.dataset.deactivateParameter, type: target.dataset.type }); showNotice(result.message); await refreshData();
        }
        if (target.dataset.editEquipment) openEquipment(state.equipment.find((item) => Number(item.id) === Number(target.dataset.editEquipment)));
        if (target.dataset.assignEquipment) {
            const itemId = target.dataset.assignEquipment;
            const assignedUserId = document.querySelector(`[data-assignee-for="${itemId}"]`)?.value;
            const result = await requestAdmin('equipment_assign', { id: itemId, assigned_user_id: assignedUserId }); showNotice(result.message); await refreshData();
        }
        if (target.dataset.returnEquipment && window.confirm('Mark this item as returned and available?')) {
            const result = await requestAdmin('equipment_return', { id: target.dataset.returnEquipment }); showNotice(result.message); await refreshData();
        }
        if (target.dataset.equipmentHistory) {
            const result = await requestAdmin('equipment_history', { id: target.dataset.equipmentHistory });
            document.getElementById('equipmentHistoryRows').innerHTML = result.history.map((entry) => `<div class="history-entry"><strong>${escapeHtml(statusLabel(entry.action))}</strong><small>${escapeHtml(formatDateTime(entry.created_at))} · ${escapeHtml(entry.actor_name || 'System')}</small><p>${escapeHtml(entry.details || '—')}</p></div>`).join('') || '<p class="empty-message">No history recorded.</p>';
            document.getElementById('historyDialog').showModal();
        }
    } catch (error) { showNotice(error.message, true); }
});

document.getElementById('profileForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(event.currentTarget);
    formData.set('action', 'profile_update');
    try { const result = await requestAdmin('profile_update', {}, { formData }); showNotice(result.message); await refreshData(); }
    catch (error) { showNotice(error.message, true); }
});
document.getElementById('passwordForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const payload = Object.fromEntries(new FormData(event.currentTarget).entries());
    try { const result = await requestAdmin('password_update', payload); event.currentTarget.reset(); showNotice(result.message); }
    catch (error) { showNotice(error.message, true); }
});

document.getElementById('logoutBtn').addEventListener('click', async () => {
    try { await fetch('api/logout.php', { method: 'POST', credentials: 'same-origin' }); } catch { /* Clear local session even if logout logging fails. */ }
    sessionStorage.clear();
    localStorage.removeItem('currentUser');
    localStorage.removeItem('authToken');
    window.location.replace('index.html');
});

document.getElementById('todayLabel').textContent = new Date().toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
const initialView = location.hash.slice(1);
if (viewTitles[initialView]) setView(initialView);
refreshData();
