#!/usr/bin/env bash
set -euo pipefail

STAMP="${1:-$(date +%Y%m%d_%H%M%S)}"
ARCHIVE="${2:-/home/u159629331/deploy_uploads/codex_clean_current_systems.tar.gz}"
BASE="/home/u159629331/domains/areagles.com/public_html"
BACKUP_BASE="/home/u159629331/deploy_backups/codex_clean_current_systems_${STAMP}"

mkdir -p "${BACKUP_BASE}"

for app in work plast sys; do
  dir="${BASE}/${app}"
  tar -czf "${BACKUP_BASE}/${app}_before.tgz" \
    --exclude='./uploads' \
    --exclude='./_release' \
    --exclude='./_desktop_build' \
    -C "${dir}" .
  tar -xzf "${ARCHIVE}" -C "${dir}"
  find "${dir}" -name '._*' -type f -delete
  rm -rf "${dir}/tests" "${dir}/.phpunit.cache"
  rm -f "${dir}/scripts/smoke_payroll_allocation.php" "${dir}/scripts/smoke_receipt_allocation.php"
  rm -f "${dir}/composer.json" "${dir}/composer.lock" "${dir}/phpunit.xml" "${dir}/phpunit.xml.dist" "${dir}/.app_env.testing.example"
  find "${dir}" -type d -exec chmod 755 {} +
  find "${dir}" -type f -exec chmod 644 {} +
  find "${dir}/scripts" -type f -name '*.sh' -exec chmod 755 {} + 2>/dev/null || true
  chmod 600 "${dir}/.app_secret" 2>/dev/null || true
  chmod 600 "${dir}/.installed_lock" 2>/dev/null || true
  chmod 644 "${dir}/.user.ini" 2>/dev/null || true
  php -l "${dir}/security.php" >/dev/null
  php -l "${dir}/finance_engine.php" >/dev/null
  php -l "${dir}/dashboard.php" >/dev/null
  php -l "${dir}/master_data.php" >/dev/null
  php -l "${dir}/inventory_engine.php" >/dev/null
  php -l "${dir}/tax_reports.php" >/dev/null
  php -l "${dir}/license_subscriptions.php" >/dev/null
  php -l "${dir}/saas.php" >/dev/null
  php -l "${dir}/saas_center.php" >/dev/null
  case "${app}" in
    work) base_url="https://work.areagles.com" ;;
    plast) base_url="https://plast.areagles.com" ;;
    sys) base_url="https://sys.areagles.com" ;;
    *) base_url="" ;;
  esac
  if [ -n "${base_url}" ] && [ -x "${dir}/scripts/smoke_http.sh" ]; then
    "${dir}/scripts/smoke_http.sh" "${base_url}" >/dev/null
  fi
  echo "DEPLOYED:${app}"
done

echo "BACKUP_DIR:${BACKUP_BASE}"
