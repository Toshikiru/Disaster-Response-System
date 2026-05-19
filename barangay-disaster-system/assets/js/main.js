/**
 * BDRS — Main Application JavaScript
 * Handles AJAX forms, auto-refresh, tooltips, QR generation, and UI utilities.
 */

'use strict';

/* ── Bootstrap Tooltips (global) ─────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
        bootstrap.Tooltip.getOrCreateInstance(el);
    });
});

/* ── AJAX Helper ─────────────────────────────────────────────── */
/**
 * Sends an AJAX request and returns a parsed JSON promise.
 * @param {string} url
 * @param {string} method  GET | POST
 * @param {FormData|Object|null} body
 */
async function bdrsAjax(url, method = 'GET', body = null) {
    const opts = {
        method,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    };

    if (body) {
        if (body instanceof FormData) {
            opts.body = body;
        } else {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
    }

    const resp = await fetch(url, opts);
    if (!resp.ok) throw new Error(`HTTP ${resp.status}: ${resp.statusText}`);
    return resp.json();
}

/* ── AJAX Form Submission ─────────────────────────────────────── */
/**
 * Attach to any <form data-ajax="true">.
 * Shows a spinner on the submit button, handles success/error responses.
 */
document.addEventListener('submit', async function (e) {
    const form = e.target;
    if (!form.dataset.ajax) return;
    e.preventDefault();

    const submitBtn  = form.querySelector('[type="submit"]');
    const originalTxt = submitBtn ? submitBtn.innerHTML : '';
    const feedbackEl  = form.querySelector('.ajax-feedback');

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing…';
    }

    try {
        const data = await bdrsAjax(form.action, 'POST', new FormData(form));

        if (feedbackEl) {
            feedbackEl.className = `ajax-feedback alert alert-${data.success ? 'success' : 'danger'} mt-2`;
            feedbackEl.textContent = data.message;
            feedbackEl.style.display = 'block';
        }

        if (data.success) {
            if (data.redirect) {
                setTimeout(() => { window.location.href = data.redirect; }, 900);
            } else if (data.reload) {
                setTimeout(() => location.reload(), 900);
            }
            if (form.dataset.resetOnSuccess === 'true') form.reset();
        }
    } catch (err) {
        if (feedbackEl) {
            feedbackEl.className = 'ajax-feedback alert alert-danger mt-2';
            feedbackEl.textContent = 'Network error. Please try again.';
            feedbackEl.style.display = 'block';
        }
        console.error('[BDRS AJAX]', err);
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalTxt;
        }
    }
});

/* ── Auto-refresh sections ────────────────────────────────────── */
/**
 * Elements with data-auto-refresh="url" and data-interval="ms"
 * will have their innerHTML replaced periodically via AJAX.
 */
document.querySelectorAll('[data-auto-refresh]').forEach(el => {
    const url      = el.dataset.autoRefresh;
    const interval = parseInt(el.dataset.interval) || 15000;

    setInterval(async () => {
        try {
            const resp = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (resp.ok) el.innerHTML = await resp.text();
        } catch { /* fail silently on LAN drops */ }
    }, interval);
});

/* ── Confirm-delete buttons ──────────────────────────────────────*/
document.addEventListener('click', e => {
    const btn = e.target.closest('[data-confirm]');
    if (!btn) return;
    if (!confirm(btn.dataset.confirm)) e.preventDefault();
});

/* ── Severity colour helper ───────────────────────────────────── */
const SEVERITY_CLASSES = {
    low:      'badge-severity-low',
    moderate: 'badge-severity-moderate',
    high:     'badge-severity-high',
    critical: 'badge-severity-critical',
};
function severityBadge(level, label) {
    const cls = SEVERITY_CLASSES[level] || '';
    return `<span class="badge ${cls} text-white">${label || level}</span>`;
}

/* ── Status badge helper ──────────────────────────────────────── */
const STATUS_CLASSES = {
    pending:      'badge-status-pending',
    acknowledged: 'badge-status-acknowledged',
    ongoing:      'badge-status-ongoing',
    resolved:     'badge-status-resolved',
    archived:     'badge-status-archived',
};
function statusBadge(status) {
    const cls = STATUS_CLASSES[status] || 'bg-secondary';
    const label = status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    return `<span class="badge ${cls} text-white">${label}</span>`;
}

/* ── Countdown timer (for recent incidents) ───────────────────── */
function startRelativeTimers() {
    document.querySelectorAll('[data-timestamp]').forEach(el => {
        const ts = new Date(el.dataset.timestamp + ' UTC');
        function update() {
            const diff = Math.floor((Date.now() - ts.getTime()) / 1000);
            if (diff < 60)        el.textContent = `${diff}s ago`;
            else if (diff < 3600) el.textContent = `${Math.floor(diff/60)}m ago`;
            else if (diff < 86400)el.textContent = `${Math.floor(diff/3600)}h ago`;
            else                  el.textContent = ts.toLocaleDateString('en-PH', {month:'short',day:'numeric',year:'numeric'});
        }
        update();
        setInterval(update, 15000);
    });
}
document.addEventListener('DOMContentLoaded', startRelativeTimers);

/* ── Character counter for textareas ─────────────────────────── */
document.querySelectorAll('textarea[maxlength]').forEach(ta => {
    const counter = document.createElement('div');
    counter.className = 'text-muted text-end fs-8 mt-1';
    ta.parentNode.appendChild(counter);
    const update = () => {
        const rem = parseInt(ta.maxLength) - ta.value.length;
        counter.textContent = `${rem} characters remaining`;
        counter.classList.toggle('text-danger', rem < 20);
    };
    ta.addEventListener('input', update);
    update();
});

/* ── Image preview before upload ──────────────────────────────── */
document.querySelectorAll('input[type="file"][data-preview]').forEach(input => {
    const container = document.getElementById(input.dataset.preview);
    if (!container) return;
    input.addEventListener('change', () => {
        container.innerHTML = '';
        [...input.files].slice(0, 5).forEach(file => {
            if (!file.type.startsWith('image/')) return;
            const reader = new FileReader();
            reader.onload = e => {
                const img = document.createElement('img');
                img.src = e.target.result;
                img.className = 'img-thumbnail me-1 mb-1';
                img.style.cssText = 'width:80px;height:80px;object-fit:cover;';
                container.appendChild(img);
            };
            reader.readAsDataURL(file);
        });
    });
});

/* ── Table search filter (client-side) ────────────────────────── */
function initTableSearch(inputId, tableId) {
    const input = document.getElementById(inputId);
    const rows  = document.querySelectorAll(`#${tableId} tbody tr`);
    if (!input || !rows.length) return;

    input.addEventListener('input', () => {
        const q = input.value.toLowerCase();
        rows.forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });
}

/* ── Print page ───────────────────────────────────────────────── */
function printPage() { window.print(); }

/* ── Sidebar mobile overlay ───────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    if (window.innerWidth > 768) return;

    const sidebar  = document.getElementById('sidebar');
    const toggle   = document.getElementById('sidebarToggle');
    const overlay  = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    document.body.appendChild(overlay);

    const openSidebar  = () => { sidebar.classList.add('sidebar-open'); overlay.classList.add('show'); };
    const closeSidebar = () => { sidebar.classList.remove('sidebar-open'); overlay.classList.remove('show'); };

    if (toggle) toggle.addEventListener('click', () => {
        sidebar.classList.contains('sidebar-open') ? closeSidebar() : openSidebar();
    });
    overlay.addEventListener('click', closeSidebar);
});
