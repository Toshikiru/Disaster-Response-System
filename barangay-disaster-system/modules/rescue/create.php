<?php
/**
 * Create Rescue Request / SOS Form
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

$page_title  = 'Request Rescue / SOS';
$active_page = 'rescue';

$incident_id = (int)($_GET['incident_id'] ?? 0);
$errors = [];
$old    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $old = $_POST;

    $location    = trim($_POST['location_address'] ?? '');
    $landmark    = trim($_POST['landmark']         ?? '');
    $priority    = trim($_POST['priority']         ?? 'high');
    $persons     = max(1, (int)($_POST['number_of_persons'] ?? 1));
    $is_medical  = isset($_POST['is_medical_emergency']) ? 1 : 0;
    $is_trapped  = isset($_POST['has_trapped_persons'])  ? 1 : 0;
    $description = trim($_POST['description']      ?? '');
    $latitude    = trim($_POST['latitude']         ?? '');
    $longitude   = trim($_POST['longitude']        ?? '');
    $inc_link    = (int)($_POST['incident_id']     ?? 0);

    if (empty($location)) $errors[] = 'Location address is required.';
    if (!in_array($priority, ['low','medium','high','critical','sos'])) $errors[] = 'Invalid priority level.';

    if (empty($errors)) {
        try {
            $ref = generate_reference(REF_RESCUE);

            db()->prepare(
                "INSERT INTO rescue_requests
                 (reference_number, requestor_user_id, incident_id, priority, status,
                  location_address, latitude, longitude, landmark,
                  number_of_persons, is_medical_emergency, has_trapped_persons, description)
                 VALUES (?,?,?,?,'pending',?,?,?,?,?,?,?,?)"
            )->execute([
                $ref,
                current_user_id(),
                $inc_link ?: null,
                $priority,
                $location,
                $latitude !== '' ? $latitude : null,
                $longitude !== '' ? $longitude : null,
                $landmark ?: null,
                $persons,
                $is_medical,
                $is_trapped,
                $description ?: null,
            ]);

            $rescue_id = (int)db()->lastInsertId();

            // Notify all officials and responders
            $notif_users = db()->query(
                "SELECT u.id FROM users u WHERE u.role_id IN (2,3,4)"
            )->fetchAll();

            $urgency = in_array($priority, ['sos','critical']) ? '🚨 URGENT — ' : '';
            foreach ($notif_users as $nu) {
                send_notification(
                    $nu['id'],
                    'rescue_status',
                    $urgency . 'New Rescue Request: ' . $ref,
                    'Priority: ' . strtoupper($priority) . ' · Location: ' . $location,
                    APP_URL . '/modules/rescue/view.php?id=' . $rescue_id
                );
            }

            log_activity('create_rescue', 'rescue', $rescue_id, 'Created rescue request ' . $ref);
            flash('success', 'Rescue request submitted. Reference: ' . $ref . '. Help is on the way!');
            header('Location: ' . APP_URL . '/modules/rescue/view.php?id=' . $rescue_id);
            exit;

        } catch (PDOException $e) {
            error_log($e->getMessage());
            $errors[] = 'System error. Please try again or call the emergency hotline.';
        }
    }
}

// Pre-fill location from resident profile
$resident_address = '';
if (is_resident()) {
    try {
        $res = db()->prepare("SELECT street_address, purok_sitio FROM residents WHERE user_id = ?");
        $res->execute([current_user_id()]);
        $res = $res->fetch();
        if ($res) {
            $resident_address = trim(($res['purok_sitio'] ? $res['purok_sitio'] . ', ' : '') . $res['street_address']);
        }
    } catch (PDOException $e) {}
}

// Fetch system hotlines for display
$hotlines = [];
try {
    $rows = db()->query(
        "SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE '%hotline%' AND setting_value != ''"
    )->fetchAll();
    foreach ($rows as $r) $hotlines[$r['setting_key']] = $r['setting_value'];
} catch (PDOException $e) {}

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
  <a href="<?= APP_URL ?>/modules/rescue/index.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left"></i>
  </a>
  <div>
    <h4 class="fw-bold mb-0 text-danger"><i class="bi bi-exclamation-circle-fill me-2"></i>Request Rescue / SOS</h4>
    <p class="text-muted mb-0" style="font-size:.82rem">Fill in all details as accurately as possible.</p>
  </div>
</div>

<!-- Emergency notice banner -->
<div class="alert alert-danger d-flex align-items-center gap-2 mb-4" role="alert">
  <i class="bi bi-telephone-fill flex-shrink-0 fs-5"></i>
  <div>
    <strong>Life-threatening emergency?</strong> Call the emergency hotline immediately while submitting this form.
    <?php if (!empty($hotlines['emergency_hotline_1'])): ?>
      <strong class="ms-2"><?= htmlspecialchars($hotlines['emergency_hotline_1']) ?></strong>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
  <strong><i class="bi bi-exclamation-triangle-fill me-1"></i>Please fix these errors:</strong>
  <ul class="mb-0 mt-1">
    <?php foreach ($errors as $e): ?>
      <li><?= htmlspecialchars($e) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<form method="POST" action="" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="incident_id" value="<?= $incident_id ?: (int)($old['incident_id'] ?? 0) ?>">

  <div class="row g-4">
    <div class="col-lg-8">

      <!-- Priority -->
      <div class="card mb-4 border-danger">
        <div class="card-header bg-danger text-white fw-bold">
          <i class="bi bi-exclamation-triangle-fill me-2"></i>Emergency Priority Level
        </div>
        <div class="card-body">
          <div class="row g-2">
            <?php
            $priorities = [
              'sos'      => ['🆘 SOS',         'Extreme danger / life at risk',   'danger'],
              'critical' => ['🔴 Critical',     'Serious injury / trapped',        'danger'],
              'high'     => ['🟠 High',         'Urgent situation, needs help now', 'warning'],
              'medium'   => ['🟡 Medium',       'Moderate urgency',               'info'],
              'low'      => ['🟢 Low',          'Non-urgent assistance',           'success'],
            ];
            $default_pri = $old['priority'] ?? 'high';
            foreach ($priorities as $pval => [$plabel, $pdesc, $pcls]): ?>
            <div class="col-6 col-md-4">
              <input type="radio" class="btn-check" name="priority" id="pri_<?= $pval ?>"
                     value="<?= $pval ?>" <?= $default_pri === $pval ? 'checked' : '' ?>>
              <label class="btn btn-outline-<?= $pcls ?> w-100 text-start py-2" for="pri_<?= $pval ?>">
                <span class="fw-bold d-block"><?= $plabel ?></span>
                <span style="font-size:.72rem"><?= $pdesc ?></span>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Location -->
      <div class="card mb-4">
        <div class="card-header"><i class="bi bi-geo-alt me-2"></i>Your Location <span class="text-danger">*</span></div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label" for="location_address">Street / Purok / Area <span class="text-danger">*</span></label>
            <input type="text" class="form-control form-control-lg" id="location_address" name="location_address"
                   placeholder="Where are you? Be as specific as possible."
                   value="<?= htmlspecialchars($old['location_address'] ?? $resident_address) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label" for="landmark">Nearest Landmark</label>
            <input type="text" class="form-control" id="landmark" name="landmark"
                   placeholder="Near the water tank, beside the chapel, behind the school…"
                   value="<?= htmlspecialchars($old['landmark'] ?? '') ?>">
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="latitude">GPS Latitude</label>
              <input type="number" class="form-control" id="latitude" name="latitude"
                     step="any" placeholder="Auto-detect or type" value="<?= htmlspecialchars($old['latitude'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="longitude">GPS Longitude</label>
              <input type="number" class="form-control" id="longitude" name="longitude"
                     step="any" placeholder="Auto-detect or type" value="<?= htmlspecialchars($old['longitude'] ?? '') ?>">
            </div>
          </div>
          <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="getLocationBtn">
            <i class="bi bi-crosshair me-1"></i>Auto-detect My Location
          </button>
          <small class="text-muted ms-2" id="locationStatus"></small>
        </div>
      </div>

      <!-- Situation details -->
      <div class="card mb-4">
        <div class="card-header"><i class="bi bi-card-text me-2"></i>Situation Details</div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label" for="number_of_persons">Number of Persons Needing Rescue <span class="text-danger">*</span></label>
            <input type="number" class="form-control" id="number_of_persons" name="number_of_persons"
                   min="1" max="999" value="<?= (int)($old['number_of_persons'] ?? 1) ?>" required>
          </div>

          <div class="mb-3">
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" id="is_medical_emergency"
                     name="is_medical_emergency" value="1"
                     <?= isset($old['is_medical_emergency']) ? 'checked' : '' ?>>
              <label class="form-check-label fw-semibold text-danger" for="is_medical_emergency">
                <i class="bi bi-hospital me-1"></i>Medical Emergency (injuries / unconscious)
              </label>
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="has_trapped_persons"
                     name="has_trapped_persons" value="1"
                     <?= isset($old['has_trapped_persons']) ? 'checked' : '' ?>>
              <label class="form-check-label fw-semibold" for="has_trapped_persons">
                <i class="bi bi-person-fill-exclamation me-1"></i>Persons Trapped / Cannot Move
              </label>
            </div>
          </div>

          <div>
            <label class="form-label" for="description">Additional Information</label>
            <textarea class="form-control" id="description" name="description" rows="3"
                      placeholder="Describe your situation: what happened, conditions around you, any special needs…"
                      maxlength="2000"><?= htmlspecialchars($old['description'] ?? '') ?></textarea>
          </div>
        </div>
      </div>

    </div>

    <!-- Right column -->
    <div class="col-lg-4">

      <!-- Emergency hotlines box -->
      <div class="card mb-4 border-danger">
        <div class="card-header bg-danger text-white"><i class="bi bi-telephone-fill me-2"></i>Emergency Hotlines</div>
        <div class="card-body p-3">
          <?php
          $hotline_labels = [
            'emergency_hotline_1' => 'Emergency',
            'emergency_hotline_2' => 'Emergency (2)',
            'mdrrmo_hotline'      => 'MDRRMO',
            'bfp_hotline'         => 'Fire (BFP)',
            'pnp_hotline'         => 'Police (PNP)',
            'hospital_hotline'    => 'Hospital',
          ];
          foreach ($hotline_labels as $key => $label):
            if (!empty($hotlines[$key])): ?>
          <div class="d-flex align-items-center gap-2 mb-2">
            <i class="bi bi-telephone-fill text-danger"></i>
            <div>
              <div style="font-size:.72rem;color:var(--bdrs-text-muted)"><?= $label ?></div>
              <a href="tel:<?= htmlspecialchars($hotlines[$key]) ?>" class="fw-bold text-danger text-decoration-none">
                <?= htmlspecialchars($hotlines[$key]) ?>
              </a>
            </div>
          </div>
          <?php endif; endforeach; ?>
        </div>
      </div>

      <!-- Submit card -->
      <div class="card border-danger">
        <div class="card-body">
          <div class="d-grid gap-2">
            <button type="submit" class="btn btn-sos btn-lg">
              <i class="bi bi-send-fill me-2"></i>Submit Rescue Request
            </button>
            <a href="<?= APP_URL ?>/modules/rescue/index.php" class="btn btn-outline-secondary">
              Cancel
            </a>
          </div>
          <p class="text-muted mt-3 mb-0" style="font-size:.73rem">
            <i class="bi bi-info-circle me-1"></i>
            Submitting this form will immediately alert barangay responders. Keep your phone line open.
          </p>
        </div>
      </div>

    </div>
  </div>
</form>

<?php include APP_ROOT . '/includes/footer.php'; ?>

<script>
document.getElementById('getLocationBtn')?.addEventListener('click', function () {
    const status = document.getElementById('locationStatus');
    if (!navigator.geolocation) { status.textContent = 'GPS not supported.'; return; }
    status.textContent = 'Detecting…';
    this.disabled = true;
    navigator.geolocation.getCurrentPosition(
        pos => {
            document.getElementById('latitude').value  = pos.coords.latitude.toFixed(7);
            document.getElementById('longitude').value = pos.coords.longitude.toFixed(7);
            status.textContent = '✓ Location detected';
            this.disabled = false;
        },
        err => { status.textContent = 'Error: ' + err.message; this.disabled = false; },
        { timeout: 10000 }
    );
});
</script>
