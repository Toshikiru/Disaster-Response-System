<?php
/**
 * Announcements Module
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

$page_title  = 'Announcements';
$active_page = 'announcements';

$filter_type = $_GET['type'] ?? 'all';
$search      = trim($_GET['search'] ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 15;

$where  = ["a.is_active = 1", "(a.expires_at IS NULL OR a.expires_at > NOW())"];
$params = [];

if ($filter_type !== 'all') {
    $where[]  = 'a.type = ?';
    $params[] = $filter_type;
}
if ($search !== '') {
    $where[]  = '(a.title LIKE ? OR a.body LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like]);
}

// Scheduled announcements: only show if time reached (officials see all)
if (!is_official()) {
    $where[] = '(a.scheduled_for IS NULL OR a.scheduled_for <= NOW())';
}

$whereStr = implode(' AND ', $where);

try {
    $countStmt = db()->prepare("SELECT COUNT(*) FROM announcements a WHERE $whereStr");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pag   = paginate($total, $per_page, $page);

    $stmt = db()->prepare(
        "SELECT a.*, u.username AS posted_by_name
         FROM announcements a
         JOIN users u ON u.id = a.posted_by_id
         WHERE $whereStr
         ORDER BY a.is_pinned DESC, a.created_at DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->execute(array_merge($params, [$per_page, $pag['offset']]));
    $announcements = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log($e->getMessage());
    $announcements = [];
    $total = 0;
    $pag   = paginate(0, $per_page, 1);
}

$ann_types = [
    'general'        => ['General',          'bi-megaphone',          'secondary'],
    'evacuation'     => ['Evacuation',        'bi-house-door',         'primary'],
    'weather_alert'  => ['Weather Alert',     'bi-cloud-lightning',    'warning'],
    'road_closure'   => ['Road Closure',      'bi-sign-stop',          'danger'],
    'rescue_update'  => ['Rescue Update',     'bi-life-preserver',     'info'],
    'relief_schedule'=> ['Relief Schedule',   'bi-box-seam',           'success'],
    'health_advisory'=> ['Health Advisory',   'bi-heart-pulse',        'danger'],
];

include APP_ROOT . '/includes/header.php';
?>

<!-- Header -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
  <div>
    <h4 class="fw-bold mb-0">Announcements</h4>
    <p class="text-muted mb-0" style="font-size:.82rem"><?= number_format($total) ?> announcement(s)</p>
  </div>
  <?php if (is_official()): ?>
  <a href="<?= APP_URL ?>/modules/announcements/create.php" class="btn btn-primary">
    <i class="bi bi-plus-circle me-1"></i>Post Announcement
  </a>
  <?php endif; ?>
</div>

<!-- Filters -->
<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-12 col-md-6">
        <input type="text" name="search" class="form-control form-control-sm"
               placeholder="Search announcements…" value="<?= htmlspecialchars($search) ?>">
      </div>
      <div class="col-6 col-md-4">
        <select name="type" class="form-select form-select-sm">
          <option value="all">All Types</option>
          <?php foreach ($ann_types as $val => [$lbl,,]): ?>
            <option value="<?= $val ?>" <?= $filter_type === $val ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2 d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-search"></i></button>
        <a href="<?= APP_URL ?>/modules/announcements/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
      </div>
    </form>
  </div>
</div>

<!-- Announcements list -->
<?php if (empty($announcements)): ?>
<div class="card">
  <div class="card-body text-center text-muted py-5">
    <i class="bi bi-megaphone fs-3 d-block mb-2"></i>No announcements found.
  </div>
</div>
<?php else: ?>

<?php foreach ($announcements as $ann):
    [$type_label, $type_icon, $type_cls] = $ann_types[$ann['type']] ?? ['Announcement', 'bi-megaphone', 'secondary'];
    $sev_border = ['info' => 'primary', 'warning' => 'warning', 'critical' => 'danger'][$ann['severity']] ?? 'secondary';
?>
<div class="card mb-3 border-start border-4 border-<?= $sev_border ?>">
  <div class="card-body">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
      <div class="flex-grow-1">
        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
          <?php if ($ann['is_pinned']): ?>
            <span class="badge bg-warning text-dark"><i class="bi bi-pin-fill me-1"></i>Pinned</span>
          <?php endif; ?>
          <span class="badge bg-<?= $type_cls ?> bg-opacity-25 text-<?= $type_cls ?>">
            <i class="<?= $type_icon ?> me-1"></i><?= $type_label ?>
          </span>
          <?php if ($ann['severity'] === 'critical'): ?>
            <span class="badge bg-danger">CRITICAL ALERT</span>
          <?php elseif ($ann['severity'] === 'warning'): ?>
            <span class="badge bg-warning text-dark">Warning</span>
          <?php endif; ?>
        </div>
        <h5 class="fw-bold mb-1"><?= htmlspecialchars($ann['title']) ?></h5>
        <p class="mb-2" style="font-size:.9rem;line-height:1.7;white-space:pre-line"><?= htmlspecialchars($ann['body']) ?></p>
        <div class="text-muted" style="font-size:.75rem">
          Posted by <strong><?= htmlspecialchars($ann['posted_by_name']) ?></strong>
          · <?= date('F d, Y h:i A', strtotime($ann['created_at'])) ?>
          <?php if ($ann['expires_at']): ?>
            · Expires: <?= date('M d, Y', strtotime($ann['expires_at'])) ?>
          <?php endif; ?>
        </div>
      </div>
      <?php if (is_official()): ?>
      <div class="d-flex gap-1 flex-shrink-0">
        <a href="<?= APP_URL ?>/modules/announcements/edit.php?id=<?= $ann['id'] ?>"
           class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-pencil"></i></a>
        <a href="<?= APP_URL ?>/modules/announcements/delete.php?id=<?= $ann['id'] ?>"
           class="btn btn-sm btn-outline-danger py-0 px-2"
           data-confirm="Delete this announcement?"><?
           ?><i class="bi bi-trash"></i></a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>

<!-- Pagination -->
<?php if ($pag['total_pages'] > 1): ?>
<nav class="mt-3">
  <ul class="pagination justify-content-center">
    <?php for ($p = 1; $p <= $pag['total_pages']; $p++): ?>
    <li class="page-item <?= $p === $pag['current_page'] ? 'active' : '' ?>">
      <a class="page-link" href="?type=<?= $filter_type ?>&search=<?= urlencode($search) ?>&page=<?= $p ?>"><?= $p ?></a>
    </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif;
endif; ?>

<?php include APP_ROOT . '/includes/footer.php'; ?>
