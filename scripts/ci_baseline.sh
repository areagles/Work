#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

echo "[ci] root=${ROOT_DIR}"

if ! command -v bash >/dev/null 2>&1; then
  echo "[ci] bash is required" >&2
  exit 1
fi

echo "[ci] validating shell scripts"
find scripts \
  -type f \
  -name '*.sh' \
  ! -name '._*' \
  -print0 | while IFS= read -r -d '' file; do
  bash -n "${file}"
done

echo "[ci] validating packaging scripts"
bash scripts/build_variant_package.sh owner-hub >/dev/null
bash scripts/build_variant_package.sh client-full >/dev/null
bash scripts/build_variant_package.sh saas-gateway >/dev/null

if ! command -v php >/dev/null 2>&1; then
  echo "[ci] php not available; skipping php lint and phpunit"
  exit 0
fi

echo "[ci] linting php files"
find . \
  -path './_release' -prune -o \
  -path './uploads' -prune -o \
  -path './vendor' -prune -o \
  -type f -name '*.php' ! -name '._*' -print0 | while IFS= read -r -d '' file; do
    php -l "${file}" >/dev/null
  done

if ! command -v composer >/dev/null 2>&1; then
  echo "[ci] composer not available; skipping phpunit"
  exit 0
fi

if [ ! -d vendor ]; then
  echo "[ci] installing composer dependencies"
  composer install --no-interaction --prefer-dist
fi

echo "[ci] running phpunit"
vendor/bin/phpunit -c phpunit.xml.dist
