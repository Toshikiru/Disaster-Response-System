<?php
/**
 * Database Configuration
 * Community Disaster Reporting & Response System
 * Adjust these values to match your local MySQL setup.
 */

define('DB_HOST',    'localhost');
define('DB_NAME',    'barangay_disaster_db');
define('DB_USER',    'root');         // Change to your MySQL username
define('DB_PASS',    '');             // Change to your MySQL password
define('DB_CHARSET', 'utf8mb4');

/**
 * Returns a PDO connection (singleton pattern).
 * Throws a descriptive exception on failure — never exposes credentials.
 */
function db(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,  // Use native prepared statements
            PDO::MYSQL_ATTR_FOUND_ROWS   => true,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Log the real error server-side; show a safe message to the browser
            error_log('[DB ERROR] ' . $e->getMessage());
            die(json_encode([
                'success' => false,
                'message' => 'Database connection failed. Please contact the system administrator.'
            ]));
        }
    }

    return $pdo;
}
