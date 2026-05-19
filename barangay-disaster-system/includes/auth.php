<?php
/**
 * Session & Authentication Helpers
 * Centralises session startup, CSRF, role checks, and activity logging.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// ── Session bootstrap ────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,              // Browser-session cookie (closes with tab)
        'path'     => '/',
        'secure'   => false,          // Set true when using HTTPS
        'httponly' => true,           // Prevent JS access
        'samesite' => 'Strict',
    ]);
    session_start();
}

// ── Session timeout enforcement ──────────────────────────────────────────────
function enforce_session_timeout(): void {
    if (isset($_SESSION['last_activity'])) {
        if ((time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
            logout_user();
        }
    }
    $_SESSION['last_activity'] = time();
}

// ── Login ─────────────────────────────────────────────────────────────────────
function login_user(array $user): void {
    session_regenerate_id(true);   // Prevent session fixation

    $_SESSION['user_id']   = $user['id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['role_id']   = $user['role_id'];
    $_SESSION['last_activity'] = time();

    // Update last_login_at
    db()->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")
         ->execute([$user['id']]);

    log_activity('login', 'auth', null, 'User logged in');
}

// ── Logout ────────────────────────────────────────────────────────────────────
function logout_user(): void {
    if (isset($_SESSION['user_id'])) {
        log_activity('logout', 'auth', null, 'User logged out');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        setcookie(session_name(), '', time() - 42000, '/');
    }
    session_destroy();
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

// ── Auth checks ───────────────────────────────────────────────────────────────
function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

function require_login(): void {
    enforce_session_timeout();
    if (!is_logged_in()) {
        header('Location: ' . APP_URL . '/index.php?msg=session_expired');
        exit;
    }
}

function require_role(array|int $roles): void {
    require_login();
    $allowed = is_array($roles) ? $roles : [$roles];
    if (!in_array((int)$_SESSION['role_id'], $allowed, true)) {
        http_response_code(403);
        include APP_ROOT . '/includes/403.php';
        exit;
    }
}

function is_admin():     bool { return (int)($_SESSION['role_id'] ?? 0) === ROLE_ADMIN; }
function is_official():  bool { return in_array((int)($_SESSION['role_id'] ?? 0), [ROLE_OFFICIAL, ROLE_ADMIN], true); }
function is_responder(): bool { return (int)($_SESSION['role_id'] ?? 0) === ROLE_RESPONDER; }
function is_resident():  bool { return (int)($_SESSION['role_id'] ?? 0) === ROLE_RESIDENT; }

function current_user_id(): int { return (int)($_SESSION['user_id'] ?? 0); }
function current_role():    int { return (int)($_SESSION['role_id'] ?? 0); }

// ── CSRF ──────────────────────────────────────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        die(json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.']));
    }
}

// ── Activity Logger ───────────────────────────────────────────────────────────
function log_activity(string $action, string $module, ?int $record_id = null, ?string $description = null): void {
    try {
        $stmt = db()->prepare(
            "INSERT INTO activity_logs (user_id, action, module, record_id, description, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $action,
            $module,
            $record_id,
            $description,
            $_SERVER['REMOTE_ADDR']       ?? null,
            $_SERVER['HTTP_USER_AGENT']   ?? null,
        ]);
    } catch (PDOException $e) {
        error_log('[LOG ERROR] ' . $e->getMessage());
    }
}

// ── Notification sender ───────────────────────────────────────────────────────
function send_notification(int $user_id, string $type, string $title, string $message, ?string $link = null): bool {
    try {
        db()->prepare(
            "INSERT INTO notifications (user_id, type, title, message, link_url) VALUES (?, ?, ?, ?, ?)"
        )->execute([$user_id, $type, $title, $message, $link]);
        return true;
    } catch (PDOException $e) {
        error_log('[NOTIF ERROR] ' . $e->getMessage());
        return false;
    }
}

// ── Reference number generator ────────────────────────────────────────────────
function generate_reference(string $prefix): string {
    $pdo  = db();
    $date = date('Ymd');
    $like = $prefix . '-' . $date . '-%';

    $table = match ($prefix) {
        REF_INCIDENT => 'incidents',
        REF_RESCUE   => 'rescue_requests',
        REF_RELIEF   => 'relief_distributions',
        REF_MISSING  => 'missing_persons',
        default      => throw new InvalidArgumentException("Unknown prefix: $prefix"),
    };

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE reference_number LIKE ?");
    $stmt->execute([$like]);
    $seq = (int)$stmt->fetchColumn() + 1;

    return $prefix . '-' . $date . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

// ── Sanitisation helpers ──────────────────────────────────────────────────────
function clean(string $value): string {
    return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

function clean_int(?string $value): int {
    return (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
}

// ── File upload handler ───────────────────────────────────────────────────────
/**
 * Handles a single file from $_FILES[$field].
 * Returns the relative path on success, or throws RuntimeException on failure.
 */
function handle_upload(array $file, string $subdir): string {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('File upload error code: ' . $file['error']);
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        throw new RuntimeException('File exceeds maximum size of 5 MB.');
    }

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!in_array($mimeType, ALLOWED_IMAGE_TYPES, true)) {
        throw new RuntimeException('Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.');
    }

    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = bin2hex(random_bytes(8)) . '_' . time() . '.' . strtolower($ext);
    $destDir  = UPLOAD_DIR . $subdir . '/';
    $destPath = $destDir . $filename;

    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new RuntimeException('Failed to save uploaded file.');
    }

    return 'uploads/' . $subdir . '/' . $filename;
}

// ── JSON response helper ──────────────────────────────────────────────────────
function json_response(bool $success, string $message, array $data = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

// ── Pagination helper ─────────────────────────────────────────────────────────
function paginate(int $total, int $perPage, int $currentPage): array {
    $totalPages = (int)ceil($total / $perPage);
    return [
        'total'        => $total,
        'per_page'     => $perPage,
        'current_page' => max(1, min($currentPage, $totalPages)),
        'total_pages'  => $totalPages,
        'offset'       => ($currentPage - 1) * $perPage,
    ];
}

// ── Flash message ─────────────────────────────────────────────────────────────
function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}
