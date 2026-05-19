<?php
/**
 * Edit Announcement — Officials & Admin only
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role([ROLE_OFFICIAL, ROLE_ADMIN]);

$page_title  = 'Edit Announcement';
$active_page = 'announcements';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    flash('danger', 'Invalid ID.');
    header('Location: ' . APP_URL . '/modules/announcements/index.php');
    exit;
}

try {
    $stmt = db()->prepare("SELECT * FROM announcements WHERE id = ?");
    $stmt->execute([$id]);
    $ann = $stmt->fetch();
    if (!$ann) {
        flash('danger', 'Announcement not found.');
        header('Location: ' . APP_URL . '/modules/announcements/index.php');
        exit;
    }
} catch (PDOException $e) {
    flash('danger', 'Database error.');
    header('Location: ' . APP_URL . '/modules/announcements/index.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $type      = trim($_POST['type']      ?? '');
    $severity  = trim($_POST['severity']  ?? 'info');
    $title     = trim($_POST['title']     ?? '');
    $body      = trim($_POST['body']      ?? '');
    $is_pinned = isset($_POST['is_pinned'])  ? 1 : 0;
    $is_active = isset($_POST['is_active'])  ? 1 : 0;
    $scheduled = trim($_POST['scheduled_for'] ?? '');
    $expires   = trim($_POST['expires_at']    ?? '');

    $valid_types = ['general','evacuation','weather_alert','road_closure','rescue_update','relief_schedule','health_advisory'];
    if (!in_array($type, $valid_types))     $errors[] = 'Invalid type.';
    if (!in_array($severity, ['info','warning','critical'])) $errors[] = 'Invalid severity.';
    if (empty($title))  $errors[] = 'Title is required.';
    if (empty($body))   $errors[] = 'Body is required.';

    if (empty($errors)) {
        try {
            db()->prepare(
                "UPDATE announcements SET
                    type = ?, severity = ?, title = ?, body = ?,
                    is_pinned = ?, is_active = ?,
                    scheduled_for = ?, expires_at = ?,
                    updated_at = NOW()
                 WHERE id = ?"
            )->execute([
                $type, $severity, $title, $body,
                $is_pinned, $is_active,
                $scheduled ?: null, $expires ?: null,
                $id
            ]);

            log_activity('edit_announcement', 'announcement', $id, 'Edited: ' . $title);
            flash('success', 'Announcement updated successfully.');
            header('Location: ' . APP_URL . '/modules/announcements/index.php');
            exit;

        } catch (PDOException $e) {
            error_log($e->getMessage());
            $errors[] = 'Database error. Please try again.';
        }
    }
}

$data = !empty($_POST) ? array_merge($ann, $_POST) : $ann;

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
  <a href="<?= APP_URL ?>/modules/announcements/index.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left"></i>
  </a>
  <h4 class="fw-bold mb-0">Edit Announcement</h4>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
  <strong>Fix the following:</strong>
  <ul class="mb-0 mt-1"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="POST" novalidate>
  <?= csrf_field() ?>
  <div class="row g-4">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header fw-bold">Announcement Content</div>
        <div class="card-body">
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label">Type <span class="text-danger">*</span></label>
              <select name="type" class="form-select" required>
                <?php foreach ([
                  'general'         => 'General Information',
                  'evacuation'      => 'Evacuation Notice',
                  'weather_alert'   => 'Weather Alert',
                  'road_closure'    => 'Road Closure',
                  'rescue_update'   => 'Rescue Operation Update',
                  'relief_schedule' => 'Relief Distribution Schedule',
                  'health_advisory' => 'Health Advisory',
                ] as $val => $lbl): ?>
                  <option value="<?= $val ?>" <?= $data['type'] === $val ? 'selected':'' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Severity</label>
              <div class="d-flex gap-2 mt-1">
                <?php foreach (['info'=>['Info','primary'],'warning'=>['Warning','warning'],'critical'=>['Critical','danger']] as $sv=>[$sl,$sc]): ?>
                <div class="form-check form-check-inline m-0">
                  <input class="form-check-input" type="radio" name="severity" id="esev_<?= $sv ?>"
                         value="<?= $sv ?>" <?= $data['severity'] === $sv ? 'checked':'' ?>>
                  <label class="form-check-label badge bg-<?= $sc ?> px-2 py-1 cursor-pointer" for="esev_<?= $sv ?>"><?= $sl ?></label>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Title <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="title"
                   value="<?= htmlspecialchars($data['title']) ?>" maxlength="200" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Body <span class="text-danger">*</span></label>
            <textarea class="form-control" name="body" rows="8" maxlength="5000" required><?= htmlspecialchars($data['body']) ?></textarea>
          </div>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card mb-4">
        <div class="card-header fw-bold">Publishing Options</div>
        <div class="card-body">
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="is_pinned" name="is_pinned" value="1"
                   <?= $data['is_pinned'] ? 'checked':'' ?>>
            <label class="form-check-label fw-semibold" for="is_pinned">
              <i class="bi bi-pin-fill me-1"></i>Pin this announcement
            </label>
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                   <?= $data['is_active'] ? 'checked':'' ?>>
            <label class="form-check-label fw-semibold" for="is_active">Active (visible to users)</label>
          </div>
          <div class="mb-3">
            <label class="form-label">Scheduled For</label>
            <input type="datetime-local" class="form-control" name="scheduled_for"
                   value="<?= htmlspecialchars($data['scheduled_for'] ? date('Y-m-d\TH:i', strtotime($data['scheduled_for'])) : '') ?>">
          </div>
          <div>
            <label class="form-label">Expires At</label>
            <input type="datetime-local" class="form-control" name="expires_at"
                   value="<?= htmlspecialchars($data['expires_at'] ? date('Y-m-d\TH:i', strtotime($data['expires_at'])) : '') ?>">
          </div>
        </div>
      </div>
      <div class="card">
        <div class="card-body d-grid gap-2">
          <button type="submit" class="btn btn-primary btn-lg">
            <i class="bi bi-save me-1"></i>Save Changes
          </button>
          <a href="<?= APP_URL ?>/modules/announcements/index.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
      </div>
    </div>
  </div>
</form>

<?php include APP_ROOT . '/includes/footer.php'; ?>
