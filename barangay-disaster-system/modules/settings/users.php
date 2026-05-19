<?php
/**
 * User Management — Admin only
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role([ROLE_ADMIN]);

$page_title  = 'User Management';
$active_page = 'settings';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'toggle_active') {
            $uid    = (int)$_POST['user_id'];
            $active = (int)$_POST['is_active'];
            // Prevent deactivating yourself
            if ($uid === current_user_id()) {
                flash('danger', 'You cannot deactivate your own account.');
            } else {
                db()->prepare("UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?")
                     ->execute([$active ? 0 : 1, $uid]);
                log_activity('toggle_user', 'user', $uid, ($active ? 'Deactivated' : 'Activated') . " user #$uid");
                flash('success', 'User status updated.');
            }

        } elseif ($action === 'reset_password') {
            $uid      = (int)$_POST['user_id'];
            $new_pass = trim($_POST['new_password'] ?? '');
            if (strlen($new_pass) < 8) {
                flash('danger', 'Password must be at least 8 characters.');
            } else {
                $hash = password_hash($new_pass, PASSWORD_BCRYPT, ['cost' => 12]);
                db()->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?")
                     ->execute([$hash, $uid]);
                log_activity('reset_password', 'user', $uid, "Password reset for user #$uid");
                flash('success', 'Password reset successfully.');
            }

        } elseif ($action === 'create_official') {
            $username = trim($_POST['username']   ?? '');
            $email    = trim($_POST['email']      ?? '');
            $password = trim($_POST['password']   ?? '');
            $first    = trim($_POST['first_name'] ?? '');
            $last     = trim($_POST['last_name']  ?? '');
            $position = trim($_POST['position']   ?? '');
            $contact  = trim($_POST['contact_number'] ?? '');

            if (strlen($password) < 8 || empty($username) || empty($email) || empty($first) || empty($last) || empty($position)) {
                flash('danger', 'All fields required. Password min 8 characters.');
            } else {
                $chk = db()->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
                $chk->execute([$username, $email]);
                if ((int)$chk->fetchColumn() > 0) {
                    flash('danger', 'Username or email already taken.');
                } else {
                    db()->beginTransaction();
                    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    db()->prepare("INSERT INTO users (role_id, username, email, password_hash) VALUES (2,?,?,?)")
                        ->execute([$username, $email, $hash]);
                    $uid = (int)db()->lastInsertId();
                    db()->prepare(
                        "INSERT INTO barangay_officials (user_id, first_name, last_name, position, contact_number)
                         VALUES (?,?,?,?,?)"
                    )->execute([$uid, $first, $last, $position, $contact ?: null]);
                    db()->commit();
                    log_activity('create_official', 'user', $uid, "Created official: $first $last");
                    flash('success', "Official account for $first $last created.");
                }
            }
        }
    } catch (PDOException $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log($e->getMessage());
        flash('danger', 'Error processing request.');
    }

    header('Location: ' . APP_URL . '/modules/settings/users.php');
    exit;
}

// Fetch all users with profile info
try {
    $users = db()->query(
        "SELECT u.id, u.username, u.email, u.is_active, u.last_login_at, u.created_at,
                r.role_name,
                COALESCE(
                    CONCAT(res.first_name,' ',res.last_name),
                    CONCAT(off.first_name,' ',off.last_name),
                    CONCAT(rsp.first_name,' ',rsp.last_name),
                    ''
                ) AS full_name
         FROM users u
         JOIN roles r ON r.id = u.role_id
         LEFT JOIN residents res ON res.user_id = u.id
         LEFT JOIN barangay_officials off ON off.user_id = u.id
         LEFT JOIN responders rsp ON rsp.user_id = u.id
         ORDER BY u.role_id ASC, u.created_at DESC"
    )->fetchAll();

    $role_counts = db()->query(
        "SELECT r.role_name, COUNT(u.id) AS cnt
         FROM roles r LEFT JOIN users u ON u.role_id = r.id
         GROUP BY r.id"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

} catch (PDOException $e) {
    error_log($e->getMessage());
    $users = [];
    $role_counts = [];
}

$role_badges = [
    'admin'            => 'danger',
    'barangay_official'=> 'primary',
    'responder'        => 'warning',
    'resident'         => 'success',
];

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
  <div>
    <h4 class="fw-bold mb-0">User Management</h4>
    <p class="text-muted mb-0" style="font-size:.82rem"><?= count($users) ?> total accounts</p>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addOfficialModal">
    <i class="bi bi-person-plus me-1"></i>Add Official Account
  </button>
</div>

<!-- Role summary -->
<div class="row g-3 mb-4">
  <?php foreach ($role_badges as $role => $cls): ?>
  <div class="col-6 col-md-3">
    <div class="card p-3 text-center">
      <div class="fw-bold fs-4"><?= $role_counts[$role] ?? 0 ?></div>
      <div class="text-muted" style="font-size:.78rem">
        <span class="badge bg-<?= $cls ?>"><?= ucwords(str_replace('_',' ',$role)) ?></span>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Search bar -->
<div class="card mb-3">
  <div class="card-body py-2">
    <input type="text" id="userSearch" class="form-control form-control-sm"
           placeholder="Search by username, name, or email…"
           style="max-width:350px">
  </div>
</div>

<!-- Users table -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="usersTable">
        <thead>
          <tr>
            <th class="ps-3">#</th>
            <th>Username</th>
            <th>Full Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Status</th>
            <th>Last Login</th>
            <th>Joined</th>
            <th class="text-end pe-3">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u):
            $role_cls = $role_badges[$u['role_name']] ?? 'secondary';
          ?>
          <tr>
            <td class="ps-3 text-muted" style="font-size:.75rem"><?= $u['id'] ?></td>
            <td>
              <span class="fw-semibold" style="font-size:.88rem"><?= htmlspecialchars($u['username']) ?></span>
              <?php if ($u['id'] === current_user_id()): ?>
                <span class="badge bg-light text-dark border ms-1" style="font-size:.65rem">You</span>
              <?php endif; ?>
            </td>
            <td style="font-size:.83rem"><?= htmlspecialchars(trim($u['full_name']) ?: '—') ?></td>
            <td style="font-size:.8rem" class="text-muted"><?= htmlspecialchars($u['email']) ?></td>
            <td>
              <span class="badge bg-<?= $role_cls ?>" style="font-size:.7rem">
                <?= ucwords(str_replace('_',' ',$u['role_name'])) ?>
              </span>
            </td>
            <td>
              <span class="badge <?= $u['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
              </span>
            </td>
            <td style="font-size:.75rem" class="text-muted">
              <?= $u['last_login_at'] ? date('M d, Y', strtotime($u['last_login_at'])) : 'Never' ?>
            </td>
            <td style="font-size:.75rem" class="text-muted">
              <?= date('M d, Y', strtotime($u['created_at'])) ?>
            </td>
            <td class="text-end pe-3">
              <div class="d-flex gap-1 justify-content-end">
                <!-- Toggle active -->
                <?php if ($u['id'] !== current_user_id()): ?>
                <form method="POST" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action"    value="toggle_active">
                  <input type="hidden" name="user_id"   value="<?= $u['id'] ?>">
                  <input type="hidden" name="is_active" value="<?= $u['is_active'] ?>">
                  <button type="submit"
                          class="btn btn-sm <?= $u['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?> py-0 px-2"
                          title="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>"
                          data-confirm="<?= $u['is_active'] ? 'Deactivate this user?' : 'Activate this user?' ?>">
                    <i class="bi bi-<?= $u['is_active'] ? 'person-x' : 'person-check' ?>"></i>
                  </button>
                </form>
                <?php endif; ?>

                <!-- Reset password -->
                <button class="btn btn-sm btn-outline-secondary py-0 px-2"
                        data-bs-toggle="modal"
                        data-bs-target="#resetModal<?= $u['id'] ?>"
                        title="Reset Password">
                  <i class="bi bi-key"></i>
                </button>
              </div>
            </td>
          </tr>

          <!-- Reset password modal per user -->
          <div class="modal fade" id="resetModal<?= $u['id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-sm">
              <div class="modal-content">
                <form method="POST">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action"  value="reset_password">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <div class="modal-header">
                    <h6 class="modal-title fw-bold">Reset: <?= htmlspecialchars($u['username']) ?></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <label class="form-label fw-semibold">New Password</label>
                    <input type="password" name="new_password" class="form-control"
                           placeholder="Min 8 characters" required minlength="8">
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-sm">Reset Password</button>
                  </div>
                </form>
              </div>
            </div>
          </div>

          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add Official Modal -->
<div class="modal fade" id="addOfficialModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_official">
        <div class="modal-header">
          <h5 class="modal-title fw-bold">Create Barangay Official Account</h5>
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
              <label class="form-label">Position <span class="text-danger">*</span></label>
              <input type="text" name="position" class="form-control"
                     placeholder="Barangay Captain, Councilor, BDRRMC Chair…" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Contact Number</label>
              <input type="text" name="contact_number" class="form-control" placeholder="09XXXXXXXXX">
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
              <input type="password" name="password" class="form-control"
                     required minlength="8" placeholder="Min 8 characters">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-person-plus me-1"></i>Create Account
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    initTableSearch('userSearch', 'usersTable');
});
</script>
