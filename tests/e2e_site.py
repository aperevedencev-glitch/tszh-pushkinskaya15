"""Сквозная проверка сайта в браузере (этап 1).

Запуск: сервер на 127.0.0.1:8080 (см. dev/README), затем
    python3 tests/e2e_site.py [папка_для_скриншотов]
Проверяет: нет ошибок в консоли, нет запросов на чужие домены, ширины 375/390/1440 без горизонтальной прокрутки,
кнопки не меньше 44 px, UTM уходят с заявкой, ссылки в бот с меткой кампании, форма показывает номер L-…
"""
import json
import os
import re
import sys
import time
from urllib.parse import urlparse

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE_URL", "http://127.0.0.1:8080")
SHOTS = sys.argv[1] if len(sys.argv) > 1 else None
HOST = urlparse(BASE).netloc
fails = []


def check(cond, msg):
    print(("✓ " if cond else "✗ ") + msg)
    if not cond:
        fails.append(msg)


with sync_playwright() as p:
    browser = p.chromium.launch()

    for width in (375, 390, 1440):
        ctx = browser.new_context(viewport={"width": width, "height": 900}, is_mobile=width < 500, has_touch=width < 500)
        page = ctx.new_page()
        errors, external = [], []
        page.on("console", lambda m: errors.append(m.text) if m.type == "error" else None)
        page.on("pageerror", lambda e: errors.append(str(e)))
        page.on("request", lambda r: external.append(r.url) if urlparse(r.url).netloc not in (HOST, "") and not r.url.startswith("data:") else None)

        page.goto(f"{BASE}/?utm_source=qr&utm_medium=print&utm_campaign=lift_2", wait_until="networkidle")
        check(not errors, f"{width}px: консоль без ошибок {errors[:2]}")
        check(not external, f"{width}px: нет запросов на чужие домены {external[:2]}")
        sw = page.evaluate("document.documentElement.scrollWidth")
        check(sw <= width, f"{width}px: нет горизонтальной прокрутки (ширина {sw})")

        small = page.evaluate("""() => [...document.querySelectorAll('a.btn, button, .inp, summary')]
            .filter(e => e.offsetParent !== null)
            .map(e => ({t: (e.textContent || e.name || '').trim().slice(0, 30), h: e.getBoundingClientRect().height}))
            .filter(x => x.h < 44)""")
        check(not small, f"{width}px: кнопки и поля не ниже 44 px {small[:3]}")

        tg = page.eval_on_selector_all("[data-tg]", "els => els.map(e => e.href)")
        check(tg and all("start=site_lift_2" in u for u in tg), f"{width}px: ссылки в бот с меткой кампании ({len(tg)} шт.)")

        if SHOTS:
            os.makedirs(SHOTS, exist_ok=True)
            page.screenshot(path=f"{SHOTS}/home-{width}.png", full_page=width < 500)

        if width == 390:
            # пустая отправка — ошибки у полей, фокус на первом
            page.click("#f-submit")
            check(page.get_attribute("#f-name", "aria-invalid") == "true", "пустая форма: ошибка у имени")
            check(page.evaluate("document.activeElement.id") == "f-name", "пустая форма: фокус на первом поле с ошибкой")
            # заполнение
            time.sleep(2.1)  # ловушка «слишком быстро» — 2 секунды
            page.fill("#f-name", "Тест Сквозной")
            # новый номер при каждом запуске: ссылка с привязкой к заявке выдаётся только новому контакту
            page.fill("#f-phone", "+7 900 " + time.strftime("%H%M%S")[:3] + "-" + time.strftime("%H%M%S")[3:5] + "-" + time.strftime("%S"))
            page.fill("#f-comment", "Проверка формы из автотеста")
            page.locator("#f-consent").scroll_into_view_if_needed()
            time.sleep(0.8)   # дождаться анимации появления блока, иначе флажок «нестабилен»
            page.check("#f-consent")
            with page.expect_response(lambda r: r.url.endswith("/api/lead.php")) as resp:
                page.click("#f-submit")
            data = resp.value.json()
            check(data.get("ok") and re.match(r"^L-\d{6}$", data.get("number") or ""), f"API вернул номер {data.get('number')}")
            page.wait_for_selector("#lead-ok:not([hidden])")
            shown = page.text_content("#ok-number")
            check(shown == data.get("number"), f"на странице показан номер {shown}")
            check(not page.is_visible("#lead-form"), "форма скрыта после успешной отправки")
            href = page.get_attribute("#ok-tg", "href")
            check(bool(re.search(r"start=L\d{6}_[a-f0-9]{10}$", href or "")), "кнопка «Продолжить в Telegram» с подписанной меткой заявки")
            check(page.evaluate("document.activeElement.id") == "lead-ok", "фокус переведён на сообщение об успехе")
            if SHOTS:
                page.locator("#zayavka").screenshot(path=f"{SHOTS}/form-success-390.png")
            print("NUMBER", data.get("number"))
        ctx.close()

    # без JavaScript форма уходит обычной отправкой
    ctx = browser.new_context(java_script_enabled=False, viewport={"width": 390, "height": 900})
    page = ctx.new_page()
    page.goto(f"{BASE}/#zayavka")
    page.fill("#f-name", "Без скриптов")
    page.fill("#f-tg", "@no_js_user")
    page.check("#f-consent")
    page.click("#f-submit")
    page.wait_for_load_state()
    h1 = page.text_content("h1") or ""
    check(bool(re.match(r"^Заявка L-\d{6} принята$", h1)), f"без JS: страница результата «{h1}»")
    ctx.close()

    # 404 и политика
    ctx = browser.new_context()
    page = ctx.new_page()
    r = page.goto(f"{BASE}/no-such-page")
    check(r.status == 404 and "Такой страницы нет" in page.content(), "404 отдаёт свою страницу")
    r = page.goto(f"{BASE}/politika/")
    check(r.status == 200 and "Политика обработки" in page.text_content("h1"), "политика открывается по /politika/")
    browser.close()

print(f"\nИтого ошибок: {len(fails)}")
sys.exit(1 if fails else 0)
