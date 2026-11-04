-- Base schema for a new local installation.
-- The application migrations that follow this file extend these tables.

CREATE TABLE IF NOT EXISTS account (
  id_account INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  firstname VARCHAR(100) NOT NULL,
  lastname VARCHAR(100) NOT NULL,
  email_account VARCHAR(190) NOT NULL UNIQUE,
  password_account VARCHAR(255) NOT NULL,
  role_account ENUM('user', 'admin') NOT NULL DEFAULT 'user',
  ban_account TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS room (
  room_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_number VARCHAR(20) NOT NULL UNIQUE,
  room_status TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_info (
  booking_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name_id VARCHAR(100) NOT NULL DEFAULT '',
  room_count INT UNSIGNED NOT NULL,
  member INT UNSIGNED NOT NULL,
  check_in DATE NOT NULL,
  check_out DATE NOT NULL,
  booking_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_booking_room_dates (room_count, check_in, check_out),
  INDEX idx_booking_user (user_id),
  CONSTRAINT fk_booking_user FOREIGN KEY (user_id) REFERENCES account (id_account),
  CONSTRAINT fk_booking_room FOREIGN KEY (room_count) REFERENCES room (room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO room (room_number, room_status) VALUES
  ('R-01', 0),
  ('R-02', 0),
  ('R-03', 0),
  ('R-04', 0),
  ('R-05', 0),
  ('R-06', 0);
