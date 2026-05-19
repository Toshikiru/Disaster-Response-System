<?php
/**
 * My Profile — All user roles
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();

$page_title  = 'My Profile';
$active_page = 'profile';

$errors   = [];
$success  = '';
$tab      = $_GET['tab'] ?? 'profile';
$user_id  = current_user_id();
$role_id  = current_role();

// ── Fetch current user ────────────────────────────────────────────
try {
    $user = db()->prepare(
        "SELECT u.*, r.role_name FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.id = ?"
    );
    $user->execute([$user_id]);
    $user = $user->fetch();

    if (!$user) {
        flash('danger', 'User account not found.');
        header('Location: ' . APP_URL . '/index.php');
        exit;
    }

    // Fetch resident profile if applicable
    $resident = null;
    if ($role_id === ROLE_RESIDENT) {
        $res = db()->prepare("SELECT * FROM residents WHERE user_id = ?");
        $res->execute([$user_id]);
        $resident = $res->fetch();
    }

    // Fetch official profile
    $official = null;
    if ($role_id === ROLE_OFFICIAL) {
        $off = db()->prepare("SELECT * FROM barangay_officials WHERE user_id = ?");
        $off->execute([$user_id]);
        $official = $off->fetch();
    }

    // Fetch responder profile
    $responder = null;
    if ($role_id === ROLE_RESPONDER) {
        $rsp = db()->prepare("SELECT * FROM responders WHERE user_id = ?");
        $rsp->execute([$user_id]);
        $responder = $rsp->fetch();
    }

} catch (PDOException $e) {
    error_log($e->getMessage());
    flash('danger', 'Error loading profile.');
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
    exit;
}

// ── Handle POST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // ── Change Password ──────────────────────────────────────────
    if ($action === 'change_password') {
        $current  = $_POST['current_password']      ?? '';
        $new_pass = $_POST['new_password']           ?? '';
        $confirm  = $_POST['confirm_password']       ?? '';

        if (empty($current))                          $errors[] = 'Current password is required.';
        elseif (!password_verify($current, $user['password_hash'])) $errors[] = 'Current password is incorrect.';
        if (strlen($new_pass) < 8)                    $errors[] = 'New password must be at least 8 characters.';
        if ($new_pass !== $confirm)                   $errors[] = 'New passwords do not match.';

        if (empty($errors)) {
            try {
                $hash = password_hash($new_pass, PASSWORD_BCRYPT, ['cost' => 12]);
                db()->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?")
                     ->execute([$hash, $user_id]);
                log_activity('change_password', 'auth', $user_id, 'Password changed successfully');
                flash('success', 'Password changed successfully.');
                header('Location: ' . APP_URL . '/modules/auth/profile.php?tab=security');
                exit;
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $errors[] = 'Error updating password. Please try again.';
            }
        }
        $tab = 'security';
    }

    // ── Update Profile Info ──────────────────────────────────────
    elseif ($action === 'update_profile') {
        $email = trim($_POST['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        } else {
            // Check email not taken by another user
            $chk = db()->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
            $chk->execute([$email, $user_id]);
            if ((int)$chk->fetchColumn() > 0) $errors[] = 'That email is already in use by another account.';
        }

        if (empty($errors)) {
            try {
                db()->prepare("UPDATE users SET email = ?, updated_at = NOW() WHERE id = ?")
                     ->execute([$email, $user_id]);

                // Update role-specific profile fields
                if ($role_id === ROLE_RESIDENT && $resident) {
                    db()->prepare(
                        "UPDATE residents SET
                            first_name = ?, middle_name = ?, last_name = ?,
                            contact_number = ?, purok_sitio = ?, street_address = ?,
                            emergency_contact_name = ?, emergency_contact_number = ?,
                            emergency_contact_relation = ?, household_size = ?,
                            has_pwd = ?, has_senior = ?, has_infant = ?, has_pregnant = ?,
                            updated_at = NOW()
                         WHERE user_id = ?"
                    )->execute([
                        trim($_POST['first_name']   ?? $resident['first_name']),
                        trim($_POST['middle_name']  ?? '') ?: null,
                        trim($_POST['last_name']    ?? $resident['last_name']),
                        trim($_POST['contact_number'] ?? '') ?: null,
                        trim($_POST['purok_sitio']  ?? '') ?: null,
                        trim($_POST['street_address'] ?? $resident['street_address']),
                        trim($_POST['emergency_contact_name']     ?? '') ?: null,
                        trim($_POST['emergency_contact_number']   ?? '') ?: null,
                        trim($_POST['emergency_contact_relation'] ?? '') ?: null,
                        max(1, (int)($_POST['household_size'] ?? 1)),
                        isset($_POST['has_pwd'])      ? 1 : 0,
                        isset($_POST['has_senior'])   ? 1 : 0,
                        isset($_POST['has_infant'])   ? 1 : 0,
                        isset($_POST['has_pregnant']) ? 1 : 0,
                        $user_id,
                    ]);
                }

                log_activity('update_profile', 'auth', $user_id, 'Profile updated');
                flash('success', 'Profile updated successfully.');
                header('Location: ' . APP_URL . '/modules/auth/profile.php?tab=profile');
                exit;

            } catch (PDOException $e) {
                error_log($e->getMessage());
                $errors[] = 'Error saving profile. Please try again.';
            }
        }
        $tab = 'profile';
    }
}

// ── Fetch recent activity for this user ───────────────────────────
try {
    $recent_activity = db()->prepare(
        "SELECT action, module, description, ip_address, created_at
         FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 10"
    );
    $recent_activity->execute([$user_id]);
    $recent_activity = $recent_activity->fetchAll();
} catch (PDOException $e) {
    $recent_activity = [];
}

// ── Fetch QR code token for resident ─────────────────────────────
$qr_token = $resident['qr_code_token'] ?? null;

include APP_ROOT . '/includes/header.php';
?>

<!-- Page header -->
<div class="d-flex align-items-center gap-2 mb-4">
  <div class="flex-shrink-0">
    <div style="width:56px;height:56px;background:var(--bdrs-blue);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:#fff;font-weight:700">
      <?= strtoupper(substr($user['username'], 0, 1)) ?>
    </div>
  </div>
  <div>
    <h4 class="fw-bold mb-0"><?= htmlspecialchars($user['username']) ?></h4>
    <span class="badge bg-primary"><?= ucwords(str_replace('_', ' ', $user['role_name'])) ?></span>
    <span class="text-muted ms-2" style="font-size:.78rem">
      Member since <?= date('F Y', strtotime($user['created_at'])) ?>
    </span>
  </div>
</div>

<!-- Error alerts -->
<?php if (!empty($errors)): ?>
<div class="alert alert-danger mb-4">
  <strong><i class="bi bi-exclamation-triangle-fill me-1"></i>Please fix the following:</strong>
  <ul class="mb-0 mt-1">
    <?php foreach ($errors as $e): ?>
      <li><?= htmlspecialchars($e) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'profile'  ? 'active' : '' ?>"
       href="?tab=profile"><i class="bi bi-person me-1"></i>My Profile</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'security' ? 'active' : '' ?>"
       href="?tab=security"><i class="bi bi-lock me-1"></i>Security</a>
  </li>
  <?php if ($role_id === ROLE_RESIDENT && $qr_token): ?>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'qr' ? 'active' : '' ?>"
       href="?tab=qr"><i class="bi bi-qr-code me-1"></i>QR ID Card</a>
  </li>
  <?php endif; ?>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'activity' ? 'active' : '' ?>"
       href="?tab=activity"><i class="bi bi-clock-history me-1"></i>Recent Activity</a>
  </li>
</ul>

<!-- ═══════════════════════════════════════
     TAB: PROFILE
════════════════════════════════════════ -->
<?php if ($tab === 'profile'): ?>
<form method="POST" action="" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="update_profile">

  <div class="row g-4">
    <div class="col-lg-8">

      <!-- Account info -->
      <div class="card mb-4">
        <div class="card-header fw-bold">
          <i class="bi bi-person-circle me-2"></i>Account Information
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Username</label>
              <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($user['username']) ?>" disabled>
              <div class="form-text">Username cannot be changed.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="email">Email Address <span class="text-danger">*</span></label>
              <input type="email" class="form-control" id="email" name="email"
                     value="<?= htmlspecialchars($_POST['email'] ?? $user['email']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Role</label>
              <input type="text" class="form-control bg-light"
                     value="<?= ucwords(str_replace('_', ' ', $user['role_name'])) ?>" disabled>
            </div>
            <div class="col-md-6">
              <label class="form-label">Last Login</label>
              <input type="text" class="form-control bg-light"
                     value="<?= $user['last_login_at'] ? date('M d, Y h:i A', strtotime($user['last_login_at'])) : 'N/A' ?>"
                     disabled>
            </div>
          </div>
        </div>
      </div>

      <!-- Resident-specific fields -->
      <?php if ($role_id === ROLE_RESIDENT && $resident): ?>
      <div class="card mb-4">
        <div class="card-header fw-bold"><i class="bi bi-person-lines-fill me-2"></i>Personal Information</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label" for="first_name">First Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="first_name" name="first_name"
                     value="<?= htmlspecialchars($_POST['first_name'] ?? $resident['first_name']) ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="middle_name">Middle Name</label>
              <input type="text" class="form-control" id="middle_name" name="middle_name"
                     value="<?= htmlspecialchars($_POST['middle_name'] ?? $resident['middle_name'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="last_name">Last Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="last_name" name="last_name"
                     value="<?= htmlspecialchars($_POST['last_name'] ?? $resident['last_name']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="contact_number">Contact Number</label>
              <input type="text" class="form-control" id="contact_number" name="contact_number"
                     value="<?= htmlspecialchars($_POST['contact_number'] ?? $resident['contact_number'] ?? '') ?>"
                     placeholder="09XXXXXXXXX">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="purok_sitio">Purok / Sitio</label>
              <input type="text" class="form-control" id="purok_sitio" name="purok_sitio"
                     value="<?= htmlspecialchars($_POST['purok_sitio'] ?? $resident['purok_sitio'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label" for="street_address">Street Address <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="street_address" name="street_address"
                     value="<?= htmlspecialchars($_POST['street_address'] ?? $resident['street_address']) ?>" required>
            </div>
          </div>
        </div>
      </div>

      <!-- Emergency contact -->
      <div class="card mb-4">
        <div class="card-header fw-bold"><i class="bi bi-telephone-fill me-2"></i>Emergency Contact</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-5">
              <label class="form-label">Contact Name</label>
              <input type="text" class="form-control" name="emergency_contact_name"
                     value="<?= htmlspecialchars($_POST['emergency_contact_name'] ?? $resident['emergency_contact_name'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Contact Number</label>
              <input type="text" class="form-control" name="emergency_contact_number"
                     value="<?= htmlspecialchars($_POST['emergency_contact_number'] ?? $resident['emergency_contact_number'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Relationship</label>
              <input type="text" class="form-control" name="emergency_contact_relation"
                     value="<?= htmlspecialchars($_POST['emergency_contact_relation'] ?? $resident['emergency_contact_relation'] ?? '') ?>"
                     placeholder="Spouse, Parent…">
            </div>
          </div>
        </div>
      </div>

      <!-- Household -->
      <div class="card mb-4">
        <div class="card-header fw-bold"><i class="bi bi-house me-2"></i>Household Information</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Household Size</label>
              <input type="number" class="form-control" name="household_size"
                     min="1" max="99"
                     value="<?= (int)($_POST['household_size'] ?? $resident['household_size'] ?? 1) ?>">
            </div>
            <div class="col-md-9 d-flex align-items-end">
              <div class="row g-2 w-100">
                <?php foreach ([
                  'has_pwd'      => 'Person with Disability (PWD)',
                  'has_senior'   => 'Senior Citizen (60+)',
                  'has_infant'   => 'Infant / Toddler',
                  'has_pregnant' => 'Pregnant Member',
                ] as $fname => $flabel): ?>
                <div class="col-6">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox"
                           id="prof_<?= $fname ?>" name="<?= $fname ?>" value="1"
                           <?= (isset($_POST[$fname]) ? true : (bool)$resident[$fname]) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="prof_<?= $fname ?>"
                           style="font-size:.85rem"><?= $flabel ?></label>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Responder info (read-only) -->
      <?php if ($role_id === ROLE_RESPONDER && $responder): ?>
      <div class="card mb-4">
        <div class="card-header fw-bold"><i class="bi bi-person-badge me-2"></i>Responder Profile</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Full Name</label>
              <input type="text" class="form-control bg-light"
                     value="<?= htmlspecialchars($responder['first_name'] . ' ' . $responder['last_name']) ?>" disabled>
            </div>
            <div class="col-md-6">
              <label class="form-label">Type</label>
              <input type="text" class="form-control bg-light"
                     value="<?= ucwords(str_replace('_', ' ', $responder['responder_type'])) ?>" disabled>
            </div>
            <div class="col-md-6">
              <label class="form-label">Unit / Station</label>
              <input type="text" class="form-control bg-light"
                     value="<?= htmlspecialchars($responder['unit_name'] ?? '—') ?>" disabled>
            </div>
            <div class="col-md-3">
              <label class="form-label">Badge Number</label>
              <input type="text" class="form-control bg-light"
                     value="<?= htmlspecialchars($responder['badge_number'] ?? '—') ?>" disabled>
            </div>
            <div class="col-md-3">
              <label class="form-label">Status</label>
              <input type="text" class="form-control bg-light"
                     value="<?= ucwords(str_replace('_', ' ', $responder['status'])) ?>" disabled>
            </div>
          </div>
          <div class="form-text mt-2">Contact your administrator to update responder profile details.</div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Official info (read-only) -->
      <?php if ($role_id === ROLE_OFFICIAL && $official): ?>
      <div class="card mb-4">
        <div class="card-header fw-bold"><i class="bi bi-award me-2"></i>Official Profile</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Full Name</label>
              <input type="text" class="form-control bg-light"
                     value="<?= htmlspecialchars($official['first_name'] . ' ' . $official['last_name']) ?>" disabled>
            </div>
            <div class="col-md-6">
              <label class="form-label">Position</label>
              <input type="text" class="form-control bg-light"
                     value="<?= htmlspecialchars($official['position']) ?>" disabled>
            </div>
          </div>
          <div class="form-text mt-2">Contact the system administrator to update your official profile.</div>
        </div>
      </div>
      <?php endif; ?>

    </div>

    <!-- Right sidebar -->
    <div class="col-lg-4">
      <div class="card sticky-top" style="top:80px">
        <div class="card-body">
          <!-- Avatar -->
          <div class="text-center mb-3">
            <div style="width:80px;height:80px;background:var(--bdrs-blue);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:2rem;color:#fff;font-weight:700;margin:0 auto">
              <?= strtoupper(substr($user['username'], 0, 1)) ?>
            </div>
            <div class="fw-bold mt-2"><?= htmlspecialchars($user['username']) ?></div>
            <div class="text-muted" style="font-size:.78rem"><?= htmlspecialchars($user['email']) ?></div>
            <span class="badge bg-primary mt-1"><?= ucwords(str_replace('_', ' ', $user['role_name'])) ?></span>
          </div>

          <?php if ($resident): ?>
          <!-- Resident quick info -->
          <div class="border rounded p-2 mb-3" style="font-size:.78rem">
            <div class="fw-semibold mb-1 text-muted" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.05em">Resident Info</div>
            <div><i class="bi bi-house me-1"></i><?= htmlspecialchars(($resident['purok_sitio'] ? $resident['purok_sitio'] . ', ' : '') . $resident['street_address']) ?></div>
            <div><i class="bi bi-people me-1"></i>Household of <?= $resident['household_size'] ?></div>
            <div><i class="bi bi-geo me-1"></i><?= htmlspecialchars($resident['barangay'] . ', ' . $resident['municipality']) ?></div>
          </div>
          <?php endif; ?>

          <div class="d-grid gap-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-save me-1"></i>Save Changes
            </button>
            <a href="?tab=security" class="btn btn-outline-secondary">
              <i class="bi bi-lock me-1"></i>Change Password
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</form>

<!-- ═══════════════════════════════════════
     TAB: SECURITY (Change Password)
════════════════════════════════════════ -->
<?php elseif ($tab === 'security'): ?>
<div class="row">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header fw-bold">
        <i class="bi bi-shield-lock me-2"></i>Change Password
      </div>
      <div class="card-body">
        <form method="POST" action="" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="change_password">

          <div class="mb-3">
            <label class="form-label" for="current_password">
              Current Password <span class="text-danger">*</span>
            </label>
            <div class="input-group">
              <input type="password" class="form-control" id="current_password"
                     name="current_password" required autocomplete="current-password">
              <button class="btn btn-outline-secondary" type="button"
                      onclick="togglePwd('current_password')" tabindex="-1">
                <i class="bi bi-eye"></i>
              </button>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label" for="new_password">
              New Password <span class="text-danger">*</span>
            </label>
            <div class="input-group">
              <input type="password" class="form-control" id="new_password"
                     name="new_password" required minlength="8"
                     autocomplete="new-password" placeholder="Minimum 8 characters">
              <button class="btn btn-outline-secondary" type="button"
                      onclick="togglePwd('new_password')" tabindex="-1">
                <i class="bi bi-eye"></i>
              </button>
            </div>
            <!-- Password strength bar -->
            <div class="progress mt-1" style="height:4px">
              <div class="progress-bar" id="strengthBar" style="width:0%;transition:width .3s"></div>
            </div>
            <div class="form-text" id="strengthLabel"></div>
          </div>

          <div class="mb-4">
            <label class="form-label" for="confirm_password">
              Confirm New Password <span class="text-danger">*</span>
            </label>
            <div class="input-group">
              <input type="password" class="form-control" id="confirm_password"
                     name="confirm_password" required autocomplete="new-password"
                     placeholder="Repeat new password">
              <button class="btn btn-outline-secondary" type="button"
                      onclick="togglePwd('confirm_password')" tabindex="-1">
                <i class="bi bi-eye"></i>
              </button>
            </div>
            <div id="matchMsg" class="form-text"></div>
          </div>

          <button type="submit" class="btn btn-danger w-100">
            <i class="bi bi-lock-fill me-2"></i>Update Password
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card">
      <div class="card-header fw-bold">
        <i class="bi bi-info-circle me-2"></i>Password Requirements
      </div>
      <div class="card-body">
        <ul class="list-unstyled mb-0" style="font-size:.88rem">
          <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>At least 8 characters long</li>
          <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>Mix of uppercase and lowercase letters</li>
          <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>At least one number</li>
          <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>At least one special character (@, #, !, etc.)</li>
          <li><i class="bi bi-x-circle text-danger me-2"></i>Do not use your username as password</li>
        </ul>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header fw-bold">
        <i class="bi bi-shield-check me-2"></i>Session Information
      </div>
      <div class="card-body" style="font-size:.85rem">
        <div class="mb-2">
          <span class="text-muted">Logged in as:</span>
          <strong class="ms-2"><?= htmlspecialchars($user['username']) ?></strong>
        </div>
        <div class="mb-2">
          <span class="text-muted">Session expires:</span>
          <strong class="ms-2">After 4 hours of inactivity</strong>
        </div>
        <div>
          <span class="text-muted">Last login:</span>
          <strong class="ms-2">
            <?= $user['last_login_at'] ? date('M d, Y h:i A', strtotime($user['last_login_at'])) : 'N/A' ?>
          </strong>
        </div>
        <div class="mt-3">
          <a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn btn-outline-danger btn-sm">
            <i class="bi bi-box-arrow-right me-1"></i>Logout All Sessions
          </a>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════
     TAB: QR ID CARD
════════════════════════════════════════ -->
<?php elseif ($tab === 'qr' && $role_id === ROLE_RESIDENT && $qr_token): ?>
<div class="row justify-content-center">
  <div class="col-md-6 col-lg-5">
    <div class="card text-center">
      <div class="card-header fw-bold">
        <i class="bi bi-qr-code me-2"></i>Resident QR Identification Card
      </div>
      <div class="card-body py-4">

        <!-- QR Code display via Google Charts API (works offline via local QR lib if needed) -->
        <div class="qr-box d-inline-block mb-3">
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=BDRS-<?= urlencode($qr_token) ?>"
               alt="QR Code" width="200" height="200"
               style="display:block"
               onerror="this.style.display='none';document.getElementById('qrFallback').style.display='block'">
          <div id="qrFallback" style="display:none;width:200px;height:200px;background:#f0f0f0;display:flex;align-items:center;justify-content:center;font-size:.75rem;color:#666">
            QR unavailable offline.<br>Token: <?= htmlspecialchars(substr($qr_token, 0, 12)) ?>…
          </div>
        </div>

        <!-- ID Card details -->
        <div class="border rounded p-3 text-start mb-3" style="font-size:.85rem">
          <div class="text-center fw-bold mb-2" style="font-size:.95rem">
            <?= htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']) ?>
          </div>
          <div class="row g-1">
            <div class="col-5 text-muted">Purok/Sitio:</div>
            <div class="col-7"><?= htmlspecialchars($resident['purok_sitio'] ?? '—') ?></div>
            <div class="col-5 text-muted">Address:</div>
            <div class="col-7"><?= htmlspecialchars($resident['street_address']) ?></div>
            <div class="col-5 text-muted">Barangay:</div>
            <div class="col-7"><?= htmlspecialchars($resident['barangay']) ?></div>
            <div class="col-5 text-muted">Contact:</div>
            <div class="col-7"><?= htmlspecialchars($resident['contact_number'] ?? '—') ?></div>
            <div class="col-5 text-muted">Emerg. Contact:</div>
            <div class="col-7"><?= htmlspecialchars($resident['emergency_contact_name'] ?? '—') ?></div>
            <div class="col-5 text-muted">Token:</div>
            <div class="col-7 font-monospace" style="font-size:.72rem"><?= htmlspecialchars(substr($qr_token, 0, 16)) ?>…</div>
          </div>
        </div>

        <button class="btn btn-outline-dark" onclick="printPage()">
          <i class="bi bi-printer me-1"></i>Print ID Card
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════
     TAB: RECENT ACTIVITY
════════════════════════════════════════ -->
<?php elseif ($tab === 'activity'): ?>
<div class="card">
  <div class="card-header fw-bold">
    <i class="bi bi-clock-history me-2"></i>Recent Account Activity
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th class="ps-3">Action</th>
            <th>Module</th>
            <th>Description</th>
            <th>IP Address</th>
            <th>Date / Time</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recent_activity)): ?>
          <tr>
            <td colspan="5" class="text-center text-muted py-4">No activity recorded yet.</td>
          </tr>
          <?php else: foreach ($recent_activity as $act): ?>
          <tr>
            <td class="ps-3">
              <span class="badge bg-light text-dark border" style="font-size:.72rem">
                <?= htmlspecialchars($act['action']) ?>
              </span>
            </td>
            <td style="font-size:.8rem"><?= htmlspecialchars($act['module']) ?></td>
            <td style="font-size:.8rem" class="text-muted">
              <?= htmlspecialchars($act['description'] ?? '—') ?>
            </td>
            <td style="font-size:.75rem" class="font-monospace text-muted">
              <?= htmlspecialchars($act['ip_address'] ?? '—') ?>
            </td>
            <td style="font-size:.75rem;white-space:nowrap">
              <?= date('M d, Y h:i A', strtotime($act['created_at'])) ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include APP_ROOT . '/includes/footer.php'; ?>

<script>
// Toggle password visibility
function togglePwd(fieldId) {
    const input = document.getElementById(fieldId);
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
}

// Password strength meter
const newPwdInput = document.getElementById('new_password');
const strengthBar  = document.getElementById('strengthBar');
const strengthLabel = document.getElementById('strengthLabel');
const confirmInput  = document.getElementById('confirm_password');
const matchMsg      = document.getElementById('matchMsg');

function checkStrength(pwd) {
    let score = 0;
    if (pwd.length >= 8)             score++;
    if (pwd.length >= 12)            score++;
    if (/[A-Z]/.test(pwd))           score++;
    if (/[0-9]/.test(pwd))           score++;
    if (/[^A-Za-z0-9]/.test(pwd))   score++;
    return score;
}

if (newPwdInput && strengthBar) {
    newPwdInput.addEventListener('input', () => {
        const score = checkStrength(newPwdInput.value);
        const levels = [
            ['0%',   'bg-secondary', ''],
            ['20%',  'bg-danger',    'Very Weak'],
            ['40%',  'bg-warning',   'Weak'],
            ['60%',  'bg-info',      'Fair'],
            ['80%',  'bg-primary',   'Strong'],
            ['100%', 'bg-success',   'Very Strong'],
        ];
        const [w, cls, label] = levels[score] || levels[0];
        strengthBar.style.width = w;
        strengthBar.className   = 'progress-bar ' + cls;
        if (strengthLabel) strengthLabel.textContent = label;
    });
}

if (confirmInput && newPwdInput && matchMsg) {
    confirmInput.addEventListener('input', () => {
        if (confirmInput.value === '') {
            matchMsg.textContent = '';
        } else if (confirmInput.value === newPwdInput.value) {
            matchMsg.className   = 'form-text text-success';
            matchMsg.textContent = '✓ Passwords match';
        } else {
            matchMsg.className   = 'form-text text-danger';
            matchMsg.textContent = '✗ Passwords do not match';
        }
    });
}
</script>
