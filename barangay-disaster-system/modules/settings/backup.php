<?php
/**
 * Database Backup & Restore — Admin only
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role([ROLE_ADMIN]);

$page_title  = 'Backup & Restore';
$active_page = 'settings';

// ── Handle backup download ────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'backup') {
    verify_csrf();

    try {
        $pdo    = db();
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $sql    = "-- BDRS Database Backup\n";
        $sql   .= "-- Generated: " . date('Y-m-d H:i:s') . " (PST)\n";
        $sql   .= "-- System: Community Disaster Reporting & Response System v1.0\n\n";
        $sql   .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            // Table structure
            $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
            $sql   .= "-- ----------------------------\n";
            $sql   .= "-- Table: $table\n";
            $sql   .= "-- ----------------------------\n";
            $sql   .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql   .= $create['Create Table'] . ";\n\n";

            // Table data
            $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll();
            if (!empty($rows)) {
                $cols  = array_keys($rows[0]);
                $colList = '`' . implode('`, `', $cols) . '`';
                $sql  .= "INSERT INTO `$table` ($colList) VALUES\n";

                $values = [];
                foreach ($rows as $row) {
                    $escaped = array_map(function($v) use ($pdo) {
                        if ($v === null) return 'NULL';
                        return $pdo->quote($v);
                    }, array_values($row));
                    $values[] = '(' . implode(', ', $escaped) . ')';
                }
                $sql .= implode(",\n", $values) . ";\n\n";
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        $filename = 'bdrs_backup_' . date('Ymd_His') . '.sql';

        log_activity('database_backup', 'settings', null, 'Database backup downloaded: ' . $filename);

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($sql));
        header('Pragma: no-cache');
        echo $sql;
        exit;

    } catch (PDOException $e) {
        error_log($e->getMessage());
        flash('danger', 'Backup failed: ' . $e->getMessage());
        header('Location: ' . APP_URL . '/modules/settings/backup.php');
        exit;
    }
}

// ── Handle restore upload ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore') {
    verify_csrf();

    if (empty($_FILES['sql_file']['name']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
        flash('danger', 'No valid SQL file uploaded.');
    } else {
        $ext = strtolower(pathinfo($_FILES['sql_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'sql') {
            flash('danger', 'Only .sql files are accepted.');
        } else {
            $sql_content = file_get_contents($_FILES['sql_file']['tmp_name']);
            if (strlen($sql_content) < 100) {
                flash('danger', 'The uploaded file appears to be empty or invalid.');
            } else {
                try {
                    $pdo = db();
                    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

                    // Split by semicolon and execute each statement
                    $statements = array_filter(
                        array_map('trim', explode(";\n", $sql_content)),
                        fn($s) => !empty($s) && !str_starts_with($s, '--')
                    );

                    $count = 0;
                    foreach ($statements as $stmt) {
                        if (!empty(trim($stmt))) {
                            $pdo->exec($stmt);
                            $count++;
                        }
                    }

                    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

                    log_activity('database_restore', 'settings', null,
                        "Database restored from: " . htmlspecialchars($_FILES['sql_file']['name']));

                    flash('success', "Database restored successfully. $count statements executed.");

                } catch (PDOException $e) {
                    error_log($e->getMessage());
                    flash('danger', 'Restore failed: ' . $e->getMessage());
                }
            }
        }
    }

    header('Location: ' . APP_URL . '/modules/settings/backup.php');
    exit;
}

// Fetch DB stats
try {
    $db_stats = db()->query(
        "SELECT
            (SELECT COUNT(*) FROM users)              AS users,
            (SELECT COUNT(*) FROM incidents)          AS incidents,
            (SELECT COUNT(*) FROM rescue_requests)    AS rescues,
            (SELECT COUNT(*) FROM missing_persons)    AS missing,
            (SELECT COUNT(*) FROM announcements)      AS announcements,
            (SELECT COUNT(*) FROM relief_distributions) AS distributions,
            (SELECT COUNT(*) FROM activity_logs)      AS logs"
    )->fetch();
} catch (PDOException $e) {
    $db_stats = [];
}

// List existing backup files in uploads/backups
$backup_dir   = APP_ROOT . '/uploads/backups/';
$backup_files = [];
if (is_dir($backup_dir)) {
    $backup_files = array_filter(scandir($backup_dir), fn($f) => str_ends_with($f, '.sql'));
    rsort($backup_files);
}

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
  <div>
    <h4 class="fw-bold mb-0">Backup &amp; Restore</h4>
    <p class="text-muted mb-0" style="font-size:.82rem">Download a full SQL backup or restore from a previous backup file.</p>
  </div>
</div>

<div class="row g-4">

  <!-- Backup -->
  <div class="col-lg-6">
    <div class="card mb-4">
      <div class="card-header fw-bold">
        <i class="bi bi-cloud-download me-2 text-primary"></i>Create Backup
      </div>
      <div class="card-body">
        <p style="font-size:.88rem">Downloads a complete SQL dump of the entire <strong><?= DB_NAME ?></strong> database including all tables and data.</p>

        <!-- DB Stats -->
        <div class="row g-2 mb-4">
          <?php
          $stat_items = [
            ['Users',         $db_stats['users']         ?? 0, 'bi-people'],
            ['Incidents',     $db_stats['incidents']     ?? 0, 'bi-exclamation-triangle'],
            ['Rescue Reqs',   $db_stats['rescues']       ?? 0, 'bi-life-preserver'],
            ['Missing',       $db_stats['missing']       ?? 0, 'bi-person-fill-exclamation'],
            ['Distributions', $db_stats['distributions'] ?? 0, 'bi-box-seam'],
            ['Log Entries',   $db_stats['logs']          ?? 0, 'bi-journal'],
          ];
          foreach ($stat_items as [$label, $count, $icon]): ?>
          <div class="col-4">
            <div class="border rounded p-2 text-center">
              <i class="<?= $icon ?> text-muted d-block mb-1"></i>
              <div class="fw-bold"><?= number_format($count) ?></div>
              <div class="text-muted" style="font-size:.7rem"><?= $label ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <a href="?action=backup&csrf_token=<?= urlencode(csrf_token()) ?>"
           class="btn btn-primary w-100">
          <i class="bi bi-download me-2"></i>Download SQL Backup
        </a>
        <div class="form-text text-center mt-2">
          Backup includes all tables, data, and structure. Store securely.
        </div>
      </div>
    </div>
  </div>

  <!-- Restore -->
  <div class="col-lg-6">
    <div class="card mb-4 border-warning">
      <div class="card-header fw-bold bg-warning bg-opacity-10">
        <i class="bi bi-cloud-upload me-2 text-warning"></i>Restore from Backup
      </div>
      <div class="card-body">
        <div class="alert alert-warning py-2 px-3 mb-3" style="font-size:.82rem">
          <strong>⚠ Warning:</strong> Restoring will overwrite current data. This action cannot be undone. Create a backup first.
        </div>

        <form method="POST" enctype="multipart/form-data" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="restore">

          <div class="mb-3">
            <label class="form-label fw-semibold">Select SQL Backup File <span class="text-danger">*</span></label>
            <input type="file" name="sql_file" class="form-control" accept=".sql" required>
            <div class="form-text">Only .sql files generated by this system are accepted.</div>
          </div>

          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="confirmRestore" required>
            <label class="form-check-label fw-semibold text-danger" for="confirmRestore">
              I understand this will overwrite all current data.
            </label>
          </div>

          <button type="submit" class="btn btn-warning w-100"
                  data-confirm="Are you absolutely sure you want to restore? ALL current data will be overwritten.">
            <i class="bi bi-arrow-counterclockwise me-2"></i>Restore Database
          </button>
        </form>
      </div>
    </div>
  </div>

</div>

<!-- Backup tips -->
<div class="card">
  <div class="card-header fw-bold"><i class="bi bi-shield-check me-2"></i>Backup Best Practices</div>
  <div class="card-body">
    <div class="row g-3" style="font-size:.85rem">
      <div class="col-md-4">
        <div class="d-flex gap-2">
          <i class="bi bi-calendar-check text-success flex-shrink-0 mt-1"></i>
          <div><strong>Schedule Regular Backups</strong><br>Download a backup at least once a week, or daily during active disaster operations.</div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="d-flex gap-2">
          <i class="bi bi-usb-drive text-primary flex-shrink-0 mt-1"></i>
          <div><strong>Store Offsite</strong><br>Save backup files on a USB drive or external storage, not just on the server PC.</div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="d-flex gap-2">
          <i class="bi bi-clock-history text-warning flex-shrink-0 mt-1"></i>
          <div><strong>Before Major Changes</strong><br>Always create a backup before updating system settings, importing data, or restoring.</div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
