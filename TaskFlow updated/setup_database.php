<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

/*
 * Manual database setup page.
 *
 * Open this in XAMPP when you want to force-check the database installation.
 * It calls taskflow_install_database(), which creates the database/tables and
 * default admin account if they are missing.
 */

$message = '';
$ok = false;
try {
    taskflow_install_database();
    $ok = true;
    $message = 'Database, tables, and starter data are ready.';
} catch (Throwable $error) {
    // Show setup errors directly because this page is specifically for debugging setup.
    $message = $error->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TaskFlow Database Setup</title>
  <link rel="stylesheet" href="<?php echo taskflow_asset_url('index.css'); ?>">
</head>
<body>
  <main class="main-area" style="padding: 24px; max-width: 900px; margin: 0 auto;">
    <section class="hero-card">
      <h2>TaskFlow Database Setup</h2>
      <p><?php echo htmlspecialchars($message); ?></p>
    </section>
    <section class="panel-card">
      <h3>Status: <?php echo $ok ? 'Success' : 'Needs attention'; ?></h3>
      <p class="muted">Database name: <strong><?php echo TASKFLOW_DB_NAME; ?></strong></p>
      <div class="toolbar">
        <a class="primary-btn" href="logout.php">Open TaskFlow Login</a>
      </div>
    </section>
  </main>
  <script src="<?php echo taskflow_asset_url('live_reload.js'); ?>"></script>
</body>
</html>
