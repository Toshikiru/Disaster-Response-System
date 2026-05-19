<?php
/**
 * Application Configuration
 * Community Disaster Reporting & Response System
 */

// ── Base Paths ──────────────────────────────────────────────────────────────
define('APP_ROOT',    dirname(__DIR__));                   // /path/to/project
define('APP_URL',     'http://localhost/barangay-disaster-system'); // Change for production
define('UPLOAD_DIR',  APP_ROOT . '/uploads/');

// ── Upload Limits ────────────────────────────────────────────────────────────
define('MAX_FILE_SIZE',      5 * 1024 * 1024);  // 5 MB per file
define('MAX_FILES_PER_INC',  5);                 // Max photos per incident
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

// ── Session ──────────────────────────────────────────────────────────────────
define('SESSION_LIFETIME',   3600 * 4);   // 4 hours of inactivity = logout
define('SESSION_NAME',       'BDRS_SESSION');

// ── Roles (match roles.id in database) ──────────────────────────────────────
define('ROLE_RESIDENT',   1);
define('ROLE_OFFICIAL',   2);
define('ROLE_RESPONDER',  3);
define('ROLE_ADMIN',      4);

// ── Reference Number Prefixes ─────────────────────────────────────────────
define('REF_INCIDENT', 'INC');
define('REF_RESCUE',   'RES');
define('REF_RELIEF',   'REL');
define('REF_MISSING',  'MPS');

// ── Severity Colours (for UI badges) ─────────────────────────────────────
define('SEVERITY_COLORS', [
    'low'      => ['bg' => '#198754', 'label' => 'Low'],
    'moderate' => ['bg' => '#fd7e14', 'label' => 'Moderate'],
    'high'     => ['bg' => '#dc3545', 'label' => 'High'],
    'critical' => ['bg' => '#6f1111', 'label' => 'CRITICAL'],
]);

// ── Incident Types ────────────────────────────────────────────────────────
define('INCIDENT_TYPES', [
    'flood'               => ['label' => 'Flood',               'icon' => 'bi-water'],
    'fire'                => ['label' => 'Fire',                'icon' => 'bi-fire'],
    'earthquake'          => ['label' => 'Earthquake',          'icon' => 'bi-activity'],
    'landslide'           => ['label' => 'Landslide',           'icon' => 'bi-layer-backward'],
    'typhoon'             => ['label' => 'Typhoon',             'icon' => 'bi-wind'],
    'accident'            => ['label' => 'Accident',            'icon' => 'bi-car-front'],
    'medical_emergency'   => ['label' => 'Medical Emergency',   'icon' => 'bi-hospital'],
    'structural_collapse' => ['label' => 'Structural Collapse', 'icon' => 'bi-building-x'],
    'storm_surge'         => ['label' => 'Storm Surge',         'icon' => 'bi-tsunami'],
    'drought'             => ['label' => 'Drought',             'icon' => 'bi-sun'],
    'other'               => ['label' => 'Other',               'icon' => 'bi-exclamation-circle'],
]);

// ── Timezone ──────────────────────────────────────────────────────────────
date_default_timezone_set('Asia/Manila');

// ── Error reporting (set to 0 / E_NONE in production) ────────────────────
if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
    error_reporting(0);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}
