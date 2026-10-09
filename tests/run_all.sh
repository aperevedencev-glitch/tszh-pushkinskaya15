#!/usr/bin/env bash
# Полный прогон всех автотестов на локальной копии «как на хостинге».
# Требует: PHP 8.1+ (CLI и модуль Apache), Apache, MariaDB/MySQL (необязательно), Python 3 + Playwright, sudo.
# Использование: sudo bash tests/run_all.sh [папка_для_скриншотов]
set -uo pipefail
cd "$(dirname "$0")/.."
REPO=$(pwd)
D=${DEPLOY_DIR:-/var/www/tszh}           # публичная и закрытая папки, как на хостинге
PORT=${PORT:-8081}
MOCK=${MOCK_DIR:-$(mktemp -d)}
SHOTS=${1:-}
FAILED=()

run() { local name=$1; shift; echo; echo "━━ $name"; if "$@"; then echo "━━ $name: OK"; else echo "━━ $name: FAIL"; FAILED+=("$name"); fi; }

echo "▶ Чистая установка в $D"
mkdir -p "$D"
rsync -a --delete --exclude 'config.php' --exclude 'data/*' --exclude 'logs/*' public private "$D/"
rm -rf "$D"/private/data/*.sqlite* "$D"/private/data/install.lock "$D"/private/data/sessions "$D"/private/logs/*.log "$D"/private/backups/db-*
INSTALL_KEY=local_install_$(date +%s)
php tests/make_config.php "$D/private" "http://127.0.0.1:$PORT" "http://127.0.0.1:8091" "$INSTALL_KEY" || exit 1
chown -R www-data:www-data "$D"
chmod 777 "$MOCK"; echo ok > "$MOCK/mode"; chmod 666 "$MOCK/mode"; rm -f "$MOCK/webhook.json"
pkill -f "mock_telegram.php" 2>/dev/null; sleep 0.3
(MOCK_DIR="$MOCK" nohup php -S 127.0.0.1:8091 tests/mock_telegram.php >/dev/null 2>&1 &)
apachectl -k restart >/dev/null 2>&1 || apachectl -k start
sleep 1

export BASE_URL="http://127.0.0.1:$PORT" MOCK_DIR="$MOCK" DB_PATH="$D/private/data/crm.sqlite" PRIVATE_DIR="$D/private" PUBLIC_DIR="$D/public"
export INSTALL_KEY SHOTS REPORT_JSON="${SHOTS:+$SHOTS/scenarios.json}"
[ -n "$SHOTS" ] && mkdir -p "$SHOTS"

# Этап 5: установка через мастер — он создаёт таблицы, руководителя boss и вписывает секреты в config.php
run "Этап 5: мастер установки"  python3 tests/deploy_http_test.py install
WEBHOOK_SECRET=$(sudo -u www-data php -r "echo (require '$D/private/config.php')['telegram']['webhook_secret'];")
export WEBHOOK_SECRET
php tests/seed_demo.php "$D/private" --users-only >/dev/null && chown -R www-data:www-data "$D"

run "Этап 4: 8 сквозных сценариев" python3 tests/scenarios_test.py ${SHOTS:+"$SHOTS"}
run "Этап 5: состояние, бэкапы, вебхук, HTTPS" python3 tests/deploy_http_test.py health
run "Этап 5: логика (SQLite)"  php tests/deploy_test.php
run "Ядро (SQLite)"            php tests/core_test.php
run "Бот (SQLite)"             php tests/bot_test.php
run "CRM-логика (SQLite)"      php tests/crm_test.php
if [ -n "${TEST_DB_PASS:-}" ]; then
  run "Ядро (MySQL)"           env TEST_DB=mysql php tests/core_test.php
  run "Бот (MySQL)"            env TEST_DB=mysql php tests/bot_test.php
  run "CRM-логика (MySQL)"     env TEST_DB=mysql php tests/crm_test.php
  run "Этап 5: логика (MySQL)" env TEST_DB=mysql php tests/deploy_test.php
fi
run "Сайт в браузере"          python3 tests/e2e_site.py
run "Вебхук по HTTP"           python3 tests/webhook_http_test.py
# браузерный тест CRM работает на своих демо-данных
rm -f "$D"/private/data/*.sqlite*
php tests/seed_demo.php "$D/private" >/dev/null && chown -R www-data:www-data "$D"
run "CRM в браузере"           python3 tests/e2e_crm.py

echo
if [ ${#FAILED[@]} -eq 0 ]; then echo "✅ Все наборы тестов прошли"; exit 0; fi
echo "❌ Не прошли: ${FAILED[*]}"; exit 1
