  </main>
  <!-- END MAIN CONTENT -->

  <!-- Footer -->
  <footer class="main-footer px-4 py-2 text-muted d-flex justify-content-between align-items-center">
    <span style="font-size:.75rem">
      &copy; <?= date('Y') ?>
      <?= htmlspecialchars($settings['barangay_name'] ?? 'Barangay') ?> &mdash;
      Community Disaster Reporting &amp; Response System v<?= $settings['system_version'] ?? '1.0.0' ?>
    </span>
    <span style="font-size:.75rem" class="d-none d-md-inline">
      Logged in as <strong><?= htmlspecialchars($_SESSION['username'] ?? '') ?></strong>
      &middot;
      <a href="<?= APP_URL ?>/modules/auth/logout.php" class="text-muted text-decoration-none">Logout</a>
    </span>
  </footer>

</div>
<!-- END MAIN WRAPPER -->

<!-- Bootstrap JS bundle (includes Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Main application JS -->
<script src="<?= APP_URL ?>/assets/js/main.js"></script>

<script>
// ── Dark mode toggle ────────────────────────────────────────
const html       = document.documentElement;
const icon       = document.getElementById('darkModeIcon');
const toggleBtn  = document.getElementById('darkModeToggle');
const DARK_KEY   = 'bdrs_dark_mode';

function applyTheme(dark) {
    html.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
    if (icon) {
        icon.className = dark ? 'bi bi-sun-fill' : 'bi bi-moon-fill';
    }
}

// Initialise from stored preference
applyTheme(localStorage.getItem(DARK_KEY) === '1');

if (toggleBtn) {
    toggleBtn.addEventListener('click', () => {
        const isDark = html.getAttribute('data-bs-theme') === 'dark';
        localStorage.setItem(DARK_KEY, isDark ? '0' : '1');
        applyTheme(!isDark);
    });
}

// ── Sidebar toggle ──────────────────────────────────────────
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebar       = document.getElementById('sidebar');
const mainWrapper   = document.querySelector('.main-wrapper');

if (sidebarToggle) {
    sidebarToggle.addEventListener('click', () => {
        sidebar.classList.toggle('sidebar-collapsed');
        mainWrapper.classList.toggle('sidebar-collapsed');
    });
}

// ── Auto-dismiss flash alerts after 5 s ─────────────────────
document.querySelectorAll('.alert.fade.show').forEach(el => {
    setTimeout(() => {
        const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
        bsAlert.close();
    }, 5000);
});

// ── Poll unread notification count every 30 s ───────────────
function pollNotifications() {
    fetch('<?= APP_URL ?>/modules/notifications/count.php')
        .then(r => r.json())
        .then(data => {
            const badge = document.querySelector('[aria-label="Notifications"] .badge');
            if (data.count > 0) {
                if (!badge) {
                    const btn = document.querySelector('[aria-label="Notifications"]');
                    if (btn) {
                        const b = document.createElement('span');
                        b.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger';
                        b.style.fontSize = '.6rem';
                        b.textContent = data.count > 99 ? '99+' : data.count;
                        btn.appendChild(b);
                    }
                } else {
                    badge.textContent = data.count > 99 ? '99+' : data.count;
                }
            } else if (badge) {
                badge.remove();
            }
        })
        .catch(() => { /* Silently fail on LAN latency */ });
}
setInterval(pollNotifications, 30000);
</script>

</body>
</html>
