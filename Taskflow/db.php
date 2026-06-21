<?php
declare(strict_types=1);

/*
 * TaskFlow database helper.
 *
 * This file is the central place for all MySQL/XAMPP database work:
 * - creates the taskflow_db database
 * - creates the required tables
 * - seeds the default admin account
 * - saves/loads the dashboard state used by index.js
 *
 * Keeping this logic in one file makes debugging easier because auth.php,
 * api.php, admin.php, setup_database.php, login.php, and index.php all call
 * the same functions instead of duplicating database code.
 */

// XAMPP default credentials. Change these only if phpMyAdmin/MySQL uses a different user/password.
const TASKFLOW_DB_HOST = '127.0.0.1';
const TASKFLOW_DB_NAME = 'taskflow_db';
const TASKFLOW_DB_USER = 'root';
const TASKFLOW_DB_PASS = '';
const TASKFLOW_SESSION_NAME = 'TASKFLOWSESSID';
const TASKFLOW_REMEMBER_EMAIL_COOKIE = 'taskflow_remember_email';
const TASKFLOW_COOKIE_CONSENT_COOKIE = 'taskflow_cookie_consent';

function taskflow_is_https(): bool
{
    /*
     * Local XAMPP normally runs on http://localhost, so secure=false there.
     * If the project is deployed to HTTPS later, secure=true protects cookies
     * from being sent over plain HTTP.
     */
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function taskflow_cookie_options(int $lifetimeSeconds = 0): array
{
    /*
     * Shared cookie settings:
     * - path=/ makes the cookie available to all TaskFlow PHP files
     * - secure is enabled automatically on HTTPS
     * - httponly prevents JavaScript from reading sensitive cookies
     * - SameSite=Lax helps reduce cross-site request attacks while keeping normal navigation working
     */
    return [
        'expires' => $lifetimeSeconds > 0 ? time() + $lifetimeSeconds : 0,
        'path' => '/',
        'secure' => taskflow_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function taskflow_public_cookie_options(int $lifetimeSeconds = 0): array
{
    /*
     * Public cookie options are used only for the consent preference.
     * Consent is not sensitive, and JavaScript needs to read it so the popup
     * can stay hidden after the user makes a choice.
     */
    $options = taskflow_cookie_options($lifetimeSeconds);
    $options['httponly'] = false;
    return $options;
}

function taskflow_start_session(): void
{
    /*
     * Starts the PHP session using secure cookie options.
     * The session stores only trusted server-side identity values like user_id
     * and role. The browser receives only the random session id cookie.
     */
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name(TASKFLOW_SESSION_NAME);
    $cookieOptions = taskflow_cookie_options();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookieOptions['path'],
        'secure' => $cookieOptions['secure'],
        'httponly' => $cookieOptions['httponly'],
        'samesite' => $cookieOptions['samesite'],
    ]);
    session_start();
}

function taskflow_set_remember_email(?string $email): void
{
    /*
     * Safe "Remember Me" cookie.
     * We store only the Gmail address to prefill the login form. We never store
     * passwords, password hashes, admin role, or private task data in cookies.
     *
     * This optional cookie is written only when the user has accepted optional
     * cookies through the consent popup.
     */
    if ($email && taskflow_optional_cookies_allowed()) {
        setcookie(TASKFLOW_REMEMBER_EMAIL_COOKIE, $email, taskflow_cookie_options(60 * 60 * 24 * 30));
        return;
    }

    $options = taskflow_cookie_options();
    $options['expires'] = time() - 3600;
    setcookie(TASKFLOW_REMEMBER_EMAIL_COOKIE, '', $options);
}

function taskflow_remembered_email(): string
{
    /*
     * Reads the safe remember-email cookie for login.php.
     * It validates the value again so a manually edited cookie cannot inject
     * unexpected text into the login input.
     */
    if (!taskflow_optional_cookies_allowed()) {
        taskflow_set_remember_email(null);
        return '';
    }

    $email = strtolower(trim((string) ($_COOKIE[TASKFLOW_REMEMBER_EMAIL_COOKIE] ?? '')));
    return preg_match('/^[A-Z0-9._%+-]+@gmail\.com$/i', $email) ? $email : '';
}

function taskflow_cookie_consent_status(): string
{
    /*
     * Consent values:
     * - accepted: optional cookies like Remember Me are allowed
     * - declined: optional cookies are blocked
     * - unknown: user has not answered the popup yet
     */
    $status = strtolower(trim((string) ($_COOKIE[TASKFLOW_COOKIE_CONSENT_COOKIE] ?? '')));
    return in_array($status, ['accepted', 'declined'], true) ? $status : 'unknown';
}

function taskflow_optional_cookies_allowed(): bool
{
    // Only optional cookies depend on consent. Security/session cookies are required for login.
    return taskflow_cookie_consent_status() === 'accepted';
}

function taskflow_set_cookie_consent(string $status): void
{
    /*
     * Saves the user's cookie preference.
     * If optional cookies are declined, also expire the Remember Me cookie from
     * the server side because it is HttpOnly and JavaScript cannot delete it.
     */
    if (!in_array($status, ['accepted', 'declined'], true)) {
        return;
    }

    setcookie(TASKFLOW_COOKIE_CONSENT_COOKIE, $status, taskflow_public_cookie_options(60 * 60 * 24 * 180));

    if ($status === 'declined') {
        taskflow_set_remember_email(null);
    }
}

function taskflow_no_cache_headers(): void
{
    /*
     * Prevents protected pages from being reused by browser Back/Forward cache.
     *
     * Important: we cannot disable the browser's Back/Forward buttons entirely,
     * but these headers force the browser to re-check PHP. That means:
     * - logged-in users who go back to login.php are redirected to the app
     * - logged-out users who go back to index.php/admin.php are redirected to login
     */
    if (headers_sent()) {
        return;
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}

function taskflow_is_local_request(): bool
{
    /*
     * Live reload is a development helper only.
     * It should run in XAMPP/localhost, but it should not expose file-change
     * signals if the project is later uploaded to an online host.
     */
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?: $host;
    $host = trim($host, '[]');

    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function taskflow_asset_url(string $assetPath): string
{
    /*
     * Adds a filemtime version to local CSS/JS files.
     *
     * Why this matters:
     * Browser cache can keep an old index.css/index.js even after PHP reloads.
     * The ?v= timestamp changes whenever the file changes, forcing the browser
     * to request the newest asset without needing Ctrl+F5.
     */
    $cleanPath = ltrim($assetPath, '/\\');
    $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . $cleanPath;
    $version = is_file($absolutePath) ? (string) filemtime($absolutePath) : (string) time();

    return htmlspecialchars($cleanPath . '?v=' . rawurlencode($version), ENT_QUOTES, 'UTF-8');
}

function taskflow_server_pdo(): PDO
{
    /*
     * Connects to the MySQL server without selecting a database yet.
     * We need this connection first because taskflow_db may not exist during
     * the first run of setup_database.php/login.php/index.php.
     */
    return new PDO(
        'mysql:host=' . TASKFLOW_DB_HOST . ';charset=utf8mb4',
        TASKFLOW_DB_USER,
        TASKFLOW_DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

function taskflow_pdo(): PDO
{
    /*
     * Connects directly to taskflow_db after the installer has created it.
     * Most queries in the app use this connection.
     */
    return new PDO(
        'mysql:host=' . TASKFLOW_DB_HOST . ';dbname=' . TASKFLOW_DB_NAME . ';charset=utf8mb4',
        TASKFLOW_DB_USER,
        TASKFLOW_DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

function taskflow_default_state(): array
{
    /*
     * Starter dashboard data. This gives the app visible sample content after
     * first setup, and it is also used when the admin resets demo records.
     */
    $today = date('Y-m-d');
    return [
        'tasks' => [
            ['id' => 'seed-task-1', 'title' => "Plan today's priorities", 'category' => 'Planning', 'priority' => 'high', 'due' => $today, 'completed' => false],
            ['id' => 'seed-task-2', 'title' => 'Review reminders', 'category' => 'Admin', 'priority' => 'medium', 'due' => $today, 'completed' => true],
        ],
        'reminders' => [
            ['id' => 'seed-reminder-1', 'title' => 'Drink water', 'description' => 'Quick reset before the next work block.', 'type' => 'Health', 'date' => $today, 'time' => '10:00', 'handled' => false],
        ],
        'events' => [
            ['id' => 'seed-event-1', 'title' => 'Weekly review', 'date' => $today, 'color' => '#7c5cff'],
        ],
        'notes' => [
            ['id' => 'seed-note-1', 'title' => 'Ideas', 'category' => 'Personal', 'body' => 'Capture quick ideas here.', 'updated' => round(microtime(true) * 1000)],
        ],
        'workSessions' => [],
        'goals' => [
            ['id' => 'seed-goal-1', 'title' => 'Finish TaskFlow upgrade', 'milestone' => 'Build core pages', 'progress' => 45, 'completed' => false],
        ],
        'habits' => [
            ['id' => 'seed-habit-1', 'title' => 'Deep work', 'streak' => 2, 'dates' => [$today]],
        ],
        'settings' => ['theme' => 'dark', 'notifications' => true],
    ];
}

function taskflow_install_database(): void
{
    /*
     * Auto-installer for XAMPP/phpMyAdmin.
     * This is safe to call many times because CREATE DATABASE/TABLE uses
     * IF NOT EXISTS, so it will not erase existing records.
     */
    $server = taskflow_server_pdo();
    $server->exec('CREATE DATABASE IF NOT EXISTS `' . TASKFLOW_DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = taskflow_pdo();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_state (
            id TINYINT UNSIGNED PRIMARY KEY,
            state_json LONGTEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(180) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin','user') NOT NULL DEFAULT 'user',
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            login_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_login DATETIME NULL,
            last_seen DATETIME NULL,
            failed_login_count INT UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            password_changed_at DATETIME NULL,
            session_version INT UNSIGNED NOT NULL DEFAULT 1,
            admin_session_token VARCHAR(128) NULL,
            admin_session_started DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    taskflow_ensure_user_security_columns($pdo);
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_history (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            email VARCHAR(180) NOT NULL,
            role VARCHAR(20) NULL,
            status VARCHAR(30) NOT NULL,
            message VARCHAR(255) NULL,
            ip_address VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id),
            INDEX (email),
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_action_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_id INT UNSIGNED NULL,
            target_user_id INT UNSIGNED NULL,
            action VARCHAR(80) NOT NULL,
            details TEXT NULL,
            ip_address VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (admin_id),
            INDEX (target_user_id),
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tasks (
            id VARCHAR(80) PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            category VARCHAR(120) DEFAULT 'General',
            priority VARCHAR(20) DEFAULT 'medium',
            due_date DATE NULL,
            completed TINYINT(1) DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reminders (
            id VARCHAR(80) PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            type VARCHAR(120) DEFAULT 'General',
            reminder_date DATE NULL,
            reminder_time TIME NULL,
            handled TINYINT(1) DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS calendar_events (
            id VARCHAR(80) PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            event_date DATE NULL,
            color VARCHAR(20) DEFAULT '#7c5cff'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notes (
            id VARCHAR(80) PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            category VARCHAR(120) DEFAULT 'General',
            body TEXT NULL,
            updated BIGINT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_sessions (
            id VARCHAR(80) PRIMARY KEY,
            session_date DATE NULL,
            minutes INT DEFAULT 0,
            note VARCHAR(255) DEFAULT 'Focused work'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS goals (
            id VARCHAR(80) PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            milestone VARCHAR(255) NULL,
            progress INT DEFAULT 0,
            completed TINYINT(1) DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS habits (
            id VARCHAR(80) PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            streak INT DEFAULT 0,
            dates_json TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Seed app_state only when the table is empty, so user data is not overwritten.
    $exists = (int) $pdo->query('SELECT COUNT(*) FROM app_state')->fetchColumn();
    if ($exists === 0) {
        taskflow_save_state(taskflow_default_state());
    }
    taskflow_seed_admin_user($pdo);
}

function taskflow_column_exists(PDO $pdo, string $table, string $column): bool
{
    /*
     * Checks if a column exists before ALTER TABLE.
     * This lets older TaskFlow databases upgrade automatically without losing data.
     */
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([TASKFLOW_DB_NAME, $table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function taskflow_ensure_user_security_columns(PDO $pdo): void
{
    /*
     * Migration for security/admin analytics columns.
     * Existing databases created before these features will not have the columns,
     * so we add them safely at setup/login time without deleting accounts.
     */
    if (!taskflow_column_exists($pdo, 'users', 'last_seen')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN last_seen DATETIME NULL AFTER last_login');
    }

    if (!taskflow_column_exists($pdo, 'users', 'failed_login_count')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN failed_login_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_seen');
    }

    if (!taskflow_column_exists($pdo, 'users', 'locked_until')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN locked_until DATETIME NULL AFTER failed_login_count');
    }

    if (!taskflow_column_exists($pdo, 'users', 'password_changed_at')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL AFTER locked_until');
        $pdo->exec('UPDATE users SET password_changed_at = COALESCE(password_changed_at, created_at)');
    }

    if (!taskflow_column_exists($pdo, 'users', 'session_version')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER password_changed_at');
    }

    if (!taskflow_column_exists($pdo, 'users', 'admin_session_token')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN admin_session_token VARCHAR(128) NULL AFTER session_version');
    }

    if (!taskflow_column_exists($pdo, 'users', 'admin_session_started')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN admin_session_started DATETIME NULL AFTER admin_session_token');
    }
}

function taskflow_seed_admin_user(PDO $pdo): void
{
    /*
     * Default admin account for testing:
     * Gmail: admin@gmail.com
     * Password: Admin@123
     *
     * If the admin already exists, this function exits early to avoid changing
     * the admin password after the user has updated it.
     */
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute(['admin@gmail.com']);
    if ($stmt->fetch()) {
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, status, password_changed_at) VALUES (?, ?, ?, ?, ?, NOW())');
    $stmt->execute([
        'TaskFlow Admin',
        'admin@gmail.com',
        password_hash('Admin@123', PASSWORD_DEFAULT),
        'admin',
        'active',
    ]);
}

function taskflow_request_ip(): string
{
    // Small helper for login history/admin action logs.
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64);
}

function taskflow_request_user_agent(): string
{
    // Keep the user agent short enough for the database column.
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 255);
}

function taskflow_log_login(?int $userId, string $email, ?string $role, string $status, string $message = ''): void
{
    /*
     * Writes every login attempt/result to login_history.
     * This powers the admin login history table and is useful during debugging.
     */
    try {
        $pdo = taskflow_pdo();
        $stmt = $pdo->prepare('
            INSERT INTO login_history (user_id, email, role, status, message, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$userId, $email, $role, $status, $message, taskflow_request_ip(), taskflow_request_user_agent()]);
    } catch (Throwable $error) {
        error_log('TaskFlow login history failed: ' . $error->getMessage());
    }
}

function taskflow_log_admin_action(?int $adminId, string $action, string $details = '', ?int $targetUserId = null): void
{
    /*
     * Records actions made from admin.php, for example:
     * "Admin changed Juan's password" or "Admin force logged out all users".
     */
    try {
        $pdo = taskflow_pdo();
        $stmt = $pdo->prepare('
            INSERT INTO admin_action_logs (admin_id, target_user_id, action, details, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$adminId, $targetUserId, $action, $details, taskflow_request_ip(), taskflow_request_user_agent()]);
    } catch (Throwable $error) {
        error_log('TaskFlow admin action log failed: ' . $error->getMessage());
    }
}

function taskflow_public_user(array $user): array
{
    // Only send safe fields to JavaScript. Never expose password_hash to the browser.
    return [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];
}

function taskflow_find_user_by_email(string $email): ?array
{
    // Used by login/register/admin checks to find one account by Gmail address.
    $pdo = taskflow_pdo();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function taskflow_find_user_by_id(int $userId): ?array
{
    // Used by PHP sessions to reload the trusted account from the database.
    $pdo = taskflow_pdo();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function taskflow_register_user(string $name, string $email, string $password, string $role = 'user'): array
{
    /*
     * Creates either a normal user or admin user.
     * Passwords are stored with password_hash(), never as plain text.
     */
    $pdo = taskflow_pdo();
    $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, status, password_changed_at) VALUES (?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, 'active']);
    return taskflow_public_user(taskflow_find_user_by_email($email));
}

function taskflow_account_is_locked(array $user): bool
{
    $lockedUntil = (string) ($user['locked_until'] ?? '');
    return $lockedUntil !== '' && strtotime($lockedUntil) !== false && strtotime($lockedUntil) > time();
}

function taskflow_register_failed_login(array $user): array
{
    /*
     * Locks an account for 15 minutes after 5 failed password attempts.
     * Returns the updated failed count and lock state for the caller.
     */
    $pdo = taskflow_pdo();
    $failedCount = (int) ($user['failed_login_count'] ?? 0) + 1;
    $locked = $failedCount >= 5;
    $sql = 'UPDATE users SET failed_login_count = ?, locked_until = ' . ($locked ? 'DATE_ADD(NOW(), INTERVAL 15 MINUTE)' : 'NULL') . ' WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$failedCount, (int) $user['id']]);
    return ['failed_count' => $failedCount, 'locked' => $locked];
}

function taskflow_force_logout_user(int $userId): void
{
    /*
     * Invalidates all active sessions for one account by bumping session_version.
     * If the target is admin, the admin single-session token is also cleared.
     */
    $pdo = taskflow_pdo();
    $stmt = $pdo->prepare('
        UPDATE users
        SET session_version = session_version + 1,
            admin_session_token = NULL,
            admin_session_started = NULL
        WHERE id = ?
    ');
    $stmt->execute([$userId]);
}

function taskflow_force_logout_role(string $role): int
{
    /*
     * Invalidates every account with the selected role.
     * Used by admin controls for "force logout all users/admins".
     */
    $pdo = taskflow_pdo();
    $stmt = $pdo->prepare('
        UPDATE users
        SET session_version = session_version + 1,
            admin_session_token = NULL,
            admin_session_started = NULL
        WHERE role = ?
    ');
    $stmt->execute([$role]);
    return $stmt->rowCount();
}

function taskflow_login_session(array $user): void
{
    /*
     * Creates the trusted server-side login state.
     * session_regenerate_id(true) is important after login because it prevents
     * session fixation: the user gets a fresh session id after authentication.
     */
    taskflow_start_session();
    session_regenerate_id(true);
    $_SESSION['taskflow_user_id'] = (int) $user['id'];
    $_SESSION['taskflow_user_role'] = $user['role'];
    $_SESSION['taskflow_session_version'] = (int) ($user['session_version'] ?? 1);

    if ($user['role'] === 'admin') {
        /*
         * Admin-only single active session:
         * every admin login creates a new token and saves it to the database.
         * Any older admin session still has the old token, so it becomes invalid
         * on its next request.
         */
        $adminToken = bin2hex(random_bytes(32));
        $pdo = taskflow_pdo();
        $stmt = $pdo->prepare('UPDATE users SET admin_session_token = ?, admin_session_started = NOW() WHERE id = ? AND role = ?');
        $stmt->execute([$adminToken, (int) $user['id'], 'admin']);
        $_SESSION['taskflow_admin_id'] = (int) $user['id'];
        $_SESSION['taskflow_admin_session_token'] = $adminToken;
    } else {
        unset($_SESSION['taskflow_admin_id'], $_SESSION['taskflow_admin_session_token']);
    }
}

function taskflow_admin_session_matches(array $user): bool
{
    /*
     * Validates that this admin browser still owns the latest token stored in DB.
     * Normal users do not use this check, so they can still login on multiple devices.
     */
    if ($user['role'] !== 'admin') {
        return true;
    }

    $sessionToken = (string) ($_SESSION['taskflow_admin_session_token'] ?? '');
    $databaseToken = (string) ($user['admin_session_token'] ?? '');
    return $sessionToken !== '' && $databaseToken !== '' && hash_equals($databaseToken, $sessionToken);
}

function taskflow_current_user(): ?array
{
    /*
     * Returns the currently logged-in user based on PHP session data.
     * The database is checked every request so deactivated/deleted users lose
     * access immediately.
     */
    taskflow_start_session();
    $userId = (int) ($_SESSION['taskflow_user_id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }

    $user = taskflow_find_user_by_id($userId);
    $sessionVersion = (int) ($_SESSION['taskflow_session_version'] ?? 0);
    $databaseVersion = (int) ($user['session_version'] ?? 1);
    if ($sessionVersion === 0 && $user) {
        // Migration fallback for sessions created before session_version existed.
        $_SESSION['taskflow_session_version'] = $databaseVersion;
        $sessionVersion = $databaseVersion;
    }

    if (!$user || $user['status'] !== 'active' || $sessionVersion !== $databaseVersion || !taskflow_admin_session_matches($user)) {
        unset($_SESSION['taskflow_user_id'], $_SESSION['taskflow_user_role'], $_SESSION['taskflow_session_version'], $_SESSION['taskflow_admin_id'], $_SESSION['taskflow_admin_session_token']);
        return null;
    }

    $pdo = taskflow_pdo();
    $stmt = $pdo->prepare('UPDATE users SET last_seen = NOW() WHERE id = ?');
    $stmt->execute([$userId]);
    $user['last_seen'] = date('Y-m-d H:i:s');
    return $user;
}

function taskflow_require_user(): array
{
    /*
     * Page guard for index.php/api.php.
     * If no valid PHP session exists, the browser is sent back to login.php.
     */
    $user = taskflow_current_user();
    if (!$user) {
        header('Location: login.php');
        exit;
    }

    return $user;
}

function taskflow_require_admin(): array
{
    /*
     * Page guard for admin.php.
     * Normal users may have a valid session, but they still cannot open admin.php.
     */
    $user = taskflow_require_user();
    if ($user['role'] !== 'admin') {
        header('Location: index.php');
        exit;
    }

    $_SESSION['taskflow_admin_id'] = (int) $user['id'];
    return $user;
}

function taskflow_logout_session(): void
{
    /*
     * Clears all server-side login data and expires the session id cookie.
     * The remember-email cookie is intentionally not removed because it stores
     * only a Gmail address for convenience, not an authentication secret.
     */
    taskflow_start_session();
    $adminId = (int) ($_SESSION['taskflow_admin_id'] ?? 0);
    $adminToken = (string) ($_SESSION['taskflow_admin_session_token'] ?? '');
    if ($adminId > 0 && $adminToken !== '') {
        try {
            $pdo = taskflow_pdo();
            /*
             * Clear the database token only when this browser still owns the
             * latest admin token. This prevents an older invalidated session
             * from logging out the newer active admin session.
             */
            $stmt = $pdo->prepare('UPDATE users SET admin_session_token = NULL, admin_session_started = NULL WHERE id = ? AND role = ? AND admin_session_token = ?');
            $stmt->execute([$adminId, 'admin', $adminToken]);
        } catch (Throwable $error) {
            error_log('TaskFlow admin session cleanup failed: ' . $error->getMessage());
        }
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $options = taskflow_cookie_options();
        $options['expires'] = time() - 3600;
        setcookie(session_name(), '', $options);
    }

    session_destroy();
}

function taskflow_record_login(int $userId): void
{
    // Tracks login count and last login time for the admin analytics table.
    $pdo = taskflow_pdo();
    $stmt = $pdo->prepare('
        UPDATE users
        SET login_count = login_count + 1,
            last_login = NOW(),
            last_seen = NOW(),
            failed_login_count = 0,
            locked_until = NULL,
            status = ?
        WHERE id = ?
    ');
    $stmt->execute(['active', $userId]);
}

function taskflow_load_state(): array
{
    /*
     * Loads the JSON dashboard state for index.js.
     * If JSON is missing/corrupted, fallback data prevents the app from going blank.
     */
    taskflow_install_database();
    $pdo = taskflow_pdo();
    $row = $pdo->query('SELECT state_json FROM app_state WHERE id = 1')->fetch();
    if (!$row) {
        return taskflow_default_state();
    }
    $state = json_decode($row['state_json'], true);
    return is_array($state) ? $state : taskflow_default_state();
}

function taskflow_save_state(array $state): void
{
    /*
     * Saves the full dashboard state as JSON, then mirrors the same data into
     * separate tables so phpMyAdmin/admin analytics can inspect counts clearly.
     */
    $pdo = taskflow_pdo();
    $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare('REPLACE INTO app_state (id, state_json) VALUES (1, :state_json)');
    $stmt->execute(['state_json' => $json]);
    taskflow_sync_tables($state);
}

function taskflow_sync_tables(array $state): void
{
    /*
     * Mirrors the JavaScript state into normal SQL tables.
     * We delete and re-insert these records because the front-end sends the
     * complete latest state after each save, making this simple and predictable.
     */
    $pdo = taskflow_pdo();
    $pdo->beginTransaction();
    try {
        foreach (['tasks', 'reminders', 'calendar_events', 'notes', 'work_sessions', 'goals', 'habits'] as $table) {
            $pdo->exec("DELETE FROM {$table}");
        }

        $stmt = $pdo->prepare('INSERT INTO tasks (id, title, category, priority, due_date, completed) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($state['tasks'] ?? [] as $item) {
            $stmt->execute([$item['id'], $item['title'], $item['category'] ?? 'General', $item['priority'] ?? 'medium', $item['due'] ?: null, !empty($item['completed']) ? 1 : 0]);
        }

        $stmt = $pdo->prepare('INSERT INTO reminders (id, title, description, type, reminder_date, reminder_time, handled) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($state['reminders'] ?? [] as $item) {
            $stmt->execute([$item['id'], $item['title'], $item['description'] ?? '', $item['type'] ?? 'General', $item['date'] ?: null, $item['time'] ?: null, !empty($item['handled']) ? 1 : 0]);
        }

        $stmt = $pdo->prepare('INSERT INTO calendar_events (id, title, event_date, color) VALUES (?, ?, ?, ?)');
        foreach ($state['events'] ?? [] as $item) {
            $stmt->execute([$item['id'], $item['title'], $item['date'] ?: null, $item['color'] ?? '#7c5cff']);
        }

        $stmt = $pdo->prepare('INSERT INTO notes (id, title, category, body, updated) VALUES (?, ?, ?, ?, ?)');
        foreach ($state['notes'] ?? [] as $item) {
            $stmt->execute([$item['id'], $item['title'], $item['category'] ?? 'General', $item['body'] ?? '', $item['updated'] ?? null]);
        }

        $stmt = $pdo->prepare('INSERT INTO work_sessions (id, session_date, minutes, note) VALUES (?, ?, ?, ?)');
        foreach ($state['workSessions'] ?? [] as $item) {
            $stmt->execute([$item['id'], $item['date'] ?: null, (int) ($item['minutes'] ?? 0), $item['note'] ?? 'Focused work']);
        }

        $stmt = $pdo->prepare('INSERT INTO goals (id, title, milestone, progress, completed) VALUES (?, ?, ?, ?, ?)');
        foreach ($state['goals'] ?? [] as $item) {
            $stmt->execute([$item['id'], $item['title'], $item['milestone'] ?? '', (int) ($item['progress'] ?? 0), !empty($item['completed']) ? 1 : 0]);
        }

        $stmt = $pdo->prepare('INSERT INTO habits (id, title, streak, dates_json) VALUES (?, ?, ?, ?)');
        foreach ($state['habits'] ?? [] as $item) {
            $stmt->execute([$item['id'], $item['title'], (int) ($item['streak'] ?? 0), json_encode($item['dates'] ?? [])]);
        }

        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}
