<?php
/**
 * Responders Management — Officials & Admin only
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role([ROLE_OFFICIAL, ROLE_ADMIN]);

$page_title  = 'Responders';
$active_page = 'responders';

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_status') {
            $rid    = (int)$_POST['responder_id'];
            $status = $_POST['status'] ?? '';
            $allowed = ['available','on_duty','off_duty','on_leave'];
            if (in_array($status, $allowed)) {
                db()->prepare("UPDATE responders SET status = ? WHERE id = ?")->execute([$status, $rid]);
                log_activity('update_responder_status', 'responders', $rid, "Status → $status");
                flash('success', 'Responder status updated.');
            }
        } elseif ($action === 'add_responder') {
            // Create user account + responder profile
            $username = trim($_POST['username'] ?? '');
            $email    = trim($_POST['email']    ?? '');
            $password = $_POST['password']      ?? '';
            $first    = trim($_POST['first_name'] ?? '');
            $last     = trim($_POST['last_name']  ?? '');
            $type     = trim($_POST['responder_type'] ?? 'mdrrmo');
            $unit     = trim($_POST['unit_name'] ?? '') ?: null;
            $badge    = trim($_POST['badge_number'] ?? '') ?: null;
            $contact  = trim($_POST['contact_number'] ?? '') ?: null;

            if (empty($username) || empty($email) || strlen($password) < 8 || empty($first) || empty($last)) {
                flash('danger', 'All required fields must be filled (password min 8 chars).');
            } else {
                $check = db()->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
                $check->execute([$username, $email]);
                if ((int)$check->fetchColumn() > 0) {
                    flash('danger', 'Username or email is already taken.');
                } else {
                    db()->beginTransaction();

                    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    db()->prepare("INSERT INTO users (role_id, username, email, password_hash) VALUES (3,?,?,?)")
                        ->execute([$username, $email, $hash]);
                    $uid = (int)db()->lastInsertId();

                    db()->prepare(
                        "INSERT INTO responders (user_id, first_name, last_name, responder_type, unit_name, badge_number, contact_number)
                         VALUES (?,?,?,?,?,?,?)"
                    )->execute([$uid, $first, $last, $type, $unit, $badge, $contact]);

                    db()->commit();
                    log_activity('add_responder', 'responders', $uid, "Added responder: $first $last");
                    flash('success', "Responder $first $last added successfully.");
                }
            }
        }
    } catch (PDOException $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log($e->getMessage());
        flash('danger', 'Error processing request.');
    }

    header('Location: ' . APP_URL . '/modules/responders/index.php');
    exit;
}

// Fetch responders with assignment counts
try {
    $responders = db()->query(
        "SELECT r.*,
                CONCAT(r.first_name,' ',r.last_name) AS full_name,
                u.username, u.email, u.last_login_at,
                (SELECT COUNT(*) FROM responder_incident_assignments ria
                 WHERE ria.responder_id = r.id AND ria.released_at IS NULL) AS active_assignments
         FROM responders r
         JOIN users u ON u.id = r.user_id
         ORDER BY r.status ASC, r.first_name ASC"
    )->fetchAll();
} catch (PDOException $e) {
    error_log($e->getMessage());
    $responders = [];
}

$type_labels = [
    'bfp'         => ['BFP',          'bi-fire',             'danger'],
    'mdrrmo'      => ['MDRRMO',        'bi-shield-fill',      'primary'],
    'police'      => ['PNP',           'bi-shield-check',     'dark'],
    'medical'     => ['Medical',       'bi-hospital',         'info'],
    'coast_guard' => ['Coast Guard',   'bi-anchor',           'secondary'],
    'other'       => ['Other',         'bi-person-badge',     'secondary'],
];

$status_labels = [
    'available' => ['Available', 'success'],
    'on_duty'   => ['On Duty',   'warning'],
    'off_duty'  => ['Off Duty',  'secondary'],
    'on_leave'  => ['On Leave',  'info'],
];

// Group by status for summary
$by_status = array_count_values(array_column($responders, 'status'));

include APP_ROOT . '/includes/header.php';
?>

<!-- Header -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
  <div>
    <h4 class="fw-bold mb-0">Emergency Responders</h4>
    <p class="text-muted mb-0" style="font-size:.82rem">
      <?= count($responders) ?> total ·
      <span class="text-success fw-semibold"><?= $by_status['available'] ?? 0 ?> available</span> ·
      <span class="text-warning fw-semibold"><?= $by_status['on_duty'] ?? 0 ?> on duty</span>
    </p>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addResponderModal">
    <i class="bi bi-person-plus me-1"></i>Add Responder
  </button>
</div>

<!-- Status summary cards -->
<div class="row g-3 mb-4">
  <?php foreach ($status_labels as $skey => [$slabel, $scls]): ?>
  <div class="col-6 col-md-3">
    <div class="card stat-card <?= $scls === 'success' ? 'success' : ($scls === 'warning' ? 'warning' : 'primary') ?>">
      <div class="d-flex align-items-center gap-3 p-3">
        <div class="stat-icon">
          <i class="bi bi-person-badge-fill"></i>
        </div>
        <div>
          <div class="stat-value"><?= $by_status[$skey] ?? 0 ?></div>
          <div class="stat-label"><?= $slabel ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Responders table -->
<div class="card">
  <div class="card-header">
    <input type="text" id="responderSearch" class="form-control form-control-sm" style="max-width:280px"
           placeholder="Search responders…" oninput="initTableSearch('responderSearch','responderTable')">
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="responderTable">
        <thead>
          <tr>
            <th class="ps-3">Name</th>
            <th>Type</th>
            <th>Unit</th>
            <th>Badge #</th>
            <th>Contact</th>
            <th>Status</th>
            <th>Active Assignments</th>
            <th>Last Login</th>
            <th class="text-end pe-3">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($responders)): ?>
          <tr>
            <td colspan="9" class="text-center text-muted py-5">
              <i class="bi bi-person-badge fs-3 d-block mb-2"></i>No responders added yet.
            </td>
          </tr>
          <?php else: foreach ($responders as $r):
            [$type_lbl, $type_icon, $type_cls] = $type_labels[$r['responder_type']] ?? ['Other','bi-person-badge','secondary'];
            [$st_lbl, $st_cls] = $status_labels[$r['status']] ?? ['Unknown','secondary'];
          ?>
          <tr>
            <td class="ps-3">
              <div class="fw-semibold" style="font-size:.88rem"><?= htmlspecialchars($r['full_name']) ?></div>
              <div class="text-muted" style="font-size:.73rem">@<?= htmlspecialchars($r['username']) ?></div>
            </td>
            <td>
              <span class="badge bg-<?= $type_cls ?> bg-opacity-20 text-<?= $type_cls ?>">
                <i class="<?= $type_icon ?> me-1"></i><?= $type_lbl ?>
              </span>
            </td>
            <td style="font-size:.82rem"><?= htmlspecialchars($r['unit_name'] ?? '—') ?></td>
            <td style="font-size:.82rem"><?= htmlspecialchars($r['badge_number'] ?? '—') ?></td>
            <td style="font-size:.82rem"><?= htmlspecialchars($r['contact_number'] ?? '—') ?></td>
            <td>
              <span class="badge bg-<?= $st_cls ?>"><?= $st_lbl ?></span>
            </td>
            <td class="text-center">
              <?php if ($r['active_assignments'] > 0): ?>
                <span class="badge bg-warning text-dark"><?= $r['active_assignments'] ?> active</span>
              <?php else: ?>
                <span class="text-muted" style="font-size:.78rem">—</span>
              <?php endif; ?>
            </td>
            <td style="font-size:.75rem" class="text-muted">
              <?= $r['last_login_at'] ? date('M d, Y', strtotime($r['last_login_at'])) : 'Never' ?>
            </td>
            <td class="text-end pe-3">
              <button class="btn btn-sm btn-outline-secondary py-0 px-2"
                      data-bs-toggle="modal"
                      data-bs-target="#statusModal<?= $r['id'] ?>"
                      title="Update status">
                <i class="bi bi-arrow-repeat"></i>
              </button>
            </td>
          </tr>

          <!-- Per-responder status modal -->
          <div class="modal fade" id="statusModal<?= $r['id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-sm">
              <div class="modal-content">
                <form method="POST">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update_status">
                  <input type="hidden" name="responder_id" value="<?= $r['id'] ?>">
                  <div class="modal-header">
                    <h6 class="modal-title fw-bold"><?= htmlspecialchars($r['full_name']) ?></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <label class="form-label fw-semibold">Update Status</label>
                    <select name="status" class="form-select" required>
                      <?php foreach ($status_labels as $sv => [$sl,]): ?>
                        <option value="<?= $sv ?>" <?= $r['status'] === $sv ? 'selected' : '' ?>><?= $sl ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Update</button>
                  </div>
                </form>
              </div>
            </div>
          </div>

          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add Responder Modal -->
<div class="modal fade" id="addResponderModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_responder">
        <div class="modal-header">
          <h5 class="modal-title fw-bold">Add Responder Account</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">First Name <span class="text-danger">*</span></label>
              <input type="text" name="first_name" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Last Name <span class="text-danger">*</span></label>
              <input type="text" name="last_name" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Username <span class="text-danger">*</span></label>
              <input type="text" name="username" class="form-control" required minlength="4">
            </div>
            <div class="col-md-6">
              <label class="form-label">Email <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Password <span class="text-danger">*</span></label>
              <input type="password" name="password" class="form-control" required minlength="8" placeholder="Min 8 characters">
            </div>
            <div class="col-md-6">
              <label class="form-label">Responder Type <span class="text-danger">*</span></label>
              <select name="responder_type" class="form-select" required>
                <?php foreach ($type_labels as $tval => [$tlbl,,]): ?>
                  <option value="<?= $tval ?>"><?= $tlbl ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Unit / Station</label>
              <input type="text" name="unit_name" class="form-control" placeholder="e.g., BFP Station 1">
            </div>
            <div class="col-md-3">
              <label class="form-label">Badge Number</label>
              <input type="text" name="badge_number" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label">Contact Number</label>
              <input type="text" name="contact_number" class="form-control" placeholder="09XXXXXXXXX">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i>Add Responder</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>

<script>
// Wire up search after DOM ready
document.addEventListener('DOMContentLoaded', () => {
    initTableSearch('responderSearch', 'responderTable');
});
</script>
