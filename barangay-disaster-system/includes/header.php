<?php
/**
 * Global HTML Header
 * Include at the top of every page: include APP_ROOT . '/includes/header.php';
 * Set $page_title before including.
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

$page_title    = $page_title ?? 'Dashboard';
$current_flash = get_flash();

// Fetch unread notification count for nav badge
$unread_count = 0;
try {
    $stmt = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([current_user_id()]);
    $unread_count = (int)$stmt->fetchColumn();
} catch (PDOException $e) { /* Fail silently on nav */ }

// Fetch system settings for barangay name / hotlines
$settings = [];
try {
    $rows = db()->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll();
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) { /* Fail silently */ }

$brgy_name = $settings['barangay_name'] ?? 'Barangay';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title><?= htmlspecialchars($page_title) ?> | <?= htmlspecialchars($brgy_name) ?> BDRS</title>

  <!-- Bootstrap 5.3 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js" defer></script>
  <!-- Custom CSS -->
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/sidebar.css">
</head>
<body class="d-flex">

<!-- ═══════════════════════════════════════════════════════════
     SIDEBAR NAVIGATION
════════════════════════════════════════════════════════════ -->
<nav id="sidebar" class="sidebar d-flex flex-column flex-shrink-0 p-0">

  <!-- Brand -->
  <a href="<?= APP_URL ?>/modules/dashboard/index.php" class="sidebar-brand d-flex align-items-center gap-2 px-3 py-3 text-decoration-none">
    <div class="sidebar-brand-icon">
      <i class="bi bi-shield-fill-exclamation"></i>
    </div>
    <div class="sidebar-brand-text">
      <span class="fw-bold d-block lh-1" style="font-size:.78rem"><?= htmlspecialchars($brgy_name) ?></span>
      <span class="text-muted" style="font-size:.68rem">Disaster Response System</span>
    </div>
  </a>

  <hr class="sidebar-divider my-0">

  <!-- Nav -->
  <ul class="nav flex-column px-2 py-2 flex-grow-1" id="sidebarNav">

    <!-- Dashboard -->
    <li class="nav-item">
      <a class="nav-link sidebar-link <?= ($active_page ?? '') === 'dashboard' ? 'active' : '' ?>"
         href="<?= APP_URL ?>/modules/dashboard/index.php">
        <i class="bi bi-speedometer2"></i>
        <span>Dashboard</span>
      </a>
    </li>

    <!-- Incidents -->
    <li class="nav-item">
      <a class="nav-link sidebar-link <?= ($active_page ?? '') === 'incidents' ? 'active' : '' ?>"
         href="<?= APP_URL ?>/modules/incidents/index.php">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span>Incident Reports</span>
        <?php
        // Show badge if there are pending incidents (officials only)
        if (is_official()):
            $pending = db()->query("SELECT COUNT(*) FROM incidents WHERE status = 'pending'")->fetchColumn();
            if ($pending > 0): ?>
              <span class="badge badge-sidebar ms-auto"><?= $pending ?></span>
        <?php endif; endif; ?>
      </a>
    </li>

    <!-- Rescue Requests -->
    <li class="nav-item">
      <a class="nav-link sidebar-link <?= ($active_page ?? '') === 'rescue' ? 'active' : '' ?>"
         href="<?= APP_URL ?>/modules/rescue/index.php">
        <i class="bi bi-life-preserver"></i>
        <span>Rescue Requests</span>
        <?php
        $sos = db()->query("SELECT COUNT(*) FROM rescue_requests WHERE status NOT IN ('completed','cancelled')")->fetchColumn();
        if ($sos > 0): ?>
          <span class="badge badge-sidebar badge-sos ms-auto"><?= $sos ?></span>
        <?php endif; ?>
      </a>
    </li>

    <!-- Announcements -->
    <li class="nav-item">
      <a class="nav-link sidebar-link <?= ($active_page ?? '') === 'announcements' ? 'active' : '' ?>"
         href="<?= APP_URL ?>/modules/announcements/index.php">
        <i class="bi bi-megaphone-fill"></i>
        <span>Announcements</span>
      </a>
    </li>

    <!-- Evacuation Centers -->
    <li class="nav-item">
      <a class="nav-link sidebar-link <?= ($active_page ?? '') === 'evacuation' ? 'active' : '' ?>"
         href="<?= APP_URL ?>/modules/evacuation/index.php">
        <i class="bi bi-house-door-fill"></i>
        <span>Evacuation Centers</span>
      </a>
    </li>

    <!-- Relief Distribution -->
    <li class="nav-item">
      <a class="nav-link sidebar-link <?= ($active_page ?? '') === 'relief' ? 'active' : '' ?>"
         href="<?= APP_URL ?>/modules/relief/index.php">
        <i class="bi bi-box-seam-fill"></i>
        <span>Relief Distribution</span>
      </a>
    </li>

    <!-- Missing Persons -->
    <li class="nav-item">
      <a class="nav-link sidebar-link <?= ($active_page ?? '') === 'missing' ? 'active' : '' ?>"
         href="<?= APP_URL ?>/modules/missing/index.php">
        <i class="bi bi-person-fill-exclamation"></i>
        <span>Missing Persons</span>
        <?php
        $missing = db()->query("SELECT COUNT(*) FROM missing_persons WHERE status = 'missing'")->fetchColumn();
        if ($missing > 0): ?>
          <span class="badge badge-sidebar badge-warning ms-auto"><?= $missing ?></span>
        <?php endif; ?>
      </a>
    </li>

    <?php if (is_official()): ?>
    <hr class="sidebar-divider">
    <li class="nav-item sidebar-section-label">MANAGEMENT</li>

    <!-- Responders -->
    <li class="nav-item">
      <a class="nav-link sidebar-link <?= ($active_page ?? '') === 'responders' ? 'active' : '' ?>"
         href="<?= APP_URL ?>/modules/responders/index.php">
        <i class="bi bi-person-badge-fill"></i>
        <span>Responders</span>
      </a>
    </li>

    <!-- Reports -->
    <li class="nav-item">
      <a class="nav-link sidebar-link <?= ($active_page ?? '') === 'reports' ? 'active' : '' ?>"
         href="<?= APP_URL ?>/modules/reports/index.php">
        <i class="bi bi-file-earmark-bar-graph-fill"></i>
        <span>Reports & Analytics</span>
      </a>
    </li>
    <?php endif; ?>

    <?php if (is_admin()): ?>
    <hr class="sidebar-divider">
    <li class="nav-item sidebar-section-label">ADMIN</li>

    <li class="nav-item">
      <a class="nav-link sidebar-link <?= ($active_page ?? '') === 'users' ? 'active' : '' ?>"
         href="<?= APP_URL ?>/modules/settings/users.php">
        <i class="bi bi-people-fill"></i>
        <span>User Management</span>
      </a>
    </li>

    <li class="nav-item">
      <a class="nav-link sidebar-link <?= ($active_page ?? '') === 'settings' ? 'active' : '' ?>"
         href="<?= APP_URL ?>/modules/settings/index.php">
        <i class="bi bi-gear-fill"></i>
        <span>System Settings</span>
      </a>
    </li>

    <li class="nav-item">
      <a class="nav-link sidebar-link <?= ($active_page ?? '') === 'backup' ? 'active' : '' ?>"
         href="<?= APP_URL ?>/modules/settings/backup.php">
        <i class="bi bi-cloud-arrow-down-fill"></i>
        <span>Backup &amp; Restore</span>
      </a>
    </li>

    <li class="nav-item">
      <a class="nav-link sidebar-link <?= ($active_page ?? '') === 'logs' ? 'active' : '' ?>"
         href="<?= APP_URL ?>/modules/settings/logs.php">
        <i class="bi bi-journal-text"></i>
        <span>Activity Logs</span>
      </a>
    </li>
    <?php endif; ?>

  </ul>

  <!-- Emergency Hotlines (bottom of sidebar) -->
  <div class="sidebar-hotlines px-3 py-2">
    <div class="hotline-label">EMERGENCY HOTLINES</div>
    <?php if (!empty($settings['emergency_hotline_1'])): ?>
    <div class="hotline-number"><i class="bi bi-telephone-fill"></i> <?= clean($settings['emergency_hotline_1']) ?></div>
    <?php endif; ?>
    <?php if (!empty($settings['mdrrmo_hotline'])): ?>
    <div class="hotline-number"><i class="bi bi-shield-fill"></i> MDRRMO: <?= clean($settings['mdrrmo_hotline']) ?></div>
    <?php endif; ?>
  </div>

