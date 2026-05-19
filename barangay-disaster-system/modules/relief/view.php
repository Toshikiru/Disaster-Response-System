<?php
/**
 * Relief Distribution — Detail View
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role([ROLE_OFFICIAL, ROLE_ADMIN]);

$page_title  = 'Distribution Details';
$active_page = 'relief';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    flash('danger', 'Invalid ID.');
    header('Location: ' . APP_URL . '/modules/relief/index.php');
    exit;
}

try {
    $dist = db()->prepare(
        "SELECT rd.*, u.username AS distributor_name,
                i.reference_number AS incident_ref, i.title AS incident_title
         FROM relief_distributions rd
         JOIN users u ON u.id = rd.distributed_by
         LEFT JOIN incidents i ON i.id = rd.incident_id
         WHERE rd.id = ?"
    );
    $dist->execute([$id]);
    $dist = $dist->fetch();

    if (!$dist) {
        flash('danger', 'Distribution record not found.');
        header('Location: ' . APP_URL . '/modules/relief/index.php');
        exit;
    }

    // Line items
    $lines = db()->prepare(
        "SELECT rdl.quantity_given, ri.name AS item_name, ri.unit
         FROM relief_distribution_lines rdl
         JOIN relief_items ri ON ri.id = rdl.item_id
         WHERE rdl.distribution_id = ?"
    );
    $lines->execute([$id]);
    $lines = $lines->fetchAll();

    // Beneficiaries
    $beneficiaries = db()->prepare(
        "SELECT rb.claimed_at, rb.claim_notes,
                CONCAT(r.first_name,' ',r.last_name) AS resident_name,
                r.street_address, r.purok_sitio, r.household_size
         FROM relief_beneficiaries rb
         JOIN residents r ON r.id = rb.resident_id
         WHERE rb.distribution_id = ?
         ORDER BY rb.claimed_at ASC"
    );
    $beneficiaries->execute([$id]);
    $beneficiaries = $beneficiaries->fetchAll();

} catch (PDOException $e) {
    error_log($e->getMessage());
    flash('danger', 'Error loading distribution record.');
    header('Location: ' . APP_URL . '/modules/relief/index.php');
    exit;
}

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
  <div class="d-flex align-items-center gap-2">
    <a href="<?= APP_URL ?>/modules/relief/index.php?tab=distributions" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left"></i>
    </a>
    <div>
      <h5 class="fw-bold mb-0">Distribution: <?= htmlspecialchars($dist['reference_number']) ?></h5>
      <span class="text-muted" style="font-size:.8rem">
        <?= date('F d, Y h:i A', strtotime($dist['distributed_at'])) ?>
      </span>
    </div>
  </div>
  <button class="btn btn-outline-dark btn-sm" onclick="printPage()">
    <i class="bi bi-printer me-1"></i>Print Record
  </button>
</div>

<!-- Print header -->
<div class="d-none d-print-block text-center mb-4">
  <h4 class="fw-bold">Relief Distribution Record</h4>
  <p>Reference: <?= htmlspecialchars($dist['reference_number']) ?> · Date: <?= date('F d, Y', strtotime($dist['distributed_at'])) ?></p>
  <hr>
</div>

<div class="row g-4">
  <div class="col-lg-8">

    <!-- Summary -->
    <div class="card mb-4">
      <div class="card-header fw-bold"><i class="bi bi-info-circle me-2"></i>Distribution Summary</div>
      <div class="card-body">
        <div class="row g-3" style="font-size:.88rem">
          <div class="col-md-6">
            <div class="text-muted fs-8 text-uppercase fw-bold mb-1">Distribution Site</div>
            <div class="fw-semibold"><?= htmlspecialchars($dist['distribution_site']) ?></div>
          </div>
          <div class="col-md-6">
            <div class="text-muted fs-8 text-uppercase fw-bold mb-1">Distributed By</div>
            <div class="fw-semibold"><?= htmlspecialchars($dist['distributor_name']) ?></div>
          </div>
          <?php if ($dist['incident_ref']): ?>
          <div class="col-12">
            <div class="text-muted fs-8 text-uppercase fw-bold mb-1">Linked Incident</div>
            <a href="<?= APP_URL ?>/modules/incidents/view.php?id=<?= $dist['incident_id'] ?>"
               class="text-decoration-none">
              <?= htmlspecialchars($dist['incident_ref']) ?> — <?= htmlspecialchars($dist['incident_title']) ?>
            </a>
          </div>
          <?php endif; ?>
          <?php if ($dist['notes']): ?>
          <div class="col-12">
            <div class="text-muted fs-8 text-uppercase fw-bold mb-1">Notes</div>
            <div><?= htmlspecialchars($dist['notes']) ?></div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Items distributed -->
    <div class="card mb-4">
      <div class="card-header fw-bold"><i class="bi bi-box-seam me-2"></i>Items Distributed</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th class="ps-3">Item</th>
              <th>Unit</th>
              <th class="text-end pe-3">Quantity Given</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($lines)): ?>
            <tr><td colspan="3" class="text-center text-muted py-3">No items recorded.</td></tr>
            <?php else: foreach ($lines as $line): ?>
            <tr>
              <td class="ps-3 fw-medium"><?= htmlspecialchars($line['item_name']) ?></td>
              <td class="text-muted"><?= htmlspecialchars($line['unit']) ?></td>
              <td class="text-end pe-3 fw-bold"><?= number_format($line['quantity_given']) ?></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Beneficiaries -->
    <?php if (!empty($beneficiaries)): ?>
    <div class="card">
      <div class="card-header fw-bold">
        <i class="bi bi-people me-2"></i>Beneficiaries (<?= count($beneficiaries) ?>)
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th class="ps-3">Name</th>
              <th>Address</th>
              <th>Household</th>
              <th>Claimed At</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($beneficiaries as $b): ?>
            <tr>
              <td class="ps-3 fw-medium" style="font-size:.85rem"><?= htmlspecialchars($b['resident_name']) ?></td>
              <td style="font-size:.82rem" class="text-muted">
                <?= htmlspecialchars(($b['purok_sitio'] ? $b['purok_sitio'] . ', ' : '') . $b['street_address']) ?>
              </td>
              <td class="text-center"><?= $b['household_size'] ?></td>
              <td style="font-size:.78rem" class="text-muted">
                <?= date('h:i A', strtotime($b['claimed_at'])) ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <!-- Right: KPIs -->
  <div class="col-lg-4">
    <div class="card mb-4">
      <div class="card-header fw-bold">Quick Stats</div>
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
          <span class="text-muted" style="font-size:.85rem">Total Item Types</span>
          <span class="fw-bold"><?= count($lines) ?></span>
        </div>
        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
          <span class="text-muted" style="font-size:.85rem">Total Items Given</span>
          <span class="fw-bold"><?= number_format(array_sum(array_column($lines, 'quantity_given'))) ?></span>
        </div>
        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
          <span class="text-muted" style="font-size:.85rem">Recorded Beneficiaries</span>
          <span class="fw-bold"><?= count($beneficiaries) ?></span>
        </div>
        <div class="d-flex justify-content-between align-items-center py-2">
          <span class="text-muted" style="font-size:.85rem">Distribution Date</span>
          <span class="fw-bold" style="font-size:.82rem"><?= date('M d, Y', strtotime($dist['distributed_at'])) ?></span>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-body d-grid">
        <a href="<?= APP_URL ?>/modules/relief/index.php?tab=distributions" class="btn btn-outline-secondary">
          <i class="bi bi-arrow-left me-1"></i>Back to Distributions
        </a>
      </div>
    </div>
  </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
