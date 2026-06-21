<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
taskflow_no_cache_headers();

/*
 * Shared logout endpoint.
 *
 * Clears the admin PHP session and sends the visitor back to the PHP login page.
 * index.js also removes the browser localStorage user before sending users here.
 */
taskflow_logout_session();
header('Location: login.php?logout=1');
exit;
