<?php
/**
 * Edit Missing Person Record — Officials & Admin only
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role([ROLE_OFFICIAL, ROLE_ADMIN]);

$page_title  = 'Edit Missing Person';
$active_page = 'missing';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    flash('danger', 'Invalid ID.');
    header('Location: ' . APP_URL . '/modules/missing/index.php');
    exit;
}

try {
    $stmt = db()->prepare("SELECT * FROM missing_persons WHERE id = ?");
    $stmt->execute([$id]);
    $person = $stmt->fetch();
    if (!$person) {
        flash('danger', 'Record not found.');
        header('Location: ' . APP_URL . '/modules/missing/index.php');
        exit;
    }
} catch (PDOException $e) {
    flash('danger', 'Database error.');
    header('Location: ' . APP_URL . '/modules/missing/index.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $full_name    = trim($_POST['full_name']          ?? '');
    $age          = (int)($_POST['age']               ?? 0);
    $gender       = trim($_POST['gender']             ?? '');
    $last_seen    = trim($_POST['last_seen_location'] ?? '');
    $last_seen_at = trim($_POST['last_seen_at']       ?? '');
    $description  = trim($_POST['description']        ?? '');
    $status       = trim($_POST['status']             ?? 'missing');
    $found_notes  = trim($_POST['found_notes']        ?? '');

    if (empty($full_name)) $errors[] = 'Full name is required.';
    if (!in_array($gender, ['male','female','other'])) $errors[] = 'Please select a valid gender.';
    if (empty($last_seen)) $errors[] = 'Last seen location is required.';

    // New photo upload
    $photo_path = $person['photo_path'];
    if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        try {
            $photo_path = handle_upload($_FILES['photo'], 'missing');
        } catch (RuntimeException $e) {
            $errors[] = 'Photo upload failed: ' . $e->getMessage();
        }
    }

    if (empty($errors)) {
        try {
            db()->prepare(
                "UPDATE missing_persons
                 SET full_name = ?, age = ?, gender = ?,
                     last_seen_location = ?, last_seen_at = ?,
                     description = ?, status = ?, found_notes = ?,
                     photo_path = ?,
                     found_at = IF(? NOT IN ('missing','unknown') AND found_at IS NULL, NOW(), found_at),
                     updated_at = NOW()
                 WHERE id = ?"
            )->execute([
                $full_name, $age ?: null, $gender,
                $last_seen, $last_seen_at ?: null,
                $description ?: null, $status, $found_notes ?: null,
                $photo_path, $status, $id
            ]);

            log_activity('edit_missing', 'missing', $id, 'Edited missing person: ' . $full_name);
            flash('success', 'Record updated successfully.');
            header('Location: ' . APP_URL . '/modules/missing/view.php?id=' . $id);
            exit;

        } catch (PDOException $e) {
            error_log($e->getMessage());
            $errors[] = 'Database error. Please try again.';
        }
    }
}

$data = !empty($_POST) ? $_POST : $person;

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
  <a href="<?= APP_URL ?>/modules/missing/view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left"></i>
  </a>
  <h4 class="fw-bold mb-0">Edit Missing Person Record</h4>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
  <strong>Fix the following:</strong>
  <ul class="mb-0 mt-1"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>
  <div class="row g-4">
    <div class="col-lg-8">
      <div class="card mb-4">
        <div class="card-header fw-bold">Person Information</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Full Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="full_name"
                     value="<?= htmlspecialchars($data['full_name'] ?? '') ?>" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Age</label>
              <input type="number" class="form-control" name="age"
                     min="0" max="120" value="<?= (int)($data['age'] ?? 0) ?: '' ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Gender <span class="text-danger">*</span></label>
              <select name="gender" class="form-select" required>
                <option value="male"   <?= ($data['gender'] ?? '') === 'male'   ? 'selected':'' ?>>Male</option>
                <option value="female" <?= ($data['gender'] ?? '') === 'female' ? 'selected':'' ?>>Female</option>
                <option value="other"  <?= ($data['gender'] ?? '') === 'other'  ? 'selected':'' ?>>Other</option>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label">Last Seen Location <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="last_seen_location"
                     value="<?= htmlspecialchars($data['last_seen_location'] ?? '') ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Last Seen Date/Time</label>
              <input type="datetime-local" class="form-control" name="last_seen_at"
                     value="<?= htmlspecialchars($data['last_seen_at'] ? date('Y-m-d\TH:i', strtotime($data['last_seen_at'])) : '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Physical Description</label>
              <textarea class="form-control" name="description" rows="3"><?= htmlspecialchars($data['description'] ?? '') ?></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <?php foreach (['missing'=>'Missing','found_safe'=>'Found Safe','found_injured'=>'Found Injured','deceased'=>'Deceased','unknown'=>'Unknown'] as $sv => $sl): ?>
                  <option value="<?= $sv ?>" <?= ($data['status'] ?? '') === $sv ? 'selected':'' ?>><?= $sl ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label">Found / Resolution Notes</label>
              <input type="text" class="form-control" name="found_notes"
                     value="<?= htmlspecialchars($data['found_notes'] ?? '') ?>"
                     placeholder="Where found, condition, etc.">
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-4">
        <div class="card-header fw-bold">Update Photo</div>
        <div class="card-body">
          <?php if ($person['photo_path']): ?>
          <div class="mb-2">
            <img src="<?= APP_URL . '/' . htmlspecialchars($person['photo_path']) ?>"
                 style="height:80px;object-fit:cover;border-radius:.4rem" alt="Current photo">
            <div class="text-muted mt-1" style="font-size:.75rem">Current photo — upload a new one to replace it.</div>
          </div>
          <?php endif; ?>
          <input type="file" class="form-control" name="photo"
                 accept="image/jpeg,image/png,image/gif,image/webp"
                 data-preview="photoPreview">
          <div id="photoPreview" class="mt-2"></div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card">
        <div class="card-body d-grid gap-2">
          <button type="submit" class="btn btn-primary btn-lg">
            <i class="bi bi-save me-1"></i>Save Changes
          </button>
          <a href="<?= APP_URL ?>/modules/missing/view.php?id=<?= $id ?>"
             class="btn btn-outline-secondary">Cancel</a>
        </div>
      </div>
    </div>
  </div>
</form>

<?php include APP_ROOT . '/includes/footer.php'; ?>
