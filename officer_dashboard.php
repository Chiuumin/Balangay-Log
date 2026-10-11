<?php
require_once __DIR__ . '/auth.php';
requireRole(['OFFICER', 'CAPTAIN']);

$pdo = getDatabaseConnection();
$userId = (int) ($_SESSION['user_id'] ?? 0);

$stmt = $pdo->prepare('SELECT full_name, user_type, profile_picture FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$userRecord = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$officerName = htmlspecialchars((string) ($userRecord['full_name'] ?? 'Barangay Officer'), ENT_QUOTES, 'UTF-8');
$roleLabel = ($userRecord['user_type'] ?? 'OFFICER') === 'CAPTAIN' ? 'Barangay Captain' : 'Desk Officer';
$profilePic = !empty($userRecord['profile_picture']) ? htmlspecialchars((string) $userRecord['profile_picture'], ENT_QUOTES, 'UTF-8') : null;

$csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

$nameParts = array_filter(explode(' ', trim($officerName)));
$initials = '';
foreach (array_slice($nameParts, 0, 2) as $p) {
    $initials .= strtoupper($p[0] ?? '');
}
if (!$initials) $initials = 'OF';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>Desk Operations | BalangayLog</title>
    <script>
        if (localStorage.getItem('balangay_theme') === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    </script>
    <link rel="stylesheet" href="admin-dashboard.css?v=<?= time() ?>" />
    <style>
        /* Red Critical Banner */
        .critical-alert-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 22px;
            background: #d61818;
            color: #ffffff;
            border-radius: 8px;
            margin-bottom: 22px;
            box-shadow: 0 4px 14px rgba(214, 24, 24, 0.22);
        }
        .critical-alert-content {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .critical-alert-icon {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.22);
            display: grid;
            place-items: center;
            font-size: 18px;
            font-weight: 800;
            flex-shrink: 0;
        }
        .critical-alert-text strong {
            display: block;
            font-size: 14.5px;
            font-weight: 800;
        }
        .critical-alert-text span {
            font-size: 12px;
            opacity: 0.92;
        }
        .critical-alert-btn {
            background: #ffffff;
            color: #d61818;
            border: 0;
            padding: 9px 18px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: transform 0.15s ease;
        }
        .critical-alert-btn:hover {
            background: #fdf2f2;
            transform: translateX(2px);
        }

        /* Queue List & Interactive Cards */
        .queue-card-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 14px;
        }
        .queue-item-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--white);
            transition: border-color 0.15s ease;
        }
        .queue-item-card:hover {
            border-color: #bccdc8;
        }
        .queue-meta-block {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .queue-header-tag {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .queue-id {
            font-family: monospace;
            font-size: 12.5px;
            font-weight: 700;
            color: var(--muted);
        }
        .queue-title {
            margin: 0;
            font-size: 14.5px;
            font-weight: 800;
            color: var(--ink);
        }
        .queue-details {
            font-size: 11.5px;
            color: var(--muted);
            margin: 0;
        }
        .queue-action-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .btn-action-primary {
            background: #173d3b;
            color: #ffffff;
            border: 1px solid #173d3b;
            padding: 7px 14px;
            border-radius: 5px;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-action-primary:hover {
            background: #122d2b;
        }
        .btn-action-quiet {
            background: #ffffff;
            color: #40575f;
            border: 1px solid var(--line);
            padding: 7px 12px;
            border-radius: 5px;
            font-size: 11.5px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-action-quiet:hover {
            background: #f4f8f7;
        }
        .btn-action-danger {
            background: #ffffff;
            color: var(--coral);
            border: 1px solid #ffd8d2;
            padding: 7px 12px;
            border-radius: 5px;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-action-danger:hover {
            background: #fff3f1;
        }

        /* Tanod Roster Cards */
        .tanod-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 14px;
            margin-top: 14px;
        }
        .tanod-card {
            background: var(--white);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .tanod-card-header {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .tanod-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .tanod-indicator.available { background: var(--green); }
        .tanod-indicator.deployed { background: var(--gold); }
        .tanod-indicator.off_duty { background: #94a3b8; }

        /* Notification Popover */
        .notif-container { position: relative; }
        .notification-bell-btn {
            position: relative;
            width: 38px;
            height: 38px;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: var(--white);
            display: grid;
            place-items: center;
            cursor: pointer;
            color: var(--ink);
        }
        .notification-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: var(--coral);
            color: #ffffff;
            font-size: 9.5px;
            font-weight: 800;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            border: 2px solid var(--white);
        }
        .notification-badge.pulse {
            transform: scale(1.15);
            animation: bellRing 1.5s infinite;
        }
        @keyframes bellRing {
            0%, 100% { box-shadow: 0 0 0 0 rgba(209, 92, 73, 0.5); }
            50% { box-shadow: 0 0 0 5px rgba(209, 92, 73, 0); }
        }
        .notif-dropdown {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            width: 360px;
            max-width: 90vw;
            background: var(--white);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: 0 12px 32px rgba(17, 34, 34, 0.18);
            z-index: 100;
            display: none;
            flex-direction: column;
            overflow: hidden;
        }
        .notif-dropdown.is-active { display: flex; }
        .notif-dropdown-header {
            padding: 12px 16px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(25, 118, 108, 0.04);
        }
        .notif-dropdown-header h4 { margin: 0; font-size: 13px; font-weight: 800; color: var(--ink); }
        .notif-dropdown-list { max-height: 380px; overflow-y: auto; display: flex; flex-direction: column; }
        .notif-item {
            padding: 12px 16px;
            border-bottom: 1px solid var(--line);
            display: flex;
            flex-direction: column;
            gap: 4px;
            cursor: pointer;
            transition: background 0.15s ease;
        }
        .notif-item:hover { background: rgba(25, 118, 108, 0.06); }
        .notif-item-top { display: flex; justify-content: space-between; align-items: center; }
        .notif-item-title { font-size: 12px; font-weight: 800; color: var(--ink); }
        .notif-item-time { font-size: 10px; color: var(--muted); }
        .notif-item-desc { font-size: 11px; color: var(--muted); margin: 0; }

        /* Dark Mode Overrides */
        [data-theme="dark"] .queue-item-card,
        [data-theme="dark"] .tanod-card {
            background: #112222;
            border-color: rgba(255, 255, 255, 0.08);
        }
        [data-theme="dark"] .btn-action-quiet {
            background: #152b2b;
            border-color: #24413f;
            color: #d1dedc;
        }
        [data-theme="dark"] .btn-action-primary {
            background: var(--teal);
            border-color: var(--teal);
        }
        [data-theme="dark"] .notification-bell-btn {
            background: #112222;
            border-color: #24413f;
            color: #ffffff;
        }
        [data-theme="dark"] .notif-dropdown {
            background: #112222;
            border-color: #24413f;
        }
        [data-theme="dark"] .notif-dropdown-header {
            background: #0f1e1e;
        }
        [data-theme="dark"] .notif-item {
            border-bottom-color: #1a2f2f;
        }
        [data-theme="dark"] .notif-item-title {
            color: #ffffff;
        }
    </style>
</head>
<body>
    <div class="admin-shell">
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

        <!-- Sticky Mobile Top Bar -->
        <header class="mobile-top-header">
            <button class="mobile-hamburger-btn" id="mobileMenuBtn" type="button" aria-label="Open Navigation">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <line x1="3" y1="12" x2="21" y2="12"></line>
                    <line x1="3" y1="18" x2="21" y2="18"></line>
                </svg>
            </button>
            <a class="admin-brand mobile-brand-inline" href="officer_dashboard.php">
                <span class="admin-brand-mark">B</span>
                <span><strong>BalangayLog</strong><small>BARANGAY DUALE</small></span>
            </a>
        </header>

        <!-- Sidebar Drawer -->
        <aside class="admin-sidebar" id="adminSidebar">
            <div class="sidebar-header-row">
                <a class="admin-brand" href="officer_dashboard.php" aria-label="BalangayLog Officer Portal">
                    <span class="admin-brand-mark">B</span>
                    <span><strong>BalangayLog</strong><small>BARANGAY DUALE</small></span>
                </a>
                <button class="sidebar-close-btn" id="sidebarCloseBtn" type="button" aria-label="Close Navigation">×</button>
            </div>

            <div class="admin-identity">
                <span class="identity-avatar" id="officerAvatar" style="position: relative; overflow: hidden;">
                    <span id="officerInitials"><?= $initials ?></span>
                    <img id="officerProfilePic" src="<?= $profilePic ?? '' ?>" alt="Officer Picture" <?= $profilePic ? '' : 'hidden' ?> style="position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                </span>
                <span>
                    <strong id="officerDisplayName"><?= $officerName ?></strong>
                    <small><?= $roleLabel ?></small>
                </span>
            </div>

            <p class="eyebrow" style="margin: 4px 10px 8px; color: #a9cfc2; font-size: 9.5px;">OPERATIONS</p>
            <nav class="admin-nav" aria-label="Officer Operations Views">
                <button class="admin-nav-link is-active" type="button" data-view="dashboard">
                    <span class="nav-symbol">▦</span>Dashboard
                </button>
                <button class="admin-nav-link" type="button" data-view="blotter">
                    <span class="nav-symbol">☷</span>All Cases / Blotter
                </button>
                <button class="admin-nav-link" type="button" id="sidebarEncodeCaseBtn">
                    <span class="nav-symbol">+</span>Encode New Case
                </button>
                <button class="admin-nav-link" type="button" data-view="triage">
                    <span class="nav-symbol">≡</span>Incoming Triage Queue
                    <span class="pill pill-pending" id="triageNavBadge" style="margin-left: auto; padding: 2px 6px;">0</span>
                </button>
                <button class="admin-nav-link" type="button" data-view="emergency">
                    <span class="nav-symbol">⚠</span>Urgent / Emergency
                    <span class="pill pill-rejected" id="emergencyNavBadge" style="margin-left: auto; padding: 2px 6px;">0</span>
                </button>
                <button class="admin-nav-link" type="button" data-view="lupon">
                    <span class="nav-symbol">⚖</span>Lupon / Mediation
                    <span class="pill pill-blue" id="luponNavBadge" style="margin-left: auto; padding: 2px 6px;">0</span>
                </button>
                <button class="admin-nav-link" type="button" data-view="dispatch">
                    <span class="nav-symbol">🚔</span>Tanod Dispatch Board
                </button>
            </nav>

            <div class="sidebar-bottom">
                <button class="back-link" id="themeToggleBtn" type="button" style="display: flex; align-items: center; justify-content: space-between;">
                    <span id="themeToggleLabel">Dark Mode</span>
                </button>
                <button class="logout-link" id="logoutBtn" type="button">Log out</button>
            </div>
        </aside>

        <!-- Main Workspace -->
        <main class="admin-main">
            <!-- Top Navigation & Desk Controls -->
            <header class="admin-topbar">
                <div>
                    <h1 style="font-size: 22px; font-weight: 800; margin: 0; font-family: 'Manrope', sans-serif;">Desk Operations</h1>
                    <p style="margin: 3px 0 0; color: var(--muted); font-size: 12px;">Live triage, blotter intake, and field dispatch</p>
                </div>
                <div class="topbar-right-controls" style="display: flex; align-items: center; gap: 12px;">
                    <!-- Notification Bell & Popover -->
                    <div class="notif-container">
                        <button class="notification-bell-btn" id="notifBellBtn" type="button" title="Live notifications">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                            </svg>
                            <span class="notification-badge" id="topbarNotifCount" style="display: none;">0</span>
                        </button>
                        <div class="notif-dropdown" id="notifDropdown">
                            <div class="notif-dropdown-header">
                                <h4>Case Alerts &amp; Submissions</h4>
                                <span class="pill pill-pending" id="notifDropdownBadge">0 Urgent</span>
                            </div>
                            <div class="notif-dropdown-list" id="notifDropdownList">
                                <p class="empty-message" style="margin: 20px 0;">No active alerts.</p>
                            </div>
                        </div>
                    </div>

                    <button class="button button-primary" type="button" id="openEncodeModalBtn" style="background: #173d3b; border-color: #173d3b;">
                        + Encode Walk-In Case
                    </button>
                </div>
            </header>

            <div class="notice" id="pageNotice" role="status" hidden></div>

            <!-- VIEW 1: DASHBOARD -->
            <section class="admin-view" id="view-dashboard">
                <div class="critical-alert-banner" id="criticalBanner" hidden>
                    <div class="critical-alert-content">
                        <div class="critical-alert-icon">!</div>
                        <div class="critical-alert-text">
                            <strong id="criticalBannerHeading">1 critical case needs immediate attention</strong>
                            <span>Tap to dispatch responders and assign an on-duty tanod.</span>
                        </div>
                    </div>
                    <button class="critical-alert-btn" type="button" id="criticalDispatchBtn">
                        Dispatch →
                    </button>
                </div>

                <div class="metric-grid" style="grid-template-columns: repeat(4, minmax(130px, 1fr));">
                    <article class="metric-tile metric-gold">
                        <span style="font-size: 10px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase;">PENDING TRIAGE</span>
                        <strong id="metricPendingTriage">0</strong>
                        <small>Requires desk review</small>
                    </article>
                    <article class="metric-tile metric-coral">
                        <span style="font-size: 10px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase;">ACTIVE DISPATCHED</span>
                        <strong id="metricActiveDispatched">0</strong>
                        <small>Tanods currently assigned</small>
                    </article>
                    <article class="metric-tile metric-blue">
                        <span style="font-size: 10px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase;">UNDER INVESTIGATION</span>
                        <strong id="metricUnderInvestigation">0</strong>
                        <small>Active blotter cases</small>
                    </article>
                    <article class="metric-tile metric-green">
                        <span style="font-size: 10px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase;">RESOLVED TODAY</span>
                        <strong id="metricResolvedToday">0</strong>
                        <small>Closed since midnight</small>
                    </article>
                </div>

                <div class="content-panel" style="margin-top: 22px;">
                    <div class="panel-heading" style="border-bottom: 1px solid var(--line); padding-bottom: 14px;">
                        <div>
                            <h2 style="font-size: 16px;">Live Triage &amp; Blotter Queue</h2>
                            <p>New citizen submissions awaiting frontline action</p>
                        </div>
                        <span class="pill pill-pending" id="triageQueuePill">0 pending</span>
                    </div>
                    <div class="queue-card-list" id="triageQueueContainer">
                        <p class="empty-message">Loading active intake queue...</p>
                    </div>
                </div>
            </section>

            <!-- VIEW 2: ALL CASES / BLOTTER -->
            <section class="admin-view" id="view-blotter" hidden>
                <div class="section-heading">
                    <div>
                        <h2>Official Barangay Blotter Records</h2>
                        <p>Complete historical log of incidents, disputes, and resolutions.</p>
                    </div>
                    <button class="button button-quiet" type="button" data-refresh-cases>Refresh Records</button>
                </div>
                <div class="content-panel">
                    <div class="toolbar">
                        <div class="segmented" role="group">
                            <button type="button" class="segment is-selected" data-blotter-filter="ALL">All Records</button>
                            <button type="button" class="segment" data-blotter-filter="PENDING">Pending</button>
                            <button type="button" class="segment" data-blotter-filter="IN_PROGRESS">In Progress</button>
                            <button type="button" class="segment" data-blotter-filter="DISPATCHED">Dispatched</button>
                            <button type="button" class="segment" data-blotter-filter="FOR_RESOLUTION">Lupon</button>
                            <button type="button" class="segment" data-blotter-filter="RESOLVED">Resolved</button>
                        </div>
                        <label class="search-field">
                            <input id="blotterSearchInput" type="search" placeholder="Search reference, complainant, purok...">
                        </label>
                    </div>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Ref Number</th>
                                    <th>Complainant</th>
                                    <th>Category</th>
                                    <th>Purok</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Assigned</th>
                                    <th>Date Encoded</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="blotterTableRows"></tbody>
                        </table>
                    </div>
                    <p class="empty-message" id="blotterEmptyMsg" hidden>No cases match your filter criteria.</p>
                </div>
            </section>

            <!-- VIEW 3: INCOMING TRIAGE QUEUE -->
            <section class="admin-view" id="view-triage" hidden>
                <div class="section-heading">
                    <div>
                        <h2>Frontline Triage Queue</h2>
                        <p>Review raw submissions, detect duplicates, and assign verification protocols.</p>
                    </div>
                </div>
                <div class="notice" style="margin-bottom: 16px; background: #fbf8eb; border-color: #ecdca6; color: #82621c;">
                    <strong>Review Guidelines:</strong> Frontline triage determines preliminary blotter qualification. All actions log into the resident's public milestone audit trail.
                </div>
                <div class="content-panel">
                    <div class="queue-card-list" id="dedicatedTriageContainer"></div>
                </div>
            </section>

            <!-- VIEW 4: URGENT / EMERGENCY -->
            <section class="admin-view" id="view-emergency" hidden>
                <div class="section-heading">
                    <div>
                        <h2>Urgent &amp; Emergency Incidents</h2>
                        <p>High-threat disputes and critical emergencies requiring immediate police or tanod response.</p>
                    </div>
                </div>
                <div class="content-panel">
                    <div class="queue-card-list" id="emergencyCardsContainer"></div>
                </div>
            </section>

            <!-- VIEW 5: LUPON / MEDIATION -->
            <section class="admin-view" id="view-lupon" hidden>
                <div class="section-heading">
                    <div>
                        <h2>Katarungang Pambarangay (Lupon Mediation)</h2>
                        <p>Track scheduled conciliation hearings, summons records, and formal amicable settlements.</p>
                    </div>
                </div>
                <div class="content-panel">
                    <div class="queue-card-list" id="luponCardsContainer"></div>
                </div>
            </section>

            <!-- VIEW 6: TANOD DISPATCH BOARD -->
            <section class="admin-view" id="view-dispatch" hidden>
                <div class="section-heading">
                    <div>
                        <h2>Tanod Patrol &amp; Dispatch Board</h2>
                        <p>Assign and monitor on-duty responders for on-site verification.</p>
                    </div>
                </div>
                <div class="content-panel">
                    <div class="panel-heading" style="padding-bottom: 10px; border-bottom: 1px solid var(--line);">
                        <h3>On-Duty Responder Roster</h3>
                        <span class="pill pill-active" id="activeTanodCount">2 Available</span>
                    </div>
                    <div class="tanod-grid" id="tanodRosterContainer"></div>
                </div>
            </section>
        </main>
    </div>

    <!-- MODAL: ENCODE WALK-IN CASE -->
    <dialog class="admin-dialog" id="encodeCaseDialog" style="width: min(640px, calc(100vw - 32px)); max-height: 90vh;">
        <form id="encodeCaseForm" method="dialog">
            <div class="dialog-heading">
                <div>
                    <h2 style="font-size: 19px; font-weight: 800; font-family: 'Manrope', sans-serif;">Encode Walk-In Desk Blotter</h2>
                    <p style="margin: 3px 0 0; color: var(--muted); font-size: 12px;">Transcribe citizen walk-in complaint for instant record logging.</p>
                </div>
                <button class="icon-button" type="button" data-close-dialog aria-label="Close">×</button>
            </div>
            <div style="max-height: calc(90vh - 170px); overflow-y: auto; padding-right: 6px; display: grid; gap: 14px;">
                <div style="border-bottom: 1px solid var(--line); padding-bottom: 12px;">
                    <p class="eyebrow" style="color: var(--teal); font-weight: 800; margin-bottom: 8px;">COMPLAINANT INFORMATION</p>
                    <div class="form-row">
                        <label>Complainant Full Name *
                            <input name="complainantName" required placeholder="e.g., Marites Ramos">
                        </label>
                        <label>Contact Phone (09XXXXXXXXX) *
                            <input name="complainantPhone" required placeholder="09171234567">
                        </label>
                    </div>
                    <div class="form-row" style="margin-top: 10px;">
                        <label>Purok / Zone *
                            <select name="purok" required>
                                <option value="">Select Purok</option>
                                <option value="Purok 1">Purok 1</option>
                                <option value="Purok 2">Purok 2</option>
                                <option value="Purok 3">Purok 3</option>
                                <option value="Purok 4">Purok 4</option>
                                <option value="Purok 5">Purok 5</option>
                                <option value="Purok 6">Purok 6</option>
                            </select>
                        </label>
                        <label>Street Address / Landmark
                            <input name="streetAddress" placeholder="e.g., Lot 5, Batol St.">
                        </label>
                    </div>
                </div>
                <div>
                    <p class="eyebrow" style="color: var(--teal); font-weight: 800; margin-bottom: 8px;">CASE PARTICULARS</p>
                    <div class="form-row">
                        <label>Incident Category *
                            <select name="category" required>
                                <option value="Noise Disturbance">Noise Disturbance</option>
                                <option value="Road obstruction">Road obstruction</option>
                                <option value="Domestic disturbance">Domestic disturbance</option>
                                <option value="Physical Altercation">Physical Altercation</option>
                                <option value="Property Dispute">Property Dispute</option>
                                <option value="Flooding">Flooding / Drainage</option>
                                <option value="Others">Others</option>
                            </select>
                        </label>
                        <label>Priority Assessment *
                            <select name="priorityLevel" required>
                                <option value="Low">Low</option>
                                <option value="High" selected>High</option>
                                <option value="Critical">Critical (Immediate Dispatch)</option>
                            </select>
                        </label>
                    </div>
                    <div style="margin-top: 10px;">
                        <label>Blotter Narrative &amp; Facts *
                            <textarea name="incidentNarrative" rows="4" required placeholder="Enter full narrative facts as stated by complainant..."></textarea>
                        </label>
                    </div>
                </div>
            </div>
            <div class="dialog-actions" style="margin-top: 14px; padding-top: 10px; border-top: 1px solid var(--line);">
                <button class="button button-quiet" type="button" data-close-dialog>Cancel</button>
                <button class="button button-primary" type="submit" style="background: #173d3b; border-color: #173d3b;">Encode &amp; Log Case</button>
            </div>
        </form>
    </dialog>

    <!-- MODAL: DISPATCH TANOD -->
    <dialog class="admin-dialog" id="dispatchModal" style="width: min(480px, calc(100vw - 32px));">
        <form id="dispatchForm" method="dialog">
            <div class="dialog-heading">
                <div>
                    <p class="eyebrow">FIELD ASSIGNMENT</p>
                    <h2>Dispatch Tanod Responders</h2>
                </div>
                <button class="icon-button" type="button" data-close-dialog aria-label="Close">×</button>
            </div>
            <input type="hidden" name="reference_number" id="dispatchRefInput">
            <label>Assigned Unit / On-Duty Tanod
                <select name="deployed_unit" id="dispatchUnitSelect" required style="margin-top: 6px;">
                    <option value="Unit Alpha (Tanod Ramon Diaz, Tanod Elena Flores)">Unit Alpha (Ramon Diaz, Elena Flores)</option>
                    <option value="Tanod Patrol 1 (Tanod Ramon Diaz)">Tanod Patrol 1 (Ramon Diaz)</option>
                    <option value="Tanod Patrol 2 (Tanod Elena Flores)">Tanod Patrol 2 (Elena Flores)</option>
                    <option value="Immediate Tanod Response Team">Immediate Tanod Response Team</option>
                </select>
            </label>
            <label style="margin-top: 12px; display: block;">Dispatch Directive Notes
                <textarea name="dispatch_notes" rows="3" placeholder="Instructions for on-site assessment..." style="margin-top: 6px;">Immediate responder dispatched for on-site investigation and peace preservation.</textarea>
            </label>
            <div class="dialog-actions" style="margin-top: 14px;">
                <button class="button button-quiet" type="button" data-close-dialog>Cancel</button>
                <button class="button button-primary" type="submit" style="background: var(--coral); border-color: var(--coral);">Confirm Dispatch</button>
            </div>
        </form>
    </dialog>

    <!-- MODAL: LUPON ENDORSEMENT / SETTLEMENT -->
    <dialog class="admin-dialog" id="luponModal" style="width: min(500px, calc(100vw - 32px));">
        <form id="luponForm" method="dialog">
            <div class="dialog-heading">
                <div>
                    <p class="eyebrow">KATARUNGANG PAMBARANGAY</p>
                    <h2 id="luponModalTitle">Endorse to Lupon Mediation</h2>
                </div>
                <button class="icon-button" type="button" data-close-dialog aria-label="Close">×</button>
            </div>
            <input type="hidden" name="reference_number" id="luponRefInput">
            <input type="hidden" name="action_mode" id="luponActionMode" value="endorse">

            <div id="luponEndorseFields">
                <label>Assigned Mediator / Officer
                    <input name="mediator" value="Capt. Eduardo Reyes" required style="margin-top: 6px;">
                </label>
                <label style="margin-top: 12px; display: block;">Mediation Schedule Hearing
                    <input name="schedule" type="datetime-local" style="margin-top: 6px;" required>
                </label>
            </div>

            <div id="luponSettleFields" style="display: none;">
                <label>Terms of Amicable Settlement
                    <textarea name="settlement_notes" rows="4" placeholder="Enter terms agreed upon by both parties..." style="margin-top: 6px;">Both parties appeared before the Lupon. Mutual amicable settlement reached and signed.</textarea>
                </label>
            </div>

            <div class="dialog-actions" style="margin-top: 14px;">
                <button class="button button-quiet" type="button" data-close-dialog>Cancel</button>
                <button class="button button-primary" type="submit" id="luponSubmitBtn" style="background: #173d3b; border-color: #173d3b;">Confirm Action</button>
            </div>
        </form>
    </dialog>

    <script src="officer-dashboard.js?v=<?= time() ?>"></script>
</body>
</html>