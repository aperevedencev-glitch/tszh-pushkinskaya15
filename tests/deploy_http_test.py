"""Этап 5 по-настоящему: Apache, браузер, cron из консоли, имитация Telegram.

  python3 tests/deploy_http_test.py install   — мастер установки на пустой базе (до создания сотрудников)
  python3 tests/deploy_http_test.py health    — /crm/health, бэкапы, вебхук, HTTPS, восстановление (после seed_demo --users-only)

Переменные: BASE_URL, MOCK_DIR, DB_PATH, PRIVATE_DIR, PUBLIC_DIR, INSTALL_KEY, [SHOTS].
"""
import json
import os
import re
import sqlite3
import subprocess
import sys
import time
import urllib.error
import urllib.request

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE_URL", "http://127.0.0.1:8081")
MOCK = os.environ["MOCK_DIR"]
DB = os.environ["DB_PATH"]
PRIV = os.environ["PRIVATE_DIR"]
PUB = os.environ.get("PUBLIC_DIR", os.path.join(os.path.dirname(PRIV), "public"))
KEY = os.environ.get("INSTALL_KEY", "")
SHOTS = os.environ.get("SHOTS", "")
PASS = "Test-Passw0rd!"
REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
fails = []


def check(cond, msg):
    print(("✓ " if cond else "✗ ") + msg)
    if not cond:
        fails.append(msg)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


def http(path, data=None, headers=None, follow=False):
    req = urllib.request.Request(BASE + path, data=data, headers=headers or {})
    opener = urllib.request.build_opener() if follow else urllib.request.build_opener(NoRedirect)
    try:
        with opener.open(req, timeout=20) as r:
            return r.status, dict(r.headers), r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers), e.read().decode()


def php(code):
    """Выполнить PHP с загруженным приложением (от имени веб-сервера, как cron на хостинге)."""
    return subprocess.run(["sudo", "-u", "www-data", "php", "-r", f"require '{PRIV}/bootstrap.php'; {code}"],
                          capture_output=True, text=True).stdout


def cfg(path):
    return php(f"echo App::cfg('{path}');")


def q(sql, *args):
    # пока базы нет — не открываем: sqlite3 создал бы пустой файл от root, и сайт не смог бы в него писать
    if not os.path.exists(DB):
        raise FileNotFoundError(DB)
    con = sqlite3.connect(DB)
    try:
        return con.execute(sql, args).fetchall()
    finally:
        con.close()


def mock_calls(method):
    try:
        with open(f"{MOCK}/calls.jsonl") as f:
            return [c for c in map(json.loads, f) if c["method"] == method]
    except FileNotFoundError:
        return []


def set_site_url(url):
    path = f"{PRIV}/config.php"
    src = open(path).read()
    src = re.sub(r"'site_url' => '[^']*'", f"'site_url' => '{url}'", src, count=1)
    open(path, "w").write(src)
    time.sleep(3)   # opcache в Apache перечитывает файлы не чаще раза в 2 с


def shot(page, name):
    if SHOTS:
        os.makedirs(SHOTS, exist_ok=True)
        page.screenshot(path=f"{SHOTS}/{name}.png", full_page=True)


def no_overflow(page):
    return page.evaluate("document.documentElement.scrollWidth <= window.innerWidth")


