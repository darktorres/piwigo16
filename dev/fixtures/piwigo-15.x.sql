-- Pre-15.x simulation patch for UpgradeChainTest.
--
-- Load this AFTER dev/fixtures/piwigo-17.0.sql (already done by setUp()).
-- Removing applied_upgrade id 181 (the 15.0.0 boundary marker) causes
-- upgrade.php to reject the database with HTTP 409.
-- Dropping piwigo_activity mirrors its absence in databases older than 15.x.

DELETE FROM piwigo_upgrade WHERE id IN (175, 176, 177, 178, 179, 180, 181);
DROP TABLE IF EXISTS piwigo_activity;
