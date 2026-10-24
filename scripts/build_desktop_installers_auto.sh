#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${ROOT_DIR}/_desktop_build"
SHELL_DIR="${BUILD_DIR}/electron_shell"
STAMP="${1:-$(date +%Y%m%d-%H%M%S)}"
DIST_DIR="${BUILD_DIR}/electron_dist_${STAMP}"
TEMPLATE_DIR="${BUILD_DIR}/runtime_template"
OUT_DIR="${2:-${HOME}/Desktop/FINAL_DESKTOP_AUTO_${STAMP}}"
INSTALLERS_DIR="${OUT_DIR}/Desktop-Installers"
ELECTRON_VERSION="${ELECTRON_VERSION:-40.7.0}"

if ! command -v node >/dev/null 2>&1; then
    echo "Node.js is required."
    exit 1
fi

if ! command -v npx >/dev/null 2>&1; then
    echo "npx is required."
    exit 1
fi

if ! command -v hdiutil >/dev/null 2>&1; then
    echo "hdiutil is required (macOS)."
    exit 1
fi

if ! command -v makensis >/dev/null 2>&1; then
    echo "makensis (NSIS) is required."
    exit 1
fi

ASAR_FILE="${BUILD_DIR}/launcher_shell.asar"
PACK_SRC_DIR="${BUILD_DIR}/electron_shell_pack_src"

echo "Preparing runtime template..."
rm -rf "${TEMPLATE_DIR}"
mkdir -p "${TEMPLATE_DIR}"

rsync -a \
    --delete \
    --exclude '.git/' \
    --exclude '_desktop_build/' \
    --exclude '_release/' \
    --exclude 'node_modules/' \
    --exclude '.installed_lock' \
    --exclude '.app_env' \
    --exclude '.app_env.*' \
    --exclude 'desktop_runtime/.env' \
    --exclude 'uploads/*' \
    "${ROOT_DIR}/" "${TEMPLATE_DIR}/"

mkdir -p "${TEMPLATE_DIR}/uploads"
touch "${TEMPLATE_DIR}/uploads/.gitkeep"

mkdir -p "${INSTALLERS_DIR}"

echo "Packing launcher ASAR..."
mkdir -p "${PACK_SRC_DIR}"
rsync -a --delete --exclude 'app.asar' "${SHELL_DIR}/" "${PACK_SRC_DIR}/"
npx --yes asar pack "${PACK_SRC_DIR}" "${ASAR_FILE}" >/dev/null

echo "Building Electron apps..."
pushd "${SHELL_DIR}" >/dev/null
npx --yes electron-packager . "Arab Eagles ERP Desktop" \
    --platform=darwin \
    --arch=arm64 \
    --overwrite \
    --out "${DIST_DIR}" \
    --asar \
    --app-version "1.0.0" \
    --electron-version "${ELECTRON_VERSION}" \
    --extra-resource "${TEMPLATE_DIR}"

popd >/dev/null

MAC_APP_RES="${DIST_DIR}/Arab Eagles ERP Desktop-darwin-arm64/Arab Eagles ERP Desktop.app/Contents/Resources"
cp "${ASAR_FILE}" "${MAC_APP_RES}/app.asar"
rm -rf "${MAC_APP_RES}/runtime_template"
cp -R "${TEMPLATE_DIR}" "${MAC_APP_RES}/runtime_template"

WIN_APP_DIR="${DIST_DIR}/Arab Eagles ERP Desktop-win32-x64"
if command -v wine64 >/dev/null 2>&1; then
    pushd "${SHELL_DIR}" >/dev/null
    npx --yes electron-packager . "Arab Eagles ERP Desktop" \
        --platform=win32 \
        --arch=x64 \
        --overwrite \
        --out "${DIST_DIR}" \
        --asar \
        --electron-version "${ELECTRON_VERSION}" \
        --extra-resource "${TEMPLATE_DIR}"
    popd >/dev/null
