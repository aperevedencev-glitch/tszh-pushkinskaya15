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
SECRET=test_secret_$(date +%s)
FAILED=()

run() { local name=$1; shift; echo; echo "━━ $name"; if "$@"; then echo "━━ $name: OK"; else echo "━━ $name: FAIL"; FAILED+=("$name"); fi; }

echo "▶ Чистая установка в $D"
mkdir -p "$D"
rsync -a --delete --exclude 'config.php' --exclude 'data/*' --exclude 'logs/*' public private "$D/"
rm -f "$D"/private/data/*.sqlite* "$D"/private/logs/*.log
cat > "$D/private/config.php" <<PHP
<?php
\$c = require __DIR__ . '/config.example.php';
\$c['site_url'] = 'http://127.0.0.1:$PORT';
\$c['db'] = ['driver' => 'sqlite', 'path' => __DIR__ . '/data/crm.sqlite'];
\$c['app_secret'] = '$(php -r 'echo bin2hex(random_bytes(32));')';
\$c['telegram']['bot_token'] = '123:TEST';
\$c['telegram']['webhook_secret'] = '$SECRET';
\$c['telegram']['api_base'] = 'http://127.0.0.1:8091';
\$c['work_hours'] = ['days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '18:00'];
return \$c;
PHP
php tests/seed_demo.php "$D/private" --users-only
chown -R www-data:www-data "$D"
chmod 777 "$MOCK"; echo ok > "$MOCK/mode"; chmod 666 "$MOCK/mode"
pkill -f "mock_telegram.php" 2>/dev/null; sleep 0.3
(MOCK_DIR="$MOCK" nohup php -S 127.0.0.1:8091 tests/mock_telegram.php >/dev/null 2>&1 &)
apachectl -k restart >/dev/null 2>&1 || apachectl -k start
sleep 1

export BASE_URL="http://127.0.0.1:$PORT" MOCK_DIR="$MOCK" DB_PATH="$D/private/data/crm.sqlite" PRIVATE_DIR="$D/private" WEBHOOK_SECRET="$SECRET"
export REPORT_JSON="${SHOTS:+$SHOTS/scenarios.json}"
[ -n "$SHOTS" ] && mkdir -p "$SHOTS"

run "Этап 4: 8 сквозных сценариев" python3 tests/scenarios_test.py ${SHOTS:+"$SHOTS"}
run "Ядро (SQLite)"            php tests/core_test.php
run "Бот (SQLite)"             php tests/bot_test.php
run "CRM-логика (SQLite)"      php tests/crm_test.php
if [ -n "${TEST_DB_PASS:-}" ]; then
  run "Ядро (MySQL)"           env TEST_DB=mysql php tests/core_test.php
  run "Бот (MySQL)"            env TEST_DB=mysql php tests/bot_test.php
  run "CRM-логика (MySQL)"     env TEST_DB=mysql php tests/crm_test.php
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
