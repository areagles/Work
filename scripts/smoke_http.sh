#!/usr/bin/env bash
set -euo pipefail

TARGET="${1:-$(pwd)}"
PORT="${2:-8099}"
TMP="$(mktemp -d)"
COOKIE="${TMP}/cookie.txt"
LOG="${TMP}/server.log"
PID=""
LOCK=""
CREATED_LOCK=0

search_file() {
  local pattern="$1"
  local file="$2"
  if command -v rg >/dev/null 2>&1; then
    rg -n "${pattern}" "${file}" >/dev/null 2>&1
  else
    grep -En "${pattern}" "${file}" >/dev/null 2>&1
  fi
}

extract_csrf() {
  local file="$1"
  if command -v rg >/dev/null 2>&1; then
    rg -o 'name="_csrf_token" value="[^"]+"' "${file}" | head -n1 | sed -E 's/.*value="([^"]+)"/\1/' || true
  else
    grep -Eo 'name="_csrf_token" value="[^"]+"' "${file}" | head -n1 | sed -E 's/.*value="([^"]+)"/\1/' || true
  fi
}

cleanup() {
  if [ -n "${PID}" ]; then
    kill "${PID}" >/dev/null 2>&1 || true
    wait "${PID}" 2>/dev/null || true
  fi
  if [ "${CREATED_LOCK}" -eq 1 ] && [ -n "${LOCK}" ]; then
    rm -f "${LOCK}"
  fi
  rm -rf "${TMP}"
}
trap cleanup EXIT

if [ -d "${TARGET}" ]; then
  DIR="${TARGET}"
  LOCK="${DIR}/.installed_lock"
  if [ ! -f "${LOCK}" ]; then
    touch "${LOCK}"
    CREATED_LOCK=1
  fi
  (
    cd "${DIR}"
    php -S 127.0.0.1:"${PORT}" >"${LOG}" 2>&1 &
    echo $! > "${TMP}/pid"
  )
  PID="$(cat "${TMP}/pid")"
  BASE="http://127.0.0.1:${PORT}"
  sleep 1
else
  BASE="${TARGET%/}"
  : > "${LOG}"
fi

echo "SMOKE_BASE=${BASE}"

fetch_code() {
  local path="$1"
  local outfile="$2"
  curl -sS -L --max-redirs 5 -c "${COOKIE}" -b "${COOKIE}" -o "${outfile}" -w "%{http_code}" "${BASE}${path}"
}

check_route() {
  local label="$1"
  local path="$2"
  local outfile="${TMP}/$(echo "${label}" | tr ' /' '__').html"
  local code
  code="$(fetch_code "${path}" "${outfile}")"
  echo "[${label}] code=${code} path=${path}"
  if search_file "Fatal error|Parse error|Uncaught|TypeError|mysqli_sql_exception|Call to undefined|Stack trace" "${outfile}"; then
    echo "  fatal_in_body=yes"
    return 1
  fi
  if search_file "وضع الصيانة|خطأ اتصال|Database configuration is missing|DB_USER|DB_NAME|MYSQL_USER|MYSQL_DATABASE" "${outfile}"; then
    echo "  maintenance_or_db_error=yes"
    return 1
  fi
if [ "${code}" != "200" ]; then
    echo "  unexpected_http_code=yes"
    return 1
  fi
  return 0
}

echo "[1] GET /login.php"
LOGIN_HTML="${TMP}/login.html"
CODE_LOGIN="$(fetch_code "/login.php" "${LOGIN_HTML}")"
echo "  code=${CODE_LOGIN}"

if search_file "وضع الصيانة|خطأ اتصال|Database configuration is missing|DB_USER|DB_NAME|MYSQL_USER|MYSQL_DATABASE" "${LOGIN_HTML}"; then
  echo "  status=BLOCKED (maintenance or DB config issue)"
  exit 1
fi

CSRF="$(extract_csrf "${LOGIN_HTML}")"
IS_SAAS_GATEWAY_LOGIN="no"
if search_file "دخول عملاء SaaS|SaaS Login|tenant code|كود المستأجر" "${LOGIN_HTML}"; then
  IS_SAAS_GATEWAY_LOGIN="yes"
fi
echo "  csrf=$([ -n "${CSRF}" ] && echo ok || echo missing)"
echo "  saas_gateway_login=${IS_SAAS_GATEWAY_LOGIN}"

echo "[2] POST /login.php (wrong credentials)"
if [ -n "${CSRF}" ] && [ "${IS_SAAS_GATEWAY_LOGIN}" = "no" ]; then
  POST_HTML="${TMP}/post.html"
  CODE_POST="$(curl -sS -L --max-redirs 5 -b "${COOKIE}" -c "${COOKIE}" -o "${POST_HTML}" -w "%{http_code}" -X POST "${BASE}/login.php" \
    --data-urlencode "_csrf_token=${CSRF}" \
    --data-urlencode "login_identity=wrong_user" \
    --data-urlencode "password=wrong_pass")"
  echo "  code=${CODE_POST}"
  if search_file "بيانات الدخول غير صحيحة|Invalid credentials|attempts remaining|المحاولات المتبقية" "${POST_HTML}"; then
    echo "  invalid_login_guard=yes"
  else
    echo "  invalid_login_guard=no"
  fi
else
  echo "  skipped=yes"
fi

echo "[3] Core guarded routes"
FAILURES=0
for entry in \
  "dashboard:/dashboard.php" \
  "finance:/finance.php" \
  "invoices:/invoices.php" \
  "tax_reports:/tax_reports.php" \
  "inventory_audit:/inventory_audit.php"
do
  label="${entry%%:*}"
  path="${entry#*:}"
  if ! check_route "${label}" "${path}"; then
    FAILURES=$((FAILURES + 1))
  fi
done

echo "[4] Public token route"
REVIEW_HTML="${TMP}/review.html"
CODE_REVIEW="$(fetch_code "/client_review.php?token=invalid-smoke-token" "${REVIEW_HTML}")"
echo "  code=${CODE_REVIEW}"
if search_file "Fatal error|Parse error|Uncaught|TypeError|mysqli_sql_exception" "${REVIEW_HTML}"; then
  echo "  public_route_fatal=yes"
  FAILURES=$((FAILURES + 1))
else
  echo "  public_route_fatal=no"
fi

if [ -s "${LOG}" ] && search_file "Fatal error|Parse error|Uncaught|TypeError|mysqli_sql_exception" "${LOG}"; then
  echo "[!] runtime_fatal=yes"
  FAILURES=$((FAILURES + 1))
else
  echo "[+] runtime_fatal=no"
fi

echo "SMOKE_FAILURES=${FAILURES}"
if [ "${FAILURES}" -gt 0 ]; then
  exit 1
fi