else
    echo "wine64 not found: using existing Windows app base and patching resources."
    WIN_BASE_DIR=""
    if [ -d "${BUILD_DIR}/Arab Eagles ERP Desktop-win32-x64" ]; then
        WIN_BASE_DIR="${BUILD_DIR}/Arab Eagles ERP Desktop-win32-x64"
    else
        WIN_BASE_DIR="$(ls -dt "${BUILD_DIR}"/electron_dist_*/"Arab Eagles ERP Desktop-win32-x64" 2>/dev/null | head -n 1 || true)"
    fi
    if [ -z "${WIN_BASE_DIR}" ] || [ ! -d "${WIN_BASE_DIR}" ]; then
        echo "No existing Windows app base found."
        exit 1
    fi
    rm -rf "${WIN_APP_DIR}"
    mkdir -p "${DIST_DIR}"
    cp -R "${WIN_BASE_DIR}" "${WIN_APP_DIR}"
fi

WIN_APP_RES="${WIN_APP_DIR}/resources"
cp "${ASAR_FILE}" "${WIN_APP_RES}/app.asar"
rm -rf "${WIN_APP_RES}/runtime_template"
cp -R "${TEMPLATE_DIR}" "${WIN_APP_RES}/runtime_template"

echo "Building macOS DMG..."
DMG_ROOT="${DIST_DIR}/dmg_root"
MAC_APP_DIR="${DIST_DIR}/Arab Eagles ERP Desktop-darwin-arm64/Arab Eagles ERP Desktop.app"
MAC_DMG="${INSTALLERS_DIR}/ArabEaglesERP-Desktop-macOS-${STAMP}.dmg"
rm -rf "${DMG_ROOT}"
mkdir -p "${DMG_ROOT}"
cp -R "${MAC_APP_DIR}" "${DMG_ROOT}/"
hdiutil create -volname "Arab Eagles Desktop" -srcfolder "${DMG_ROOT}" -ov -format UDZO "${MAC_DMG}" >/dev/null

echo "Building Windows installer..."
WIN_DIST_DIR="${WIN_APP_DIR}"
WIN_EXE="${INSTALLERS_DIR}/ArabEaglesERP-Desktop-Windows-${STAMP}.exe"
WIN_NSI="${BUILD_DIR}/windows_installer_auto_${STAMP}.nsi"

cat > "${WIN_NSI}" <<NSI
Unicode true
!define APPNAME "Arab Eagles ERP Desktop"
Name "\${APPNAME}"
OutFile "${WIN_EXE}"
InstallDir "\$PROGRAMFILES\\Arab Eagles ERP Desktop"
RequestExecutionLevel admin
SetCompressor /SOLID lzma

Page directory
Page instfiles
UninstPage uninstConfirm
UninstPage instfiles

Section "Install"
  SetOutPath "\$INSTDIR"
  File /r "${WIN_DIST_DIR}/*"

  CreateDirectory "\$SMPROGRAMS\\Arab Eagles ERP Desktop"
  CreateShortcut "\$SMPROGRAMS\\Arab Eagles ERP Desktop\\Arab Eagles ERP Desktop.lnk" "\$INSTDIR\\Arab Eagles ERP Desktop.exe"
  CreateShortcut "\$DESKTOP\\Arab Eagles ERP Desktop.lnk" "\$INSTDIR\\Arab Eagles ERP Desktop.exe"

  WriteUninstaller "\$INSTDIR\\Uninstall.exe"
  WriteRegStr HKLM "Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\ArabEaglesERPDesktop" "DisplayName" "Arab Eagles ERP Desktop"
  WriteRegStr HKLM "Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\ArabEaglesERPDesktop" "UninstallString" '"\$INSTDIR\\Uninstall.exe"'
SectionEnd

Section "Uninstall"
  Delete "\$DESKTOP\\Arab Eagles ERP Desktop.lnk"
  Delete "\$SMPROGRAMS\\Arab Eagles ERP Desktop\\Arab Eagles ERP Desktop.lnk"
  RMDir "\$SMPROGRAMS\\Arab Eagles ERP Desktop"
  RMDir /r "\$INSTDIR"
  DeleteRegKey HKLM "Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\ArabEaglesERPDesktop"
SectionEnd
NSI

makensis "${WIN_NSI}" >/dev/null

echo ""
echo "Done."
echo "Output: ${OUT_DIR}"
echo "DMG: ${MAC_DMG}"
echo "EXE: ${WIN_EXE}"
