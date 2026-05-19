<?php
/**
 * Activity Logs — Admin only
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role([ROLE_ADMIN]);

$page_title  = 'Activity Logs';
$active_page = 'logs';

$filter_module = $_GET['module'] ?? 'all';
$filter_user   = trim($_GET['user'] ?? '');
$filter_date   = $_GET['date'] ?? '';
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 50;

$where  = ['1=1'];
$params = [];

if ($filter_module !== 'all') {
    $where[]  = 'al.module = ?';
    $params[] = $filter_module;
}
if ($filter_user !== '') {
    $where[]  = 'u.username LIKE ?';
    $params[] = '%' . $filter_user . '%';
}
if ($filter_date !== '') {
    $where[]  = 'DATE(al.created_at) = ?';
    $params[] = $filter_date;
}

$whereStr = implode(' AND ', $where);

try {
    $countStmt = db()->prepare(
        "SELECT COUNT(*) FROM activity_logs al LEFT JOIN users u ON u.id = al.user_id WHERE $whereStr"
    );
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pag   = paginate($total, $per_page, $page);

    $stmt = db()->prepare(
        "SELECT al.*, u.username
         FROM activity_logs al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE $whereStr
         ORDER BY al.created_at DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->execute(array_merge($params, [$per_page, $pag['offset']]));
    $logs = $stmt->fetchAll();

    $modules = db()->query("SELECT DISTINCT module FROM activity_logs ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {
    error_log($e->getMessage());
    $logs = [];
    $total = 0;
    $pag   = paginate(0, $per_page, 1);
    $modules = [];
}

$action_icons = [
    'login'           => ['bi-box-arrow-in-right', 'success'],
    'logout'          => ['bi-box-arrow-right',     'secondary'],
    'login_failed'    => ['bi-x-circle',            'danger'],
    'register'        => ['bi-person-plus',         'primary'],
    'create_incident' => ['bi-exclamation-triangle','danger'],
    'update_status'   => ['bi-arrow-repeat',        'warning'],
    'assign_responder'=> ['bi-person-check',        'info'],
    'create_rescue'   => ['bi-life-preserver',      'danger'],
    'new_distribution'=> ['bi-box-seam',            'success'],
    'update_settings' => ['bi-gear',                'secondary'],
];

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
  <div>
    <h4 class="fw-bold mb-0">Activity Logs</h4>
    <p class="text-muted mb-0" style="font-size:.82rem"><?= number_format($total) ?> log entries</p>
  </div>
  <button class="btn btn-outline-secondary btn-sm" onclick="printPage()">
    <i class="bi bi-printer me-1"></i>Print
  </button>
</div>

<!-- Filters -->
<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-12 col-md-3">
        <input type="text" name="user" class="form-control form-control-sm"
               placeholder="Filter by username…" value="<?= htmlspecialchars($filter_user) ?>">
      </div>
      <div class="col-6 col-md-2">
        <select name="module" class="form-select form-select-sm">
          <option value="all">All Modules</option>
          <?php foreach ($modules as $mod): ?>
            <option value="<?= $mod ?>" <?= $filter_module === $mod ? 'selected' : '' ?>><?= ucfirst($mod) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <input type="date" name="date" class="form-control form-control-sm"
               value="<?= htmlspecialchars($filter_date) ?>">
      </div>
      <div class="col-12 col-md-2 d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-search"></i></button>
        <a href="<?= APP_URL ?>/modules/settings/logs.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
      </div>
    </form>
  </div>
</div>

<!-- Logs table -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0">
        <thead>
          <tr>
            <th class="ps-3">#</th>
            <th>Date/Time</th>
            <th>User</th>
            <th>Action</th>
            <th>Module</th>
            <th>Description</th>
            <th>IP Address</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($logs)): ?>
          <tr>
            <td colspan="7" class="text-center text-muted py-4">No log entries found.</td>
          </tr>
          <?php else: foreach ($logs as $log):
            [$icon, $icls] = $action_icons[$log['action']] ?? ['bi-circle', 'secondary'];
          ?>
          <tr>
            <td class="ps-3 text-muted" style="font-size:.75rem"><?= number_format($log['id']) ?></td>
            <td style="font-size:.78rem;white-space:nowrap"><?= date('M d, Y h:i:s A', strtotime($log['created_at'])) ?></td>
            <td>
              <span class="badge bg-light text-dark border" style="font-size:.72rem">
                <?= htmlspecialchars($log['username'] ?? 'System') ?>
              </span>
            </td>
            <td>
              <span class="text-<?= $icls ?>" style="font-size:.82rem">
                <i class="<?= $icon ?> me-1"></i><?= htmlspecialchars($log['action']) ?>
              </span>
            </td>
            <td>
              <span class="badge bg-light text-dark border" style="font-size:.7rem">
                <?= htmlspecialchars($log['module']) ?>
              </span>
            </td>
            <td style="font-size:.78rem" class="text-muted">
              <?= htmlspecialchars($log['description'] ?? '—') ?>
            </td>
            <td style="font-size:.75rem" class="text-muted font-monospace">
              <?= htmlspecialchars($log['ip_address'] ?? '—') ?>
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
    <small class="text-muted">Showing <?= ($pag['offset']+1) ?>–<?= min($pag['offset']+$per_page, $total) ?> of <?= number_format($total) ?></small>
    <nav>
      <ul class="pagination pagination-sm mb-0">
        <?php for ($p = 1; $p <= min($pag['total_pages'], 20); $p++): ?>
        <li class="page-item <?= $p === $pag['current_page'] ? 'active' : '' ?>">
          <a class="page-link"
             href="?module=<?= urlencode($filter_module) ?>&user=<?= urlencode($filter_user) ?>&date=<?= urlencode($filter_date) ?>&page=<?= $p ?>">
            <?= $p ?>
          </a>
        </li>
        <?php endfor; ?>
      </ul>
    </nav>
  </div>
  <?php endif; ?>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
