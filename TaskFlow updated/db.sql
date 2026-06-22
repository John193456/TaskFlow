-- TaskFlow database import file
-- Use this in phpMyAdmin's Import tab after selecting your InfinityFree database.
-- Do not paste PHP files into phpMyAdmin. phpMyAdmin accepts SQL only.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS app_state (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  state_json LONGTEXT NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_app_state_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tasks (
  id VARCHAR(80) NOT NULL,
  user_id INT UNSIGNED NOT NULL DEFAULT 0,
  title VARCHAR(255) NOT NULL,
  category VARCHAR(120) DEFAULT 'General',
  priority VARCHAR(20) DEFAULT 'medium',
  due_date DATE NULL,
  completed TINYINT(1) DEFAULT 0,
  PRIMARY KEY (user_id, id),
  INDEX idx_tasks_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reminders (
  id VARCHAR(80) NOT NULL,
  user_id INT UNSIGNED NOT NULL DEFAULT 0,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  type VARCHAR(120) DEFAULT 'General',
  reminder_date DATE NULL,
  reminder_time TIME NULL,
  handled TINYINT(1) DEFAULT 0,
  PRIMARY KEY (user_id, id),
  INDEX idx_reminders_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS calendar_events (
  id VARCHAR(80) NOT NULL,
  user_id INT UNSIGNED NOT NULL DEFAULT 0,
  title VARCHAR(255) NOT NULL,
  event_date DATE NULL,
  color VARCHAR(20) DEFAULT '#7c5cff',
  PRIMARY KEY (user_id, id),
  INDEX idx_calendar_events_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notes (
  id VARCHAR(80) NOT NULL,
  user_id INT UNSIGNED NOT NULL DEFAULT 0,
  title VARCHAR(255) NOT NULL,
  category VARCHAR(120) DEFAULT 'General',
  body TEXT NULL,
  updated BIGINT NULL,
  PRIMARY KEY (user_id, id),
  INDEX idx_notes_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS work_sessions (
  id VARCHAR(80) NOT NULL,
  user_id INT UNSIGNED NOT NULL DEFAULT 0,
  session_date DATE NULL,
  minutes INT DEFAULT 0,
  duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  note VARCHAR(255) DEFAULT 'Focused work',
  PRIMARY KEY (user_id, id),
  INDEX idx_work_sessions_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS goals (
  id VARCHAR(80) NOT NULL,
  user_id INT UNSIGNED NOT NULL DEFAULT 0,
  title VARCHAR(255) NOT NULL,
  milestone VARCHAR(255) NULL,
  progress INT DEFAULT 0,
  completed TINYINT(1) DEFAULT 0,
  PRIMARY KEY (user_id, id),
  INDEX idx_goals_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS habits (
  id VARCHAR(80) NOT NULL,
  user_id INT UNSIGNED NOT NULL DEFAULT 0,
  title VARCHAR(255) NOT NULL,
  streak INT DEFAULT 0,
  dates_json TEXT NULL,
  PRIMARY KEY (user_id, id),
  INDEX idx_habits_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default admin account:
-- Gmail: admin@gmail.com
-- Password: Admin@123
INSERT INTO users (name, email, password_hash, role, status, password_changed_at)
VALUES (
  'TaskFlow Admin',
  'admin@gmail.com',
  '$2y$10$XDuiy5qgYOzTOHoFppe5ZepldL.EmbrXMDJVZ8epsu7YHswvu0ncC',
  'admin',
  'active',
  NOW()
)
ON DUPLICATE KEY UPDATE email = email;

INSERT INTO app_state (user_id, state_json)
SELECT id, '{"tasks":[],"reminders":[],"events":[],"notes":[],"workSessions":[],"goals":[],"habits":[],"settings":{"theme":"dark","notifications":true}}'
FROM users
WHERE email = 'admin@gmail.com'
ON DUPLICATE KEY UPDATE user_id = user_id;
