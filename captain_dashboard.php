<?php
require_once __DIR__ . '/auth.php';
include __DIR__ . '/views/settings_partial.php';
requireRole(['CAPTAIN']);
$currentUser = getCurrentUser();
$captainName = htmlspecialchars((string) ($currentUser['full_name'] ?? 'Barangay Captain'), ENT_QUOTES, 'UTF-8');
$csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
	<title>Executive Overview | BalangayLog</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="stylesheet" href="admin-dashboard.css">
	<link rel="stylesheet" href="captain-dashboard.css">
</head>
<body>
	<div class="admin-shell">
		<aside class="admin-sidebar">
			<a class="admin-brand" href="captain_dashboard.php" aria-label="BalangayLog Executive Overview">
				<span class="admin-brand-mark">B</span>
				<span><strong>BalangayLog</strong><small>BARANGAY DUALE</small></span>
			</a>
			<div class="admin-identity">
				<span class="identity-avatar" id="captainAvatar">CP<img id="captainPicture" alt="" hidden></span>
				<span><strong id="captainSideName">Capt. <?= $captainName ?></strong><small>Punong Barangay</small></span>
			</div>
			<nav class="admin-nav" aria-label="Captain navigation">
				<button class="admin-nav-link is-active" type="button" data-view="overview"><span class="nav-symbol">▦</span>Executive Overview</button>
				<button class="admin-nav-link" type="button" data-view="registry"><span class="nav-symbol">☰</span>Master Blotter Registry</button>
				<button class="admin-nav-link" type="button" data-view="endorsements"><span class="nav-symbol">✎</span>Awaiting Captain Endorsement <span class="nav-count" id="navEndorseCount" hidden>0</span></button>
				<button class="admin-nav-link" type="button" data-view="lupon"><span class="nav-symbol">⚖</span>Lupon / Mediation Registry</button>
				<button class="admin-nav-link" type="button" data-view="heatmap"><span class="nav-symbol">◉</span>Purok Incident Heatmaps</button>
				<button class="admin-nav-link" type="button" data-view="analytics"><span class="nav-symbol">◷</span>Analytics &amp; Official Reports</button>
			</nav>
			<div class="sidebar-bottom"><button class="logout-link" id="logoutBtn" type="button">Log out</button></div>
		</aside>

		<main class="admin-main">
			<header class="admin-topbar">
				<div><p class="eyebrow">EXECUTIVE OFFICE</p><h1 id="viewTitle">Executive Overview</h1></div>
				<div class="topbar-right">
					<div class="captain-badge"><strong id="captainName">Capt. <?= $captainName ?></strong><span class="role-badge">Punong Barangay</span></div>
					<button type="button" class="button button-primary" data-open-advisory>+ Issue Barangay Advisory</button>
				</div>
			</header>

			<div class="alert-banner" id="criticalBanner" role="alert" hidden>
				<span><strong id="criticalCount">0</strong> Critical Incidents Requiring Executive Notice</span>
				<button type="button" class="small-button" id="criticalReview">Review incidents</button>
			</div>
			<div class="notice" id="pageNotice" role="status" hidden></div>

			<section class="admin-view" id="view-overview">
				<div class="section-heading"><div><h2>Peace and order at a glance</h2><p>This month's incidents, mediation, and items waiting for your signature.</p></div><button type="button" class="button button-quiet" data-refresh>Refresh data</button></div>
				<div class="metric-grid metric-grid-4">
					<article class="metric-tile metric-blue"><span>Monthly incidents</span><strong id="metricIncidents">—</strong><small>Cases logged this month</small></article>
					<article class="metric-tile metric-gold"><span>Cases under mediation</span><strong id="metricMediation">—</strong><small>Active Lupon proceedings</small></article>
					<article class="metric-tile metric-coral"><span>Endorsements needed</span><strong id="metricEndorse">—</strong><small>Waiting for your signature</small></article>
					<article class="metric-tile metric-green"><span>Resolution rate</span><strong id="metricRate">—</strong><small>Resolved vs. filed this month</small></article>
				</div>

				<div class="content-panel">
					<div class="panel-heading"><div><h2>Cases awaiting endorsement</h2><p>Lupon certifications and PNP transmittals that need your formal approval.</p></div><button class="text-button" type="button" data-open-view="endorsements">View all →</button></div>
					<div class="table-scroll"><table><thead><tr><th>Case</th><th>Type</th><th>Purok</th><th>Referred by</th><th>Date referred</th><th></th></tr></thead><tbody id="overviewEndorseRows"></tbody></table></div>
					<p class="empty-message" id="overviewEndorseEmpty" hidden>Nothing is waiting for your signature.</p>
				</div>

				<div class="analytics-grid">
					<section class="content-panel"><div class="panel-heading"><div><h2>Incident trend</h2><p>Cases filed per month.</p></div></div><div id="trendChart" class="chart-box"></div></section>
					<section class="content-panel"><div class="panel-heading"><div><h2>Incidents by purok</h2><p>This month, highest first.</p></div></div><div id="purokBars" class="bar-list"></div></section>
					<section class="content-panel"><div class="panel-heading"><div><h2>Resolution rate</h2><p>Resolved cases this month.</p></div></div><div id="rateRing" class="rate-ring"></div></section>
				</div>

				<div class="content-panel">
					<div class="panel-heading"><div><h2>Quick actions</h2></div></div>
					<div class="quick-actions">
						<button type="button" class="button button-primary" data-open-advisory>Issue Barangay Advisory</button>
						<button type="button" class="button button-quiet" data-generate-report>Generate Monthly DILG Report</button>
						<button type="button" class="button button-quiet" data-open-view="lupon">Review Lupon Referrals</button>
					</div>
				</div>
			</section>

			<section class="admin-view" id="view-registry" hidden>
				<div class="section-heading"><div><h2>Master blotter registry</h2><p>Read-only list of every case in the barangay.</p></div></div>
				<div class="content-panel">
					<div class="toolbar">
						<label class="search-field"><span class="sr-only">Search cases</span><input id="caseSearch" type="search" placeholder="Search reference no., incident, purok, priority"></label>
						<label class="select-field"><span class="sr-only">Filter by status</span><select id="caseStatusFilter"><option value="">All statuses</option></select></label>
					</div>
					<div class="table-scroll"><table><thead><tr><th>Case no.</th><th>Incident</th><th>Purok</th><th>Priority</th><th>Status</th><th>Reported</th><th></th></tr></thead><tbody id="caseRows"></tbody></table></div>
					<p class="empty-message" id="caseEmpty" hidden>No cases match this view.</p>
				</div>
			</section>

			<section class="admin-view" id="view-endorsements" hidden>
				<div class="section-heading"><div><h2>Awaiting captain endorsement</h2><p>Pending Lupon certifications and PNP referrals.</p></div></div>
				<div class="content-panel">
					<div class="table-scroll"><table><thead><tr><th>Case</th><th>Type</th><th>Purok</th><th>Referred by</th><th>Date referred</th><th></th></tr></thead><tbody id="endorseRows"></tbody></table></div>
					<p class="empty-message" id="endorseEmpty" hidden>Nothing is waiting for your signature.</p>
				</div>
			</section>

			<section class="admin-view" id="view-lupon" hidden>
				<div class="section-heading"><div><h2>Lupon / mediation registry</h2><p>Cases referred to the Lupong Tagapamayapa and their outcomes.</p></div></div>
				<div class="content-panel">
					<div class="table-scroll"><table><thead><tr><th>Case</th><th>Parties</th><th>Purok</th><th>Stage</th><th>Next hearing</th><th>Status</th></tr></thead><tbody id="luponRows"></tbody></table></div>
					<p class="empty-message" id="luponEmpty" hidden>No cases are in mediation.</p>
				</div>
			</section>

			<section class="admin-view" id="view-heatmap" hidden>
				<div class="section-heading"><div><h2>Purok incident heatmap</h2><p>Darker tiles mean more incidents in the selected period.</p></div><label class="select-field"><span class="sr-only">Period</span><select id="heatPeriod"><option value="month">This month</option><option value="all">All time</option></select></label></div>
				<div class="content-panel"><div id="heatGrid" class="heat-grid"></div><p class="empty-message" id="heatEmpty" hidden>No incidents recorded for this period.</p></div>
			</section>

			<section class="admin-view" id="view-analytics" hidden>
				<div class="section-heading"><div><h2>Analytics &amp; official reports</h2><p>Trends over time and printable reports for the DILG.</p></div><button type="button" class="button button-primary" data-generate-report>Generate Monthly DILG Report</button></div>
				<div class="analytics-grid analytics-grid-2">
					<section class="content-panel"><div class="panel-heading"><div><h2>Incident trend</h2></div></div><div id="trendChart2" class="chart-box"></div></section>
					<section class="content-panel"><div class="panel-heading"><div><h2>Incidents by category</h2></div></div><div id="categoryBars" class="bar-list"></div></section>
				</div>
			</section>
		</main>
	</div>

	<dialog class="admin-dialog" id="advisoryDialog"><form id="advisoryForm" method="dialog"><div class="dialog-heading"><div><p class="eyebrow">BARANGAY ADVISORY</p><h2>Issue advisory</h2></div><button class="icon-button" type="button" data-close-dialog aria-label="Close">×</button></div><label>Title<input name="title" maxlength="120" required></label><label>Applies to<select name="purok_id" id="advisoryPurok"><option value="">Whole barangay</option></select></label><label>Message<textarea name="body" rows="5" required></textarea></label><div class="dialog-actions"><button class="button button-quiet" type="button" data-close-dialog>Cancel</button><button class="button button-primary" type="submit">Publish advisory</button></div></form></dialog>

	<dialog class="admin-dialog" id="endorseDialog"><form id="endorseForm" method="dialog"><div class="dialog-heading"><div><p class="eyebrow">FORMAL ENDORSEMENT</p><h2 id="endorseTitle">Endorse case</h2></div><button class="icon-button" type="button" data-close-dialog aria-label="Close">×</button></div><div id="endorseDetails" class="detail-content"></div><input type="hidden" name="id"><label>Remarks (optional)<textarea name="remarks" rows="3"></textarea></label><div class="dialog-actions"><button class="button button-quiet" type="button" data-close-dialog>Cancel</button><button class="button button-primary" type="submit" id="endorseSubmit">Endorse / Sign</button></div></form></dialog>

	<dialog class="admin-dialog" id="caseDialog"><div class="dialog-heading"><div><p class="eyebrow">CASE RECORD (READ-ONLY)</p><h2 id="caseDialogTitle">Case details</h2></div><button class="icon-button" type="button" data-close-dialog aria-label="Close">×</button></div><div id="caseDetails" class="detail-content"></div><div class="dialog-actions"><button class="button button-quiet" type="button" data-close-dialog>Close</button></div></dialog>

	<script src="captain-dashboard.js" defer></script>
</body>
</html>