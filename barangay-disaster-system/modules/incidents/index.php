<?php
/**
 * Incident Reports — List View
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

$page_title  = 'Incident Reports';
$active_page = 'incidents';

// ── Filters ──────────────────────────────────────────────────────
$filter_type     = $_GET['type']     ?? 'all';
$filter_severity = $_GET['severity'] ?? 'all';
$filter_status   = $_GET['status']   ?? 'all';
$filter_date     = $_GET['date']     ?? '';
$search          = trim($_GET['search'] ?? '');
$page            = max(1, (int)($_GET['page'] ?? 1));
$per_page        = 20;

// ── Build WHERE clause ────────────────────────────────────────────
$where   = ['1=1'];
$params  = [];

if ($filter_type !== 'all') {
    $where[]  = 'i.incident_type = ?';
    $params[] = $filter_type;
}
if ($filter_severity !== 'all') {
    $where[]  = 'i.severity = ?';
    $params[] = $filter_severity;
}
if ($filter_status !== 'all') {
    $where[]  = 'i.status = ?';
    $params[] = $filter_status;
}
if ($filter_date !== '') {
    $where[]  = 'DATE(i.reported_at) = ?';
    $params[] = $filter_date;
}
if ($search !== '') {
    $where[]  = '(i.reference_number LIKE ? OR i.title LIKE ? OR i.location_address LIKE ?)';
    $like = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like]);
}

// Residents only see their own reports
if (is_resident()) {
    $where[]  = 'i.reporter_user_id = ?';
    $params[] = current_user_id();
}

$whereStr = implode(' AND ', $where);

try {
    // Count total
    $countStmt = db()->prepare("SELECT COUNT(*) FROM incidents i WHERE $whereStr");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $pag    = paginate($total, $per_page, $page);
    $offset = $pag['offset'];

    // Fetch page
    $stmt = db()->prepare(
        "SELECT i.*,
                CONCAT(COALESCE(r.first_name,''), ' ', COALESCE(r.last_name,'')) AS reporter_name,
                (SELECT COUNT(*) FROM responder_incident_assignments ria WHERE ria.incident_id = i.id AND ria.released_at IS NULL) AS responder_count
         FROM incidents i
         LEFT JOIN residents r ON r.user_id = i.reporter_user_id
         WHERE $whereStr
         ORDER BY
           FIELD(i.severity,'critical','high','moderate','low'),
           i.created_at DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->execute(array_merge($params, [$per_page, $offset]));
    $incidents = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log($e->getMessage());
    $incidents = [];
    $total = 0;
    $pag   = paginate(0, $per_page, 1);
}

include APP_ROOT . '/includes/header.php';
?>

<!-- Page header -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
  <div>
    <h4 class="fw-bold mb-0">Incident Reports</h4>
    <p class="text-muted mb-0" style="font-size:.82rem"><?= number_format($total) ?> record(s) found</p>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/modules/incidents/create.php" class="btn btn-primary">
      <i class="bi bi-plus-circle me-1"></i>Report Incident
    </a>
    <?php if (is_official()): ?>
    <a href="<?= APP_URL ?>/modules/reports/incidents.php" class="btn btn-outline-secondary">
      <i class="bi bi-download me-1"></i>Export
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- Filters -->
<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-12 col-md-3">
        <input type="text" name="search" class="form-control form-control-sm"
               placeholder="Search reference, title, location…" value="<?= htmlspecialchars($search) ?>">
      </div>
      <div class="col-6 col-md-2">
        <select name="type" class="form-select form-select-sm">
          <option value="all">All Types</option>
          <?php foreach (INCIDENT_TYPES as $val => $info): ?>
            <option value="<?= $val ?>" <?= $filter_type === $val ? 'selected' : '' ?>><?= $info['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <select name="severity" class="form-select form-select-sm">
          <option value="all">All Severities</option>
          <option value="critical" <?= $filter_severity === 'critical' ? 'selected' : '' ?>>Critical</option>
          <option value="high"     <?= $filter_severity === 'high'     ? 'selected' : '' ?>>High</option>
          <option value="moderate" <?= $filter_severity === 'moderate' ? 'selected' : '' ?>>Moderate</option>
          <option value="low"      <?= $filter_severity === 'low'      ? 'selected' : '' ?>>Low</option>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <select name="status" class="form-select form-select-sm">
          <option value="all">All Statuses</option>
          <option value="pending"      <?= $filter_status === 'pending'      ? 'selected' : '' ?>>Pending</option>
          <option value="acknowledged" <?= $filter_status === 'acknowledged' ? 'selected' : '' ?>>Acknowledged</option>
          <option value="ongoing"      <?= $filter_status === 'ongoing'      ? 'selected' : '' ?>>Ongoing</option>
          <option value="resolved"     <?= $filter_status === 'resolved'     ? 'selected' : '' ?>>Resolved</option>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <input type="date" name="date" class="form-control form-control-sm"
               value="<?= htmlspecialchars($filter_date) ?>" title="Filter by incident date">
      </div>
      <div class="col-12 col-md-1 d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
          <i class="bi bi-search"></i>
        </button>
        <a href="<?= APP_URL ?>/modules/incidents/index.php" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-x"></i>
        </a>
      </div>
    </form>
  </div>
</div>

<!-- Incidents table -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th class="ps-3">Reference</th>
            <th>Type</th>
            <th>Title</th>
            <th>Severity</th>
            <th>Status</th>
            <th>Location</th>
            <th>Affected</th>
            <th>Reporter</th>
            <th>Reported</th>
            <th class="text-end pe-3">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($incidents)): ?>
          <tr>
            <td colspan="10" class="text-center text-muted py-5">
              <i class="bi bi-inbox fs-3 d-block mb-2"></i>
              No incident records match your filters.
            </td>
          </tr>
          <?php else: foreach ($incidents as $inc):
              $type_info = INCIDENT_TYPES[$inc['incident_type']] ?? ['label' => $inc['incident_type'], 'icon' => 'bi-exclamation'];
          ?>
          <tr>
            <td class="ps-3">
              <a href="<?= APP_URL ?>/modules/incidents/view.php?id=<?= $inc['id'] ?>"
                 class="fw-semibold text-primary text-decoration-none" style="font-size:.8rem">
                <?= htmlspecialchars($inc['reference_number']) ?>
              </a>
            </td>
            <td>
              <div class="d-flex align-items-center gap-1" style="font-size:.82rem">
                <i class="<?= $type_info['icon'] ?>"></i>
                <?= htmlspecialchars($type_info['label']) ?>
              </div>
            </td>
            <td>
              <div class="fw-medium text-truncate" style="max-width:160px;font-size:.83rem">
                <?= htmlspecialchars($inc['title']) ?>
              </div>
            </td>
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
            <td class="text-truncate" style="max-width:130px;font-size:.78rem">
              <?= htmlspecialchars($inc['location_address']) ?>
            </td>
            <td style="font-size:.8rem">
              <?= $inc['estimated_affected'] ? number_format($inc['estimated_affected']) : '—' ?>
            </td>
            <td style="font-size:.78rem"><?= htmlspecialchars($inc['reporter_name']) ?></td>
            <td style="font-size:.75rem" class="text-muted">
              <span data-timestamp="<?= $inc['created_at'] ?>"></span>
            </td>
            <td class="text-end pe-3">
              <div class="d-flex gap-1 justify-content-end">
                <a href="<?= APP_URL ?>/modules/incidents/view.php?id=<?= $inc['id'] ?>"
                   class="btn btn-sm btn-outline-primary py-0 px-2" title="View details">
                  <i class="bi bi-eye"></i>
                </a>
                <?php if (is_official()): ?>
                <a href="<?= APP_URL ?>/modules/incidents/edit.php?id=<?= $inc['id'] ?>"
                   class="btn btn-sm btn-outline-secondary py-0 px-2" title="Edit">
                  <i class="bi bi-pencil"></i>
                </a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Pagination -->
  <?php if ($pag['total_pages'] > 1): ?>
  <div class="card-footer d-flex justify-content-between align-items-center py-2">
    <small class="text-muted">
      Showing <?= ($pag['offset'] + 1) ?>–<?= min($pag['offset'] + $per_page, $total) ?> of <?= number_format($total) ?>
    </small>
    <nav>
      <ul class="pagination pagination-sm mb-0">
        <?php
        $base_url = '?' . http_build_query(array_filter([
            'type' => $filter_type !== 'all' ? $filter_type : '',
            'severity' => $filter_severity !== 'all' ? $filter_severity : '',
            'status' => $filter_status !== 'all' ? $filter_status : '',
            'search' => $search,
            'date' => $filter_date,
        ]));
        for ($p = 1; $p <= $pag['total_pages']; $p++):
        ?>
        <li class="page-item <?= $p === $pag['current_page'] ? 'active' : '' ?>">
          <a class="page-link" href="<?= $base_url ?>&page=<?= $p ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
      </ul>
    </nav>
  </div>
  <?php endif; ?>

</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
