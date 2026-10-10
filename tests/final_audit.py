"""Этап 6: итоговая проверка того, что не покрыто отдельными наборами.

  - все внутренние ссылки, якоря, картинки, стили и скрипты сайта отвечают 200;
  - страницы сайта и CRM на 375 и 390 px: без горизонтальной прокрутки, без ошибок в консоли, без запросов на чужие домены;
  - закрытые адреса (config.php, private/, бэкапы, служебные файлы) не открываются;
  - в публичной папке на сервере и во всей истории git нет секретов;
  - Lighthouse для главной и политики (если указан LIGHTHOUSE — путь к CLI).

Переменные: BASE_URL, PUBLIC_DIR, PRIVATE_DIR, [LIGHTHOUSE], [SHOTS]. Пользователи boss/anna из seed_demo.
"""
import json
import os
import re
import subprocess
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE_URL", "http://127.0.0.1:8081")
PUB = os.environ["PUBLIC_DIR"]
PRIV = os.environ["PRIVATE_DIR"]
LH = os.environ.get("LIGHTHOUSE", "")
SHOTS = os.environ.get("SHOTS", "")
REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PASS = "Test-Passw0rd!"
HOST = urllib.parse.urlparse(BASE).netloc
fails = []
report = {}


def check(cond, msg, key=None):
    print(("✓ " if cond else "✗ ") + msg)
    if not cond:
        fails.append(msg)
    if key:
        report.setdefault(key, []).append([bool(cond), msg])


def get(path):
    req = urllib.request.Request(path if path.startswith("http") else BASE + path)
    try:
        with urllib.request.urlopen(req, timeout=20) as r:
            return r.status, r.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()


class Refs(HTMLParser):
    def __init__(self):
        super().__init__()
        self.refs, self.ids = [], set()

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if a.get("id"):
            self.ids.add(a["id"])
        for k in ("href", "src"):
            if a.get(k):
                self.refs.append((tag, a[k]))
        if a.get("srcset"):
            for part in a["srcset"].split(","):
                self.refs.append((tag, part.strip().split(" ")[0]))
        if tag == "meta" and a.get("property") in ("og:image",) and a.get("content"):
            self.refs.append(("meta", a["content"]))


# ------------------------------------------------------------------ ссылки
def test_links():
    print("\nСсылки и ресурсы сайта")
    pages = ["/", "/politika/", "/404.html"]
    seen, external, broken, anchors_bad = set(), set(), [], []
    for page in pages:
        code, body = get(page)
        check(code == 200, f"{page} → {code}", "links")
        p = Refs()
        p.feed(body.decode())
        for tag, ref in p.refs:
            if ref.startswith(("tel:", "mailto:")):
                continue
            u = urllib.parse.urlparse(urllib.parse.urljoin(BASE + page, ref))
            if u.netloc and u.netloc != HOST:
                external.add(f"{u.scheme}://{u.netloc}{u.path}" + (" (картинка/скрипт!)" if tag in ("img", "script", "link", "source") else ""))
                continue
            if u.fragment and (u.path in ("", page) or u.path == urllib.parse.urlparse(BASE + page).path):
                if u.fragment not in p.ids:
                    anchors_bad.append(f"{page}#{u.fragment}")
                continue
            target = u.path + ("?" + u.query if u.query else "")
            if target in seen:
                continue
            seen.add(target)
            c, _ = get(target)
            if c != 200:
                broken.append(f"{target} → {c}")
    check(not broken, f"внутренние ссылки, картинки, стили, скрипты: проверено {len(seen)}, битых {len(broken)} {broken[:5]}", "links")
    check(not anchors_bad, f"якоря в меню ведут на существующие блоки {anchors_bad[:5]}", "links")
    res = [e for e in external if "картинка" in e]
    check(not res, f"ресурсов с чужих доменов нет {res}", "links")
    ok_ext = sorted(e for e in external if "t.me/" in e or "ВАШ-ДОМЕН" in urllib.parse.unquote(e) or "xn--" in e)
    other = sorted(set(external) - set(ok_ext))
    check(not other, f"внешние ссылки — только на бота в Telegram ({len(ok_ext)}) {other[:3]}", "links")


