/*
 * TaskFlow dashboard browser logic.
 *
 * PHP renders the page shell in index.php. This file handles the interactive
 * parts: pages, modals, calendar, timers, search, profile settings, and saving
 * app data to api.php.
 */
const todayISO = () => new Date().toISOString().slice(0, 10);
const uid = () => `${Date.now()}-${Math.random().toString(16).slice(2)}`;
const storeKey = "taskflowStateV2";

const defaultState = {
  tasks: [
    { id: uid(), title: "Plan today's priorities", category: "Planning", priority: "high", due: todayISO(), completed: false },
    { id: uid(), title: "Review reminders", category: "Admin", priority: "medium", due: todayISO(), completed: true }
  ],
  reminders: [
    { id: uid(), title: "Drink water", description: "Quick reset before the next work block.", type: "Health", date: todayISO(), time: "10:00", handled: false }
  ],
  events: [
    { id: uid(), title: "Weekly review", date: todayISO(), color: "#7c5cff" }
  ],
  notes: [
    { id: uid(), title: "Ideas", category: "Personal", body: "Capture quick ideas here.", updated: Date.now() }
  ],
  workSessions: [],
  goals: [
    { id: uid(), title: "Finish TaskFlow upgrade", milestone: "Build core pages", progress: 45, completed: false }
  ],
  habits: [
    { id: uid(), title: "Deep work", streak: 2, dates: [todayISO()] }
  ],
  settings: { theme: "dark", notifications: true }
};

let state = loadState();
let serverDatabaseEnabled = false;
let currentUser = null;
let currentView = "home";
let modalSubmit = null;
let analyticsChart = null;
let habitChart = null;
let workTimer = { running: false, start: 0, elapsed: 0, interval: null };
let focus = { mode: "focus", total: 25 * 60, remaining: 25 * 60, running: false, interval: null };
let calendarDate = new Date();
let calendarMode = "month";
let selectedCalendarDate = todayISO();

function loadState() {
  const saved = localStorage.getItem(storeKey);
  if (!saved) return structuredClone(defaultState);
  return { ...structuredClone(defaultState), ...JSON.parse(saved) };
}

function saveState() {
  localStorage.setItem(storeKey, JSON.stringify(state));
  saveServerState();
}

async function loadServerState() {
  // Load the latest saved dashboard state from MySQL through api.php.
  if (!location.protocol.startsWith("http")) return;
  try {
    const response = await fetch("api.php?action=state", { cache: "no-store" });
    if (response.status === 401) {
      localStorage.removeItem("currentUser");
      window.location.replace("login.php");
      return;
    }
    if (!response.ok) return;
    const payload = await response.json();
    if (payload && payload.state) {
      state = { ...structuredClone(defaultState), ...payload.state };
      localStorage.setItem(storeKey, JSON.stringify(state));
      serverDatabaseEnabled = true;
    }
  } catch (error) {
    serverDatabaseEnabled = false;
  }
}

async function saveServerState() {
  // Save the complete dashboard state to MySQL through api.php.
  if (!serverDatabaseEnabled || !location.protocol.startsWith("http")) return;
  try {
    const response = await fetch("api.php?action=state", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ state })
    });
    if (response.status === 401) {
      localStorage.removeItem("currentUser");
      window.location.replace("login.php");
    }
  } catch (error) {
    serverDatabaseEnabled = false;
  }
}

function checkLogin() {
  // Guard for the dashboard: no browser user means go back to the PHP login page.
  const sessionUser = window.TASKFLOW_SESSION_USER || null;
  if (sessionUser && sessionUser.id) {
    currentUser = sessionUser;
    localStorage.setItem("currentUser", JSON.stringify(sessionUser));
    renderProfile();
    applyTheme();
    return true;
  }

  const savedUser = localStorage.getItem("currentUser");
  if (!savedUser) {
    window.location.replace("login.php");
    return false;
  }
  currentUser = JSON.parse(savedUser);
  renderProfile();
  applyTheme();
  return true;
}

function renderProfile() {
  const name = currentUser.name || currentUser.username || "User";
  document.getElementById("profileName").textContent = name;
  document.getElementById("avatar").textContent = name.charAt(0).toUpperCase();
  const adminPageLink = document.getElementById("adminPageLink");
  if (adminPageLink) {
    adminPageLink.classList.toggle("hidden", currentUser.role !== "admin");
  }
}

function applyTheme() {
  document.body.classList.toggle("light-theme", state.settings.theme === "light");
}

function switchView(view) {
  // Central page switcher for sidebar, search results, and profile dropdown navigation.
  currentView = view;
  document.querySelectorAll("#sideNav button").forEach(button => {
    button.classList.toggle("active", button.dataset.view === view);
  });
  document.getElementById("sidebar").classList.remove("open");
  const searchResults = document.getElementById("searchResults");
  const profileDropdown = document.getElementById("profileDropdown");
  const globalSearch = document.getElementById("globalSearch");
  if (searchResults) searchResults.classList.add("hidden");
  if (profileDropdown) profileDropdown.classList.add("hidden");
  if (globalSearch) globalSearch.blur();
  render();
  requestAnimationFrame(() => window.scrollTo({ top: 0, behavior: "smooth" }));
}

function render() {
  const views = {
    home: renderHome,
    calendar: renderCalendar,
    notes: renderNotes,
    work: renderWork,
    goals: renderGoals,
    habits: renderHabits,
    focus: renderFocus,
    analytics: renderAnalytics,
    settings: renderSettings
  };
  views[currentView]();
}

function greeting() {
  const hour = new Date().getHours();
  if (hour < 12) return "Good Morning!";
  if (hour < 18) return "Good Afternoon!";
  return "Good Evening!";
}

function stats() {
  const today = todayISO();
  const todayTasks = state.tasks.filter(task => task.due === today);
  const completedToday = todayTasks.filter(task => task.completed).length;
  const remindersToday = state.reminders.filter(reminder => reminder.date === today);
  const handledReminders = remindersToday.filter(reminder => reminder.handled).length;
  const weekHours = state.workSessions.reduce((sum, session) => sum + session.minutes, 0) / 60;
  return { todayTasks, completedToday, remindersToday, handledReminders, weekHours };
}

function renderHero() {
  const name = currentUser?.name || currentUser?.username || "there";
  return `
    <section class="hero-card">
      <h2>${greeting()}</h2>
      <p>Here's your productivity snapshot for today, ${escapeHtml(name)}.</p>
    </section>
  `;
}

function renderStatsGrid() {
  const s = stats();
  const taskPercent = s.todayTasks.length ? Math.round((s.completedToday / s.todayTasks.length) * 100) : 0;
  return `
    <section class="stats-grid">
      <article class="data-card lavender"><small>TODAY'S TASKS</small><strong>${s.completedToday}/${s.todayTasks.length}</strong><p>${taskPercent}% complete</p></article>
      <article class="data-card purple"><small>REMINDERS</small><strong>${s.handledReminders}/${s.remindersToday.length}</strong><p>handled today</p></article>
      <article class="data-card cream"><small>WORK HOURS</small><strong>${s.weekHours.toFixed(1)}h</strong><p>this week</p></article>
    </section>
  `;
}

