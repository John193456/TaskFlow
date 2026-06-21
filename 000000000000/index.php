<?php
declare(strict_types=1);

/*
 * TaskFlow dashboard entry point.
 *
 * This PHP page renders the dashboard shell, then index.js fills the page with
 * Home/Calendar/Notes/Work/Goals/Habits/Focus/Analytics content.
 *
 * Important debugging note:
 * If this page loads but data does not save, check api.php and db.php.
 * If this page redirects to login.php, check whether the PHP session exists.
 */
require_once __DIR__ . '/db.php';
taskflow_no_cache_headers();
try {
    // Auto-create the database/tables if the user opens TaskFlow before running setup_database.php.
    taskflow_install_database();
    // Server-side guard: only logged-in users with a valid PHP session can open the dashboard.
    $sessionUser = taskflow_require_user();
    $publicSessionUser = taskflow_public_user($sessionUser);
} catch (Throwable $error) {
    // Save the actual database/session error in the XAMPP/PHP log and return to login.
    error_log($error->getMessage());
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>TaskFlow Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <link rel="stylesheet" href="<?php echo taskflow_asset_url('index.css'); ?>" />
</head>
<body>
  <div class="app-shell">
    <aside id="sidebar" class="sidebar">
      <div class="brand">
        <div class="brand-mark"><i class="fa-solid fa-bolt"></i></div>
        <div>
          <h1>TaskFlow</h1>
          <p>Productivity suite</p>
        </div>
      </div>

      <nav id="sideNav" class="side-nav">
        <button data-view="home" class="active"><i class="fa-solid fa-house"></i><span>Home</span></button>
        <button data-view="calendar"><i class="fa-solid fa-calendar-days"></i><span>Calendar</span></button>
        <button data-view="notes"><i class="fa-solid fa-note-sticky"></i><span>Notes</span></button>
        <button data-view="work"><i class="fa-solid fa-clock"></i><span>Work Hours</span></button>
        <button data-view="goals"><i class="fa-solid fa-bullseye"></i><span>Goals</span></button>
        <button data-view="habits"><i class="fa-solid fa-repeat"></i><span>Habits</span></button>
        <button data-view="focus"><i class="fa-solid fa-headphones-simple"></i><span>Focus</span></button>
        <button data-view="analytics"><i class="fa-solid fa-chart-line"></i><span>Analytics</span></button>
      </nav>
    </aside>

    <main class="main-area">
      <header class="topbar">
        <button id="menuButton" class="icon-button mobile-only" aria-label="Open menu"><i class="fa-solid fa-bars"></i></button>
        <div class="mobile-brand">
          <div class="mobile-brand-mark"><i class="fa-solid fa-bolt"></i></div>
          <div>
            <strong>TaskFlow</strong>
            <span>Productivity suite</span>
          </div>
        </div>
        <div class="search-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input id="globalSearch" type="search" placeholder="Search pages, tasks, notes, habits, goals, work hours, reminders..." autocomplete="off" />
          <div id="searchResults" class="search-results hidden"></div>
        </div>
        <div class="profile-menu">
          <button id="profileButton" class="profile-button">
            <span id="avatar">A</span>
            <strong id="profileName">User</strong>
            <i class="fa-solid fa-chevron-down"></i>
          </button>
          <div id="profileDropdown" class="profile-dropdown hidden">
            <button data-view-jump="settings"><i class="fa-solid fa-user-gear"></i> Profile settings</button>
            <a href="admin.php" id="adminPageLink" class="dropdown-link hidden"><i class="fa-solid fa-shield-halved"></i> Admin page</a>
            <button id="logoutButton"><i class="fa-solid fa-right-from-bracket"></i> Logout</button>
          </div>
        </div>
      </header>

      <section id="pageContent" class="page-content"></section>
    </main>
  </div>

  <div id="modalBackdrop" class="modal-backdrop hidden">
    <div class="modal">
      <header>
        <h3 id="modalTitle">New Item</h3>
        <button id="modalClose" class="icon-button"><i class="fa-solid fa-xmark"></i></button>
      </header>
      <form id="modalForm" class="modal-form"></form>
    </div>
  </div>

  <script>
    /*
     * Trusted user data from PHP session.
     * index.js uses this instead of trusting editable browser localStorage.
     */
    window.TASKFLOW_SESSION_USER = <?php echo json_encode($publicSessionUser, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
  </script>
  <script src="<?php echo taskflow_asset_url('live_reload.js'); ?>"></script>
  <script src="<?php echo taskflow_asset_url('index.js'); ?>"></script>
</body>
</html>
