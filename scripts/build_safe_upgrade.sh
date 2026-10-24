#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${ROOT_DIR}/_release"
STAMP="$(date +%Y%m%d-%H%M%S)"
STAGE_DIR="${OUT_DIR}/new-work-safe-upgrade-${STAMP}"
ZIP_PATH="${OUT_DIR}/new-work-safe-upgrade-${STAMP}.zip"

mkdir -p "${STAGE_DIR}"

rsync -a --delete \
  --exclude '.DS_Store' \
  --exclude '.app_env' \
  --exclude '.app_env.*' \
  --exclude '.env' \
  --exclude '.git/' \
  --exclude '_release/' \
  --exclude '_desktop_build/' \
  --exclude 'node_modules/' \
  --exclude 'uploads/' \
  --exclude 'install.php' \
  --exclude 'database_schema.php' \
  --exclude 'db_alter.php' \
  "${ROOT_DIR}/" "${STAGE_DIR}/"

(
  cd "${OUT_DIR}"
  zip -rq "$(basename "${ZIP_PATH}")" "$(basename "${STAGE_DIR}")"
)

echo "SAFE_UPGRADE_PACKAGE=${ZIP_PATH}"
