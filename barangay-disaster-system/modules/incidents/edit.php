<?php
/**
 * Edit Incident — Officials & Admin only
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role([ROLE_OFFICIAL, ROLE_ADMIN]);

$page_title  = 'Edit Incident';
$active_page = 'incidents';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    flash('danger', 'Invalid incident ID.');
    header('Location: ' . APP_URL . '/modules/incidents/index.php');
    exit;
}

try {
    $stmt = db()->prepare("SELECT * FROM incidents WHERE id = ?");
    $stmt->execute([$id]);
    $incident = $stmt->fetch();
    if (!$incident) {
        flash('danger', 'Incident not found.');
        header('Location: ' . APP_URL . '/modules/incidents/index.php');
        exit;
    }
} catch (PDOException $e) {
    flash('danger', 'Database error.');
    header('Location: ' . APP_URL . '/modules/incidents/index.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $title       = trim($_POST['title']            ?? '');
    $type        = trim($_POST['incident_type']    ?? '');
    $severity    = trim($_POST['severity']         ?? '');
    $status      = trim($_POST['status']           ?? '');
    $description = trim($_POST['description']      ?? '');
    $location    = trim($_POST['location_address'] ?? '');
    $landmark    = trim($_POST['landmark']         ?? '');
    $latitude    = trim($_POST['latitude']         ?? '');
    $longitude   = trim($_POST['longitude']        ?? '');
    $affected    = (int)($_POST['estimated_affected']   ?? 0);
    $households  = (int)($_POST['estimated_households'] ?? 0);
    $notes       = trim($_POST['notes']            ?? '');
    $reported_at = trim($_POST['reported_at']      ?? '');

    if (empty($title))    $errors[] = 'Title is required.';
    if (!array_key_exists($type, INCIDENT_TYPES)) $errors[] = 'Invalid incident type.';
    if (!in_array($severity, ['low','moderate','high','critical'])) $errors[] = 'Invalid severity.';
    if (!in_array($status, ['pending','acknowledged','ongoing','resolved','archived'])) $errors[] = 'Invalid status.';
    if (empty($location)) $errors[] = 'Location is required.';
    if (empty($reported_at)) $errors[] = 'Incident date/time is required.';

    if (empty($errors)) {
        try {
            $old_status = $incident['status'];

            db()->prepare(
                "UPDATE incidents SET
                    title = ?, incident_type = ?, severity = ?, status = ?,
                    description = ?, location_address = ?, landmark = ?,
                    latitude = ?, longitude = ?,
                    estimated_affected = ?, estimated_households = ?,
                    notes = ?, reported_at = ?,
                    acknowledged_at = IF(? = 'acknowledged' AND acknowledged_at IS NULL, NOW(), acknowledged_at),
                    resolved_at     = IF(? = 'resolved'     AND resolved_at     IS NULL, NOW(), resolved_at),
                    updated_at = NOW()
                 WHERE id = ?"
            )->execute([
                $title, $type, $severity, $status,
                $description, $location, $landmark ?: null,
                $latitude  !== '' ? $latitude  : null,
                $longitude !== '' ? $longitude : null,
                $affected   ?: null, $households ?: null,
                $notes ?: null, $reported_at,
                $status, $status, $id
            ]);

            // Log status change if it changed
            if ($old_status !== $status) {
                db()->prepare(
                    "INSERT INTO incident_status_history (incident_id, changed_by, old_status, new_status, remarks)
                     VALUES (?,?,?,?, 'Updated via edit form')"
                )->execute([$id, current_user_id(), $old_status, $status]);
            }

            log_activity('edit_incident', 'incident', $id, 'Edited incident: ' . $incident['reference_number']);
            flash('success', 'Incident updated successfully.');
            header('Location: ' . APP_URL . '/modules/incidents/view.php?id=' . $id);
            exit;

        } catch (PDOException $e) {
            error_log($e->getMessage());
            $errors[] = 'Database error. Please try again.';
        }
    }
}

$data = !empty($_POST) ? array_merge($incident, $_POST) : $incident;

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
  <a href="<?= APP_URL ?>/modules/incidents/view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left"></i>
  </a>
  <div>
    <h4 class="fw-bold mb-0">Edit Incident</h4>
    <span class="text-muted" style="font-size:.82rem"><?= htmlspecialchars($incident['reference_number']) ?></span>
  </div>
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

      <div class="card mb-4">
        <div class="card-header fw-bold"><i class="bi bi-info-circle me-2"></i>Incident Details</div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label">Title <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="title"
                   value="<?= htmlspecialchars($data['title']) ?>" maxlength="200" required>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <label class="form-label">Type <span class="text-danger">*</span></label>
              <select name="incident_type" class="form-select" required>
                <?php foreach (INCIDENT_TYPES as $val => $info): ?>
                  <option value="<?= $val ?>" <?= $data['incident_type'] === $val ? 'selected':'' ?>>
                    <?= $info['label'] ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Severity <span class="text-danger">*</span></label>
              <select name="severity" class="form-select" required>
                <option value="low"      <?= $data['severity'] === 'low'      ? 'selected':'' ?>>Low</option>
                <option value="moderate" <?= $data['severity'] === 'moderate' ? 'selected':'' ?>>Moderate</option>
                <option value="high"     <?= $data['severity'] === 'high'     ? 'selected':'' ?>>High</option>
                <option value="critical" <?= $data['severity'] === 'critical' ? 'selected':'' ?>>Critical</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Status <span class="text-danger">*</span></label>
              <select name="status" class="form-select" required>
                <option value="pending"      <?= $data['status'] === 'pending'      ? 'selected':'' ?>>Pending</option>
                <option value="acknowledged" <?= $data['status'] === 'acknowledged' ? 'selected':'' ?>>Acknowledged</option>
                <option value="ongoing"      <?= $data['status'] === 'ongoing'      ? 'selected':'' ?>>Ongoing</option>
                <option value="resolved"     <?= $data['status'] === 'resolved'     ? 'selected':'' ?>>Resolved</option>
                <option value="archived"     <?= $data['status'] === 'archived'     ? 'selected':'' ?>>Archived</option>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Description <span class="text-danger">*</span></label>
            <textarea class="form-control" name="description" rows="4" maxlength="3000" required><?= htmlspecialchars($data['description']) ?></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Incident Date/Time <span class="text-danger">*</span></label>
            <input type="datetime-local" class="form-control" name="reported_at"
                   value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime($data['reported_at']))) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Internal Notes (Officials only)</label>
            <textarea class="form-control" name="notes" rows="2" maxlength="2000"><?= htmlspecialchars($data['notes'] ?? '') ?></textarea>
          </div>
        </div>
      </div>

      <div class="card mb-4">
        <div class="card-header fw-bold"><i class="bi bi-geo-alt me-2"></i>Location</div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label">Address <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="location_address"
                   value="<?= htmlspecialchars($data['location_address']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Landmark</label>
            <input type="text" class="form-control" name="landmark"
                   value="<?= htmlspecialchars($data['landmark'] ?? '') ?>">
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Latitude</label>
              <input type="number" class="form-control" name="latitude" step="any"
                     value="<?= htmlspecialchars($data['latitude'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Longitude</label>
              <input type="number" class="form-control" name="longitude" step="any"
                     value="<?= htmlspecialchars($data['longitude'] ?? '') ?>">
            </div>
          </div>
        </div>
      </div>

    </div>

    <div class="col-lg-4">
      <div class="card mb-4">
        <div class="card-header fw-bold"><i class="bi bi-people me-2"></i>Affected</div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label">Estimated Persons Affected</label>
            <input type="number" class="form-control" name="estimated_affected"
                   min="0" value="<?= (int)($data['estimated_affected'] ?? 0) ?: '' ?>">
          </div>
          <div>
            <label class="form-label">Estimated Households Affected</label>
            <input type="number" class="form-control" name="estimated_households"
                   min="0" value="<?= (int)($data['estimated_households'] ?? 0) ?: '' ?>">
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-body d-grid gap-2">
          <button type="submit" class="btn btn-primary btn-lg">
            <i class="bi bi-save me-1"></i>Save Changes
          </button>
          <a href="<?= APP_URL ?>/modules/incidents/view.php?id=<?= $id ?>"
             class="btn btn-outline-secondary">Cancel</a>
        </div>
      </div>
    </div>
  </div>
</form>

<?php include APP_ROOT . '/includes/footer.php'; ?>
