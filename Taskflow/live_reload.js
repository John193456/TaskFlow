/*
 * TaskFlow local live reload.
 *
 * This script is intentionally small and runs only on localhost. It helps while
 * editing the XAMPP project: when PHP/CSS/JS files change, the open browser page
 * reloads by itself so manual refresh or Ctrl+F5 is no longer needed.
 */
(() => {
  const localHosts = new Set(["localhost", "127.0.0.1", "::1"]);

  if (!localHosts.has(window.location.hostname)) {
    return;
  }

  const endpoint = "live_reload.php";
  const intervalMs = 2000;
  let currentVersion = null;
  let isChecking = false;

  async function checkForSystemChange() {
    if (isChecking) {
      return;
    }

    isChecking = true;

    try {
      const response = await fetch(`${endpoint}?t=${Date.now()}`, {
        cache: "no-store",
        credentials: "same-origin",
      });

      if (!response.ok) {
        return;
      }

      const data = await response.json();
      if (!data.enabled || !data.version) {
        return;
      }

      if (currentVersion === null) {
        currentVersion = data.version;
        return;
      }

      if (data.version !== currentVersion) {
        currentVersion = data.version;
        window.location.reload();
      }
    } catch (error) {
      /*
       * Avoid noisy alerts while Apache/XAMPP is restarting or the user is
       * moving files. The next timer tick will try again.
       */
    } finally {
      isChecking = false;
    }
  }

  checkForSystemChange();
  window.setInterval(checkForSystemChange, intervalMs);
  window.addEventListener("focus", checkForSystemChange);
})();
