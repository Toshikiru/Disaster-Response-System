<?php
/**
 * Report Missing Person
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();

$page_title  = 'Report Missing Person';
$active_page = 'missing';

$errors = [];
$old    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $old = $_POST;

    $full_name    = trim($_POST['full_name']          ?? '');
    $age          = (int)($_POST['age']               ?? 0);
    $gender       = trim($_POST['gender']             ?? '');
    $last_seen    = trim($_POST['last_seen_location'] ?? '');
    $last_seen_at = trim($_POST['last_seen_at']       ?? '');
    $description  = trim($_POST['description']        ?? '');
    $incident_id  = (int)($_POST['incident_id']       ?? 0);

    if (empty($full_name))  $errors[] = 'Full name is required.';
    if (!in_array($gender, ['male','female','other'])) $errors[] = 'Please select a gender.';
    if (empty($last_seen))  $errors[] = 'Last seen location is required.';

    // Photo upload
    $photo_path = null;
    if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        try {
            $photo_path = handle_upload($_FILES['photo'], 'missing');
        } catch (RuntimeException $e) {
            $errors[] = 'Photo upload failed: ' . $e->getMessage();
        }
    }

    if (empty($errors)) {
        try {
            $ref = generate_reference(REF_MISSING);

            db()->prepare(
                "INSERT INTO missing_persons
                 (reference_number, reporter_user_id, incident_id, full_name, age, gender,
                  last_seen_location, last_seen_at, description, photo_path, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,'missing')"
            )->execute([
                $ref,
                current_user_id(),
                $incident_id ?: null,
                $full_name,
                $age ?: null,
                $gender,
                $last_seen,
                $last_seen_at ?: null,
                $description  ?: null,
                $photo_path,
            ]);

            $mps_id = (int)db()->lastInsertId();

            // Notify officials
            $officials = db()->query("SELECT id FROM users WHERE role_id IN (2,4)")->fetchAll();
            foreach ($officials as $off) {
                send_notification(
                    $off['id'],
                    'missing_update',
                    'Missing Person Reported: ' . $ref,
                    $full_name . ' · Last seen: ' . $last_seen,
                    APP_URL . '/modules/missing/view.php?id=' . $mps_id
                );
            }

            log_activity('report_missing', 'missing', $mps_id, 'Reported missing person: ' . $full_name);
            flash('success', 'Missing person report submitted. Reference: ' . $ref);
            header('Location: ' . APP_URL . '/modules/missing/view.php?id=' . $mps_id);
            exit;

        } catch (PDOException $e) {
            error_log($e->getMessage());
            $errors[] = 'Database error. Please try again.';
        }
    }
}

// Active incidents for linking
try {
    $incidents = db()->query(
        "SELECT id, reference_number, title FROM incidents
         WHERE status NOT IN ('archived') ORDER BY created_at DESC LIMIT 30"
    )->fetchAll();
} catch (PDOException $e) {
    $incidents = [];
}

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
  <a href="<?= APP_URL ?>/modules/missing/index.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left"></i>
  </a>
  <div>
    <h4 class="fw-bold mb-0 text-danger">
      <i class="bi bi-person-fill-exclamation me-2"></i>Report Missing Person
    </h4>
    <p class="text-muted mb-0" style="font-size:.82rem">Fill in all known details to help locate this person.</p>
  </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
  <strong><i class="bi bi-exclamation-triangle-fill me-1"></i>Please fix the following:</strong>
  <ul class="mb-0 mt-1">
    <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>

  <div class="row g-4">
    <div class="col-lg-8">

      <!-- Personal Info -->
      <div class="card mb-4">
        <div class="card-header fw-bold">
          <i class="bi bi-person me-2"></i>Missing Person Information
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Full Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="full_name"
                     value="<?= htmlspecialchars($old['full_name'] ?? '') ?>"
                     placeholder="Complete name of the missing person" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Age</label>
              <input type="number" class="form-control" name="age"
                     min="0" max="120"
                     value="<?= htmlspecialchars($old['age'] ?? '') ?>" placeholder="e.g., 34">
            </div>
            <div class="col-md-3">
              <label class="form-label">Gender <span class="text-danger">*</span></label>
              <select name="gender" class="form-select" required>
                <option value="">— Select —</option>
                <option value="male"   <?= ($old['gender'] ?? '') === 'male'   ? 'selected':'' ?>>Male</option>
                <option value="female" <?= ($old['gender'] ?? '') === 'female' ? 'selected':'' ?>>Female</option>
                <option value="other"  <?= ($old['gender'] ?? '') === 'other'  ? 'selected':'' ?>>Other</option>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label">Last Seen Location <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="last_seen_location"
                     value="<?= htmlspecialchars($old['last_seen_location'] ?? '') ?>"
                     placeholder="Street, Purok, Landmark where last seen" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Last Seen Date/Time</label>
              <input type="datetime-local" class="form-control" name="last_seen_at"
                     value="<?= htmlspecialchars($old['last_seen_at'] ?? '') ?>"
                     max="<?= date('Y-m-d\TH:i') ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Physical Description</label>
              <textarea class="form-control" name="description" rows="4"
                        placeholder="Describe clothing, physical features, medical conditions, distinguishing marks…"
                        maxlength="2000"><?= htmlspecialchars($old['description'] ?? '') ?></textarea>
            </div>
            <div class="col-md-8">
              <label class="form-label">Link to Disaster Incident</label>
              <select name="incident_id" class="form-select">
                <option value="">— No linked incident —</option>
                <?php foreach ($incidents as $inc): ?>
                  <option value="<?= $inc['id'] ?>" <?= ($old['incident_id'] ?? 0) == $inc['id'] ? 'selected':'' ?>>
                    <?= htmlspecialchars($inc['reference_number'] . ' — ' . $inc['title']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
      </div>

      <!-- Photo -->
      <div class="card mb-4">
        <div class="card-header fw-bold">
          <i class="bi bi-camera me-2"></i>Photo of Missing Person <span class="text-muted">(optional but recommended)</span>
        </div>
        <div class="card-body">
          <input type="file" class="form-control" name="photo"
                 accept="image/jpeg,image/png,image/gif,image/webp"
                 data-preview="photoPreview">
          <div class="form-text">A clear face photo helps in identification. Max 5 MB.</div>
          <div id="photoPreview" class="mt-2"></div>
        </div>
      </div>

    </div>

    <!-- Right column -->
    <div class="col-lg-4">

      <!-- Tips card -->
      <div class="card mb-4 border-warning">
        <div class="card-header bg-warning text-dark fw-bold">
          <i class="bi bi-lightbulb me-2"></i>Reporting Tips
        </div>
        <div class="card-body" style="font-size:.82rem">
          <ul class="mb-0 ps-3">
            <li class="mb-1">Include clothing color and type at time last seen</li>
            <li class="mb-1">Mention any medical conditions or disabilities</li>
            <li class="mb-1">Note distinguishing marks (tattoos, scars, moles)</li>
            <li class="mb-1">Provide emergency contact numbers</li>
            <li>Upload the most recent photo available</li>
          </ul>
        </div>
      </div>

      <!-- Submit -->
      <div class="card">
        <div class="card-body d-grid gap-2">
          <button type="submit" class="btn btn-danger btn-lg">
            <i class="bi bi-send-fill me-2"></i>Submit Report
          </button>
          <a href="<?= APP_URL ?>/modules/missing/index.php" class="btn btn-outline-secondary">
            Cancel
          </a>
          <p class="text-muted mb-0 mt-1" style="font-size:.73rem">
            <i class="bi bi-info-circle me-1"></i>
            A reference number will be generated. Officials will be notified immediately.
          </p>
        </div>
      </div>

    </div>
  </div>
</form>

<?php include APP_ROOT . '/includes/footer.php'; ?>
