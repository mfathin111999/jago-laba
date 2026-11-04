#!/bin/bash
# Source this file (". scripts/development/staging-env.sh") to point the Laravel app and the legacy scripts at the
# DISPOSABLE staging database. Nothing is written to a .env file. Override any value before sourcing.
export APP_ENV="${APP_ENV:-local}" APP_DEBUG="${APP_DEBUG:-true}"
export APP_KEY="${APP_KEY:-base64:c3RhZ2luZy1rZXktc3RhZ2luZy1rZXktMTIzNDU2Nzg=}"   # staging only, never reuse elsewhere
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT="${STAGING_DB_PORT:-3320}" DB_DATABASE="${STAGING_DB_NAME:-jl_staging_a}" DB_USERNAME=root DB_PASSWORD=
export JL_DB_HOST=127.0.0.1 JL_DB_PORT="$DB_PORT" JL_DB_NAME="$DB_DATABASE" JL_DB_USER=root JL_DB_PASS=
export JL_COMPANY_KEY="${JL_COMPANY_KEY:-LOCAL-COMPANY}" JL_MARKETPLACE_OAUTH_MODE=sandbox
export SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=database
