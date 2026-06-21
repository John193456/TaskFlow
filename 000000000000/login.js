/*
 * Login/register browser logic.
 *
 * Main flow:
 * 1. Validate Gmail and password format in the browser for fast feedback.
 * 2. Send the request to auth.php when running through XAMPP/http.
 * 3. Store only safe user details in localStorage so index.js knows who is logged in.
 * 4. Route admins to admin.php and normal users to index.php.
 */
const usersKey = "taskflowUsers";
const cookieConsentName = "taskflow_cookie_consent";
const rememberEmailCookieName = "taskflow_remember_email";

function getUsers() {
  // Offline fallback for file:// testing. XAMPP/http uses auth.php instead.
  const saved = localStorage.getItem(usersKey);
  const defaults = [{
    email: "juan@gmail.com",
    password: "Juan@123",
    name: "Juan",
    role: "user",
    status: "active",
    loginCount: 0,
    lastLogin: null
  }];
  return saved ? JSON.parse(saved) : defaults;
}

function saveUsers(users) {
  localStorage.setItem(usersKey, JSON.stringify(users));
}

function cookieSecurePart() {
  return location.protocol === "https:" ? "; Secure" : "";
}

function setCookie(name, value, maxAgeSeconds) {
  document.cookie = `${name}=${encodeURIComponent(value)}; Max-Age=${maxAgeSeconds}; Path=/; SameSite=Lax${cookieSecurePart()}`;
}

function expireCookie(name) {
  document.cookie = `${name}=; Max-Age=0; Path=/; SameSite=Lax${cookieSecurePart()}`;
}

function readCookie(name) {
  const cookies = document.cookie.split(";").map(cookie => cookie.trim());
  const match = cookies.find(cookie => cookie.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.slice(name.length + 1)) : "";
}

function cookieConsentStatus() {
  const status = readCookie(cookieConsentName) || window.TASKFLOW_COOKIE_CONSENT || "unknown";
  return ["accepted", "declined"].includes(status) ? status : "unknown";
}

function optionalCookiesAllowed() {
  return cookieConsentStatus() === "accepted";
}

function applyCookiePreference() {
  const remember = document.getElementById("rememberMe");
  const allowed = optionalCookiesAllowed();
  remember.disabled = !allowed;
  remember.checked = allowed;
  remember.parentElement.classList.toggle("cookie-disabled", !allowed);
  if (!allowed) expireCookie(rememberEmailCookieName);
}

function bindCookieConsent() {
  const panel = document.getElementById("cookieConsent");
  const accept = document.getElementById("acceptCookies");
  const decline = document.getElementById("declineCookies");
  if (!panel || !accept || !decline) return;

  if (cookieConsentStatus() === "unknown") {
    panel.classList.remove("hidden");
  }

  accept.addEventListener("click", () => {
    setCookie(cookieConsentName, "accepted", 60 * 60 * 24 * 180);
    window.TASKFLOW_COOKIE_CONSENT = "accepted";
    authRequest({ action: "cookie_consent", consent: "accepted" });
    panel.classList.add("hidden");
    applyCookiePreference();
  });

  decline.addEventListener("click", () => {
    setCookie(cookieConsentName, "declined", 60 * 60 * 24 * 180);
    window.TASKFLOW_COOKIE_CONSENT = "declined";
    authRequest({ action: "cookie_consent", consent: "declined" });
    panel.classList.add("hidden");
    applyCookiePreference();
  });
}

function showTab(tab) {
  const isLogin = tab === "login";
  document.getElementById("loginForm").classList.toggle("hidden", !isLogin);
  document.getElementById("registerForm").classList.toggle("hidden", isLogin);
  document.getElementById("loginTab").classList.toggle("active", isLogin);
  document.getElementById("registerTab").classList.toggle("active", !isLogin);
  document.getElementById("authTitle").textContent = isLogin ? "Login" : "Create Account";
  document.getElementById("authSubtitle").textContent = isLogin ? "Welcome back to TaskFlow" : "Register using your Gmail account";
}

function setMessage(id, text, success = false) {
  const element = document.getElementById(id);
  element.textContent = text;
  element.classList.toggle("success", success);
}

function isGmail(email) {
  return /^[A-Z0-9._%+-]+@gmail\.com$/i.test(email);
}

function isStrongPassword(password) {
  return password.length >= 8 && /[0-9]/.test(password) && /[^A-Za-z0-9]/.test(password);
}

function dashboardUrl() {
  // The project is PHP-first now, so the dashboard entry point is always index.php.
  return "index.php";
}

function routeAfterLogin(user) {
  // Admin users go to the protected PHP admin page; normal users go to the app dashboard.
  if (user.role === "admin" && location.protocol.startsWith("http")) {
    return "admin.php";
  }
  return dashboardUrl();
}

