/* ТСЖ «Пушкинская 15» — сайт: метки кампании, ссылки в бот, форма заявки. Без внешних библиотек. */
(function () {
  'use strict';

  var KEY = 'tszh_first_touch';
  var UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
  var loadedAt = Date.now();

  /* 1. Первое касание за сессию: UTM, откуда пришли, страница входа */
  function readTouch() {
    try { return JSON.parse(sessionStorage.getItem(KEY) || 'null'); } catch (e) { return null; }
  }
  function saveTouch() {
    if (readTouch()) return;
    var q = new URLSearchParams(location.search);
    var t = { landing_page: location.pathname + location.search, referrer: '' };
    UTM.forEach(function (k) { t[k] = (q.get(k) || '').slice(0, 100); });
    try {
      if (document.referrer) {
        var r = new URL(document.referrer);
        if (r.host !== location.host) t.referrer = r.host + r.pathname; // без параметров: в них бывают личные данные
      }
    } catch (e) { /* пустой или кривой referrer */ }
    try { sessionStorage.setItem(KEY, JSON.stringify(t)); } catch (e) { /* приватный режим */ }
  }
  saveTouch();
  var touch = readTouch() || {};

  /* 2. Кнопки «Написать в Telegram»: t.me/<бот>?start=site_<кампания> */
  function campaignTag() {
    var raw = touch.utm_campaign || touch.utm_source || '';
    var tag = raw.replace(/[^A-Za-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 58);
    return tag ? 'site_' + tag : 'site';
  }
  document.querySelectorAll('[data-tg]').forEach(function (a) {
    try {
      var u = new URL(a.href);
      u.searchParams.set('start', campaignTag());
      a.href = u.toString();
    } catch (e) { /* оставляем ссылку как есть */ }
  });

  /* 3. Форма заявки */
  var form = document.getElementById('lead-form');
  if (!form || !window.fetch || !window.FormData) return; // без JS форма уйдёт обычной отправкой

  var status = document.getElementById('f-status');
  var submit = document.getElementById('f-submit');
  var fields = { name: 'f-name', phone: 'f-phone', telegram: 'f-tg', consent: 'f-consent' };

  function setError(name, msg) {
    var input = document.getElementById(fields[name]);
    var box = document.getElementById('e-' + name);
    if (box) box.textContent = msg || '';
    if (input) {
      if (msg) input.setAttribute('aria-invalid', 'true');
      else input.removeAttribute('aria-invalid');
    }
  }
  function clearErrors() { Object.keys(fields).forEach(function (k) { setError(k, ''); }); status.textContent = ''; status.className = 'form-status'; }

  function validate() {
    var ok = true;
    var name = form.name.value.trim();
    var phone = form.phone.value.trim();
    var tg = form.telegram.value.trim();
    if (name.length < 2) { setError('name', 'Напишите, как к вам обращаться.'); ok = false; }
    if (!phone && !tg) { setError('phone', 'Укажите телефон или ник в Telegram — иначе мы не сможем ответить.'); ok = false; }
    if (phone) {
      var d = phone.replace(/\D/g, '');
      if (!(d.length === 11 && /^[78]/.test(d)) && !(d.length === 10 && d[0] === '9')) {
        setError('phone', 'Проверьте номер: 10 цифр после +7.'); ok = false;
      }
    }
    if (tg && !/^(https?:\/\/)?(t\.me\/)?@?[A-Za-z][A-Za-z0-9_]{4,31}$/.test(tg)) {
      setError('telegram', 'Ник — латиница, цифры и _, от 5 символов. Например: @ivan_petrov.'); ok = false;
    }
    if (!form.consent.checked) { setError('consent', 'Без согласия на обработку данных мы не можем сохранить заявку.'); ok = false; }
    return ok;
  }

  function focusFirstError() {
    var bad = form.querySelector('[aria-invalid="true"]');
    if (bad) bad.focus();
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    clearErrors();
    if (!validate()) { focusFirstError(); return; }

    form.elapsed.value = String(Date.now() - loadedAt);
    UTM.concat(['referrer', 'landing_page']).forEach(function (k) { if (form[k]) form[k].value = touch[k] || ''; });

    submit.disabled = true;
    submit.textContent = 'Отправляем…';
    fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Сервер ответил с ошибкой.' }; }); })
      .then(function (data) {
        if (data.ok) { showSuccess(data); return; }
        if (data.errors) {
          Object.keys(data.errors).forEach(function (k) { setError(k, data.errors[k]); });
          focusFirstError();
        }
        status.className = 'form-status error';
        status.textContent = data.error || 'Проверьте поля, отмеченные красным.';
      })
      .catch(function () {
        status.className = 'form-status error';
        status.textContent = 'Нет связи с сервером. Проверьте интернет и отправьте ещё раз.';
      })
      .then(function () { submit.disabled = false; submit.textContent = 'Отправить заявку'; });
  });

  function showSuccess(data) {
    var box = document.getElementById('lead-ok');
    if (data.number) {
      document.getElementById('ok-number').textContent = data.number;
      if (data.existing) {
        document.getElementById('ok-text').textContent = 'У вас уже есть открытая заявка — сообщение добавлено в неё. Менеджер свяжется в рабочее время.';
      }
      if (data.telegram) document.getElementById('ok-tg').href = data.telegram;
    }
    form.hidden = true;
    box.hidden = false;
    box.focus();
  }
})();
