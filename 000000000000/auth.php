<?php
declare(strict_types=1);
/*
 * Authentication endpoint for TaskFlow.
 *
 * login.js sends JSON requests here for:
 * - register
 * - login
 * - update_profile
 * - change_password
 *
 * This file returns JSON only. It does not render HTML pages.
 */
require_once __DIR__ . '/db.php';
taskflow_no_cache_headers();
taskflow_start_session();

header('Content-Type: application/json; charset=utf-8');

function taskflow_json(array $payload, int $status = 200): void
{
    // Standard JSON response helper so every branch exits with the same format.
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function taskflow_valid_gmail(string $email): bool
{
    // Requirement: users/admins must use Gmail accounts only.
    return (bool) preg_match('/^[A-Z0-9._%+-]+@gmail\.com$/i', $email);
}

function taskflow_strong_password(string $password): bool
{
    // Requirement: at least 8 characters, one number, and one special character.
    return strlen($password) >= 8
        && preg_match('/[0-9]/', $password)
        && preg_match('/[^A-Za-z0-9]/', $password);
}

try {
    // Ensure database/tables exist before processing any account request.
    taskflow_install_database();

    // JSON body from fetch("auth.php") in login.js.
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        taskflow_json(['ok' => false, 'message' => 'Invalid request.'], 422);
    }

    $action = $payload['action'] ?? '';
    $email = strtolower(trim((string) ($payload['email'] ?? '')));
    $password = (string) ($payload['password'] ?? '');
    $remember = !empty($payload['remember']);

    if ($action === 'cookie_consent') {
        /*
         * Saves the user's cookie decision from the popup.
         * This affects optional cookies only. Session cookies remain required
         * because they protect login/admin access.
         */
        $consent = strtolower(trim((string) ($payload['consent'] ?? '')));
        if (!in_array($consent, ['accepted', 'declined'], true)) {
            taskflow_json(['ok' => false, 'message' => 'Invalid cookie consent choice.'], 422);
        }
        taskflow_set_cookie_consent($consent);
        taskflow_json(['ok' => true, 'consent' => $consent]);
    }

    // Validate Gmail early for both registration and login.
    if (in_array($action, ['register', 'login'], true) && !taskflow_valid_gmail($email)) {
        taskflow_json(['ok' => false, 'message' => 'Use a valid Gmail address.'], 422);
    }

    if ($action === 'register') {
        /*
         * Normal public registration always creates a role=user account.
         * Admin accounts are created inside admin.php by an existing admin.
         */
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            $name = ucfirst(strstr($email, '@', true));
        }
        if (!taskflow_strong_password($password)) {
            taskflow_json(['ok' => false, 'message' => 'Password must be 8+ characters with a number and special character.'], 422);
        }
        if (taskflow_find_user_by_email($email)) {
            taskflow_json(['ok' => false, 'message' => 'This Gmail account is already registered.'], 409);
        }
        $user = taskflow_register_user($name, $email, $password);
        taskflow_record_login($user['id']);
        $freshUser = taskflow_find_user_by_id((int) $user['id']);
        if (!$freshUser) {
            taskflow_json(['ok' => false, 'message' => 'Account was created but session could not be started.'], 500);
        }
        taskflow_login_session($freshUser);
        taskflow_set_remember_email($remember ? $email : null);
        taskflow_log_login((int) $user['id'], $email, $freshUser['role'], 'success', 'Registered and logged in.');
        taskflow_json(['ok' => true, 'user' => $user]);
    }

    if ($action === 'login') {
        /*
         * Login checks:
         * 1. account exists
         * 2. password matches password_hash()
         * 3. account is active
         * 4. every valid login gets a PHP session; admin role can access admin.php
         */
        $user = taskflow_find_user_by_email($email);
        if (!$user) {
            taskflow_log_login(null, $email, null, 'failed', 'Unknown Gmail account.');
            taskflow_json(['ok' => false, 'message' => 'Invalid Gmail or password.'], 401);
        }
        if (taskflow_account_is_locked($user)) {
            taskflow_log_login((int) $user['id'], $email, $user['role'], 'locked', 'Login blocked because the account is temporarily locked.');
            taskflow_json(['ok' => false, 'message' => 'This account is locked after too many failed login attempts. Try again later.'], 423);
        }
        if ($user['status'] !== 'active') {
            taskflow_log_login((int) $user['id'], $email, $user['role'], 'failed', 'Inactive account attempted login.');
            taskflow_json(['ok' => false, 'message' => 'This account is inactive. Contact the admin.'], 403);
        }
        if (!password_verify($password, $user['password_hash'])) {
            $failed = taskflow_register_failed_login($user);
            taskflow_log_login((int) $user['id'], $email, $user['role'], $failed['locked'] ? 'locked' : 'failed', 'Invalid password attempt ' . $failed['failed_count'] . '.');
            if ($failed['locked']) {
                taskflow_json(['ok' => false, 'message' => 'Too many failed attempts. Account locked for 15 minutes.'], 423);
            }
            taskflow_json(['ok' => false, 'message' => 'Invalid Gmail or password.'], 401);
        }
        taskflow_record_login((int) $user['id']);
        taskflow_login_session($user);
        taskflow_set_remember_email($remember ? $email : null);
        taskflow_log_login((int) $user['id'], $email, $user['role'], 'success', 'Login successful.');
        taskflow_json(['ok' => true, 'user' => taskflow_public_user($user)]);
    }

    if ($action === 'update_profile') {
        // Users may update their display name only. Gmail stays locked.
        $sessionUser = taskflow_current_user();
        if (!$sessionUser) {
            taskflow_json(['ok' => false, 'message' => 'Login session expired. Please login again.'], 401);
        }
        $userId = (int) $sessionUser['id'];
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            taskflow_json(['ok' => false, 'message' => 'Name is required.'], 422);
        }
        $pdo = taskflow_pdo();
        $stmt = $pdo->prepare('UPDATE users SET name = ? WHERE id = ?');
        $stmt->execute([$name, $userId]);
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        taskflow_json(['ok' => true, 'user' => taskflow_public_user($stmt->fetch())]);
    }

    if ($action === 'change_password') {
        // Password change requires the current password so another user cannot change it silently.
        $sessionUser = taskflow_current_user();
        if (!$sessionUser) {
            taskflow_json(['ok' => false, 'message' => 'Login session expired. Please login again.'], 401);
        }
        $userId = (int) $sessionUser['id'];
        $currentPassword = (string) ($payload['current_password'] ?? '');
        $newPassword = (string) ($payload['new_password'] ?? '');
        if (!taskflow_strong_password($newPassword)) {
            taskflow_json(['ok' => false, 'message' => 'New password must be 8+ characters with a number and special character.'], 422);
        }
        $pdo = taskflow_pdo();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            taskflow_json(['ok' => false, 'message' => 'Current password is incorrect.'], 401);
        }
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW(), session_version = session_version + 1 WHERE id = ?');
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
        $_SESSION['taskflow_session_version'] = (int) ($_SESSION['taskflow_session_version'] ?? 1) + 1;
        taskflow_json(['ok' => true]);
    }

    taskflow_json(['ok' => false, 'message' => 'Unknown action.'], 400);
} catch (Throwable $error) {
    // During debugging, this message helps identify database or PHP errors from the browser response.
    taskflow_json(['ok' => false, 'message' => $error->getMessage()], 500);
}