async function authRequest(payload) {
  // Sends login/register/profile data to PHP. Returns null if not running through a web server.
  if (!location.protocol.startsWith("http")) return null;
  try {
    const response = await fetch("auth.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    return await response.json();
  } catch (error) {
    return null;
  }
}

function saveCurrentUser(user) {
  // Store only public account data. Passwords and password hashes must never go to localStorage.
  localStorage.setItem("currentUser", JSON.stringify({
    id: user.id || null,
    name: user.name,
    email: user.email,
    role: user.role || "user"
  }));
}

async function handleLogin(event) {
  // Handles the Login form submit.
  event.preventDefault();
  const email = document.getElementById("loginEmail").value.trim().toLowerCase();
  const password = document.getElementById("loginPassword").value;
  const remember = document.getElementById("rememberMe").checked && optionalCookiesAllowed();

  if (!isGmail(email)) {
    setMessage("loginMessage", "Please use a valid Gmail address.");
    return;
  }

  const serverResult = await authRequest({ action: "login", email, password, remember });
  if (serverResult) {
    if (!serverResult.ok) {
      setMessage("loginMessage", serverResult.message || "Login failed.");
      return;
    }
    saveCurrentUser(serverResult.user);
    setMessage("loginMessage", "Login successful. Redirecting...", true);
    setTimeout(() => window.location.replace(routeAfterLogin(serverResult.user)), 500);
    return;
  }

  const users = getUsers();
  const user = users.find(account => account.email === email && account.password === password);
  if (!user) {
    setMessage("loginMessage", "Invalid Gmail or password.");
    return;
  }
  if (user.status !== "active") {
    setMessage("loginMessage", "This account is inactive.");
    return;
  }
  user.loginCount = (user.loginCount || 0) + 1;
  user.lastLogin = new Date().toLocaleString();
  saveUsers(users);
  saveCurrentUser(user);
  setMessage("loginMessage", "Login successful. Redirecting...", true);
  setTimeout(() => window.location.replace(routeAfterLogin(user)), 500);
}

async function handleRegister(event) {
  // Handles the Register form submit.
  event.preventDefault();
  const name = document.getElementById("registerName").value.trim();
  const email = document.getElementById("registerEmail").value.trim().toLowerCase();
  const password = document.getElementById("registerPassword").value;
  const confirm = document.getElementById("confirmPassword").value;

  if (!isGmail(email)) {
    setMessage("registerMessage", "Registration requires a valid Gmail address.");
    return;
  }
  if (!isStrongPassword(password)) {
    setMessage("registerMessage", "Password must be 8+ characters with a number and special character.");
    return;
  }
  if (password !== confirm) {
    setMessage("registerMessage", "Passwords do not match.");
    return;
  }

  const serverResult = await authRequest({ action: "register", name, email, password, remember: optionalCookiesAllowed() });
  if (serverResult) {
    if (!serverResult.ok) {
      setMessage("registerMessage", serverResult.message || "Registration failed.");
      return;
    }
    saveCurrentUser(serverResult.user);
    setMessage("registerMessage", "Account created. Redirecting...", true);
    setTimeout(() => window.location.replace(dashboardUrl()), 500);
    return;
  }

  const users = getUsers();
  if (users.some(account => account.email === email)) {
    setMessage("registerMessage", "This Gmail account is already registered.");
    return;
  }
  const user = { name, email, password, role: "user", status: "active", loginCount: 1, lastLogin: new Date().toLocaleString() };
  users.push(user);
  saveUsers(users);
  saveCurrentUser(user);
  setMessage("registerMessage", "Account created. Redirecting...", true);
  setTimeout(() => window.location.replace(dashboardUrl()), 500);
}

function bindLoginControls() {
  bindCookieConsent();
  applyCookiePreference();
  document.querySelectorAll("[data-tab]").forEach(button => {
    button.addEventListener("click", () => showTab(button.dataset.tab));
  });
  document.querySelectorAll("[data-toggle]").forEach(button => {
    button.addEventListener("click", () => {
      const input = document.getElementById(button.dataset.toggle);
      input.type = input.type === "password" ? "text" : "password";
      button.innerHTML = input.type === "password" ? '<i class="fa-solid fa-eye"></i>' : '<i class="fa-solid fa-eye-slash"></i>';
    });
  });
  document.getElementById("forgotPassword").addEventListener("click", () => {
    setMessage("loginMessage", "Ask your admin to reset your account password.", true);
  });
  document.getElementById("loginForm").addEventListener("submit", handleLogin);
  document.getElementById("registerForm").addEventListener("submit", handleRegister);
}

window.addEventListener("DOMContentLoaded", () => {
  // Force a fresh login each time this page opens, preventing accidental admin auto-login.
  localStorage.removeItem("currentUser");
  bindLoginControls();
  showTab("login");
});

window.addEventListener("pageshow", event => {
  /*
   * Some browsers restore pages instantly from Back/Forward cache.
   * Reloading forces PHP to re-check the current session and redirect if needed.
   */
  if (event.persisted) window.location.reload();
});