function renderHome() {
  document.getElementById("pageContent").innerHTML = `
    ${renderHero()}
    ${renderStatsGrid()}
    <section class="content-grid">
      <div class="panel-card">
        <div class="panel-head">
          <h3>Today's Todos</h3>
          <button class="primary-btn" data-add="task"><i class="fa-solid fa-plus"></i>Add task</button>
        </div>
        <div class="progress-track"><div class="progress-fill" style="width:${taskCompletion()}%"></div></div>
        <br>
        <div class="item-list">${taskItems(state.tasks.filter(task => task.due === todayISO()).slice(0, 6))}</div>
      </div>
      <div>
        <div class="panel-card">
          <div class="panel-head">
            <h3>Today's Reminders</h3>
            <button class="ghost-btn" data-add="reminder"><i class="fa-solid fa-bell"></i>Add</button>
          </div>
          <div class="item-list">${reminderItems(state.reminders.filter(reminder => reminder.date === todayISO()).slice(0, 5))}</div>
        </div>
        <div class="panel-card">
          <div class="panel-head"><h3>Goals Snapshot</h3><button class="ghost-btn" data-view-jump="goals">Open</button></div>
          <div class="item-list">${goalItems(state.goals.slice(0, 3), false)}</div>
        </div>
      </div>
    </section>
  `;
}

function taskCompletion() {
  const tasks = state.tasks.filter(task => task.due === todayISO());
  if (!tasks.length) return 0;
  return Math.round((tasks.filter(task => task.completed).length / tasks.length) * 100);
}

function taskItems(tasks) {
  if (!tasks.length) return `<div class="empty">No tasks yet. Add one to start your day.</div>`;
  return tasks.map(task => `
    <article class="item ${task.completed ? "completed" : ""}" draggable="true" data-drag-task="${task.id}">
      <button class="check-btn" data-toggle-task="${task.id}"><i class="fa-solid ${task.completed ? "fa-check" : "fa-circle"}"></i></button>
      <div class="item-main">
        <span class="item-title">${escapeHtml(task.title)}</span>
        <div class="item-meta">${escapeHtml(task.category || "General")} - Due ${task.due || "Anytime"} <span class="priority ${task.priority}">${task.priority}</span></div>
      </div>
      <div class="item-actions">
        <button data-edit-task="${task.id}"><i class="fa-solid fa-pen"></i></button>
        <button data-delete-task="${task.id}"><i class="fa-solid fa-trash"></i></button>
      </div>
    </article>
  `).join("");
}

function reminderItems(reminders) {
  if (!reminders.length) return `<div class="empty">No reminders scheduled.</div>`;
  return reminders.map(reminder => `
    <article class="item ${reminder.handled ? "completed" : ""}">
      <button class="check-btn" data-toggle-reminder="${reminder.id}"><i class="fa-solid ${reminder.handled ? "fa-check" : "fa-bell"}"></i></button>
      <div class="item-main">
        <span class="item-title">${escapeHtml(reminder.title)}</span>
        <div class="item-meta">${escapeHtml(reminder.type)} - ${reminder.date} ${reminder.time}<br>${escapeHtml(reminder.description || "")}</div>
      </div>
      <div class="item-actions">
        <button data-edit-reminder="${reminder.id}"><i class="fa-solid fa-pen"></i></button>
        <button data-delete-reminder="${reminder.id}"><i class="fa-solid fa-trash"></i></button>
      </div>
    </article>
  `).join("");
}

function renderCalendar() {
  const year = calendarDate.getFullYear();
  const month = calendarDate.getMonth();
  const title = calendarDate.toLocaleDateString("en-US", { month: "long", year: "numeric" });
  const viewTitle = calendarMode === "month" ? "Monthly Calendar" : calendarMode === "week" ? "Weekly Calendar" : "Daily Calendar";
  document.getElementById("pageContent").innerHTML = `
    ${renderHero()}
    <section class="panel-card">
      <div class="calendar-toolbar">
        <div>
          <h3 class="calendar-title">${viewTitle}</h3>
          <p class="muted">${title}</p>
        </div>
        <div class="calendar-controls">
          <button class="ghost-btn" data-calendar-nav="prev-year"><i class="fa-solid fa-angles-left"></i>Year</button>
          <button class="ghost-btn" data-calendar-nav="prev"><i class="fa-solid fa-chevron-left"></i>Month</button>
          <select id="calendarMonth">${monthOptions(month)}</select>
          <select id="calendarYear">${yearOptions(year)}</select>
          <button class="ghost-btn" data-calendar-nav="next">Month<i class="fa-solid fa-chevron-right"></i></button>
          <button class="ghost-btn" data-calendar-nav="next-year">Year<i class="fa-solid fa-angles-right"></i></button>
          <button class="ghost-btn" data-calendar-nav="today">Today</button>
        </div>
      </div>
      <div class="panel-head">
        <div class="segmented">
          <button data-calendar-mode="month" class="${calendarMode === "month" ? "active" : ""}">Monthly</button>
          <button data-calendar-mode="week" class="${calendarMode === "week" ? "active" : ""}">Weekly</button>
          <button data-calendar-mode="day" class="${calendarMode === "day" ? "active" : ""}">Daily</button>
        </div>
        <div class="toolbar">
          <button class="primary-btn" data-add="event"><i class="fa-solid fa-plus"></i>Create event</button>
          <button class="ghost-btn" data-add="reminder"><i class="fa-solid fa-bell"></i>Create reminder</button>
        </div>
      </div>
      ${calendarMode === "month" ? renderMonthCalendar(year, month) : ""}
      ${calendarMode === "week" ? renderWeekCalendar() : ""}
      ${calendarMode === "day" ? renderDayCalendar(selectedCalendarDate) : ""}
      <div class="calendar-agenda">${renderSelectedDateAgenda()}</div>
    </section>
  `;
  bindCalendarControls();
}

function renderMonthCalendar(year, month) {
  const days = new Date(year, month + 1, 0).getDate();
  const firstDay = new Date(year, month, 1).getDay();
  const cells = [];
  const previousMonthDays = new Date(year, month, 0).getDate();
  for (let i = firstDay - 1; i >= 0; i--) {
    const date = new Date(year, month - 1, previousMonthDays - i);
    cells.push(calendarCell(date, true));
  }
  for (let day = 1; day <= days; day++) {
    cells.push(calendarCell(new Date(year, month, day), false));
  }
  while (cells.length % 7 !== 0) {
    const nextDay = cells.length - firstDay - days + 1;
    cells.push(calendarCell(new Date(year, month + 1, nextDay), true));
  }
  return `
    <div class="calendar-grid month-calendar-grid">
      ${["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"].map(day => `<div class="weekday">${day}</div>`).join("")}
      ${cells.join("")}
    </div>
  `;
}

