"""Проверка CRM в браузере: вход, роли, CSRF, канбан, ответ клиенту, отчёты, обезличивание.

Ожидает Apache с CRM на BASE_URL, данные из tests/seed_demo.php, имитацию Telegram (MOCK_DIR), DB_PATH.
Запуск: python3 tests/e2e_crm.py [папка_для_скриншотов]
"""
import json
import os
import sqlite3
import sys
import time
from urllib.parse import urlparse

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE_URL", "http://127.0.0.1:8081")
DB = os.environ["DB_PATH"]
MOCK = os.environ["MOCK_DIR"]
SHOTS = sys.argv[1] if len(sys.argv) > 1 else None
PASS = "Test-Passw0rd!"
HOST = urlparse(BASE).netloc
fails = []


def check(cond, msg):
    print(("✓ " if cond else "✗ ") + msg)
    if not cond:
        fails.append(msg)


def q(sql, *a):
    with sqlite3.connect(DB) as c:
        return c.execute(sql, a).fetchall()


def shot(page, name, full=False):
    if SHOTS:
        os.makedirs(SHOTS, exist_ok=True)
        page.screenshot(path=f"{SHOTS}/{name}.png", full_page=full)


def login(ctx, user, password=PASS):
    page = ctx.new_page()
    page.goto(f"{BASE}/crm/")
    page.fill("#l-login", user)
    page.fill("#l-pass", password)
    page.click("button[type=submit]")
    page.wait_for_load_state()
    return page


def watch(page, errors, external):
    page.on("console", lambda m: errors.append(m.text) if m.type == "error" else None)
    page.on("pageerror", lambda e: errors.append(str(e)))
    page.on("request", lambda r: external.append(r.url) if urlparse(r.url).netloc not in (HOST, "") and not r.url.startswith("data:") else None)


deal_of = {row[0]: row[1] for row in q("SELECT d.number, u.login FROM deals d JOIN users u ON u.id = d.manager_id")}
anna_deal = next(n for n, l in deal_of.items() if l == "anna")
oleg_deal = next(n for n, l in deal_of.items() if l == "oleg")
id_of = lambda num: q("SELECT id FROM deals WHERE number = ?", num)[0][0]

