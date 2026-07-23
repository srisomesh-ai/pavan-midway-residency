-- ============================================================
-- MIGRATION 08: Open gate page (QR access, no login)
--
--   - visitors.created_by becomes nullable (no guard account)
--   - gate_submits table for rate limiting the open page
--   - settings for the gate key and switch
--
-- Run AFTER 07_notifications.sql.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Entries from the open gate page have no user behind them
-- ------------------------------------------------------------
ALTER TABLE `visitors`
  MODIFY COLUMN `created_by` INT UNSIGNED NULL COMMENT 'NULL when logged from the open gate page',
  ADD COLUMN `source` ENUM('gate_open','guard','resident','admin') NOT NULL DEFAULT 'guard' AFTER `created_by`,
  ADD COLUMN `logged_ip` VARCHAR(45) NULL AFTER `source`;

-- ------------------------------------------------------------
-- Rate limiting for the open page
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `gate_submits` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_address`  VARCHAR(45)  NULL,
  `flat_id`     INT UNSIGNED NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gs_ip_time` (`ip_address`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Settings
--   gate_open        1 = the QR page works, 0 = closed
--   gate_key         empty = no secret needed. Put a value here later
--                    and the page will require ?k=<value>, which lets
--                    you lock it down without reprinting the QR.
--   gate_max_per_hour  entries allowed from one device per hour
-- ------------------------------------------------------------
INSERT IGNORE INTO settings (key_name, key_value) VALUES
('gate_open',         '1'),
('gate_key',          ''),
('gate_max_per_hour', '20');

SET FOREIGN_KEY_CHECKS = 1;
