<?php
/**
 * Reports & Analytics — Officials & Admin
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role([ROLE_OFFICIAL, ROLE_ADMIN]);

$page_title  = 'Reports & Analytics';
$active_page = 'reports';

$report_type = $_GET['report'] ?? 'summary';
$date_from   = $_GET['date_from'] ?? date('Y-m-01');
$date_to     = $_GET['date_to']   ?? date('Y-m-d');

try {
    // ── Summary report data ──────────────────────────────────────
    $summary = [
        'incidents_total'   => (int)db()->prepare("SELECT COUNT(*) FROM incidents WHERE DATE(created_at) BETWEEN ? AND ?")->execute([$date_from, $date_to]) ? db()->query("SELECT COUNT(*) FROM incidents WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'")->fetchColumn() : 0,
        'rescues_total'     => (int)db()->query("SELECT COUNT(*) FROM rescue_requests WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'")->fetchColumn(),
        'resolved'          => (int)db()->query("SELECT COUNT(*) FROM incidents WHERE status='resolved' AND DATE(updated_at) BETWEEN '$date_from' AND '$date_to'")->fetchColumn(),
        'missing_total'     => (int)db()->query("SELECT COUNT(*) FROM missing_persons WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'")->fetchColumn(),
        'missing_found'     => (int)db()->query("SELECT COUNT(*) FROM missing_persons WHERE status IN ('found_safe','found_injured') AND DATE(updated_at) BETWEEN '$date_from' AND '$date_to'")->fetchColumn(),
        'distributions'     => (int)db()->query("SELECT COUNT(*) FROM relief_distributions WHERE DATE(distributed_at) BETWEEN '$date_from' AND '$date_to'")->fetchColumn(),
        'beneficiaries'     => (int)db()->query("SELECT COALESCE(SUM(total_beneficiaries),0) FROM relief_distributions WHERE DATE(distributed_at) BETWEEN '$date_from' AND '$date_to'")->fetchColumn(),
    ];

    // Incidents by type
    $by_type = db()->query(
        "SELECT incident_type, COUNT(*) AS cnt,
                SUM(severity = 'critical') AS critical_cnt,
                SUM(status = 'resolved') AS resolved_cnt
         FROM incidents
         WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'
         GROUP BY incident_type ORDER BY cnt DESC"
    )->fetchAll();

    // Incidents by severity
    $by_severity = db()->query(
        "SELECT severity, COUNT(*) AS cnt FROM incidents
         WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'
         GROUP BY severity ORDER BY FIELD(severity,'critical','high','moderate','low')"
    )->fetchAll();

    // Rescue by status
    $rescue_by_status = db()->query(
        "SELECT status, COUNT(*) AS cnt FROM rescue_requests
         WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'
         GROUP BY status"
    )->fetchAll();

    // Top incident locations
    $top_locations = db()->query(
        "SELECT location_address, COUNT(*) AS cnt FROM incidents
         WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'
         GROUP BY location_address ORDER BY cnt DESC LIMIT 10"
    )->fetchAll();

    // Recent incident list for tabular report
    $incident_list = db()->query(
        "SELECT i.reference_number, i.incident_type, i.severity, i.status,
                i.title, i.location_address, i.estimated_affected,
                i.reported_at,
                CONCAT(COALESCE(r.first_name,''),' ',COALESCE(r.last_name,'')) AS reporter
         FROM incidents i
         LEFT JOIN residents r ON r.user_id = i.reporter_user_id
         WHERE DATE(i.created_at) BETWEEN '$date_from' AND '$date_to'
         ORDER BY i.created_at DESC"
    )->fetchAll();

} catch (PDOException $e) {
    error_log($e->getMessage());
    $summary = $by_type = $by_severity = $rescue_by_status = $top_locations = $incident_list = [];
}

include APP_ROOT . '/includes/header.php';
?>

<!-- Header -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4 no-print">
  <div>
    <h4 class="fw-bold mb-0">Reports & Analytics</h4>
    <p class="text-muted mb-0" style="font-size:.82rem">
      Period: <?= date('M d, Y', strtotime($date_from)) ?> — <?= date('M d, Y', strtotime($date_to)) ?>
    </p>
  </div>
  <button class="btn btn-outline-dark" onclick="printPage()">
    <i class="bi bi-printer me-1"></i>Print Report
  </button>
</div>

<!-- Date filter -->
<div class="card mb-4 no-print">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-3">
        <label class="form-label mb-1" style="font-size:.8rem">Date From</label>
        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= $date_from ?>">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label mb-1" style="font-size:.8rem">Date To</label>
        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= $date_to ?>">
      </div>
      <div class="col-12 col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100">Generate</button>
      </div>
      <div class="col-12 col-md-4 d-flex gap-1 flex-wrap">
        <a href="?date_from=<?= date('Y-m-01') ?>&date_to=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary btn-sm">This Month</a>
        <a href="?date_from=<?= date('Y-01-01') ?>&date_to=<?= date('Y-12-31') ?>" class="btn btn-outline-secondary btn-sm">This Year</a>
        <a href="?date_from=2020-01-01&date_to=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary btn-sm">All Time</a>
      </div>
    </form>
  </div>
</div>

<!-- Print header (visible only on print) -->
<div class="d-none d-print-block text-center mb-4">
  <h3 class="fw-bold">Disaster Response Incident Report</h3>
  <p>Period: <?= date('F d, Y', strtotime($date_from)) ?> to <?= date('F d, Y', strtotime($date_to)) ?></p>
  <p>Generated: <?= date('F d, Y h:i A') ?></p>
  <hr>
</div>

<!-- Summary KPIs -->
<div class="row g-3 mb-4">
  <?php
  $kpi_cards = [
    ['Total Incidents',     $summary['incidents_total'] ?? 0,  'danger',  'bi-exclamation-triangle'],
    ['Rescue Requests',     $summary['rescues_total'] ?? 0,    'warning', 'bi-life-preserver'],
    ['Incidents Resolved',  $summary['resolved'] ?? 0,         'success', 'bi-check-circle'],
    ['Missing Reports',     $summary['missing_total'] ?? 0,    'primary', 'bi-person-fill-exclamation'],
    ['Persons Found',       $summary['missing_found'] ?? 0,    'success', 'bi-person-check'],
    ['Distributions',       $summary['distributions'] ?? 0,    'primary', 'bi-box-seam'],
    ['Total Beneficiaries', $summary['beneficiaries'] ?? 0,    'success', 'bi-people'],
  ];
  foreach ($kpi_cards as [$label, $value, $cls, $icon]): ?>
  <div class="col-6 col-md-3 col-lg">
    <div class="card stat-card <?= $cls ?> p-3">
      <div class="d-flex align-items-center gap-2">
        <div class="stat-icon"><i class="<?= $icon ?>"></i></div>
        <div>
          <div class="stat-value"><?= number_format($value) ?></div>
          <div class="stat-label"><?= $label ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Charts row -->
<div class="row g-4 mb-4 no-print">
  <div class="col-md-6">
    <div class="card">
      <div class="card-header fw-semibold">Incidents by Type</div>
      <div class="card-body">
        <canvas id="chartByType" style="max-height:220px"></canvas>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card">
      <div class="card-header fw-semibold">Incidents by Severity</div>
      <div class="card-body">
        <canvas id="chartBySeverity" style="max-height:220px"></canvas>
      </div>
    </div>
  </div>
</div>

<!-- Incidents by type table -->
<div class="card mb-4">
  <div class="card-header fw-semibold">Breakdown by Incident Type</div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead>
        <tr>
          <th class="ps-3">Incident Type</th>
          <th class="text-center">Total</th>
          <th class="text-center">Critical</th>
          <th class="text-center">Resolved</th>
          <th class="text-center">Resolution Rate</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($by_type as $bt):
          $rate = $bt['cnt'] > 0 ? round(($bt['resolved_cnt'] / $bt['cnt']) * 100) : 0;
          $type_label = INCIDENT_TYPES[$bt['incident_type']]['label'] ?? ucfirst($bt['incident_type']);
        ?>
        <tr>
          <td class="ps-3 fw-medium"><?= $type_label ?></td>
          <td class="text-center fw-bold"><?= $bt['cnt'] ?></td>
          <td class="text-center"><?= $bt['critical_cnt'] > 0 ? '<span class="text-danger fw-bold">'.$bt['critical_cnt'].'</span>' : '—' ?></td>
          <td class="text-center text-success"><?= $bt['resolved_cnt'] ?></td>
          <td class="text-center">
            <div class="d-flex align-items-center gap-1 justify-content-center">
              <div class="progress flex-grow-1" style="height:5px;max-width:60px">
                <div class="progress-bar bg-success" style="width:<?= $rate ?>%"></div>
              </div>
              <span style="font-size:.78rem"><?= $rate ?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($by_type)): ?>
        <tr><td colspan="5" class="text-center text-muted py-3">No incidents in selected period.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Top incident locations -->
<?php if (!empty($top_locations)): ?>
<div class="card mb-4">
  <div class="card-header fw-semibold">Top Incident Locations</div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead>
        <tr><th class="ps-3">Location</th><th class="text-center">Incidents</th></tr>
      </thead>
      <tbody>
        <?php $max_cnt = $top_locations[0]['cnt'];
        foreach ($top_locations as $loc): ?>
        <tr>
          <td class="ps-3" style="font-size:.85rem"><?= htmlspecialchars($loc['location_address']) ?></td>
          <td class="text-center">
            <div class="d-flex align-items-center gap-2 justify-content-center">
              <div class="progress" style="height:5px;width:80px">
                <div class="progress-bar bg-danger" style="width:<?= ($loc['cnt']/$max_cnt)*100 ?>%"></div>
              </div>
              <span class="fw-bold" style="font-size:.85rem"><?= $loc['cnt'] ?></span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Detailed incident list -->
<div class="card">
  <div class="card-header fw-semibold d-flex justify-content-between">
    Incident List (<?= count($incident_list) ?> records)
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm mb-0" style="font-size:.78rem">
        <thead>
          <tr>
            <th class="ps-3">Reference</th>
            <th>Type</th>
            <th>Severity</th>
            <th>Status</th>
            <th>Title</th>
            <th>Location</th>
            <th>Affected</th>
            <th>Reporter</th>
            <th>Reported</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($incident_list as $inc): ?>
          <tr>
            <td class="ps-3 fw-semibold"><?= htmlspecialchars($inc['reference_number']) ?></td>
            <td><?= htmlspecialchars(INCIDENT_TYPES[$inc['incident_type']]['label'] ?? $inc['incident_type']) ?></td>
            <td><span class="badge badge-severity-<?= $inc['severity'] ?> text-white" style="font-size:.65rem"><?= ucfirst($inc['severity']) ?></span></td>
            <td><span class="badge badge-status-<?= $inc['status'] ?> text-white" style="font-size:.65rem"><?= ucfirst(str_replace('_',' ',$inc['status'])) ?></span></td>
            <td class="text-truncate" style="max-width:150px"><?= htmlspecialchars($inc['title']) ?></td>
            <td class="text-truncate" style="max-width:120px"><?= htmlspecialchars($inc['location_address']) ?></td>
            <td class="text-center"><?= $inc['estimated_affected'] ?? '—' ?></td>
            <td><?= htmlspecialchars(trim($inc['reporter'])) ?: '—' ?></td>
            <td style="white-space:nowrap"><?= date('M d, Y', strtotime($inc['reported_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($incident_list)): ?>
          <tr><td colspan="9" class="text-center text-muted py-3">No incidents in selected period.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>

<script>
const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
const gridColor = isDark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.07)';
const textColor = isDark ? '#94a3b8' : '#64748b';

// By type
const ctxType = document.getElementById('chartByType');
if (ctxType) {
  new Chart(ctxType, {
    type: 'bar',
    data: {
      labels: <?= json_encode(array_map(fn($r) => INCIDENT_TYPES[$r['incident_type']]['label'] ?? $r['incident_type'], $by_type)) ?>,
      datasets: [{
        label: 'Incidents',
        data: <?= json_encode(array_column($by_type, 'cnt')) ?>,
        backgroundColor: '#1565c0',
        borderRadius: 4,
      }]
    },
    options: {
      responsive: true,
      indexAxis: 'y',
      plugins: { legend: { display: false } },
      scales: {
        x: { ticks: { color: textColor, font: { size: 11 } }, grid: { color: gridColor } },
        y: { ticks: { color: textColor, font: { size: 11 } }, grid: { display: false } }
      }
    }
  });
}

// By severity
const ctxSev = document.getElementById('chartBySeverity');
if (ctxSev) {
  const sevColors = { critical: '#6f1111', high: '#c0392b', moderate: '#e67e22', low: '#198754' };
  const sevData = <?= json_encode($by_severity) ?>;
  new Chart(ctxSev, {
    type: 'doughnut',
    data: {
      labels: sevData.map(r => r.severity.charAt(0).toUpperCase() + r.severity.slice(1)),
      datasets: [{
        data: sevData.map(r => r.cnt),
        backgroundColor: sevData.map(r => sevColors[r.severity] || '#94a3b8'),
        borderWidth: 2,
        borderColor: isDark ? '#1e2535' : '#fff',
      }]
    },
    options: {
      responsive: true,
      cutout: '60%',
      plugins: {
        legend: { position: 'bottom', labels: { color: textColor, font: { size: 11 } } }
      }
    }
  });
}
</script>
