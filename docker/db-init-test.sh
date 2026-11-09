#!/bin/sh
# Local dev only (docker-compose.override.yml): the PHPUnit suite runs against
# imgsli_test (RefreshDatabase migrates it fresh per run). Runs once, during
# first-time db init, after the image has created MYSQL_USER.
#
# A shell script (not .sql) so the grant follows DB_USERNAME: the image grants
# MYSQL_USER on MYSQL_DATABASE only, and a hardcoded user name breaks as soon
# as the dev .env renames it.
set -e

mysql --protocol=socket -uroot -p"$MYSQL_ROOT_PASSWORD" <<SQL
CREATE DATABASE IF NOT EXISTS imgsli_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON imgsli_test.* TO '${MYSQL_USER:-imgsli}'@'%';
FLUSH PRIVILEGES;
SQL
