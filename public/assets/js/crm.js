/* CRM: перетаскивание на канбане, смена этапа, причина отказа, высота столбцов в отчётах. Без библиотек. */
(function () {
  'use strict';
  var csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';

  /* Отчёты: высота столбцов по часам (CSP запрещает inline-стили, поэтому через JS) */
  document.querySelectorAll('.hours i[data-h]').forEach(function (i) { i.style.height = i.getAttribute('data-h') + '%'; });

  /* Карточка сделки: поле «причина» видно только для «Отказ» */
  var mf = document.querySelector('[data-move-form]');
  if (mf) {
    var sel = mf.querySelector('select[name="stage"]');
    var box = mf.querySelector('[data-reason]');
    var reason = mf.querySelector('input[name="reason"]');
    var sync = function () { box.hidden = sel.value !== 'lost'; reason.required = sel.value === 'lost'; };
    sel.addEventListener('change', sync);
    sync();
  }

  var board = document.getElementById('board');
  if (!board) return;

  var dialog = document.getElementById('lost-dialog');
  var live = document.createElement('p');
  live.className = 'sr';
  live.setAttribute('role', 'status');
  document.body.appendChild(live);

  function askReason() {
    return new Promise(function (resolve) {
      if (!dialog || !dialog.showModal) { resolve(null); return; }
      var ta = dialog.querySelector('textarea');
      ta.value = '';
      dialog.returnValue = '';
      dialog.showModal();
      ta.focus();
      dialog.addEventListener('close', function onClose() {
        dialog.removeEventListener('close', onClose);
        resolve(dialog.returnValue === 'ok' && ta.value.trim().length >= 3 ? ta.value.trim() : null);
      });
    });
  }

  function money(n) {
    return n ? n.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₽' : '0 ₽';
  }
  function recount() {
    board.querySelectorAll('.col').forEach(function (col) {
      var cards = col.querySelectorAll('.card.deal');
      var sum = 0;
      cards.forEach(function (c) { sum += parseFloat(c.dataset.amount || '0'); });
      col.querySelector('[data-count]').textContent = cards.length;
      col.querySelector('[data-sum]').textContent = money(sum);
    });
  }

  function move(card, stage) {
    var from = card.closest('.col');
    var to = board.querySelector('.col[data-stage="' + stage + '"]');
    if (!to || from === to) return;
    var go = function (reason) {
      var fd = new FormData();
      fd.append('action', 'move');
      fd.append('stage', stage);
      if (reason) fd.append('reason', reason);
      fetch('/crm/deal.php?id=' + card.dataset.id, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': csrf } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var select = card.querySelector('select.move');
          if (!d.ok) {
            if (select) select.value = from.dataset.stage;
            live.textContent = d.error || 'Не получилось изменить этап.';
            card.querySelector('.subj').insertAdjacentHTML('afterend', '<p class="tag late" data-err></p>');
            card.querySelector('[data-err]').textContent = d.error || 'Ошибка';
            setTimeout(function () { var e = card.querySelector('[data-err]'); if (e) e.remove(); }, 4000);
            return;
          }
          to.querySelector('.cards').prepend(card);
          if (select) select.value = stage;
          recount();
          live.textContent = 'Заявка перенесена на этап «' + to.querySelector('h2').textContent + '».';
        })
        .catch(function () {
          var select = card.querySelector('select.move');
          if (select) select.value = from.dataset.stage;
          live.textContent = 'Нет связи с сервером. Этап не изменён.';
        });
    };
    if (stage === 'lost') {
      askReason().then(function (reason) {
        if (reason) go(reason);
        else { var s = card.querySelector('select.move'); if (s) s.value = from.dataset.stage; }
      });
    } else {
      go(null);
    }
  }

  /* Выпадающий список в карточке — работает с клавиатуры и на телефоне */
  board.addEventListener('change', function (e) {
    if (e.target.matches('select.move')) move(e.target.closest('.card.deal'), e.target.value);
  });

  /* Перетаскивание мышью */
  var dragged = null;
  board.addEventListener('dragstart', function (e) {
    var card = e.target.closest && e.target.closest('.card.deal[draggable="true"]');
    if (!card) return;
    dragged = card;
    card.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', card.dataset.id);
  });
  board.addEventListener('dragend', function () {
    if (dragged) dragged.classList.remove('dragging');
    dragged = null;
    board.querySelectorAll('.col.over').forEach(function (c) { c.classList.remove('over'); });
  });
  board.querySelectorAll('.col').forEach(function (col) {
    col.addEventListener('dragover', function (e) { if (dragged) { e.preventDefault(); col.classList.add('over'); } });
    col.addEventListener('dragleave', function (e) { if (!col.contains(e.relatedTarget)) col.classList.remove('over'); });
    col.addEventListener('drop', function (e) {
      e.preventDefault();
      col.classList.remove('over');
      if (dragged) move(dragged, col.dataset.stage);
    });
  });
})();