with sync_playwright() as p:
    br = p.chromium.launch()

    # --- Вход ---
    ctx = br.new_context(viewport={"width": 1440, "height": 900})
    page = ctx.new_page()
    r = page.goto(f"{BASE}/crm/deals.php")
    check("/crm/login.php" in page.url, "без входа CRM отправляет на страницу входа")
    page.fill("#l-login", "anna")
    page.fill("#l-pass", "wrong-password")
    with page.expect_response(lambda r: "login.php" in r.url and r.request.method == "POST") as resp:
        page.click("button[type=submit]")
    check(resp.value.status == 401 and "Неверный логин или пароль" in page.content(), "неверный пароль — 401 и понятная ошибка")
    cookies = page.context.cookies()
    check(all(c["httpOnly"] and c["sameSite"] == "Lax" for c in cookies if "tszh_crm" in c["name"]), "cookie сессии: HttpOnly, SameSite=Lax")
    ctx.close()

    # --- Менеджер Анна ---
    ctx = br.new_context(viewport={"width": 1440, "height": 900})
    page = login(ctx, "anna")
    errors, external = [], []
    watch(page, errors, external)
    page.goto(f"{BASE}/crm/")
    check(page.locator(".col").count() == 5, "канбан: пять колонок этапов")
    check(page.locator(".card.deal").count() == 5, "менеджер видит свои сделки и общий входящий поток (5)")
    shot(page, "crm-kanban-1440")

    # чужая новая заявка: видна, но менять нельзя
    page.goto(f"{BASE}/crm/deal.php?id={id_of(oleg_deal)}")
    check("Взять себе" in page.content() and page.locator("#r-text").count() == 0, f"чужая новая заявка {oleg_deal}: только просмотр и «Взять себе»")

    # CSRF: запрос без токена отклоняется (ожидаемые 403 в консоли не считаем ошибкой)
    status = page.evaluate("""async (id) => (await fetch('/crm/deal.php?id=' + id, {method: 'POST', body: new URLSearchParams({action: 'move', stage: 'contacted'}), headers: {Accept: 'application/json'}})).status""", id_of(anna_deal))
    check(status == 403, f"POST без CSRF-токена → {status}")
    check(q("SELECT stage FROM deals WHERE number = ?", anna_deal)[0][0] == "new", "этап не изменился без токена")
    errors.clear()

    # Отказ без причины невозможен, с причиной — проходит
    page.goto(f"{BASE}/crm/")
    page.select_option(f"#mv-{id_of(anna_deal)}", "lost")
    page.wait_for_selector("#lost-dialog[open]")
    page.click("#lost-dialog button[value=cancel]")
    time.sleep(0.4)
    check(q("SELECT stage FROM deals WHERE number = ?", anna_deal)[0][0] == "new", "«Отказ» без причины не сохраняется")
    page.select_option(f"#mv-{id_of(anna_deal)}", "lost")
    page.fill("#lost-reason", "Вопрос решён по телефону")
    with page.expect_response(lambda r: "deal.php" in r.url and r.request.method == "POST"):
        page.click("#lost-dialog button[value=ok]")
    time.sleep(0.3)
    st, reason = q("SELECT stage, lost_reason FROM deals WHERE number = ?", anna_deal)[0]
    check(st == "lost" and reason == "Вопрос решён по телефону", "«Отказ» с причиной сохранён")
    check(page.locator('.col[data-stage="lost"] [data-count]').text_content() == "1", "счётчик колонки «Отказ» обновился")

    # Ответ клиенту в Telegram
    bot_deal = q("SELECT d.id, d.number FROM deals d JOIN contacts c ON c.id = d.contact_id JOIN users u ON u.id = d.manager_id WHERE c.telegram_id IS NOT NULL AND u.login = 'anna' LIMIT 1")
    if bot_deal:
        did, num = bot_deal[0]
        open(f"{MOCK}/calls.jsonl", "w").close()
        open(f"{MOCK}/mode", "w").write("ok")
        page.goto(f"{BASE}/crm/deal.php?id={did}")
        page.fill("#r-text", "Добрый день! Бухгалтер проверит начисление и ответит до пятницы.")
        page.click("form.reply button[type=submit]")
        page.wait_for_load_state()
        sent = [json.loads(l) for l in open(f"{MOCK}/calls.jsonl") if l.strip()]
        to_client = [c for c in sent if c["method"] == "sendMessage" and "Бухгалтер проверит" in c["params"].get("text", "")]
        check(bool(to_client), f"ответ менеджера ушёл клиенту через бота ({num})")
        check("доставлено в Telegram" in page.content(), "в ленте статус «доставлено в Telegram»")
        check(q("SELECT stage FROM deals WHERE id = ?", did)[0][0] == "contacted", "после первого ответа этап «Связались»")
        shot(page, "crm-deal-1440", True)

    # Поиск по телефону
    page.goto(f"{BASE}/crm/deals.php?q=5550022")
    check("Елена" in page.content(), "поиск по части телефона находит контакт")
    page.goto(f"{BASE}/crm/deals.php?q=" + anna_deal)
    check(page.locator("tbody tr").count() == 1, f"поиск по номеру {anna_deal}")

    check(not errors and not external, f"консоль без ошибок, внешних запросов нет {errors[:2]} {external[:2]}")
    # Отчёты менеджеру недоступны
    r = page.goto(f"{BASE}/crm/reports.php")
    check(r.status == 403, f"менеджеру отчёты недоступны → {r.status}")
    ctx.close()

    # --- Олег забирает свою заявку из «Новых», Анна теряет к ней доступ ---
    ctx = br.new_context()
    page = login(ctx, "oleg")
    page.goto(f"{BASE}/crm/")
    with page.expect_response(lambda r: "deal.php" in r.url and r.request.method == "POST"):
        page.select_option(f"#mv-{id_of(oleg_deal)}", "contacted")
    ctx.close()
    ctx = br.new_context()
    page = login(ctx, "anna")
    r = page.goto(f"{BASE}/crm/deal.php?id={id_of(oleg_deal)}")
    check(r.status == 403, f"менеджер не открывает чужую сделку вне «Новых» → {r.status}")
    ctx.close()

    # --- Руководитель ---
    ctx = br.new_context(viewport={"width": 1440, "height": 900}, accept_downloads=True)
    page = login(ctx, "boss")
    errors, external = [], []
    watch(page, errors, external)
    paid = q("SELECT id FROM deals WHERE stage = 'new' LIMIT 1")[0][0]
    page.goto(f"{BASE}/crm/deal.php?id={paid}")
    page.fill("#d-amount", "2500")
    page.click("form:has(#d-amount) button[type=submit]")
    page.select_option("#d-stage", "paid")
    page.click("form[data-move-form] button[type=submit]")
    page.wait_for_load_state()
    check(q("SELECT stage, amount FROM deals WHERE id = ?", paid)[0] == ("paid", 2500), "руководитель провёл сделку в «Оплачено» с суммой 2500")
    page.goto(f"{BASE}/crm/reports.php")
    check("2 500,00 ₽" in page.content(), "выручка видна в отчёте по источнику")
    shot(page, "crm-reports-1440", True)
    with page.expect_download() as dl:
        page.click("text=Скачать CSV >> nth=0")
    path = dl.value.path()
    raw = open(path, "rb").read()
    check(raw.startswith(b"\xef\xbb\xbf") and "Выручка".encode() in raw, "CSV: UTF-8 с BOM, заголовки по-русски")

    # Обезличивание
    cid = q("SELECT id FROM contacts WHERE phone = '+79165550022'")[0][0]
    page.goto(f"{BASE}/crm/contact.php?id={cid}")
    page.fill("#a-confirm", "УДАЛИТЬ")
    page.click("form:has(#a-confirm) button[type=submit]")
    page.wait_for_load_state()
    row = q("SELECT name, phone, anonymized_at FROM contacts WHERE id = ?", cid)[0]
    texts = [t[0] for t in q("SELECT m.text FROM messages m JOIN deals d ON d.id = m.deal_id WHERE d.contact_id = ? AND m.author = 'client'", cid)]
    check(row[0] is None and row[1] is None and row[2], "контакт обезличен: имя и телефон удалены")
    check(all(t == "[удалено по 152-ФЗ]" for t in texts), "тексты переписки удалены")
    check(q("SELECT COUNT(*) FROM audit_log WHERE entity = 'contact' AND entity_id = ? AND action = 'anonymize'", cid)[0][0] == 1, "запись в audit_log")

    # Мобильный вид
    page.set_viewport_size({"width": 390, "height": 844})
    page.goto(f"{BASE}/crm/")
    sw = page.evaluate("document.documentElement.scrollWidth")
    check(sw <= 390, f"канбан на телефоне без горизонтальной прокрутки страницы ({sw})")
    shot(page, "crm-kanban-390")
    check(not errors and not external, f"у руководителя консоль без ошибок, внешних запросов нет {errors[:2]}")
    br.close()

print(f"\nИтого ошибок: {len(fails)}")
sys.exit(1 if fails else 0)
