<?php
/**
 * Rescue Request — Detail View & Dispatch Management
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();

$page_title  = 'Rescue Request Details';
$active_page = 'rescue';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    flash('danger', 'Invalid ID.');
    header('Location: ' . APP_URL . '/modules/rescue/index.php');
    exit;
}

try {
    $stmt = db()->prepare(
        "SELECT rr.*,
                CONCAT(COALESCE(res.first_name,''),' ',COALESCE(res.last_name,'')) AS requestor_name,
                res.contact_number AS requestor_contact,
                res.street_address AS requestor_address,
                u.username AS requestor_username,
                CONCAT(COALESCE(rsp.first_name,''),' ',COALESCE(rsp.last_name,'')) AS responder_name,
                rsp.contact_number AS responder_contact,
                rsp.responder_type, rsp.unit_name,
                i.reference_number AS incident_ref, i.title AS incident_title
         FROM rescue_requests rr
         LEFT JOIN residents  res ON res.user_id = rr.requestor_user_id
         LEFT JOIN users      u   ON u.id        = rr.requestor_user_id
         LEFT JOIN responders rsp ON rsp.id       = rr.assigned_responder_id
         LEFT JOIN incidents  i   ON i.id         = rr.incident_id
         WHERE rr.id = ?"
    );
    $stmt->execute([$id]);
    $rescue = $stmt->fetch();

    if (!$rescue) {
        flash('danger', 'Rescue request not found.');
        header('Location: ' . APP_URL . '/modules/rescue/index.php');
        exit;
    }

    // Residents can only view their own requests
    if (is_resident() && $rescue['requestor_user_id'] !== current_user_id()) {
        http_response_code(403);
        include APP_ROOT . '/includes/403.php';
        exit;
    }

    // Available responders for assignment
    $available_responders = [];
    if (is_official()) {
        $available_responders = db()->query(
            "SELECT id, CONCAT(first_name,' ',last_name) AS full_name,
                    responder_type, unit_name, contact_number
             FROM responders WHERE status = 'available' ORDER BY first_name"
        )->fetchAll();
    }

} catch (PDOException $e) {
    error_log($e->getMessage());
    flash('danger', 'Error loading rescue request.');
    header('Location: ' . APP_URL . '/modules/rescue/index.php');
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_official()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_status') {
            $new_status = $_POST['new_status'] ?? '';
            $allowed    = ['pending','dispatched','en_route','arrived','completed','cancelled'];

            if (in_array($new_status, $allowed)) {
                $updates = ['status = ?', 'updated_at = NOW()'];
                $params  = [$new_status];

                if ($new_status === 'dispatched') { $updates[] = 'dispatched_at = NOW()'; }
                if ($new_status === 'arrived')    { $updates[] = 'arrived_at    = NOW()'; }
                if ($new_status === 'completed')  { $updates[] = 'completed_at  = NOW()'; }

                $params[] = $id;
                db()->prepare(
                    "UPDATE rescue_requests SET " . implode(', ', $updates) . " WHERE id = ?"
                )->execute($params);

                // Notify requestor
                send_notification(
                    $rescue['requestor_user_id'],
                    'rescue_status',
                    'Rescue Update: ' . $rescue['reference_number'],
                    'Your rescue request status is now: ' . ucwords(str_replace('_', ' ', $new_status)),
                    APP_URL . '/modules/rescue/view.php?id=' . $id
                );

                log_activity('update_rescue_status', 'rescue', $id, "Status → $new_status");
                flash('success', 'Status updated to: ' . ucwords(str_replace('_', ' ', $new_status)));
            }

        } elseif ($action === 'assign_responder') {
            $responder_id = (int)$_POST['responder_id'];
            if ($responder_id) {
                db()->prepare(
                    "UPDATE rescue_requests SET assigned_responder_id = ?, status = 'dispatched',
                     dispatched_at = IFNULL(dispatched_at, NOW()), updated_at = NOW()
                     WHERE id = ?"
                )->execute([$responder_id, $id]);

                db()->prepare(
                    "UPDATE responders SET status = 'on_duty' WHERE id = ?"
                )->execute([$responder_id]);

                send_notification(
                    $rescue['requestor_user_id'],
                    'rescue_status',
                    'Responder Dispatched: ' . $rescue['reference_number'],
                    'A responder has been dispatched to your location.',
                    APP_URL . '/modules/rescue/view.php?id=' . $id
                );

                log_activity('assign_rescue_responder', 'rescue', $id, "Assigned responder #$responder_id");
                flash('success', 'Responder assigned and dispatched.');
            }
        }

    } catch (PDOException $e) {
        error_log($e->getMessage());
        flash('danger', 'Error processing action.');
    }

    header('Location: ' . APP_URL . '/modules/rescue/view.php?id=' . $id);
    exit;
}

$priority_config = [
    'sos'      => ['SOS',      'badge-severity-critical'],
    'critical' => ['Critical', 'badge-severity-critical'],
    'high'     => ['High',     'badge-severity-high'],
    'medium'   => ['Medium',   'badge-severity-moderate'],
    'low'      => ['Low',      'badge-severity-low'],
];

$status_config = [
    'pending'    => ['Pending',    'secondary'],
    'dispatched' => ['Dispatched', 'primary'],
    'en_route'   => ['En Route',   'warning'],
    'arrived'    => ['Arrived',    'info'],
    'completed'  => ['Completed',  'success'],
    'cancelled'  => ['Cancelled',  'dark'],
];

[$pri_label, $pri_cls] = $priority_config[$rescue['priority']] ?? ['—', 'bg-secondary'];
[$st_label,  $st_cls]  = $status_config[$rescue['status']]     ?? ['—', 'secondary'];

include APP_ROOT . '/includes/header.php';
?>

<!-- Header -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
  <div class="d-flex align-items-center gap-2">
    <a href="<?= APP_URL ?>/modules/rescue/index.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left"></i>
    </a>
    <div>
      <h5 class="fw-bold mb-0">Rescue Request: <?= htmlspecialchars($rescue['reference_number']) ?></h5>
      <div class="d-flex gap-2 mt-1">
        <span class="badge <?= $pri_cls ?> text-white"><?= $pri_label ?></span>
        <span class="badge bg-<?= $st_cls ?> text-white"><?= $st_label ?></span>
        <?php if ($rescue['is_medical_emergency']): ?>
          <span class="badge bg-danger">MEDICAL EMERGENCY</span>
        <?php endif; ?>
        <?php if ($rescue['has_trapped_persons']): ?>
          <span class="badge bg-warning text-dark">TRAPPED PERSONS</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php if (is_official()): ?>
  <div class="d-flex gap-2">
    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#statusModal">
      <i class="bi bi-arrow-repeat me-1"></i>Update Status
    </button>
    <?php if (!$rescue['assigned_responder_id']): ?>
    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#assignModal">
      <i class="bi bi-person-plus me-1"></i>Dispatch Responder
    </button>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="row g-4">

  <!-- Left: Main details -->
  <div class="col-lg-8">

    <div class="card mb-4">
      <div class="card-header fw-bold"><i class="bi bi-geo-alt me-2"></i>Location & Situation</div>
      <div class="card-body">
        <div class="row g-3" style="font-size:.88rem">
          <div class="col-12">
            <div class="text-muted fs-8 text-uppercase fw-bold mb-1">Location Address</div>
            <div class="fw-semibold fs-6"><i class="bi bi-geo-alt-fill text-danger me-1"></i><?= htmlspecialchars($rescue['location_address']) ?></div>
            <?php if ($rescue['landmark']): ?>
              <div class="text-muted">📍 <?= htmlspecialchars($rescue['landmark']) ?></div>
            <?php endif; ?>
          </div>
          <div class="col-md-4">
            <div class="text-muted fs-8 text-uppercase fw-bold mb-1">Persons Needing Help</div>
            <div class="fw-bold fs-4 text-danger"><?= $rescue['number_of_persons'] ?></div>
          </div>
          <div class="col-md-4">
            <div class="text-muted fs-8 text-uppercase fw-bold mb-1">Medical Emergency</div>
            <div><?= $rescue['is_medical_emergency']
              ? '<span class="badge bg-danger px-2 py-1">YES — Medical needed</span>'
              : '<span class="text-muted">No</span>' ?></div>
          </div>
          <div class="col-md-4">
            <div class="text-muted fs-8 text-uppercase fw-bold mb-1">Persons Trapped</div>
            <div><?= $rescue['has_trapped_persons']
              ? '<span class="badge bg-warning text-dark px-2 py-1">YES — Trapped</span>'
              : '<span class="text-muted">No</span>' ?></div>
          </div>
          <?php if ($rescue['description']): ?>
          <div class="col-12">
            <div class="text-muted fs-8 text-uppercase fw-bold mb-1">Situation Description</div>
            <p class="mb-0" style="white-space:pre-line"><?= htmlspecialchars($rescue['description']) ?></p>
          </div>
          <?php endif; ?>
          <?php if ($rescue['incident_ref']): ?>
          <div class="col-12">
            <div class="text-muted fs-8 text-uppercase fw-bold mb-1">Linked Incident</div>
            <a href="<?= APP_URL ?>/modules/incidents/view.php?id=<?= $rescue['incident_id'] ?>" class="text-decoration-none">
              <?= htmlspecialchars($rescue['incident_ref']) ?> — <?= htmlspecialchars($rescue['incident_title']) ?>
            </a>
          </div>
          <?php endif; ?>
          <?php if ($rescue['latitude'] && $rescue['longitude']): ?>
          <div class="col-12">
            <a href="https://maps.google.com/?q=<?= $rescue['latitude'] ?>,<?= $rescue['longitude'] ?>"
               target="_blank" class="btn btn-sm btn-outline-primary">
              <i class="bi bi-map me-1"></i>Open on Google Maps
            </a>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Timeline -->
    <div class="card">
      <div class="card-header fw-bold"><i class="bi bi-clock-history me-2"></i>Response Timeline</div>
      <div class="card-body">
        <?php
        $timeline = [
          ['Requested',  $rescue['created_at'],    'primary'],
          ['Dispatched', $rescue['dispatched_at'],  'warning'],
          ['Arrived',    $rescue['arrived_at'],     'info'],
          ['Completed',  $rescue['completed_at'],   'success'],
        ];
        foreach ($timeline as [$label, $ts, $cls]):
        ?>
        <div class="d-flex align-items-center gap-3 mb-2">
          <div style="width:10px;height:10px;border-radius:50%;flex-shrink:0;background:<?= $ts ? "var(--bdrs-$cls)" : 'var(--bdrs-border)' ?>"></div>
          <div style="font-size:.85rem">
            <span class="fw-semibold"><?= $label ?>:</span>
            <?php if ($ts): ?>
              <span class="text-muted ms-1"><?= date('M d, Y h:i A', strtotime($ts)) ?></span>
            <?php else: ?>
              <span class="text-muted ms-1">—</span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>

  <!-- Right: Requestor & Responder -->
  <div class="col-lg-4">

    <!-- Requestor -->
    <div class="card mb-4">
      <div class="card-header fw-bold"><i class="bi bi-person me-2"></i>Requestor</div>
      <div class="card-body" style="font-size:.85rem">
        <div class="fw-semibold mb-1"><?= htmlspecialchars($rescue['requestor_name'] ?: $rescue['requestor_username']) ?></div>
        <?php if ($rescue['requestor_contact']): ?>
        <div><i class="bi bi-telephone me-1 text-success"></i>
          <a href="tel:<?= htmlspecialchars($rescue['requestor_contact']) ?>" class="text-decoration-none fw-bold text-success">
            <?= htmlspecialchars($rescue['requestor_contact']) ?>
          </a>
        </div>
        <?php endif; ?>
        <?php if ($rescue['requestor_address']): ?>
        <div class="text-muted mt-1"><i class="bi bi-house me-1"></i><?= htmlspecialchars($rescue['requestor_address']) ?></div>
        <?php endif; ?>
        <div class="text-muted mt-1"><i class="bi bi-calendar me-1"></i><?= date('M d, Y h:i A', strtotime($rescue['created_at'])) ?></div>
      </div>
    </div>

    <!-- Assigned Responder -->
    <div class="card mb-4">
      <div class="card-header fw-bold"><i class="bi bi-person-badge me-2"></i>Assigned Responder</div>
      <div class="card-body" style="font-size:.85rem">
        <?php if ($rescue['responder_name'] && trim($rescue['responder_name'])): ?>
          <div class="fw-semibold mb-1"><?= htmlspecialchars($rescue['responder_name']) ?></div>
          <div class="text-muted"><?= ucwords(str_replace('_',' ',$rescue['responder_type'])) ?>
            <?= $rescue['unit_name'] ? ' · ' . htmlspecialchars($rescue['unit_name']) : '' ?>
          </div>
          <?php if ($rescue['responder_contact']): ?>
          <div class="mt-1"><i class="bi bi-telephone me-1"></i>
            <a href="tel:<?= htmlspecialchars($rescue['responder_contact']) ?>" class="text-decoration-none fw-bold text-success">
              <?= htmlspecialchars($rescue['responder_contact']) ?>
            </a>
          </div>
          <?php endif; ?>
        <?php else: ?>
          <div class="text-muted text-center py-2">
            <i class="bi bi-person-dash fs-4 d-block mb-1"></i>No responder assigned yet.
          </div>
          <?php if (is_official()): ?>
          <button class="btn btn-success btn-sm w-100 mt-2"
                  data-bs-toggle="modal" data-bs-target="#assignModal">
            <i class="bi bi-person-plus me-1"></i>Assign Responder
          </button>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Quick status -->
    <div class="card">
      <div class="card-header fw-bold">Current Status</div>
      <div class="card-body text-center">
        <span class="badge bg-<?= $st_cls ?> px-3 py-2 d-block mb-2" style="font-size:.9rem"><?= $st_label ?></span>
        <?php if (is_official() && !in_array($rescue['status'], ['completed','cancelled'])): ?>
        <button class="btn btn-sm btn-outline-primary w-100"
                data-bs-toggle="modal" data-bs-target="#statusModal">
          <i class="bi bi-arrow-repeat me-1"></i>Update Status
        </button>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<!-- Status Modal -->
<?php if (is_official()): ?>
<div class="modal fade" id="statusModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_status">
        <div class="modal-header">
          <h6 class="modal-title fw-bold">Update Rescue Status</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <select name="new_status" class="form-select" required>
            <?php foreach ($status_config as $sv => [$sl,]): ?>
              <option value="<?= $sv ?>" <?= $rescue['status'] === $sv ? 'selected':'' ?>><?= $sl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm">Update</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Assign Responder Modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign_responder">
        <div class="modal-header">
          <h5 class="modal-title fw-bold">Dispatch Responder</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php if (empty($available_responders)): ?>
            <div class="alert alert-warning mb-0">No available responders at this time.</div>
          <?php else: ?>
          <label class="form-label fw-semibold">Select Responder <span class="text-danger">*</span></label>
          <select name="responder_id" class="form-select" required>
            <option value="">— Select responder to dispatch —</option>
            <?php foreach ($available_responders as $r): ?>
              <option value="<?= $r['id'] ?>">
                <?= htmlspecialchars($r['full_name']) ?> —
                <?= ucwords(str_replace('_',' ',$r['responder_type'])) ?>
                <?= $r['unit_name'] ? '(' . htmlspecialchars($r['unit_name']) . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <?php if (!empty($available_responders)): ?>
          <button type="submit" class="btn btn-success">
            <i class="bi bi-send me-1"></i>Dispatch
          </button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include APP_ROOT . '/includes/footer.php'; ?>
