<?php
/**
 * Evacuation Centers Management
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

$page_title  = 'Evacuation Centers';
$active_page = 'evacuation';

// Handle POST (officials: update occupancy, add center)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_official()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_occupancy') {
            $cid  = (int)$_POST['center_id'];
            $occ  = max(0, (int)$_POST['current_occupancy']);
            $stat = $_POST['status'] ?? 'active';
            if (!in_array($stat, ['standby','active','full','closed'])) $stat = 'active';
            db()->prepare(
                "UPDATE evacuation_centers SET current_occupancy = ?, status = ?, updated_at = NOW() WHERE id = ?"
            )->execute([$occ, $stat, $cid]);
            log_activity('update_evac', 'evacuation', $cid, "Occupancy → $occ, status → $stat");
            flash('success', 'Evacuation center updated.');

        } elseif ($action === 'add_center') {
            db()->prepare(
                "INSERT INTO evacuation_centers
                 (name, location_address, capacity, contact_person, contact_number,
                  has_medical_area, has_water_supply, has_power_supply, has_toilet_facilities, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                trim($_POST['name']), trim($_POST['location_address']),
                max(1, (int)$_POST['capacity']),
                trim($_POST['contact_person'] ?? '') ?: null,
                trim($_POST['contact_number'] ?? '') ?: null,
                isset($_POST['has_medical_area'])     ? 1 : 0,
                isset($_POST['has_water_supply'])      ? 1 : 0,
                isset($_POST['has_power_supply'])      ? 1 : 0,
                isset($_POST['has_toilet_facilities']) ? 1 : 0,
                trim($_POST['notes'] ?? '') ?: null,
            ]);
            log_activity('add_evac_center', 'evacuation', (int)db()->lastInsertId(), 'Added evacuation center');
            flash('success', 'Evacuation center added successfully.');
        }
    } catch (PDOException $e) {
        error_log($e->getMessage());
        flash('danger', 'Error updating evacuation center.');
    }
    header('Location: ' . APP_URL . '/modules/evacuation/index.php');
    exit;
}

try {
    $centers = db()->query(
        "SELECT *, ROUND((current_occupancy / NULLIF(capacity,0)) * 100) AS pct
         FROM evacuation_centers ORDER BY status ASC, name ASC"
    )->fetchAll();
} catch (PDOException $e) {
    $centers = [];
}

$status_config = [
    'standby' => ['Standby',  'secondary'],
    'active'  => ['Active',   'success'],
    'full'    => ['Full',     'danger'],
    'closed'  => ['Closed',   'dark'],
];

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
  <div>
    <h4 class="fw-bold mb-0">Evacuation Centers</h4>
    <p class="text-muted mb-0" style="font-size:.82rem">
      <?= count(array_filter($centers, fn($c) => $c['status'] === 'active')) ?> active ·
      Total evacuees: <?= number_format(array_sum(array_column($centers, 'current_occupancy'))) ?>
    </p>
  </div>
  <?php if (is_official()): ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCenterModal">
    <i class="bi bi-plus-circle me-1"></i>Add Center
  </button>
  <?php endif; ?>
</div>

<div class="row g-3">
  <?php if (empty($centers)): ?>
  <div class="col-12">
    <div class="card">
      <div class="card-body text-center text-muted py-5">
        <i class="bi bi-house-door fs-3 d-block mb-2"></i>No evacuation centers registered.
      </div>
    </div>
  </div>
  <?php else: foreach ($centers as $c):
    $pct = (int)($c['pct'] ?? 0);
    $bar_cls = $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success');
    [$st_label, $st_cls] = $status_config[$c['status']] ?? ['Unknown', 'secondary'];
  ?>
  <div class="col-md-6 col-xl-4">
    <div class="card h-100 <?= $c['status'] === 'full' ? 'border-danger' : '' ?>">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-bold text-truncate"><?= htmlspecialchars($c['name']) ?></span>
        <span class="badge bg-<?= $st_cls ?> flex-shrink-0"><?= $st_label ?></span>
      </div>
      <div class="card-body">
        <div class="text-muted mb-2" style="font-size:.8rem">
          <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($c['location_address']) ?>
        </div>

        <!-- Occupancy bar -->
        <div class="mb-3">
          <div class="d-flex justify-content-between mb-1">
            <span style="font-size:.78rem">Occupancy</span>
            <span style="font-size:.78rem" class="fw-semibold">
              <?= number_format($c['current_occupancy']) ?> / <?= number_format($c['capacity']) ?>
              (<?= $pct ?>%)
            </span>
          </div>
          <div class="progress" style="height:8px">
            <div class="progress-bar <?= $bar_cls ?>" style="width:<?= min($pct, 100) ?>%"></div>
          </div>
        </div>

        <!-- Facilities -->
        <div class="d-flex flex-wrap gap-1 mb-3">
          <?php
          $facilities = [
            'has_medical_area'      => ['bi-hospital',     'Medical Area'],
            'has_water_supply'      => ['bi-droplet',      'Water'],
            'has_power_supply'      => ['bi-lightning',    'Power'],
            'has_toilet_facilities' => ['bi-door-closed',  'Toilets'],
          ];
          foreach ($facilities as $field => [$icon, $label]):
          ?>
            <span class="badge <?= $c[$field] ? 'bg-success' : 'bg-secondary opacity-50' ?>"
                  style="font-size:.65rem">
              <i class="<?= $icon ?> me-1"></i><?= $label ?>
            </span>
          <?php endforeach; ?>
        </div>

        <?php if ($c['contact_person']): ?>
        <div style="font-size:.78rem" class="text-muted">
          <i class="bi bi-person me-1"></i><?= htmlspecialchars($c['contact_person']) ?>
          <?= $c['contact_number'] ? ' · ' . htmlspecialchars($c['contact_number']) : '' ?>
        </div>
        <?php endif; ?>
      </div>

      <?php if (is_official()): ?>
      <div class="card-footer bg-transparent">
        <button class="btn btn-sm btn-outline-primary w-100"
                data-bs-toggle="modal" data-bs-target="#updateModal<?= $c['id'] ?>">
          <i class="bi bi-pencil me-1"></i>Update Occupancy & Status
        </button>
      </div>

      <!-- Update modal for this center -->
      <div class="modal fade" id="updateModal<?= $c['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-sm">
          <div class="modal-content">
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="update_occupancy">
              <input type="hidden" name="center_id" value="<?= $c['id'] ?>">
              <div class="modal-header">
                <h6 class="modal-title fw-bold"><?= htmlspecialchars($c['name']) ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <div class="mb-3">
                  <label class="form-label">Current Occupancy</label>
                  <input type="number" name="current_occupancy" class="form-control"
                         min="0" max="<?= $c['capacity'] ?>"
                         value="<?= $c['current_occupancy'] ?>" required>
                  <div class="form-text">Max capacity: <?= number_format($c['capacity']) ?></div>
                </div>
                <div>
                  <label class="form-label">Status</label>
                  <select name="status" class="form-select">
                    <?php foreach ($status_config as $sv => [$sl,]): ?>
                      <option value="<?= $sv ?>" <?= $c['status'] === $sv ? 'selected' : '' ?>><?= $sl ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm">Save</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<!-- Add Center Modal -->
<?php if (is_official()): ?>
<div class="modal fade" id="addCenterModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_center">
        <div class="modal-header">
          <h5 class="modal-title fw-bold">Add Evacuation Center</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-7">
              <label class="form-label">Center Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required placeholder="e.g., Barangay Hall, Covered Court">
            </div>
            <div class="col-md-5">
              <label class="form-label">Capacity <span class="text-danger">*</span></label>
              <input type="number" name="capacity" class="form-control" min="1" required placeholder="200">
            </div>
            <div class="col-12">
              <label class="form-label">Address <span class="text-danger">*</span></label>
              <input type="text" name="location_address" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Contact Person</label>
              <input type="text" name="contact_person" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">Contact Number</label>
              <input type="text" name="contact_number" class="form-control" placeholder="09XXXXXXXXX">
            </div>
            <div class="col-12">
              <label class="form-label d-block mb-1">Facilities Available</label>
              <div class="d-flex flex-wrap gap-3">
                <?php foreach ($facilities as $field => [$icon, $label]): ?>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="<?= $field ?>" id="fc_<?= $field ?>" value="1">
                  <label class="form-check-label" for="fc_<?= $field ?>"><i class="<?= $icon ?> me-1"></i><?= $label ?></label>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="notes" class="form-control" rows="2" placeholder="Additional information…"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Add Center</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include APP_ROOT . '/includes/footer.php'; ?>
