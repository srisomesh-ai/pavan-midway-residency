-- ============================================================
-- MIGRATION 06: Resident app
--   - resident and guard accounts linked to flats
--   - visitor entries with resident approval
--   - away / travel notices
--   - complaints and suggestions
--
-- Run AFTER 05_vehicle_types.sql.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- USERS: link a login to a flat, and track credential handout
-- ------------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN `flat_id` INT UNSIGNED NULL AFTER `designation`,
  ADD COLUMN `resident_type` ENUM('owner','tenant','family') NULL AFTER `flat_id`,
  ADD COLUMN `temp_password` VARCHAR(60) NULL COMMENT 'Plain text until first login, then cleared' AFTER `resident_type`,
  ADD COLUMN `created_by` INT UNSIGNED NULL AFTER `temp_password`,
  ADD KEY `idx_user_flat` (`flat_id`);

-- ------------------------------------------------------------
-- VISITORS
-- Flow: guard creates entry -> resident approves or denies ->
--       guard marks entry -> guard marks exit
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `visitors` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `flat_id`         INT UNSIGNED NOT NULL,

  `visitor_name`    VARCHAR(120) NOT NULL,
  `visitor_mobile`  VARCHAR(15)  NULL,
  `visitor_count`   TINYINT      NOT NULL DEFAULT 1,
  `purpose`         ENUM('guest','delivery','cab','service','staff','other') NOT NULL DEFAULT 'guest',
  `purpose_note`    VARCHAR(150) NULL,
  `vehicle_no`      VARCHAR(20)  NULL,

  `status`          ENUM('pending','approved','denied','expired','entered','exited')
                    NOT NULL DEFAULT 'pending',
  `decided_by`      INT UNSIGNED NULL COMMENT 'resident user id',
  `decided_at`      DATETIME     NULL,
  `deny_reason`     VARCHAR(150) NULL,

  `entry_at`        DATETIME     NULL,
  `exit_at`         DATETIME     NULL,
  `gate_pass`       VARCHAR(10)  NULL,

  `created_by`      INT UNSIGNED NULL COMMENT 'guard user id',
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_vis_flat_time` (`flat_id`,`created_at`),
  KEY `idx_vis_status` (`status`,`created_at`),
  KEY `idx_vis_pass` (`gate_pass`),
  CONSTRAINT `fk_vis_flat` FOREIGN KEY (`flat_id`) REFERENCES `flats`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- PRE-APPROVED VISITORS
-- Resident allows someone in advance (maid, cook, expected guest)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `preapproved_visitors` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `flat_id`        INT UNSIGNED NOT NULL,
  `visitor_name`   VARCHAR(120) NOT NULL,
  `visitor_mobile` VARCHAR(15)  NULL,
  `purpose`        ENUM('guest','delivery','cab','service','staff','other') NOT NULL DEFAULT 'guest',
  `valid_from`     DATE         NOT NULL,
  `valid_to`       DATE         NOT NULL,
  `gate_pass`      VARCHAR(10)  NOT NULL,
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_by`     INT UNSIGNED NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pre_pass` (`gate_pass`),
  KEY `idx_pre_flat` (`flat_id`,`is_active`),
  CONSTRAINT `fk_pre_flat` FOREIGN KEY (`flat_id`) REFERENCES `flats`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- AWAY NOTICES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `away_notices` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `flat_id`        INT UNSIGNED NOT NULL,
  `from_date`      DATE         NOT NULL,
  `to_date`        DATE         NOT NULL,
  `contact_mobile` VARCHAR(15)  NULL COMMENT 'Where to reach them while away',
  `note`           VARCHAR(300) NULL,
  `key_with`       VARCHAR(120) NULL COMMENT 'Who holds a spare key',
  `status`         ENUM('upcoming','active','completed','cancelled') NOT NULL DEFAULT 'upcoming',
  `created_by`     INT UNSIGNED NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_away_flat` (`flat_id`),
  KEY `idx_away_dates` (`from_date`,`to_date`),
  KEY `idx_away_status` (`status`),
  CONSTRAINT `fk_away_flat` FOREIGN KEY (`flat_id`) REFERENCES `flats`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- COMPLAINTS AND SUGGESTIONS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaints` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `flat_id`      INT UNSIGNED NULL,
  `raised_by`    INT UNSIGNED NULL,

  `kind`         ENUM('complaint','suggestion') NOT NULL DEFAULT 'complaint',
  `category`     ENUM('water','electricity','lift','security','housekeeping','parking',
                      'common_area','noise','maintenance','other') NOT NULL DEFAULT 'other',
  `subject`      VARCHAR(150) NOT NULL,
  `body`         TEXT         NOT NULL,
  `is_anonymous` TINYINT(1)   NOT NULL DEFAULT 0,

  `status`       ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  `priority`     ENUM('low','normal','high') NOT NULL DEFAULT 'normal',

  `assigned_to`  INT UNSIGNED NULL,
  `resolved_by`  INT UNSIGNED NULL,
  `resolved_at`  DATETIME     NULL,

  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_cmp_flat` (`flat_id`),
  KEY `idx_cmp_status` (`status`,`created_at`),
  KEY `idx_cmp_kind` (`kind`),
  CONSTRAINT `fk_cmp_flat` FOREIGN KEY (`flat_id`) REFERENCES `flats`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- COMPLAINT REPLIES (thread)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaint_replies` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `complaint_id` BIGINT UNSIGNED NOT NULL,
  `user_id`      INT UNSIGNED NULL,
  `body`         TEXT         NOT NULL,
  `is_committee` TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rep_complaint` (`complaint_id`,`created_at`),
  CONSTRAINT `fk_rep_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Settings
-- ------------------------------------------------------------
INSERT IGNORE INTO settings (key_name, key_value) VALUES
('visitor_auto_expire_minutes', '30'),
('resident_app_open',           '1');

SET FOREIGN_KEY_CHECKS = 1;
