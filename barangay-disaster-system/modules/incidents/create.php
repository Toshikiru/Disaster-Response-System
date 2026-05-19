<?php
/**
 * Create Incident Report
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

$page_title  = 'Report an Incident';
$active_page = 'incidents';

$errors = [];
$old    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $old = $_POST;

    // Validation
    $title       = trim($_POST['title']       ?? '');
    $type        = trim($_POST['incident_type'] ?? '');
    $severity    = trim($_POST['severity']     ?? '');
    $description = trim($_POST['description'] ?? '');
    $location    = trim($_POST['location_address'] ?? '');
    $landmark    = trim($_POST['landmark']    ?? '');
    $latitude    = trim($_POST['latitude']    ?? '');
    $longitude   = trim($_POST['longitude']   ?? '');
    $affected    = (int)($_POST['estimated_affected'] ?? 0);
    $households  = (int)($_POST['estimated_households'] ?? 0);
    $reported_at = trim($_POST['reported_at'] ?? '');

    if (empty($title))       $errors[] = 'Incident title is required.';
    if (empty($type) || !array_key_exists($type, INCIDENT_TYPES)) $errors[] = 'Please select a valid incident type.';
    if (!in_array($severity, ['low','moderate','high','critical'])) $errors[] = 'Please select a severity level.';
    if (empty($description)) $errors[] = 'Description is required.';
    if (empty($location))    $errors[] = 'Location address is required.';
    if (empty($reported_at)) $errors[] = 'Incident date/time is required.';

    // Photo uploads (optional, up to 5)
    $photo_paths = [];
    if (!empty($_FILES['photos']['name'][0])) {
        $file_count = count($_FILES['photos']['name']);
        if ($file_count > MAX_FILES_PER_INC) {
            $errors[] = 'Maximum ' . MAX_FILES_PER_INC . ' photos allowed.';
        } else {
            for ($i = 0; $i < $file_count; $i++) {
                if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                $single = [
                    'name'     => $_FILES['photos']['name'][$i],
                    'type'     => $_FILES['photos']['type'][$i],
                    'tmp_name' => $_FILES['photos']['tmp_name'][$i],
                    'error'    => $_FILES['photos']['error'][$i],
                    'size'     => $_FILES['photos']['size'][$i],
                ];
                try {
                    $photo_paths[] = handle_upload($single, 'incidents');
                } catch (RuntimeException $e) {
                    $errors[] = 'Photo upload failed: ' . $e->getMessage();
                }
            }
        }
    }

    if (empty($errors)) {
        try {
            $ref = generate_reference(REF_INCIDENT);

            db()->prepare(
                "INSERT INTO incidents
                 (reference_number, reporter_user_id, incident_type, severity, status,
                  title, description, location_address, latitude, longitude, landmark,
                  estimated_affected, estimated_households, reported_at)
                 VALUES (?,?,?,?,'pending',?,?,?,?,?,?,?,?,?)"
            )->execute([
                $ref,
                current_user_id(),
                $type,
                $severity,
                $title,
                $description,
                $location,
                $latitude !== '' ? $latitude : null,
                $longitude !== '' ? $longitude : null,
                $landmark ?: null,
                $affected  ?: null,
                $households ?: null,
                $reported_at,
            ]);

            $incident_id = (int)db()->lastInsertId();

            // Save photos
            foreach ($photo_paths as $path) {
                db()->prepare(
                    "INSERT INTO incident_photos (incident_id, file_path) VALUES (?,?)"
                )->execute([$incident_id, $path]);
            }

            // Notify all officials of new incident
            $officials = db()->query(
                "SELECT u.id FROM users u WHERE u.role_id IN (2,4)"
            )->fetchAll();
            foreach ($officials as $off) {
                send_notification(
                    $off['id'],
                    'incident_update',
                    'New Incident: ' . $ref,
                    ucfirst($type) . ' — ' . $title . ' at ' . $location,
                    APP_URL . '/modules/incidents/view.php?id=' . $incident_id
                );
            }

            log_activity('create_incident', 'incident', $incident_id, 'Created incident ' . $ref);
            flash('success', 'Incident report submitted successfully. Reference: ' . $ref);
            header('Location: ' . APP_URL . '/modules/incidents/view.php?id=' . $incident_id);
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
  <a href="<?= APP_URL ?>/modules/incidents/index.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left"></i>
  </a>
  <div>
    <h4 class="fw-bold mb-0">Report an Incident</h4>
    <p class="text-muted mb-0" style="font-size:.82rem">Fill out all required fields accurately.</p>
  </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
  <strong><i class="bi bi-exclamation-triangle-fill me-1"></i>Please fix the following errors:</strong>
  <ul class="mb-0 mt-1">
    <?php foreach ($errors as $e): ?>
      <li><?= htmlspecialchars($e) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<form method="POST" action="" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>

  <div class="row g-4">

    <!-- Left column: core details -->
    <div class="col-lg-8">

      <!-- Basic Info -->
      <div class="card mb-4">
        <div class="card-header">
          <i class="bi bi-info-circle me-2"></i>Incident Details
        </div>
        <div class="card-body">

          <div class="mb-3">
            <label class="form-label" for="title">Incident Title <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="title" name="title"
                   placeholder="e.g., Flash flood at Purok 3 near the river"
                   value="<?= htmlspecialchars($old['title'] ?? '') ?>" maxlength="200" required>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label" for="incident_type">Incident Type <span class="text-danger">*</span></label>
              <select class="form-select" id="incident_type" name="incident_type" required>
                <option value="">— Select type —</option>
                <?php foreach (INCIDENT_TYPES as $val => $info): ?>
                  <option value="<?= $val ?>" <?= ($old['incident_type'] ?? '') === $val ? 'selected' : '' ?>>
                    <?= $info['label'] ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Severity Level <span class="text-danger">*</span></label>
              <div class="d-flex gap-2 flex-wrap mt-1">
                <?php foreach (['low' => ['Low','success'], 'moderate' => ['Moderate','warning'], 'high' => ['High','danger'], 'critical' => ['CRITICAL','dark']] as $sval => [$slabel, $scls]): ?>
                <div class="form-check form-check-inline m-0">
                  <input class="form-check-input" type="radio" name="severity"
                         id="sev_<?= $sval ?>" value="<?= $sval ?>"
                         <?= ($old['severity'] ?? '') === $sval ? 'checked' : '' ?> required>
                  <label class="form-check-label badge bg-<?= $scls ?> px-2 py-1 cursor-pointer" for="sev_<?= $sval ?>">
                    <?= $slabel ?>
                  </label>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label" for="description">Description <span class="text-danger">*</span></label>
            <textarea class="form-control" id="description" name="description" rows="4"
                      placeholder="Describe the incident in detail: what happened, current situation, hazards present…"
                      maxlength="3000" required><?= htmlspecialchars($old['description'] ?? '') ?></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label" for="reported_at">Date &amp; Time of Incident <span class="text-danger">*</span></label>
            <input type="datetime-local" class="form-control" id="reported_at" name="reported_at"
                   value="<?= htmlspecialchars($old['reported_at'] ?? date('Y-m-d\TH:i')) ?>"
                   max="<?= date('Y-m-d\TH:i') ?>" required>
            <div class="form-text">When did the incident occur? (Not the time you are filing this report)</div>
          </div>

        </div>
      </div>

      <!-- Location -->
      <div class="card mb-4">
        <div class="card-header">
          <i class="bi bi-geo-alt me-2"></i>Location Information
        </div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label" for="location_address">Street Address / Purok / Area <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="location_address" name="location_address"
                   placeholder="e.g., Purok 3, Sitio Mabuhay, near the bridge"
                   value="<?= htmlspecialchars($old['location_address'] ?? '') ?>" maxlength="300" required>
          </div>
          <div class="mb-3">
            <label class="form-label" for="landmark">Nearest Landmark</label>
            <input type="text" class="form-control" id="landmark" name="landmark"
                   placeholder="e.g., Beside the elementary school, behind the chapel"
                   value="<?= htmlspecialchars($old['landmark'] ?? '') ?>" maxlength="200">
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="latitude">GPS Latitude <span class="text-muted">(optional)</span></label>
              <input type="number" class="form-control" id="latitude" name="latitude"
                     placeholder="e.g., 10.3157" step="any" min="-90" max="90"
                     value="<?= htmlspecialchars($old['latitude'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="longitude">GPS Longitude <span class="text-muted">(optional)</span></label>
              <input type="number" class="form-control" id="longitude" name="longitude"
                     placeholder="e.g., 123.8854" step="any" min="-180" max="180"
                     value="<?= htmlspecialchars($old['longitude'] ?? '') ?>">
            </div>
          </div>
          <div class="mt-2">
            <button type="button" class="btn btn-sm btn-outline-primary" id="getLocationBtn">
              <i class="bi bi-crosshair me-1"></i>Use My Current Location
            </button>
            <small class="text-muted ms-2" id="locationStatus"></small>
          </div>
        </div>
      </div>

      <!-- Photos -->
      <div class="card mb-4">
        <div class="card-header">
          <i class="bi bi-camera me-2"></i>Incident Photos <span class="text-muted">(optional, max 5)</span>
        </div>
        <div class="card-body">
          <input type="file" class="form-control" name="photos[]" id="photoInput"
                 accept="image/jpeg,image/png,image/gif,image/webp"
                 multiple data-preview="photoPreview">
          <div class="form-text">JPG, PNG, GIF or WebP · Max 5 MB per photo</div>
          <div id="photoPreview" class="mt-2 d-flex flex-wrap gap-1"></div>
        </div>
      </div>

    </div>

    <!-- Right column: affected & submit -->
    <div class="col-lg-4">

      <div class="card mb-4">
        <div class="card-header">
          <i class="bi bi-people me-2"></i>Affected Population
        </div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label" for="estimated_affected">Estimated Affected Persons</label>
            <input type="number" class="form-control" id="estimated_affected" name="estimated_affected"
                   min="0" max="9999" placeholder="0"
                   value="<?= htmlspecialchars($old['estimated_affected'] ?? '') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label" for="estimated_households">Estimated Affected Households</label>
            <input type="number" class="form-control" id="estimated_households" name="estimated_households"
                   min="0" max="9999" placeholder="0"
                   value="<?= htmlspecialchars($old['estimated_households'] ?? '') ?>">
          </div>
        </div>
      </div>

      <!-- Severity guide -->
      <div class="card mb-4">
        <div class="card-header"><i class="bi bi-question-circle me-2"></i>Severity Guide</div>
        <div class="card-body p-2">
          <table class="table table-sm mb-0" style="font-size:.78rem">
            <tbody>
              <tr><td><span class="badge badge-severity-low text-white">Low</span></td><td>Minor incident, no immediate danger</td></tr>
              <tr><td><span class="badge badge-severity-moderate text-white">Moderate</span></td><td>Property damage, some risk present</td></tr>
              <tr><td><span class="badge badge-severity-high text-white">High</span></td><td>Persons injured or at serious risk</td></tr>
              <tr><td><span class="badge badge-severity-critical text-white">Critical</span></td><td>Life-threatening, mass casualties</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Submit -->
      <div class="card">
        <div class="card-body">
          <div class="d-grid gap-2">
            <button type="submit" class="btn btn-primary btn-lg">
              <i class="bi bi-send-fill me-2"></i>Submit Report
            </button>
            <a href="<?= APP_URL ?>/modules/incidents/index.php" class="btn btn-outline-secondary">
              Cancel
            </a>
          </div>
          <p class="text-muted mt-2 mb-0" style="font-size:.75rem">
            <i class="bi bi-info-circle me-1"></i>
            A reference number will be generated automatically. You can track the status of your report from the Incident Reports page.
          </p>
        </div>
      </div>

    </div>
  </div>
</form>

<?php include APP_ROOT . '/includes/footer.php'; ?>

<script>
// GPS location button
document.getElementById('getLocationBtn')?.addEventListener('click', function () {
    const status = document.getElementById('locationStatus');
    if (!navigator.geolocation) {
        status.textContent = 'Geolocation not supported by this browser.';
        return;
    }
    status.textContent = 'Detecting location…';
    this.disabled = true;

    navigator.geolocation.getCurrentPosition(
        pos => {
            document.getElementById('latitude').value  = pos.coords.latitude.toFixed(7);
            document.getElementById('longitude').value = pos.coords.longitude.toFixed(7);
            status.textContent = '✓ Location captured';
            this.disabled = false;
        },
        err => {
            status.textContent = 'Could not get location: ' + err.message;
            this.disabled = false;
        },
        { timeout: 10000 }
    );
});
</script>
