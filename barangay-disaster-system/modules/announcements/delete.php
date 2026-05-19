<?php
// modules/announcements/delete.php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role([ROLE_OFFICIAL, ROLE_ADMIN]);

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    flash('danger', 'Invalid ID.');
    header('Location: ' . APP_URL . '/modules/announcements/index.php');
    exit;
}

try {
    // Soft-delete: just deactivate it
    db()->prepare("UPDATE announcements SET is_active = 0, updated_at = NOW() WHERE id = ?")
       ->execute([$id]);
    log_activity('delete_announcement', 'announcement', $id, 'Deactivated announcement #' . $id);
    flash('success', 'Announcement removed.');
} catch (PDOException $e) {
    error_log($e->getMessage());
    flash('danger', 'Error removing announcement.');
}

header('Location: ' . APP_URL . '/modules/announcements/index.php');
exit;
