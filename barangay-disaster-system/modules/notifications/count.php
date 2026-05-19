<?php
// modules/notifications/count.php — returns unread count as JSON
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
header('Content-Type: application/json');
try {
    $cnt = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $cnt->execute([current_user_id()]);
    echo json_encode(['count' => (int)$cnt->fetchColumn()]);
} catch (PDOException $e) {
    echo json_encode(['count' => 0]);
}
