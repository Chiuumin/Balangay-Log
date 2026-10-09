<?php
require_once __DIR__ . '/auth.php';
requireRole(['RESIDENT']);

$pdo = getDatabaseConnection();
$userId = (int) ($_SESSION['user_id'] ?? 0);


$stmt = $pdo->prepare('SELECT full_name, email_or_phone, contact_number, purok, profile_picture FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$userRecord = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$residentName = htmlspecialchars((string) ($userRecord['full_name'] ?? 'Resident'), ENT_QUOTES, 'UTF-8');
$purok = htmlspecialchars((string) ($userRecord['purok'] ?? 'Purok 2'), ENT_QUOTES, 'UTF-8');
$contactNumber = htmlspecialchars((string) ($userRecord['contact_number'] ?? $userRecord['email_or_phone'] ?? ''), ENT_QUOTES, 'UTF-8');
$profilePic = !empty($userRecord['profile_picture']) ? htmlspecialchars((string) $userRecord['profile_picture'], ENT_QUOTES, 'UTF-8') : null;

$csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

$nameParts = array_filter(explode(' ', trim($residentName)));
$initials = '';
foreach (array_slice($nameParts, 0, 2) as $p) {
    $initials .= strtoupper($p[0] ?? '');
}
if (!$initials) $initials = 'JD';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>Resident Portal | BalangayLog</title>
    <script>
        if (localStorage.getItem('balangay_theme') === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    </script>
    <link rel="stylesheet" href="admin-dashboard.css?v=<?= time() ?>" />
</head>
<body>
<div class="admin-shell">
        <!-- Backdrop Dimmer Overlay -->
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

        <!-- Sticky Mobile Header Bar -->
        <header class="mobile-top-header">
            <button class="mobile-hamburger-btn" id="mobileMenuBtn" type="button" aria-label="Open Navigation">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <line x1="3" y1="12" x2="21" y2="12"></line>
                    <line x1="3" y1="18" x2="21" y2="18"></line>
                </svg>
            </button>
            <a class="admin-brand mobile-brand-inline" href="resident_dashboard.php">
                <span class="admin-brand-mark">B</span>
                <span><strong>BalangayLog</strong><small>BARANGAY DUALE</small></span>
            </a>
        </header>

        <!-- Sliding Sidebar Drawer -->
        <aside class="admin-sidebar" id="adminSidebar">
            <div class="sidebar-header-row">
                <a class="admin-brand" href="resident_dashboard.php" aria-label="BalangayLog Resident Portal">
                    <span class="admin-brand-mark">B</span>
                    <span><strong>BalangayLog</strong><small>BARANGAY DUALE</small></span>
                </a>
                <button class="sidebar-close-btn" id="sidebarCloseBtn" type="button" aria-label="Close Navigation">×</button>
            </div>

<div class="admin-identity">
        <span class="identity-avatar" id="residentAvatar" style="position: relative; overflow: hidden;">
            <span id="residentSidebarInitials" style="<?= $profilePic ? 'display: none;' : '' ?>"><?= $initials ?></span>
            <img id="residentSidebarPic" src="<?= $profilePic ?? '' ?>" alt="Resident Avatar" style="position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; border-radius: 50%; <?= $profilePic ? 'display: block;' : 'display: none;' ?>">
        </span>
        <span><strong id="residentName"><?= $residentName ?></strong><small>Verified • <?= $purok ?></small></span>
    </div>

            <nav class="admin-nav" aria-label="Resident Portal Views">
                <button class="admin-nav-link is-active" type="button" data-view="overview"><span class="nav-symbol">▦</span>Dashboard / Home</button>
                <button class="admin-nav-link" type="button" data-view="reports"><span class="nav-symbol">☷</span>My Filed Reports</button>
                <button class="admin-nav-link" type="button" data-view="hotlines"><span class="nav-symbol">☏</span>Emergency Hotlines</button>
                <button class="admin-nav-link" type="button" data-view="profile"><span class="nav-symbol">⚙</span>My Profile &amp; Settings</button>
            </nav>

            <div class="sidebar-bottom">
                <button class="back-link" id="themeToggleBtn" type="button" style="display: flex; align-items: center; justify-content: space-between;">
                    <span id="themeToggleLabel">Dark Mode</span>
                </button>
                <button class="logout-link" id="logoutBtn" type="button">Log out</button>
            </div>
        </aside>

        <!-- MAIN VIEWSPACE -->
        <main class="admin-main">
            <header class="admin-topbar">
                <div>
                    <p class="eyebrow">RESIDENT PORTAL</p>
                    <h1 id="viewTitle">Resident Dashboard</h1>
                </div>
                <div class="topbar-meta"><span class="status-indicator"></span>System online <time id="todayLabel"><?= date('M j, Y') ?></time></div>
            </header>

            <div class="notice" id="pageNotice" role="status" hidden></div>

            <!-- VIEW 1: DASHBOARD / HOME -->
            <section class="admin-view" id="view-overview">
                <div class="section-heading">
                    <div>
                        <h2>Incident reports &amp; activity</h2>
                        <p>Track your submitted reports and receive official barangay feedback.</p>
                    </div>
                    <button type="button" class="button button-primary" id="openReportModalBtn">+ File Incident Report</button>
                </div>

                <div class="metric-grid" style="grid-template-columns: repeat(4, minmax(130px, 1fr));">
                    <article class="metric-tile metric-slate"><span>Submitted reports</span><strong id="metricTotal">—</strong><small>All reports on record</small></article>
                    <article class="metric-tile metric-gold"><span>Under review</span><strong id="metricReview">—</strong><small>Awaiting desk verification</small></article>
                    <article class="metric-tile metric-blue"><span>In progress</span><strong id="metricProgress">—</strong><small>Barangay action underway</small></article>
                    <article class="metric-tile metric-green"><span>Resolved</span><strong id="metricResolved">—</strong><small>Cases settled amicably</small></article>
                </div>

                <div class="content-panel overview-panel">
                    <div class="panel-heading">
                        <div>
                            <h2>My Recent Reports</h2>
                            <p>Official records submitted via your verified resident profile.</p>
                        </div>
                        <button class="text-button" type="button" data-open-view="reports">View all reports <span aria-hidden="true">→</span></button>
                    </div>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Tracking #</th>
                                    <th>Incident Type</th>
                                    <th>Location</th>
                                    <th>Date Filed</th>
                                    <th>Status</th>
                                    <th>Officer Remarks</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="overviewRecentRows"></tbody>
                        </table>
                    </div>
                    <p class="empty-message" id="overviewRecentEmpty" hidden>You have not filed any incident reports yet.</p>
                </div>
            </section>

            <!-- VIEW 2: ALL FILED REPORTS (Full table + filters) -->
            <section class="admin-view" id="view-reports" hidden>
                <div class="section-heading">
                    <div>
                        <h2>My Incident Records</h2>
                        <p>Complete history of incidents and disputes submitted under your name.</p>
                    </div>
                    <button type="button" class="button button-primary" id="openReportModalBtn2">+ File Incident Report</button>
                </div>
                <div class="content-panel">
                    <div class="toolbar">
                        <div class="segmented" role="group" aria-label="Status filter">
                            <button type="button" class="segment is-selected" data-report-filter="all">All</button>
                            <button type="button" class="segment" data-report-filter="UNDER_REVIEW">Under Review</button>
                            <button type="button" class="segment" data-report-filter="IN_PROGRESS">In Progress</button>
                            <button type="button" class="segment" data-report-filter="RESOLVED">Resolved</button>
                        </div>
                        <label class="search-field">
                            <span class="sr-only">Search my reports</span>
                            <input id="reportSearch" type="search" placeholder="Search tracking #, type, location...">
                        </label>
                    </div>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Tracking #</th>
                                    <th>Incident Type</th>
                                    <th>Location</th>
                                    <th>Date Filed</th>
                                    <th>Status</th>
                                    <th>Officer Remarks</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="allReportRows"></tbody>
                        </table>
                    </div>
                    <p class="empty-message" id="allReportsEmpty" hidden>No incident reports match this filter.</p>
                </div>
            </section>

            <!-- VIEW 3: EMERGENCY HOTLINES -->
            <section class="admin-view" id="view-hotlines" hidden>
                <div class="section-heading">
                    <div>
                        <h2>Emergency Assistance &amp; Hotlines</h2>
                        <p>Direct lines for peace, order, and municipal support.</p>
                    </div>
                </div>
                <div class="metric-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px;">
                    <div class="content-panel" style="margin-top: 0; display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <p class="eyebrow">BARANGAY ACTION</p>
                            <h2 style="font-size: 16px; margin: 4px 0 6px;">Barangay Hall Desk</h2>
                            <strong style="color: var(--coral); font-size: 20px; font-family: 'Manrope', sans-serif;">(02) 8123-4567</strong>
                            <p style="margin: 8px 0 16px; color: var(--muted); font-size: 12px;">General assistance, blotter follow-ups, and desk support.</p>
                        </div>
                        <a href="tel:0281234567" class="button button-quiet" style="text-decoration: none;">Call Desk</a>
                    </div>

                    <div class="content-panel" style="margin-top: 0; display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <p class="eyebrow">PEACE &amp; ORDER</p>
                            <h2 style="font-size: 16px; margin: 4px 0 6px;">Barangay Tanod Base</h2>
                            <strong style="color: var(--coral); font-size: 20px; font-family: 'Manrope', sans-serif;">0917-555-0147</strong>
                            <p style="margin: 8px 0 16px; color: var(--muted); font-size: 12px;">Immediate patrol dispatch, curfew enforcement, and urgent dispute resolution.</p>
                        </div>
                        <a href="tel:09175550147" class="button button-quiet" style="text-decoration: none;">Call Tanod</a>
                    </div>

                    <div class="content-panel" style="margin-top: 0; display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <p class="eyebrow">LAW ENFORCEMENT</p>
                            <h2 style="font-size: 16px; margin: 4px 0 6px;">PNP Municipal Station</h2>
                            <strong style="color: var(--coral); font-size: 20px; font-family: 'Manrope', sans-serif;">0998-598-7920</strong>
                            <p style="margin: 8px 0 16px; color: var(--muted); font-size: 12px;">Local police dispatch, criminal incidents, and high-level community security.</p>
                        </div>
                        <a href="tel:09985987920" class="button button-quiet" style="text-decoration: none;">Call Police</a>
                    </div>

                    <div class="content-panel" style="margin-top: 0; display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <p class="eyebrow">RESCUE &amp; FIRE</p>
                            <h2 style="font-size: 16px; margin: 4px 0 6px;">BFP Fire Station</h2>
                            <strong style="color: var(--coral); font-size: 20px; font-family: 'Manrope', sans-serif;">(02) 8426-0219</strong>
                            <p style="margin: 8px 0 16px; color: var(--muted); font-size: 12px;">Fire response, vehicular accidents, and ambulance emergency dispatch.</p>
                        </div>
                        <a href="tel:0284260219" class="button button-quiet" style="text-decoration: none;">Call BFP</a>
                    </div>
                </div>
            </section>

            <!-- VIEW 4: RESIDENCY PROFILE & SECURITY SETTINGS (Identical to Admin Settings) -->
            <section class="admin-view" id="view-profile" hidden>
                <div class="section-heading">
                    <div>
                        <h2>Profile &amp; Residency Details</h2>
                        <p>Review your personal records and manage portal security.</p>
                    </div>
                </div>

<div class="settings-stack">
                    <div class="content-panel profile-card">
                        <form id="profileForm" enctype="multipart/form-data">
                            <div class="profile-header-group">
                                <div class="profile-avatar-wrap">
                                    <div class="profile-avatar-lg" id="settingsAvatarBadge">
                                        <span id="settingsAvatarInitials" style="<?= $profilePic ? 'display: none;' : '' ?>"><?= $initials ?></span>
                                        <img id="residentAvatarPreview" src="<?= $profilePic ?? '' ?>" alt="Profile Picture" style="<?= $profilePic ? 'display: block;' : 'display: none;' ?> width: 100%; height: 100%; object-fit: cover;">
                                    </div>
                                    <label class="avatar-upload-trigger" for="residentPhotoInput" title="Upload new photo" aria-label="Upload photo">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                                            <circle cx="12" cy="13" r="4"/>
                                        </svg>
                                    </label>
                                    <input type="file" name="profile_picture" id="residentPhotoInput" accept="image/png,image/jpeg,image/webp" class="sr-only">
                                </div>
                                
                                <div class="profile-title-block">
                                    <h2><?= $residentName ?></h2>
                                    <span class="role-badge" style="background: #19766c;">Verified Resident</span>
                                </div>

                                <div class="profile-save-action">
                                    <button class="button button-quiet" type="submit">Save Profile Details</button>
                                </div>
                            </div>

                            <div class="profile-meta-grid">
                                <div class="meta-item">
                                    <label class="meta-label" for="profileName">FULL NAME</label>
                                    <input class="meta-input" name="full_name" id="profileName" value="<?= $residentName ?>" required>
                                </div>

                                <div class="meta-item">
                                    <span class="meta-label">ASSIGNED PUROK</span>
                                    <strong class="meta-value"><?= $purok ?></strong>
                                </div>

                                <div class="meta-item">
                                    <span class="meta-label">USERNAME / LOGIN</span>
                                    <strong class="meta-value"><?= htmlspecialchars((string)($userRecord['username'] ?? $userRecord['email_or_phone'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></strong>
                                </div>

                                <div class="meta-item">
                                    <label class="meta-label" for="profileContact">CONTACT NUMBER</label>
                                    <input class="meta-input" name="contact_number" id="profileContact" value="<?= $contactNumber ?>" placeholder="e.g. 09171234567">
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="content-panel password-card">
                        <div class="panel-heading">
                            <div>
                                <h2>Change Password</h2>
                                <p>Ensure your account uses a secure password.</p>
                            </div>
                        </div>

                        <form class="settings-password-form" id="passwordForm">
                            <div class="form-group">
                                <label for="current_password">Current Password</label>
                                <input name="current_password" id="current_password" type="password" autocomplete="current-password" placeholder="••••••••" required>
                            </div>

                            <div class="form-group">
                                <label for="new_password">New Password</label>
                                <input name="new_password" id="new_password" type="password" minlength="8" autocomplete="new-password" placeholder="••••••••" required>
                            </div>

                            <div class="form-group">
                                <label for="confirm_password">Confirm New Password</label>
                                <input name="confirm_password" id="confirm_password" type="password" minlength="8" autocomplete="new-password" placeholder="••••••••" required>
                            </div>

                            <div class="form-actions">
                                <button class="button button-primary" type="submit">Update Password</button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- DIALOG: FILE INCIDENT REPORT (Identical Dialog Architecture) -->
<!-- DIALOG: ENCODE NEW INCIDENT REPORT (Matches Official Schema Exactly) -->
<dialog class="admin-dialog" id="reportDialog" style="width: min(640px, calc(100vw - 32px)); max-height: 90vh;">
    <form id="incidentForm" method="dialog">
        <div class="dialog-heading">
            <div>
                <h2 style="font-size: 19px; font-weight: 800; font-family: 'Manrope', sans-serif;">Encode New Incident Report</h2>
                <div style="display: flex; align-items: center; gap: 8px; margin-top: 6px;">
                    <span class="eyebrow" style="margin: 0; font-size: 11px;">REPORTING CHANNEL:</span>
                    <select name="reporting_channel" style="min-height: 30px; font-size: 11.5px; padding: 2px 8px; width: auto; border: 1px solid var(--line); border-radius: 4px;">
                        <option value="Citizen Online Report" selected>Citizen Online Report</option>
                        <option value="Walk-in Desk">Walk-in Desk</option>
                        <option value="Hotline Call">Hotline Call</option>
                    </select>
                </div>
            </div>
            <button class="icon-button" type="button" data-close-dialog aria-label="Close">×</button>
        </div>

        <div style="max-height: calc(90vh - 170px); overflow-y: auto; padding-right: 6px; display: grid; gap: 14px;">
            <!-- SECTION 1: COMPLAINANT / REPORTER INFO -->
            <div style="border-bottom: 1px solid var(--line); padding-bottom: 12px;">
                <p class="eyebrow" style="color: var(--teal); font-weight: 800; letter-spacing: 0.5px; margin-bottom: 10px;">COMPLAINANT / REPORTER INFO</p>
                <div class="form-row">
                    <label>Full Name *
                        <input name="complainant_name" value="<?= $residentName ?>" required placeholder="e.g., Ligaya Bautista">
                    </label>
                    <label>Contact Number *
                        <input name="contact_number" value="<?= htmlspecialchars((string)($currentUser['contact_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required placeholder="09xxxxxxxxx">
                    </label>
                </div>

                <div class="form-row" style="margin-top: 10px;">
                    <label>Purok / Zone *
                        <select name="purok" id="purokSelect" required>
                            <option value="">Select Purok</option>
                            <option value="Purok 1">Purok 1</option>
                            <option value="Purok 2" <?= $purok === 'Purok 2' ? 'selected' : '' ?>>Purok 2</option>
                            <option value="Purok 3" <?= $purok === 'Purok 3' ? 'selected' : '' ?>>Purok 3</option>
                            <option value="Purok 4">Purok 4</option>
                            <option value="Purok 5">Purok 5</option>
                            <option value="Purok 6">Purok 6</option>
                        </select>
                    </label>
                    <label>House / Bldg No. *
                        <input name="house_number" required placeholder="e.g., 124 or Lot 5">
                    </label>
                </div>

                <div style="margin-top: 10px;">
                    <label>Street Name *
                        <input name="street_name" required placeholder="e.g., Rizal St.">
                    </label>
                </div>
            </div>

            <!-- SECTION 2: INCIDENT DETAILS -->
            <div>
                <p class="eyebrow" style="color: var(--teal); font-weight: 800; letter-spacing: 0.5px; margin-bottom: 10px;">INCIDENT DETAILS</p>
                <label>Incident Summary / Title *
                    <input name="summary" required placeholder="e.g., Loud Music Disturbance at Sitio Masaya">
                </label>

                <div class="form-row" style="margin-top: 10px;">
                    <label>Category *
                        <select name="category" required>
                            <option value="">Select Category</option>
                            <option value="Noise Disturbance">Noise Disturbance</option>
                            <option value="Flooding">Flooding / Drainage</option>
                            <option value="Road Obstruction">Road Obstruction</option>
                            <option value="Vandalism">Vandalism</option>
                            <option value="Physical Altercation">Physical Altercation</option>
                            <option value="Property Dispute">Property Dispute</option>
                            <option value="Others">Others</option>
                        </select>
                    </label>
                    <label>Initial Priority (AI / Officer) *
                        <select name="priority" required>
                            <option value="Low">Low</option>
                            <option value="Normal">Normal</option>
                            <option value="High" selected>High</option>
                            <option value="Urgent">Urgent / Critical</option>
                        </select>
                    </label>
                </div>

                <div style="margin-top: 10px;">
                    <label>Incident Narrative / Blotter Notes *
                        <textarea name="narrative" rows="4" required placeholder="Provide clear description of the incident..."></textarea>
                    </label>
                </div>
            </div>
        </div>

        <div class="dialog-actions" style="margin-top: 14px; padding-top: 10px; border-top: 1px solid var(--line);">
            <button class="button button-quiet" type="button" data-close-dialog>Cancel</button>
            <button class="button button-primary" type="submit" style="background: #112222; border-color: #112222;">Encode &amp; File Case</button>
        </div>
    </form>
</dialog>

    <!-- DIALOG: VIEW INCIDENT DETAILS & OFFICER FEEDBACK -->
<dialog class="admin-dialog" id="reportDetailDialog" style="width: min(640px, calc(100vw - 32px)); max-height: 92vh;">
    <div class="dialog-heading">
        <div>
            <p class="eyebrow">CASE STATUS TRACKING</p>
            <h2 id="detailDialogTitle">Incident Progress</h2>
        </div>
        <button class="icon-button" type="button" data-close-dialog aria-label="Close">×</button>
    </div>

<!-- Modern Civic Logistics Stepper -->
<div class="tracking-stepper" id="trackingStepper">
    <div class="stepper-step is-done" id="step-reported">
        <div class="stepper-node">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
        </div>
        <span class="stepper-label">Reported</span>
    </div>

    <div class="stepper-step" id="step-review">
        <div class="stepper-node">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
            </svg>
        </div>
        <span class="stepper-label">Desk Review</span>
    </div>

    <div class="stepper-step" id="step-action">
        <div class="stepper-node">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
            </svg>
        </div>
        <span class="stepper-label">Unit Dispatched</span>
    </div>

    <div class="stepper-step" id="step-resolved">
        <div class="stepper-node">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <path d="m9 12 2 2 4-4"></path>
            </svg>
        </div>
        <span class="stepper-label">Resolved</span>
    </div>
</div>

    <div style="max-height: calc(92vh - 240px); overflow-y: auto; padding-right: 6px;">
        <!-- Core Details -->
        <div id="reportDetailContent" class="detail-content" style="border-bottom: 1px solid var(--line); padding-bottom: 16px; margin-bottom: 16px;"></div>

        <!-- Timeline Log -->
        <h3 style="font-size: 13px; font-weight: 800; font-family: 'Manrope', sans-serif; margin-bottom: 12px; color: var(--ink);">Milestone History</h3>
        <div class="tracking-timeline" id="trackingTimeline"></div>
    </div>

    <div class="dialog-actions" style="margin-top: 14px; padding-top: 10px; border-top: 1px solid var(--line);">
        <button class="button button-quiet" type="button" data-close-dialog>Close</button>
    </div>
</dialog>

    <script src="resident-dashboard.js?v=<?= time() ?>"></script>
</body>
</html>