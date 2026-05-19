<?php
/**
 * Relief Distribution — Inventory & Distribution Management
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

$page_title  = 'Relief Distribution';
$active_page = 'relief';

$tab = $_GET['tab'] ?? 'inventory';

// ── Handle POST actions ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_official()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_stock') {
            $item_id  = (int)$_POST['item_id'];
            $quantity = max(0, (int)$_POST['quantity']);

            // Upsert inventory
            $existing = db()->prepare("SELECT id FROM relief_inventory WHERE item_id = ?");
            $existing->execute([$item_id]);
            if ($existing->fetch()) {
                db()->prepare(
                    "UPDATE relief_inventory SET quantity = ?, last_updated_by = ?, updated_at = NOW() WHERE item_id = ?"
                )->execute([$quantity, current_user_id(), $item_id]);
            } else {
                db()->prepare(
                    "INSERT INTO relief_inventory (item_id, quantity, last_updated_by) VALUES (?,?,?)"
                )->execute([$item_id, $quantity, current_user_id()]);
            }
            log_activity('update_stock', 'relief', $item_id, "Stock updated: item #$item_id → $quantity");
            flash('success', 'Stock updated successfully.');

        } elseif ($action === 'new_distribution') {
            $incident_id = (int)($_POST['incident_id'] ?? 0) ?: null;
            $site        = trim($_POST['distribution_site'] ?? 'Barangay Hall');
            $notes       = trim($_POST['notes'] ?? '') ?: null;
            $items       = $_POST['items']     ?? [];
            $quantities  = $_POST['quantities'] ?? [];

            if (empty($items)) {
                flash('danger', 'Please select at least one item.');
            } else {
                db()->beginTransaction();

                $ref = generate_reference(REF_RELIEF);
                db()->prepare(
                    "INSERT INTO relief_distributions (reference_number, incident_id, distributed_by, distribution_site, notes)
                     VALUES (?,?,?,?,?)"
                )->execute([$ref, $incident_id, current_user_id(), $site, $notes]);

                $dist_id = (int)db()->lastInsertId();

                foreach ($items as $idx => $item_id) {
                    $item_id = (int)$item_id;
                    $qty     = max(1, (int)($quantities[$idx] ?? 1));

                    db()->prepare(
                        "INSERT INTO relief_distribution_lines (distribution_id, item_id, quantity_given) VALUES (?,?,?)"
                    )->execute([$dist_id, $item_id, $qty]);

                    // Deduct from inventory
                    db()->prepare(
                        "UPDATE relief_inventory SET quantity = GREATEST(0, quantity - ?), updated_at = NOW() WHERE item_id = ?"
                    )->execute([$qty, $item_id]);
                }

                db()->commit();
                log_activity('new_distribution', 'relief', $dist_id, "Relief distribution $ref created");
                flash('success', "Distribution recorded. Reference: $ref");
                header('Location: ' . APP_URL . '/modules/relief/index.php?tab=distributions');
                exit;
            }
        }
    } catch (PDOException $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log($e->getMessage());
        flash('danger', 'Error processing request. Please try again.');
    }

    header('Location: ' . APP_URL . '/modules/relief/index.php?tab=' . $tab);
    exit;
}

// ── Fetch data ────────────────────────────────────────────────────
try {
    // Inventory overview
    $inventory = db()->query("SELECT * FROM v_relief_inventory ORDER BY item_name")->fetchAll();

    // Recent distributions (last 20)
    $distributions = db()->query(
        "SELECT rd.*, u.username AS distributor_name,
                COUNT(rdl.id) AS item_types,
                SUM(rdl.quantity_given) AS total_items
         FROM relief_distributions rd
         JOIN users u ON u.id = rd.distributed_by
         LEFT JOIN relief_distribution_lines rdl ON rdl.distribution_id = rd.id
         GROUP BY rd.id
         ORDER BY rd.distributed_at DESC
         LIMIT 20"
    )->fetchAll();

    // All active incidents for distribution form
    $active_incidents = db()->query(
        "SELECT id, reference_number, title FROM incidents
         WHERE status NOT IN ('archived','resolved') ORDER BY created_at DESC LIMIT 30"
    )->fetchAll();

    // Items for distribution form
    $relief_items = db()->query(
        "SELECT ri.id, ri.name, ri.unit, COALESCE(inv.quantity,0) AS stock
         FROM relief_items ri
         LEFT JOIN relief_inventory inv ON inv.item_id = ri.id
         WHERE ri.is_active = 1 ORDER BY ri.name"
    )->fetchAll();

    // Summary KPIs
    $total_beneficiaries = (int)db()->query(
        "SELECT COALESCE(SUM(total_beneficiaries),0) FROM relief_distributions"
    )->fetchColumn();
    $total_distributions = (int)db()->query(
        "SELECT COUNT(*) FROM relief_distributions"
    )->fetchColumn();
    $low_stock = array_filter($inventory, fn($i) => in_array($i['stock_level'], ['critical','out_of_stock']));

} catch (PDOException $e) {
    error_log($e->getMessage());
    $inventory = $distributions = $active_incidents = $relief_items = [];
    $total_beneficiaries = $total_distributions = 0;
    $low_stock = [];
}

include APP_ROOT . '/includes/header.php';
?>

<!-- Header -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
  <div>
    <h4 class="fw-bold mb-0">Relief Distribution</h4>
    <p class="text-muted mb-0" style="font-size:.82rem">
      <?= $total_distributions ?> distribution events ·
      <?= number_format($total_beneficiaries) ?> total beneficiaries
      <?php if (!empty($low_stock)): ?>
        · <span class="text-danger fw-semibold"><?= count($low_stock) ?> items low/out of stock</span>
      <?php endif; ?>
    </p>
  </div>
  <?php if (is_official()): ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newDistModal">
    <i class="bi bi-plus-circle me-1"></i>New Distribution
  </button>
  <?php endif; ?>
</div>

<!-- Low stock alert -->
<?php if (!empty($low_stock) && is_official()): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
  <div>
    <strong>Low / Out of Stock Items:</strong>
    <?= implode(', ', array_map(fn($i) => htmlspecialchars($i['item_name']), $low_stock)) ?>
  </div>
</div>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'inventory' ? 'active' : '' ?>" href="?tab=inventory">
      <i class="bi bi-box-seam me-1"></i>Inventory
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'distributions' ? 'active' : '' ?>" href="?tab=distributions">
      <i class="bi bi-truck me-1"></i>Distribution History
    </a>
  </li>
</ul>

<!-- INVENTORY TAB -->
<?php if ($tab === 'inventory'): ?>
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-box-seam me-2"></i>Current Stock Levels</span>
    <?php if (is_official()): ?>
    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#stockModal">
      <i class="bi bi-pencil me-1"></i>Update Stock
    </button>
    <?php endif; ?>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th class="ps-3">Item</th>
            <th>Unit</th>
            <th>Current Stock</th>
            <th>Stock Level</th>
            <th class="text-end pe-3">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($inventory as $item):
            $level_cfg = [
              'adequate'     => ['Adequate',     'success'],
              'low'          => ['Low',          'warning'],
              'critical'     => ['Critical',     'danger'],
              'out_of_stock' => ['Out of Stock', 'dark'],
            ][$item['stock_level']] ?? ['Unknown', 'secondary'];
          ?>
          <tr>
            <td class="ps-3 fw-medium"><?= htmlspecialchars($item['item_name']) ?></td>
            <td class="text-muted" style="font-size:.85rem"><?= htmlspecialchars($item['unit']) ?></td>
            <td>
              <span class="fw-bold fs-5"><?= number_format($item['current_stock']) ?></span>
            </td>
            <td style="min-width:140px">
              <?php
              $pct = min(100, $item['current_stock'] > 0 ? min(100, ($item['current_stock'] / 200) * 100) : 0);
              $bar = ['adequate' => 'bg-success', 'low' => 'bg-warning', 'critical' => 'bg-danger', 'out_of_stock' => 'bg-dark'][$item['stock_level']] ?? 'bg-secondary';
              ?>
              <div class="progress" style="height:6px">
                <div class="progress-bar <?= $bar ?>" style="width:<?= $pct ?>%"></div>
              </div>
            </td>
            <td class="text-end pe-3">
              <span class="badge bg-<?= $level_cfg[1] ?>"><?= $level_cfg[0] ?></span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- DISTRIBUTIONS TAB -->
<?php elseif ($tab === 'distributions'): ?>
<div class="card">
  <div class="card-header"><i class="bi bi-truck me-2"></i>Relief Distribution History</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th class="ps-3">Reference</th>
            <th>Date</th>
            <th>Distribution Site</th>
            <th>Item Types</th>
            <th>Total Items</th>
            <th>Beneficiaries</th>
            <th>Distributed By</th>
            <th class="pe-3">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($distributions)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No distributions recorded yet.</td></tr>
          <?php else: foreach ($distributions as $d): ?>
          <tr>
            <td class="ps-3">
              <span class="fw-semibold text-success" style="font-size:.8rem">
                <?= htmlspecialchars($d['reference_number']) ?>
              </span>
            </td>
            <td style="font-size:.82rem"><?= date('M d, Y h:i A', strtotime($d['distributed_at'])) ?></td>
            <td style="font-size:.82rem"><?= htmlspecialchars($d['distribution_site']) ?></td>
            <td class="text-center"><?= $d['item_types'] ?></td>
            <td class="text-center fw-semibold"><?= number_format($d['total_items']) ?></td>
            <td class="text-center"><?= $d['total_beneficiaries'] ? number_format($d['total_beneficiaries']) : '—' ?></td>
            <td style="font-size:.82rem"><?= htmlspecialchars($d['distributor_name']) ?></td>
            <td class="pe-3">
              <a href="<?= APP_URL ?>/modules/relief/view.php?id=<?= $d['id'] ?>"
                 class="btn btn-sm btn-outline-primary py-0 px-2"><i class="bi bi-eye"></i></a>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>


<!-- ── Update Stock Modal ─────────────────────────────────────── -->
<?php if (is_official()): ?>
<div class="modal fade" id="stockModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold">Update Stock Levels</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="max-height:60vh;overflow-y:auto">
        <?php foreach ($inventory as $item): ?>
        <form method="POST" class="row g-2 align-items-center border-bottom py-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_stock">
          <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
          <div class="col-6">
            <label class="form-label mb-0 fw-medium" style="font-size:.88rem"><?= htmlspecialchars($item['item_name']) ?></label>
            <div class="text-muted" style="font-size:.73rem"><?= htmlspecialchars($item['unit']) ?></div>
          </div>
          <div class="col-4">
            <input type="number" name="quantity" class="form-control form-control-sm"
                   min="0" value="<?= (int)$item['current_stock'] ?>" required>
          </div>
          <div class="col-2">
            <button type="submit" class="btn btn-sm btn-primary w-100">Save</button>
          </div>
        </form>
        <?php endforeach; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ── New Distribution Modal ─────────────────────────────────── -->
<div class="modal fade" id="newDistModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="new_distribution">
        <div class="modal-header">
          <h5 class="modal-title fw-bold">Record New Distribution</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3 mb-4">
            <div class="col-md-5">
              <label class="form-label fw-semibold">Distribution Site <span class="text-danger">*</span></label>
              <input type="text" name="distribution_site" class="form-control"
                     value="Barangay Hall" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Link to Incident</label>
              <select name="incident_id" class="form-select">
                <option value="">— No linked incident —</option>
                <?php foreach ($active_incidents as $inc): ?>
                  <option value="<?= $inc['id'] ?>">
                    <?= htmlspecialchars($inc['reference_number'] . ' — ' . $inc['title']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Notes</label>
              <input type="text" name="notes" class="form-control" placeholder="Optional notes">
            </div>
          </div>

          <h6 class="fw-bold mb-3">Items to Distribute</h6>
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead>
                <tr>
                  <th>Include</th>
                  <th>Item</th>
                  <th>Current Stock</th>
                  <th>Quantity to Give</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($relief_items as $idx => $item): ?>
                <tr>
                  <td>
                    <input type="checkbox" name="items[]" value="<?= $item['id'] ?>"
                           id="distItem<?= $idx ?>" class="form-check-input dist-item-cb"
                           data-idx="<?= $idx ?>">
                  </td>
                  <td>
                    <label for="distItem<?= $idx ?>" class="cursor-pointer fw-medium" style="font-size:.88rem">
                      <?= htmlspecialchars($item['name']) ?>
                    </label>
                    <span class="text-muted" style="font-size:.75rem">(<?= $item['unit'] ?>)</span>
                  </td>
                  <td>
                    <span class="badge <?= $item['stock'] > 0 ? 'bg-success' : 'bg-danger' ?>">
                      <?= number_format($item['stock']) ?>
                    </span>
                  </td>
                  <td>
                    <input type="number" name="quantities[]" class="form-control form-control-sm"
                           style="width:90px" min="1" max="<?= $item['stock'] ?>"
                           value="1" disabled id="distQty<?= $idx ?>">
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-send me-1"></i>Record Distribution
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include APP_ROOT . '/includes/footer.php'; ?>

<script>
// Enable/disable quantity input when checkbox is checked
document.querySelectorAll('.dist-item-cb').forEach(cb => {
    cb.addEventListener('change', function() {
        const qtyInput = document.getElementById('distQty' + this.dataset.idx);
        if (qtyInput) qtyInput.disabled = !this.checked;
    });
});
</script>
