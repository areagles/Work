#!/usr/bin/env bash
set -euo pipefail

TS="${1:-$(date +%Y%m%d_%H%M%S)}"
BASE="/home/u159629331/domains/areagles.com/public_html"
ARCHIVE="/home/u159629331/deploy_uploads/codex_receipt_allocation_patch.tar.gz"
BACKUP_DIR="/home/u159629331/deploy_backups/codex_receipt_allocation_${TS}"

FILES=(
  finance_engine.php
  finance.php
  print_finance_voucher.php
  invoices.php
  saas.php
)

mkdir -p "${BACKUP_DIR}"

for name in work plast sys; do
  dir="${BASE}/${name}"
  tar -czf "${BACKUP_DIR}/${name}_before.tgz" --ignore-failed-read -C "${dir}" "${FILES[@]}"
  tar -xzf "${ARCHIVE}" -C "${dir}"
  php -l "${dir}/finance_engine.php" >/dev/null
  php -l "${dir}/finance.php" >/dev/null
  php -l "${dir}/print_finance_voucher.php" >/dev/null
  php -l "${dir}/invoices.php" >/dev/null
  php -l "${dir}/saas.php" >/dev/null
done

echo "BACKUP_DIR=${BACKUP_DIR}"
echo "SYNC_OK=1"