# ------------------------------------------------------------------ страницы в браузере
def watch(page, bucket):
    page.on("console", lambda m: m.type == "error" and bucket["errors"].append(m.text))
    page.on("pageerror", lambda e: bucket["errors"].append(str(e)))
    page.on("request", lambda r: urllib.parse.urlparse(r.url).netloc not in (HOST, "") and not r.url.startswith("data:") and bucket["external"].append(r.url))


def test_pages(br):
    print("\nСтраницы на 375 и 390 px")
    public_pages = ["/", "/politika/"]   # страницу 404 проверяет e2e_site.py (ответ 404 браузер всегда пишет в консоль)
    crm_pages = ["/crm/", "/crm/deals.php", "/crm/tasks.php", "/crm/reports.php", "/crm/kb.php", "/crm/users.php", "/crm/settings.php", "/crm/health", "/crm/profile.php"]
    for width in (375, 390):
        ctx = br.new_context(viewport={"width": width, "height": 844}, is_mobile=True, has_touch=True)
        page = ctx.new_page()
        b = {"errors": [], "external": []}
        watch(page, b)
        over = []
        for path in public_pages + ["/crm/login.php"]:
            page.goto(BASE + path, wait_until="networkidle")
            if not page.evaluate("document.documentElement.scrollWidth <= window.innerWidth"):
                over.append(path)
        page.fill("#l-login", "boss")
        page.fill("#l-pass", PASS)
        page.click("button[type=submit]")
        page.wait_for_load_state()
        deal = page.locator('a[href^="/crm/deal.php?id="]').first.get_attribute("href")
        for path in crm_pages + [deal]:
            page.goto(BASE + path, wait_until="networkidle")
            # канбан прокручивается внутри своей области — это нормально; страница целиком не должна
            if not page.evaluate("document.documentElement.scrollWidth <= window.innerWidth"):
                over.append(path)
        if SHOTS and width == 375:
            page.goto(BASE + deal, wait_until="networkidle")
            page.screenshot(path=f"{SHOTS}/final-deal-375.png", full_page=True)
        check(not over, f"{width} px: {len(public_pages) + len(crm_pages) + 2} страниц сайта и CRM без горизонтальной прокрутки {over}", "pages")
        check(not b["errors"], f"{width} px: консоль без ошибок {b['errors'][:2]}", "pages")
        check(not b["external"], f"{width} px: ни одного запроса на чужие домены {b['external'][:2]}", "pages")
        ctx.close()


# ------------------------------------------------------------------ закрытые адреса
def test_closed():
    print("\nЗакрытые адреса")
    paths = ["/config.php", "/private/config.php", "/../private/config.php", "/_boot.php", "/.htaccess", "/bot/.htaccess",
             "/crm/../../private/config.php", "/bot/anything.php", "/api/", "/assets/", "/install.php"]
    bad = []
    for p in paths:
        code, body = get(p)
        if code == 200 and (b"<?php" in body or b"return [" in body or b"bot_token" in body or b"Index of" in body):
            bad.append(f"{p} → {code}")
        elif code == 200 and p not in ("/api/",):
            bad.append(f"{p} → {code}")
    check(not bad, f"служебные файлы, config.php, private/, листинги папок, install.php не открываются ({len(paths)} адресов) {bad}", "closed")
    for f in os.listdir(f"{PRIV}/backups"):
        if f.startswith("db-"):
            code, _ = get("/backups/" + f)
            check(code == 404, f"бэкап по прямому адресу не скачивается (HTTP {code})", "closed")
            break