</nav>
<!-- END SIDEBAR -->

<!-- ═══════════════════════════════════════════════════════════
     MAIN CONTENT WRAPPER
════════════════════════════════════════════════════════════ -->
<div class="main-wrapper flex-grow-1 d-flex flex-column">

  <!-- TOP NAVIGATION BAR -->
  <nav class="topbar navbar navbar-expand px-3 py-2 d-flex align-items-center justify-content-between">

    <!-- Sidebar toggle -->
    <button class="btn btn-link sidebar-toggle me-2 p-0" id="sidebarToggle" aria-label="Toggle sidebar">
      <i class="bi bi-list fs-4"></i>
    </button>

    <!-- Page title breadcrumb -->
    <span class="topbar-title d-none d-md-inline fw-semibold"><?= htmlspecialchars($page_title) ?></span>

    <div class="d-flex align-items-center gap-2 ms-auto">

      <!-- Dark mode toggle -->
      <button class="btn btn-link topbar-btn" id="darkModeToggle" title="Toggle dark mode" aria-label="Toggle dark mode">
        <i class="bi bi-moon-fill" id="darkModeIcon"></i>
      </button>

      <!-- Notifications -->
      <div class="dropdown">
        <button class="btn btn-link topbar-btn position-relative" data-bs-toggle="dropdown" aria-label="Notifications">
          <i class="bi bi-bell-fill"></i>
          <?php if ($unread_count > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem">
              <?= $unread_count > 99 ? '99+' : $unread_count ?>
            </span>
          <?php endif; ?>
        </button>
        <div class="dropdown-menu dropdown-menu-end notification-dropdown shadow" style="width:320px;max-height:400px;overflow-y:auto">
          <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
            <strong>Notifications</strong>
            <a href="<?= APP_URL ?>/modules/notifications/mark_all_read.php" class="small text-muted text-decoration-none">Mark all read</a>
          </div>
          <div id="notificationList">
            <?php
            try {
                $notifs = db()->prepare(
                    "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10"
                );
                $notifs->execute([current_user_id()]);
                $notif_rows = $notifs->fetchAll();
                if (empty($notif_rows)): ?>
                  <div class="px-3 py-3 text-muted text-center small">No notifications</div>
                <?php else:
                  foreach ($notif_rows as $n): ?>
                    <a href="<?= $n['link_url'] ? htmlspecialchars($n['link_url']) : '#' ?>"
                       class="dropdown-item notification-item py-2 <?= $n['is_read'] ? '' : 'unread' ?>">
                      <div class="fw-semibold" style="font-size:.82rem"><?= htmlspecialchars($n['title']) ?></div>
                      <div class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($n['message']) ?></div>
                      <div class="text-muted" style="font-size:.7rem"><?= date('M d, Y h:i A', strtotime($n['created_at'])) ?></div>
                    </a>
                <?php endforeach; endif;
            } catch (PDOException $e) { echo '<div class="px-3 py-2 text-muted small">Could not load notifications</div>'; }
            ?>
          </div>
          <div class="border-top px-3 py-2 text-center">
            <a href="<?= APP_URL ?>/modules/notifications/index.php" class="small">View all notifications</a>
          </div>
        </div>
      </div>

      <!-- User dropdown -->
      <div class="dropdown">
        <button class="btn btn-link topbar-btn d-flex align-items-center gap-1" data-bs-toggle="dropdown">
          <div class="topbar-avatar">
            <?= strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)) ?>
          </div>
          <span class="d-none d-md-inline small fw-medium"><?= htmlspecialchars($_SESSION['username'] ?? '') ?></span>
          <i class="bi bi-chevron-down" style="font-size:.65rem"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow">
          <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/auth/profile.php"><i class="bi bi-person-circle me-2"></i>My Profile</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/modules/auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
        </ul>
      </div>

    </div>
  </nav>
  <!-- END TOPBAR -->

  <!-- Flash messages -->
  <?php if ($current_flash): ?>
  <div class="alert alert-<?= htmlspecialchars($current_flash['type']) ?> alert-dismissible fade show m-3 mb-0" role="alert">
    <?= htmlspecialchars($current_flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <!-- PAGE CONTENT STARTS -->
  <main class="main-content p-3 p-md-4 flex-grow-1">
