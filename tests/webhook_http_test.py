"""Проверка вебхука и связки «сайт ↔ бот ↔ очередь» по-настоящему: HTTP, Apache, curl к имитации Telegram.

Ожидает: сайт на BASE_URL (Apache, public/ и private/ как на хостинге), имитацию Telegram из tests/mock_telegram.php,
переменные WEBHOOK_SECRET, MOCK_DIR, DB_PATH (SQLite сайта), PRIVATE_DIR (для запуска cron).
Запуск: python3 tests/webhook_http_test.py
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

BASE = os.environ.get("BASE_URL", "http://127.0.0.1:8081")
SECRET = os.environ["WEBHOOK_SECRET"]
MOCK = os.environ["MOCK_DIR"]
DB = os.environ["DB_PATH"]
PRIV = os.environ["PRIVATE_DIR"]
fails = []
upd = int(time.time()) * 10


def check(cond, msg):
    print(("✓ " if cond else "✗ ") + msg)
    if not cond:
        fails.append(msg)


def http(path, data=None, headers=None, method=None):
    req = urllib.request.Request(BASE + path, data=data, headers=headers or {}, method=method)
    try:
        with urllib.request.urlopen(req, timeout=20) as r:
            return r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()


def hook(update, secret=SECRET):
    h = {"Content-Type": "application/json"}
    if secret is not None:
        h["X-Telegram-Bot-Api-Secret-Token"] = secret
    return http("/bot/webhook.php", json.dumps(update).encode(), h)


def msg(uid, text=None, **extra):
    global upd
    upd += 1
    m = {"message_id": upd, "from": {"id": uid, "is_bot": False, "first_name": "Тест", "username": f"user{uid}"},
         "chat": {"id": uid, "type": "private"}, "date": int(time.time())}
    if text is not None:
        m["text"] = text
    m.update(extra)
    return {"update_id": upd, "message": m}


def cb(uid, data):
    global upd
    upd += 1
    return {"update_id": upd, "callback_query": {"id": str(upd), "from": {"id": uid, "is_bot": False, "first_name": "Тест"},
            "data": data, "message": {"message_id": 1, "chat": {"id": uid, "type": "private"}}}}


def calls():
    try:
        with open(f"{MOCK}/calls.jsonl") as f:
            return [json.loads(l) for l in f if l.strip()]
    except FileNotFoundError:
        return []


def sent_to(chat):
    return [c["params"].get("text", "") for c in calls() if c["method"] == "sendMessage" and c["params"].get("chat_id") == chat and c["mode"] == "ok"]


def mode(m):
    with open(f"{MOCK}/mode", "w") as f:
        f.write(m)


def db():
    return sqlite3.connect(DB)


def cron():
    subprocess.run(["sudo", "-u", "www-data", "php", f"{PRIV}/cron/run.php"], check=False)


def lead(name, phone, comment):
    data = urllib.parse.urlencode({"name": name, "phone": phone, "comment": comment, "consent": "yes", "elapsed": "5000",
                                   "utm_source": "qr", "utm_campaign": "lift_2"}).encode()
    return http("/api/lead.php", data, {"Accept": "application/json"})


mode("ok")
open(f"{MOCK}/calls.jsonl", "w").close()

# --- Защита вебхука ---
with db() as c:
    n0 = c.execute("SELECT COUNT(*) FROM tg_updates").fetchone()[0]
st, _ = hook(msg(90001, "/start"), secret=None)
check(st == 403, f"вебхук без секрета → {st}")
st, _ = hook(msg(90001, "/start"), secret="wrong")
check(st == 403, f"вебхук с неверным секретом → {st}")
with db() as c:
    check(c.execute("SELECT COUNT(*) FROM tg_updates").fetchone()[0] == n0, "отклонённые запросы ничего не записали")
st, _ = http("/bot/webhook.php")
check(st == 405, f"GET на вебхук → {st}")
st, _ = http("/bot/anything.php")
check(st in (403, 404), f"другие файлы в /bot/ закрыты → {st}")

# --- Нормальный /start и повторная доставка ---
u = msg(90001, "/start site_lift_2")
st, body = hook(u)
check(st == 200 and body == "ok", "вебхук с верным секретом → 200")
check(any("бот ТСЖ" in t for t in sent_to(90001)), "бот ответил через Telegram API (curl → имитация)")
before = len(sent_to(90001))
st, body = hook(u)
check(st == 200 and body == "duplicate" and len(sent_to(90001)) == before, "повторная доставка того же update_id не обрабатывается дважды")

# --- Заявка в боте целиком через вебхук ---
uid = 90002
for step in [msg(uid, "📝 Оставить заявку"), msg(uid, "Сергей"), msg(uid, "+7 926 000-11-22"), msg(uid, "Не закрывается дверь подъезда")]:
    hook(step)
hook(cb(uid, "consent:lead"))
num = re.search(r"L-\d{6}", " ".join(sent_to(uid)))
check(bool(num), f"заявка в боте через вебхук → {num.group(0) if num else 'нет номера'}")
with db() as c:
    row = c.execute("SELECT d.number, s.bot_start_tag FROM deals d JOIN contacts ct ON ct.id = d.contact_id LEFT JOIN sources s ON s.deal_id = d.id WHERE ct.telegram_id = ?", (uid,)).fetchone()
check(row is not None, "сделка и контакт в базе")

# --- Сайт → «Продолжить в Telegram» → та же сделка ---
st, body = lead("Марина", "+7 916 300-40-50", "Прошу проверить счётчик")
data = json.loads(body)
start = urllib.parse.parse_qs(urllib.parse.urlparse(data["telegram"]).query)["start"][0]
uid = 90003
hook(msg(uid, "/start " + start))
check(any(data["number"] in t for t in sent_to(uid)), f"бот узнал заявку с сайта {data['number']}")
hook(msg(uid, "Счётчик на кухне, номер на фото"))
with db() as c:
    deals = c.execute("SELECT COUNT(*) FROM deals d JOIN contacts ct ON ct.id = d.contact_id WHERE ct.phone = '+79163004050'").fetchone()[0]
    msgs = c.execute("SELECT COUNT(*) FROM messages m JOIN deals d ON d.id = m.deal_id WHERE d.number = ? AND m.direction = 'in'", (data["number"],)).fetchone()[0]
check(deals == 1 and msgs == 2, f"сообщение из бота дописано в ту же сделку (сделок {deals}, входящих {msgs})")

# --- Telegram недоступен: заявка с сайта сохраняется, уведомление уходит позже ---
mode("down")
st, body = lead("Николай", "+7 916 777-88-99", "Telegram лежит, а заявка должна сохраниться")
d2 = json.loads(body)
check(st == 200 and d2.get("ok") and d2.get("number"), f"Telegram недоступен — заявка всё равно сохранена ({d2.get('number')})")
time.sleep(1)
with db() as c:
    pending = c.execute("SELECT COUNT(*) FROM outbox WHERE kind = 'notify_new' AND sent_at IS NULL AND failed_at IS NULL").fetchone()[0]
check(pending >= 1, f"уведомление менеджеру ждёт в очереди ({pending})")
mode("ok")
with db() as c:
    c.execute("UPDATE outbox SET next_try_at = '2000-01-01 00:00:00' WHERE sent_at IS NULL")
cron()
with db() as c:
    left = c.execute("SELECT COUNT(*) FROM outbox WHERE kind = 'notify_new' AND sent_at IS NULL AND failed_at IS NULL").fetchone()[0]
notified = [c for c in calls() if c["method"] == "sendMessage" and c["mode"] == "ok" and d2["number"] in c["params"].get("text", "")]
check(left == 0 and notified, "связь вернулась — cron отправил уведомление менеджеру")
check(all("Николай" not in c["params"].get("text", "") and "777" not in c["params"].get("text", "") for c in notified), "в уведомлении нет имени и телефона")

print(f"\nИтого ошибок: {len(fails)}")
sys.exit(1 if fails else 0)
