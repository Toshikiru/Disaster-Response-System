<?php
/**
 * System Settings — Admin only
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_role([ROLE_ADMIN]);

$page_title  = 'System Settings';
$active_page = 'settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $stmt = db()->prepare(
            "INSERT INTO system_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );

        $fields = [
            'barangay_name', 'municipality', 'province',
            'emergency_hotline_1', 'emergency_hotline_2',
            'mdrrmo_hotline', 'bfp_hotline', 'pnp_hotline', 'hospital_hotline',
            'allow_registration', 'maintenance_mode',
        ];

        foreach ($fields as $key) {
            if (isset($_POST[$key])) {
                $stmt->execute([$key, trim($_POST[$key])]);
            }
        }

        log_activity('update_settings', 'settings', null, 'System settings updated');
        flash('success', 'Settings saved successfully.');

    } catch (PDOException $e) {
        error_log($e->getMessage());
        flash('danger', 'Error saving settings.');
    }

    header('Location: ' . APP_URL . '/modules/settings/index.php');
    exit;
}

try {
    $rows = db()->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll();
    $settings = [];
    foreach ($rows as $r) $settings[$r['setting_key']] = $r['setting_value'];
} catch (PDOException $e) {
    $settings = [];
}

include APP_ROOT . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
  <div>
    <h4 class="fw-bold mb-0">System Settings</h4>
    <p class="text-muted mb-0" style="font-size:.82rem">Configure barangay information, hotlines, and system options.</p>
  </div>
</div>

<form method="POST" action="" novalidate>
  <?= csrf_field() ?>
  <div class="row g-4">

    <!-- Barangay Info -->
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header fw-bold"><i class="bi bi-building me-2"></i>Barangay Information</div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label">Barangay Name</label>
            <input type="text" name="barangay_name" class="form-control"
                   value="<?= htmlspecialchars($settings['barangay_name'] ?? '') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Municipality / City</label>
            <input type="text" name="municipality" class="form-control"
                   value="<?= htmlspecialchars($settings['municipality'] ?? '') ?>">
          </div>
          <div class="mb-0">
            <label class="form-label">Province</label>
            <input type="text" name="province" class="form-control"
                   value="<?= htmlspecialchars($settings['province'] ?? '') ?>">
          </div>
        </div>
      </div>
    </div>

    <!-- Emergency Hotlines -->
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header fw-bold"><i class="bi bi-telephone me-2"></i>Emergency Hotlines</div>
        <div class="card-body">
          <?php
          $hotline_fields = [
            'emergency_hotline_1' => 'Primary Emergency Hotline',
            'emergency_hotline_2' => 'Secondary Emergency Hotline',
            'mdrrmo_hotline'      => 'MDRRMO',
            'bfp_hotline'         => 'BFP (Fire)',
            'pnp_hotline'         => 'PNP (Police)',
            'hospital_hotline'    => 'Municipal Hospital',
          ];
          foreach ($hotline_fields as $key => $label): ?>
          <div class="mb-3">
            <label class="form-label"><?= $label ?></label>
            <input type="text" name="<?= $key ?>" class="form-control"
                   value="<?= htmlspecialchars($settings[$key] ?? '') ?>"
                   placeholder="09XX-XXX-XXXX">
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- System Options -->
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header fw-bold"><i class="bi bi-gear me-2"></i>System Options</div>
        <div class="card-body">
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="allow_registration"
                   name="allow_registration" value="1"
                   <?= ($settings['allow_registration'] ?? '1') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="allow_registration">
              Allow Public Resident Registration
            </label>
            <div class="form-text">When disabled, only admins can create resident accounts.</div>
          </div>
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="maintenance_mode"
                   name="maintenance_mode" value="1"
                   <?= ($settings['maintenance_mode'] ?? '0') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="maintenance_mode">
              Maintenance Mode
            </label>
            <div class="form-text text-danger">Enable only during system maintenance. Residents will not be able to log in.</div>
          </div>
        </div>
      </div>
    </div>

    <!-- System Info (read-only) -->
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header fw-bold"><i class="bi bi-info-circle me-2"></i>System Information</div>
        <div class="card-body">
          <table class="table table-sm mb-0">
            <tbody>
              <tr><td class="text-muted">Version</td><td class="fw-semibold"><?= $settings['system_version'] ?? '1.0.0' ?></td></tr>
              <tr><td class="text-muted">PHP Version</td><td class="fw-semibold"><?= PHP_VERSION ?></td></tr>
              <tr><td class="text-muted">Database</td><td class="fw-semibold">MySQL / MariaDB</td></tr>
              <tr>
                <td class="text-muted">Total Users</td>
                <td class="fw-semibold">
                  <?php try { echo db()->query("SELECT COUNT(*) FROM users")->fetchColumn(); } catch(PDOException $e) { echo '—'; } ?>
                </td>
              </tr>
              <tr>
                <td class="text-muted">Total Incidents</td>
                <td class="fw-semibold">
                  <?php try { echo number_format((int)db()->query("SELECT COUNT(*) FROM incidents")->fetchColumn()); } catch(PDOException $e) { echo '—'; } ?>
                </td>
              </tr>
              <tr>
                <td class="text-muted">Server Time (PST)</td>
                <td class="fw-semibold"><?= date('M d, Y h:i A') ?></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>

  <div class="mt-4">
    <button type="submit" class="btn btn-primary px-5">
      <i class="bi bi-save me-1"></i>Save Settings
    </button>
  </div>
</form>

<?php include APP_ROOT . '/includes/footer.php'; ?>
