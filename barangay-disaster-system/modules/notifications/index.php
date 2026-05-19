<?php
/**
 * Notifications — Full inbox page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();

$page_title  = 'Notifications';
$active_page = 'notifications';

$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;

// Mark individual notification as read
if (isset($_GET['read'])) {
    $nid = (int)$_GET['read'];
    try {
        db()->prepare(
            "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?"
        )->execute([$nid, current_user_id()]);
    } catch (PDOException $e) {}

    // Get link from notification and redirect
    try {
        $n = db()->prepare("SELECT link_url FROM notifications WHERE id = ? AND user_id = ?");
        $n->execute([$nid, current_user_id()]);
        $notif = $n->fetch();
        if ($notif && $notif['link_url']) {
            header('Location: ' . $notif['link_url']);
            exit;
        }
    } catch (PDOException $e) {}
}

try {
    $total_stmt = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
    $total_stmt->execute([current_user_id()]);
    $total = (int)$total_stmt->fetchColumn();
    $pag   = paginate($total, $per_page, $page);

    $stmt = db()->prepare(
        "SELECT * FROM notifications WHERE user_id = ?
         ORDER BY created_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->execute([current_user_id(), $per_page, $pag['offset']]);
    $notifications = $stmt->fetchAll();

    $unread = (int)db()->prepare(
        "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
    )->execute([current_user_id()]) ? db()->query(
        "SELECT COUNT(*) FROM notifications WHERE user_id = " . current_user_id() . " AND is_read = 0"
    )->fetchColumn() : 0;

} catch (PDOException $e) {
    error_log($e->getMessage());
    $notifications = [];
    $total = 0;
    $pag   = paginate(0, $per_page, 1);
    $unread = 0;
}

$type_icons = [
    'incident_update' => ['bi-exclamation-triangle-fill', 'danger'],
    'rescue_status'   => ['bi-life-preserver',            'warning'],
    'announcement'    => ['bi-megaphone-fill',            'primary'],
    'relief_claim'    => ['bi-box-seam-fill',             'success'],
    'missing_update'  => ['bi-person-fill-exclamation',   'info'],
    'system_alert'    => ['bi-shield-fill',               'secondary'],
];

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
  <div>
    <h4 class="fw-bold mb-0">Notifications</h4>
    <p class="text-muted mb-0" style="font-size:.82rem">
      <?= number_format($total) ?> total ·
      <span class="<?= $unread > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>">
        <?= $unread ?> unread
      </span>
    </p>
  </div>
  <?php if ($unread > 0): ?>
  <a href="<?= APP_URL ?>/modules/notifications/mark_all_read.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-check-all me-1"></i>Mark All as Read
  </a>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-body p-0">
    <?php if (empty($notifications)): ?>
    <div class="text-center text-muted py-5">
      <i class="bi bi-bell-slash fs-3 d-block mb-2"></i>
      No notifications yet.
    </div>
    <?php else: ?>
    <?php foreach ($notifications as $n):
        [$icon, $icls] = $type_icons[$n['type']] ?? ['bi-bell', 'secondary'];
    ?>
    <a href="<?= APP_URL ?>/modules/notifications/index.php?read=<?= $n['id'] ?>"
       class="d-flex align-items-start gap-3 px-4 py-3 border-bottom text-decoration-none <?= $n['is_read'] ? '' : 'notification-item unread' ?>"
       style="color:inherit">
      <div class="flex-shrink-0 mt-1">
        <div style="width:36px;height:36px;border-radius:50%;background:var(--bdrs-<?= $icls === 'secondary' ? 'border' : $icls . '-light' ?>, #eee);display:flex;align-items:center;justify-content:center;font-size:.95rem;color:var(--bdrs-<?= $icls ?>)">
          <i class="<?= $icon ?>"></i>
        </div>
      </div>
      <div class="flex-grow-1 overflow-hidden">
        <div class="d-flex justify-content-between align-items-start gap-2">
          <div class="fw-semibold <?= $n['is_read'] ? 'text-muted' : '' ?>" style="font-size:.88rem">
            <?= htmlspecialchars($n['title']) ?>
          </div>
          <?php if (!$n['is_read']): ?>
          <span class="badge bg-danger flex-shrink-0" style="font-size:.6rem;padding:.2em .5em">NEW</span>
          <?php endif; ?>
        </div>
        <div class="text-muted text-truncate" style="font-size:.8rem"><?= htmlspecialchars($n['message']) ?></div>
        <div class="text-muted mt-1" style="font-size:.73rem">
          <i class="bi bi-clock me-1"></i><?= date('M d, Y h:i A', strtotime($n['created_at'])) ?>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Pagination -->
  <?php if ($pag['total_pages'] > 1): ?>
  <div class="card-footer d-flex justify-content-between align-items-center py-2">
    <small class="text-muted">Showing <?= ($pag['offset']+1) ?>–<?= min($pag['offset']+$per_page, $total) ?> of <?= number_format($total) ?></small>
    <nav>
      <ul class="pagination pagination-sm mb-0">
        <?php for ($p = 1; $p <= $pag['total_pages']; $p++): ?>
        <li class="page-item <?= $p === $pag['current_page'] ? 'active':'' ?>">
          <a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
      </ul>
    </nav>
  </div>
  <?php endif; ?>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