function calendarCell(date, muted) {
  const iso = toLocalISO(date);
  const events = state.events.filter(event => event.date === iso);
  const reminders = state.reminders.filter(reminder => reminder.date === iso);
  return `
    <button class="calendar-cell ${muted ? "muted-day" : ""} ${selectedCalendarDate === iso ? "selected" : ""}" data-calendar-date="${iso}">
      <strong>${date.getDate()}</strong>
      ${events.slice(0, 3).map(event => `<span class="event-pill" style="background:${event.color}">${escapeHtml(event.title)}</span>`).join("")}
      ${reminders.slice(0, 2).map(reminder => `<span class="event-pill" style="background:#667085">${escapeHtml(reminder.title)}</span>`).join("")}
    </button>
  `;
}

function renderWeekCalendar() {
  const base = parseLocalDate(selectedCalendarDate);
  const start = new Date(base);
  start.setDate(base.getDate() - base.getDay());
  const days = Array.from({ length: 7 }, (_, index) => {
    const date = new Date(start);
    date.setDate(start.getDate() + index);
    const iso = toLocalISO(date);
    return `
      <button class="week-day-card ${selectedCalendarDate === iso ? "calendar-cell selected" : ""}" data-calendar-date="${iso}">
        <strong>${date.toLocaleDateString("en-US", { weekday: "short", month: "short", day: "numeric" })}</strong>
        <div class="calendar-list">${calendarDayMiniItems(iso)}</div>
      </button>
    `;
  });
  return `<div class="calendar-grid">${days.join("")}</div>`;
}

function renderDayCalendar(dateISO) {
  const date = parseLocalDate(dateISO);
  return `
    <div class="daily-card">
      <div class="panel-head">
        <h3>${date.toLocaleDateString("en-US", { weekday: "long", month: "long", day: "numeric", year: "numeric" })}</h3>
        <div class="toolbar">
          <button class="primary-btn" data-add="event-for-day"><i class="fa-solid fa-plus"></i>Add event here</button>
          <button class="ghost-btn" data-add="reminder-for-day"><i class="fa-solid fa-bell"></i>Add reminder here</button>
        </div>
      </div>
      <div class="item-list">${calendarAgendaItems(dateISO)}</div>
    </div>
  `;
}

function renderSelectedDateAgenda() {
  const date = parseLocalDate(selectedCalendarDate);
  return `
    <div class="panel-head">
      <h3>${date.toLocaleDateString("en-US", { month: "long", day: "numeric", year: "numeric" })} Agenda</h3>
      <div class="toolbar">
        <button class="ghost-btn" data-add="event-for-day"><i class="fa-solid fa-plus"></i>Event on selected day</button>
        <button class="ghost-btn" data-add="reminder-for-day"><i class="fa-solid fa-bell"></i>Reminder on selected day</button>
      </div>
    </div>
    <div class="item-list">${calendarAgendaItems(selectedCalendarDate)}</div>
  `;
}

function calendarAgendaItems(dateISO) {
  const events = state.events.filter(event => event.date === dateISO);
  const reminders = state.reminders.filter(reminder => reminder.date === dateISO);
  const eventHtml = events.map(event => `
    <article class="item">
      <i class="fa-solid fa-calendar-check muted"></i>
      <div class="item-main"><span class="item-title">${escapeHtml(event.title)}</span><div class="item-meta">Event</div></div>
      <div class="item-actions"><button data-edit-event="${event.id}"><i class="fa-solid fa-pen"></i></button><button data-delete-event="${event.id}"><i class="fa-solid fa-trash"></i></button></div>
    </article>
  `).join("");
  const reminderHtml = reminders.map(reminder => `
    <article class="item ${reminder.handled ? "completed" : ""}">
      <button class="check-btn" data-toggle-reminder="${reminder.id}"><i class="fa-solid ${reminder.handled ? "fa-check" : "fa-bell"}"></i></button>
      <div class="item-main"><span class="item-title">${escapeHtml(reminder.title)}</span><div class="item-meta">${reminder.time} - ${escapeHtml(reminder.description || reminder.type)}</div></div>
      <div class="item-actions"><button data-edit-reminder="${reminder.id}"><i class="fa-solid fa-pen"></i></button><button data-delete-reminder="${reminder.id}"><i class="fa-solid fa-trash"></i></button></div>
    </article>
  `).join("");
  return eventHtml || reminderHtml ? eventHtml + reminderHtml : `<div class="empty">No events or reminders for this date.</div>`;
}

function calendarDayMiniItems(dateISO) {
  const events = state.events.filter(event => event.date === dateISO);
  const reminders = state.reminders.filter(reminder => reminder.date === dateISO);
  const items = [
    ...events.map(event => `<span class="event-pill" style="background:${event.color}">${escapeHtml(event.title)}</span>`),
    ...reminders.map(reminder => `<span class="event-pill" style="background:#667085">${escapeHtml(reminder.title)}</span>`)
  ];
  return items.length ? items.join("") : `<p class="muted">No schedule</p>`;
}

function isMobileCalendar() {
  return window.matchMedia("(max-width: 760px)").matches;
}

function openCalendarDayPopup(dateISO) {
  // Mobile calendar uses this popup so the month grid stays compact on small screens.
  const date = parseLocalDate(dateISO);
  document.getElementById("modalTitle").textContent = date.toLocaleDateString("en-US", {
    weekday: "long",
    month: "long",
    day: "numeric",
    year: "numeric"
  });
  document.getElementById("modalForm").innerHTML = `
    <div class="modal-day-actions">
      <button class="ghost-btn" type="button" data-add="event-for-day"><i class="fa-solid fa-plus"></i>Add event</button>
      <button class="ghost-btn" type="button" data-add="reminder-for-day"><i class="fa-solid fa-bell"></i>Add reminder</button>
    </div>
    <div class="item-list">${calendarAgendaItems(dateISO)}</div>
  `;
  modalSubmit = null;
  document.getElementById("modalBackdrop").classList.remove("hidden");
}

function bindCalendarControls() {
  const monthSelect = document.getElementById("calendarMonth");
  const yearSelect = document.getElementById("calendarYear");
  if (monthSelect) {
    monthSelect.addEventListener("change", () => {
      calendarDate.setMonth(Number(monthSelect.value));
      renderCalendar();
    });
  }
  if (yearSelect) {
    yearSelect.addEventListener("change", () => {
      calendarDate.setFullYear(Number(yearSelect.value));
      renderCalendar();
    });
  }
}

