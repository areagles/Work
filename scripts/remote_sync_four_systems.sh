#!/usr/bin/env bash
set -euo pipefail

TS="${1:-$(date +%Y%m%d_%H%M%S)}"
BASE="/home/u159629331/domains/areagles.com/public_html"
ARCHIVE="/home/u159629331/deploy_uploads/codex_sync_20260329_systems.tar.gz"
BACKUP_DIR="/home/u159629331/deploy_backups/codex_system_sync_${TS}"

FILES=(
  security.php
  license_subscriptions.php
  saas.php
  saas_center.php
  scripts/smoke_http.sh
  RELEASE_VARIANTS.md
  LICENSE_SUBSCRIPTIONS_QUICKSTART.md
  scripts/build_variant_package.sh
  scripts/build_all_variants.sh
  scripts/build_owner_lab_final.sh
  scripts/build_saas_gateway_final.sh
)

mkdir -p "${BACKUP_DIR}"

for name in work plast sys; do
  dir="${BASE}/${name}"
  tar -czf "${BACKUP_DIR}/${name}_selected_before.tgz" --ignore-failed-read -C "${dir}" "${FILES[@]}"
  tar -xzf "${ARCHIVE}" -C "${dir}"
  find "${dir}" -name '._*' -type f -delete
  rm -f "${dir}/scripts/smoke_payroll_allocation.php" "${dir}/scripts/smoke_receipt_allocation.php"
  find "${dir}" -type d -exec chmod 755 {} +
  find "${dir}" -type f -exec chmod 644 {} +
  find "${dir}/scripts" -type f -name '*.sh' -exec chmod 755 {} + 2>/dev/null || true
  chmod 600 "${dir}/.app_secret" 2>/dev/null || true
  chmod 600 "${dir}/.installed_lock" 2>/dev/null || true
  chmod 644 "${dir}/.user.ini" 2>/dev/null || true
  php -l "${dir}/security.php" >/dev/null
  php -l "${dir}/license_subscriptions.php" >/dev/null
  php -l "${dir}/saas.php" >/dev/null
  php -l "${dir}/saas_center.php" >/dev/null
  bash -n "${dir}/scripts/build_variant_package.sh"
  bash -n "${dir}/scripts/build_all_variants.sh"
  bash -n "${dir}/scripts/build_owner_lab_final.sh"
  bash -n "${dir}/scripts/build_saas_gateway_final.sh"
  case "${name}" in
    work) base_url="https://work.areagles.com" ;;
    plast) base_url="https://plast.areagles.com" ;;
    sys) base_url="https://sys.areagles.com" ;;
    *) base_url="" ;;
  esac
  if [ -n "${base_url}" ] && [ -x "${dir}/scripts/smoke_http.sh" ]; then
    "${dir}/scripts/smoke_http.sh" "${base_url}" >/dev/null
  fi
done

echo "BACKUP_DIR=${BACKUP_DIR}"
echo "SYNC_OK=1"