# ------------------------------------------------------------------ установка
def test_install(br):
    print("\nМастер установки /install.php")
    secrets_before = (cfg("app_secret"), cfg("telegram.webhook_secret"))
    check(secrets_before == ("", ""), "до установки app_secret и webhook_secret в config.php пустые")

    p = br.new_page(viewport={"width": 390, "height": 844}, is_mobile=True)
    r = p.goto(f"{BASE}/install.php")
    check(r.status == 200 and "Установка CRM" in p.content(), "мастер открывается до установки")
    fails_red = p.locator(".check.fail").count()
    check(fails_red == 0, f"проверка сервера: красных пунктов {fails_red}")
    check(p.locator(".check.warn", has_text="https").count() == 1, "адрес без https помечен «можно позже», установку не блокирует")
    check(no_overflow(p), "мастер без горизонтальной прокрутки на 390 px")
    shot(p, "s5-install-form")

    code, _, body = http("/install.php", b"install_key=x&login=evil&name=x&password=Evil-Passw0rd!&password2=Evil-Passw0rd!",
                         {"Content-Type": "application/x-www-form-urlencoded"})
    check(code == 403, "POST без CSRF-токена — 403")

    def submit(key, login, name, pw, pw2=None):
        p.fill("#i-key", key)
        p.fill("#i-name", name)
        p.fill("#i-login", login)
        p.fill("#i-pass", pw)
        p.fill("#i-pass2", pw2 if pw2 is not None else pw)
        p.click("button[type=submit]")
        p.wait_for_load_state()
        return p.content()

    html = submit("неверный-ключ", "boss", "Руководитель Ирина", PASS)
    check("Неверный ключ установки" in html, "неверный ключ установки — отказ")
    check(cfg("app_secret") == "" and (not os.path.exists(DB) or q("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")[0][0] == 0),
          "с неверным ключом мастер ничего не записал: ни секретов в config.php, ни таблиц")
    html = submit(KEY, "boss", "Руководитель Ирина", "boss123456")
    check("не должен содержать логин" in html, "пароль, содержащий логин, — отказ")
    html = submit(KEY, "boss", "Руководитель Ирина", PASS, PASS + "x")
    check("Пароли не совпадают" in html, "несовпадающие пароли — отказ")
    check(not os.path.exists(DB) or q("SELECT COUNT(*) FROM sqlite_master WHERE name = 'users'")[0][0] == 0
          or q("SELECT COUNT(*) FROM users")[0][0] == 0, "после отказов сотрудников в базе нет")

    html = submit(KEY, "boss", "Руководитель Ирина", PASS)
    check("Установка завершена" in html, "установка прошла")
    shot(p, "s5-install-done")
    cron_cmd = p.locator("code").first.text_content()
    check(cron_cmd.endswith("/private/cron/run.php") and "/bin/php -f" in cron_cmd, f"мастер показал команду для cron: {cron_cmd}")
    users = q("SELECT login, role, password_hash FROM users")
    check(len(users) == 1 and users[0][:2] == ("boss", "admin") and users[0][2].startswith("$2y$"), "создан один руководитель, пароль — bcrypt-хеш")
    check(q("SELECT COUNT(*) FROM kb_articles")[0][0] >= 10, "стартовые ответы бота загружены")
    check(not os.path.exists(f"{PUB}/install.php"), "файл install.php удалил сам себя")
    check(os.path.exists(f"{PRIV}/data/install.lock"), "создан замок private/data/install.lock")
    app_secret, wh_secret = cfg("app_secret"), cfg("telegram.webhook_secret")
    check(re.fullmatch(r"[0-9a-f]{64}", app_secret) is not None, "мастер вписал app_secret в config.php (64 hex)")
    check(re.fullmatch(r"[A-Za-z0-9_-]{16,256}", wh_secret) is not None, "мастер вписал webhook_secret в config.php")
    check(oct(os.stat(f"{PRIV}/config.php").st_mode & 0o777) == "0o600", "config.php — права 600")
    check(app_secret not in html and wh_secret not in html and KEY not in html, "секреты и ключ установки не показаны на странице")

    code, _, _ = http("/install.php")
    check(code == 404, "повторно /install.php — 404 (файла нет)")
    subprocess.run(["cp", f"{REPO}/public/install.php", f"{PUB}/install.php"], check=True)
    subprocess.run(["chown", "www-data:www-data", f"{PUB}/install.php"], check=True)
    code, _, body = http("/install.php")
    check(code == 404 and "Установка уже выполнена" in body, "даже если вернуть install.php — мастер заблокирован (404)")
    os.remove(f"{PUB}/install.php")

    p.goto(f"{BASE}/crm/login.php")
    p.fill("#l-login", "boss")
    p.fill("#l-pass", PASS)
    p.click("button[type=submit]")
    p.wait_for_load_state()
    check("/crm/" in p.url and "Выйти" in p.content(), "руководитель входит в CRM с паролем из мастера")
    p.close()


# ------------------------------------------------------------------ состояние системы
def login(br, who, width=1280):
    ctx = br.new_context(viewport={"width": width, "height": 900})
    p = ctx.new_page()
    p.goto(f"{BASE}/crm/login.php")
    p.fill("#l-login", who)
    p.fill("#l-pass", PASS)
    p.click("button[type=submit]")
    p.wait_for_load_state()
    return ctx, p


def status_of(p, title):
    li = p.locator(f'.check[data-check="{title}"]')
    return (li.get_attribute("class") or "").replace("check", "").strip(), li.locator("p").text_content()