function buildSearchResults(term) {
  const normalized = term.toLowerCase().trim();
  if (!normalized) return [];
  const pages = [
    ["home", "Home", "Dashboard overview", "fa-house"],
    ["calendar", "Calendar", "Events and reminders", "fa-calendar-days"],
    ["notes", "Notes", "Saved notes", "fa-note-sticky"],
    ["work", "Work Hours", "Timers and work logs", "fa-clock"],
    ["goals", "Goals", "Goal progress", "fa-bullseye"],
    ["habits", "Habits", "Daily habit tracker", "fa-repeat"],
    ["focus", "Focus", "Pomodoro timer", "fa-headphones-simple"],
    ["analytics", "Analytics", "Charts and reports", "fa-chart-line"]
  ].map(([view, title, meta, icon]) => ({ type: "Page", title, meta, icon, view }));

  const tasks = state.tasks.map(task => ({
    type: "Task",
    title: task.title,
    meta: `${task.completed ? "Completed" : "Active"} - ${task.category || "General"} - Due ${task.due || "Anytime"}`,
    icon: "fa-list-check",
    view: "home"
  }));

  const notes = state.notes.map(note => ({
    type: "Note",
    title: note.title,
    meta: `${note.category || "General"} - ${note.body || "No note body"}`,
    icon: "fa-note-sticky",
    view: "notes"
  }));

  const habits = state.habits.map(habit => ({
    type: "Habit",
    title: habit.title,
    meta: `${habit.streak || 0} day streak - ${habit.dates.length} completions`,
    icon: "fa-repeat",
    view: "habits"
  }));

  const goals = state.goals.map(goal => ({
    type: "Goal",
    title: goal.title,
    meta: `${goal.progress || 0}% progress - ${goal.milestone || "No milestone"}`,
    icon: "fa-bullseye",
    view: "goals"
  }));

  const work = state.workSessions.map(session => ({
    type: "Work",
    title: `${session.minutes} minute work session`,
    meta: `${session.date} - ${session.note || "Focused work"}`,
    icon: "fa-clock",
    view: "work"
  }));

  const reminders = state.reminders.map(reminder => ({
    type: "Reminder",
    title: reminder.title,
    meta: `${reminder.date} ${reminder.time} - ${reminder.description || reminder.type || "Calendar reminder"}`,
    icon: "fa-bell",
    view: "calendar",
    date: reminder.date
  }));

  const events = state.events.map(event => ({
    type: "Event",
    title: event.title,
    meta: `${event.date} - Calendar event`,
    icon: "fa-calendar-check",
    view: "calendar",
    date: event.date
  }));

  return [...pages, ...tasks, ...notes, ...habits, ...goals, ...work, ...reminders, ...events]
    .filter(item => `${item.type} ${item.title} ${item.meta}`.toLowerCase().includes(normalized))
    .slice(0, 12);
}

function renderSearchResults(term) {
  const panel = document.getElementById("searchResults");
  const results = buildSearchResults(term);
  if (!term.trim()) {
    panel.classList.add("hidden");
    panel.innerHTML = "";
    return;
  }
  panel.classList.remove("hidden");
  panel.innerHTML = results.length ? results.map((result, index) => `
    <button class="search-result" data-search-index="${index}">
      <span class="search-result-icon"><i class="fa-solid ${result.icon}"></i></span>
      <span><strong>${escapeHtml(result.title)}</strong><small>${escapeHtml(result.meta).slice(0, 120)}</small></span>
      <span class="search-result-type">${escapeHtml(result.type)}</span>
    </button>
  `).join("") : `<div class="empty">No results found.</div>`;
  panel.dataset.results = JSON.stringify(results);
}

function selectSearchResult(index) {
  const panel = document.getElementById("searchResults");
  const results = JSON.parse(panel.dataset.results || "[]");
  const result = results[index];
  if (!result) return;
  if (result.date) {
    selectedCalendarDate = result.date;
    calendarDate = parseLocalDate(result.date);
    calendarMode = "day";
  }
  document.getElementById("globalSearch").value = "";
  panel.classList.add("hidden");
  switchView(result.view);
}

function monthOptions(activeMonth) {
  return Array.from({ length: 12 }, (_, index) => `<option value="${index}" ${index === activeMonth ? "selected" : ""}>${new Date(2026, index, 1).toLocaleDateString("en-US", { month: "long" })}</option>`).join("");
}

function yearOptions(activeYear) {
  const years = [];
  for (let year = activeYear - 10; year <= activeYear + 10; year++) years.push(year);
  return years.map(year => `<option value="${year}" ${year === activeYear ? "selected" : ""}>${year}</option>`).join("");
}

function renderNotes() {
  document.getElementById("pageContent").innerHTML = `
    ${renderHero()}
    <section class="panel-card">
      <div class="panel-head">
        <h3>Notes</h3>
        <button class="primary-btn" data-add="note"><i class="fa-solid fa-plus"></i>Create note</button>
      </div>
      <div class="toolbar"><input id="noteSearch" placeholder="Search notes..." /><select id="noteCategory"><option value="">All categories</option>${uniqueOptions(state.notes.map(note => note.category))}</select></div>
      <div id="notesList" class="item-list">${noteItems(state.notes)}</div>
    </section>
  `;
  document.getElementById("noteSearch").addEventListener("input", filterNotes);
  document.getElementById("noteCategory").addEventListener("change", filterNotes);
}

function noteItems(notes) {
  if (!notes.length) return `<div class="empty">No notes found.</div>`;
  return notes.map(note => `
    <article class="item">
      <i class="fa-solid fa-note-sticky muted"></i>
      <div class="item-main">
        <span class="item-title">${escapeHtml(note.title)}</span>
        <div class="item-meta">${escapeHtml(note.category || "General")} - ${new Date(note.updated).toLocaleDateString()}<br>${escapeHtml(note.body).slice(0, 120)}</div>
      </div>
      <div class="item-actions">
        <button data-edit-note="${note.id}"><i class="fa-solid fa-pen"></i></button>
        <button data-delete-note="${note.id}"><i class="fa-solid fa-trash"></i></button>
      </div>
    </article>
  `).join("");
}

function filterNotes() {
  const term = document.getElementById("noteSearch").value.toLowerCase();
  const category = document.getElementById("noteCategory").value;
  const filtered = state.notes.filter(note => {
    const matchesText = `${note.title} ${note.body}`.toLowerCase().includes(term);
    const matchesCategory = !category || note.category === category;
    return matchesText && matchesCategory;
  });
  document.getElementById("notesList").innerHTML = noteItems(filtered);
}

