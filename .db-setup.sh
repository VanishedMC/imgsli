#!/usr/bin/env bash
set -e
service mariadb start
for i in $(seq 1 30); do mysqladmin ping >/dev/null 2>&1 && break; sleep 1; done
mysqladmin status
mysql <<'SQL'
CREATE DATABASE IF NOT EXISTS imgsli CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS imgsli_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'imgsli'@'localhost' IDENTIFIED BY 'imgsli';
CREATE USER IF NOT EXISTS 'imgsli'@'127.0.0.1' IDENTIFIED BY 'imgsli';
GRANT ALL PRIVILEGES ON imgsli.* TO 'imgsli'@'localhost';
GRANT ALL PRIVILEGES ON imgsli.* TO 'imgsli'@'127.0.0.1';
GRANT ALL PRIVILEGES ON imgsli_test.* TO 'imgsli'@'localhost';
GRANT ALL PRIVILEGES ON imgsli_test.* TO 'imgsli'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
echo db-setup-ok
