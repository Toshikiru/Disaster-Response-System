<?php
// modules/notifications/mark_all_read.php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
try {
    db()->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([current_user_id()]);
} catch (PDOException $e) {}
header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? APP_URL . '/modules/dashboard/index.php'));
exit;
