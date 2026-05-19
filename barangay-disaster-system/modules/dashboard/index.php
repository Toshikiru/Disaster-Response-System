<?php
/**
 * Main Dashboard
 * Displays KPIs, recent incidents, rescue queue, and analytics charts.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

$page_title  = 'Dashboard';
$active_page = 'dashboard';

// ── Fetch all dashboard data ─────────────────────────────────────
try {
    // KPI row
    $kpi = db()->query("SELECT * FROM v_dashboard_kpis")->fetch();

    // Recent incidents (last 10)
    $recent_incidents = db()->query(
        "SELECT i.*, r.first_name, r.last_name, r.contact_number
         FROM incidents i
         LEFT JOIN residents r ON r.user_id = i.reporter_user_id
         ORDER BY i.created_at DESC LIMIT 10"
    )->fetchAll();

    // Active rescue queue (not completed/cancelled)
    $rescue_queue = db()->query(
        "SELECT * FROM v_rescue_queue ORDER BY
         FIELD(priority,'sos','critical','high','medium','low'),
         created_at ASC
         LIMIT 8"
    )->fetchAll();

    // Incident counts by type (for chart)
    $type_counts = db()->query(
        "SELECT incident_type, COUNT(*) as cnt
         FROM incidents
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY incident_type ORDER BY cnt DESC"
    )->fetchAll();

    // Incidents by day (last 14 days)
    $daily_counts = db()->query(
        "SELECT DATE(reported_at) as day, COUNT(*) as cnt
         FROM incidents
         WHERE reported_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
         GROUP BY DATE(reported_at) ORDER BY day ASC"
    )->fetchAll();

    // Latest announcements (3)
    $announcements = db()->query(
        "SELECT a.*, u.username FROM announcements a
         JOIN users u ON u.id = a.posted_by_id
         WHERE a.is_active = 1 AND (a.expires_at IS NULL OR a.expires_at > NOW())
         ORDER BY a.is_pinned DESC, a.created_at DESC LIMIT 3"
    )->fetchAll();

    // Evacuation centers summary
    $evac_centers = db()->query(
        "SELECT * FROM evacuation_centers WHERE status IN ('active','standby') ORDER BY status ASC LIMIT 5"
    )->fetchAll();

    // Missing persons count
    $active_rescues_count = $kpi['active_rescues'] ?? 0;

} catch (PDOException $e) {
    error_log($e->getMessage());
    $kpi = [];
    $recent_incidents = $rescue_queue = $type_counts = $daily_counts = $announcements = $evac_centers = [];
}

// ── Prepare chart data as JSON ────────────────────────────────────
$chart_type_labels = json_encode(array_map(fn($r) => ucwords(str_replace('_', ' ', $r['incident_type'])), $type_counts));
$chart_type_data   = json_encode(array_column($type_counts, 'cnt'));

$chart_daily_labels = json_encode(array_map(fn($r) => date('M d', strtotime($r['day'])), $daily_counts));
$chart_daily_data   = json_encode(array_column($daily_counts, 'cnt'));

include APP_ROOT . '/includes/header.php';
?>

<!-- ── Emergency banner (shows if critical incidents exist) ────────── -->
<?php
$crit_count = 0;
try {
    $crit_count = (int)db()->query(
        "SELECT COUNT(*) FROM incidents WHERE severity = 'critical' AND status NOT IN ('resolved','archived')"
    )->fetchColumn();
} catch (PDOException $e) {}
if ($crit_count > 0): ?>
<div class="emergency-banner">
  <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0"></i>
  <span>⚠ ACTIVE CRITICAL INCIDENTS: <?= $crit_count ?> ongoing — Coordinate with responders immediately.</span>
  <a href="<?= APP_URL ?>/modules/incidents/index.php?filter=critical" class="btn btn-sm btn-light ms-auto fw-bold py-0">View Now</a>
</div>
<?php endif; ?>

<!-- ── KPI Cards ────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">

  <!-- Active Incidents -->
  <div class="col-6 col-md-3">
    <div class="stat-card danger d-flex align-items-center gap-3 p-3">
      <div class="stat-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
      <div>
        <div class="stat-value"><?= number_format($kpi['active_incidents'] ?? 0) ?></div>
        <div class="stat-label">Active Incidents</div>
        <div class="stat-change text-danger">
          <?= ($kpi['pending_incidents'] ?? 0) ?> pending
        </div>
      </div>
    </div>
  </div>

  <!-- Active Rescues -->
  <div class="col-6 col-md-3">
    <div class="stat-card warning d-flex align-items-center gap-3 p-3">
      <div class="stat-icon"><i class="bi bi-life-preserver"></i></div>
      <div>
        <div class="stat-value"><?= number_format($kpi['active_rescues'] ?? 0) ?></div>
        <div class="stat-label">Rescue Requests</div>
        <div class="stat-change text-danger">
          <?= ($kpi['critical_rescues'] ?? 0) ?> critical / SOS
        </div>
      </div>
    </div>
  </div>

  <!-- Evacuees -->
  <div class="col-6 col-md-3">
    <div class="stat-card primary d-flex align-items-center gap-3 p-3">
      <div class="stat-icon"><i class="bi bi-house-door-fill"></i></div>
      <div>
        <div class="stat-value"><?= number_format($kpi['total_evacuees'] ?? 0) ?></div>
        <div class="stat-label">Total Evacuees</div>
        <div class="stat-change text-muted">
          <?= ($kpi['active_evac_centers'] ?? 0) ?> centers active
        </div>
      </div>
    </div>
  </div>

  <!-- Missing Persons -->
  <div class="col-6 col-md-3">
    <div class="stat-card success d-flex align-items-center gap-3 p-3">
      <div class="stat-icon"><i class="bi bi-person-fill-exclamation"></i></div>
      <div>
        <div class="stat-value"><?= number_format($kpi['missing_persons'] ?? 0) ?></div>
        <div class="stat-label">Missing Persons</div>
        <div class="stat-change text-muted">
          <?= ($kpi['available_responders'] ?? 0) ?> responders available
        </div>
      </div>
    </div>
  </div>

</div>

<!-- ── Second row: Incident types chart + Daily trend ───────────── -->
<div class="row g-3 mb-4">

  <div class="col-md-5">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-pie-chart-fill me-2 text-primary"></i>Incidents by Type (30 days)</span>
      </div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <div class="chart-container" style="max-width:260px;width:100%">
          <canvas id="chartByType"></canvas>
        </div>
      </div>
    </div>
  </div>

  <div class="col-md-7">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-graph-up me-2 text-primary"></i>Daily Incident Trend (14 days)</span>
      </div>
      <div class="card-body">
        <div class="chart-container">
          <canvas id="chartDaily"></canvas>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- ── Third row: Recent incidents + Rescue queue + Announcements ── -->
<div class="row g-3 mb-4">

  <!-- Recent Incidents table -->
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-exclamation-triangle me-2 text-danger"></i>Recent Incidents</span>
        <a href="<?= APP_URL ?>/modules/incidents/index.php" class="btn btn-sm btn-outline-primary">View All</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0" id="recentIncidentsTable">
            <thead>
              <tr>
                <th class="ps-3">Reference</th>
                <th>Type</th>
                <th>Severity</th>
                <th>Status</th>
                <th>Location</th>
                <th>Reported</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recent_incidents)): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">No incidents recorded yet.</td></tr>
              <?php else: foreach ($recent_incidents as $inc):
                  $type_info = INCIDENT_TYPES[$inc['incident_type']] ?? ['label' => $inc['incident_type'], 'icon' => 'bi-exclamation'];
              ?>
              <tr class="cursor-pointer" onclick="window.location='<?= APP_URL ?>/modules/incidents/view.php?id=<?= $inc['id'] ?>'">
                <td class="ps-3">
                  <a href="<?= APP_URL ?>/modules/incidents/view.php?id=<?= $inc['id'] ?>"
                     class="fw-semibold text-primary text-decoration-none" style="font-size:.8rem">
                    <?= htmlspecialchars($inc['reference_number']) ?>
                  </a>
                </td>
                <td style="font-size:.82rem"><?= htmlspecialchars($type_info['label']) ?></td>
                <td>
                  <span class="badge badge-severity-<?= $inc['severity'] ?> text-white" style="font-size:.7rem">
                    <?= ucfirst($inc['severity']) ?>
                  </span>
                </td>
                <td>
                  <span class="badge badge-status-<?= $inc['status'] ?> text-white" style="font-size:.7rem">
                    <?= ucfirst(str_replace('_', ' ', $inc['status'])) ?>
                  </span>
                </td>
                <td style="font-size:.78rem" class="text-truncate" style="max-width:130px">
                  <?= htmlspecialchars($inc['location_address']) ?>
                </td>
                <td style="font-size:.75rem" class="text-muted">
                  <span data-timestamp="<?= $inc['created_at'] ?>"></span>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Rescue queue + Announcements stack -->
  <div class="col-lg-5 d-flex flex-column gap-3">

    <!-- Active Rescue Queue -->
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-life-preserver me-2 text-warning"></i>Rescue Queue</span>
        <a href="<?= APP_URL ?>/modules/rescue/index.php" class="btn btn-sm btn-outline-warning">View All</a>
      </div>
      <div class="card-body p-0" style="max-height:220px;overflow-y:auto">
        <?php if (empty($rescue_queue)): ?>
          <div class="text-center text-muted py-3" style="font-size:.85rem">No active rescue requests.</div>
        <?php else: foreach ($rescue_queue as $rq): ?>
          <a href="<?= APP_URL ?>/modules/rescue/view.php?id=<?= $rq['id'] ?>"
             class="d-flex align-items-center gap-2 px-3 py-2 text-decoration-none border-bottom rescue-queue-item">
            <div class="flex-shrink-0">
              <span class="badge badge-severity-<?= $rq['priority'] === 'sos' ? 'critical' : ($rq['priority'] === 'critical' ? 'critical' : ($rq['priority'] === 'high' ? 'high' : 'low')) ?> text-white" style="font-size:.65rem;padding:.25em .55em">
                <?= strtoupper($rq['priority']) ?>
              </span>
            </div>
            <div class="flex-grow-1 overflow-hidden">
              <div class="fw-semibold" style="font-size:.8rem"><?= htmlspecialchars($rq['requestor_name'] ?? 'Unknown') ?></div>
              <div class="text-muted text-truncate" style="font-size:.72rem"><?= htmlspecialchars($rq['location_address']) ?></div>
            </div>
            <div class="flex-shrink-0 text-end">
              <div style="font-size:.7rem" class="text-muted"><?= $rq['number_of_persons'] ?> person(s)</div>
              <?php if ($rq['is_medical_emergency']): ?>
                <span class="badge bg-danger" style="font-size:.6rem">MEDICAL</span>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Latest Announcements -->
    <div class="card flex-grow-1">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-megaphone me-2 text-info"></i>Announcements</span>
        <?php if (is_official()): ?>
        <a href="<?= APP_URL ?>/modules/announcements/create.php" class="btn btn-sm btn-outline-info">+ Post</a>
        <?php endif; ?>
      </div>
      <div class="card-body p-2">
        <?php if (empty($announcements)): ?>
          <p class="text-muted text-center py-2" style="font-size:.85rem">No announcements yet.</p>
        <?php else: foreach ($announcements as $ann):
            $sev_cls = ['info' => 'info', 'warning' => 'warning', 'critical' => 'danger'][$ann['severity']] ?? 'secondary';
        ?>
          <div class="border-start border-4 border-<?= $sev_cls ?> ps-2 mb-2">
            <?php if ($ann['is_pinned']): ?>
              <span class="badge bg-warning text-dark me-1" style="font-size:.6rem">PINNED</span>
            <?php endif; ?>
            <div class="fw-semibold" style="font-size:.8rem"><?= htmlspecialchars($ann['title']) ?></div>
            <div class="text-muted text-truncate-2" style="font-size:.75rem"><?= htmlspecialchars($ann['body']) ?></div>
            <div class="text-muted" style="font-size:.7rem"><?= date('M d, Y h:i A', strtotime($ann['created_at'])) ?></div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

  </div>
</div>

<!-- ── Evacuation Centers row ────────────────────────────────────── -->
<div class="row g-3">
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-house-door me-2 text-primary"></i>Evacuation Centers Status</span>
        <a href="<?= APP_URL ?>/modules/evacuation/index.php" class="btn btn-sm btn-outline-primary">Manage</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th class="ps-3">Center Name</th>
                <th>Location</th>
                <th>Capacity</th>
                <th>Occupancy</th>
                <th>Status</th>
                <th>Contact</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($evac_centers)): ?>
              <tr><td colspan="6" class="text-center text-muted py-3">No evacuation centers registered.</td></tr>
              <?php else: foreach ($evac_centers as $ec):
                  $pct = $ec['capacity'] > 0 ? round(($ec['current_occupancy'] / $ec['capacity']) * 100) : 0;
                  $pct_cls = $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success');
                  $st_cls  = ['standby' => 'secondary', 'active' => 'success', 'full' => 'danger', 'closed' => 'dark'][$ec['status']] ?? 'secondary';
              ?>
              <tr>
                <td class="ps-3 fw-semibold" style="font-size:.85rem"><?= htmlspecialchars($ec['name']) ?></td>
                <td style="font-size:.8rem"><?= htmlspecialchars($ec['location_address']) ?></td>
                <td style="font-size:.82rem"><?= number_format($ec['capacity']) ?></td>
                <td style="min-width:120px">
                  <div class="d-flex align-items-center gap-2">
                    <div class="progress flex-grow-1" style="height:6px">
                      <div class="progress-bar <?= $pct_cls ?>" style="width:<?= $pct ?>%"></div>
                    </div>
                    <span style="font-size:.75rem;white-space:nowrap"><?= $ec['current_occupancy'] ?>/<?= $ec['capacity'] ?></span>
                  </div>
                </td>
                <td><span class="badge bg-<?= $st_cls ?>"><?= ucfirst($ec['status']) ?></span></td>
                <td style="font-size:.78rem"><?= htmlspecialchars($ec['contact_number'] ?? '—') ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>

<!-- ── Chart.js initialisation ───────────────────────────────────── -->
<script>
const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
const gridColor  = isDark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.07)';
const textColor  = isDark ? '#94a3b8' : '#64748b';

// Incident types doughnut chart
const ctxType = document.getElementById('chartByType');
if (ctxType) {
  new Chart(ctxType, {
    type: 'doughnut',
    data: {
      labels: <?= $chart_type_labels ?>,
      datasets: [{
        data: <?= $chart_type_data ?>,
        backgroundColor: ['#c0392b','#e67e22','#1565c0','#1b7a3e','#8e44ad','#2980b9','#e74c3c','#27ae60','#f39c12','#16a085','#7f8c8d'],
        borderWidth: 2,
        borderColor: isDark ? '#1e2535' : '#fff',
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      plugins: {
        legend: { position: 'bottom', labels: { color: textColor, font: { size: 11 }, padding: 8 } }
      },
      cutout: '62%',
    }
  });
}

// Daily trend line chart
const ctxDaily = document.getElementById('chartDaily');
if (ctxDaily) {
  new Chart(ctxDaily, {
    type: 'line',
    data: {
      labels: <?= $chart_daily_labels ?>,
      datasets: [{
        label: 'Incidents',
        data: <?= $chart_daily_data ?>,
        borderColor: '#c0392b',
        backgroundColor: 'rgba(192,57,43,.10)',
        fill: true,
        tension: .4,
        pointRadius: 4,
        pointBackgroundColor: '#c0392b',
        borderWidth: 2,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: { ticks: { color: textColor, font: { size: 11 } }, grid: { color: gridColor } },
        y: { ticks: { color: textColor, font: { size: 11 }, stepSize: 1 }, grid: { color: gridColor }, beginAtZero: true }
      },
      plugins: {
        legend: { display: false }
      }
    }
  });
}

// Re-render charts when dark mode toggled
document.getElementById('darkModeToggle')?.addEventListener('click', () => {
    setTimeout(() => location.reload(), 300);
});
</script>
