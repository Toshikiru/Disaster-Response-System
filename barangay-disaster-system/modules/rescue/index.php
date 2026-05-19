<?php
/**
 * Rescue Requests — Queue & Management
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

$page_title  = 'Rescue Requests';
$active_page = 'rescue';

$filter_status   = $_GET['status']   ?? 'active';
$filter_priority = $_GET['priority'] ?? 'all';
$search          = trim($_GET['search'] ?? '');
$page            = max(1, (int)($_GET['page'] ?? 1));
$per_page        = 20;

$where  = ['1=1'];
$params = [];

if ($filter_status === 'active') {
    $where[] = "rr.status NOT IN ('completed','cancelled')";
} elseif ($filter_status !== 'all') {
    $where[]  = 'rr.status = ?';
    $params[] = $filter_status;
}

if ($filter_priority !== 'all') {
    $where[]  = 'rr.priority = ?';
    $params[] = $filter_priority;
}

if ($search !== '') {
    $where[]  = "(rr.reference_number LIKE ? OR rr.location_address LIKE ?)";
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like]);
}

// Residents only see their own requests
if (is_resident()) {
    $where[]  = 'rr.requestor_user_id = ?';
    $params[] = current_user_id();
}

// Responders only see requests assigned to them
if (is_responder()) {
    $where[]  = '(rr.assigned_responder_id = (SELECT id FROM responders WHERE user_id = ?) OR rr.assigned_responder_id IS NULL)';
    $params[] = current_user_id();
}

$whereStr = implode(' AND ', $where);

try {
    $countStmt = db()->prepare("SELECT COUNT(*) FROM rescue_requests rr WHERE $whereStr");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pag   = paginate($total, $per_page, $page);

    $stmt = db()->prepare(
        "SELECT rr.*,
                CONCAT(COALESCE(res.first_name,''), ' ', COALESCE(res.last_name,'')) AS requestor_name,
                res.contact_number AS requestor_contact,
                CONCAT(COALESCE(rsp.first_name,''), ' ', COALESCE(rsp.last_name,'')) AS responder_name,
                rsp.contact_number AS responder_contact,
                rsp.responder_type
         FROM rescue_requests rr
         LEFT JOIN residents  res ON res.user_id = rr.requestor_user_id
         LEFT JOIN responders rsp ON rsp.id      = rr.assigned_responder_id
         WHERE $whereStr
         ORDER BY
           FIELD(rr.priority,'sos','critical','high','medium','low'),
           rr.created_at ASC
         LIMIT ? OFFSET ?"
    );
    $stmt->execute(array_merge($params, [$per_page, $pag['offset']]));
    $requests = $stmt->fetchAll();

    // Summary counts for tabs
    $counts = db()->query(
        "SELECT
           SUM(status NOT IN ('completed','cancelled')) AS active,
           SUM(priority IN ('sos','critical') AND status NOT IN ('completed','cancelled')) AS critical,
           SUM(status = 'completed') AS completed
         FROM rescue_requests"
    )->fetch();

} catch (PDOException $e) {
    error_log($e->getMessage());
    $requests = [];
    $total = 0;
    $pag   = paginate(0, $per_page, 1);
    $counts = [];
}

include APP_ROOT . '/includes/header.php';
?>

<!-- Page header -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
  <div>
    <h4 class="fw-bold mb-0">Rescue Requests</h4>
    <p class="text-muted mb-0" style="font-size:.82rem">
      <?= number_format($counts['active'] ?? 0) ?> active ·
      <span class="text-danger fw-semibold"><?= number_format($counts['critical'] ?? 0) ?> critical/SOS</span>
    </p>
  </div>
  <a href="<?= APP_URL ?>/modules/rescue/create.php" class="btn btn-sos px-4">
    <i class="bi bi-exclamation-circle-fill me-2"></i>SOS — Request Rescue
  </a>
</div>

<!-- Status tabs -->
<ul class="nav nav-tabs mb-4">
  <?php
  $tabs = [
    ['active',    'Active',    $counts['active']    ?? 0, 'danger'],
    ['completed', 'Completed', $counts['completed'] ?? 0, 'success'],
    ['all',       'All',       $total, 'secondary'],
  ];
  foreach ($tabs as [$val, $label, $cnt, $cls]): ?>
  <li class="nav-item">
    <a class="nav-link <?= $filter_status === $val ? 'active' : '' ?>"
       href="?status=<?= $val ?>">
      <?= $label ?>
      <span class="badge bg-<?= $cls ?> ms-1" style="font-size:.65rem"><?= $cnt ?></span>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<!-- Filters -->
<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
      <div class="col-12 col-md-5">
        <input type="text" name="search" class="form-control form-control-sm"
               placeholder="Search reference, location…" value="<?= htmlspecialchars($search) ?>">
      </div>
      <div class="col-6 col-md-3">
        <select name="priority" class="form-select form-select-sm">
          <option value="all">All Priorities</option>
          <option value="sos"      <?= $filter_priority === 'sos'      ? 'selected':'' ?>>SOS (Extreme)</option>
          <option value="critical" <?= $filter_priority === 'critical' ? 'selected':'' ?>>Critical</option>
          <option value="high"     <?= $filter_priority === 'high'     ? 'selected':'' ?>>High</option>
          <option value="medium"   <?= $filter_priority === 'medium'   ? 'selected':'' ?>>Medium</option>
          <option value="low"      <?= $filter_priority === 'low'      ? 'selected':'' ?>>Low</option>
        </select>
      </div>
      <div class="col-6 col-md-2 d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-search"></i></button>
        <a href="?status=<?= $filter_status ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
      </div>
    </form>
  </div>
</div>

<!-- Requests table -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th class="ps-3">Reference</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Requestor</th>
            <th>Location</th>
            <th>Persons</th>
            <th>Medical</th>
            <th>Responder</th>
            <th>Time</th>
            <?php if (is_official()): ?><th class="text-end pe-3">Actions</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($requests)): ?>
          <tr>
            <td colspan="10" class="text-center text-muted py-5">
              <i class="bi bi-life-preserver fs-3 d-block mb-2"></i>
              No rescue requests found.
            </td>
          </tr>
          <?php else: foreach ($requests as $rq):
              $pri_cls = match($rq['priority']) {
                  'sos','critical' => 'badge-severity-critical',
                  'high'           => 'badge-severity-high',
                  'medium'         => 'badge-severity-moderate',
                  default          => 'badge-severity-low',
              };
              $st_cls = match($rq['status']) {
                  'pending'    => 'badge-status-pending',
                  'dispatched','en_route','arrived' => 'badge-status-ongoing',
                  'completed'  => 'badge-status-resolved',
                  'cancelled'  => 'badge-status-archived',
                  default      => 'bg-secondary',
              };
          ?>
          <tr>
            <td class="ps-3">
              <a href="<?= APP_URL ?>/modules/rescue/view.php?id=<?= $rq['id'] ?>"
                 class="fw-semibold text-danger text-decoration-none" style="font-size:.8rem">
                <?= htmlspecialchars($rq['reference_number']) ?>
              </a>
            </td>
            <td>
              <span class="badge <?= $pri_cls ?> text-white" style="font-size:.7rem">
                <?= strtoupper($rq['priority']) ?>
              </span>
            </td>
            <td>
              <span class="badge <?= $st_cls ?> text-white" style="font-size:.7rem">
                <?= ucfirst(str_replace('_',' ',$rq['status'])) ?>
              </span>
            </td>
            <td>
              <div style="font-size:.82rem"><?= htmlspecialchars($rq['requestor_name']) ?></div>
              <div class="text-muted" style="font-size:.73rem"><?= htmlspecialchars($rq['requestor_contact'] ?? '') ?></div>
            </td>
            <td class="text-truncate" style="max-width:140px;font-size:.8rem">
              <?= htmlspecialchars($rq['location_address']) ?>
            </td>
            <td style="font-size:.82rem;text-align:center"><?= $rq['number_of_persons'] ?></td>
            <td class="text-center">
              <?php if ($rq['is_medical_emergency']): ?>
                <span class="badge bg-danger" style="font-size:.65rem">YES</span>
              <?php else: ?>
                <span class="text-muted" style="font-size:.78rem">—</span>
              <?php endif; ?>
            </td>
            <td style="font-size:.78rem">
              <?= $rq['responder_name'] ? htmlspecialchars($rq['responder_name']) : '<span class="text-muted">Unassigned</span>' ?>
            </td>
            <td style="font-size:.73rem" class="text-muted">
              <span data-timestamp="<?= $rq['created_at'] ?>"></span>
            </td>
            <?php if (is_official()): ?>
            <td class="text-end pe-3">
              <a href="<?= APP_URL ?>/modules/rescue/view.php?id=<?= $rq['id'] ?>"
                 class="btn btn-sm btn-outline-primary py-0 px-2">
                <i class="bi bi-eye"></i>
              </a>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Pagination -->
  <?php if ($pag['total_pages'] > 1): ?>
  <div class="card-footer d-flex justify-content-between align-items-center py-2">
    <small class="text-muted">Showing <?= ($pag['offset']+1) ?>–<?= min($pag['offset']+$per_page, $total) ?> of <?= number_format($total) ?></small>
    <nav>
      <ul class="pagination pagination-sm mb-0">
        <?php for ($p = 1; $p <= $pag['total_pages']; $p++): ?>
        <li class="page-item <?= $p === $pag['current_page'] ? 'active' : '' ?>">
          <a class="page-link" href="?status=<?= $filter_status ?>&priority=<?= $filter_priority ?>&search=<?= urlencode($search) ?>&page=<?= $p ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
      </ul>
    </nav>
  </div>
  <?php endif; ?>
</div>

<!-- Auto-refresh the queue every 20 seconds for officials -->
<?php if (is_official()): ?>
<script>
setInterval(() => {
  if (document.querySelector('[data-status="active"]')) location.reload();
}, 20000);
</script>
<?php endif; ?>

<?php include APP_ROOT . '/includes/footer.php'; ?>
