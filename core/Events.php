<?php
/**
 * Events — recurring event occurrence engine + helpers.
 */
class Events
{
    // ── Occurrence generation ────────────────────────────────

    /**
     * Return occurrences of $event that fall within [$from, $to].
     * Each occurrence is ['start' => DateTimeImmutable, 'end' => DateTimeImmutable|null].
     */
    public static function occurrences(
        array            $event,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int              $max = 200
    ): array {
        $start  = new DateTimeImmutable($event['start_dt']);
        $durSec = 0;
        if (!empty($event['end_dt'])) {
            $durSec = max(0, (new DateTimeImmutable($event['end_dt']))->getTimestamp() - $start->getTimestamp());
        }

        $type     = $event['recur_type']     ?? 'none';
        $interval = max(1, (int)($event['recur_interval'] ?? 1));
        $until    = !empty($event['recur_until'])
                        ? new DateTimeImmutable($event['recur_until'] . ' 23:59:59')
                        : null;
        $maxCount = !empty($event['recur_count']) ? (int)$event['recur_count'] : null;

        // ── Single (non-recurring) ──────────────────────────
        if ($type === 'none') {
            return ($start >= $from && $start <= $to)
                ? [self::occ($start, $durSec)]
                : [];
        }

        // ── Recurring ───────────────────────────────────────
        $results  = [];
        $current  = $start;
        $emitted  = 0;

        for ($guard = 0; $guard < 5000; $guard++) {
            if ($until   && $current > $until) break;
            if ($current > $to)                break;

            if ($current >= $from) {
                $results[] = self::occ($current, $durSec);
                if (count($results) >= $max) break;
            }

            $emitted++;
            if ($maxCount && $emitted >= $maxCount) break;

            $next = self::advance($current, $type, $interval, $event);
            if ($next === null || $next <= $current) break;
            $current = $next;
        }

        return $results;
    }

    private static function occ(DateTimeImmutable $start, int $durSec): array
    {
        return [
            'start' => $start,
            'end'   => $durSec > 0 ? $start->modify("+{$durSec} seconds") : null,
        ];
    }

    private static function advance(
        DateTimeImmutable $cur,
        string $type,
        int    $interval,
        array  $event
    ): ?DateTimeImmutable {
        switch ($type) {

            case 'daily':
                return $cur->modify("+{$interval} days");

            case 'weekly':
                $days = array_map('intval', array_filter(
                    explode(',', $event['recur_days'] ?? ''),
                    fn($v) => $v !== ''
                ));
                if (empty($days)) $days = [(int)$cur->format('w')];
                sort($days);
                $dow = (int)$cur->format('w');
                // Next day within same week cycle
                foreach ($days as $d) {
                    if ($d > $dow) {
                        return $cur->modify('+' . ($d - $dow) . ' days');
                    }
                }
                // Wrap to first day of the next interval-week cycle
                $skip = 7 * $interval - $dow + $days[0];
                return $cur->modify("+{$skip} days");

            case 'monthly':
                if (($event['recur_month_type'] ?? 'date') === 'date') {
                    return $cur->modify("+{$interval} months");
                }
                // Same weekday-of-month (e.g. "2nd Tuesday")
                $dow  = (int)$cur->format('w');
                $dom  = (int)$cur->format('j');
                $nth  = (int)ceil($dom / 7);
                $next = $cur->modify("+{$interval} months");
                return self::nthWeekday(
                    (int)$next->format('Y'),
                    (int)$next->format('n'),
                    $dow,
                    $nth,
                    $cur->format('H:i:s')
                );

            case 'yearly':
                return $cur->modify("+{$interval} years");

            default:
                return null;
        }
    }

