#!/usr/bin/env bash
# Архив для загрузки на хостинг через файловый менеджер Reg.ru.
# В архиве: public/ (→ в папку сайта) и private/ (→ в домашнюю папку, рядом с www).
# Без секретов, базы, журналов и бэкапов. Использование: bash dev/build_release.sh
set -euo pipefail
cd "$(dirname "$0")/.."
VER=$(date +%Y%m%d-%H%M)
OUT=dist/tszh-pushkinskaya15-$VER.zip
mkdir -p dist
rm -f "$OUT"
zip -qr "$OUT" public private \
  -x 'private/config.php' 'private/data/*' 'private/logs/*' 'private/backups/*' '*.DS_Store' \
  -x 'public/.well-known/*'
# пустые служебные папки с защитой нужны на сервере
zip -q "$OUT" private/data/.gitkeep private/logs/.gitkeep private/backups/.gitkeep
# контроль: в архиве не должно быть секретов и данных
if unzip -l "$OUT" | grep -E 'config\.php$|\.sqlite|db-.*\.sql\.gz|\.log$' | grep -v 'config\.example\.php'; then
  echo "В архив попали лишние файлы" >&2; rm -f "$OUT"; exit 1
fi
echo "$OUT ($(du -h "$OUT" | cut -f1)), файлов: $(unzip -Z1 "$OUT" | grep -vc '/$')"
