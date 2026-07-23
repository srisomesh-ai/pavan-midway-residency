-- ============================================================
-- MIGRATION 07: Notifications
--
--   - one row per notification, per recipient
--   - committee notices go to every resident
--   - resident actions (complaints, away notices) go to the committee
--   - visitor requests go to the one resident concerned
--
-- Run AFTER 06_resident_app.sql.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- NOTIFICATIONS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL COMMENT 'who receives it',

  `kind`         ENUM('notice','visitor','complaint','complaint_reply',
                      'away','account','submission','system') NOT NULL DEFAULT 'system',
  `title`        VARCHAR(120) NOT NULL,
  `body`         VARCHAR(400) NULL,

  `link`         VARCHAR(120) NULL COMMENT 'page to open, e.g. my-visitors.html',
  `entity`       VARCHAR(40)  NULL COMMENT 'visitor | complaint | away ...',
  `entity_id`    BIGINT UNSIGNED NULL,

  `is_urgent`    TINYINT(1)   NOT NULL DEFAULT 0,
  `read_at`      DATETIME     NULL,
  `created_by`   INT UNSIGNED NULL COMMENT 'who caused it',
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_notif_user`   (`user_id`,`read_at`,`id`),
  KEY `idx_notif_time`   (`created_at`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- NOTICES  (committee announcements to all residents)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notices` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`        VARCHAR(150) NOT NULL,
  `body`         TEXT         NOT NULL,
  `category`     ENUM('general','urgent','water','electricity','maintenance',
                      'event','meeting','security') NOT NULL DEFAULT 'general',
  `is_pinned`    TINYINT(1)   NOT NULL DEFAULT 0,
  `audience`     ENUM('all','block_a','block_b','owners','tenants') NOT NULL DEFAULT 'all',

  `posted_by`    INT UNSIGNED NULL,
  `sent_count`   INT          NOT NULL DEFAULT 0,
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_notice_time` (`created_at`),
  KEY `idx_notice_pin`  (`is_pinned`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Push device tokens (for Firebase later; safe to leave empty)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `push_tokens` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `token`       VARCHAR(255) NOT NULL,
  `platform`    VARCHAR(20)  NULL,
  `last_seen`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_push_token` (`token`),
  KEY `idx_push_user` (`user_id`),
  CONSTRAINT `fk_push_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Settings
-- ------------------------------------------------------------
INSERT IGNORE INTO settings (key_name, key_value) VALUES
('notify_poll_seconds', '45'),
('fcm_server_key',      '');

SET FOREIGN_KEY_CHECKS = 1;
