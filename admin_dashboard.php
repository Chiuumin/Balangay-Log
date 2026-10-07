<?php
require_once __DIR__ . '/auth.php';
requireRole(['SYSTEM_ADMIN', 'ADMIN']);
$currentUser = getCurrentUser();
$adminName = htmlspecialchars((string) ($currentUser['full_name'] ?? 'System Administrator'), ENT_QUOTES, 'UTF-8');
$csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
	<title>System Administration | BalangayLog</title>
	<link rel="stylesheet" href="admin-dashboard.css">
</head>
<body>
	<div class="admin-shell">
		<aside class="admin-sidebar">
			<a class="admin-brand" href="admin_dashboard.php" aria-label="BalangayLog System Overview">
				<span class="admin-brand-mark">B</span>
				<span><strong>BalangayLog</strong><small>BARANGAY DUALE</small></span>
			</a>

			<div class="admin-identity">
				<span class="identity-avatar" id="adminAvatar">AD<img id="adminProfilePicture" alt="" hidden></span>
				<span><strong id="adminName"><?= $adminName ?></strong><small>System Administrator</small></span>
			</div>

			<nav class="admin-nav" aria-label="System administration">
				<button class="admin-nav-link is-active" type="button" data-view="overview"><span class="nav-symbol">▦</span>System Overview</button>
				<button class="admin-nav-link" type="button" data-view="users"><span class="nav-symbol">♙</span>User Management</button>
				<button class="admin-nav-link" type="button" data-view="parameters"><span class="nav-symbol">☷</span>System Parameters</button>
				<button class="admin-nav-link" type="button" data-view="equipment"><span class="nav-symbol">▣</span>Equipment &amp; Inventory</button>
				<button class="admin-nav-link" type="button" data-view="audit"><span class="nav-symbol">◷</span>System Audit Logs</button>
				<button class="admin-nav-link" type="button" data-view="settings"><span class="nav-symbol">⚙</span>Settings</button>
			</nav>

			<div class="sidebar-bottom">
				<button class="logout-link" id="logoutBtn" type="button">Log out</button>
			</div>
		</aside>

		<main class="admin-main">
			<header class="admin-topbar">
				<div>
					<p class="eyebrow">SYSTEM ADMINISTRATION</p>
					<h1 id="viewTitle">System Overview</h1>
				</div>
				<div class="topbar-meta"><span class="status-indicator"></span>System online <time id="todayLabel"></time></div>
			</header>

			<div class="notice" id="pageNotice" role="status" hidden></div>

			<section class="admin-view" id="view-overview">
				<div class="section-heading"><div><h2>Barangay system at a glance</h2><p>Live account and equipment totals.</p></div><button type="button" class="button button-quiet" data-refresh>Refresh data</button></div>
				<div class="metric-grid">
					<article class="metric-tile metric-coral"><span>Pending registrations</span><strong id="metricPending">—</strong><small>Awaiting review</small></article>
					<article class="metric-tile metric-green"><span>Total residents</span><strong id="metricResidents">—</strong><small>Registered resident accounts</small></article>
					<article class="metric-tile metric-blue"><span>Officers &amp; captains</span><strong id="metricOfficials">—</strong><small>Barangay personnel</small></article>
					<article class="metric-tile metric-gold"><span>Active users</span><strong id="metricActive">—</strong><small>Accounts currently enabled</small></article>
					<article class="metric-tile metric-slate"><span>Equipment in use</span><strong id="metricEquipment">—</strong><small>Assigned inventory items</small></article>
				</div>
				<div class="content-panel overview-panel">
					<div class="panel-heading"><div><h2>Resident registrations to review</h2><p>Review submitted details before approval.</p></div><button class="text-button" type="button" data-open-view="users">View all residents <span aria-hidden="true">→</span></button></div>
					<div class="table-scroll"><table><thead><tr><th>Resident</th><th>Contact</th><th>Purok</th><th>Registered</th><th>Status</th><th></th></tr></thead><tbody id="overviewPendingRows"></tbody></table></div>
					<p class="empty-message" id="overviewPendingEmpty" hidden>No resident accounts are waiting for review.</p>
				</div>
			</section>

			<section class="admin-view" id="view-users" hidden>
				<div class="section-heading"><div><h2>Manage access to BalangayLog</h2><p>Review resident sign-ups and maintain official accounts.</p></div><button type="button" class="button button-primary" id="addOfficialBtn">+ Add Officer / Captain</button></div>
				<div class="content-panel">
					<div class="toolbar">
						<div class="segmented" role="group" aria-label="User list type"><button type="button" class="segment is-selected" data-user-filter="pending">Pending residents</button><button type="button" class="segment" data-user-filter="all">All users</button><button type="button" class="segment" data-user-filter="officials">Officers &amp; captains</button></div>
						<label class="search-field"><span class="sr-only">Search users</span><input id="userSearch" type="search" placeholder="Search name, role, contact"></label>
					</div>
					<div class="table-scroll"><table><thead id="userTableHead"></thead><tbody id="userTableRows"></tbody></table></div>
					<p class="empty-message" id="usersEmpty" hidden>No accounts match this view.</p>
				</div>
			</section>

			<section class="admin-view" id="view-parameters" hidden>
				<div class="section-heading"><div><h2>System parameters</h2><p>Manage values available in resident and incident forms.</p></div></div>
				<div class="parameter-grid">
					<section class="content-panel parameter-panel"><div class="panel-heading"><div><h2>Puroks</h2><p>Neighborhoods available for case and resident records.</p></div></div><form class="inline-add" id="addPurokForm"><label class="sr-only" for="newPurok">New Purok name</label><input id="newPurok" name="name" placeholder="Purok name" required><button class="button button-primary" type="submit">Add Purok</button></form><div class="parameter-list" id="purokList"></div></section>
					<section class="content-panel parameter-panel"><div class="panel-heading"><div><h2>Incident categories</h2><p>Used categories are retained on existing case records.</p></div></div><form class="inline-add" id="addCategoryForm"><label class="sr-only" for="newCategory">New category name</label><input id="newCategory" name="name" placeholder="Category name" required><button class="button button-primary" type="submit">Add category</button></form><div class="parameter-list" id="categoryList"></div></section>
				</div>
			</section>

			<section class="admin-view" id="view-equipment" hidden>
				<div class="section-heading"><div><h2>Equipment &amp; inventory</h2><p>Track condition, assignment, and vehicle details.</p></div><button type="button" class="button button-primary" id="addEquipmentBtn">+ Add equipment</button></div>
				<div class="content-panel"><div class="toolbar"><label class="search-field"><span class="sr-only">Search equipment</span><input id="equipmentSearch" type="search" placeholder="Search item, serial or assignee"></label><label class="select-field"><span class="sr-only">Filter category</span><select id="equipmentCategoryFilter"><option value="">All categories</option><option value="PATROL_EQUIPMENT">Patrol equipment</option><option value="COMMUNICATION">Radios &amp; communication</option><option value="VEHICLE">Patrol vehicles</option></select></label></div><div class="table-scroll"><table><thead><tr><th>Item</th><th>Category / ID</th><th>Qty.</th><th>Condition</th><th>Status</th><th>Assigned to</th><th>Acquired</th><th></th></tr></thead><tbody id="equipmentRows"></tbody></table></div><p class="empty-message" id="equipmentEmpty" hidden>No equipment has been added yet.</p></div>
			</section>

			<section class="admin-view" id="view-audit" hidden>
				<div class="section-heading"><div><h2>System audit logs</h2><p>Read-only history of account and system changes.</p></div></div>
				<div class="content-panel"><form class="audit-filters" id="auditFilterForm"><label class="search-field"><span class="sr-only">Search audit logs</span><input id="auditSearch" type="search" placeholder="Search user, action or details"></label><select id="auditUserFilter" aria-label="Filter by user"><option value="">All users</option></select><select id="auditActionFilter" aria-label="Filter by action"><option value="">All actions</option></select><input id="auditFrom" type="date" aria-label="From date"><input id="auditTo" type="date" aria-label="To date"><button type="submit" class="button button-quiet">Filter</button></form><div class="table-scroll"><table><thead><tr><th>Date / time</th><th>User</th><th>Action</th><th>Details</th></tr></thead><tbody id="auditRows"></tbody></table></div><p class="empty-message" id="auditEmpty" hidden>No activity found for these filters.</p></div>
			</section>

			<section class="admin-view" id="view-settings" hidden>
				<div class="section-heading"><div><h2>Administrator settings</h2><p>Update your account details and password.</p></div></div>
				<div class="settings-grid">
					<form class="content-panel settings-form" id="profileForm" enctype="multipart/form-data"><h2>Account information</h2><p>These details are attached to your admin activity.</p><label>Full name<input name="full_name" id="settingsName" required></label><label>Contact number<input name="contact_number" id="settingsContact"></label><label>Profile picture (optional)<input name="profile_picture" type="file" accept="image/png,image/jpeg,image/webp"></label><button class="button button-primary" type="submit">Save account details</button></form>
					<form class="content-panel settings-form" id="passwordForm"><h2>Change password</h2><p>Choose a password you do not use elsewhere.</p><label>Current password<input name="current_password" type="password" autocomplete="current-password" required></label><label>New password<input name="new_password" type="password" minlength="8" autocomplete="new-password" required></label><label>Confirm new password<input name="confirm_password" type="password" minlength="8" autocomplete="new-password" required></label><button class="button button-primary" type="submit">Update password</button></form>
				</div>
			</section>
		</main>
	</div>

	<dialog class="admin-dialog" id="accountDialog"><form id="officialForm" method="dialog"><div class="dialog-heading"><div><p class="eyebrow">OFFICIAL ACCOUNT</p><h2 id="officialDialogTitle">Add Officer / Captain</h2></div><button class="icon-button" type="button" data-close-dialog aria-label="Close">×</button></div><input type="hidden" name="id"><label>Full name<input name="full_name" required></label><div class="form-row"><label>Position<select name="role" required><option value="OFFICER">Barangay Officer</option><option value="CAPTAIN">Barangay Captain</option></select></label><label>Contact number<input name="contact_number"></label></div><label>Username<input name="username" required autocomplete="off"></label><label id="temporaryPasswordLabel">Temporary password<input name="password" type="password" minlength="8" autocomplete="new-password"></label><p class="dialog-help" id="accountDialogHelp">The official will sign in with this temporary password.</p><div class="dialog-actions"><button class="button button-quiet" type="button" data-close-dialog>Cancel</button><button class="button button-primary" type="submit">Save account</button></div></form></dialog>

	<dialog class="admin-dialog" id="detailDialog"><div class="dialog-heading"><div><p class="eyebrow">ACCOUNT REVIEW</p><h2>Resident details</h2></div><button class="icon-button" type="button" data-close-dialog aria-label="Close">×</button></div><div id="residentDetailContent" class="detail-content"></div><div class="dialog-actions"><button class="button button-quiet" type="button" data-close-dialog>Close</button><button class="button button-danger" type="button" id="rejectResidentBtn">Reject</button><button class="button button-primary" type="button" id="approveResidentBtn">Approve</button></div></dialog>

	<dialog class="admin-dialog" id="equipmentDialog"><form id="equipmentForm" method="dialog"><div class="dialog-heading"><div><p class="eyebrow">INVENTORY</p><h2 id="equipmentDialogTitle">Add equipment</h2></div><button class="icon-button" type="button" data-close-dialog aria-label="Close">×</button></div><input type="hidden" name="id"><label>Item name<input name="name" required></label><div class="form-row"><label>Category<select name="category"><option value="PATROL_EQUIPMENT">Patrol equipment</option><option value="COMMUNICATION">Radios &amp; communication</option><option value="VEHICLE">Patrol vehicle</option></select></label><label class="serial-field">Item ID / serial number<input name="serial_number"></label><label class="vehicle-field" hidden>Plate number<input name="plate_number"></label></div><div class="form-row"><label>Quantity<input name="quantity" type="number" min="1" value="1" required></label><label>Condition<input name="condition_label" value="Good" required></label></div><div class="form-row"><label>Status<select name="status"><option value="AVAILABLE">Available</option><option value="IN_USE">In Use</option><option value="UNDER_MAINTENANCE">Under Maintenance</option><option value="DAMAGED">Damaged</option><option value="LOST">Lost</option></select></label><label>Date acquired<input name="acquired_at" type="date"></label></div><label class="vehicle-field" hidden>Vehicle type<input name="vehicle_type"></label><label>Notes<textarea name="notes" rows="3"></textarea></label><div class="dialog-actions"><button class="button button-quiet" type="button" data-close-dialog>Cancel</button><button class="button button-primary" type="submit">Save equipment</button></div></form></dialog>

	<dialog class="admin-dialog" id="reasonDialog"><form id="reasonForm" method="dialog"><div class="dialog-heading"><div><p class="eyebrow">REASON REQUIRED</p><h2 id="reasonDialogTitle">Add a reason</h2></div><button class="icon-button" type="button" data-close-dialog aria-label="Close">×</button></div><input type="hidden" name="action"><input type="hidden" name="id"><input type="hidden" name="status"><label>Reason<textarea name="reason" rows="4" required></textarea></label><div class="dialog-actions"><button class="button button-quiet" type="button" data-close-dialog>Cancel</button><button class="button button-danger" type="submit">Confirm</button></div></form></dialog>

	<dialog class="admin-dialog" id="historyDialog"><div class="dialog-heading"><div><p class="eyebrow">INVENTORY RECORD</p><h2>Equipment history</h2></div><button class="icon-button" type="button" data-close-dialog aria-label="Close">×</button></div><div id="equipmentHistoryRows" class="history-list"></div></dialog>
	<script src="admin-dashboard.js" defer></script>
</body>
</html>
