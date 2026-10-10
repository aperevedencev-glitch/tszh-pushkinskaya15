"""Этап 4. Восемь сквозных сценариев «сайт ↔ бот ↔ CRM» — одним прогоном, как это делал бы человек:
форма на сайте и CRM — в браузере, бот — через настоящий вебхук, повторы — через cron.

Окружение (см. tests/run_all.sh): Apache с public/ и private/ как на хостинге (BASE_URL),
имитация Telegram API (MOCK_DIR), SQLite сайта (DB_PATH), PRIVATE_DIR, WEBHOOK_SECRET.
Запуск: python3 tests/scenarios_test.py [папка_для_скриншотов]
"""
import json
import os
import re
import sqlite3
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE_URL", "http://127.0.0.1:8081")
MOCK = os.environ["MOCK_DIR"]
DB = os.environ["DB_PATH"]
PRIV = os.environ["PRIVATE_DIR"]
SECRET = os.environ["WEBHOOK_SECRET"]
SHOTS = sys.argv[1] if len(sys.argv) > 1 else None
PASS = "Test-Passw0rd!"
results = []          # (сценарий, что проверено, результат)
fails = []
upd = int(time.time()) * 100


def check(scn, cond, msg):
    print(("  ✓ " if cond else "  ✗ ") + msg)
    results.append((scn, msg, "ok" if cond else "FAIL"))
    if not cond:
        fails.append(f"{scn}: {msg}")


def q(sql, *a):
    with sqlite3.connect(DB) as c:
        return c.execute(sql, a).fetchall()


def one(sql, *a):
    r = q(sql, *a)
    return r[0][0] if r else None


def mock_mode(m):
    with open(f"{MOCK}/mode", "w") as f:
        f.write(m)


def calls():
    try:
        with open(f"{MOCK}/calls.jsonl") as f:
            return [json.loads(x) for x in f if x.strip()]
    except FileNotFoundError:
        return []


def sent(chat=None, contains=None):
    out = []
    for c in calls():
        if c["method"] != "sendMessage" or c["mode"] != "ok":
            continue
        if chat is not None and c["params"].get("chat_id") != chat:
            continue
        if contains and contains not in c["params"].get("text", ""):
            continue
        out.append(c)
    return out


def hook(update):
    req = urllib.request.Request(f"{BASE}/bot/webhook.php", json.dumps(update).encode(),
                                 {"Content-Type": "application/json", "X-Telegram-Bot-Api-Secret-Token": SECRET})
    with urllib.request.urlopen(req, timeout=20) as r:
        return r.status


def msg(uid, text=None, first="Жилец", **extra):
    global upd
    upd += 1
    m = {"message_id": upd, "from": {"id": uid, "is_bot": False, "first_name": first, "username": f"u{uid}"},
         "chat": {"id": uid, "type": "private"}, "date": int(time.time())}
    if text is not None:
        m["text"] = text
    m.update(extra)
    return hook({"update_id": upd, "message": m})


def press(uid, data):
    global upd
    upd += 1
    return hook({"update_id": upd, "callback_query": {"id": str(upd), "from": {"id": uid, "is_bot": False, "first_name": "Жилец"},
                 "data": data, "message": {"message_id": 1, "chat": {"id": uid, "type": "private"}}}})


def cron():
    subprocess.run(["sudo", "-u", "www-data", "php", f"{PRIV}/cron/run.php"], check=False)


def set_work_hours(start, end):
    cfg = f"{PRIV}/config.php"
    src = open(cfg).read()
    src = re.sub(r"'start'(\s*)=> '\d\d:\d\d'", rf"'start'\1=> '{start}'", src)
    src = re.sub(r"'end'(\s*)=> '\d\d:\d\d'", rf"'end'\1=> '{end}'", src)
    open(cfg, "w").write(src)
    # opcache в Apache перечитывает изменённые файлы не чаще раза в 2 с (opcache.revalidate_freq)
    time.sleep(3)


def shot(page, name, full=False):
    if SHOTS:
        os.makedirs(SHOTS, exist_ok=True)
        page.screenshot(path=f"{SHOTS}/{name}.png", full_page=full)


def crm_login(br, login):
    ctx = br.new_context(viewport={"width": 1440, "height": 900}, accept_downloads=True)
    p = ctx.new_page()
    p.goto(f"{BASE}/crm/login.php")
    p.fill("#l-login", login)
    p.fill("#l-pass", PASS)
    p.click("button[type=submit]")
    p.wait_for_load_state()
    return ctx, p