function renderWork() {
  const total = state.workSessions.reduce((sum, session) => sum + session.minutes, 0);
  document.getElementById("pageContent").innerHTML = `
    ${renderHero()}
    <section class="stats-grid">
      <article class="data-card cream"><small>TOTAL HOURS</small><strong>${(total / 60).toFixed(1)}h</strong><p>all logged sessions</p></article>
      <article class="data-card lavender"><small>AVERAGE DAILY</small><strong>${averageDailyHours()}h</strong><p>based on session days</p></article>
      <article class="data-card green"><small>SESSIONS</small><strong>${state.workSessions.length}</strong><p>completed work blocks</p></article>
      <article class="data-card purple"><small>ACTIVE TIMER</small><strong id="workTimerDisplay">${formatTime(workTimer.elapsed)}</strong><p>current session</p></article>
    </section>
    <section class="panel-card">
      <div class="panel-head"><h3>Work Hours Timer</h3><button class="ghost-btn" data-add="work-session"><i class="fa-solid fa-plus"></i>Add manual session</button></div>
      <div class="segmented">
        <button id="startWork" class="primary-btn"><i class="fa-solid fa-play"></i>Start timer</button>
        <button id="stopWork" class="danger-btn"><i class="fa-solid fa-stop"></i>Stop timer</button>
        <button id="breakWork" class="ghost-btn"><i class="fa-solid fa-mug-hot"></i>Break timer</button>
      </div><br>
      <div class="item-list">${workSessionItems()}</div>
    </section>
  `;
  document.getElementById("startWork").addEventListener("click", startWorkTimer);
  document.getElementById("stopWork").addEventListener("click", stopWorkTimer);
  document.getElementById("breakWork").addEventListener("click", () => alert("Break started. Pause, stretch, and come back fresh."));
}

function workSessionItems() {
  if (!state.workSessions.length) return `<div class="empty">No work sessions yet.</div>`;
  return state.workSessions.slice().reverse().map(session => `
    <article class="item">
      <i class="fa-solid fa-clock muted"></i>
      <div class="item-main"><span class="item-title">${session.minutes} minutes</span><div class="item-meta">${session.date} - ${escapeHtml(session.note || "Focused work")}</div></div>
      <div class="item-actions"><button data-delete-session="${session.id}"><i class="fa-solid fa-trash"></i></button></div>
    </article>
  `).join("");
}

function averageDailyHours() {
  const days = new Set(state.workSessions.map(session => session.date)).size || 1;
  const total = state.workSessions.reduce((sum, session) => sum + session.minutes, 0);
  return (total / 60 / days).toFixed(1);
}

function startWorkTimer() {
  if (workTimer.running) return;
  workTimer.running = true;
  workTimer.start = Date.now() - workTimer.elapsed * 1000;
  workTimer.interval = setInterval(() => {
    workTimer.elapsed = Math.floor((Date.now() - workTimer.start) / 1000);
    const display = document.getElementById("workTimerDisplay");
    if (display) display.textContent = formatTime(workTimer.elapsed);
  }, 1000);
}

function stopWorkTimer() {
  if (!workTimer.running && !workTimer.elapsed) return;
  clearInterval(workTimer.interval);
  const minutes = Math.max(1, Math.round(workTimer.elapsed / 60));
  state.workSessions.push({ id: uid(), date: todayISO(), minutes, note: "Timer session" });
  workTimer = { running: false, start: 0, elapsed: 0, interval: null };
  saveState();
  renderWork();
}

function renderGoals() {
  document.getElementById("pageContent").innerHTML = `
    ${renderHero()}
    <section class="panel-card">
      <div class="panel-head"><h3>Goals</h3><button class="primary-btn" data-add="goal"><i class="fa-solid fa-plus"></i>Add goal</button></div>
      <div class="item-list">${goalItems(state.goals, true)}</div>
    </section>
  `;
}

function goalItems(goals, actions = true) {
  if (!goals.length) return `<div class="empty">No goals yet.</div>`;
  return goals.map(goal => `
    <article class="item ${goal.completed ? "completed" : ""}">
      <i class="fa-solid fa-bullseye muted"></i>
      <div class="item-main">
        <span class="item-title">${escapeHtml(goal.title)}</span>
        <div class="item-meta">${escapeHtml(goal.milestone || "Next milestone")}</div>
        <div class="progress-track"><div class="progress-fill" style="width:${goal.progress}%"></div></div>
      </div>
      ${actions ? `<div class="item-actions"><button data-edit-goal="${goal.id}"><i class="fa-solid fa-pen"></i></button><button data-delete-goal="${goal.id}"><i class="fa-solid fa-trash"></i></button></div>` : ""}
    </article>
  `).join("");
}

function renderHabits() {
  document.getElementById("pageContent").innerHTML = `
    ${renderHero()}
    <section class="panel-card">
      <div class="panel-head"><h3>Habits</h3><button class="primary-btn" data-add="habit"><i class="fa-solid fa-plus"></i>Add habit</button></div>
      <div class="item-list">${habitItems()}</div>
    </section>
  `;
}

function habitItems() {
  if (!state.habits.length) return `<div class="empty">No habits yet.</div>`;
  return state.habits.map(habit => {
    const done = habit.dates.includes(todayISO());
    return `
      <article class="item ${done ? "completed" : ""}">
        <button class="check-btn" data-toggle-habit="${habit.id}"><i class="fa-solid ${done ? "fa-check" : "fa-plus"}"></i></button>
        <div class="item-main"><span class="item-title">${escapeHtml(habit.title)}</span><div class="item-meta">${habit.streak || 0} day streak - ${habit.dates.length} completions</div></div>
        <div class="item-actions"><button data-edit-habit="${habit.id}"><i class="fa-solid fa-pen"></i></button><button data-delete-habit="${habit.id}"><i class="fa-solid fa-trash"></i></button></div>
      </article>
    `;
  }).join("");
}

function renderFocus() {
  document.getElementById("pageContent").innerHTML = `
    ${renderHero()}
    <section class="panel-card">
      <div class="panel-head"><h3>Pomodoro Focus</h3><span class="muted">Background sounds: rain, cafe, silence</span></div>
      <div class="segmented">
        <button data-focus-mode="focus" class="${focus.mode === "focus" ? "active" : ""}">25 min Focus</button>
        <button data-focus-mode="break" class="${focus.mode === "break" ? "active" : ""}">5 min Break</button>
        <button data-focus-mode="long" class="${focus.mode === "long" ? "active" : ""}">15 min Long Break</button>
      </div>
      <div id="focusDisplay" class="timer-display">${formatTime(focus.remaining)}</div>
      <div class="segmented">
        <button id="startFocus" class="primary-btn"><i class="fa-solid fa-play"></i>Start</button>
        <button id="pauseFocus" class="ghost-btn"><i class="fa-solid fa-pause"></i>Pause</button>
        <button id="resetFocus" class="danger-btn"><i class="fa-solid fa-rotate-left"></i>Reset</button>
      </div>
    </section>
  `;
  document.querySelectorAll("[data-focus-mode]").forEach(button => button.addEventListener("click", () => setFocusMode(button.dataset.focusMode)));
  document.getElementById("startFocus").addEventListener("click", startFocus);
  document.getElementById("pauseFocus").addEventListener("click", pauseFocus);
  document.getElementById("resetFocus").addEventListener("click", resetFocus);
}

