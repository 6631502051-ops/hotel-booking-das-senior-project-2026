-- Senior Project 2: payment approval workflow and door access log.
-- Run once after 001_user_dashboard.sql.

-- New booking life cycle:
--   pending_payment   -> booking saved, guest has not paid yet
--   awaiting_approval -> guest pressed "ยืนยันชำระเงิน", waiting for an admin
--   active            -> admin approved the payment (confirmed booking)
--   rejected          -> admin rejected the payment, room released
--   cancelled         -> guest cancelled
-- The column default stays 'active' so bookings that an admin adds by hand
-- on admin.php are confirmed straight away.
ALTER TABLE booking_info
  MODIFY COLUMN booking_status
    ENUM('pending_payment', 'awaiting_approval', 'active', 'rejected', 'cancelled')
    NOT NULL DEFAULT 'active';

CREATE TABLE IF NOT EXISTS payment (
  payment_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id    INT NOT NULL,
  amount        DECIMAL(10,2) NOT NULL,
  method        VARCHAR(30) NOT NULL DEFAULT 'promptpay_simulated',
  payment_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  requested_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by   INT NULL,
  reviewed_at   DATETIME NULL,
  review_note   VARCHAR(255) NULL,
  INDEX idx_payment_status (payment_status, requested_at),
  INDEX idx_payment_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS door_access_log (
  log_id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_number  VARCHAR(20) NOT NULL,
  booking_id   INT NULL,
  code_type    ENUM('main', 'temporary') NULL,
  code_masked  CHAR(6) NOT NULL,
  result       ENUM('granted', 'denied') NOT NULL,
  reason       VARCHAR(40) NOT NULL,
  source       VARCHAR(60) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_door_log_room_time (room_number, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
