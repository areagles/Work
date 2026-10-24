#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${ROOT_DIR}/_release"
STAMP="$(date +%Y%m%d-%H%M%S)"
STAGE_NAME="client-system-final-${STAMP}"
STAGE_DIR="${OUT_DIR}/${STAGE_NAME}"
ZIP_PATH="${OUT_DIR}/${STAGE_NAME}.zip"

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

cp "${ROOT_DIR}/LICENSE_PROFILE_CLIENT.env.example" "${STAGE_DIR}/.app_env.template"
echo "client" > "${STAGE_DIR}/EDITION.txt"

(
  cd "${OUT_DIR}"
  zip -rq "$(basename "${ZIP_PATH}")" "${STAGE_NAME}"
)

echo "CLIENT_FINAL_PACKAGE=${ZIP_PATH}"
