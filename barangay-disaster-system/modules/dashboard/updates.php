<?php
/**
 * Live Barangay Updates — Real-time feed for all users
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();

$page_title  = 'Live Barangay Updates';
$active_page = 'updates';

try {
    // All active announcements
    $announcements = db()->query(
        "SELECT a.*, u.username AS posted_by
         FROM announcements a
         JOIN users u ON u.id = a.posted_by_id
         WHERE a.is_active = 1
           AND (a.scheduled_for IS NULL OR a.scheduled_for <= NOW())
           AND (a.expires_at IS NULL OR a.expires_at > NOW())
         ORDER BY a.severity = 'critical' DESC,
                  a.is_pinned DESC,
                  a.created_at DESC
         LIMIT 30"
    )->fetchAll();

    // Active incidents (public summary)
    $active_incidents = db()->query(
        "SELECT i.incident_type, i.severity, i.title,
                i.location_address, i.estimated_affected, i.reported_at, i.status
         FROM incidents i
         WHERE i.status NOT IN ('resolved','archived')
         ORDER BY FIELD(i.severity,'critical','high','moderate','low'), i.created_at DESC
         LIMIT 10"
    )->fetchAll();

    // Active evacuation centers
    $evac_centers = db()->query(
        "SELECT name, location_address, capacity, current_occupancy, status,
                contact_person, contact_number, has_medical_area
         FROM evacuation_centers
         WHERE status IN ('active','standby')
         ORDER BY status = 'active' DESC, name ASC"
    )->fetchAll();

    // System settings (hotlines)
    $settings = [];
    $rows = db()->query(
        "SELECT setting_key, setting_value FROM system_settings"
    )->fetchAll();
    foreach ($rows as $r) $settings[$r['setting_key']] = $r['setting_value'];

    // Active rescue count
    $rescue_count = (int)db()->query(
        "SELECT COUNT(*) FROM rescue_requests WHERE status NOT IN ('completed','cancelled')"
    )->fetchColumn();

} catch (PDOException $e) {
    error_log($e->getMessage());
    $announcements = $active_incidents = $evac_centers = [];
    $settings = [];
    $rescue_count = 0;
}

$type_icons = INCIDENT_TYPES;
$sev_config = [
    'critical' => ['CRITICAL', 'danger'],
    'high'     => ['High',     'warning'],
    'moderate' => ['Moderate', 'orange'],
    'low'      => ['Low',      'success'],
];

include APP_ROOT . '/includes/header.php';
?>

<!-- Auto-refresh notice -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
  <div>
    <h4 class="fw-bold mb-0"><i class="bi bi-broadcast text-danger me-2"></i>Live Barangay Updates</h4>
    <p class="text-muted mb-0" style="font-size:.82rem">
      Real-time disaster and emergency information · Auto-refreshes every 30 seconds
      <span class="badge bg-success ms-1" id="liveIndicator">● LIVE</span>
    </p>
  </div>
  <div class="text-muted" style="font-size:.78rem">
    Last updated: <span id="lastUpdated"><?= date('h:i:s A') ?></span>
  </div>
</div>

<!-- Critical alerts at top -->
<?php
$critical_anns = array_filter($announcements, fn($a) => $a['severity'] === 'critical');
foreach ($critical_anns as $ann):
?>
<div class="emergency-banner mb-3 rounded">
  <i class="bi bi-exclamation-triangle-fill fs-5"></i>
  <div>
    <strong><?= htmlspecialchars($ann['title']) ?></strong>
    <div style="font-size:.82rem;font-weight:400;opacity:.9"><?= htmlspecialchars(substr($ann['body'], 0, 200)) ?>…</div>
  </div>
</div>
<?php endforeach; ?>

<div class="row g-4">

  <!-- Left column: Incidents + Announcements -->
  <div class="col-lg-8">

    <!-- Active incidents -->
    <?php if (!empty($active_incidents)): ?>
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-bold text-danger">
          <i class="bi bi-exclamation-triangle-fill me-2"></i>Active Incidents
        </span>
        <span class="badge bg-danger"><?= count($active_incidents) ?> active</span>
      </div>
      <div class="card-body p-0">
        <?php foreach ($active_incidents as $inc):
          $t   = $type_icons[$inc['incident_type']] ?? ['label' => $inc['incident_type'], 'icon' => 'bi-exclamation'];
          [$slabel, $scls] = $sev_config[$inc['severity']] ?? ['—', 'secondary'];
        ?>
        <div class="d-flex align-items-start gap-3 px-3 py-3 border-bottom">
          <div class="incident-type-icon bg-danger bg-opacity-10 text-danger flex-shrink-0">
            <i class="<?= $t['icon'] ?>"></i>
          </div>
          <div class="flex-grow-1">
            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
              <span class="fw-semibold" style="font-size:.88rem"><?= htmlspecialchars($inc['title']) ?></span>
              <span class="badge badge-severity-<?= $inc['severity'] ?> text-white" style="font-size:.65rem"><?= $slabel ?></span>
              <span class="badge badge-status-<?= $inc['status'] ?> text-white" style="font-size:.65rem"><?= ucfirst($inc['status']) ?></span>
            </div>
            <div class="text-muted" style="font-size:.8rem">
              <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($inc['location_address']) ?>
            </div>
            <?php if ($inc['estimated_affected']): ?>
            <div class="text-muted" style="font-size:.75rem">
              <i class="bi bi-people me-1"></i><?= number_format($inc['estimated_affected']) ?> persons affected
            </div>
            <?php endif; ?>
            <div class="text-muted" style="font-size:.72rem">
              <i class="bi bi-clock me-1"></i><?= date('M d, Y h:i A', strtotime($inc['reported_at'])) ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="card-footer bg-transparent text-center py-2">
        <a href="<?= APP_URL ?>/modules/incidents/index.php" class="small text-decoration-none">
          View all incidents →
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- Announcements feed -->
    <div class="card" id="announcementsFeed">
      <div class="card-header fw-bold">
        <i class="bi bi-megaphone-fill me-2 text-primary"></i>Official Announcements
      </div>
      <div class="card-body p-0">
        <?php if (empty($announcements)): ?>
        <div class="text-center text-muted py-4">No announcements at this time.</div>
        <?php else:
          $ann_type_icons = [
            'general'         => ['bi-megaphone',         'secondary'],
            'evacuation'      => ['bi-house-door',         'primary'],
            'weather_alert'   => ['bi-cloud-lightning',    'warning'],
            'road_closure'    => ['bi-sign-stop',          'danger'],
            'rescue_update'   => ['bi-life-preserver',     'info'],
            'relief_schedule' => ['bi-box-seam',           'success'],
            'health_advisory' => ['bi-heart-pulse',        'danger'],
          ];
          foreach ($announcements as $ann):
            $sev_border = ['info' => 'primary', 'warning' => 'warning', 'critical' => 'danger'][$ann['severity']] ?? 'secondary';
            [$aicon, $acls] = $ann_type_icons[$ann['type']] ?? ['bi-megaphone', 'secondary'];
        ?>
        <div class="border-start border-4 border-<?= $sev_border ?> px-3 py-3 border-bottom">
          <div class="d-flex align-items-start gap-2 mb-1 flex-wrap">
            <?php if ($ann['is_pinned']): ?>
              <span class="badge bg-warning text-dark" style="font-size:.63rem"><i class="bi bi-pin-fill"></i> Pinned</span>
            <?php endif; ?>
            <span class="badge bg-<?= $acls ?> bg-opacity-20 text-<?= $acls ?>" style="font-size:.65rem">
              <i class="<?= $aicon ?> me-1"></i><?= ucwords(str_replace('_',' ',$ann['type'])) ?>
            </span>
          </div>
          <div class="fw-semibold mb-1" style="font-size:.92rem"><?= htmlspecialchars($ann['title']) ?></div>
          <p class="text-muted mb-1" style="font-size:.83rem;line-height:1.6;white-space:pre-line"><?= htmlspecialchars($ann['body']) ?></p>
          <div class="text-muted" style="font-size:.72rem">
            Posted by <strong><?= htmlspecialchars($ann['posted_by']) ?></strong>
            · <?= date('M d, Y h:i A', strtotime($ann['created_at'])) ?>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

  </div>

  <!-- Right column: Hotlines + Evac + SOS -->
  <div class="col-lg-4">

    <!-- SOS button for residents -->
    <?php if (is_resident()): ?>
    <div class="card mb-4 border-danger">
      <div class="card-body text-center py-4">
        <p class="fw-bold mb-3" style="font-size:.9rem">Need immediate assistance?</p>
        <a href="<?= APP_URL ?>/modules/rescue/create.php" class="btn btn-sos w-100 mb-2">
          <i class="bi bi-exclamation-circle-fill me-2 fs-5"></i>SOS — Request Rescue
        </a>
        <a href="<?= APP_URL ?>/modules/incidents/create.php" class="btn btn-outline-danger w-100" style="font-size:.85rem">
          <i class="bi bi-exclamation-triangle me-1"></i>Report an Incident
        </a>
        <?php if ($rescue_count > 0): ?>
        <div class="mt-2 text-muted" style="font-size:.73rem">
          <i class="bi bi-life-preserver me-1"></i><?= $rescue_count ?> active rescue request(s) in queue
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Emergency Hotlines -->
    <div class="card mb-4 border-danger">
      <div class="card-header fw-bold bg-danger text-white">
        <i class="bi bi-telephone-fill me-2"></i>Emergency Hotlines
      </div>
      <div class="card-body p-3">
        <?php
        $hotline_map = [
          'emergency_hotline_1' => ['Primary Emergency', 'danger'],
          'emergency_hotline_2' => ['Emergency (Alt)',   'danger'],
          'mdrrmo_hotline'      => ['MDRRMO',            'primary'],
          'bfp_hotline'         => ['BFP Fire Station',  'warning'],
          'pnp_hotline'         => ['PNP Police',        'dark'],
          'hospital_hotline'    => ['Hospital',          'info'],
        ];
        foreach ($hotline_map as $key => [$label, $cls]):
          if (!empty($settings[$key])): ?>
        <div class="d-flex align-items-center gap-2 mb-2">
          <i class="bi bi-telephone-fill text-<?= $cls ?>"></i>
          <div class="flex-grow-1">
            <div class="text-muted" style="font-size:.7rem"><?= $label ?></div>
            <a href="tel:<?= htmlspecialchars($settings[$key]) ?>"
               class="fw-bold text-<?= $cls ?> text-decoration-none">
              <?= htmlspecialchars($settings[$key]) ?>
            </a>
          </div>
        </div>
        <?php endif; endforeach; ?>
      </div>
    </div>

    <!-- Evacuation Centers -->
    <?php if (!empty($evac_centers)): ?>
    <div class="card mb-4">
      <div class="card-header fw-bold">
        <i class="bi bi-house-door me-2 text-primary"></i>Evacuation Centers
      </div>
      <div class="card-body p-0">
        <?php foreach ($evac_centers as $ec):
          $pct   = $ec['capacity'] > 0 ? round(($ec['current_occupancy']/$ec['capacity'])*100) : 0;
          $pcls  = $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success');
          $stcls = $ec['status'] === 'active' ? 'success' : 'secondary';
        ?>
        <div class="px-3 py-2 border-bottom">
          <div class="d-flex justify-content-between align-items-start mb-1">
            <div class="fw-semibold" style="font-size:.85rem"><?= htmlspecialchars($ec['name']) ?></div>
            <span class="badge bg-<?= $stcls ?> flex-shrink-0" style="font-size:.65rem"><?= ucfirst($ec['status']) ?></span>
          </div>
          <div class="text-muted" style="font-size:.75rem">
            <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($ec['location_address']) ?>
          </div>
          <div class="d-flex align-items-center gap-2 mt-1">
            <div class="progress flex-grow-1" style="height:5px">
              <div class="progress-bar <?= $pcls ?>" style="width:<?= $pct ?>%"></div>
            </div>
            <span style="font-size:.72rem;white-space:nowrap">
              <?= number_format($ec['current_occupancy']) ?>/<?= number_format($ec['capacity']) ?>
            </span>
          </div>
          <?php if ($ec['contact_number']): ?>
          <div class="text-muted mt-1" style="font-size:.72rem">
            <i class="bi bi-telephone me-1"></i><?= htmlspecialchars($ec['contact_number']) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <div class="text-center py-2">
          <a href="<?= APP_URL ?>/modules/evacuation/index.php" class="small text-decoration-none">
            View all centers →
          </a>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>

<script>
// Auto-refresh the page every 30 seconds
let countdown = 30;
const indicator = document.getElementById('liveIndicator');
const lastUpdated = document.getElementById('lastUpdated');

setInterval(() => {
    countdown--;
    if (countdown <= 0) {
        location.reload();
    }
    if (indicator) {
        indicator.textContent = countdown < 5 ? '● Refreshing…' : '● LIVE';
    }
}, 1000);
</script>