function setFocusMode(mode) {
  const totals = { focus: 25 * 60, break: 5 * 60, long: 15 * 60 };
  pauseFocus();
  focus.mode = mode;
  focus.total = totals[mode];
  focus.remaining = totals[mode];
  renderFocus();
}

function startFocus() {
  if (focus.running) return;
  focus.running = true;
  focus.interval = setInterval(() => {
    focus.remaining = Math.max(0, focus.remaining - 1);
    const display = document.getElementById("focusDisplay");
    if (display) display.textContent = formatTime(focus.remaining);
    if (focus.remaining === 0) {
      pauseFocus();
      alert("Focus session complete.");
    }
  }, 1000);
}

function pauseFocus() {
  clearInterval(focus.interval);
  focus.running = false;
}

function resetFocus() {
  pauseFocus();
  focus.remaining = focus.total;
  renderFocus();
}

function renderAnalytics() {
  const completed = state.tasks.filter(task => task.completed).length;
  const total = state.tasks.length || 1;
  document.getElementById("pageContent").innerHTML = `
    ${renderHero()}
    <section class="stats-grid">
      <article class="data-card lavender"><small>TASK COMPLETION</small><strong>${Math.round((completed / total) * 100)}%</strong><p>overall rate</p></article>
      <article class="data-card purple"><small>WEEKLY PRODUCTIVITY</small><strong>${state.tasks.length + state.reminders.length}</strong><p>tracked items</p></article>
      <article class="data-card cream"><small>WORK HOURS</small><strong>${stats().weekHours.toFixed(1)}h</strong><p>logged</p></article>
      <article class="data-card green"><small>HABIT COMPLETION</small><strong>${state.habits.reduce((sum, habit) => sum + habit.dates.length, 0)}</strong><p>total check-ins</p></article>
    </section>
    <section class="content-grid">
      <div class="panel-card"><div class="panel-head"><h3>Monthly Productivity</h3></div><canvas id="analyticsChart"></canvas></div>
      <div class="panel-card"><div class="panel-head"><h3>Habit Completion</h3></div><canvas id="habitChart"></canvas></div>
    </section>
  `;
  requestAnimationFrame(drawCharts);
}

function drawCharts() {
  if (analyticsChart) analyticsChart.destroy();
  if (habitChart) habitChart.destroy();
  analyticsChart = new Chart(document.getElementById("analyticsChart"), {
    type: "bar",
    data: {
      labels: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul"],
      datasets: [{ label: "Tasks", data: [3, 5, 4, 8, 7, state.tasks.length, state.tasks.filter(task => task.completed).length], backgroundColor: "#7c5cff", borderRadius: 8 }]
    },
    options: { responsive: true, plugins: { legend: { display: false } } }
  });
  habitChart = new Chart(document.getElementById("habitChart"), {
    type: "doughnut",
    data: {
      labels: state.habits.map(habit => habit.title),
      datasets: [{ data: state.habits.map(habit => Math.max(1, habit.dates.length)), backgroundColor: ["#7c5cff", "#9b7bff", "#dff6dd", "#f7eed8"] }]
    }
  });
}

function renderSettings() {
  document.getElementById("pageContent").innerHTML = `
    ${renderHero()}
    <section class="panel-card">
      <div class="panel-head"><h3>Settings</h3></div>
      <form id="settingsForm" class="modal-form settings-form">
        <div class="field"><label>Username</label><input id="settingsName" value="${escapeAttr(currentUser.name || currentUser.username || "")}" /></div>
        <div class="field"><label>Gmail Address</label><input id="settingsEmail" value="${escapeAttr(currentUser.email || "")}" readonly /></div>
        <div class="field"><label>Theme</label><select id="settingsTheme"><option value="dark">Dark gradient</option><option value="light">Light mode</option></select></div>
        <label><input id="settingsNotifications" type="checkbox" ${state.settings.notifications ? "checked" : ""}> Enable notifications</label>
        <p id="settingsMessage" class="muted"></p>
        <button class="primary-btn" type="submit"><i class="fa-solid fa-floppy-disk"></i>Save settings</button>
      </form>
    </section>
    <section class="panel-card">
      <div class="panel-head"><h3>Change Password</h3></div>
      <form id="passwordForm" class="modal-form settings-form">
        <div class="field"><label>Current Password</label><input id="currentPassword" type="password" required /></div>
        <div class="field"><label>New Password</label><input id="newPassword" type="password" required /></div>
        <p class="muted">Password must be 8+ characters with a number and special character.</p>
        <p id="passwordMessage" class="muted"></p>
        <button class="primary-btn" type="submit"><i class="fa-solid fa-key"></i>Change password</button>
      </form>
    </section>
  `;
  document.getElementById("settingsTheme").value = state.settings.theme;
  document.getElementById("settingsForm").addEventListener("submit", async event => {
    event.preventDefault();
    currentUser.name = document.getElementById("settingsName").value.trim() || "User";
    state.settings.theme = document.getElementById("settingsTheme").value;
    state.settings.notifications = document.getElementById("settingsNotifications").checked;
    await updateServerProfile(currentUser.name);
    localStorage.setItem("currentUser", JSON.stringify(currentUser));
    saveState();
    renderProfile();
    applyTheme();
    renderSettings();
  });
  document.getElementById("passwordForm").addEventListener("submit", changeUserPassword);
}

async function updateServerProfile(name) {
  if (!location.protocol.startsWith("http") || !currentUser.id) return;
  try {
    const response = await fetch("auth.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "update_profile", user_id: currentUser.id, name })
    });
    const payload = await response.json();
    if (payload.ok && payload.user) {
      currentUser = { ...currentUser, ...payload.user };
    }
  } catch (error) {
    document.getElementById("settingsMessage").textContent = "Saved locally. Server update failed.";
  }
}

async function changeUserPassword(event) {
  event.preventDefault();
  const message = document.getElementById("passwordMessage");
  const currentPassword = document.getElementById("currentPassword").value;
  const newPassword = document.getElementById("newPassword").value;
  if (newPassword.length < 8 || !/[0-9]/.test(newPassword) || !/[^A-Za-z0-9]/.test(newPassword)) {
    message.textContent = "New password must be 8+ characters with a number and special character.";
    return;
  }
  if (!location.protocol.startsWith("http") || !currentUser.id) {
    message.textContent = "Password changes require XAMPP/PHP login.";
    return;
  }
  try {
    const response = await fetch("auth.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "change_password", user_id: currentUser.id, current_password: currentPassword, new_password: newPassword })
    });
    const payload = await response.json();
    message.textContent = payload.ok ? "Password changed successfully." : (payload.message || "Password update failed.");
    if (payload.ok) event.target.reset();
  } catch (error) {
    message.textContent = "Password update failed.";
  }
}

