<?php
/**
 * Missing Persons Registry
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

$page_title  = 'Missing Persons';
$active_page = 'missing';

$filter_status = $_GET['status'] ?? 'missing';
$search        = trim($_GET['search'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 20;

$where  = ['1=1'];
$params = [];

if ($filter_status !== 'all') {
    $where[]  = 'mp.status = ?';
    $params[] = $filter_status;
}
if ($search !== '') {
    $where[]  = '(mp.full_name LIKE ? OR mp.last_seen_location LIKE ? OR mp.reference_number LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like]);
}

$whereStr = implode(' AND ', $where);

try {
    $countStmt = db()->prepare("SELECT COUNT(*) FROM missing_persons mp WHERE $whereStr");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pag   = paginate($total, $per_page, $page);

    $stmt = db()->prepare(
        "SELECT mp.*, u.username AS reporter_username
         FROM missing_persons mp
         JOIN users u ON u.id = mp.reporter_user_id
         WHERE $whereStr
         ORDER BY mp.created_at DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->execute(array_merge($params, [$per_page, $pag['offset']]));
    $persons = $stmt->fetchAll();

    $status_counts = db()->query(
        "SELECT status, COUNT(*) AS cnt FROM missing_persons GROUP BY status"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

} catch (PDOException $e) {
    error_log($e->getMessage());
    $persons = [];
    $total = 0;
    $pag   = paginate(0, $per_page, 1);
    $status_counts = [];
}

$status_config = [
    'missing'      => ['Missing',       'danger'],
    'found_safe'   => ['Found Safe',    'success'],
    'found_injured'=> ['Found Injured', 'warning'],
    'deceased'     => ['Deceased',      'dark'],
    'unknown'      => ['Unknown',       'secondary'],
];

include APP_ROOT . '/includes/header.php';
?>

<!-- Header -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
  <div>
    <h4 class="fw-bold mb-0">Missing Persons Registry</h4>
    <p class="text-muted mb-0" style="font-size:.82rem">
      <span class="text-danger fw-bold"><?= $status_counts['missing'] ?? 0 ?> currently missing</span>
    </p>
  </div>
  <a href="<?= APP_URL ?>/modules/missing/create.php" class="btn btn-danger">
    <i class="bi bi-person-fill-exclamation me-1"></i>Report Missing Person
  </a>
</div>

<!-- Status filter tabs -->
<ul class="nav nav-tabs mb-4">
  <?php foreach (array_merge(['all' => ['All Records', 'secondary']], array_map(fn($v) => [$v[0], $v[1]], $status_config)) as $val => [$lbl, $cls]):
      $cnt = $val === 'all' ? $total : ($status_counts[$val] ?? 0); ?>
  <li class="nav-item">
    <a class="nav-link <?= $filter_status === $val ? 'active' : '' ?>" href="?status=<?= $val ?>">
      <?= $lbl ?> <span class="badge bg-<?= $cls ?> ms-1" style="font-size:.65rem"><?= $cnt ?></span>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<!-- Search -->
<div class="card mb-4">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
      <div class="col-12 col-md-8">
        <input type="text" name="search" class="form-control form-control-sm"
               placeholder="Search by name, location, or reference number…"
               value="<?= htmlspecialchars($search) ?>">
      </div>
      <div class="col-12 col-md-4 d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-search me-1"></i>Search</button>
        <a href="?status=<?= $filter_status ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
      </div>
    </form>
  </div>
</div>

<!-- Cards grid -->
<?php if (empty($persons)): ?>
<div class="card">
  <div class="card-body text-center text-muted py-5">
    <i class="bi bi-person-fill-exclamation fs-3 d-block mb-2"></i>
    No missing person records found.
  </div>
</div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($persons as $p):
      [$st_label, $st_cls] = $status_config[$p['status']] ?? ['Unknown', 'secondary'];
  ?>
  <div class="col-md-6 col-lg-4">
    <div class="card h-100 <?= $p['status'] === 'missing' ? 'border-danger' : '' ?>">
      <div class="card-body">
        <div class="d-flex gap-3">
          <!-- Photo or placeholder -->
          <div class="flex-shrink-0">
            <?php if ($p['photo_path']): ?>
              <img src="<?= APP_URL . '/' . htmlspecialchars($p['photo_path']) ?>"
                   alt="<?= htmlspecialchars($p['full_name']) ?>"
                   style="width:70px;height:80px;object-fit:cover;border-radius:.5rem;border:2px solid var(--bdrs-border)">
            <?php else: ?>
              <div style="width:70px;height:80px;background:var(--bdrs-blue-light);border-radius:.5rem;display:flex;align-items:center;justify-content:center;font-size:2rem;color:var(--bdrs-blue)">
                <i class="bi bi-person-fill"></i>
              </div>
            <?php endif; ?>
          </div>

          <div class="flex-grow-1 overflow-hidden">
            <div class="d-flex align-items-start justify-content-between gap-1 mb-1">
              <h6 class="fw-bold mb-0 text-truncate"><?= htmlspecialchars($p['full_name']) ?></h6>
              <span class="badge bg-<?= $st_cls ?> flex-shrink-0" style="font-size:.65rem"><?= $st_label ?></span>
            </div>
            <div class="text-muted mb-1" style="font-size:.75rem">
              <?= $p['age'] ? $p['age'] . ' yrs · ' : '' ?><?= ucfirst($p['gender']) ?>
            </div>
            <?php if ($p['last_seen_location']): ?>
            <div style="font-size:.78rem"><i class="bi bi-geo-alt me-1 text-danger"></i><?= htmlspecialchars($p['last_seen_location']) ?></div>
            <?php endif; ?>
            <?php if ($p['last_seen_at']): ?>
            <div class="text-muted" style="font-size:.73rem">
              Last seen: <?= date('M d, Y h:i A', strtotime($p['last_seen_at'])) ?>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($p['description']): ?>
        <div class="mt-2 text-truncate-2 text-muted" style="font-size:.78rem">
          <?= htmlspecialchars($p['description']) ?>
        </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mt-2">
          <small class="text-muted" style="font-size:.7rem"><?= htmlspecialchars($p['reference_number']) ?></small>
          <div class="d-flex gap-1">
            <a href="<?= APP_URL ?>/modules/missing/view.php?id=<?= $p['id'] ?>"
               class="btn btn-sm btn-outline-primary py-0 px-2"><i class="bi bi-eye"></i></a>
            <?php if (is_official()): ?>
            <a href="<?= APP_URL ?>/modules/missing/edit.php?id=<?= $p['id'] ?>"
               class="btn btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-pencil"></i></a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($pag['total_pages'] > 1): ?>
<nav class="mt-4">
  <ul class="pagination justify-content-center">
    <?php for ($p = 1; $p <= $pag['total_pages']; $p++): ?>
    <li class="page-item <?= $p === $pag['current_page'] ? 'active' : '' ?>">
      <a class="page-link" href="?status=<?= $filter_status ?>&search=<?= urlencode($search) ?>&page=<?= $p ?>"><?= $p ?></a>
    </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif;
endif; ?>

<?php include APP_ROOT . '/includes/footer.php'; ?>
