<?php
declare(strict_types=1);

/** Простые шаблоны: {{key}} — экранированное значение, {{{key}}} — готовый HTML. */
final class Page
{
    public static function render(string $template, array $vars): string
    {
        $html = file_get_contents(APP_PRIVATE . '/templates/' . $template . '.html');
        $html = preg_replace_callback('/\{\{\{\s*(\w+)\s*\}\}\}/', static fn($m) => (string)($vars[$m[1]] ?? ''), $html);
        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', static fn($m) => htmlspecialchars((string)($vars[$m[1]] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $html);
    }

    /** Заметный плейсхолдер для данных, которых пока нет. */
    public static function ph(string $what): string
    {
        return '<mark class="ph" title="Нужно от заказчика">[Нужно от заказчика: ' . htmlspecialchars($what, ENT_QUOTES, 'UTF-8') . ']</mark>';
    }

    /** Значение из config.company или плейсхолдер. */
    public static function company(string $key, string $label): string
    {
        $v = trim((string)App::cfg('company.' . $key, ''));
        return $v === '' ? self::ph($label) : htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }

    public static function phoneLink(string $key, string $label): string
    {
        $v = trim((string)App::cfg('company.' . $key, ''));
        if ($v === '') {
            return self::ph($label);
        }
        $tel = preg_replace('/[^\d+]/', '', $v);
        return sprintf('<a href="tel:%s">%s</a>', htmlspecialchars($tel), htmlspecialchars($v));
    }

    public static function emailLink(): string
    {
        $v = trim((string)App::cfg('company.email', ''));
        return $v === '' ? self::ph('e-mail для обращений') : sprintf('<a href="mailto:%1$s">%1$s</a>', htmlspecialchars($v));
    }

    /** Общие переменные для всех страниц сайта. */
    public static function common(): array
    {
        $site = App::siteUrl();
        $bot = App::botUsername();
        return [
            'site_url'        => $site,
            'bot_username'    => $bot,
            'bot_url'         => Deeplink::botUrl('site'),
            'company_name'    => (string)App::cfg('company.name', ''),
            'inn'             => (string)App::cfg('company.inn', ''),
            'ogrn'            => (string)App::cfg('company.ogrn', ''),
            'ogrn_date'       => (string)App::cfg('company.ogrn_date', ''),
            'work_hours'      => (new WorkHours(null, [], []))->label(),
            'policy_version'  => (string)App::cfg('policy_version', ''),
            'year'            => date('Y'),
            'full_name'       => self::company('full_name', 'полное наименование ТСЖ по уставу'),
            'address'         => self::company('address', 'юридический адрес'),
            'dpo_name'        => self::company('dpo_name', 'ответственный за обработку персональных данных'),
            'rkn_number'      => self::company('rkn_number', 'номер в реестре операторов ПДн'),
            'phone_office'    => self::phoneLink('phone_office', 'телефон правления'),
            'phone_emergency' => self::phoneLink('phone_emergency', 'телефон аварийной службы'),
            'email'           => self::emailLink(),
            'retention'       => (string)(int)App::cfg('retention_months', 12),
            'cross_border'    => self::ph('заключение юриста о трансграничной передаче данных через Telegram и уведомлении Роскомнадзора'),
        ] + self::prices();
    }

    private static function prices(): array
    {
        $out = [];
        foreach (['billing', 'master', 'keys', 'docs', 'waste', 'issue'] as $k) {
            $v = trim((string)App::cfg('prices.' . $k, ''));
            $out['price_' . $k] = $v === '' ? self::ph('цена') : htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        }
        $out['services_note'] = App::cfg('services_confirmed', false)
            ? 'Точную стоимость и сроки менеджер подтвердит по заявке.'
            : 'Перечень услуг на этой странице — черновик. ' . self::ph('утверждённый перечень услуг, цены и сроки');
        return $out;
    }
}