    /** Find the Nth occurrence of $dow (0=Sun) in a given year/month. */
    private static function nthWeekday(int $y, int $m, int $dow, int $nth, string $time): DateTimeImmutable
    {
        $first    = new DateTimeImmutable(sprintf('%04d-%02d-01 %s', $y, $m, $time));
        $firstDow = (int)$first->format('w');
        $diff     = ($dow - $firstDow + 7) % 7;
        $day      = 1 + $diff + ($nth - 1) * 7;
        $daysInM  = (int)$first->format('t');
        while ($day > $daysInM) $day -= 7;
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d %s', $y, $m, $day, $time));
    }

    // ── Convenience queries ──────────────────────────────────

    /**
     * Return the next $limit upcoming published occurrences within $daysAhead days.
     * Each row is the original event row merged with 'occ_start' and 'occ_end' strings.
     */
    public static function upcoming(int $siteId, int $limit = 10, int $daysAhead = 180): array
    {
        $from = new DateTimeImmutable('today midnight');
        $to   = new DateTimeImmutable("today midnight +{$daysAhead} days");

        $events = Database::fetchAll(
            "SELECT * FROM events WHERE site_id = ? AND status = 'published' ORDER BY start_dt ASC",
            [$siteId]
        );

        $result = [];
        foreach ($events as $e) {
            foreach (self::occurrences($e, $from, $to, $limit) as $occ) {
                $result[] = array_merge($e, [
                    'occ_start' => $occ['start']->format('Y-m-d H:i:s'),
                    'occ_end'   => $occ['end'] ? $occ['end']->format('Y-m-d H:i:s') : null,
                ]);
                if (count($result) >= $limit * 3) break;
            }
        }

        usort($result, fn($a, $b) => strcmp($a['occ_start'], $b['occ_start']));
        return array_slice($result, 0, $limit);
    }

    // ── Formatting helpers ───────────────────────────────────

    public static function formatDateRange(string $start, ?string $end, bool $allDay = false): string
    {
        $s = new DateTimeImmutable($start);
        if ($allDay) {
            if (!$end) return $s->format('l, F j, Y');
            $e = new DateTimeImmutable($end);
            if ($s->format('Y-m-d') === $e->format('Y-m-d')) return $s->format('l, F j, Y');
            return $s->format('F j') . '–' . $e->format('j, Y');
        }
        $startStr = $s->format('l, F j, Y \a\t g:i a');
        if (!$end) return $startStr;
        $e = new DateTimeImmutable($end);
        if ($s->format('Y-m-d') === $e->format('Y-m-d')) {
            return $s->format('l, F j, Y') . ', ' . $s->format('g:i a') . '–' . $e->format('g:i a');
        }
        return $startStr . ' – ' . $e->format('F j, Y \a\t g:i a');
    }

    public static function recurrenceLabel(array $event): string
    {
        $type = $event['recur_type'] ?? 'none';
        if ($type === 'none') return '';
        $n    = (int)($event['recur_interval'] ?? 1);
        $days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        switch ($type) {
            case 'daily':   return $n === 1 ? 'Daily' : "Every {$n} days";
            case 'weekly':
                $label = $n === 1 ? 'Weekly' : "Every {$n} weeks";
                if (!empty($event['recur_days'])) {
                    $dl = array_map(fn($d) => $days[(int)$d] ?? '', explode(',', $event['recur_days']));
                    $label .= ' on ' . implode(', ', array_filter($dl));
                }
                return $label;
            case 'monthly': return $n === 1 ? 'Monthly' : "Every {$n} months";
            case 'yearly':  return $n === 1 ? 'Yearly'  : "Every {$n} years";
        }
        return ucfirst($type);
    }

    // ── Slug helper ──────────────────────────────────────────

    public static function makeSlug(string $title, int $siteId, int $excludeId = 0): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($title));
        $base = trim($base, '-') ?: 'event';
        $slug = $base;
        for ($i = 2; $i < 1000; $i++) {
            $clash = Database::fetch(
                "SELECT id FROM events WHERE slug = ? AND site_id = ? AND id != ?",
                [$slug, $siteId, $excludeId]
            );
            if (!$clash) return $slug;
            $slug = "{$base}-{$i}";
        }
        return $base . '-' . time();
    }
}
