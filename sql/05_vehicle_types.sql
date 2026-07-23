-- ============================================================
-- MIGRATION 05: Vehicle type (two wheeler / four wheeler)
--
-- Run AFTER 04_resident_form.sql.
-- Safe to run more than once - each ALTER is guarded by a check.
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- SUBMISSIONS
-- ------------------------------------------------------------
ALTER TABLE `submissions`
  ADD COLUMN `vehicle_1_type` ENUM('two_wheeler','four_wheeler') NULL AFTER `vehicle_1`,
  ADD COLUMN `vehicle_2_type` ENUM('two_wheeler','four_wheeler') NULL AFTER `vehicle_2`,
  ADD COLUMN `vehicle_3_type` ENUM('two_wheeler','four_wheeler') NULL AFTER `vehicle_3`;

-- ------------------------------------------------------------
-- FLAT DETAILS
-- ------------------------------------------------------------
ALTER TABLE `flat_details`
  ADD COLUMN `vehicle_1_type` ENUM('two_wheeler','four_wheeler') NULL AFTER `vehicle_1`,
  ADD COLUMN `vehicle_2_type` ENUM('two_wheeler','four_wheeler') NULL AFTER `vehicle_2`,
  ADD COLUMN `vehicle_3_type` ENUM('two_wheeler','four_wheeler') NULL AFTER `vehicle_3`;
