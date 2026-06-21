<?php
declare(strict_types=1);

/*
 * TaskFlow login/register page.
 *
 * This is now a PHP page so XAMPP can serve it together with the rest of the
 * backend. The page still uses login.css and login.js for the design and
 * browser-side form behavior, while auth.php handles the real database login.
 */
require_once __DIR__ . '/db.php';
taskflow_no_cache_headers();

try {
    /*
     * Running setup here makes the first login/register screen safer:
     * if the database or tables are missing, they are created before the
     * browser sends login/register requests to auth.php.
     */
    taskflow_install_database();
    /*
     * Back-button guard:
     * if a logged-in user/admin opens login.php again, send them to the correct
     * page instead of showing the login form.
     */
    $activeUser = taskflow_current_user();
    if ($activeUser) {
        header('Location: ' . ($activeUser['role'] === 'admin' ? 'admin.php' : 'index.php'));
        exit;
    }
} catch (Throwable $error) {
    /*
     * We do not show raw database errors on the login screen because they can
     * expose server details. The full message is saved in the PHP error log for
     * debugging in XAMPP.
     */
    error_log('TaskFlow login setup check failed: ' . $error->getMessage());
}

$rememberedEmail = taskflow_remembered_email();
$cookieConsentStatus = taskflow_cookie_consent_status();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>TaskFlow Login</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" />
  <link rel="stylesheet" href="<?php echo taskflow_asset_url('login.css'); ?>" />
</head>
<body>
  <main class="auth-page">
    <section class="auth-card">
      <div class="auth-brand">
        <div class="brand-orb"><i class="fa-solid fa-bolt"></i></div>
        <h1 id="authTitle">Login</h1>
        <p id="authSubtitle">Welcome back to TaskFlow</p>
      </div>

      <div class="auth-tabs">
        <button id="loginTab" class="active" data-tab="login">Login</button>
        <button id="registerTab" data-tab="register">Register</button>
      </div>

      <form id="loginForm" class="auth-form">
        <label class="floating-field">
          <input id="loginEmail" type="email" required placeholder=" " value="<?php echo htmlspecialchars($rememberedEmail); ?>" />
          <span>Gmail Address</span>
        </label>
        <label class="floating-field password-field">
          <input id="loginPassword" type="password" required placeholder=" " />
          <span>Password</span>
          <button type="button" class="toggle-password" data-toggle="loginPassword"><i class="fa-solid fa-eye"></i></button>
        </label>
        <div class="auth-row">
          <label><input id="rememberMe" type="checkbox" checked /> Remember Me</label>
          <button type="button" id="forgotPassword">Forgot Password</button>
        </div>
        <p id="loginMessage" class="form-message"></p>
        <button class="submit-btn" type="submit">Login</button>
        <p class="auth-footer">Don't have an account? <button type="button" data-tab="register">Create Account</button></p>
      </form>

      <form id="registerForm" class="auth-form hidden">
        <label class="floating-field">
          <input id="registerName" type="text" required placeholder=" " />
          <span>Full Name</span>
        </label>
        <label class="floating-field">
          <input id="registerEmail" type="email" required placeholder=" " />
          <span>Gmail Address</span>
        </label>
        <label class="floating-field password-field">
          <input id="registerPassword" type="password" required minlength="8" placeholder=" " />
          <span>Password</span>
          <button type="button" class="toggle-password" data-toggle="registerPassword"><i class="fa-solid fa-eye"></i></button>
        </label>
        <label class="floating-field password-field">
          <input id="confirmPassword" type="password" required placeholder=" " />
          <span>Confirm Password</span>
          <button type="button" class="toggle-password" data-toggle="confirmPassword"><i class="fa-solid fa-eye"></i></button>
        </label>
        <p class="password-hint">Password must be at least 8 characters with a number and special character.</p>
        <p id="registerMessage" class="form-message"></p>
        <button class="submit-btn" type="submit">Register & Continue</button>
        <p class="auth-footer">Already have an account? <button type="button" data-tab="login">Login</button></p>
      </form>
    </section>
  </main>
  <section id="cookieConsent" class="cookie-consent <?php echo $cookieConsentStatus === 'unknown' ? '' : 'hidden'; ?>" role="dialog" aria-live="polite" aria-label="Cookie consent request">
    <div>
      <h2>Cookie Permission</h2>
      <p>
        TaskFlow uses a required session cookie to keep your login secure.
        Optional cookies are used only for Remember Me, like saving your Gmail on this device.
      </p>
    </div>
    <div class="cookie-actions">
      <button id="acceptCookies" class="cookie-accept" type="button">Allow cookies</button>
      <button id="declineCookies" class="cookie-decline" type="button">Decline optional</button>
    </div>
  </section>
  <script>
    window.TASKFLOW_COOKIE_CONSENT = <?php echo json_encode($cookieConsentStatus); ?>;
  </script>
  <script src="<?php echo taskflow_asset_url('live_reload.js'); ?>"></script>
  <script src="<?php echo taskflow_asset_url('login.js'); ?>"></script>
</body>
</html>
