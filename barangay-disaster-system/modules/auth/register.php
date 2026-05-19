<?php
/**
 * Resident Registration — Public
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

// Check if public registration is allowed
$allow_reg = '1';
try {
    $s = db()->query("SELECT setting_value FROM system_settings WHERE setting_key = 'allow_registration'")->fetch();
    if ($s) $allow_reg = $s['setting_value'];
} catch (PDOException $e) {}

if ($allow_reg !== '1') {
    die('<div class="text-center p-5">Registration is currently disabled. Please contact the barangay office.</div>');
}

if (is_logged_in()) {
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
    exit;
}

$errors = [];
$old    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $old = $_POST;

    $username  = trim($_POST['username']   ?? '');
    $email     = trim($_POST['email']      ?? '');
    $password  = $_POST['password']        ?? '';
    $confirm   = $_POST['password_confirm'] ?? '';
    $first     = trim($_POST['first_name'] ?? '');
    $last      = trim($_POST['last_name']  ?? '');
    $middle    = trim($_POST['middle_name'] ?? '');
    $dob       = trim($_POST['date_of_birth'] ?? '');
    $gender    = trim($_POST['gender']     ?? '');
    $purok     = trim($_POST['purok_sitio'] ?? '');
    $street    = trim($_POST['street_address'] ?? '');
    $contact   = trim($_POST['contact_number'] ?? '');
    $ec_name   = trim($_POST['emergency_contact_name'] ?? '');
    $ec_number = trim($_POST['emergency_contact_number'] ?? '');
    $ec_rel    = trim($_POST['emergency_contact_relation'] ?? '');
    $hh_size   = max(1, (int)($_POST['household_size'] ?? 1));
    $has_pwd   = isset($_POST['has_pwd'])    ? 1 : 0;
    $has_senior= isset($_POST['has_senior']) ? 1 : 0;
    $has_infant= isset($_POST['has_infant']) ? 1 : 0;
    $has_preg  = isset($_POST['has_pregnant']) ? 1 : 0;

    // Validate
    if (empty($username) || strlen($username) < 4) $errors[] = 'Username must be at least 4 characters.';
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) $errors[] = 'Username may only contain letters, numbers, and underscores.';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email address is required.';
    if (empty($password) || strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';
    if (empty($first)) $errors[] = 'First name is required.';
    if (empty($last))  $errors[] = 'Last name is required.';
    if (!in_array($gender, ['male','female','other'])) $errors[] = 'Please select a gender.';
    if (empty($street)) $errors[] = 'Street address is required.';

    // Check unique username / email
    if (empty($errors)) {
        try {
            $check = db()->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
            $check->execute([$username, $email]);
            if ((int)$check->fetchColumn() > 0) {
                $errors[] = 'Username or email address is already taken.';
            }
        } catch (PDOException $e) {
            $errors[] = 'System error. Please try again.';
        }
    }

    if (empty($errors)) {
        try {
            db()->beginTransaction();

            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            db()->prepare(
                "INSERT INTO users (role_id, username, email, password_hash) VALUES (1, ?, ?, ?)"
            )->execute([$username, $email, $hash]);

            $user_id = (int)db()->lastInsertId();
            $qr_token = bin2hex(random_bytes(16));

            // Fetch barangay/municipality/province from settings
            $settings_rows = db()->query(
                "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('barangay_name','municipality','province')"
            )->fetchAll();
            $sett = [];
            foreach ($settings_rows as $sr) $sett[$sr['setting_key']] = $sr['setting_value'];

            db()->prepare(
                "INSERT INTO residents
                 (user_id, qr_code_token, first_name, middle_name, last_name, date_of_birth, gender,
                  purok_sitio, street_address, barangay, municipality, province,
                  contact_number, emergency_contact_name, emergency_contact_number, emergency_contact_relation,
                  household_size, has_pwd, has_senior, has_infant, has_pregnant)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $user_id, $qr_token,
                $first, $middle ?: null, $last,
                $dob ?: null, $gender,
                $purok ?: null, $street,
                $sett['barangay_name'] ?? 'Barangay',
                $sett['municipality']  ?? 'Municipality',
                $sett['province']      ?? 'Province',
                $contact  ?: null,
                $ec_name  ?: null, $ec_number ?: null, $ec_rel ?: null,
                $hh_size,
                $has_pwd, $has_senior, $has_infant, $has_preg,
            ]);

            db()->commit();

            log_activity('register', 'auth', $user_id, 'New resident registered: ' . $username);

            // Auto-login after registration
            $new_user = db()->prepare("SELECT u.*, r.role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?");
            $new_user->execute([$user_id]);
            login_user($new_user->fetch());

            flash('success', 'Registration successful! Welcome to the BDRS system.');
            header('Location: ' . APP_URL . '/modules/dashboard/index.php');
            exit;

        } catch (PDOException $e) {
            db()->rollBack();
            error_log($e->getMessage());
            $errors[] = 'Registration failed. Please try again.';
        }
    }
}

$brgy_name = 'Barangay';
try {
    $s = db()->query("SELECT setting_value FROM system_settings WHERE setting_key = 'barangay_name'")->fetch();
    if ($s) $brgy_name = $s['setting_value'];
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register | <?= htmlspecialchars($brgy_name) ?> BDRS</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap">
  <style>
    body { font-family:'Plus Jakarta Sans',system-ui,sans-serif; background:#f3f5f8; }
    .reg-header { background:linear-gradient(135deg,#0f1e2e,#1565c0); color:#fff; border-radius:1rem 1rem 0 0; padding:1.5rem 2rem; }
    .card { border-radius:1rem; box-shadow:0 4px 24px rgba(0,0,0,.10); border:none; }
    .section-label { font-size:.7rem;letter-spacing:.1em;text-transform:uppercase;font-weight:700;color:#64748b;margin-bottom:.5rem; }
  </style>
</head>
<body class="py-4">
<div class="container" style="max-width:800px">

  <div class="text-center mb-3">
    <a href="<?= APP_URL ?>/index.php" class="text-muted text-decoration-none" style="font-size:.85rem">
      <i class="bi bi-arrow-left me-1"></i>Back to Login
    </a>
  </div>

  <div class="card">
    <div class="reg-header">
      <div class="d-flex align-items-center gap-3">
        <div style="width:46px;height:46px;background:rgba(255,255,255,.15);border-radius:.5rem;display:flex;align-items:center;justify-content:center;font-size:1.4rem">
          <i class="bi bi-person-plus-fill"></i>
        </div>
        <div>
          <h4 class="mb-0 fw-bold">Resident Registration</h4>
          <p class="mb-0 opacity-75" style="font-size:.82rem"><?= htmlspecialchars($brgy_name) ?> — Disaster Response System</p>
        </div>
      </div>
    </div>

    <div class="card-body p-4">

      <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <strong><i class="bi bi-exclamation-triangle-fill me-1"></i>Please correct these errors:</strong>
        <ul class="mb-0 mt-1"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
      </div>
      <?php endif; ?>

      <form method="POST" action="" novalidate>
        <?= csrf_field() ?>

        <!-- Account credentials -->
        <div class="section-label mb-2">Account Credentials</div>
        <div class="row g-3 mb-4">
          <div class="col-md-4">
            <label class="form-label" for="username">Username <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="username" name="username"
                   value="<?= htmlspecialchars($old['username'] ?? '') ?>"
                   placeholder="e.g., juan_dela_cruz" required minlength="4" pattern="[a-zA-Z0-9_]+">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="email">Email Address <span class="text-danger">*</span></label>
            <input type="email" class="form-control" id="email" name="email"
                   value="<?= htmlspecialchars($old['email'] ?? '') ?>"
                   placeholder="yourname@email.com" required>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="password">Password <span class="text-danger">*</span></label>
            <input type="password" class="form-control" id="password" name="password"
                   placeholder="Min. 8 characters" required minlength="8">
          </div>
          <div class="col-md-4 offset-md-8">
            <label class="form-label" for="password_confirm">Confirm Password <span class="text-danger">*</span></label>
            <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                   placeholder="Repeat password" required>
          </div>
        </div>

        <!-- Personal info -->
        <div class="section-label mb-2">Personal Information</div>
        <div class="row g-3 mb-4">
          <div class="col-md-4">
            <label class="form-label" for="first_name">First Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="first_name" name="first_name"
                   value="<?= htmlspecialchars($old['first_name'] ?? '') ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="middle_name">Middle Name</label>
            <input type="text" class="form-control" id="middle_name" name="middle_name"
                   value="<?= htmlspecialchars($old['middle_name'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="last_name">Last Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="last_name" name="last_name"
                   value="<?= htmlspecialchars($old['last_name'] ?? '') ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="date_of_birth">Date of Birth</label>
            <input type="date" class="form-control" id="date_of_birth" name="date_of_birth"
                   value="<?= htmlspecialchars($old['date_of_birth'] ?? '') ?>" max="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Gender <span class="text-danger">*</span></label>
            <select name="gender" class="form-select" required>
              <option value="">— Select —</option>
              <option value="male"   <?= ($old['gender'] ?? '') === 'male'   ? 'selected' : '' ?>>Male</option>
              <option value="female" <?= ($old['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
              <option value="other"  <?= ($old['gender'] ?? '') === 'other'  ? 'selected' : '' ?>>Other</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="contact_number">Contact Number</label>
            <input type="text" class="form-control" id="contact_number" name="contact_number"
                   value="<?= htmlspecialchars($old['contact_number'] ?? '') ?>" placeholder="09XXXXXXXXX">
          </div>
        </div>

        <!-- Address -->
        <div class="section-label mb-2">Home Address</div>
        <div class="row g-3 mb-4">
          <div class="col-md-4">
            <label class="form-label" for="purok_sitio">Purok / Sitio</label>
            <input type="text" class="form-control" id="purok_sitio" name="purok_sitio"
                   value="<?= htmlspecialchars($old['purok_sitio'] ?? '') ?>" placeholder="Purok 1, Sitio Mabuhay…">
          </div>
          <div class="col-md-8">
            <label class="form-label" for="street_address">Street Address <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="street_address" name="street_address"
                   value="<?= htmlspecialchars($old['street_address'] ?? '') ?>"
                   placeholder="House no., street name, barangay" required>
          </div>
        </div>

        <!-- Emergency contact -->
        <div class="section-label mb-2">Emergency Contact</div>
        <div class="row g-3 mb-4">
          <div class="col-md-5">
            <label class="form-label" for="emergency_contact_name">Contact Name</label>
            <input type="text" class="form-control" id="emergency_contact_name" name="emergency_contact_name"
                   value="<?= htmlspecialchars($old['emergency_contact_name'] ?? '') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="emergency_contact_number">Contact Number</label>
            <input type="text" class="form-control" id="emergency_contact_number" name="emergency_contact_number"
                   value="<?= htmlspecialchars($old['emergency_contact_number'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="emergency_contact_relation">Relationship</label>
            <input type="text" class="form-control" id="emergency_contact_relation" name="emergency_contact_relation"
                   value="<?= htmlspecialchars($old['emergency_contact_relation'] ?? '') ?>"
                   placeholder="Spouse, Parent, Sibling…">
          </div>
        </div>

        <!-- Household -->
        <div class="section-label mb-2">Household Information</div>
        <div class="row g-3 mb-4">
          <div class="col-md-3">
            <label class="form-label" for="household_size">Household Size</label>
            <input type="number" class="form-control" id="household_size" name="household_size"
                   min="1" max="99" value="<?= (int)($old['household_size'] ?? 1) ?>">
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
                  <input class="form-check-input" type="checkbox" id="<?= $fname ?>" name="<?= $fname ?>" value="1"
                         <?= isset($old[$fname]) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="<?= $fname ?>" style="font-size:.85rem"><?= $flabel ?></label>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="d-grid gap-2">
          <button type="submit" class="btn btn-primary btn-lg">
            <i class="bi bi-person-check-fill me-2"></i>Create My Account
          </button>
        </div>

        <p class="text-center text-muted mt-3 mb-0" style="font-size:.8rem">
          Already have an account?
          <a href="<?= APP_URL ?>/index.php" class="text-decoration-none">Sign in here</a>
        </p>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Password match indicator
const pwd  = document.getElementById('password');
const conf = document.getElementById('password_confirm');
conf.addEventListener('input', () => {
    conf.setCustomValidity(conf.value !== pwd.value ? 'Passwords do not match' : '');
});
</script>
</body>
</html>
