<?php
/**
 * Create Announcement — Officials & Admin only
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role([ROLE_OFFICIAL, ROLE_ADMIN]);

$page_title  = 'Post Announcement';
$active_page = 'announcements';

$errors = [];
$old    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $old = $_POST;

    $type        = trim($_POST['type']        ?? '');
    $severity    = trim($_POST['severity']    ?? 'info');
    $title       = trim($_POST['title']       ?? '');
    $body        = trim($_POST['body']        ?? '');
    $is_pinned   = isset($_POST['is_pinned'])  ? 1 : 0;
    $scheduled   = trim($_POST['scheduled_for'] ?? '');
    $expires     = trim($_POST['expires_at']  ?? '');
    $incident_id = (int)($_POST['incident_id'] ?? 0);

    $valid_types = ['general','evacuation','weather_alert','road_closure','rescue_update','relief_schedule','health_advisory'];
    if (!in_array($type, $valid_types))     $errors[] = 'Please select a valid announcement type.';
    if (!in_array($severity, ['info','warning','critical'])) $errors[] = 'Invalid severity.';
    if (empty($title))  $errors[] = 'Title is required.';
    if (empty($body))   $errors[] = 'Announcement body is required.';

    if (empty($errors)) {
        try {
            db()->prepare(
                "INSERT INTO announcements
                 (posted_by_id, incident_id, type, severity, title, body, is_pinned, scheduled_for, expires_at)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            )->execute([
                current_user_id(),
                $incident_id ?: null,
                $type,
                $severity,
                $title,
                $body,
                $is_pinned,
                $scheduled ?: null,
                $expires    ?: null,
            ]);

            $ann_id = (int)db()->lastInsertId();

            // Notify all residents for critical/warning announcements
            if (in_array($severity, ['critical','warning'])) {
                $residents = db()->query("SELECT u.id FROM users u WHERE u.role_id = 1")->fetchAll();
                foreach ($residents as $res) {
                    send_notification(
                        $res['id'],
                        'announcement',
                        ($severity === 'critical' ? '🚨 CRITICAL ALERT: ' : '⚠ ') . $title,
                        substr($body, 0, 120) . (strlen($body) > 120 ? '…' : ''),
                        APP_URL . '/modules/announcements/index.php'
                    );
                }
            }

            log_activity('create_announcement', 'announcement', $ann_id, 'Posted: ' . $title);
            flash('success', 'Announcement posted successfully.');
            header('Location: ' . APP_URL . '/modules/announcements/index.php');
            exit;

        } catch (PDOException $e) {
            error_log($e->getMessage());
            $errors[] = 'Database error. Please try again.';
        }
    }
}

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
  <a href="<?= APP_URL ?>/modules/announcements/index.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left"></i>
  </a>
  <div>
    <h4 class="fw-bold mb-0">Post Announcement</h4>
    <p class="text-muted mb-0" style="font-size:.82rem">Announce to all registered residents.</p>
  </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
  <strong>Fix the following:</strong>
  <ul class="mb-0 mt-1"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="POST" action="" novalidate>
  <?= csrf_field() ?>

  <div class="row g-4">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header"><i class="bi bi-megaphone me-2"></i>Announcement Content</div>
        <div class="card-body">

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label">Type <span class="text-danger">*</span></label>
              <select name="type" class="form-select" required>
                <option value="">— Select type —</option>
                <?php foreach ([
                  'general'         => 'General Information',
                  'evacuation'      => 'Evacuation Notice',
                  'weather_alert'   => 'Weather Alert',
                  'road_closure'    => 'Road Closure',
                  'rescue_update'   => 'Rescue Operation Update',
                  'relief_schedule' => 'Relief Distribution Schedule',
                  'health_advisory' => 'Health Advisory',
                ] as $val => $lbl): ?>
                  <option value="<?= $val ?>" <?= ($old['type'] ?? '') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Severity / Alert Level <span class="text-danger">*</span></label>
              <div class="d-flex gap-2 mt-1">
                <?php foreach (['info' => ['Info','primary'], 'warning' => ['Warning','warning'], 'critical' => ['Critical','danger']] as $sv => [$sl, $sc]): ?>
                <div class="form-check form-check-inline m-0">
                  <input class="form-check-input" type="radio" name="severity"
                         id="sev_<?= $sv ?>" value="<?= $sv ?>"
                         <?= ($old['severity'] ?? 'info') === $sv ? 'checked' : '' ?>>
                  <label class="form-check-label badge bg-<?= $sc ?> px-2 py-1 cursor-pointer" for="sev_<?= $sv ?>"><?= $sl ?></label>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label" for="title">Announcement Title <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="title" name="title"
                   placeholder="e.g., EVACUATION ADVISORY: Barangay-wide Flood Warning"
                   value="<?= htmlspecialchars($old['title'] ?? '') ?>" maxlength="200" required>
          </div>

          <div class="mb-3">
            <label class="form-label" for="body">Announcement Body <span class="text-danger">*</span></label>
            <textarea class="form-control" id="body" name="body" rows="8"
                      placeholder="Write the full announcement here. Include all relevant instructions for residents."
                      maxlength="5000" required><?= htmlspecialchars($old['body'] ?? '') ?></textarea>
          </div>

        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card mb-4">
        <div class="card-header"><i class="bi bi-gear me-2"></i>Publishing Options</div>
        <div class="card-body">

          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="is_pinned" name="is_pinned" value="1"
                   <?= isset($old['is_pinned']) ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="is_pinned">
              <i class="bi bi-pin-fill me-1"></i>Pin this announcement
            </label>
            <div class="form-text">Pinned announcements appear at the top of the list.</div>
          </div>

          <div class="mb-3">
            <label class="form-label" for="scheduled_for">Schedule for later</label>
            <input type="datetime-local" class="form-control" id="scheduled_for" name="scheduled_for"
                   value="<?= htmlspecialchars($old['scheduled_for'] ?? '') ?>">
            <div class="form-text">Leave blank to publish immediately.</div>
          </div>

          <div class="mb-3">
            <label class="form-label" for="expires_at">Expiry Date/Time</label>
            <input type="datetime-local" class="form-control" id="expires_at" name="expires_at"
                   value="<?= htmlspecialchars($old['expires_at'] ?? '') ?>">
            <div class="form-text">Leave blank for no expiry.</div>
          </div>

          <div class="mb-0">
            <label class="form-label" for="incident_id">Link to Incident</label>
            <select name="incident_id" class="form-select form-select-sm" id="incident_id">
              <option value="">— No linked incident —</option>
              <?php
              try {
                  $incs = db()->query(
                      "SELECT id, reference_number, title FROM incidents
                       WHERE status NOT IN ('archived') ORDER BY created_at DESC LIMIT 20"
                  )->fetchAll();
                  foreach ($incs as $inc): ?>
                  <option value="<?= $inc['id'] ?>" <?= ($old['incident_id'] ?? 0) == $inc['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($inc['reference_number'] . ' — ' . $inc['title']) ?>
                  </option>
              <?php endforeach;
              } catch (PDOException $e) { /* fail silently */ } ?>
            </select>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-body d-grid gap-2">
          <button type="submit" class="btn btn-primary btn-lg">
            <i class="bi bi-megaphone-fill me-2"></i>Post Announcement
          </button>
          <a href="<?= APP_URL ?>/modules/announcements/index.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
      </div>
    </div>
  </div>
</form>

<?php include APP_ROOT . '/includes/footer.php'; ?>
