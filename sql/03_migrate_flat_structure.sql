-- ============================================================
-- MIGRATION 03: Reset flat structure to the real building layout
--
-- RUN THIS ONLY IF you already imported the earlier 144-flat seed.
-- If you are setting up a fresh database, skip this file and just
-- run 01_schema.sql then 02_seed.sql.
--
-- WHAT IT DOES
--   1. Drops the old global-unique constraint on flat_no
--   2. Adds flat_code, floor_label, is_locked columns
--   3. Deletes the old A-101..D-904 flats and A/B/C/D blocks
--   4. Rebuilds 2 blocks and 140 flats in the correct format
--
-- WARNING: this deletes every row in `flats` and any user_flats
-- links pointing at them. Safe now (no residents added yet).
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. BLOCKS - add structural columns
-- ------------------------------------------------------------
ALTER TABLE `blocks`
  ADD COLUMN `code` VARCHAR(10) NOT NULL DEFAULT '' AFTER `name`,
  ADD COLUMN `total_floors` INT NOT NULL DEFAULT 0 AFTER `code`,
  ADD COLUMN `flats_per_floor` INT NOT NULL DEFAULT 0 AFTER `total_floors`,
  ADD COLUMN `total_flats` INT NOT NULL DEFAULT 0 AFTER `flats_per_floor`,
  ADD COLUMN `is_locked` TINYINT(1) NOT NULL DEFAULT 1 AFTER `total_flats`;

-- ------------------------------------------------------------
-- 2. FLATS - restructure
-- ------------------------------------------------------------
ALTER TABLE `flats` DROP INDEX `uq_flat_no`;

ALTER TABLE `flats`
  ADD COLUMN `flat_code` VARCHAR(30) NOT NULL DEFAULT '' AFTER `flat_no`,
  ADD COLUMN `floor_label` VARCHAR(20) NULL AFTER `floor`,
  ADD COLUMN `is_locked` TINYINT(1) NOT NULL DEFAULT 1 AFTER `occupancy`;

-- ------------------------------------------------------------
-- 3. Clear old structural data
-- ------------------------------------------------------------
DELETE FROM `user_flats`;
DELETE FROM `flats`;
DELETE FROM `blocks`;

ALTER TABLE `flats`  AUTO_INCREMENT = 1;
ALTER TABLE `blocks` AUTO_INCREMENT = 1;

-- ------------------------------------------------------------
-- 4. Apply new constraints
-- ------------------------------------------------------------
ALTER TABLE `blocks`
  ADD UNIQUE KEY `uq_block_code` (`code`);

ALTER TABLE `flats`
  ADD UNIQUE KEY `uq_flat_code` (`flat_code`),
  ADD UNIQUE KEY `uq_block_flat` (`block_id`,`flat_no`),
  ADD KEY `idx_flat_floor` (`floor`);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- NOW RUN 02_seed.sql TO LOAD THE 140 FLATS
-- (the admin user and settings rows are protected by INSERT IGNORE,
--  so your existing login will not be affected)
-- ============================================================
