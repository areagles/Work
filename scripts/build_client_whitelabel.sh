#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${ROOT_DIR}/_release"
STAMP="$(date +%Y%m%d-%H%M%S)"
STAGE_NAME="client-whitelabel-final-${STAMP}"
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

echo "client-whitelabel" > "${STAGE_DIR}/EDITION.txt"

cat > "${STAGE_DIR}/WHITE_LABEL_SETUP.md" <<'DOC'
# White-label Setup

## 1) Environment profile
- Use `.app_env.template` as reference.
- Keep these keys in client mode:
  - `APP_LICENSE_EDITION=client`
  - `APP_LICENSE_REMOTE_ONLY=1`
  - `APP_LICENSE_REMOTE_LOCK=1`

## 2) Brand customization (without touching code)
- Login as admin and open `master_data.php`.
- Set:
  - Application Name
  - Brand Logo
  - Theme Color
- Save and refresh cache.

## 3) Domain & license binding
- Configure your central license endpoint in `.app_env`:
  - `APP_LICENSE_REMOTE_URL`
  - `APP_LICENSE_REMOTE_TOKEN`

## 4) Security checks
- Ensure only your super user identity is configured:
  - `APP_SUPER_USER_USERNAME` (or ID/EMAIL)
- Client admins must not receive super-user identity.
DOC

(
  cd "${OUT_DIR}"
  zip -rq "$(basename "${ZIP_PATH}")" "${STAGE_NAME}"
)

echo "CLIENT_WHITELABEL_PACKAGE=${ZIP_PATH}"
