<?php
declare(strict_types=1);

/**
 * Логика Telegram-бота: меню, заявка по шагам, база знаний, связь с менеджером, удаление данных.
 * Бот честно представляется ботом. Без согласия в CRM ничего не сохраняется.
 */
final class Bot
{
    public const BTN_LEAD = '📝 Оставить заявку';
    public const BTN_PRICES = '💰 Цены и сроки';
    public const BTN_FAQ = '❓ Частые вопросы';
    public const BTN_MANAGER = '💬 Связаться с менеджером';
    public const BTN_DELETE = '🗑 Удалить мои данные';
    public const BTN_CANCEL = '✖️ Отмена';
    public const BTN_SHARE = '📱 Поделиться номером';
    public const BTN_NO_PHONE = 'Без телефона — пишите в Telegram';

    private const EMERGENCY = ['авари', 'затоп', 'залива', 'залил', 'течет', 'течёт', 'протек', 'потоп', 'прорва', 'искрит', 'замыка', 'пожар', 'горит', 'застря', 'запах газ', 'пахнет газ'];

    private int $uid;
    private int $chatId;
    private array $from;
    private BotSession $s;

    public static function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $cq = $update['callback_query'];
            if (($cq['message']['chat']['type'] ?? '') !== 'private' || !empty($cq['from']['is_bot'])) {
                return;
            }
            (new self($cq['from'], (int)$cq['message']['chat']['id']))->onCallback($cq);
            return;
        }
        $m = $update['message'] ?? null;
        if (!$m || ($m['chat']['type'] ?? '') !== 'private' || empty($m['from']) || !empty($m['from']['is_bot'])) {
            return; // группы, каналы, изменённые сообщения и служебные события не обрабатываем
        }
        (new self($m['from'], (int)$m['chat']['id']))->onMessage($m);
    }

    private function __construct(array $from, int $chatId)
    {
        $this->from = $from;
        $this->uid = (int)$from['id'];
        $this->chatId = $chatId;
        $this->s = BotSession::load($this->uid);
    }

    // ---------------------------------------------------------------- сообщения

    private function onMessage(array $m): void
    {
        $limit = (array)App::cfg('telegram.rate_limit', ['max' => 30, 'window_sec' => 60]);
        if (!RateLimit::hit('bot', (string)$this->uid, (int)$limit['max'], (int)$limit['window_sec'])) {
            return;
        }
        $text = trim((string)($m['text'] ?? ''));

        if (preg_match('~^/start(?:@\w+)?(?:\s+(\S+))?~', $text, $mm)) {
            $this->start($mm[1] ?? '');
            return;
        }
        if (preg_match('~^/link(?:@\w+)?\s+(\S+)~', $text, $mm)) {
            $name = StaffLink::redeem($mm[1], $this->uid);
            $this->say($name ? '✅ Готово, ' . $this->e($name) . '. Уведомления CRM будут приходить в этот чат.' : 'Код не подошёл или устарел. Получите новый в профиле CRM.');
            return;
        }
        if ($text === '/stop' || $text === self::BTN_DELETE) {
            $this->askDelete();
            return;
        }
        if ($text === '/cancel' || $text === self::BTN_CANCEL || $text === '/menu') {
            $this->s->reset();
            $this->s->save();
            $this->say('Хорошо. Чем помочь?', $this->menu());
            return;
        }
        if ($text === '/help') {
            $this->say($this->intro(), $this->menu());
            return;
        }

        switch ($this->s->state) {
            case 'lead_name':
                $this->leadName($text);
                return;
            case 'lead_phone':
                $this->leadPhone($m, $text);
                return;
            case 'lead_text':
                $this->leadText($text);
                return;
            case 'ask_text':
                $this->askText($text);
                return;
        }

        if ($text === self::BTN_LEAD) {
            $this->leadStart();
            return;
        }
        if ($text === self::BTN_PRICES) {
            $this->prices();
            return;
        }
        if ($text === self::BTN_FAQ) {
            $this->faqList();
            return;
        }
        if ($text === self::BTN_MANAGER) {
            $this->s->state = 'ask_text';
            $this->s->save();
            $this->say('Напишите вопрос одним сообщением — я передам его менеджеру.', $this->cancelKb());
            return;
        }
        if ($text === '') {
            $this->say('Пока я понимаю только текст. Опишите, пожалуйста, вопрос словами.', $this->menu());
            return;
        }
        $this->freeText($text);
    }

    // ---------------------------------------------------------------- /start

    private function start(string $payload): void
    {
        $this->s->reset();
        $p = $payload !== '' ? Deeplink::parse($payload) : [];

        if (isset($p['deal'])) {
            $this->startFromSite($p['deal']);
            return;
        }
        if (isset($p['invalid'])) {
            $this->s->save();
            $this->say($this->intro() . "\n\nСсылка на заявку не распознана. Если вы оставляли заявку на сайте, просто напишите сюда — или оставьте её заново.", $this->menu());
            return;
        }
        if (isset($p['campaign']) || isset($p['tag'])) {
            $this->s->data['start_tag'] = mb_substr($p['campaign'] ?? $p['tag'], 0, 64);
        }
        $this->s->save();
        $this->say($this->intro(), $this->menu());
    }

    /** Переход с сайта после заявки: привязываем Telegram к той же карточке, сделку не дублируем. */
    private function startFromSite(string $number): void
    {
        $db = App::db();
        $deal = Deals::byNumber($number);
        if (!$deal) {
            $this->s->save();
            $this->say($this->intro(), $this->menu());
            return;
        }
        $contact = Contacts::find((int)$deal['contact_id']);
        if ($contact['anonymized_at']) {
            // данные по этой заявке удалены по 152-ФЗ — старую ссылку к ним не привязываем
            $this->s->save();
            $this->say('Эта заявка закрыта. Чтобы задать новый вопрос, нажмите «Оставить заявку».', $this->menu());
            return;
        }
        // ссылка привязывает Telegram, только пока заявка «нетронута»: одно сообщение, одно согласие, не старше 7 дней.
        // Иначе её мог получить человек, заранее оставивший заявку с чужим номером, и дождаться обращения жильца.
        if (!$contact['telegram_id']) {
            $inbound = (int)$db->value("SELECT COUNT(*) FROM messages m JOIN deals d ON d.id = m.deal_id WHERE d.contact_id = ? AND m.direction = 'in' AND m.author = 'client'", [$contact['id']]);
            $consents = (int)$db->value('SELECT COUNT(*) FROM consents WHERE contact_id = ?', [$contact['id']]);
            $fresh = strtotime((string)$deal['created_at']) >= App::now()->modify('-7 days')->getTimestamp();
            if ($inbound > 1 || $consents > 1 || !$fresh) {
                Log::event('deeplink_refused', ['deal' => $number, 'inbound' => $inbound, 'consents' => $consents, 'fresh' => $fresh]);
                $this->s->save();
                $this->say("Ссылка на заявку {$number} больше не действует. Если это ваша заявка — нажмите «Оставить заявку» и напишите сюда, менеджер свяжет обращения.", $this->menu());
                return;
            }
        }
        if ($contact['telegram_id'] && (int)$contact['telegram_id'] !== $this->uid) {
            $this->s->save();
            $this->say("Заявка {$number} уже связана с другим аккаунтом Telegram. Если это ваша заявка — напишите сюда новое сообщение через «Оставить заявку», менеджер разберётся.", $this->menu());
            return;
        }
        $db->tx(function () use ($contact) {
            $other = App::db()->value('SELECT id FROM contacts WHERE telegram_id = ? AND id <> ?', [$this->uid, $contact['id']]);
            if ($other) {
                Contacts::merge((int)$other, (int)$contact['id']);
            }
            $upd = ['telegram_id' => $this->uid, 'updated_at' => Db::ts(), 'last_activity_at' => Db::ts()];
            if (!empty($this->from['username'])) {
                $upd['telegram_username'] = strtolower($this->from['username']);
            }
            App::db()->update('contacts', $upd, 'id = ?', [$contact['id']]);
        });
        Deals::addSource((int)$contact['id'], (int)$deal['id'], ['channel' => 'site_bot', 'bot_start_tag' => str_replace('-', '', $number)]);
        Deals::addMessage((int)$deal['id'], 'note', 'bot', 'system', 'Клиент перешёл в Telegram-бот по ссылке с сайта');

        $this->s->data['deal_number'] = $number;
        $this->s->save();
        $name = '';   // по имени не здороваемся: ссылку мог переслать кто-то другой
        if (in_array($deal['stage'], Deals::OPEN, true)) {
            $this->say("Здравствуйте{$name}! Я бот ТСЖ «Пушкинская 15» — автоматический помощник.\n\nВаша заявка <b>{$number}</b> у нас. Если хотите что-то добавить — просто напишите сюда, сообщение попадёт в эту заявку. Ответ менеджера придёт в этот чат.", $this->menu());
        } else {
            $this->say("Здравствуйте{$name}! Заявка <b>{$number}</b> уже закрыта. Чтобы задать новый вопрос, нажмите «Оставить заявку» или просто напишите сюда.", $this->menu());
        }
    }

    // ---------------------------------------------------------------- заявка по шагам

    private function leadStart(): void
    {
        $this->s->reset();
        $this->s->state = 'lead_name';
        $this->s->save();
        $this->say("Оставим заявку — это 4 коротких шага.\n\n1/4. Как к вам обращаться?", $this->cancelKb());
    }

    private function leadName(string $text): void
    {
        if (mb_strlen($text) < 2 || mb_strlen($text) > 100 || str_starts_with($text, '/')) {
            $this->say('Напишите, пожалуйста, имя — от 2 до 100 символов.', $this->cancelKb());
            return;
        }
        $this->s->data['name'] = $text;
        $this->s->state = 'lead_phone';
        $this->s->save();
        $this->say('2/4. Телефон для связи. Нажмите «Поделиться номером» или напишите его.', [
            'reply_markup' => ['keyboard' => [[['text' => self::BTN_SHARE, 'request_contact' => true]], [['text' => self::BTN_NO_PHONE]], [['text' => self::BTN_CANCEL]]],
                'resize_keyboard' => true, 'one_time_keyboard' => true],
        ]);
    }

    private function leadPhone(array $m, string $text): void
    {
        if (isset($m['contact'])) {
            if ((int)($m['contact']['user_id'] ?? 0) !== $this->uid) {
                $this->say('Это контакт другого человека. Отправьте свой номер кнопкой или напишите его.');
                return;
            }
            $phone = Phone::normalize((string)$m['contact']['phone_number']);
        } elseif ($text === self::BTN_NO_PHONE) {
            $phone = null;
        } else {
            $phone = Phone::normalize($text);
            if (!$phone) {
                $this->say('Не похоже на российский номер. Напишите 10 цифр после +7 или нажмите «Без телефона».');
                return;
            }
        }
        $this->s->data['phone'] = $phone;
        // кнопкой «Поделиться номером» телефон подтверждает сам Telegram; набранный руками — нет
        $this->s->data['phone_verified'] = isset($m['contact']) && $phone !== null;
        $this->s->state = 'lead_text';
        $this->s->save();
        $this->say('3/4. Опишите, что случилось или что нужно.', $this->cancelKb());
    }

    private function leadText(string $text): void
    {
        if (mb_strlen($text) < 3) {
            $this->say('Опишите вопрос хотя бы парой слов.', $this->cancelKb());
            return;
        }
        $this->s->data['text'] = mb_substr($text, 0, 2000);
        $this->s->state = 'lead_consent';
        $this->s->save();
        $this->askConsent('lead');
    }

    private function askConsent(string $purpose): void
    {
        $policy = App::siteUrl() . '/politika/';
        $this->say(
            ($purpose === 'lead' ? '4/4. ' : '') . "Чтобы передать обращение менеджеру, нужно ваше согласие на обработку персональных данных: имя, телефон, идентификатор и ник в Telegram, текст обращения. Данные хранятся на сервере в России и используются только для ответа.\n\nПолитика: {$policy}",
            ['reply_markup' => ['inline_keyboard' => [[
                ['text' => '✅ Согласен', 'callback_data' => 'consent:' . $purpose],
                ['text' => 'Отмена', 'callback_data' => 'cancel'],
            ]]]]
        );
    }

    // ---------------------------------------------------------------- свободный текст и вопрос менеджеру

    private function freeText(string $text): void
    {
        if ($this->isEmergency($text)) {
            $a = App::db()->one("SELECT * FROM kb_articles WHERE active = 1 AND title = 'Авария' LIMIT 1");
            $this->say($a ? Kb::render($a['answer']) : 'Если это авария — звоните в аварийную службу. Угроза жизни — 112.');
        }
        // человек уже дал согласие и у него есть открытая заявка — сообщение идёт в неё
        if ($this->consented() && $this->openDealNumber()) {
            $this->passToManager($text, null);
            return;
        }
        $a = Kb::search($text);
        $this->s->data['pending_text'] = mb_substr($text, 0, 2000);
        $this->s->save();
        if ($a) {
            $this->say(Kb::render($a['answer']), ['reply_markup' => ['inline_keyboard' => [[['text' => 'Не то — спросить менеджера', 'callback_data' => 'ask']]]]]);
            return;
        }
        $this->say('Готового ответа у меня нет. Передать ваш вопрос менеджеру?', ['reply_markup' => ['inline_keyboard' => [
            [['text' => '💬 Передать менеджеру', 'callback_data' => 'ask']],
            [['text' => '❓ Частые вопросы', 'callback_data' => 'faq']],
        ]]]);
    }

    private function askText(string $text): void
    {
        if (mb_strlen($text) < 2) {
            $this->say('Напишите вопрос словами.', $this->cancelKb());
            return;
        }
        $this->s->data['pending_text'] = mb_substr($text, 0, 2000);
        $this->s->state = '';
        $this->s->save();
        if ($this->consented()) {
            $this->passToManager($text, null);
        } else {
            $this->askConsent('ask');
        }
    }

    /** Передать текст в CRM: в открытую заявку или новой заявкой. $consent — только если согласие дано сейчас. */
    private function passToManager(string $text, ?array $consent, array $lead = []): void
    {
        $in = [
            'channel'           => 'bot',
            'telegram_id'       => $this->uid,
            'telegram_username' => $this->from['username'] ?? null,
            'name'              => $lead['name'] ?? $this->profileName(),
            'phone'             => $lead['phone'] ?? null,
            'phone_verified'    => !empty($lead['phone_verified']),
            'text'              => $text,
            'deal_number'       => $this->s->data['deal_number'] ?? null,
        ];
        if ($consent) {
            $in['consent'] = $consent;
        }
        if (!$this->openDealNumber()) {
            $in['source'] = [
                'channel'       => !empty($this->s->data['deal_number']) ? 'site_bot' : 'bot',
                'bot_start_tag' => $this->s->data['start_tag'] ?? null,
            ];
        }
        try {
            $r = Leads::capture($in);
        } catch (InvalidArgumentException) {
            $this->askConsent('ask');
            return;
        }
        $this->s->reset();
        $this->s->data['deal_number'] = $r['number'];
        $this->s->save();

        $hours = (new WorkHours())->label();
        if ($r['created']) {
            $msg = "✅ Заявка <b>{$r['number']}</b> принята.";
            $msg .= $r['off_hours']
                ? "\n\nСейчас нерабочее время. Менеджер свяжется с вами в рабочее время: {$hours}."
                : "\n\nМенеджер свяжется с вами в рабочее время: {$hours}. Ответ придёт в этот чат или по телефону.";
            $msg .= "\n\nСохраните номер. Если захотите что-то добавить — просто напишите сюда.";
        } else {
            $msg = "Передал менеджеру — сообщение добавлено в заявку <b>{$r['number']}</b>.";
            if ($r['off_hours']) {
                $msg .= "\nСейчас нерабочее время, менеджер ответит в рабочие часы: {$hours}.";
            }
        }
        $this->say($msg, $this->menu());
    }

    // ---------------------------------------------------------------- кнопки под сообщениями

    private function onCallback(array $cq): void
    {
        $data = (string)($cq['data'] ?? '');
        $this->answerCallback((string)$cq['id']);
        $msgId = (int)($cq['message']['message_id'] ?? 0);

        if (str_starts_with($data, 'kb:')) {
            $a = Kb::find((int)substr($data, 3));
            $this->say($a ? '<b>' . $this->e($a['title']) . "</b>\n\n" . Kb::render($a['answer']) : 'Этот ответ больше не доступен.', $this->menu());
            return;
        }
        if ($data === 'faq') {
            $this->faqList();
            return;
        }
        if ($data === 'lead') {
            $this->leadStart();
            return;
        }
        if ($data === 'cancel' || $data === 'del:no') {
            $this->clearButtons($msgId);
            $this->s->reset();
            $this->s->save();
            $this->say('Хорошо, отменил. Чем помочь?', $this->menu());
            return;
        }
        if ($data === 'ask') {
            $text = (string)($this->s->data['pending_text'] ?? '');
            if ($text === '') {
                $this->s->state = 'ask_text';
                $this->s->save();
                $this->say('Напишите вопрос одним сообщением — я передам его менеджеру.', $this->cancelKb());
                return;
            }
            $this->clearButtons($msgId);
            $this->consented() ? $this->passToManager($text, null) : $this->askConsent('ask');
            return;
        }
        if ($data === 'consent:lead' && $this->s->state === 'lead_consent') {
            $this->clearButtons($msgId);
            $d = $this->s->data;
            $this->passToManager((string)$d['text'], ['telegram_id' => $this->uid], ['name' => $d['name'] ?? null, 'phone' => $d['phone'] ?? null, 'phone_verified' => !empty($d['phone_verified'])]);
            return;
        }
        if ($data === 'consent:ask' && !empty($this->s->data['pending_text'])) {
            $this->clearButtons($msgId);
            $this->passToManager((string)$this->s->data['pending_text'], ['telegram_id' => $this->uid]);
            return;
        }
        if ($data === 'del:yes') {
            $this->clearButtons($msgId);
            $this->deleteRequested();
            return;
        }
        // устаревшая кнопка (например, согласие на уже отправленную заявку)
        $this->say('Эта кнопка уже не действует. Чем помочь?', $this->menu());
    }

    // ---------------------------------------------------------------- база знаний, цены, удаление

    private function faqList(): void
    {
        $rows = [];
        foreach (Kb::active() as $a) {
            $rows[] = [['text' => mb_substr($a['button_label'] ?: $a['title'], 0, 40), 'callback_data' => 'kb:' . $a['id']]];
        }
        if (!$rows) {
            $this->say('Раздел пока пуст. Напишите вопрос — я передам его менеджеру.', $this->menu());
            return;
        }
        $this->say('Выберите вопрос:', ['reply_markup' => ['inline_keyboard' => $rows]]);
    }

    private function prices(): void
    {
        $labels = ['billing' => 'Вопросы по начислениям', 'master' => 'Вызов мастера в квартиру', 'keys' => 'Ключи и брелоки домофона',
            'docs' => 'Справки и выписки', 'waste' => 'Вывоз строительного мусора', 'issue' => 'Сообщить о неисправности в доме'];
        $lines = [];
        foreach ($labels as $k => $label) {
            $v = trim((string)App::cfg('prices.' . $k, ''));
            $lines[] = '• ' . $label . ' — ' . ($v !== '' ? $this->e($v) : '[Нужно от заказчика: цена]');
        }
        $note = App::cfg('services_confirmed', false) ? '' : "\n\n[Нужно от заказчика: утверждённый перечень услуг, цены и сроки]";
        $this->say("<b>Цены и сроки</b>\n\n" . implode("\n", $lines) . $note . "\n\nТочную стоимость и срок менеджер подтвердит по заявке.",
            ['reply_markup' => ['inline_keyboard' => [[['text' => self::BTN_LEAD, 'callback_data' => 'lead']]]]]);
    }

    private function askDelete(): void
    {
        $this->s->reset();
        $this->s->save();
        $this->say('Удалить ваши персональные данные? Запрос получит руководитель ТСЖ, после проверки данные будут обезличены. Открытые заявки после этого закроются без ответа.', [
            'reply_markup' => ['inline_keyboard' => [[['text' => 'Да, удалить', 'callback_data' => 'del:yes'], ['text' => 'Нет', 'callback_data' => 'del:no']]]],
        ]);
    }

    private function deleteRequested(): void
    {
        $db = App::db();
        $contact = $db->one('SELECT * FROM contacts WHERE telegram_id = ? AND anonymized_at IS NULL', [$this->uid]);
        $db->run('DELETE FROM bot_sessions WHERE telegram_id = ?', [$this->uid]);
        if (!$contact) {
            $this->say('У нас нет сохранённых данных о вас. Черновики диалога удалены.', ['reply_markup' => ['remove_keyboard' => true]]);
            return;
        }
        $db->tx(function () use ($contact) {
            $cid = (int)$contact['id'];
            Contacts::addConsent($cid, 'bot', null, $this->uid, 'revoked');
            $dealId = App::db()->value('SELECT id FROM deals WHERE contact_id = ? ORDER BY id DESC LIMIT 1', [$cid]);
            $admin = App::db()->value("SELECT id FROM users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1");
            Deals::addTask($dealId ? (int)$dealId : null, $admin ? (int)$admin : null, 'delete_request',
                'Запрос на удаление персональных данных (контакт #' . $cid . ')', App::now());
            Audit::log(null, 'contact', $cid, 'delete_requested', ['channel' => 'bot']);
        });
        $this->say("Запрос принят. Согласие на обработку отозвано, новые сообщения от вас сохраняться не будут. Руководитель ТСЖ проверит запрос, после этого данные будут обезличены.\n\nЕсли передумаете — просто оставьте новую заявку.", ['reply_markup' => ['remove_keyboard' => true]]);
    }

    // ---------------------------------------------------------------- помощники

    private function intro(): string
    {
        return "Здравствуйте! Я бот ТСЖ «Пушкинская 15» — автоматический помощник, а не человек.\n\n"
            . "Помогу оставить заявку, отвечу на частые вопросы и передам ваш вопрос менеджеру. Менеджеры работают " . (new WorkHours(null, [], []))->label() . ".\n\n"
            . "Выберите действие в меню или просто напишите вопрос.";
    }

    private function consented(): bool
    {
        $id = App::db()->value('SELECT id FROM contacts WHERE telegram_id = ? AND anonymized_at IS NULL', [$this->uid]);
        return $id && Contacts::hasConsent((int)$id);
    }

    private function openDealNumber(): ?string
    {
        $id = App::db()->value('SELECT id FROM contacts WHERE telegram_id = ?', [$this->uid]);
        if (!$id) {
            return null;
        }
        $d = Deals::openFor((int)$id);
        return $d['number'] ?? null;
    }

    private function profileName(): ?string
    {
        $n = trim(($this->from['first_name'] ?? '') . ' ' . ($this->from['last_name'] ?? ''));
        return $n !== '' ? mb_substr($n, 0, 100) : null;
    }

    private function isEmergency(string $text): bool
    {
        $t = mb_strtolower(str_replace('ё', 'е', $text));
        foreach (self::EMERGENCY as $w) {
            if (str_contains($t, str_replace('ё', 'е', $w))) {
                return true;
            }
        }
        return false;
    }

    private function menu(): array
    {
        return ['reply_markup' => [
            'keyboard' => [
                [['text' => self::BTN_LEAD]],
                [['text' => self::BTN_PRICES], ['text' => self::BTN_FAQ]],
                [['text' => self::BTN_MANAGER]],
                [['text' => self::BTN_DELETE]],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
        ]];
    }

    private function cancelKb(): array
    {
        return ['reply_markup' => ['keyboard' => [[['text' => self::BTN_CANCEL]]], 'resize_keyboard' => true]];
    }

    private function say(string $text, array $extra = []): void
    {
        Telegram::reply($this->chatId, $text, $extra);
    }

    private function answerCallback(string $id): void
    {
        try {
            Telegram::api('answerCallbackQuery', ['callback_query_id' => $id], 5);
        } catch (TelegramError) {
            // не критично: кнопка просто перестанет «крутиться» чуть позже
        }
    }

    private function clearButtons(int $msgId): void
    {
        if (!$msgId) {
            return;
        }
        try {
            Telegram::api('editMessageReplyMarkup', ['chat_id' => $this->chatId, 'message_id' => $msgId, 'reply_markup' => ['inline_keyboard' => []]], 5);
        } catch (TelegramError) {
        }
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
