# Community Disaster Reporting & Response System (BDRS)
## Installation & Setup Guide

---

## Requirements
- PHP 7.4+ (PHP 8.x recommended)
- MySQL 5.7+ or MariaDB 10.3+
- Apache 2.4+ with `mod_rewrite` enabled
- XAMPP / WAMP / LAMP (for local/LAN deployment)

---

## Installation Steps

### 1. Copy files to web server
Place the entire `barangay-disaster-system/` folder inside your web root:
- **XAMPP (Windows):** `C:/xampp/htdocs/barangay-disaster-system/`
- **LAMP (Linux):** `/var/www/html/barangay-disaster-system/`

### 2. Create the database
1. Open **phpMyAdmin** → go to http://localhost/phpmyadmin
2. Click **New** → create a database named `barangay_disaster_db`
3. Select the database → click **Import**
4. Upload `database/schema.sql` and click **Go**

### 3. Configure database connection
Edit `config/database.php` and update:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'barangay_disaster_db');
define('DB_USER', 'root');       // Your MySQL username
define('DB_PASS', '');           // Your MySQL password
```

### 4. Configure the application URL
Edit `config/config.php`:
```php
define('APP_URL', 'http://localhost/barangay-disaster-system');
```
For LAN access, replace `localhost` with your server's local IP:
```php
define('APP_URL', 'http://192.168.1.100/barangay-disaster-system');
```

### 5. Set folder permissions
Ensure the `uploads/` directory is writable:
```bash
chmod -R 755 uploads/
```

### 6. Create upload directories
The following directories must exist (created automatically on first use):
```
uploads/incidents/
uploads/profiles/
uploads/missing/
```

### 7. Access the system
- Open your browser: `http://localhost/barangay-disaster-system/`
- **Default Admin Login:**
  - Username: `admin`
  - Password: *(set via phpMyAdmin — see step 8)*

### 8. Set the admin password
Run this in phpMyAdmin SQL tab (replace `YourSecurePassword` with a strong password):
```sql
UPDATE users
SET password_hash = '$2y$12$' || SUBSTRING(MD5(RAND()), 1, 22) || 'PLACEHOLDER'
WHERE username = 'admin';
```
Better: Use PHP to generate a proper hash:
```php
echo password_hash('YourSecurePassword', PASSWORD_BCRYPT, ['cost' => 12]);
```
Then update:
```sql
UPDATE users SET password_hash = 'the_hash_above' WHERE username = 'admin';
```

---

## Default System Settings (configure via Settings page)
| Setting | Default Value |
|---------|--------------|
| Barangay Name | Sample Barangay |
| Municipality | Sample Municipality |
| Province | Sample Province |
| Emergency Hotline | 0917-000-0000 |

---

## User Roles
| Role | Access |
|------|--------|
| **Admin** | Full access, system settings, user management |
| **Barangay Official** | Manage incidents, announcements, relief, responders |
| **Responder** | View and update assigned incidents/rescues |
| **Resident** | Report incidents, request rescue, view announcements |

---

## LAN / Offline Setup
1. Set your server PC as a static IP (e.g., `192.168.1.100`)
2. Update `APP_URL` to use the LAN IP
3. Connect all devices to the same Wi-Fi router
4. Access from any device: `http://192.168.1.100/barangay-disaster-system/`
5. No internet required — the system works fully offline on LAN

---

## Directory Structure
```
barangay-disaster-system/
├── config/
│   ├── config.php          # App constants and settings
│   └── database.php        # PDO connection
├── includes/
│   ├── auth.php            # Session, CSRF, helpers
│   ├── header.php          # Global HTML header + sidebar
│   ├── footer.php          # Global HTML footer + scripts
│   └── 403.php             # Access denied page
├── assets/
│   ├── css/
│   │   ├── main.css        # Main stylesheet
│   │   └── sidebar.css     # Sidebar styles
│   └── js/
│       └── main.js         # Application JavaScript
├── modules/
│   ├── auth/               # Login, logout, register, profile
│   ├── dashboard/          # Main dashboard
│   ├── incidents/          # Incident CRUD
│   ├── rescue/             # Rescue requests
│   ├── announcements/      # Barangay announcements
│   ├── evacuation/         # Evacuation centers
│   ├── relief/             # Relief distribution
│   ├── missing/            # Missing persons
│   ├── responders/         # Responder management
│   ├── reports/            # Analytics & reports
│   ├── settings/           # System settings & logs
│   └── notifications/      # Notification endpoints
├── uploads/                # User-uploaded files
├── database/
│   └── schema.sql          # Complete DB schema
├── .htaccess               # Apache security rules
└── index.php               # Login page (entry point)
```

---

## Security Notes
- All passwords are bcrypt-hashed (cost factor 12)
- All DB queries use PDO prepared statements (SQL injection safe)
- CSRF tokens on all state-changing forms
- Session timeout after 4 hours of inactivity
- Role-based access control on every protected page
- File uploads are MIME-type validated (images only)
- Input is sanitized with `htmlspecialchars()` throughout

---

## Support & Troubleshooting

**Blank page / error?**
- Enable PHP errors temporarily in `config/config.php`: `error_reporting(E_ALL);`
- Check the Apache error log

**Cannot connect to database?**
- Verify MySQL is running in XAMPP
- Double-check `config/database.php` credentials

**Upload fails?**
- Check that `uploads/` folder permissions are set to 755
- Confirm `upload_max_filesize` in `php.ini` is ≥ 5M

---

*BDRS v1.0 — For local/LAN deployment in Philippine barangays*
