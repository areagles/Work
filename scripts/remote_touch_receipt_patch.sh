#!/usr/bin/env bash
set -euo pipefail

BASE="/home/u159629331/domains/areagles.com/public_html"

for name in work plast sys; do
  dir="${BASE}/${name}"
  touch "${dir}/finance_engine.php" "${dir}/finance.php" "${dir}/print_finance_voucher.php" "${dir}/invoices.php" "${dir}/saas.php"
done

echo "CACHE_TOUCH_OK=1"
