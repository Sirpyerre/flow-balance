#!/usr/bin/env bash
(
set -euo pipefail

psql --username postgres --dbname postgres --set ON_ERROR_STOP=1 \
  --set app_password="$DB_PASSWORD" --set test_password="$TEST_DB_PASSWORD" <<'SQL'
CREATE ROLE flow_balance_app LOGIN PASSWORD :'app_password';
CREATE ROLE flow_balance_test_app LOGIN PASSWORD :'test_password';
CREATE DATABASE flow_balance OWNER flow_balance_app;
CREATE DATABASE flow_balance_test OWNER flow_balance_test_app;
REVOKE CONNECT ON DATABASE flow_balance, flow_balance_test FROM PUBLIC;
SQL
)
