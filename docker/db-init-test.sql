-- Local dev only (docker-compose.override.yml): the PHPUnit suite runs
-- against imgsli_test (RefreshDatabase migrates it fresh per run).
-- Runs as root during first-time db init, after the MYSQL_USER from the
-- docker image's env has been created.
CREATE DATABASE IF NOT EXISTS imgsli_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON imgsli_test.* TO 'imgsli'@'%';
FLUSH PRIVILEGES;
