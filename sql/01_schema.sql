-- ============================================================
-- Pavan Midway Residency - Community App
-- Sprint 1: Authentication Foundation
-- MySQL 5.7+ / MariaDB 10.3+
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- BLOCKS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blocks` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(50)  NOT NULL,
  `sort_order`  INT          NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_block_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- FLATS  (144 units)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `flats` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `block_id`       INT UNSIGNED NULL,
  `flat_no`        VARCHAR(20)  NOT NULL,
  `floor`          INT          NULL,
  `area_sqft`      DECIMAL(10,2) NULL,
  `flat_type`      VARCHAR(20)  NULL COMMENT '1BHK / 2BHK / 3BHK',
  `occupancy`      ENUM('owner','tenant','vacant') NOT NULL DEFAULT 'vacant',
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_flat_no` (`flat_no`),
  KEY `idx_flat_block` (`block_id`),
  CONSTRAINT `fk_flat_block` FOREIGN KEY (`block_id`) REFERENCES `blocks`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- USERS
-- role: super_admin | admin | resident | guard
-- status: pending | active | suspended
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`             VARCHAR(120) NOT NULL,
  `email`            VARCHAR(150) NULL,
  `username`         VARCHAR(60)  NULL,
  `mobile`           VARCHAR(15)  NULL,
  `password_hash`    VARCHAR(255) NULL,
  `role`             ENUM('super_admin','admin','resident','guard') NOT NULL DEFAULT 'resident',
  `designation`      VARCHAR(80)  NULL COMMENT 'President / Secretary / Treasurer',
  `status`           ENUM('pending','active','suspended') NOT NULL DEFAULT 'pending',
  `photo_url`        VARCHAR(255) NULL,
  `fcm_token`        VARCHAR(255) NULL,
  `failed_attempts`  INT          NOT NULL DEFAULT 0,
  `locked_until`     DATETIME     NULL,
  `last_login_at`    DATETIME     NULL,
  `last_login_ip`    VARCHAR(45)  NULL,
  `must_change_pwd`  TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_email` (`email`),
  UNIQUE KEY `uq_user_username` (`username`),
  UNIQUE KEY `uq_user_mobile` (`mobile`),
  KEY `idx_user_role_status` (`role`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- USER <-> FLAT mapping (a user may hold more than one flat)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_flats` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `flat_id`      INT UNSIGNED NOT NULL,
  `relation`     ENUM('owner','tenant','family') NOT NULL DEFAULT 'owner',
  `is_primary`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_flat` (`user_id`,`flat_id`),
  KEY `idx_uf_flat` (`flat_id`),
  CONSTRAINT `fk_uf_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_uf_flat` FOREIGN KEY (`flat_id`) REFERENCES `flats`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- SESSIONS  (token based, works for web + future APK)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sessions` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `token_hash`   CHAR(64)     NOT NULL COMMENT 'sha256 of raw token',
  `device_info`  VARCHAR(255) NULL,
  `ip_address`   VARCHAR(45)  NULL,
  `expires_at`   DATETIME     NOT NULL,
  `revoked_at`   DATETIME     NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_hash` (`token_hash`),
  KEY `idx_sess_user` (`user_id`),
  KEY `idx_sess_expiry` (`expires_at`),
  CONSTRAINT `fk_sess_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- LOGIN ATTEMPTS  (brute force throttling / audit)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier`   VARCHAR(150) NOT NULL COMMENT 'email or username tried',
  `ip_address`   VARCHAR(45)  NULL,
  `success`      TINYINT(1)   NOT NULL DEFAULT 0,
  `user_agent`   VARCHAR(255) NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_la_ident_time` (`identifier`,`created_at`),
  KEY `idx_la_ip_time` (`ip_address`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- ACTIVITY LOG
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NULL,
  `action`       VARCHAR(80)  NOT NULL,
  `entity`       VARCHAR(60)  NULL,
  `entity_id`    VARCHAR(60)  NULL,
  `details`      TEXT         NULL,
  `ip_address`   VARCHAR(45)  NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_al_user` (`user_id`),
  KEY `idx_al_action_time` (`action`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- SETTINGS  (key/value society config)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `key_name`   VARCHAR(80)  NOT NULL,
  `key_value`  TEXT         NULL,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
