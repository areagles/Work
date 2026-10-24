#!/usr/bin/env bash
set -euo pipefail

DIR="${1:?target dir required}"
ARCHIVE="/home/u159629331/deploy_uploads/codex_payroll_loan_fix.tar.gz"

tar -xzf "${ARCHIVE}" -C "${DIR}"
php -l "${DIR}/finance_engine.php"
php -l "${DIR}/security.php"
php "${DIR}/scripts/smoke_payroll_allocation.php"
