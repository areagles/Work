#!/usr/bin/env bash
set -euo pipefail

DIR="${1:?target dir required}"
ARCHIVE="/home/u159629331/deploy_uploads/codex_receipt_allocation_patch.tar.gz"

tar -xzf "${ARCHIVE}" -C "${DIR}"
php -l "${DIR}/finance_engine.php"
php -l "${DIR}/finance.php"
php -l "${DIR}/print_finance_voucher.php"
php -l "${DIR}/invoices.php"
php -l "${DIR}/saas.php"

echo "VERIFY_OK=${DIR}"
