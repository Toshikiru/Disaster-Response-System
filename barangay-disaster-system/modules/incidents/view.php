<?php
/**
 * Incident Detail View
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

$page_title  = 'Incident Details';
$active_page = 'incidents';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    flash('danger', 'Invalid incident ID.');
    header('Location: ' . APP_URL . '/modules/incidents/index.php');
    exit;
}

try {
    // Fetch incident
    $stmt = db()->prepare(
        "SELECT i.*,
                CONCAT(COALESCE(r.first_name,''), ' ', COALESCE(r.last_name,'')) AS reporter_name,
                r.contact_number AS reporter_contact,
                r.purok_sitio,
                u.username AS reporter_username
         FROM incidents i
         LEFT JOIN residents r ON r.user_id = i.reporter_user_id
         LEFT JOIN users u     ON u.id      = i.reporter_user_id
         WHERE i.id = ?"
    );
    $stmt->execute([$id]);
    $incident = $stmt->fetch();

    if (!$incident) {
        flash('danger', 'Incident not found.');
        header('Location: ' . APP_URL . '/modules/incidents/index.php');
        exit;
    }

    // Residents can only see their own incidents
    if (is_resident() && $incident['reporter_user_id'] !== current_user_id()) {
        http_response_code(403);
        include APP_ROOT . '/includes/403.php';
        exit;
    }

    // Fetch photos
    $photos = db()->prepare("SELECT * FROM incident_photos WHERE incident_id = ?");
    $photos->execute([$id]);
    $photos = $photos->fetchAll();

    // Fetch assigned responders
    $responders = db()->prepare(
        "SELECT rsp.*, ria.assigned_at, ria.task_notes,
                CONCAT(rsp.first_name,' ',rsp.last_name) AS full_name
         FROM responder_incident_assignments ria
         JOIN responders rsp ON rsp.id = ria.responder_id
         WHERE ria.incident_id = ? AND ria.released_at IS NULL"
    );
    $responders->execute([$id]);
    $responders = $responders->fetchAll();

    // Fetch status history
    $history = db()->prepare(
        "SELECT sh.*, u.username
         FROM incident_status_history sh
         JOIN users u ON u.id = sh.changed_by
         WHERE sh.incident_id = ?
         ORDER BY sh.changed_at DESC"
    );
    $history->execute([$id]);
    $history = $history->fetchAll();

    // Available responders for assignment (officials only)
    $available_responders = [];
    if (is_official()) {
        $available_responders = db()->query(
            "SELECT r.id, CONCAT(r.first_name,' ',r.last_name) AS full_name,
                    r.responder_type, r.unit_name, r.contact_number
             FROM responders r
             WHERE r.status = 'available'
             ORDER BY r.first_name"
        )->fetchAll();
    }

} catch (PDOException $e) {
    error_log($e->getMessage());
    flash('danger', 'Error loading incident data.');
    header('Location: ' . APP_URL . '/modules/incidents/index.php');
    exit;
}

// ── Handle POST actions (status update, assign responder) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_official()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_status') {
            $new_status = $_POST['new_status'] ?? '';
            $remarks    = trim($_POST['remarks'] ?? '');
            $allowed    = ['pending','acknowledged','ongoing','resolved','archived'];

            if (in_array($new_status, $allowed)) {
                $old_status = $incident['status'];

                db()->prepare(
                    "UPDATE incidents SET status = ?,
                     acknowledged_at = IF(? = 'acknowledged' AND acknowledged_at IS NULL, NOW(), acknowledged_at),
                     resolved_at     = IF(? = 'resolved',     NOW(), resolved_at),
                     updated_at      = NOW()
                     WHERE id = ?"
                )->execute([$new_status, $new_status, $new_status, $id]);

                db()->prepare(
                    "INSERT INTO incident_status_history (incident_id, changed_by, old_status, new_status, remarks)
                     VALUES (?,?,?,?,?)"
                )->execute([$id, current_user_id(), $old_status, $new_status, $remarks ?: null]);

                // Notify reporter
                send_notification(
                    $incident['reporter_user_id'],
                    'incident_update',
                    'Incident Status Updated: ' . $incident['reference_number'],
                    'Your incident has been updated to: ' . ucfirst(str_replace('_', ' ', $new_status)),
                    APP_URL . '/modules/incidents/view.php?id=' . $id
                );

                log_activity('update_status', 'incident', $id, "Status: $old_status → $new_status");
                flash('success', 'Status updated to: ' . ucfirst(str_replace('_', ' ', $new_status)));
            }

        } elseif ($action === 'assign_responder') {
            $responder_id = (int)($_POST['responder_id'] ?? 0);
            $task_notes   = trim($_POST['task_notes'] ?? '');

            if ($responder_id) {
                // Check not already assigned
                $already = db()->prepare(
                    "SELECT id FROM responder_incident_assignments
                     WHERE incident_id = ? AND responder_id = ? AND released_at IS NULL"
                );
                $already->execute([$id, $responder_id]);

                if (!$already->fetch()) {
                    db()->prepare(
                        "INSERT INTO responder_incident_assignments (incident_id, responder_id, task_notes)
                         VALUES (?,?,?)"
                    )->execute([$id, $responder_id, $task_notes ?: null]);

                    db()->prepare(
                        "UPDATE responders SET status = 'on_duty' WHERE id = ?"
                    )->execute([$responder_id]);

                    log_activity('assign_responder', 'incident', $id, "Assigned responder #$responder_id");
                    flash('success', 'Responder assigned successfully.');
                } else {
                    flash('warning', 'This responder is already assigned to this incident.');
                }
            }

        } elseif ($action === 'release_responder') {
            $responder_id = (int)($_POST['responder_id'] ?? 0);
            if ($responder_id) {
                db()->prepare(
                    "UPDATE responder_incident_assignments SET released_at = NOW()
                     WHERE incident_id = ? AND responder_id = ? AND released_at IS NULL"
                )->execute([$id, $responder_id]);

                db()->prepare(
                    "UPDATE responders SET status = 'available' WHERE id = ?"
                )->execute([$responder_id]);

                log_activity('release_responder', 'incident', $id, "Released responder #$responder_id");
                flash('success', 'Responder released.');
            }
        }

    } catch (PDOException $e) {
        error_log($e->getMessage());
        flash('danger', 'Action failed. Please try again.');
    }

    header('Location: ' . APP_URL . '/modules/incidents/view.php?id=' . $id);
    exit;
}

$type_info = INCIDENT_TYPES[$incident['incident_type']] ?? ['label' => $incident['incident_type'], 'icon' => 'bi-exclamation'];

include APP_ROOT . '/includes/header.php';
?>

<!-- Back + Actions bar -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
  <div class="d-flex align-items-center gap-2">
    <a href="<?= APP_URL ?>/modules/incidents/index.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left"></i>
    </a>
    <div>
      <h5 class="fw-bold mb-0"><?= htmlspecialchars($incident['title']) ?></h5>
      <span class="text-muted" style="font-size:.8rem"><?= htmlspecialchars($incident['reference_number']) ?></span>
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if (is_official()): ?>
    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#statusModal">
      <i class="bi bi-arrow-repeat me-1"></i>Update Status
    </button>
    <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#assignModal">
      <i class="bi bi-person-plus me-1"></i>Assign Responder
    </button>
    <a href="<?= APP_URL ?>/modules/incidents/edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-pencil me-1"></i>Edit
    </a>
    <?php endif; ?>
    <button class="btn btn-sm btn-outline-dark" onclick="printPage()">
      <i class="bi bi-printer me-1"></i>Print
    </button>
  </div>
</div>

<div class="row g-4">

  <!-- Left: main details -->
  <div class="col-lg-8">

    <!-- Incident Info card -->
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="<?= $type_info['icon'] ?> me-2"></i><?= htmlspecialchars($type_info['label']) ?></span>
        <div class="d-flex gap-2">
          <span class="badge badge-severity-<?= $incident['severity'] ?> text-white">
            <?= ucfirst($incident['severity']) ?>
          </span>
          <span class="badge badge-status-<?= $incident['status'] ?> text-white">
            <?= ucfirst(str_replace('_', ' ', $incident['status'])) ?>
          </span>
        </div>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <div class="text-muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Location</div>
            <div class="fw-medium"><?= htmlspecialchars($incident['location_address']) ?></div>
            <?php if ($incident['landmark']): ?>
              <div class="text-muted" style="font-size:.8rem">📍 <?= htmlspecialchars($incident['landmark']) ?></div>
            <?php endif; ?>
          </div>
          <div class="col-md-3">
            <div class="text-muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Incident Date</div>
            <div class="fw-medium"><?= date('M d, Y', strtotime($incident['reported_at'])) ?></div>
            <div class="text-muted" style="font-size:.8rem"><?= date('h:i A', strtotime($incident['reported_at'])) ?></div>
          </div>
          <div class="col-md-3">
            <div class="text-muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Filed On</div>
            <div class="fw-medium"><?= date('M d, Y', strtotime($incident['created_at'])) ?></div>
            <div class="text-muted" style="font-size:.8rem"><?= date('h:i A', strtotime($incident['created_at'])) ?></div>
          </div>
          <?php if ($incident['estimated_affected'] || $incident['estimated_households']): ?>
          <div class="col-md-6">
            <div class="text-muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Estimated Affected</div>
            <div class="fw-medium">
              <?= $incident['estimated_affected'] ? number_format($incident['estimated_affected']) . ' person(s)' : '' ?>
              <?= $incident['estimated_households'] ? ' · ' . number_format($incident['estimated_households']) . ' household(s)' : '' ?>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <hr>
        <div class="text-muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.4rem">Description</div>
        <p style="font-size:.9rem;line-height:1.7;white-space:pre-line"><?= htmlspecialchars($incident['description']) ?></p>

        <?php if ($incident['notes'] && is_official()): ?>
        <div class="alert alert-info py-2 px-3 mb-0" style="font-size:.85rem">
          <strong>Internal Notes:</strong> <?= htmlspecialchars($incident['notes']) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Photos -->
    <?php if (!empty($photos)): ?>
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-images me-2"></i>Incident Photos</div>
      <div class="card-body">
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($photos as $ph): ?>
          <a href="<?= APP_URL . '/' . htmlspecialchars($ph['file_path']) ?>" target="_blank">
            <img src="<?= APP_URL . '/' . htmlspecialchars($ph['file_path']) ?>"
                 alt="Incident photo"
                 class="img-thumbnail"
                 style="width:110px;height:90px;object-fit:cover;cursor:zoom-in">
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Status History -->
    <div class="card">
      <div class="card-header"><i class="bi bi-clock-history me-2"></i>Status History</div>
      <div class="card-body p-0">
        <?php if (empty($history)): ?>
          <p class="text-muted text-center py-3" style="font-size:.85rem">No status changes recorded yet.</p>
        <?php else: ?>
        <div class="timeline px-3 pt-3">
          <?php foreach ($history as $h): ?>
          <div class="d-flex gap-3 mb-3">
            <div class="flex-shrink-0">
              <div style="width:10px;height:10px;background:var(--bdrs-blue);border-radius:50%;margin-top:4px"></div>
            </div>
            <div>
              <div style="font-size:.82rem">
                <span class="badge badge-status-<?= $h['old_status'] ?> text-white me-1"><?= ucfirst(str_replace('_',' ',$h['old_status'])) ?></span>
                → 
                <span class="badge badge-status-<?= $h['new_status'] ?> text-white ms-1"><?= ucfirst(str_replace('_',' ',$h['new_status'])) ?></span>
              </div>
              <?php if ($h['remarks']): ?>
                <div class="text-muted" style="font-size:.8rem"><?= htmlspecialchars($h['remarks']) ?></div>
              <?php endif; ?>
              <div class="text-muted" style="font-size:.73rem">
                by <?= htmlspecialchars($h['username']) ?> · <?= date('M d, Y h:i A', strtotime($h['changed_at'])) ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- Right: reporter + responders -->
  <div class="col-lg-4">

    <!-- Reporter info -->
    <div class="card mb-4">
      <div class="card-header"><i class="bi bi-person me-2"></i>Reporter Information</div>
      <div class="card-body" style="font-size:.85rem">
        <div class="mb-1"><strong>Name:</strong> <?= htmlspecialchars($incident['reporter_name'] ?: $incident['reporter_username']) ?></div>
        <?php if ($incident['reporter_contact']): ?>
        <div class="mb-1"><strong>Contact:</strong> <?= htmlspecialchars($incident['reporter_contact']) ?></div>
        <?php endif; ?>
        <?php if ($incident['purok_sitio']): ?>
        <div class="mb-1"><strong>Purok/Sitio:</strong> <?= htmlspecialchars($incident['purok_sitio']) ?></div>
        <?php endif; ?>
        <?php if ($incident['latitude'] && $incident['longitude']): ?>
        <div class="mt-2">
          <a href="https://maps.google.com/?q=<?= $incident['latitude'] ?>,<?= $incident['longitude'] ?>"
             target="_blank" class="btn btn-sm btn-outline-primary w-100">
            <i class="bi bi-map me-1"></i>View on Google Maps
          </a>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Assigned Responders -->
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-person-badge me-2"></i>Assigned Responders</span>
        <span class="badge bg-primary"><?= count($responders) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if (empty($responders)): ?>
          <p class="text-muted text-center py-3 mb-0" style="font-size:.82rem">No responders assigned yet.</p>
        <?php else: foreach ($responders as $rsp): ?>
          <div class="d-flex align-items-start gap-2 px-3 py-2 border-bottom">
            <div class="flex-shrink-0">
              <div style="width:36px;height:36px;background:var(--bdrs-blue-light);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;color:var(--bdrs-blue)">
                <i class="bi bi-person-badge-fill"></i>
              </div>
            </div>
            <div class="flex-grow-1">
              <div class="fw-semibold" style="font-size:.83rem"><?= htmlspecialchars($rsp['full_name']) ?></div>
              <div class="text-muted" style="font-size:.75rem">
                <?= ucwords(str_replace('_', ' ', $rsp['responder_type'])) ?>
                <?= $rsp['unit_name'] ? ' · ' . htmlspecialchars($rsp['unit_name']) : '' ?>
              </div>
              <?php if ($rsp['task_notes']): ?>
                <div class="text-muted" style="font-size:.73rem"><?= htmlspecialchars($rsp['task_notes']) ?></div>
              <?php endif; ?>
            </div>
            <?php if (is_official()): ?>
            <form method="POST" class="flex-shrink-0">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="release_responder">
              <input type="hidden" name="responder_id" value="<?= $rsp['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1"
                      title="Release responder"
                      data-confirm="Release this responder from the incident?">
                <i class="bi bi-x"></i>
              </button>
            </form>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Quick rescue link -->
    <?php if (is_resident()): ?>
    <div class="card border-danger">
      <div class="card-body text-center">
        <p class="fw-semibold mb-2" style="font-size:.85rem">Need immediate help?</p>
        <a href="<?= APP_URL ?>/modules/rescue/create.php?incident_id=<?= $id ?>" class="btn btn-sos w-100">
          <i class="bi bi-exclamation-circle-fill me-2"></i>SOS — Request Rescue
        </a>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- ── Status Update Modal ──────────────────────────────────────── -->
<?php if (is_official()): ?>
<div class="modal fade" id="statusModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_status">
        <div class="modal-header">
          <h5 class="modal-title fw-bold">Update Incident Status</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">New Status</label>
            <select name="new_status" class="form-select" required>
              <option value="">— Select status —</option>
              <option value="pending"      <?= $incident['status'] === 'pending'      ? 'selected':'' ?>>Pending</option>
              <option value="acknowledged" <?= $incident['status'] === 'acknowledged' ? 'selected':'' ?>>Acknowledged</option>
              <option value="ongoing"      <?= $incident['status'] === 'ongoing'      ? 'selected':'' ?>>Ongoing</option>
              <option value="resolved"     <?= $incident['status'] === 'resolved'     ? 'selected':'' ?>>Resolved</option>
              <option value="archived"     <?= $incident['status'] === 'archived'     ? 'selected':'' ?>>Archived</option>
            </select>
          </div>
          <div class="mb-0">
            <label class="form-label fw-semibold">Remarks <span class="text-muted">(optional)</span></label>
            <textarea name="remarks" class="form-control" rows="3" placeholder="Briefly describe the update…" maxlength="500"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Status</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Assign Responder Modal ──────────────────────────────────── -->
<div class="modal fade" id="assignModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign_responder">
        <div class="modal-header">
          <h5 class="modal-title fw-bold">Assign Responder</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php if (empty($available_responders)): ?>
            <div class="alert alert-warning mb-0">No available responders at the moment.</div>
          <?php else: ?>
          <div class="mb-3">
            <label class="form-label fw-semibold">Select Responder <span class="text-danger">*</span></label>
            <select name="responder_id" class="form-select" required>
              <option value="">— Select responder —</option>
              <?php foreach ($available_responders as $r): ?>
                <option value="<?= $r['id'] ?>">
                  <?= htmlspecialchars($r['full_name']) ?> — <?= ucwords(str_replace('_',' ',$r['responder_type'])) ?>
                  <?= $r['unit_name'] ? '(' . htmlspecialchars($r['unit_name']) . ')' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-0">
            <label class="form-label fw-semibold">Task Notes <span class="text-muted">(optional)</span></label>
            <textarea name="task_notes" class="form-control" rows="2" placeholder="Specific instructions for this responder…" maxlength="500"></textarea>
          </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <?php if (!empty($available_responders)): ?>
          <button type="submit" class="btn btn-success">Assign</button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include APP_ROOT . '/includes/footer.php'; ?>