def test_health(br):
    print("\nСостояние системы /crm/health")
    code, h, _ = http("/crm/health")
    check(code in (302, 303) and "login.php" in h.get("Location", ""), "без входа — переадресация на вход")

    ctx, p = login(br, "anna")
    r = p.goto(f"{BASE}/crm/health")
    check(r.status == 403, "менеджер — 403")
    check(p.locator('nav a[href="/crm/health.php"]').count() == 0, "у менеджера нет пункта «Состояние»")
    ctx.close()

    # cron и бэкап — как на хостинге: от имени пользователя сайта
    subprocess.run(["sudo", "-u", "www-data", "php", f"{PRIV}/cron/run.php"], check=True)
    out = subprocess.run(["sudo", "-u", "www-data", "php", f"{PRIV}/cron/run.php", "backup", "-v"], capture_output=True, text=True).stdout
    files = sorted(f for f in os.listdir(f"{PRIV}/backups") if f.startswith("db-"))
    check(len(files) >= 1 and files[-1] in out, f"ручной запуск cron backup создал {files[-1] if files else '—'}")
    st = os.stat(f"{PRIV}/backups/{files[-1]}")
    check(oct(st.st_mode & 0o777) == "0o600", "бэкап — права 600, доступен только владельцу")
    code, _, _ = http("/../private/backups/" + files[-1])
    check(code in (400, 403, 404), f"бэкап не скачивается с сайта (HTTP {code})")

    ctx, p = login(br, "boss")
    r = p.goto(f"{BASE}/crm/health")
    check(r.status == 200 and "Состояние системы" in p.content(), "руководитель открывает /crm/health")
    for title in ["Подключение", "Таблицы", "Cron", "Бэкап базы", "Закрытая папка вне сайта", "Мастер установки", "Секреты", "Версия PHP"]:
        s, d = status_of(p, title)
        check(s == "ok", f"«{title}» — {s}: {d.strip()[:90]}")
    s, d = status_of(p, "Вебхук бота")
    check(s == "fail" and "https://" in d, f"сайт без https — вебхук нельзя подключить: {d.strip()[:80]}")
    check(p.locator("#h-confirm").is_disabled(), "кнопка подключения вебхука заблокирована без https")
    s, d = status_of(p, "Последняя ошибка")
    check(s in ("ok", "warn"), f"последняя ошибка: {d.strip()[:80]}")
    html = p.content()
    secrets = [cfg("telegram.bot_token"), cfg("telegram.webhook_secret"), cfg("app_secret"), KEY]
    check(all(x and x not in html for x in secrets), "на странице нет токена бота, секретов и ключа установки")
    shot(p, "s5-health")

    # подключение вебхука на «боевой» домен — только с кодовым словом
    set_site_url("https://tszh-test.ru")
    p.goto(f"{BASE}/crm/health")
    before = len(mock_calls("setWebhook"))
    p.fill("#h-confirm", "да")
    p.click("text=Подключить вебхук")
    p.wait_for_load_state()
    check("ПОДКЛЮЧИТЬ" in p.locator(".flash").text_content() and len(mock_calls("setWebhook")) == before, "без кодового слова вебхук не подключается")
    p.fill("#h-confirm", "ПОДКЛЮЧИТЬ")
    p.click("text=Подключить вебхук")
    p.wait_for_load_state()
    calls = mock_calls("setWebhook")
    last = calls[-1]["params"] if calls else {}
    check(len(calls) == before + 1, "кодовое слово — setWebhook отправлен один раз")
    check(last.get("url") == "https://tszh-test.ru/bot/webhook.php", f"адрес вебхука: {last.get('url')}")
    check(last.get("secret_token") == cfg("telegram.webhook_secret"), "передан secret_token из config.php")
    check(mock_calls("getWebhookInfo") and mock_calls("getWebhookInfo")[-1]["t"] > calls[-1]["t"], "сразу после — проверка getWebhookInfo")
    s, d = status_of(p, "Вебхук бота")
    check(s == "ok", f"вебхук — {s}: {d.strip()[:90]}")
    check(q("SELECT COUNT(*) FROM audit_log WHERE entity = 'webhook' AND action = 'set'")[0][0] == 1, "подключение записано в audit_log")
    set_site_url(BASE)

    p.click("text=Сделать бэкап сейчас")
    p.wait_for_load_state()
    check("Бэкап создан" in p.locator(".flash").text_content(), "кнопка «Сделать бэкап сейчас» работает")
    code, _, _ = http("/crm/health.php", b"action=backup_now", {"Content-Type": "application/x-www-form-urlencoded", "Cookie": "; ".join(f"{c['name']}={c['value']}" for c in ctx.cookies())})
    check(code == 403, "POST без CSRF-токена — 403")
    ctx.close()

    ctx, p = login(br, "boss", 390)
    p.goto(f"{BASE}/crm/health")
    check(no_overflow(p), "/crm/health на 390 px без горизонтальной прокрутки")
    shot(p, "s5-health-mobile")
    ctx.close()

    print("\nВосстановление из бэкапа (private/tools/restore.php)")
    files = sorted(f for f in os.listdir(f"{PRIV}/backups") if f.startswith("db-"))
    deals_before = q("SELECT COUNT(*) FROM deals")[0][0]
    php("Leads::capture(['channel'=>'site','name'=>'После бэкапа','phone'=>'+79035559999','text'=>'x','consent'=>['ip'=>'127.0.0.1']]);")
    check(q("SELECT COUNT(*) FROM deals")[0][0] == deals_before + 1, "после бэкапа добавлена заявка")
    r = subprocess.run(["sudo", "-u", "www-data", "php", f"{PRIV}/tools/restore.php", files[-1]], input="нет\n", capture_output=True, text=True)
    check(r.returncode == 1 and q("SELECT COUNT(*) FROM deals")[0][0] == deals_before + 1, "без слова ВОССТАНОВИТЬ ничего не меняется")
    r = subprocess.run(["sudo", "-u", "www-data", "php", f"{PRIV}/tools/restore.php", files[-1]], input="ВОССТАНОВИТЬ\n", capture_output=True, text=True)
    check(r.returncode == 0 and q("SELECT COUNT(*) FROM deals")[0][0] == deals_before, "восстановлено состояние на момент бэкапа")
    check("Текущее состояние сохранено" in r.stdout, "перед восстановлением сохранён страховочный бэкап")

    print("\nВебхук из консоли (private/tools/webhook.php)")
    r = subprocess.run(["sudo", "-u", "www-data", "php", f"{PRIV}/tools/webhook.php", "info"], capture_output=True, text=True)
    check(r.returncode == 0 and "url: https://tszh-test.ru/bot/webhook.php" in r.stdout, "info показывает getWebhookInfo")
    check(cfg("telegram.webhook_secret") not in r.stdout + r.stderr, "секрет в консоль не выводится")
    r = subprocess.run(["sudo", "-u", "www-data", "php", f"{PRIV}/tools/webhook.php", "set", "--confirm=127.0.0.1"], capture_output=True, text=True)
    check(r.returncode == 1 and "https://" in r.stderr, "set с http-адресом сайта — отказ")

    print("\nHTTPS")
    code, h, _ = http("/politika/", headers={"Host": "tszh-test.ru"})
    check(code == 301 and h.get("Location") == "https://tszh-test.ru/politika/", f"http:// → 301 на https:// ({h.get('Location')})")
    os.makedirs(f"{PUB}/.well-known/acme-challenge", exist_ok=True)
    with open(f"{PUB}/.well-known/acme-challenge/token123", "w") as f:
        f.write("token123.check")
    code, h, body = http("/.well-known/acme-challenge/token123", headers={"Host": "tszh-test.ru"})
    check(code == 200 and body == "token123.check", f"файл проверки SSL-сертификата Let's Encrypt открывается по http (HTTP {code})")
    subprocess.run(["rm", "-rf", f"{PUB}/.well-known"])
    code, h, _ = http("/.env", headers={"Host": "tszh-test.ru", "X-Forwarded-Proto": "https"})
    check(code == 403, f"служебные файлы (.env, .htaccess) закрыты (HTTP {code})")
    code, h, _ = http("/", headers={"Host": "tszh-test.ru", "X-Forwarded-Proto": "https"})
    check(code == 200 and h.get("Strict-Transport-Security") == "max-age=31536000", "по https (за прокси Reg.ru) — 200 и HSTS")
    code, h, _ = http("/")
    check(code == 200 and "Strict-Transport-Security" not in h, "по http на локальной машине — без HSTS")


with sync_playwright() as pw:
    br = pw.chromium.launch()
    mode = sys.argv[1] if len(sys.argv) > 1 else "install"
    (test_install if mode == "install" else test_health)(br)
    br.close()

print(f"\nИтого ошибок: {len(fails)}")
sys.exit(1 if fails else 0)
