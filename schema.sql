-- MustrHQ — database schema
-- Import this into your MySQL database (phpMyAdmin > Import, or the CLI).
-- All datetimes are stored in UTC.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS admins (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(60)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS brands (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(80)  NOT NULL,
  is_active TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS roles (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(80)  NOT NULL,
  is_active TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS staff (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(100) NOT NULL,
  pin_hash        VARCHAR(255) NOT NULL,
  hourly_rate     DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  photo           VARCHAR(255) DEFAULT NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until    DATETIME     DEFAULT NULL,
  created_at      DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS staff_brands (
  staff_id INT UNSIGNED NOT NULL,
  brand_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (staff_id, brand_id),
  FOREIGN KEY (staff_id) REFERENCES staff(id)  ON DELETE CASCADE,
  FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS staff_roles (
  staff_id INT UNSIGNED NOT NULL,
  role_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (staff_id, role_id),
  FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
  FOREIGN KEY (role_id)  REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS time_entries (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  staff_id   INT UNSIGNED NOT NULL,
  brand_id   INT UNSIGNED DEFAULT NULL,
  clock_in   DATETIME     NOT NULL,          -- UTC
  clock_out  DATETIME     DEFAULT NULL,      -- UTC, NULL means still on shift
  note       VARCHAR(255) DEFAULT NULL,
  created_at DATETIME     NOT NULL,
  updated_at DATETIME     DEFAULT NULL,
  FOREIGN KEY (staff_id) REFERENCES staff(id)  ON DELETE CASCADE,
  FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL,
  INDEX idx_staff_in (staff_id, clock_in),
  INDEX idx_open (staff_id, clock_out)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Recurring expected start per person per weekday (drives late / no-show).
-- dow: 1=Mon .. 7=Sun (matches PHP date('N')). Times are local (APP_TZ).
CREATE TABLE IF NOT EXISTS schedules (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  staff_id       INT UNSIGNED NOT NULL,
  dow            TINYINT      NOT NULL,
  expected_start TIME         NOT NULL,
  expected_end   TIME         DEFAULT NULL,
  UNIQUE KEY uq_staff_dow (staff_id, dow),
  FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kiosk pairing codes. A tablet is paired to the kiosk with one of these codes.
-- A code can be tied to a brand, so that kiosk tags every clock-in with it.
CREATE TABLE IF NOT EXISTS kiosk_codes (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(40)  NOT NULL UNIQUE,
  label        VARCHAR(80)  NOT NULL,
  brand_id     INT UNSIGNED DEFAULT NULL,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  last_used_at DATETIME     DEFAULT NULL,
  created_at   DATETIME     NOT NULL,
  FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- Starter data you can rename or delete later.
INSERT INTO brands (name) VALUES ('Brand One'), ('Brand Two');
INSERT INTO roles  (name) VALUES ('Cook'), ('Packer'), ('Cleaner');
