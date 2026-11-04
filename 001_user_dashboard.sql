ALTER TABLE booking_info
  ADD COLUMN booking_status ENUM('active', 'cancelled') NOT NULL DEFAULT 'active' AFTER booking_date,
  ADD COLUMN access_code CHAR(6) NULL AFTER booking_status,
  ADD COLUMN access_code_updated_at DATETIME NULL AFTER access_code,
  ADD COLUMN temporary_code CHAR(6) NULL AFTER access_code_updated_at,
  ADD COLUMN temporary_code_expires_at DATETIME NULL AFTER temporary_code;

CREATE INDEX idx_booking_user_status ON booking_info (user_id, booking_status);
CREATE INDEX idx_booking_access_code ON booking_info (access_code);
