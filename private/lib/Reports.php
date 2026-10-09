<?php
declare(strict_types=1);

/** Отчёты руководителя. Источник сделки — первое касание контакта (самая ранняя строка sources). */
final class Reports
{
    public function __construct(private string $from, private string $to)
    {
    }

    /** Период из запроса: по умолчанию последние 30 дней. Границы — даты, «по» включительно. */
    public static function fromRequest(array $q): self
    {
        $to = self::date($q['to'] ?? '') ?? date('Y-m-d');
        $from = self::date($q['from'] ?? '') ?? date('Y-m-d', strtotime($to . ' -29 days'));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return new self($from, $to);
    }

    private static function date(string $s): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
    }

    public function from(): string
    {
        return $this->from;
    }

    public function to(): string
    {
        return $this->to;
    }

    private function range(): array
    {
        return [$this->from . ' 00:00:00', date('Y-m-d', strtotime($this->to . ' +1 day')) . ' 00:00:00'];
    }

    private const FIRST_TOUCH = 'LEFT JOIN sources s ON s.id = (SELECT MIN(s2.id) FROM sources s2 WHERE s2.contact_id = d.contact_id)';

    private static function label(?string $channel): string
    {
        return ['site_form' => 'Сайт, форма', 'bot' => 'Telegram-бот', 'site_bot' => 'Сайт → бот'][$channel ?? ''] ?? 'Не указан';
    }

    /** Заявки и выручка по источнику и кампании первого касания. */
    public function sources(): array
    {
        [$a, $b] = $this->range();
        $db = App::db();
        $rows = [];
        $key = static fn($r) => ($r['channel'] ?? '') . '|' . ($r['utm_source'] ?? '') . '|' . ($r['utm_campaign'] ?? '');
        $init = static fn($r) => ['channel' => self::label($r['channel']), 'utm_source' => $r['utm_source'] ?: '—', 'utm_campaign' => $r['utm_campaign'] ?: '—',
            'leads' => 0, 'off_hours' => 0, 'paid' => 0, 'revenue' => 0.0];
        foreach ($db->all('SELECT d.is_off_hours, s.channel, s.utm_source, s.utm_campaign FROM deals d ' . self::FIRST_TOUCH . ' WHERE d.created_at >= ? AND d.created_at < ?', [$a, $b]) as $r) {
            $rows[$key($r)] ??= $init($r);
            $rows[$key($r)]['leads']++;
            $rows[$key($r)]['off_hours'] += (int)$r['is_off_hours'];
        }
        foreach ($db->all("SELECT d.amount, s.channel, s.utm_source, s.utm_campaign FROM deals d " . self::FIRST_TOUCH . " WHERE d.stage = 'paid' AND d.closed_at >= ? AND d.closed_at < ?", [$a, $b]) as $r) {
            $rows[$key($r)] ??= $init($r);
            $rows[$key($r)]['paid']++;
            $rows[$key($r)]['revenue'] += (float)$r['amount'];
        }
        usort($rows, static fn($x, $y) => [$y['revenue'], $y['leads']] <=> [$x['revenue'], $x['leads']]);
        return $rows;
    }

    /** Заявки по часам суток; сколько пришло в нерабочее время. */
    public function hours(): array
    {
        [$a, $b] = $this->range();
        $h = array_fill(0, 24, 0);
        $off = 0;
        $total = 0;
        foreach (App::db()->all('SELECT created_at, is_off_hours FROM deals WHERE created_at >= ? AND created_at < ?', [$a, $b]) as $r) {
            $h[(int)substr($r['created_at'], 11, 2)]++;
            $off += (int)$r['is_off_hours'];
            $total++;
        }
        return ['hours' => $h, 'off' => $off, 'total' => $total];
    }

    /** Воронка: сколько сделок дошло до каждого этапа. */
    public function funnel(): array
    {
        [$a, $b] = $this->range();
        $db = App::db();
        $deals = $db->all('SELECT id, stage FROM deals WHERE created_at >= ? AND created_at < ?', [$a, $b]);
        $order = ['new' => 0, 'contacted' => 1, 'invoiced' => 2, 'paid' => 3];
        $reached = ['new' => 0, 'contacted' => 0, 'invoiced' => 0, 'paid' => 0];
        $lost = 0;
        foreach ($deals as $d) {
            $max = $order[$d['stage']] ?? 0;
            foreach ($db->all('SELECT to_stage FROM deal_stage_log WHERE deal_id = ?', [$d['id']]) as $l) {
                $max = max($max, $order[$l['to_stage']] ?? 0);
            }
            foreach ($order as $st => $i) {
                if ($i <= $max) {
                    $reached[$st]++;
                }
            }
            $lost += $d['stage'] === 'lost' ? 1 : 0;
        }
        $out = [];
        $prev = null;
        foreach ($reached as $st => $n) {
            $out[] = ['stage' => Deals::STAGES[$st], 'count' => $n,
                'from_prev' => $prev ? round($n / $prev * 100) : null,
                'from_total' => $reached['new'] ? round($n / $reached['new'] * 100) : null];
            $prev = $n ?: null;
        }
        return ['rows' => $out, 'lost' => $lost, 'total' => $reached['new']];
    }

    /** Время до первого ответа в рабочих минутах: по сотрудникам и в целом. */
    public function firstResponse(): array
    {
        [$a, $b] = $this->range();
        $wh = new WorkHours();
        $rows = App::db()->all('SELECT d.created_at, d.first_response_at, d.manager_id, u.name FROM deals d LEFT JOIN users u ON u.id = d.manager_id WHERE d.created_at >= ? AND d.created_at < ?', [$a, $b]);
        $by = [];
        $all = [];
        $without = 0;
        foreach ($rows as $r) {
            $name = $r['name'] ?? 'Не назначен';
            $by[$name] ??= ['name' => $name, 'mins' => [], 'without' => 0];
            if (!$r['first_response_at']) {
                $by[$name]['without']++;
                $without++;
                continue;
            }
            $m = $wh->workingMinutes(new DateTimeImmutable($r['created_at']), new DateTimeImmutable($r['first_response_at']));
            $by[$name]['mins'][] = $m;
            $all[] = $m;
        }
        $stat = static function (array $m): array {
            if (!$m) {
                return ['avg' => null, 'median' => null, 'n' => 0];
            }
            sort($m);
            $n = count($m);
            $med = $n % 2 ? $m[intdiv($n, 2)] : ($m[$n / 2 - 1] + $m[$n / 2]) / 2;
            return ['avg' => (int)round(array_sum($m) / $n), 'median' => (int)round($med), 'n' => $n];
        };
        $out = [];
        foreach ($by as $b2) {
            $out[] = ['name' => $b2['name'], 'without' => $b2['without']] + $stat($b2['mins']);
        }
        return ['by' => $out, 'total' => $stat($all) + ['without' => $without]];
    }

    public static function minutes(?int $m): string
    {
        if ($m === null) {
            return '—';
        }
        return $m < 60 ? $m . ' мин' : intdiv($m, 60) . ' ч ' . ($m % 60) . ' мин';
    }

    /** CSV для Excel: UTF-8 с BOM, разделитель «;». */
    public static function csv(string $filename, array $header, array $rows): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header, ';', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, array_map(static function ($v) {
                if (is_float($v)) {
                    return number_format($v, 2, ',', '');
                }
                $v = (string)$v;
                // защита от формул в Excel: значения из UTM приходят от посетителей
                return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
            }, $r), ';', '"', '');
        }
        fclose($out);
        exit;
    }
}
