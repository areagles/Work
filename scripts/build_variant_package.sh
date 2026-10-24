#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${ROOT_DIR}/_release"
STAMP="$(date +%Y%m%d-%H%M%S)"
VARIANT_RAW="${1:-}"
VARIANT="$(printf '%s' "${VARIANT_RAW}" | tr '[:upper:]' '[:lower:]')"

if [[ -z "${VARIANT}" ]]; then
  echo "Usage: $0 <owner-hub|owner-lab|client-full|saas-gateway>" >&2
  exit 1
fi

case "${VARIANT}" in
  owner-hub)
    PACKAGE_PREFIX="owner-hub-full"
    ENV_TEMPLATE="ENV_PROFILE_OWNER_HUB.env.example"
    PROFILE_LINE="APP_RUNTIME_PROFILE=owner_hub"
    VARIANT_TITLE="Owner Hub"
    VARIANT_NOTE="Primary secure owner system for activation, subscriptions, and SaaS control."
    ;;
  owner-lab)
    PACKAGE_PREFIX="owner-lab-full"
    ENV_TEMPLATE="ENV_PROFILE_OWNER_HUB.env.example"
    PROFILE_LINE="APP_RUNTIME_PROFILE=owner_hub"
    VARIANT_TITLE="Owner Lab"
    VARIANT_NOTE="Experimental owner clone for testing and staging before promoting changes to production."
    ;;
  client-full)
    PACKAGE_PREFIX="client-full"
    ENV_TEMPLATE="ENV_PROFILE_CLIENT_FULL.env.example"
    PROFILE_LINE="APP_RUNTIME_PROFILE=client_full"
    VARIANT_TITLE="Client Full"
    VARIANT_NOTE="Private customer deployment controlled by the owner system and isolated from managing other systems."
    ;;
  saas-gateway)
    PACKAGE_PREFIX="saas-gateway-full"
    ENV_TEMPLATE="ENV_PROFILE_SAAS_GATEWAY.env.example"
    PROFILE_LINE="APP_RUNTIME_PROFILE=saas_gateway"
    VARIANT_TITLE="SaaS Gateway"
    VARIANT_NOTE="Tenant-per-database SaaS gateway for deployments hosted on your own infrastructure."
    ;;
  *)
    echo "Unknown variant: ${VARIANT}" >&2
    exit 1
    ;;
esac

STAGE_NAME="${PACKAGE_PREFIX}-${STAMP}"
STAGE_DIR="${OUT_DIR}/${STAGE_NAME}"
ZIP_PATH="${OUT_DIR}/${STAGE_NAME}.zip"

mkdir -p "${STAGE_DIR}"

rsync -a --delete \
  --exclude '.DS_Store' \
  --exclude '.app_env' \
  --exclude '.app_env.*' \
  --exclude '.app_secret' \
  --exclude '.env' \
  --exclude '.installed_lock' \
  --exclude 'installed_lock.txt' \
  --exclude '.phpunit.cache/' \
  --exclude '.git/' \
  --exclude '_release/' \
  --exclude '_desktop_build/' \
  --exclude 'node_modules/' \
  --exclude 'tests/' \
  --exclude 'uploads/' \
  --exclude 'vendor/' \
  --exclude '*.tgz' \
  --exclude 'scripts/smoke_payroll_allocation.php' \
  --exclude 'scripts/smoke_receipt_allocation.php' \
  --exclude 'composer.json' \
  --exclude 'composer.lock' \
  --exclude 'phpunit.xml' \
  --exclude 'phpunit.xml.dist' \
  "${ROOT_DIR}/" "${STAGE_DIR}/"

cp "${ROOT_DIR}/${ENV_TEMPLATE}" "${STAGE_DIR}/.app_env.template"
printf '%s\n' "${VARIANT}" > "${STAGE_DIR}/VARIANT.txt"
printf '%s\n' "${VARIANT_TITLE}" > "${STAGE_DIR}/EDITION.txt"

cat > "${STAGE_DIR}/PACKAGE_MANIFEST.md" <<DOC
# ${VARIANT_TITLE} Package

- Variant: \`${VARIANT}\`
- Runtime profile: \`${PROFILE_LINE#APP_RUNTIME_PROFILE=}\`
- Generated at: \`${STAMP}\`

## Purpose
${VARIANT_NOTE}

## Setup
1. Copy \`.app_env.template\` to \`.app_env\`.
2. Fill database and domain values.
3. Keep \`${PROFILE_LINE}\`.
4. Run the installer or upgrade path needed for the target hosting.
DOC

(
  cd "${OUT_DIR}"
  zip -rq "$(basename "${ZIP_PATH}")" "${STAGE_NAME}"
)

echo "${VARIANT}=${ZIP_PATH}"
