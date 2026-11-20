#!/usr/bin/env bash
(
# No -u: the entrypoint's docker_process_sql reads $1 without a default.
set -eo pipefail
: "${DB_PASSWORD:?}" "${TEST_DB_PASSWORD:?}" "${MIGRATION_TEST_DB_PASSWORD:?}"

# Sourced by the image entrypoint, which provides docker_process_sql. Doubles backslashes and
# quotes so a password can sit inside a SQL string literal whatever characters it has.
literal() {
  local quote="'" value=${1//\\/\\\\}
  printf "'%s'" "${value//"$quote"/"$quote$quote"}"
}

# Same character set and collation as the production server, so comparisons behave alike.
docker_process_sql <<SQL
CREATE DATABASE flow_balance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE flow_balance_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE flow_balance_migration_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'flow_balance_app'@'%' IDENTIFIED BY $(literal "$DB_PASSWORD");
CREATE USER 'flow_balance_test_app'@'%' IDENTIFIED BY $(literal "$TEST_DB_PASSWORD");
CREATE USER 'flow_balance_migration_test_app'@'%' IDENTIFIED BY $(literal "$MIGRATION_TEST_DB_PASSWORD");
GRANT ALL PRIVILEGES ON flow_balance.* TO 'flow_balance_app'@'%';
GRANT ALL PRIVILEGES ON flow_balance_test.* TO 'flow_balance_test_app'@'%';
GRANT ALL PRIVILEGES ON flow_balance_migration_test.* TO 'flow_balance_migration_test_app'@'%';
SQL
)