function openModal(title, fields, submitText, onSubmit) {
  document.getElementById("modalTitle").textContent = title;
  document.getElementById("modalForm").innerHTML = fields + `<button class="primary-btn" type="submit">${submitText}</button>`;
  modalSubmit = onSubmit;
  document.getElementById("modalBackdrop").classList.remove("hidden");
}

function closeModal() {
  document.getElementById("modalBackdrop").classList.add("hidden");
  modalSubmit = null;
}

function formValue(name) {
  return document.querySelector(`[name="${name}"]`).value.trim();
}

function taskForm(task = {}) {
  openModal(task.id ? "Edit Task" : "Create Task", `
    <div class="field"><label>Title</label><input name="title" required value="${escapeAttr(task.title || "")}"></div>
    <div class="form-row">
      <div class="field"><label>Due date</label><input name="due" type="date" value="${task.due || todayISO()}"></div>
      <div class="field"><label>Priority</label><select name="priority"><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select></div>
    </div>
    <div class="field"><label>Category</label><input name="category" value="${escapeAttr(task.category || "General")}"></div>
  `, "Save task", () => {
    const data = { title: formValue("title"), due: formValue("due"), priority: formValue("priority"), category: formValue("category") || "General" };
    if (task.id) Object.assign(task, data);
    else state.tasks.unshift({ id: uid(), completed: false, ...data });
    saveState();
    closeModal();
    render();
  });
  document.querySelector("[name='priority']").value = task.priority || "medium";
}

function reminderForm(reminder = {}) {
  openModal(reminder.id ? "Edit Reminder" : "Create Reminder", `
    <div class="field"><label>Title</label><input name="title" required value="${escapeAttr(reminder.title || "")}"></div>
    <div class="field"><label>Description</label><textarea name="description">${escapeHtml(reminder.description || "")}</textarea></div>
    <div class="form-row">
      <div class="field"><label>Date</label><input name="date" type="date" value="${reminder.date || todayISO()}"></div>
      <div class="field"><label>Time</label><input name="time" type="time" value="${reminder.time || "09:00"}"></div>
    </div>
    <div class="field"><label>Type</label><input name="type" value="${escapeAttr(reminder.type || "General")}"></div>
  `, "Save reminder", () => {
    const data = { title: formValue("title"), description: formValue("description"), date: formValue("date"), time: formValue("time"), type: formValue("type") || "General" };
    if (reminder.id) Object.assign(reminder, data);
    else state.reminders.unshift({ id: uid(), handled: false, ...data });
    saveState();
    closeModal();
    render();
  });
}

function eventForm(eventItem = {}) {
  openModal(eventItem.id ? "Edit Event" : "Create Event", `
    <div class="field"><label>Event title</label><input name="title" required value="${escapeAttr(eventItem.title || "")}"></div>
    <div class="form-row">
      <div class="field"><label>Date</label><input name="date" type="date" value="${eventItem.date || todayISO()}"></div>
      <div class="field"><label>Color</label><input name="color" type="color" value="${eventItem.color || "#7c5cff"}"></div>
    </div>
  `, "Save event", () => {
    const data = { title: formValue("title"), date: formValue("date"), color: formValue("color") };
    if (eventItem.id) Object.assign(eventItem, data);
    else state.events.push({ id: uid(), ...data });
    selectedCalendarDate = data.date;
    calendarDate = parseLocalDate(data.date);
    saveState();
    closeModal();
    renderCalendar();
  });
}

function noteForm(note = {}) {
  openModal(note.id ? "Edit Note" : "Create Note", `
    <div class="field"><label>Title</label><input name="title" required value="${escapeAttr(note.title || "")}"></div>
    <div class="field"><label>Category</label><input name="category" value="${escapeAttr(note.category || "General")}"></div>
    <div class="field"><label>Note</label><textarea name="body" rows="8">${escapeHtml(note.body || "")}</textarea></div>
  `, "Save note", () => {
    const data = { title: formValue("title"), category: formValue("category") || "General", body: formValue("body"), updated: Date.now() };
    if (note.id) Object.assign(note, data);
    else state.notes.unshift({ id: uid(), ...data });
    saveState();
    closeModal();
    renderNotes();
  });
}

function goalForm(goal = {}) {
  openModal(goal.id ? "Edit Goal" : "Add Goal", `
    <div class="field"><label>Goal</label><input name="title" required value="${escapeAttr(goal.title || "")}"></div>
    <div class="field"><label>Milestone</label><input name="milestone" value="${escapeAttr(goal.milestone || "")}"></div>
    <div class="field"><label>Progress</label><input name="progress" type="range" min="0" max="100" value="${goal.progress || 0}"></div>
  `, "Save goal", () => {
    const data = { title: formValue("title"), milestone: formValue("milestone"), progress: Number(formValue("progress")), completed: Number(formValue("progress")) >= 100 };
    if (goal.id) Object.assign(goal, data);
    else state.goals.unshift({ id: uid(), ...data });
    saveState();
    closeModal();
    renderGoals();
  });
}

function habitForm(habit = {}) {
  openModal(habit.id ? "Edit Habit" : "Add Habit", `
    <div class="field"><label>Habit name</label><input name="title" required value="${escapeAttr(habit.title || "")}"></div>
  `, "Save habit", () => {
    if (habit.id) habit.title = formValue("title");
    else state.habits.unshift({ id: uid(), title: formValue("title"), streak: 0, dates: [] });
    saveState();
    closeModal();
    renderHabits();
  });
}

function manualWorkForm() {
  openModal("Add Work Session", `
    <div class="form-row">
      <div class="field"><label>Date</label><input name="date" type="date" value="${todayISO()}"></div>
      <div class="field"><label>Minutes</label><input name="minutes" type="number" min="1" value="25"></div>
    </div>
    <div class="field"><label>Note</label><input name="note" value="Focused work"></div>
  `, "Save session", () => {
    state.workSessions.push({ id: uid(), date: formValue("date"), minutes: Number(formValue("minutes")), note: formValue("note") });
    saveState();
    closeModal();
    renderWork();
  });
}

