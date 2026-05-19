<?php
/**
 * Missing Person — Detail View & Status Update
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();

$page_title  = 'Missing Person Details';
$active_page = 'missing';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    flash('danger', 'Invalid record ID.');
    header('Location: ' . APP_URL . '/modules/missing/index.php');
    exit;
}

try {
    $stmt = db()->prepare(
        "SELECT mp.*,
                u.username AS reporter_username,
                CONCAT(COALESCE(r.first_name,''),' ',COALESCE(r.last_name,'')) AS reporter_name,
                r.contact_number AS reporter_contact,
                i.reference_number AS incident_ref, i.title AS incident_title
         FROM missing_persons mp
         JOIN users u ON u.id = mp.reporter_user_id
         LEFT JOIN residents r ON r.user_id = mp.reporter_user_id
         LEFT JOIN incidents i ON i.id = mp.incident_id
         WHERE mp.id = ?"
    );
    $stmt->execute([$id]);
    $person = $stmt->fetch();

    if (!$person) {
        flash('danger', 'Record not found.');
        header('Location: ' . APP_URL . '/modules/missing/index.php');
        exit;
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    flash('danger', 'Error loading record.');
    header('Location: ' . APP_URL . '/modules/missing/index.php');
    exit;
}

// Handle status update — officials only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_official()) {
    verify_csrf();

    $new_status  = $_POST['new_status']  ?? '';
    $found_notes = trim($_POST['found_notes'] ?? '');
    $allowed     = ['missing','found_safe','found_injured','deceased','unknown'];

    if (in_array($new_status, $allowed)) {
        try {
            db()->prepare(
                "UPDATE missing_persons
                 SET status = ?, found_notes = ?,
                     found_at = IF(? != 'missing' AND found_at IS NULL, NOW(), found_at),
                     updated_at = NOW()
                 WHERE id = ?"
            )->execute([$new_status, $found_notes ?: null, $new_status, $id]);

            // Notify reporter
            send_notification(
                $person['reporter_user_id'],
                'missing_update',
                'Missing Person Status Updated: ' . $person['reference_number'],
                $person['full_name'] . ' — Status: ' . ucwords(str_replace('_', ' ', $new_status)),
                APP_URL . '/modules/missing/view.php?id=' . $id
            );

            log_activity('update_missing_status', 'missing', $id,
                $person['full_name'] . ' status → ' . $new_status);

            flash('success', 'Status updated to: ' . ucwords(str_replace('_', ' ', $new_status)));

        } catch (PDOException $e) {
            error_log($e->getMessage());
            flash('danger', 'Error updating status.');
        }
    }

    header('Location: ' . APP_URL . '/modules/missing/view.php?id=' . $id);
    exit;
}

$status_config = [
    'missing'       => ['Missing',        'danger'],
    'found_safe'    => ['Found Safe',     'success'],
    'found_injured' => ['Found Injured',  'warning'],
    'deceased'      => ['Deceased',       'dark'],
    'unknown'       => ['Unknown',        'secondary'],
];

[$st_label, $st_cls] = $status_config[$person['status']] ?? ['Unknown', 'secondary'];

include APP_ROOT . '/includes/header.php';
?>

<!-- Header -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
  <div class="d-flex align-items-center gap-2">
    <a href="<?= APP_URL ?>/modules/missing/index.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left"></i>
    </a>
    <div>
      <h5 class="fw-bold mb-0"><?= htmlspecialchars($person['full_name']) ?></h5>
      <span class="text-muted" style="font-size:.8rem"><?= htmlspecialchars($person['reference_number']) ?></span>
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if (is_official()): ?>
    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#statusModal">
      <i class="bi bi-arrow-repeat me-1"></i>Update Status
    </button>
    <a href="<?= APP_URL ?>/modules/missing/edit.php?id=<?= $id ?>"
       class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-pencil me-1"></i>Edit
    </a>
    <?php endif; ?>
    <button class="btn btn-sm btn-outline-dark" onclick="printPage()">
      <i class="bi bi-printer me-1"></i>Print
    </button>
  </div>
</div>

<div class="row g-4">

  <!-- Left: details -->
  <div class="col-lg-8">
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-bold"><i class="bi bi-person-fill-exclamation me-2"></i>Person Information</span>
        <span class="badge bg-<?= $st_cls ?> fs-7"><?= $st_label ?></span>
      </div>
      <div class="card-body">
        <div class="row g-3">

          <!-- Photo -->
          <div class="col-md-3 text-center">
            <?php if ($person['photo_path']): ?>
              <img src="<?= APP_URL . '/' . htmlspecialchars($person['photo_path']) ?>"
                   alt="<?= htmlspecialchars($person['full_name']) ?>"
                   class="img-fluid rounded"
                   style="max-height:180px;object-fit:cover;border:2px solid var(--bdrs-border)">
            <?php else: ?>
              <div style="width:120px;height:140px;background:var(--bdrs-blue-light);border-radius:.5rem;display:flex;align-items:center;justify-content:center;font-size:3rem;color:var(--bdrs-blue);margin:0 auto">
                <i class="bi bi-person-fill"></i>
              </div>
              <div class="text-muted mt-1" style="font-size:.75rem">No photo</div>
            <?php endif; ?>
          </div>

          <!-- Info -->
          <div class="col-md-9">
            <div class="row g-2" style="font-size:.88rem">
              <div class="col-md-6">
                <div class="text-muted fs-8 text-uppercase fw-bold mb-1">Full Name</div>
                <div class="fw-semibold"><?= htmlspecialchars($person['full_name']) ?></div>
              </div>
              <div class="col-md-3">
                <div class="text-muted fs-8 text-uppercase fw-bold mb-1">Age</div>
                <div class="fw-semibold"><?= $person['age'] ? $person['age'] . ' yrs' : '—' ?></div>
              </div>
              <div class="col-md-3">
                <div class="text-muted fs-8 text-uppercase fw-bold mb-1">Gender</div>
                <div class="fw-semibold"><?= ucfirst($person['gender']) ?></div>
              </div>
              <div class="col-md-8">
                <div class="text-muted fs-8 text-uppercase fw-bold mb-1">Last Seen Location</div>
                <div><i class="bi bi-geo-alt text-danger me-1"></i><?= htmlspecialchars($person['last_seen_location'] ?? '—') ?></div>
              </div>
              <div class="col-md-4">
                <div class="text-muted fs-8 text-uppercase fw-bold mb-1">Last Seen At</div>
                <div><?= $person['last_seen_at'] ? date('M d, Y h:i A', strtotime($person['last_seen_at'])) : '—' ?></div>
              </div>
              <?php if ($person['incident_ref']): ?>
              <div class="col-12">
                <div class="text-muted fs-8 text-uppercase fw-bold mb-1">Linked Incident</div>
                <a href="<?= APP_URL ?>/modules/incidents/view.php?id=<?= $person['incident_id'] ?>"
                   class="text-decoration-none">
                  <?= htmlspecialchars($person['incident_ref']) ?> — <?= htmlspecialchars($person['incident_title']) ?>
                </a>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Description -->
          <?php if ($person['description']): ?>
          <div class="col-12">
            <hr class="my-2">
            <div class="text-muted fs-8 text-uppercase fw-bold mb-1">Physical Description / Notes</div>
            <p class="mb-0" style="font-size:.88rem;white-space:pre-line"><?= htmlspecialchars($person['description']) ?></p>
          </div>
          <?php endif; ?>

          <!-- Found info -->
          <?php if ($person['status'] !== 'missing' && $person['found_notes']): ?>
          <div class="col-12">
            <div class="alert alert-<?= $st_cls ?> py-2 px-3 mb-0">
              <strong>Update Notes:</strong> <?= htmlspecialchars($person['found_notes']) ?>
              <?php if ($person['found_at']): ?>
                <span class="text-muted ms-2" style="font-size:.78rem">
                  · <?= date('M d, Y h:i A', strtotime($person['found_at'])) ?>
                </span>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>

        </div>
      </div>
    </div>
  </div>

  <!-- Right: reporter + dates -->
  <div class="col-lg-4">

    <div class="card mb-4">
      <div class="card-header fw-bold"><i class="bi bi-person me-2"></i>Reported By</div>
      <div class="card-body" style="font-size:.85rem">
        <div class="mb-1">
          <strong><?= htmlspecialchars($person['reporter_name'] ?: $person['reporter_username']) ?></strong>
        </div>
        <?php if ($person['reporter_contact']): ?>
        <div class="text-muted"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($person['reporter_contact']) ?></div>
        <?php endif; ?>
        <div class="text-muted mt-2">
          <i class="bi bi-calendar me-1"></i>
          Filed: <?= date('M d, Y h:i A', strtotime($person['created_at'])) ?>
        </div>
        <?php if ($person['updated_at'] !== $person['created_at']): ?>
        <div class="text-muted">
          <i class="bi bi-arrow-repeat me-1"></i>
          Updated: <?= date('M d, Y h:i A', strtotime($person['updated_at'])) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-header fw-bold"><i class="bi bi-info-circle me-2"></i>Record Status</div>
      <div class="card-body text-center">
        <span class="badge bg-<?= $st_cls ?> px-3 py-2 fs-7 d-block mb-2"><?= $st_label ?></span>
        <div class="text-muted" style="font-size:.78rem">
          Ref: <strong><?= htmlspecialchars($person['reference_number']) ?></strong>
        </div>
        <?php if ($person['status'] === 'missing'): ?>
        <div class="alert alert-danger py-2 px-2 mt-2 mb-0" style="font-size:.78rem">
          <i class="bi bi-exclamation-circle me-1"></i>
          This person is still reported as missing.
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (is_official()): ?>
    <div class="card">
      <div class="card-body d-grid gap-2">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#statusModal">
          <i class="bi bi-arrow-repeat me-1"></i>Update Status
        </button>
        <a href="<?= APP_URL ?>/modules/missing/edit.php?id=<?= $id ?>"
           class="btn btn-outline-secondary">
          <i class="bi bi-pencil me-1"></i>Edit Record
        </a>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- Status Update Modal -->
<?php if (is_official()): ?>
<div class="modal fade" id="statusModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrf_field() ?>
        <div class="modal-header">
          <h5 class="modal-title fw-bold">Update Status</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">New Status <span class="text-danger">*</span></label>
            <select name="new_status" class="form-select" required>
              <?php foreach ($status_config as $sval => [$slabel, $scls]): ?>
                <option value="<?= $sval ?>" <?= $person['status'] === $sval ? 'selected' : '' ?>>
                  <?= $slabel ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="form-label fw-semibold">Notes / Update Details</label>
            <textarea name="found_notes" class="form-control" rows="3"
                      placeholder="Where found, condition, who found them…"
                      maxlength="1000"><?= htmlspecialchars($person['found_notes'] ?? '') ?></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Update</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include APP_ROOT . '/includes/footer.php'; ?>
