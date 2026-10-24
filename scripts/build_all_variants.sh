#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_SCRIPT="${ROOT_DIR}/scripts/build_variant_package.sh"

for variant in owner-hub owner-lab client-full saas-gateway; do
  "${BUILD_SCRIPT}" "${variant}"
done