function handleActions(event) {
  // One delegated click handler for dynamic buttons rendered inside pageContent/modals.
  const target = event.target.closest("button");
  if (!target) return;
  if (target.dataset.add === "task") taskForm();
  if (target.dataset.add === "reminder") reminderForm();
  if (target.dataset.add === "event") eventForm();
  if (target.dataset.add === "event-for-day") eventForm({ date: selectedCalendarDate });
  if (target.dataset.add === "reminder-for-day") reminderForm({ date: selectedCalendarDate });
  if (target.dataset.add === "note") noteForm();
  if (target.dataset.add === "goal") goalForm();
  if (target.dataset.add === "habit") habitForm();
  if (target.dataset.add === "work-session") manualWorkForm();
  if (target.dataset.viewJump) switchView(target.dataset.viewJump);
  if (target.dataset.calendarMode) {
    calendarMode = target.dataset.calendarMode;
    renderCalendar();
  }
  if (target.dataset.calendarNav) {
    navigateCalendar(target.dataset.calendarNav);
  }
  if (target.dataset.calendarDate) {
    selectedCalendarDate = target.dataset.calendarDate;
    calendarDate = parseLocalDate(target.dataset.calendarDate);
    renderCalendar();
    if (calendarMode === "month" && isMobileCalendar()) openCalendarDayPopup(selectedCalendarDate);
  }

  mutateByDataset(target, "toggleTask", state.tasks, item => item.completed = !item.completed);
  mutateByDataset(target, "deleteTask", state.tasks, null, true);
  mutateByDataset(target, "toggleReminder", state.reminders, item => item.handled = !item.handled);
  mutateByDataset(target, "deleteReminder", state.reminders, null, true);
  mutateByDataset(target, "deleteEvent", state.events, null, true);
  mutateByDataset(target, "deleteNote", state.notes, null, true);
  mutateByDataset(target, "deleteGoal", state.goals, null, true);
  mutateByDataset(target, "deleteHabit", state.habits, null, true);
  mutateByDataset(target, "deleteSession", state.workSessions, null, true);

  if (target.dataset.editTask) taskForm(state.tasks.find(item => item.id === target.dataset.editTask));
  if (target.dataset.editReminder) reminderForm(state.reminders.find(item => item.id === target.dataset.editReminder));
  if (target.dataset.editEvent) eventForm(state.events.find(item => item.id === target.dataset.editEvent));
  if (target.dataset.editNote) noteForm(state.notes.find(item => item.id === target.dataset.editNote));
  if (target.dataset.editGoal) goalForm(state.goals.find(item => item.id === target.dataset.editGoal));
  if (target.dataset.editHabit) habitForm(state.habits.find(item => item.id === target.dataset.editHabit));
  if (target.dataset.toggleHabit) toggleHabit(target.dataset.toggleHabit);
}

function navigateCalendar(direction) {
  if (direction === "prev") calendarDate.setMonth(calendarDate.getMonth() - 1);
  if (direction === "next") calendarDate.setMonth(calendarDate.getMonth() + 1);
  if (direction === "prev-year") calendarDate.setFullYear(calendarDate.getFullYear() - 1);
  if (direction === "next-year") calendarDate.setFullYear(calendarDate.getFullYear() + 1);
  if (direction === "today") {
    calendarDate = new Date();
    selectedCalendarDate = todayISO();
  } else {
    selectedCalendarDate = toLocalISO(new Date(calendarDate.getFullYear(), calendarDate.getMonth(), 1));
  }
  renderCalendar();
}

function mutateByDataset(target, key, collection, mutator, remove = false) {
  const id = target.dataset[key];
  if (!id) return;
  const index = collection.findIndex(item => item.id === id);
  if (index === -1) return;
  if (remove) collection.splice(index, 1);
  else mutator(collection[index]);
  saveState();
  render();
}

function toggleHabit(id) {
  const habit = state.habits.find(item => item.id === id);
  if (!habit) return;
  const today = todayISO();
  if (habit.dates.includes(today)) {
    habit.dates = habit.dates.filter(date => date !== today);
    habit.streak = Math.max(0, habit.streak - 1);
  } else {
    habit.dates.push(today);
    habit.streak = (habit.streak || 0) + 1;
  }
  saveState();
  renderHabits();
}

function uniqueOptions(values) {
  return [...new Set(values.filter(Boolean))].map(value => `<option value="${escapeAttr(value)}">${escapeHtml(value)}</option>`).join("");
}

function formatTime(seconds) {
  const mins = Math.floor(seconds / 60).toString().padStart(2, "0");
  const secs = Math.floor(seconds % 60).toString().padStart(2, "0");
  return `${mins}:${secs}`;
}

function toLocalISO(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

function parseLocalDate(dateISO) {
  const [year, month, day] = dateISO.split("-").map(Number);
  return new Date(year, month - 1, day);
}

function escapeHtml(value = "") {
  return String(value).replace(/[&<>"']/g, char => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[char]));
}

function escapeAttr(value = "") {
  return escapeHtml(value).replace(/`/g, "&#096;");
}

function bindControls() {
  document.getElementById("sideNav").addEventListener("click", event => {
    const button = event.target.closest("[data-view]");
    if (button) switchView(button.dataset.view);
  });
  document.getElementById("pageContent").addEventListener("click", handleActions);
  document.getElementById("modalClose").addEventListener("click", closeModal);
  document.getElementById("modalBackdrop").addEventListener("click", event => {
    if (event.target.id === "modalBackdrop") closeModal();
  });
  document.getElementById("modalForm").addEventListener("submit", event => {
    event.preventDefault();
    if (modalSubmit) modalSubmit();
  });
  document.getElementById("modalForm").addEventListener("click", event => {
    const button = event.target.closest("button");
    if (button && button.type !== "submit") event.preventDefault();
    handleActions(event);
  });
  document.getElementById("profileButton").addEventListener("click", () => document.getElementById("profileDropdown").classList.toggle("hidden"));
  document.getElementById("profileDropdown").addEventListener("click", event => {
    const jumpButton = event.target.closest("[data-view-jump]");
    if (!jumpButton) return;
    document.getElementById("profileDropdown").classList.add("hidden");
    switchView(jumpButton.dataset.viewJump);
  });
  document.getElementById("logoutButton").addEventListener("click", () => {
    if (confirm("Logout from TaskFlow?")) {
      localStorage.removeItem("currentUser");
      window.location.replace(location.protocol.startsWith("http") ? "logout.php" : "login.php");
    }
  });
  document.getElementById("menuButton").addEventListener("click", () => document.getElementById("sidebar").classList.toggle("open"));
  document.getElementById("globalSearch").addEventListener("input", event => {
    renderSearchResults(event.target.value);
  });
  document.getElementById("globalSearch").addEventListener("keydown", event => {
    if (event.key === "Enter") {
      event.preventDefault();
      selectSearchResult(0);
    }
    if (event.key === "Escape") {
      document.getElementById("searchResults").classList.add("hidden");
    }
  });
  document.getElementById("searchResults").addEventListener("click", event => {
    const resultButton = event.target.closest("[data-search-index]");
    if (resultButton) selectSearchResult(Number(resultButton.dataset.searchIndex));
  });
  document.addEventListener("click", event => {
    if (!event.target.closest(".search-wrap")) {
      document.getElementById("searchResults").classList.add("hidden");
    }
  });
}

window.addEventListener("DOMContentLoaded", async () => {
  if (!checkLogin()) return;
  await loadServerState();
  bindControls();
  renderHome();
});

window.addEventListener("pageshow", event => {
  /*
   * If the browser restores the dashboard from Back/Forward cache, reload it
   * so PHP can verify that the session is still valid.
   */
  if (event.persisted) window.location.reload();
});