# ------------------------------------------------------------------ секреты
def test_secrets():
    print("\nСекреты")
    cfg = json.loads(subprocess.run(["php", "-r", f"echo json_encode(require '{PRIV}/config.php');"], capture_output=True, text=True).stdout)
    secrets = [s for s in [cfg["telegram"]["bot_token"], cfg["telegram"]["webhook_secret"], cfg["app_secret"], cfg.get("install_key", "")] if s and len(s) >= 6]
    hits = []
    for root, _, files in os.walk(PUB):
        for f in files:
            data = open(os.path.join(root, f), "rb").read()
            for s in secrets:
                if s.encode() in data:
                    hits.append(os.path.join(root, f))
    check(not hits, f"в публичной папке на сервере нет ни одного секрета из config.php ({sum(len(f) for _, _, f in os.walk(PUB))} файлов)", "secrets")
    tracked = subprocess.run(["git", "-C", REPO, "ls-files"], capture_output=True, text=True).stdout.split()
    check("private/config.php" not in tracked and not any(t.endswith((".sqlite", ".sql.gz", ".log")) for t in tracked),
          "config.php, база, бэкапы и журналы не в git", "secrets")
    hist = subprocess.run(["git", "-C", REPO, "log", "--all", "-p", "--no-color"], capture_output=True, text=True, errors="replace").stdout
    patterns = {
        "токен Telegram-бота": r"\b\d{8,10}:AA[A-Za-z0-9_-]{33}\b",
        "закрытый ключ": r"-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----",
        "ключ GitHub": r"\bgh[pousr]_[A-Za-z0-9]{36}\b",
    }
    commits = subprocess.run(["git", "-C", REPO, "rev-list", "--all", "--count"], capture_output=True, text=True).stdout.strip()
    if os.environ.get("TEST_DB_PASS"):
        patterns["пароль тестовой БД"] = re.escape(os.environ["TEST_DB_PASS"])
    # совпадения в tests/ — заведомые заглушки (например, строка в форме токена для проверки, что журнал её вырезает);
    # секретом считается только совпадение вне tests/
    found, fixtures, current = {}, [], None
    for line in hist.splitlines():
        if line.startswith("+++ "):
            current = line[4:].strip().removeprefix("b/")
            continue
        for k, v in patterns.items():
            if re.search(v, line):
                if current and current.startswith("tests/"):
                    fixtures.append(f"{current}: {k}")
                else:
                    found[k] = found.get(k, 0) + 1
    if fixtures:
        print(f"  (заглушки в тестах, не секреты: {sorted(set(fixtures))})")
    check(not found, f"во всей истории git ({commits} коммитов, все ветки) нет токенов, паролей и ключей {found}", "secrets")
    leaks = re.findall(r"^\+.*'(?:bot_token|webhook_secret|app_secret|pass|install_key)'\s*=>\s*'([^']+)'", hist, re.M)
    leaks = [v for v in leaks if v not in ("123:TEST", "TEST", "local") and not v.startswith(("ВАШ", "test", "local_install", "$"))]
    check(not leaks, f"в истории git нет заполненных секретов в настройках {leaks[:3]}", "secrets")


# ------------------------------------------------------------------ Lighthouse
def test_lighthouse():
    if not LH:
        print("\nLighthouse пропущен (нет LIGHTHOUSE)")
        return
    print("\nLighthouse")
    chrome = subprocess.run(["python3", "-c", "from playwright.sync_api import sync_playwright as s\nwith s() as p: print(p.chromium.executable_path)"],
                            capture_output=True, text=True).stdout.strip()
    for path in ["/", "/politika/"]:
        for preset in ("mobile", "desktop"):
            out = os.path.join(SHOTS or tempfile.gettempdir(), f"lh-{preset}-{path.strip('/') or 'index'}.json")
            args = [LH, BASE + path, "--quiet", "--output=json", f"--output-path={out}", f"--chrome-path={chrome}",
                    "--chrome-flags=--headless=new --no-sandbox", "--only-categories=performance,accessibility,best-practices,seo"]
            if preset == "desktop":
                args.append("--preset=desktop")
            subprocess.run(args, capture_output=True, text=True, timeout=240, env={**os.environ, "CHROME_PATH": chrome})
            try:
                cats = json.load(open(out))["categories"]
            except Exception as e:  # noqa
                check(False, f"Lighthouse {preset} {path}: не запустился ({e})", "lighthouse")
                continue
            sc = {k: round((v["score"] or 0) * 100) for k, v in cats.items()}
            # SEO на тестовом сервере снижает только noindex/robots для 127.0.0.1 — для доступности и практик порог 95
            check(sc["accessibility"] >= 95 and sc["best-practices"] >= 95 and sc["performance"] >= 85,
                  f"Lighthouse {preset} {path}: производительность {sc['performance']}, доступность {sc['accessibility']}, "
                  f"практики {sc['best-practices']}, SEO {sc['seo']}", "lighthouse")


with sync_playwright() as pw:
    br = pw.chromium.launch()
    test_links()
    test_pages(br)
    br.close()
test_closed()
test_secrets()
test_lighthouse()

if os.environ.get("REPORT_JSON"):
    json.dump(report, open(os.environ["REPORT_JSON"], "w"), ensure_ascii=False, indent=1)
print(f"\nИтого ошибок: {len(fails)}")
sys.exit(1 if fails else 0)
