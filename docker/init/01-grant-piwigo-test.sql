-- Grant piwigo user access to piwigo_test so UpgradeChainTest can create/drop it.
-- Runs automatically on first container start via /docker-entrypoint-initdb.d/.
GRANT ALL PRIVILEGES ON `piwigo_test`.* TO 'piwigo'@'%';
FLUSH PRIVILEGES;
