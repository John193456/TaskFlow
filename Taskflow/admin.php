<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
taskflow_no_cache_headers();
taskflow_start_session();

/*
 * TaskFlow admin page.
 *
 * This page is the control center for account security and user monitoring:
 * - account statistics
 * - search/filter/sort users
 * - force logout controls
 * - online/offline status
 * - failed login lock visibility
 * - login history
 * - admin action logs
 */

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function taskflow_admin_fetch_user(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function taskflow_admin_user_label(?array $user): string
{
    if (!$user) {
        return 'Unknown account';
    }

    return $user['name'] . ' <' . $user['email'] . '>';
}

$notice = '';
$redirectToLogin = false;

try {
    taskflow_install_database();
    $adminSessionUser = taskflow_require_admin();
    $pdo = taskflow_pdo();
    $adminId = (int) $adminSessionUser['id'];

    /*
     * POST actions are processed before loading analytics so the page refreshes
     * with the latest numbers after every admin command.
     */
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'toggle_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $status = ($_POST['status'] ?? '') === 'active' ? 'active' : 'inactive';
        $target = taskflow_admin_fetch_user($pdo, $userId);
        if ($target && $target['role'] !== 'admin') {
            $stmt = $pdo->prepare('UPDATE users SET status = ? WHERE id = ? AND role != ?');
            $stmt->execute([$status, $userId, 'admin']);
            taskflow_log_admin_action($adminId, 'account_status', 'Admin set ' . taskflow_admin_user_label($target) . ' to ' . $status . '.', $userId);
            $notice = 'Account status updated.';
        }
    }

    if ($action === 'delete_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $target = taskflow_admin_fetch_user($pdo, $userId);
        if ($target && $target['role'] !== 'admin') {
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ? AND role != ?');
            $stmt->execute([$userId, 'admin']);
            taskflow_log_admin_action($adminId, 'delete_user', 'Admin deleted ' . taskflow_admin_user_label($target) . '.', $userId);
            $notice = 'User account deleted.';
        }
    }

    if ($action === 'unlock_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $target = taskflow_admin_fetch_user($pdo, $userId);
        if ($target) {
            $stmt = $pdo->prepare('UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = ?');
            $stmt->execute([$userId]);
            taskflow_log_admin_action($adminId, 'unlock_account', 'Admin unlocked ' . taskflow_admin_user_label($target) . '.', $userId);
            $notice = 'Account lock cleared.';
        }
    }

    if ($action === 'force_logout_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $target = taskflow_admin_fetch_user($pdo, $userId);
        if ($target) {
            taskflow_force_logout_user($userId);
            taskflow_log_login($userId, $target['email'], $target['role'], 'forced_logout', 'Force logout by admin.');
            taskflow_log_admin_action($adminId, 'force_logout_user', 'Admin force logged out ' . taskflow_admin_user_label($target) . '.', $userId);
            $notice = 'Account sessions were forced out.';
            if ($userId === $adminId) {
                taskflow_logout_session();
                header('Location: login.php?forced=1');
                exit;
            }
        }
    }

    if ($action === 'force_logout_role') {
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'user';
        $count = taskflow_force_logout_role($role);
        taskflow_log_admin_action($adminId, 'force_logout_role', 'Admin force logged out ' . $count . ' ' . $role . ' account session(s).');
        $notice = 'All ' . $role . ' sessions were forced out.';
        if ($role === 'admin') {
            taskflow_logout_session();
            header('Location: login.php?forced=1');
            exit;
        }
    }

    if ($action === 'create_admin') {
        $name = trim((string) ($_POST['new_admin_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['new_admin_email'] ?? '')));
        $password = (string) ($_POST['new_admin_password'] ?? '');
        if ($name === '' || !preg_match('/^[A-Z0-9._%+-]+@gmail\.com$/i', $email)) {
            $notice = 'New admin needs a name and valid Gmail address.';
        } elseif (taskflow_find_user_by_email($email)) {
            $notice = 'That Gmail account already exists.';
        } elseif (strlen($password) < 8 || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
            $notice = 'New admin password must be 8+ characters with a number and special character.';
        } else {
            $created = taskflow_register_user($name, $email, $password, 'admin');
            taskflow_log_admin_action($adminId, 'create_admin', 'Admin created a new admin account for ' . $name . ' <' . $email . '>.', (int) $created['id']);
            $notice = 'New admin account created.';
        }
    }

    if ($action === 'admin_settings') {
        $name = trim((string) ($_POST['admin_name'] ?? ''));
        $newPassword = (string) ($_POST['admin_new_password'] ?? '');
        if ($name !== '') {
            $stmt = $pdo->prepare('UPDATE users SET name = ? WHERE id = ? AND role = ?');
            $stmt->execute([$name, $adminId, 'admin']);
            taskflow_log_admin_action($adminId, 'admin_settings', 'Admin changed own display name to ' . $name . '.', $adminId);
            $notice = 'Admin username updated.';
        }
        if ($newPassword !== '') {
            if (strlen($newPassword) < 8 || !preg_match('/[0-9]/', $newPassword) || !preg_match('/[^A-Za-z0-9]/', $newPassword)) {
                $notice = 'Admin password must be 8+ characters with a number and special character.';
            } else {
                $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW(), session_version = session_version + 1 WHERE id = ? AND role = ?');
                $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $adminId, 'admin']);
                $_SESSION['taskflow_session_version'] = (int) ($_SESSION['taskflow_session_version'] ?? 1) + 1;
                taskflow_log_admin_action($adminId, 'admin_password', 'Admin changed own password.', $adminId);
                $notice = 'Admin password updated.';
            }
        }
    }

    if ($action === 'reset_user_password') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $target = taskflow_admin_fetch_user($pdo, $userId);
        $newPassword = (string) ($_POST['new_password'] ?? '');
        if (strlen($newPassword) < 8 || !preg_match('/[0-9]/', $newPassword) || !preg_match('/[^A-Za-z0-9]/', $newPassword)) {
            $notice = 'User password must be 8+ characters with a number and special character.';
        } elseif ($target && $target['role'] !== 'admin') {
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = NOW(), session_version = session_version + 1 WHERE id = ? AND role != ?');
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId, 'admin']);
            taskflow_log_admin_action($adminId, 'reset_password', 'Admin changed ' . taskflow_admin_user_label($target) . ' password.', $userId);
            $notice = 'User password updated by admin.';
        }
    }

    if ($action === 'reset') {
        taskflow_save_state(taskflow_default_state());
        taskflow_log_admin_action($adminId, 'reset_demo_data', 'Admin restored demo TaskFlow records.');
        $notice = 'Demo TaskFlow data has been restored.';
    }

    $adminStmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $adminStmt->execute([$adminId]);
    $adminUser = $adminStmt->fetch();

    $allUsers = $pdo->query("
        SELECT *,
            CASE WHEN last_seen IS NOT NULL AND last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 1 ELSE 0 END AS is_online,
            CASE WHEN locked_until IS NOT NULL AND locked_until > NOW() THEN 1 ELSE 0 END AS is_locked
        FROM users
        ORDER BY created_at DESC
    ")->fetchAll();

    $search = trim((string) ($_GET['q'] ?? ''));
    $roleFilter = (string) ($_GET['role'] ?? 'all');
    $statusFilter = (string) ($_GET['status'] ?? 'all');
    $onlineFilter = (string) ($_GET['online'] ?? 'all');
    $sort = (string) ($_GET['sort'] ?? 'created_at');
    $dir = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $sortMap = [
        'name' => 'name',
        'email' => 'email',
        'role' => 'role',
        'status' => 'status',
        'login_count' => 'login_count',
        'created_at' => 'created_at',
        'last_login' => 'last_login',
        'password_changed_at' => 'password_changed_at',
    ];
    $sortColumn = $sortMap[$sort] ?? 'created_at';

    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = '(name LIKE ? OR email LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    if (in_array($roleFilter, ['admin', 'user'], true)) {
        $where[] = 'role = ?';
        $params[] = $roleFilter;
    }
    if (in_array($statusFilter, ['active', 'inactive'], true)) {
        $where[] = 'status = ?';
        $params[] = $statusFilter;
    }
    if ($onlineFilter === 'online') {
        $where[] = 'last_seen IS NOT NULL AND last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)';
    } elseif ($onlineFilter === 'offline') {
        $where[] = '(last_seen IS NULL OR last_seen < DATE_SUB(NOW(), INTERVAL 5 MINUTE))';
    } elseif ($onlineFilter === 'locked') {
        $where[] = 'locked_until IS NOT NULL AND locked_until > NOW()';
    }

    $sql = "
        SELECT *,
            CASE WHEN last_seen IS NOT NULL AND last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 1 ELSE 0 END AS is_online,
            CASE WHEN locked_until IS NOT NULL AND locked_until > NOW() THEN 1 ELSE 0 END AS is_locked
        FROM users
        " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
        ORDER BY {$sortColumn} {$dir}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    $totalUsers = count($allUsers);
    $adminAccounts = count(array_filter($allUsers, fn($user) => $user['role'] === 'admin'));
    $activeUsers = count(array_filter($allUsers, fn($user) => $user['status'] === 'active'));
    $inactiveUsers = $totalUsers - $activeUsers;
    $onlineUsers = count(array_filter($allUsers, fn($user) => (int) $user['is_online'] === 1));
    $lockedAccounts = count(array_filter($allUsers, fn($user) => (int) $user['is_locked'] === 1));
    $activeAdminSessions = count(array_filter($allUsers, fn($user) => $user['role'] === 'admin' && !empty($user['admin_session_token'])));
    $totalLogins = array_sum(array_map(fn($user) => (int) $user['login_count'], $allUsers));

    $tables = ['tasks', 'reminders', 'calendar_events', 'notes', 'work_sessions', 'goals', 'habits'];
    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    $loginHistory = $pdo->query("
        SELECT lh.*, u.name AS user_name
        FROM login_history lh
        LEFT JOIN users u ON u.id = lh.user_id
        ORDER BY lh.created_at DESC
        LIMIT 30
    ")->fetchAll();

    $adminLogs = $pdo->query("
        SELECT logs.*, admin.name AS admin_name, target.name AS target_name
        FROM admin_action_logs logs
        LEFT JOIN users admin ON admin.id = logs.admin_id
        LEFT JOIN users target ON target.id = logs.target_user_id
        ORDER BY logs.created_at DESC
        LIMIT 30
    ")->fetchAll();
} catch (Throwable $error) {
    $notice = $error->getMessage();
    $users = $allUsers = $loginHistory = $adminLogs = [];
    $adminUser = ['name' => '', 'email' => '', 'admin_session_started' => null];
    $counts = [];
    $totalUsers = $adminAccounts = $activeUsers = $inactiveUsers = $onlineUsers = $lockedAccounts = $activeAdminSessions = $totalLogins = 0;
    $search = $roleFilter = $statusFilter = $onlineFilter = '';
    $sort = 'created_at';
    $dir = 'DESC';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TaskFlow Admin</title>
  <link rel="stylesheet" href="<?php echo taskflow_asset_url('index.css'); ?>">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <style>
    .admin-wrap { padding: 24px; max-width: 1240px; margin: 0 auto; }
    .admin-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .admin-table th, .admin-table td { padding: 10px; border-bottom: 1px solid #ece9ff; text-align: left; vertical-align: top; }
    .admin-table th { color: #667085; white-space: nowrap; }
    .admin-scroll { overflow: auto; }
    .status-pill { display: inline-flex; align-items: center; gap: 5px; padding: 5px 9px; border-radius: 999px; font-weight: 800; font-size: 12px; }
    .status-pill.active, .status-pill.online { color: #027a48; background: #dff6dd; }
    .status-pill.inactive, .status-pill.offline, .status-pill.locked { color: #b42318; background: #fee4e2; }
    .status-pill.admin { color: #42307d; background: #ebe9fe; }
    .analytics-slider { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(280px, 36%); gap: 14px; overflow-x: auto; scroll-snap-type: x mandatory; padding-bottom: 10px; }
    .analytics-slide { scroll-snap-align: start; min-height: 210px; }
    .admin-stats-grid { grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 10px; }
    .admin-stats-grid .data-card { min-height: 118px; padding: 14px 16px; border-radius: 18px; }
    .admin-stats-grid .data-card small { min-height: 28px; margin-bottom: 8px; font-size: 11px; line-height: 1.2; }
    .admin-stats-grid .data-card strong { font-size: 26px; }
    .admin-stats-grid .data-card p { margin-top: 6px; font-size: 12px; line-height: 1.25; }
    .admin-chart-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 14px; margin-bottom: 18px; }
    .admin-chart-card { min-height: 280px; }
    .admin-chart-card canvas { max-height: 210px; }
    .admin-panel-toggle { display: none; }
    .admin-panel-toggle.active { display: block; }
    .admin-controls-head { align-items: flex-start; }
    .admin-control-actions { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 10px; margin-left: auto; }
    .admin-control-actions > button { white-space: nowrap; }
    .admin-control-menu { position: relative; margin-left: 0; }
    .admin-control-menu summary {
      list-style: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border: 0;
      border-radius: 14px;
      padding: 12px 16px;
      color: #fff;
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      box-shadow: 0 14px 28px rgba(124, 92, 255, 0.26);
      font-weight: 900;
      cursor: pointer;
    }
    .admin-control-menu summary::-webkit-details-marker { display: none; }
    .admin-control-panel {
      position: absolute;
      top: calc(100% + 10px);
      right: 0;
      z-index: 30;
      display: grid;
      gap: 8px;
      width: min(280px, 88vw);
      padding: 10px;
      border: 1px solid #ece9ff;
      border-radius: 18px;
      background: #fff;
      box-shadow: 0 24px 60px rgba(12, 18, 43, 0.22);
    }
    .admin-control-panel a,
    .admin-control-panel button {
      justify-content: flex-start;
      width: 100%;
      margin: 0;
      text-align: left;
    }
    .admin-control-panel form { margin: 0; }
    .row-action-menu { display: inline-block; min-width: 48px; }
    .row-action-menu summary {
      list-style: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 44px;
      min-height: 40px;
      border: 0;
      border-radius: 12px;
      padding: 10px;
      color: var(--text);
      background: #f5f3ff;
      font-weight: 900;
      cursor: pointer;
    }
    .row-action-menu summary::-webkit-details-marker { display: none; }
    .row-action-panel {
      display: grid;
      gap: 8px;
      min-width: 230px;
      margin-top: 8px;
      padding: 10px;
      border: 1px solid #ece9ff;
      border-radius: 16px;
      background: #fff;
      box-shadow: 0 16px 36px rgba(12, 18, 43, 0.16);
    }
    .row-action-panel form { margin: 0; }
    .row-action-panel button,
    .row-action-panel input {
      width: 100%;
    }
    .row-action-panel button {
      justify-content: flex-start;
      text-align: left;
    }
    .password-action { display: flex; align-items: center; gap: 8px; width: 100%; margin-top: 8px !important; }
    .row-action-panel .password-action {
      align-items: stretch;
      flex-direction: column;
      margin-top: 0 !important;
    }
    .password-action input, .admin-filters input, .admin-filters select {
      min-width: 150px;
      border: 1px solid #e4e7ec;
      border-radius: 12px;
      padding: 9px;
      background: #fff;
    }
    .admin-filters { display: grid; grid-template-columns: minmax(180px, 1.6fr) repeat(5, minmax(130px, 1fr)) auto; gap: 10px; align-items: end; }
    .compact-note { color: #667085; font-size: 12px; }
    .log-table td { max-width: 360px; }
    @media (max-width: 1180px) {
      .admin-stats-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
    @media (max-width: 1000px) {
      .admin-filters { grid-template-columns: 1fr 1fr; }
      .admin-chart-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 760px) {
      .admin-wrap { padding: 12px; }
      .admin-controls-head { flex-direction: column; }
      .admin-control-actions { justify-content: flex-start; width: 100%; margin-left: 0; }
      .admin-table { min-width: 980px; }
      .analytics-slider { grid-auto-columns: minmax(240px, 82%); }
      .admin-chart-card { min-height: 280px; }
      .admin-chart-card canvas { max-height: 210px; }
      .admin-stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .row-action-panel { min-width: 220px; }
      .password-action { align-items: stretch; flex-direction: column; }
      .password-action input { width: 100%; min-width: 0; }
    }
    @media (max-width: 520px) {
      .admin-wrap { padding: 10px; }
      .admin-scroll { margin: 0 -8px; padding: 0 8px; }
      .admin-filters { grid-template-columns: 1fr; }
      .admin-stats-grid { grid-template-columns: 1fr; }
      .admin-control-actions { display: grid; grid-template-columns: 1fr; }
      .admin-control-actions > button { justify-content: center; width: 100%; }
      .admin-control-menu { width: 100%; margin-left: 0; }
      .admin-control-menu summary { justify-content: center; width: 100%; }
      .admin-control-panel { position: static; width: 100%; margin-top: 10px; box-shadow: none; }
      .analytics-slider { grid-auto-columns: 92%; }
    }
  </style>
</head>
<body>
  <main class="admin-wrap">
    <section class="hero-card">
      <h2>TaskFlow Admin Page</h2>
      <p>Account security, login activity, user controls, and TaskFlow analytics.</p>
    </section>

    <section class="panel-card">
      <div class="panel-head admin-controls-head">
        <div>
          <h3>Admin Controls</h3>
          <p class="compact-note">Current admin session started: <?php echo h($adminUser['admin_session_started'] ?: 'Not recorded'); ?></p>
        </div>
        <div class="admin-control-actions">
          <button class="ghost-btn" type="button" data-admin-panel="userAccountsPanel">User Accounts</button>
          <button class="ghost-btn" type="button" data-admin-panel="loginHistoryPanel">Login History</button>
          <button class="ghost-btn" type="button" data-admin-panel="adminActionLogsPanel">Action Logs</button>
          <details class="admin-control-menu">
            <summary><span>&#9776;</span> Admin Menu</summary>
            <div class="admin-control-panel">
              <a class="primary-btn" href="index.php">Open App</a>
              <a class="ghost-btn" href="setup_database.php">Run Setup</a>
              <button class="ghost-btn" type="button" data-admin-panel="adminSettingsPanel">Admin Settings</button>
              <button class="ghost-btn" type="button" data-admin-panel="createAdminPanel">Create Admin</button>
              <form method="post" onsubmit="return confirm('Force logout all normal users?');">
                <input type="hidden" name="action" value="force_logout_role">
                <input type="hidden" name="role" value="user">
                <button class="ghost-btn" type="submit">Force logout all users</button>
              </form>
              <form method="post" onsubmit="return confirm('Force logout all admins, including this admin session?');">
                <input type="hidden" name="action" value="force_logout_role">
                <input type="hidden" name="role" value="admin">
                <button class="danger-btn" type="submit">Force logout all admins</button>
              </form>
              <a class="danger-btn" href="admin_logout.php">Logout Admin</a>
            </div>
          </details>
        </div>
      </div>
      <?php if ($notice): ?><p class="muted"><?php echo h($notice); ?></p><?php endif; ?>
    </section>

    <section id="adminSettingsPanel" class="panel-card admin-panel-toggle">
      <div class="panel-head">
        <h3>Admin Settings</h3>
        <span class="muted">Gmail: <?php echo h($adminUser['email'] ?? ''); ?></span>
      </div>
      <form class="modal-form" method="post">
        <input type="hidden" name="action" value="admin_settings">
        <div class="form-row">
          <div class="field">
            <label>Admin Username</label>
            <input name="admin_name" value="<?php echo h($adminUser['name'] ?? ''); ?>" required>
          </div>
          <div class="field">
            <label>New Admin Password</label>
            <input name="admin_new_password" type="password" placeholder="Optional: Admin@1234">
          </div>
        </div>
        <p class="muted">Gmail cannot be edited. Password requires 8+ characters, a number, and a special character.</p>
        <button class="primary-btn" type="submit">Save Admin Settings</button>
      </form>
    </section>

    <section id="createAdminPanel" class="panel-card admin-panel-toggle">
      <div class="panel-head"><h3>Create Admin Account</h3></div>
      <form class="modal-form" method="post">
        <input type="hidden" name="action" value="create_admin">
        <div class="form-row">
          <div class="field"><label>Admin Name</label><input name="new_admin_name" required></div>
          <div class="field"><label>Admin Gmail</label><input name="new_admin_email" type="email" placeholder="newadmin@gmail.com" required></div>
        </div>
        <div class="field"><label>Admin Password</label><input name="new_admin_password" type="password" placeholder="Admin@1234" required></div>
        <p class="muted">Password requires 8+ characters, a number, and a special character.</p>
        <button class="primary-btn" type="submit">Create Admin</button>
      </form>
    </section>

    <section class="stats-grid admin-stats-grid">
      <article class="data-card lavender"><small>REGISTERED ACCOUNTS</small><strong><?php echo $totalUsers; ?></strong><p>total users</p></article>
      <article class="data-card purple"><small>ADMIN ACCOUNTS</small><strong><?php echo $adminAccounts; ?></strong><p>privileged accounts</p></article>
      <article class="data-card green"><small>ONLINE ACCOUNTS</small><strong><?php echo $onlineUsers; ?></strong><p>active in last 5 min</p></article>
      <article class="data-card cream"><small>LOCKED ACCOUNTS</small><strong><?php echo $lockedAccounts; ?></strong><p>failed login lock</p></article>
      <article class="data-card lavender"><small>ACTIVE ADMIN SESSIONS</small><strong><?php echo $activeAdminSessions; ?></strong><p>current admin tokens</p></article>
      <article class="data-card green"><small>TOTAL LOGINS</small><strong><?php echo $totalLogins; ?></strong><p>successful logins</p></article>
    </section>

    <section class="admin-chart-grid">
      <div class="panel-card admin-chart-card"><div class="panel-head"><h3>Account Status</h3></div><canvas id="accountChart"></canvas></div>
      <div class="panel-card admin-chart-card"><div class="panel-head"><h3>TaskFlow Records</h3></div><canvas id="recordsChart"></canvas></div>
    </section>

    <section class="panel-card">
      <div class="panel-head"><h3>Search, Filter, And Sort Users</h3></div>
      <form class="admin-filters" method="get">
        <input type="hidden" name="panel" value="userAccountsPanel">
        <input name="q" placeholder="Search name or Gmail" value="<?php echo h($search); ?>">
        <select name="role">
          <option value="all">All roles</option>
          <option value="user" <?php echo $roleFilter === 'user' ? 'selected' : ''; ?>>Users</option>
          <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>Admins</option>
        </select>
        <select name="status">
          <option value="all">All status</option>
          <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
          <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        </select>
        <select name="online">
          <option value="all">All activity</option>
          <option value="online" <?php echo $onlineFilter === 'online' ? 'selected' : ''; ?>>Online</option>
          <option value="offline" <?php echo $onlineFilter === 'offline' ? 'selected' : ''; ?>>Offline</option>
          <option value="locked" <?php echo $onlineFilter === 'locked' ? 'selected' : ''; ?>>Locked</option>
        </select>
        <select name="sort">
          <option value="created_at" <?php echo $sort === 'created_at' ? 'selected' : ''; ?>>Created date</option>
          <option value="last_login" <?php echo $sort === 'last_login' ? 'selected' : ''; ?>>Last used</option>
          <option value="login_count" <?php echo $sort === 'login_count' ? 'selected' : ''; ?>>Login count</option>
          <option value="status" <?php echo $sort === 'status' ? 'selected' : ''; ?>>Status</option>
          <option value="password_changed_at" <?php echo $sort === 'password_changed_at' ? 'selected' : ''; ?>>Password changed</option>
        </select>
        <select name="dir">
          <option value="desc" <?php echo $dir === 'DESC' ? 'selected' : ''; ?>>Descending</option>
          <option value="asc" <?php echo $dir === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
        </select>
        <button class="primary-btn" type="submit">Apply</button>
      </form>
    </section>

    <section class="panel-card">
      <div class="panel-head"><h3>User Analytics Slides</h3><span class="muted">Swipe or scroll sideways</span></div>
      <div class="analytics-slider">
        <?php foreach ($allUsers as $user): ?>
          <article class="data-card analytics-slide <?php echo (int) $user['is_online'] === 1 ? 'green' : 'cream'; ?>">
            <small><?php echo h(strtoupper($user['role'])); ?></small>
            <strong><?php echo h($user['name']); ?></strong>
            <p><?php echo h($user['email']); ?></p>
            <p>Status: <span class="status-pill <?php echo h($user['status']); ?>"><?php echo h($user['status']); ?></span></p>
            <p>Activity: <span class="status-pill <?php echo (int) $user['is_online'] === 1 ? 'online' : 'offline'; ?>"><?php echo (int) $user['is_online'] === 1 ? 'online' : 'offline'; ?></span></p>
            <p>Login count: <?php echo (int) $user['login_count']; ?></p>
            <p>Password changed: <?php echo h($user['password_changed_at'] ?: 'Never'); ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <section id="userAccountsPanel" class="panel-card admin-panel-toggle">
      <div class="panel-head"><h3>User Accounts</h3><span class="muted"><?php echo count($users); ?> shown</span></div>
      <div class="admin-scroll">
        <table class="admin-table">
          <thead>
            <tr>
              <th>Name</th><th>Gmail</th><th>Role</th><th>Status</th><th>Online</th><th>Failed</th><th>Login Count</th><th>Last Used</th><th>Password Changed</th><th>Created</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $user): ?>
              <tr>
                <td><?php echo h($user['name']); ?></td>
                <td><?php echo h($user['email']); ?></td>
                <td><span class="status-pill <?php echo h($user['role']); ?>"><?php echo h($user['role']); ?></span></td>
                <td><span class="status-pill <?php echo h($user['status']); ?>"><?php echo h($user['status']); ?></span></td>
                <td><span class="status-pill <?php echo (int) $user['is_online'] === 1 ? 'online' : 'offline'; ?>"><?php echo (int) $user['is_online'] === 1 ? 'online' : 'offline'; ?></span><br><span class="compact-note"><?php echo h($user['last_seen'] ?: 'Never seen'); ?></span></td>
                <td><?php echo (int) $user['failed_login_count']; ?><?php if ((int) $user['is_locked'] === 1): ?><br><span class="status-pill locked">locked until <?php echo h($user['locked_until']); ?></span><?php endif; ?></td>
                <td><?php echo (int) $user['login_count']; ?></td>
                <td><?php echo h($user['last_login'] ?: 'Never'); ?></td>
                <td><?php echo h($user['password_changed_at'] ?: 'Never'); ?></td>
                <td><?php echo h($user['created_at']); ?></td>
                <td>
                  <details class="row-action-menu">
                    <summary aria-label="Open account actions" title="Open account actions"><span>&#9776;</span></summary>
                    <div class="row-action-panel">
                    <?php if ($user['role'] !== 'admin'): ?>
                      <form method="post">
                        <input type="hidden" name="action" value="toggle_user">
                        <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                        <input type="hidden" name="status" value="<?php echo $user['status'] === 'active' ? 'inactive' : 'active'; ?>">
                        <button class="<?php echo $user['status'] === 'active' ? 'danger-btn' : 'primary-btn'; ?>" type="submit"><?php echo $user['status'] === 'active' ? 'Deactivate' : 'Activate'; ?></button>
                      </form>
                      <form method="post" onsubmit="return confirm('Delete this user account?');">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                        <button class="danger-btn" type="submit">Delete</button>
                      </form>
                      <form method="post" class="password-action">
                        <input type="hidden" name="action" value="reset_user_password">
                        <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                        <input name="new_password" type="password" placeholder="New password" required>
                        <button class="ghost-btn" type="submit">Change Password</button>
                      </form>
                    <?php endif; ?>
                    <form method="post" onsubmit="return confirm('Force logout this account?');">
                      <input type="hidden" name="action" value="force_logout_user">
                      <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                      <button class="ghost-btn" type="submit">Force Logout</button>
                    </form>
                    <?php if ((int) $user['is_locked'] === 1 || (int) $user['failed_login_count'] > 0): ?>
                      <form method="post">
                        <input type="hidden" name="action" value="unlock_user">
                        <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                        <button class="ghost-btn" type="submit">Unlock</button>
                      </form>
                    <?php endif; ?>
                    </div>
                  </details>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section id="loginHistoryPanel" class="panel-card admin-panel-toggle">
      <div class="panel-head"><h3>Login History</h3><span class="muted">Latest 30 records</span></div>
      <div class="admin-scroll">
        <table class="admin-table log-table">
          <thead><tr><th>Time</th><th>User</th><th>Gmail</th><th>Role</th><th>Status</th><th>Message</th><th>IP</th></tr></thead>
          <tbody>
            <?php foreach ($loginHistory as $row): ?>
              <tr>
                <td><?php echo h($row['created_at']); ?></td>
                <td><?php echo h($row['user_name'] ?: 'Unknown'); ?></td>
                <td><?php echo h($row['email']); ?></td>
                <td><?php echo h($row['role'] ?: '-'); ?></td>
                <td><span class="status-pill <?php echo in_array($row['status'], ['success'], true) ? 'online' : 'locked'; ?>"><?php echo h($row['status']); ?></span></td>
                <td><?php echo h($row['message']); ?></td>
                <td><?php echo h($row['ip_address']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section id="adminActionLogsPanel" class="panel-card admin-panel-toggle">
      <div class="panel-head"><h3>Admin Action Logs</h3><span class="muted">Latest 30 records</span></div>
      <div class="admin-scroll">
        <table class="admin-table log-table">
          <thead><tr><th>Time</th><th>Admin</th><th>Target</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
          <tbody>
            <?php foreach ($adminLogs as $row): ?>
              <tr>
                <td><?php echo h($row['created_at']); ?></td>
                <td><?php echo h($row['admin_name'] ?: 'Unknown admin'); ?></td>
                <td><?php echo h($row['target_name'] ?: '-'); ?></td>
                <td><?php echo h($row['action']); ?></td>
                <td><?php echo h($row['details']); ?></td>
                <td><?php echo h($row['ip_address']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
  <script src="<?php echo taskflow_asset_url('live_reload.js'); ?>"></script>
  <script>
    document.querySelectorAll('[data-admin-panel]').forEach((button) => {
      button.addEventListener('click', () => {
        const panel = document.getElementById(button.dataset.adminPanel);
        const shouldOpen = panel && !panel.classList.contains('active');
        document.querySelectorAll('.admin-panel-toggle').forEach((item) => {
          item.classList.remove('active');
        });
        if (shouldOpen) {
          panel.classList.add('active');
          window.setTimeout(() => panel.scrollIntoView({ behavior: 'smooth', block: 'start' }), 80);
        }
        const menu = button.closest('.admin-control-menu');
        if (menu) menu.removeAttribute('open');
      });
    });

    const requestedPanel = new URLSearchParams(window.location.search).get('panel');
    if (requestedPanel) {
      const panel = document.getElementById(requestedPanel);
      if (panel) panel.classList.add('active');
    }

    document.addEventListener('click', (event) => {
      document.querySelectorAll('.admin-control-menu[open], .row-action-menu[open]').forEach((menu) => {
        if (!menu.contains(event.target)) menu.removeAttribute('open');
      });
    });

    window.addEventListener('pageshow', (event) => {
      if (event.persisted) window.location.reload();
    });

    new Chart(document.getElementById('accountChart'), {
      type: 'doughnut',
      data: {
        labels: ['Active', 'Inactive', 'Locked'],
        datasets: [{ data: [<?php echo $activeUsers; ?>, <?php echo $inactiveUsers; ?>, <?php echo $lockedAccounts; ?>], backgroundColor: ['#7c5cff', '#f7eed8', '#fee4e2'] }]
      }
    });
    new Chart(document.getElementById('recordsChart'), {
      type: 'bar',
      data: {
        labels: <?php echo json_encode(array_map(fn($name) => ucwords(str_replace('_', ' ', $name)), array_keys($counts))); ?>,
        datasets: [{ data: <?php echo json_encode(array_values($counts)); ?>, backgroundColor: '#7c5cff', borderRadius: 8 }]
      },
      options: { plugins: { legend: { display: false } } }
    });
  </script>
</body>
</html>