def manager_of(number):
    return q("SELECT u.login, u.telegram_id FROM deals d JOIN users u ON u.id = d.manager_id WHERE d.number = ?", number)[0]


def site_lead(br, url, name, phone, comment, wait_success=True):
    ctx = br.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True)
    p = ctx.new_page()
    p.goto(url, wait_until="networkidle")
    time.sleep(2.1)
    p.fill("#f-name", name)
    p.fill("#f-phone", phone)
    p.fill("#f-comment", comment)
    p.check("#f-consent")
    t_done = time.time()   # момент нажатия «Отправить»
    with p.expect_response(lambda r: r.url.endswith("/api/lead.php")) as resp:
        p.click("#f-submit")
    data = resp.value.json()
    if wait_success and data.get("number"):
        p.wait_for_selector("#lead-ok:not([hidden])")
    return ctx, p, data, t_done


mock_mode("ok")
open(f"{MOCK}/calls.jsonl", "w").close()

with sync_playwright() as pw:
    br = pw.chromium.launch()

    # ------------------------------------------------------------------ 1
    print("\nСценарий 1. Реклама с UTM → сайт → форма → карточка с UTM, уведомление менеджеру ≤ 10 с")
    ctx, page, d1, t_done = site_lead(br, f"{BASE}/?utm_source=yandex&utm_medium=cpc&utm_campaign=autumn_repair&utm_content=banner1",
                                      "Ирина Соколова", "+7 916 400-10-01", "Протекает потолок в ванной после дождя")
    n1 = d1["number"]
    check(1, bool(re.match(r"^L-\d{6}$", n1 or "")), f"сайт показал номер заявки {n1}")
    shot(page, "s1-site-success")
    login1, tg1 = manager_of(n1)
    hit = None
    for _ in range(100):
        hit = sent(tg1, n1)
        if hit:
            break
        time.sleep(0.1)
    lat = (hit[0]["t"] - t_done) if hit else None
    check(1, hit is not None and lat <= 10, f"уведомление ответственному ({login1}) в Telegram через {lat:.2f} с после нажатия «Отправить»" if hit else "уведомление не пришло за 10 с")
    src = q("SELECT utm_source, utm_medium, utm_campaign, utm_content, landing_page FROM sources s JOIN deals d ON d.id = s.deal_id WHERE d.number = ?", n1)[0]
    check(1, src[:4] == ("yandex", "cpc", "autumn_repair", "banner1"), f"UTM в базе: {src[:4]}")
    ctx.close()
    ctx, crm = crm_login(br, login1)
    crm.goto(f"{BASE}/crm/deal.php?id={one('SELECT id FROM deals WHERE number = ?', n1)}")
    body = crm.text_content("#h-src + dl") or ""
    check(1, "autumn_repair" in body and "yandex" in body, "в карточке CRM видны источник и UTM")
    shot(crm, "s1-crm-card")
    ctx.close()

    # ------------------------------------------------------------------ 2
    print("\nСценарий 2. Сайт → «Продолжить в Telegram» → бот узнаёт заявку и пишет в ту же сделку")
    ctx, page, d2, _ = site_lead(br, f"{BASE}/", "Павел Иванов", "+7 916 400-10-02", "Не работает домофон во 2-м подъезде")
    n2 = d2["number"]
    href = page.get_attribute("#ok-tg", "href")
    start = urllib.parse.parse_qs(urllib.parse.urlparse(href).query)["start"][0]
    ctx.close()
    U2 = 81001
    msg(U2, "/start " + start, first="Павел")
    check(2, any(n2 in c["params"]["text"] for c in sent(U2)), f"бот по ссылке с сайта узнал заявку {n2}")
    msg(U2, "Код домофона на двери стёрся, фото пришлю", first="Павел")
    did2 = one("SELECT id FROM deals WHERE number = ?", n2)
    chans = [r[0] for r in q("SELECT channel FROM messages WHERE deal_id = ? AND direction = 'in' ORDER BY id", did2)]
    deals_of_contact = one("SELECT COUNT(*) FROM deals WHERE contact_id = (SELECT contact_id FROM deals WHERE id = ?)", did2)
    check(2, chans == ["site", "bot"] and deals_of_contact == 1, f"в сделке {n2} сообщения с сайта и из бота, новой сделки нет ({chans})")
    check(2, one("SELECT telegram_id FROM contacts WHERE id = (SELECT contact_id FROM deals WHERE id = ?)", did2) == U2, "Telegram привязан к тому же контакту")

    # ------------------------------------------------------------------ 3
    print("\nСценарий 3. Сначала бот, потом сайт с тем же телефоном → один контакт; открытая заявка дополняется, закрытая — новая")
    U3 = 81002
    msg(U3, "/start qr_lift_5", first="Вера")
    for t in ["📝 Оставить заявку", "Вера Николаевна"]:
        msg(U3, t, first="Вера")
    # номер — кнопкой «Поделиться номером»: Telegram подтверждает, что он принадлежит этому человеку
    msg(U3, None, first="Вера", contact={"phone_number": "79164001003", "user_id": U3, "first_name": "Вера"})
    msg(U3, "Нужен ключ от домофона", first="Вера")
    press(U3, "consent:lead")
    n3 = re.search(r"L-\d{6}", sent(U3)[-1]["params"]["text"]).group(0)
    check(3, bool(n3), f"заявка из бота {n3}, метка qr_lift_5")
    ctx, page, d3, _ = site_lead(br, f"{BASE}/?utm_source=vk&utm_campaign=chat_doma", "Вера", "8 916 400 10 03", "Ключ нужен срочно")
    check(3, d3["number"] == n3 and d3.get("existing"), f"через неделю с сайта с тем же телефоном — дописано в открытую {n3}")
    ctx.close()
    ctx, crm = crm_login(br, manager_of(n3)[0])
    did3 = one("SELECT id FROM deals WHERE number = ?", n3)
    crm.goto(f"{BASE}/crm/deal.php?id={did3}")
    crm.select_option("#d-stage", "lost")
    crm.fill("#d-reason", "Ключ выдали в офисе")
    crm.click("form[data-move-form] button[type=submit]")
    crm.wait_for_load_state()
    ctx.close()
    ctx, page, d3b, _ = site_lead(br, f"{BASE}/?utm_source=vk&utm_campaign=chat_doma", "Вера", "+79164001003", "Теперь нужен мастер: течёт смеситель")
    n3b = d3b["number"]
    contacts = one("SELECT COUNT(*) FROM contacts WHERE phone = '+79164001003'")
    same = one("SELECT COUNT(DISTINCT contact_id) FROM deals WHERE number IN (?, ?)", n3, n3b)
    check(3, n3b != n3 and contacts == 1 and same == 1, f"после закрытия — новая заявка {n3b}, контакт один")
    ctx.close()

    # номер набран в боте руками: склеивать небезопасно (так можно получить чужие ответы) — дубль руководителю, он объединяет
    U3t = 81012
    msg(U3t, "/start", first="Глеб")
    for t in ["📝 Оставить заявку", "Глеб", "+7 916 400-10-13", "Не работает лифт во 2 подъезде"]:
        msg(U3t, t, first="Глеб")
    press(U3t, "consent:lead")
    n3t = re.search(r"L-\d{6}", sent(U3t)[-1]["params"]["text"]).group(0)
    ctx, page, d3t, _ = site_lead(br, f"{BASE}/", "Глеб", "+79164001013", "Лифт так и не работает")
    ctx.close()
    c_bot = one("SELECT contact_id FROM deals WHERE number = ?", n3t)
    c_site = one("SELECT contact_id FROM deals WHERE number = ?", d3t["number"])
    check(3, c_bot != c_site and one("SELECT telegram_id FROM contacts WHERE id = ?", c_site) is None,
          "номер набран в боте руками — сайт не присоединяет заявку к этому Telegram")
    ctx, crm = crm_login(br, "boss")
    crm.goto(f"{BASE}/crm/tasks.php")
    check(3, crm.locator("tr", has_text="Дубль контакта").count() >= 1, "у руководителя задача «Дубль контакта» со ссылкой на карточку")
    crm.goto(f"{BASE}/crm/contact.php?id={c_bot}")
    crm.fill("#m-into", str(c_site))
    crm.fill("#m-confirm", "ОБЪЕДИНИТЬ")
    crm.click("form:has(#m-into) button[type=submit]")
    crm.wait_for_load_state()
    merged = one("SELECT COUNT(DISTINCT contact_id) FROM deals WHERE number IN (?, ?)", n3t, d3t["number"])
    check(3, merged == 1 and one("SELECT telegram_id FROM contacts WHERE id = ?", c_site) == U3t
          and one("SELECT COUNT(*) FROM tasks WHERE kind = 'duplicate' AND done_at IS NULL") == 0,
          "руководитель проверил и объединил в карточке контакта — один контакт с Telegram и телефоном, задача закрыта")
    ctx.close()

    # ------------------------------------------------------------------ 4
    print("\nСценарий 4. Менеджер отвечает в карточке → клиент получает от бота → ответ клиента в карточке")
    ctx, crm = crm_login(br, manager_of(n2)[0])
    crm.goto(f"{BASE}/crm/deal.php?id={did2}")
    crm.fill("#r-text", "Мастер придёт завтра с 10 до 12. Будете дома?")
    crm.click("form.reply button[type=submit]")
    crm.wait_for_load_state()
    got = sent(U2, "Мастер придёт завтра")
    check(4, bool(got), "клиент получил ответ менеджера от бота")
    check(4, "доставлено в Telegram" in crm.content(), "в карточке статус «доставлено в Telegram»")
    msg(U2, "Да, буду дома, спасибо!", first="Павел")
    crm.reload()
    check(4, "Да, буду дома, спасибо!" in crm.content(), "ответ клиента появился в ленте карточки")
    shot(crm, "s4-crm-dialog", True)
    ctx.close()

    # ------------------------------------------------------------------ 5
    print("\nСценарий 5. Заявка в нерабочее время → бот отвечает сразу → утром задача менеджеру → в отчёте как ночная")
    set_work_hours("09:00", "09:01")   # «сейчас» гарантированно нерабочее время
    try:
        U5 = 81003
        msg(U5, "💬 Связаться с менеджером", first="Глеб")
        msg(U5, "Сосед сверху делает ремонт ночью, что делать?", first="Глеб")
        press(U5, "consent:ask")
        reply = sent(U5)[-1]["params"]["text"]
        n5 = re.search(r"L-\d{6}", reply).group(0)
        check(5, "нерабочее время" in reply, f"бот сразу ответил: заявка {n5} принята, менеджер свяжется в рабочее время")
        did5 = one("SELECT id FROM deals WHERE number = ?", n5)
        task = q("SELECT kind, due_at, user_id FROM tasks WHERE deal_id = ?", did5)
        mgr5 = one("SELECT manager_id FROM deals WHERE id = ?", did5)
        check(5, task and task[0][0] == "callback" and task[0][1].endswith("09:00:00") and task[0][2] == mgr5,
              f"ответственному задача «связаться» на {task[0][1] if task else '—'}")
        check(5, one("SELECT is_off_hours FROM deals WHERE id = ?", did5) == 1, "заявка помечена как пришедшая в нерабочее время")
    finally:
        set_work_hours("09:00", "18:00")
    ctx, crm = crm_login(br, "boss")
    crm.goto(f"{BASE}/crm/reports.php")
    kpi = crm.locator(".kpi", has_text="В нерабочее время")
    off_n = int((kpi.locator("b").text_content() or "0").strip())
    off_db = one("SELECT COUNT(*) FROM deals WHERE is_off_hours = 1")
    check(5, off_n >= 1 and off_n == off_db, f"в отчёте руководителя учтена как нерабочее время: {off_n} заявк. ({kpi.locator('small').text_content()})")
    crm.goto(f"{BASE}/crm/deals.php?off_hours=1")
    check(5, n5 in crm.content(), "фильтр «только нерабочее время» показывает заявку")

    # ------------------------------------------------------------------ 6
    print("\nСценарий 6. «Оплачено» → выручка в отчёте по источнику первой рекламы")
    did3b = one("SELECT id FROM deals WHERE number = ?", n3b)
    crm.goto(f"{BASE}/crm/deal.php?id={did3b}")
    crm.fill("#d-amount", "3200")
    crm.click("form:has(#d-amount) button[type=submit]")
    crm.wait_for_load_state()
    crm.select_option("#d-stage", "paid")
    crm.click("form[data-move-form] button[type=submit]")
    crm.wait_for_load_state()
    crm.goto(f"{BASE}/crm/reports.php")
    rows = crm.eval_on_selector_all("section.card:first-of-type tbody tr", "rs => rs.map(r => r.innerText.replace(/\\s+/g, ' '))")
    bot_row = next((r for r in rows if r.startswith("Telegram-бот") and "3 200,00" in r), None)
    vk_row = next((r for r in rows if "chat_doma" in r), "")
    check(6, bot_row is not None, f"выручка 3 200 ₽ — у первого касания (бот, метка qr_lift_5): «{bot_row}»")
    check(6, "3 200,00" not in vk_row, "у последней кампании (vk/chat_doma) выручка не задвоена")
    shot(crm, "s6-reports", True)
    ctx.close()

    # ------------------------------------------------------------------ 7
    print("\nСценарий 7. «Удалить мои данные» → задача руководителю → обезличивание с записью в audit_log")
    msg(U3, "/stop", first="Вера")
    press(U3, "del:yes")
    cid3 = one("SELECT contact_id FROM deals WHERE number = ?", n3)
    t7 = q("SELECT t.id, u.login FROM tasks t JOIN users u ON u.id = t.user_id WHERE t.kind = 'delete_request' AND t.done_at IS NULL")
    check(7, bool(t7) and t7[0][1] == "boss", "задача на удаление у руководителя")
    ctx, crm = crm_login(br, "boss")
    crm.goto(f"{BASE}/crm/tasks.php")
    crm.click("text=Открыть запрос")
    crm.wait_for_load_state()
    check(7, "Есть запрос клиента на удаление" in crm.content(), "руководитель открыл запрос из задачи")
    crm.fill("#a-confirm", "УДАЛИТЬ")
    crm.click("form:has(#a-confirm) button[type=submit]")
    crm.wait_for_load_state()
    c = q("SELECT name, phone, telegram_id, anonymized_at FROM contacts WHERE id = ?", cid3)[0]
    left = one("SELECT COUNT(*) FROM messages m JOIN deals d ON d.id = m.deal_id WHERE d.contact_id = ? AND m.author IN ('client','manager','bot') AND m.text <> '[удалено по 152-ФЗ]'", cid3)
    check(7, c[:3] == (None, None, None) and c[3], "имя, телефон и Telegram удалены")
    check(7, left == 0, "тексты переписки удалены")
    check(7, one("SELECT COUNT(*) FROM audit_log WHERE entity = 'contact' AND entity_id = ? AND action = 'anonymize'", cid3) == 1, "в audit_log запись об обезличивании")
    check(7, one("SELECT COUNT(*) FROM tasks WHERE kind = 'delete_request' AND done_at IS NULL") == 0, "задача закрыта")
    check(7, one("SELECT amount FROM deals WHERE number = ?", n3b) == 3200, "выручка для отчётов сохранилась")
    shot(crm, "s7-contact-anonymized")
    ctx.close()

    # ------------------------------------------------------------------ 8
    print("\nСценарий 8. Telegram недоступен → заявка с сайта сохраняется, уведомление уходит, когда связь вернулась")
    mock_mode("down")
    ctx, page, d8, _ = site_lead(br, f"{BASE}/?utm_source=flyer", "Николай", "+7 916 400-10-08", "Проверка при недоступном Telegram")
    n8 = d8.get("number")
    check(8, bool(n8), f"сайт принял заявку {n8} и показал номер")
    ctx.close()
    did8 = one("SELECT id FROM deals WHERE number = ?", n8)
    pend = one("SELECT COUNT(*) FROM outbox WHERE deal_id = ? AND kind = 'notify_new' AND sent_at IS NULL AND failed_at IS NULL", did8)
    check(8, did8 is not None and pend == 1, "заявка в CRM, уведомление ждёт в очереди")
    mock_mode("ok")
    q("UPDATE outbox SET next_try_at = '2000-01-01 00:00:00' WHERE sent_at IS NULL AND failed_at IS NULL")
    cron()
    login8, tg8 = manager_of(n8)
    check(8, bool(sent(tg8, n8)), f"связь вернулась — cron отправил уведомление {login8}")
    check(8, one("SELECT COUNT(*) FROM outbox WHERE deal_id = ? AND sent_at IS NOT NULL", did8) == 1, "в очереди отмечено «отправлено»")

    br.close()

print("\n" + "=" * 60)
for scn in range(1, 9):
    rs = [r for r in results if r[0] == scn]
    ok = all(r[2] == "ok" for r in rs)
    print(f"Сценарий {scn}: {'OK' if ok else 'FAIL'} ({sum(r[2] == 'ok' for r in rs)}/{len(rs)})")
print(f"Итого ошибок: {len(fails)}")
if os.environ.get("REPORT_JSON"):   # без папки для скриншотов run_all.sh передаёт пустую строку
    with open(os.environ["REPORT_JSON"], "w") as f:
        json.dump(results, f, ensure_ascii=False)
sys.exit(1 if fails else 0)
