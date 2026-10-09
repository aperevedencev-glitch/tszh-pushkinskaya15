<?php
declare(strict_types=1);

/**
 * Рабочее время менеджеров: дни недели и часы из config.php,
 * праздники (holidays) и перенесённые рабочие дни (workdays) — в настройках CRM.
 */
final class WorkHours
{
    private array $days;
    private string $start;
    private string $end;
    private array $holidays;
    private array $workdays;

    public function __construct(?array $cfg = null, ?array $holidays = null, ?array $workdays = null)
    {
        $cfg ??= (array)App::cfg('work_hours', []);
        $this->days = array_map('intval', $cfg['days'] ?? [1, 2, 3, 4, 5]);
        $this->start = $cfg['start'] ?? '09:00';
        $this->end = $cfg['end'] ?? '18:00';
        $this->holidays = $holidays ?? (array)Settings::get('holidays', []);
        $this->workdays = $workdays ?? (array)Settings::get('workdays', []);
    }

    public function isWorkingDay(DateTimeImmutable $d): bool
    {
        $ymd = $d->format('Y-m-d');
        if (in_array($ymd, $this->workdays, true)) {
            return true;
        }
        if (in_array($ymd, $this->holidays, true)) {
            return false;
        }
        return in_array((int)$d->format('N'), $this->days, true);
    }

    public function isWorkingTime(DateTimeImmutable $d): bool
    {
        if (!$this->isWorkingDay($d)) {
            return false;
        }
        $hm = $d->format('H:i');
        return $hm >= $this->start && $hm < $this->end;
    }

    /** Ближайшее начало рабочего дня строго после $d (или $d, если сейчас рабочее время). */
    public function nextStart(DateTimeImmutable $d): DateTimeImmutable
    {
        if ($this->isWorkingTime($d)) {
            return $d;
        }
        [$h, $m] = array_map('intval', explode(':', $this->start));
        $cand = $d->setTime($h, $m);
        if ($cand <= $d) {
            $cand = $cand->modify('+1 day');
        }
        for ($i = 0; $i < 30 && !$this->isWorkingDay($cand); $i++) {
            $cand = $cand->modify('+1 day');
        }
        return $cand;
    }

    /** Сколько рабочих минут прошло между двумя моментами (для «времени до первого ответа»). */
    public function workingMinutes(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        if ($to <= $from) {
            return 0;
        }
        [$sh, $sm] = array_map('intval', explode(':', $this->start));
        [$eh, $em] = array_map('intval', explode(':', $this->end));
        $total = 0;
        $day = $from->setTime(0, 0);
        for ($i = 0; $day <= $to && $i < 400; $i++, $day = $day->modify('+1 day')) {
            if (!$this->isWorkingDay($day)) {
                continue;
            }
            $ws = max($day->setTime($sh, $sm)->getTimestamp(), $from->getTimestamp());
            $we = min($day->setTime($eh, $em)->getTimestamp(), $to->getTimestamp());
            if ($we > $ws) {
                $total += intdiv($we - $ws, 60);
            }
        }
        return $total;
    }

    public function label(): string
    {
        $names = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];
        $d = $this->days;
        sort($d);
        $span = (count($d) > 1 && end($d) - $d[0] === count($d) - 1)
            ? $names[$d[0]] . '–' . $names[end($d)]
            : implode(', ', array_map(static fn($x) => $names[$x], $d));
        return sprintf('%s, %s–%s', $span, $this->start, $this->end);
    }
}
