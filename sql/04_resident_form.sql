-- ============================================================
-- MIGRATION 04: Resident information form + approval workflow
--
-- Run AFTER 03_migrate_flat_structure.sql and 02_seed.sql.
-- Safe to run on a database that already has the 140 flats.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- SUBMISSIONS
-- One row per form submission. Nothing here is live until a
-- committee member approves it.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `submissions` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `flat_id`             INT UNSIGNED NOT NULL,

  -- Owner (always collected)
  `owner_name`          VARCHAR(120) NOT NULL,
  `owner_mobile`        VARCHAR(15)  NOT NULL,
  `owner_mobile_alt`    VARCHAR(15)  NULL,
  `owner_email`         VARCHAR(150) NULL,

  -- Vehicles
  `vehicle_count`       TINYINT      NOT NULL DEFAULT 0,
  `vehicle_1`           VARCHAR(20)  NULL,
  `vehicle_2`           VARCHAR(20)  NULL,
  `vehicle_3`           VARCHAR(20)  NULL,

  -- Branch: how the flat is used
  `status`              ENUM('owner','rented','vacant') NOT NULL,

  -- If owner-occupied
  `family_members`      TINYINT      NULL,

  -- If rented
  `tenant_name`         VARCHAR(120) NULL,
  `tenant_mobile`       VARCHAR(15)  NULL,
  `tenant_mobile_alt`   VARCHAR(15)  NULL,
  `tenant_family`       TINYINT      NULL,
  `rent_amount`         DECIMAL(10,2) NULL,
  `lease_start`         DATE         NULL,
  `lease_end`           DATE         NULL,

  -- If vacant
  `vacant_since`        VARCHAR(40)  NULL COMMENT 'Free text: "3 months", "Jan 2026"',
  `looking_to_rent`     TINYINT(1)   NULL,
  `expected_rent`       DECIMAL(10,2) NULL,

  `notes`               TEXT         NULL,

  -- Review workflow
  `review_state`        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by`         INT UNSIGNED NULL,
  `reviewed_at`         DATETIME     NULL,
  `review_note`         VARCHAR(255) NULL,

  `submitted_ip`        VARCHAR(45)  NULL,
  `submitted_lang`      VARCHAR(5)   NULL DEFAULT 'en',
  `created_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_sub_flat`  (`flat_id`),
  KEY `idx_sub_state` (`review_state`,`created_at`),
  CONSTRAINT `fk_sub_flat` FOREIGN KEY (`flat_id`) REFERENCES `flats`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- FLAT DETAILS
-- The approved, live record for each flat. One row per flat,
-- overwritten whenever a new submission is approved.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `flat_details` (
  `flat_id`             INT UNSIGNED NOT NULL,

  `owner_name`          VARCHAR(120) NOT NULL,
  `owner_mobile`        VARCHAR(15)  NOT NULL,
  `owner_mobile_alt`    VARCHAR(15)  NULL,
  `owner_email`         VARCHAR(150) NULL,

  `vehicle_count`       TINYINT      NOT NULL DEFAULT 0,
  `vehicle_1`           VARCHAR(20)  NULL,
  `vehicle_2`           VARCHAR(20)  NULL,
  `vehicle_3`           VARCHAR(20)  NULL,

  `status`              ENUM('owner','rented','vacant') NOT NULL,
  `family_members`      TINYINT      NULL,

  `tenant_name`         VARCHAR(120) NULL,
  `tenant_mobile`       VARCHAR(15)  NULL,
  `tenant_mobile_alt`   VARCHAR(15)  NULL,
  `tenant_family`       TINYINT      NULL,
  `rent_amount`         DECIMAL(10,2) NULL,
  `lease_start`         DATE         NULL,
  `lease_end`           DATE         NULL,

  `vacant_since`        VARCHAR(40)  NULL,
  `looking_to_rent`     TINYINT(1)   NULL,
  `expected_rent`       DECIMAL(10,2) NULL,

  `notes`               TEXT         NULL,

  `source_submission`   BIGINT UNSIGNED NULL,
  `approved_by`         INT UNSIGNED NULL,
  `approved_at`         DATETIME     NULL,
  `updated_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`flat_id`),
  KEY `idx_fd_status` (`status`),
  KEY `idx_fd_owner_mobile` (`owner_mobile`),
  CONSTRAINT `fk_fd_flat` FOREIGN KEY (`flat_id`) REFERENCES `flats`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Rate limiting for the public form
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `form_submits` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_address`  VARCHAR(45)  NULL,
  `flat_id`     INT UNSIGNED NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fs_ip_time` (`ip_address`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Form settings
-- ------------------------------------------------------------
INSERT IGNORE INTO settings (key_name, key_value) VALUES
('form_open',            '1'),
('form_max_per_ip_hour', '5');

SET FOREIGN_KEY_CHECKS = 1;
