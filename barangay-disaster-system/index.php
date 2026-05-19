<?php
/**
 * Login Page — Public Entry Point
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Already logged in → redirect to dashboard
if (is_logged_in()) {
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
    exit;
}

$error = '';
$msg   = '';

// ── Handle login submission ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        try {
            $stmt = db()->prepare(
                "SELECT u.*, r.role_name FROM users u
                 JOIN roles r ON r.id = u.role_id
                 WHERE (u.username = ? OR u.email = ?) AND u.is_active = 1
                 LIMIT 1"
            );
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                login_user($user);
                header('Location: ' . APP_URL . '/modules/dashboard/index.php');
                exit;
            } else {
                $error = 'Invalid username or password.';
                // Log failed attempt
                log_activity('login_failed', 'auth', null, 'Failed login attempt for: ' . htmlspecialchars($username));
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'A system error occurred. Please contact the administrator.';
        }
    }
}

// Flash messages from redirect
if (!empty($_GET['msg'])) {
    $msgs = [
        'session_expired' => 'Your session has expired. Please log in again.',
        'logged_out'      => 'You have been successfully logged out.',
    ];
    $msg = $msgs[$_GET['msg']] ?? '';
}

// Fetch barangay name for branding
$brgy_name = 'Barangay';
try {
    $s = db()->query("SELECT setting_value FROM system_settings WHERE setting_key = 'barangay_name'")->fetch();
    if ($s) $brgy_name = $s['setting_value'];
} catch (PDOException $e) { /* fail silently */ }
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login | <?= htmlspecialchars($brgy_name) ?> BDRS</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap">
  <style>
    body {
      font-family:'Plus Jakarta Sans',system-ui,sans-serif;
      background:#0f1e2e;
      min-height:100vh;
      display:flex;
      align-items:center;
      justify-content:center;
      position:relative;
      overflow:hidden;
    }
    /* Animated background pattern */
    body::before {
      content:'';
      position:absolute;
      inset:0;
      background:
        radial-gradient(ellipse 80% 60% at 20% 10%, rgba(192,57,43,.18) 0%, transparent 60%),
        radial-gradient(ellipse 60% 50% at 80% 80%, rgba(21,101,192,.15) 0%, transparent 60%);
      animation: bgshift 12s ease-in-out infinite alternate;
    }
    @keyframes bgshift {
      from { opacity:.7; }
      to   { opacity:1; }
    }
    .login-card {
      background:rgba(255,255,255,.97);
      border-radius:1rem;
      box-shadow:0 20px 60px rgba(0,0,0,.4);
      width:100%;
      max-width:420px;
      padding:2.5rem;
      position:relative;
      z-index:1;
    }
    [data-bs-theme="dark"] .login-card { background:rgba(30,37,53,.97); }
    .login-brand-icon {
      width:58px;height:58px;
      background:linear-gradient(135deg,#c0392b,#7f0000);
      border-radius:.75rem;
      display:flex;align-items:center;justify-content:center;
      font-size:1.7rem;color:#fff;
      box-shadow:0 6px 20px rgba(192,57,43,.5);
      margin:0 auto 1rem;
    }
    .form-control {
      border-radius:.5rem;
      padding:.65rem 1rem;
      font-size:.9rem;
    }
    .btn-login {
      background:linear-gradient(135deg,#1565c0,#0d47a1);
      color:#fff;border:none;
      border-radius:.5rem;
      padding:.75rem;
      font-weight:600;font-size:.95rem;
      width:100%;
      transition:transform .15s,box-shadow .15s;
    }
    .btn-login:hover {
      transform:translateY(-1px);
      box-shadow:0 6px 20px rgba(21,101,192,.4);
      color:#fff;
    }
    .powered-by { font-size:.7rem; color:#94a3b8; text-align:center; margin-top:1.5rem; }
    .login-panel-right {
      background:linear-gradient(135deg,#c0392b 0%,#7f0000 100%);
      border-radius:1rem;
      color:#fff;
      padding:2.5rem;
      max-width:340px;
      position:relative;
      z-index:1;
    }
    .hotline-box {
      background:rgba(255,255,255,.12);
      border-radius:.5rem;
      padding:.6rem .85rem;
      font-size:.8rem;
      margin-bottom:.4rem;
    }
    @media(max-width:768px){.login-panel-right{display:none!important}}
  </style>
</head>
<body>
<div class="d-flex gap-4 align-items-stretch p-3" style="position:relative;z-index:1">

  <!-- Login Card -->
  <div class="login-card">
    <div class="login-brand-icon">
      <i class="bi bi-shield-fill-exclamation"></i>
    </div>
    <h1 class="text-center fw-bold mb-0" style="font-size:1.3rem"><?= htmlspecialchars($brgy_name) ?></h1>
    <p class="text-center text-muted mb-3" style="font-size:.78rem">Disaster Reporting &amp; Response System</p>

    <?php if ($error): ?>
      <div class="alert alert-danger py-2 px-3" style="font-size:.85rem">
        <i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <?php if ($msg): ?>
      <div class="alert alert-info py-2 px-3" style="font-size:.85rem">
        <i class="bi bi-info-circle-fill me-1"></i><?= htmlspecialchars($msg) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="" autocomplete="off" novalidate>
      <?= csrf_field() ?>

      <div class="mb-3">
        <label class="form-label fw-semibold" for="username">Username or Email</label>
        <div class="input-group">
          <span class="input-group-text bg-transparent"><i class="bi bi-person"></i></span>
          <input type="text" class="form-control" id="username" name="username"
                 placeholder="Enter username or email"
                 value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                 required autocomplete="username">
        </div>
      </div>

      <div class="mb-4">
        <label class="form-label fw-semibold" for="password">Password</label>
        <div class="input-group">
          <span class="input-group-text bg-transparent"><i class="bi bi-lock"></i></span>
          <input type="password" class="form-control" id="password" name="password"
                 placeholder="Enter password" required autocomplete="current-password">
          <button class="btn btn-outline-secondary" type="button" id="togglePwd" tabindex="-1" aria-label="Show/hide password">
            <i class="bi bi-eye" id="pwdIcon"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="btn-login btn mb-2">
        <i class="bi bi-box-arrow-in-right me-1"></i>Sign In
      </button>
    </form>

    <div class="text-center mt-3">
      <a href="<?= APP_URL ?>/modules/auth/register.php"
         class="text-decoration-none" style="font-size:.82rem">
        <i class="bi bi-person-plus me-1"></i>Register as Resident
      </a>
    </div>

    <div class="powered-by">Powered by BDRS v1.0 &middot; <?= date('Y') ?></div>
  </div>

  <!-- Right info panel (desktop only) -->
  <div class="login-panel-right d-none d-lg-flex flex-column justify-content-between">
    <div>
      <h2 class="fw-bold mb-1" style="font-size:1.2rem">Emergency Response System</h2>
      <p style="font-size:.82rem;opacity:.85;line-height:1.6">
        A centralized platform for disaster reporting, rescue coordination,
        and community safety — serving the residents of
        <?= htmlspecialchars($brgy_name) ?>.
      </p>
    </div>

    <div>
      <div class="mb-3" style="font-size:.72rem;letter-spacing:.1em;opacity:.6;text-transform:uppercase;font-weight:700">
        Emergency Hotlines
      </div>
      <?php
      try {
          $hotlines = db()->query(
              "SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE '%hotline%' AND setting_value != ''"
          )->fetchAll();
          foreach ($hotlines as $h):
              $lbl = ucwords(str_replace(['_', 'hotline'], [' ', ''], $h['setting_key']));
      ?>
        <div class="hotline-box">
          <i class="bi bi-telephone-fill me-1"></i>
          <strong><?= htmlspecialchars(trim($lbl)) ?>:</strong>
          <?= htmlspecialchars($h['setting_value']) ?>
        </div>
      <?php endforeach;
      } catch (PDOException $e) { /* fail silently */ } ?>
    </div>

    <div style="font-size:.72rem;opacity:.5">
      &copy; <?= date('Y') ?> <?= htmlspecialchars($brgy_name) ?> &mdash; LAN / Offline Ready
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Show/hide password
document.getElementById('togglePwd')?.addEventListener('click', () => {
    const pwd  = document.getElementById('password');
    const icon = document.getElementById('pwdIcon');
    if (pwd.type === 'password') { pwd.type = 'text';     icon.className = 'bi bi-eye-slash'; }
    else                         { pwd.type = 'password'; icon.className = 'bi bi-eye'; }
});
// Dark mode persistence
if (localStorage.getItem('bdrs_dark_mode') === '1') {
    document.documentElement.setAttribute('data-bs-theme','dark');
}
</script>
</body>
</html>
