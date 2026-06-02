<?php
/**
 * Lectionary — Roman Catholic liturgical calendar calculator.
 *
 * Determines the liturgical season, week, and day for any date;
 * generates a lookup key for the lectionary_readings table;
 * and converts human-readable Scripture references to api.bible format.
 */
class Lectionary
{
    // ── Calendar calculations ────────────────────────────────────────────────

    /** Easter Sunday (Gregorian algorithm). */
    public static function easter(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day   = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /** First Sunday of Advent (Sunday nearest to Nov 30, always on or before Dec 3). */
    public static function adventStart(int $year): \DateTimeImmutable
    {
        $nov30 = new \DateTimeImmutable("$year-11-30");
        $dow   = (int) $nov30->format('w'); // 0 = Sun
        $add   = ($dow === 0) ? 0 : 7 - $dow;
        return $nov30->modify("+{$add} days");
    }

    /** Baptism of the Lord: Sunday after Jan 6 (or Jan 13 if Jan 6 is a Sunday). */
    public static function baptismOfLord(int $year): \DateTimeImmutable
    {
        $jan6 = new \DateTimeImmutable("$year-01-06");
        $dow  = (int) $jan6->format('w');
        $add  = ($dow === 0) ? 7 : 7 - $dow;
        return $jan6->modify("+{$add} days");
    }

    /**
     * Liturgical year number (ending year of the cycle).
     * Year A 2022-2023 → 2023, Year B 2023-2024 → 2024, etc.
     */
    public static function liturgicalYear(\DateTimeInterface $date): int
    {
        $y = (int) $date->format('Y');
        return ($date >= self::adventStart($y)) ? $y + 1 : $y;
    }

    /** Sunday reading cycle: A, B, or C. */
    public static function sundayCycle(\DateTimeInterface $date): string
    {
        $offset = (self::liturgicalYear($date) - 2023) % 3;
        return ['A', 'B', 'C'][($offset + 3) % 3];
    }

    /** Weekday reading cycle: I or II. */
    public static function weekdayCycle(\DateTimeInterface $date): string
    {
        return (self::liturgicalYear($date) % 2 === 1) ? 'I' : 'II';
    }

    // ── Main liturgical day resolver ─────────────────────────────────────────

    /**
     * Returns an array describing the liturgical day:
     *   season        — 'advent' | 'christmas' | 'ordinary' | 'lent' | 'holyweek' | 'easter'
     *   week          — week number within the season
     *   dow           — day of week 0 (Sun) – 6 (Sat)
     *   sunday_cycle  — A | B | C
     *   weekday_cycle — I | II
     *   title         — human-readable liturgical day name
     *   lookup_key    — primary key for lectionary_readings table
     *   lookup_key_w  — secondary key (weekday variant)
     */
    public static function liturgicalDay(\DateTimeInterface $date): array
    {
        $year = (int) $date->format('Y');
        $dow  = (int) $date->format('w');
        $ymd  = $date->format('Y-m-d');
        $d    = \DateTimeImmutable::createFromInterface($date);

        $sc = self::sundayCycle($d);
        $wc = self::weekdayCycle($d);

        $dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

        // Key liturgical dates for this year
        $adventThis    = self::adventStart($year);
        $xmasThis      = new \DateTimeImmutable("$year-12-25");
        $xmasPrev      = new \DateTimeImmutable(($year - 1) . "-12-25");
        $baptismThis   = self::baptismOfLord($year);
        $baptismPrev   = self::baptismOfLord($year - 1);
        $easter        = self::easter($year);
        $ashWed        = $easter->modify('-46 days');
        $palmSunday    = $easter->modify('-7 days');
        $holyThursday  = $easter->modify('-3 days');
        $pentecost     = $easter->modify('+49 days');

        $ord = fn(int $n) => self::ordinal($n);

        // ── ADVENT ──────────────────────────────────────────────────────────
        if ($d >= $adventThis && $d < $xmasThis) {
            $diff    = (int) $adventThis->diff($d)->days;
            $week    = min((int)($diff / 7) + 1, 4);
            $title   = ($dow === 0)
                ? $ord($week) . ' Sunday of Advent'
                : 'Advent, Week ' . $week . ' — ' . $dayNames[$dow];
            return self::make('advent', $week, $dow, $sc, $wc, $title, "S-advent-{$week}-{$sc}", "W-advent-{$week}-{$dow}-{$wc}");
        }

        // ── CHRISTMAS SEASON (this year: Dec 25 onward) ─────────────────────
        if ($d >= $xmasThis) {
            return self::christmasInfo($d, $xmasThis, $sc, $wc, $dow, $ymd, $year);
        }

        // ── CHRISTMAS SEASON (Jan: prev Christmas → Baptism of the Lord) ────
        if ($d >= $xmasPrev && $d < $baptismPrev) {
            return self::christmasInfo($d, $xmasPrev, $sc, $wc, $dow, $ymd, $year - 1);
        }
        if ($ymd === $baptismPrev->format('Y-m-d')) {
            return self::make('christmas', 1, 0, $sc, $wc, 'Baptism of the Lord', "DATE-{$ymd}", "S-christmas-baptism-{$sc}");
        }
        if ($ymd === $baptismThis->format('Y-m-d')) {
            return self::make('christmas', 1, 0, $sc, $wc, 'Baptism of the Lord', "DATE-{$ymd}", "S-christmas-baptism-{$sc}");
        }

        // ── LENT ─────────────────────────────────────────────────────────────
        if ($d >= $ashWed && $d < $palmSunday) {
            if ($ymd === $ashWed->format('Y-m-d')) {
                return self::make('lent', 0, 3, $sc, $wc, 'Ash Wednesday', "DATE-{$ymd}", "DATE-{$ymd}");
            }
            // Days after Ash Wed: first Sunday of Lent is 4 days after Ash Wed
            $diff = (int) $ashWed->diff($d)->days;
            // Calculate Lent week: Ash Wed = pre-week 1; first Sunday = day 4 (if Ash Wed is Wed)
            // Simpler: find the Monday before Ash Wed to start week counting
            $ashDow = (int) $ashWed->format('w'); // Should always be Wed (3)
            $weekStart = $ashWed->modify('-' . $ashDow . ' days'); // Sunday of Ash Wed week
            $lentDiff = (int) $weekStart->diff($d)->days;
            $week = (int)($lentDiff / 7) + 1;
            $title = ($dow === 0)
                ? $ord($week) . ' Sunday of Lent'
                : 'Lent, Week ' . $week . ' — ' . $dayNames[$dow];
            return self::make('lent', $week, $dow, $sc, $wc, $title, "S-lent-{$week}-{$sc}", "W-lent-{$week}-{$dow}-{$wc}");
        }

        // ── HOLY WEEK ────────────────────────────────────────────────────────
        if ($d >= $palmSunday && $d <= $holyThursday) {
            $hwTitles = [
                $palmSunday->format('Y-m-d')   => 'Palm Sunday of the Lord\'s Passion',
                $easter->modify('-6 days')->format('Y-m-d') => 'Holy Monday',
                $easter->modify('-5 days')->format('Y-m-d') => 'Holy Tuesday',
                $easter->modify('-4 days')->format('Y-m-d') => 'Holy Wednesday',
                $holyThursday->format('Y-m-d') => 'Holy Thursday (Mass of the Lord\'s Supper)',
            ];
            $title = $hwTitles[$ymd] ?? 'Holy Week';
            return self::make('holyweek', 6, $dow, $sc, $wc, $title, "DATE-{$ymd}", "DATE-{$ymd}");
        }

        // ── EASTER TRIDUUM: Good Friday & Holy Saturday ──────────────────────
        $gfYmd = $easter->modify('-2 days')->format('Y-m-d');
        $hsYmd = $easter->modify('-1 day')->format('Y-m-d');
        if ($ymd === $gfYmd) return self::make('holyweek', 6, 5, $sc, $wc, 'Good Friday', "DATE-{$ymd}", "DATE-{$ymd}");
        if ($ymd === $hsYmd) return self::make('holyweek', 6, 6, $sc, $wc, 'Holy Saturday / Easter Vigil', "DATE-{$ymd}", "DATE-{$ymd}");

        // ── EASTER SEASON ────────────────────────────────────────────────────
        if ($d >= $easter && $d <= $pentecost) {
            $diff = (int) $easter->diff($d)->days;
            $week = (int)($diff / 7) + 1;
            if ($diff === 0)       $title = 'Easter Sunday';
            elseif ($diff === 49)  $title = 'Pentecost Sunday';
            elseif ($dow === 0)    $title = $ord($week) . ' Sunday of Easter';
            elseif ($diff < 8)     $title = $ord($diff + 1) . ' Day within the Octave of Easter';
            else                   $title = 'Easter, Week ' . $week . ' — ' . $dayNames[$dow];
            $lk = ($dow === 0) ? "S-easter-{$week}-{$sc}" : "DATE-{$ymd}";
            return self::make('easter', $week, $dow, $sc, $wc, $title, $lk, "W-easter-{$week}-{$dow}-{$wc}");
        }

        // ── ORDINARY TIME ────────────────────────────────────────────────────
        // Period 1: Day after Baptism of the Lord → day before Ash Wednesday
        $ot1Start = $baptismThis->modify('+1 day');
        if ($d >= $ot1Start && $d < $ashWed) {
            $diff = (int) $ot1Start->diff($d)->days;
            $week = (int)($diff / 7) + 1;
            $title = ($dow === 0)
                ? $ord($week) . ' Sunday of Ordinary Time'
                : 'Ordinary Time, Week ' . $week . ' — ' . $dayNames[$dow];
            $lk = ($dow === 0) ? "S-ordinary-{$week}-{$sc}" : "W-ordinary-{$week}-{$dow}-{$wc}";
            return self::make('ordinary', $week, $dow, $sc, $wc, $title, $lk, "W-ordinary-{$week}-{$dow}-{$wc}");
        }

        // Period 2: Day after Pentecost → day before Advent
        if ($d > $pentecost && $d < $adventThis) {
            // The 34th Sunday of OT is always the Sunday immediately before Advent
            $sundayBeforeAdvent = $adventThis->modify('-7 days');
            if ($dow === 0) {
                $weeksFromEnd = (int)($d->diff($sundayBeforeAdvent)->days / 7);
            } else {
                $thisSunday   = $d->modify('-' . $dow . ' days');
                $weeksFromEnd = (int)($thisSunday->diff($sundayBeforeAdvent)->days / 7);
            }
            $week  = max(1, min(34, 34 - $weeksFromEnd));
            $title = ($dow === 0)
                ? $ord($week) . ' Sunday of Ordinary Time'
                : 'Ordinary Time, Week ' . $week . ' — ' . $dayNames[$dow];
            $lk = ($dow === 0) ? "S-ordinary-{$week}-{$sc}" : "W-ordinary-{$week}-{$dow}-{$wc}";
            return self::make('ordinary', $week, $dow, $sc, $wc, $title, $lk, "W-ordinary-{$week}-{$dow}-{$wc}");
        }

        // Fallback (should not be reached)
        return self::make('ordinary', 1, $dow, $sc, $wc, 'Ordinary Time', "W-ordinary-1-{$dow}-{$wc}", "W-ordinary-1-{$dow}-{$wc}");
    }

    // ── Database lookup ──────────────────────────────────────────────────────

    /**
     * Fetch the lectionary_readings row for a given date.
     * Priority: exact date override → primary lookup key → weekday key.
     * Falls back to auto-fetching from USCCB if auto-fetch is enabled.
     * Returns the DB row merged with 'info' key, or null if not found.
     */
    /**
     * Fetch the lectionary_readings row for a given date.
     *
     * @param bool $autoFetch  When true (admin context only), will make an HTTP
     *                         request to USCCB if the readings are not in the DB.
     *                         Always pass false on public page renders to avoid
     *                         blocking the response for up to 10 seconds.
     */
    public static function readingsForDate(\DateTimeInterface $date, bool $autoFetch = false): ?array
    {
        $info = self::liturgicalDay($date);
        $ymd  = $date->format('Y-m-d');

        $row = Database::fetch("SELECT * FROM lectionary_readings WHERE date_override = ?", [$ymd]);
        if ($row) return array_merge($row, ['info' => $info]);

        $row = Database::fetch("SELECT * FROM lectionary_readings WHERE lookup_key = ?", [$info['lookup_key']]);
        if ($row) return array_merge($row, ['info' => $info]);

        if (!empty($info['lookup_key_w']) && $info['lookup_key_w'] !== $info['lookup_key']) {
            $row = Database::fetch("SELECT * FROM lectionary_readings WHERE lookup_key = ?", [$info['lookup_key_w']]);
            if ($row) return array_merge($row, ['info' => $info]);
        }

        // Auto-fetch from USCCB — only when explicitly requested (admin context).
        // NEVER call this during a public page render: it makes a blocking HTTP
        // request with a 10-second timeout and will kill TTFB.
        if ($autoFetch && setting('readings_auto_fetch', '1') === '1') {
            if (self::autoFetchForDate($date)) {
                $row = Database::fetch(
                    "SELECT * FROM lectionary_readings WHERE date_override = ?", [$ymd]
                );
                if ($row) return array_merge($row, ['info' => $info]);
            }
        }

        return null;
    }

    // ── USCCB auto-fetch ─────────────────────────────────────────────────────

    /**
     * Fetch one date's readings from the USCCB daily readings page and store in the DB.
     * Captures both the liturgical citations (e.g. "Is 42:1-4, 6-7") and the full
     * passage text from the same HTML page — no separate Bible API needed.
     *
     * Returns true if at least a gospel reference was found and stored.
     */
    public static function autoFetchForDate(\DateTimeInterface $date, bool $force = false): bool
    {
        $ymd     = $date->format('Y-m-d');
        $dateStr = $date->format('mdy');           // MMDDYY — USCCB URL format
        $url     = 'https://bible.usccb.org/bible/readings/' . $dateStr . '.cfm';

        $html = self::httpGet($url);
        if (!$html || strlen($html) < 500) return false;

        $title = self::parseUSCCBTitle($html);
        $refs  = self::parseUSCCBRefs($html);
        $texts = self::parseUSCCBTexts($html);   // passage text from same page

        if (empty($refs['gospel'])) return false;

        $info = self::liturgicalDay($date);

        // Fetch the existing row (all columns) so we can fall back to its refs
        // when the fresh parse returns null for a slot — avoids overwriting good data.
        $existingRow = Database::fetch(
            "SELECT * FROM lectionary_readings WHERE date_override = ?", [$ymd]
        );

        // CPDV (local DB) is tried first for each reading; falls back to USCCB HTML text.
        $cpdv = fn(?string $ref, ?string $scraped): ?string =>
            ($ref ? (self::lookupCPDV($ref) ?? $scraped) : $scraped);

        // For refs: use freshly parsed value when non-null; otherwise keep existing DB value.
        // This prevents a parse failure from wiping out a ref that was correctly stored before.
        $keepRef = fn(?string $fresh, string $col): ?string =>
            $fresh ?? ($existingRow[$col] ?? null);

        $data = [
            'lookup_key'       => "DATE-{$ymd}",
            'date_override'    => $ymd,
            'liturgical_title' => $title ?: ($existingRow['liturgical_title'] ?? $info['title']),
            'reading1_ref'     => $keepRef($refs['reading1'] ?? null, 'reading1_ref'),
            'reading1_text'    => $cpdv($refs['reading1'] ?? null, $texts['reading1'] ?? null),
            'psalm_ref'        => $keepRef($refs['psalm']    ?? null, 'psalm_ref'),
            'psalm_text'       => $cpdv($refs['psalm']    ?? null, $texts['psalm']    ?? null),
            'reading2_ref'     => $keepRef($refs['reading2'] ?? null, 'reading2_ref'),
            'reading2_text'    => $cpdv($refs['reading2'] ?? null, $texts['reading2'] ?? null),
            'gospel_ref'       => $keepRef($refs['gospel']   ?? null, 'gospel_ref'),
            'gospel_text'      => $cpdv($refs['gospel']   ?? null, $texts['gospel']   ?? null),
            'notes'            => $existingRow['notes'] ?? null,
        ];

        try {
            if ($existingRow) {
                if (!$force) {
                    // Preserve manually-pasted text: only overwrite _text columns when
                    // they are still NULL (i.e. the entry was from an earlier fetch that
                    // didn't capture text yet). Force-refresh bypasses this check.
                    $hasManual = Database::fetch(
                        "SELECT id FROM lectionary_readings
                         WHERE id = ? AND (reading1_text IS NOT NULL
                                        OR psalm_text    IS NOT NULL
                                        OR gospel_text   IS NOT NULL)",
                        [$existingRow['id']]
                    );
                    if ($hasManual) {
                        unset($data['reading1_text'], $data['psalm_text'],
                              $data['reading2_text'], $data['gospel_text']);
                    }
                }
                Database::update('lectionary_readings', $data, 'id = ?', [$existingRow['id']]);
            } else {
                Database::insert('lectionary_readings', $data);
            }
        } catch (\Throwable $e) {
            // Text columns may not exist yet (migration 0027 pending) — retry without them
            unset($data['reading1_text'], $data['psalm_text'],
                  $data['reading2_text'], $data['gospel_text']);
            if ($existingRow) {
                Database::update('lectionary_readings', $data, 'id = ?', [$existingRow['id']]);
            } else {
                Database::insert('lectionary_readings', $data);
            }
        }

        return true;
    }

    /**
     * Bulk auto-fetch for a range of dates.
     * Always re-fetches (refreshes references + text), skipping only entries that
     * already have gospel text stored (manual entries are detected separately).
     * Returns [fetched => N, failed => N].
     */
    public static function autoFetchRange(\DateTimeInterface $start, int $days): array
    {
        $fetched = 0;
        $failed  = 0;
        $d       = \DateTimeImmutable::createFromInterface($start);

        for ($i = 0; $i < $days; $i++) {
            $ymd = $d->format('Y-m-d');

            // Skip only if gospel_text is already populated (avoid hammering USCCB)
            $already = Database::fetch(
                "SELECT id FROM lectionary_readings
                 WHERE date_override = ? AND gospel_text IS NOT NULL",
                [$ymd]
            );
            if ($already) { $fetched++; $d = $d->modify('+1 day'); continue; }

            if (self::autoFetchForDate($d)) {
                $fetched++;
            } else {
                $failed++;
            }
            // Small delay to be polite to USCCB servers
            usleep(300000); // 0.3 s
            $d = $d->modify('+1 day');
        }

        return ['fetched' => $fetched, 'failed' => $failed];
    }

    /** Extract the liturgical day title from USCCB HTML. */
    private static function parseUSCCBTitle(string $html): string
    {
        // Try <h1> first
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
            $t = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($t && strlen($t) > 3 && strlen($t) < 150) return $t;
        }

        // Try a heading with class containing "day" or "title"
        if (preg_match('/<[^>]+class="[^"]*(?:day|reading-heading|title)[^"]*"[^>]*>(.*?)<\/[^>]+>/is', $html, $m)) {
            $t = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($t && strlen($t) > 3 && strlen($t) < 150) return $t;
        }

        // Fall back to <title> tag, stripping site name suffix
        if (preg_match('/<title[^>]*>([^<]+)/i', $html, $m)) {
            $t = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            // USCCB format: "Seventh Sunday of Easter | USCCB" — take part before |
            foreach (['|', '–', '-', '·'] as $sep) {
                if (str_contains($t, $sep)) {
                    $t = trim(explode($sep, $t)[0]);
                    break;
                }
            }
            if ($t && strlen($t) > 3 && strlen($t) < 150) return $t;
        }

        return '';
    }

    /**
     * Parse USCCB daily readings page HTML and extract Scripture references
     * for First Reading, Psalm, Second Reading, and Gospel.
     *
     * Returns associative array: ['reading1' => '...', 'psalm' => '...', ...]
     */
    private static function parseUSCCBRefs(string $html): array
    {
        // Convert to clean plain text, preserving section boundaries
        $text = preg_replace('/<(script|style)[^>]*>.*?<\/(script|style)>/is', '', $html);
        $text = preg_replace('/<\/?(div|p|h[1-6]|li|br|section|article|header|tr|td)[^>]*>/i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", trim($text));

        // Scripture reference pattern:
        // Handles "Is 42:1-4, 6-7" · "1 Cor 13:1-13" · "Ps 23:1-3, 5" · "Jn 3:16"
        // Book name: optional leading digit, uppercase letter, then letters/period
        $refPat = '/\b((?:\d\s+)?[A-Z][a-zA-Z]+\.?)\s+(\d+:\d+(?:[a-z]?)(?:[\d,;\s:a-z\-]*)?)/';

        // Section headers to search for, in priority order
        $sections = [
            'reading1' => ['Reading I', 'First Reading', 'Reading 1', 'READING I'],
            'psalm'    => ['Responsorial Psalm', 'Psalm Response', 'RESPONSORIAL PSALM'],
            'reading2' => ['Reading II', 'Second Reading', 'Reading 2', 'READING II'],
        ];

        $refs    = [];
        $textLen = strlen($text);

        foreach ($sections as $key => $headers) {
            foreach ($headers as $header) {
                $pos = stripos($text, $header);
                if ($pos === false) continue;

                // Look for a reference within the 500 chars after this header
                $end     = min($pos + strlen($header) + 500, $textLen);
                $segment = substr($text, $pos + strlen($header), $end - $pos - strlen($header));

                if (preg_match($refPat, $segment, $m)) {
                    // Clean trailing punctuation and stray letters
                    $ref = rtrim(trim($m[1] . ' ' . $m[2]), "., \t\n\r");
                    // Sanity check: must contain a colon (chapter:verse)
                    if (str_contains($ref, ':')) {
                        $refs[$key] = $ref;
                        break;
                    }
                }
            }
        }

        // Gospel: use a negative lookahead to avoid matching "Gospel Acclamation"
        // (the Alleluia verse that precedes the Gospel on the USCCB page)
        if (preg_match('/\bGospel(?!\s+Acclamation)\b/i', $text, $gm, PREG_OFFSET_CAPTURE)) {
            $gospelStart = $gm[0][1] + strlen($gm[0][0]);
            $segment     = substr($text, $gospelStart, min(500, $textLen - $gospelStart));
            if (preg_match($refPat, $segment, $m)) {
                $ref = rtrim(trim($m[1] . ' ' . $m[2]), "., \t\n\r");
                if (str_contains($ref, ':')) {
                    $refs['gospel'] = $ref;
                }
            }
        }

        return $refs;
    }

    /**
     * Parse the full reading text for each section from USCCB daily readings HTML.
     * The USCCB page contains the complete NABRE text of every reading.
     * Returns ['reading1' => '...', 'psalm' => '...', 'reading2' => '...', 'gospel' => '...'].
     */
    private static function parseUSCCBTexts(string $html): array
    {
        // ── 1. Convert HTML to paragraph-separated plain text ────────────────
        $text = preg_replace('/<(script|style)[^>]*>.*?<\/(script|style)>/is', '', $html);
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/?(p|div|h[1-6]|li|tr|blockquote|section|article)[^>]*>/i', "\n\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Strip inline verse-number markers (NABRE uses superscript Unicode or bracketed digits)
        $text = preg_replace('/[\x{00B9}\x{00B2}\x{00B3}\x{2070}-\x{2079}]+/u', '', $text);
        $text = preg_replace('/\[\s*\d+\s*\]/', '', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", trim($text));

        $textLen = strlen($text);

        // ── 2. Locate section start positions ────────────────────────────────
        // Keep section-start headers and end-of-gospel stop markers completely separate.
        // "Alleluia" / "Gospel Acclamation" are intentionally NOT in section headers:
        // they appear in Easter psalm refrains and before the Gospel, and would corrupt
        // the section-boundary logic if used as position anchors.
        $positions = [];

        foreach (['Reading I', 'First Reading', 'Reading 1'] as $hdr) {
            $pos = stripos($text, $hdr);
            if ($pos !== false) { $positions['reading1'] = $pos; break; }
        }
        foreach (['Responsorial Psalm', 'Psalm Response', 'RESPONSORIAL PSALM'] as $hdr) {
            $pos = stripos($text, $hdr);
            if ($pos !== false) { $positions['psalm'] = $pos; break; }
        }
        foreach (['Reading II', 'Second Reading', 'Reading 2'] as $hdr) {
            $pos = stripos($text, $hdr);
            if ($pos !== false) { $positions['reading2'] = $pos; break; }
        }

        // Gospel: must NOT match "Gospel Acclamation" (the Alleluia verse before the Gospel).
        // Use a negative lookahead so we find the actual Gospel heading.
        if (preg_match('/\bGospel(?!\s+Acclamation)\b/i', $text, $gm, PREG_OFFSET_CAPTURE)) {
            $positions['gospel'] = $gm[0][1];
        }

        asort($positions);
        $orderedKeys = array_keys($positions);

        // ── 3. Stop markers — used ONLY to end the last section (Gospel) ─────
        // These phrases appear after the Gospel text in USCCB page HTML.
        $endMarkers = [
            'Homily Notes', 'About This Resource', 'About this resource',
            'Copyright', 'United States Conference', 'usccb.org',
            'New American Bible', 'Lectionary for Mass',
            'Scripture texts', 'Confraternity of Christian Doctrine',
        ];

        // ── 4. Extract and clean text for each reading section ───────────────
        $texts = [];

        for ($i = 0; $i < count($orderedKeys); $i++) {
            $key   = $orderedKeys[$i];
            $start = $positions[$key];

            // End = start of next reading section; for the last section, scan for
            // an end marker that appears strictly AFTER this section's start position.
            if ($i + 1 < count($orderedKeys)) {
                $end = $positions[$orderedKeys[$i + 1]];
            } else {
                $end = $textLen;
                foreach ($endMarkers as $marker) {
                    $mpos = stripos($text, $marker, $start + 200);
                    if ($mpos !== false && $mpos < $end) {
                        $end = $mpos;
                    }
                }
            }

            $section = substr($text, $start, $end - $start);

            // Split into non-empty lines
            $lines = array_values(array_filter(
                explode("\n", $section),
                fn($l) => trim($l) !== ''
            ));

            // Skip the section header line and the scripture reference line
            $bodyStart  = 0;
            $headerDone = false;
            $refDone    = false;

            foreach ($lines as $idx => $line) {
                $t = trim($line);
                if (!$headerDone) {
                    $headerDone = true;
                    $bodyStart  = $idx + 1;
                    continue;
                }
                if (!$refDone && preg_match('/\d+:\d+/', $t)) {
                    $refDone   = true;
                    $bodyStart = $idx + 1;
                    continue;
                }
                break;
            }

            $bodyLines = array_slice($lines, $bodyStart);

            // Remove noise lines: lone verse numbers, copyright, boilerplate
            $bodyLines = array_filter($bodyLines, function (string $line): bool {
                $l = trim($line);
                if ($l === '') return false;
                if (preg_match('/^\d+$/', $l)) return false;
                if (mb_strlen($l) < 4) return false;
                if (stripos($l, 'copyright') !== false) return false;
                if (stripos($l, 'United States Conference') !== false) return false;
                if (stripos($l, 'New American Bible') !== false) return false;
                if (stripos($l, 'usccb.org') !== false) return false;
                if (stripos($l, 'Lectionary for Mass') !== false) return false;
                return true;
            });

            $body = trim(implode("\n", $bodyLines));

            if (strlen($body) > 30) {
                $texts[$key] = $body;
            }
        }

        return $texts;
    }

    // ── Reference conversion ─────────────────────────────────────────────────

    // ── CPDV local Bible text ────────────────────────────────────────────────

    /**
     * Import cpdv.json into the cpdv_verses table.
     * Expects the file at $filePath to be the JSON structure:
     *   { "BookName": { "chapter": { "verse": "text" } } }
     *
     * Returns ['imported' => N, 'skipped' => N] or ['error' => 'message'].
     */
    public static function importCPDV(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return ['error' => 'File not found: ' . $filePath];
        }

        $json = file_get_contents($filePath);
        if (!$json) return ['error' => 'Could not read the file.'];

        $data = json_decode($json, true);
        if (!is_array($data)) return ['error' => 'Invalid JSON in file.'];

        // Map from CPDV JSON book names to OSIS codes
        $nameMap = [
            'Genesis'          => 'GEN', 'Exodus'           => 'EXO',
            'Leviticus'        => 'LEV', 'Numbers'          => 'NUM',
            'Deuteronomy'      => 'DEU', 'Joshua'           => 'JOS',
            'Judges'           => 'JDG', 'Ruth'             => 'RUT',
            '1 Samuel'         => '1SA', '2 Samuel'         => '2SA',
            '1 Kings'          => '1KI', '2 Kings'          => '2KI',
            '1 Chronicles'     => '1CH', '2 Chronicles'     => '2CH',
            'Ezra'             => 'EZR', 'Nehemiah'         => 'NEH',
            'Tobit'            => 'TOB', 'Judith'           => 'JDT',
            'Esther'           => 'EST', 'Job'              => 'JOB',
            'Psalms'           => 'PSA', 'Proverbs'         => 'PRO',
            'Ecclesiastes'     => 'ECC', 'Song of Solomon'  => 'SNG',
            'Wisdom'           => 'WIS', 'Sirach'           => 'SIR',
            'Isaiah'           => 'ISA', 'Jeremiah'         => 'JER',
            'Lamentations'     => 'LAM', 'Baruch'           => 'BAR',
            'Ezekiel'          => 'EZK', 'Daniel'           => 'DAN',
            'Hosea'            => 'HOS', 'Joel'             => 'JOL',
            'Amos'             => 'AMO', 'Obadiah'          => 'OBA',
            'Jonah'            => 'JON', 'Micah'            => 'MIC',
            'Nahum'            => 'NAM', 'Habakkuk'         => 'HAB',
            'Zephaniah'        => 'ZEP', 'Haggai'           => 'HAG',
            'Zechariah'        => 'ZEC', 'Malachi'          => 'MAL',
            '1 Maccabees'      => '1MA', '2 Maccabees'      => '2MA',
            'Matthew'          => 'MAT', 'Mark'             => 'MRK',
            'Luke'             => 'LUK', 'John'             => 'JHN',
            'Acts'             => 'ACT', 'Romans'           => 'ROM',
            '1 Corinthians'    => '1CO', '2 Corinthians'    => '2CO',
            'Galatians'        => 'GAL', 'Ephesians'        => 'EPH',
            'Philippians'      => 'PHP', 'Colossians'       => 'COL',
            '1 Thessalonians'  => '1TH', '2 Thessalonians'  => '2TH',
            '1 Timothy'        => '1TI', '2 Timothy'        => '2TI',
            'Titus'            => 'TIT', 'Philemon'         => 'PHM',
            'Hebrews'          => 'HEB', 'James'            => 'JAS',
            '1 Peter'          => '1PE', '2 Peter'          => '2PE',
            '1 John'           => '1JN', '2 John'           => '2JN',
            '3 John'           => '3JN', 'Jude'             => 'JUD',
            'Revelation'       => 'REV',
        ];

        Database::query("TRUNCATE TABLE cpdv_verses");

        $imported  = 0;
        $skipped   = 0;
        $batch     = [];
        $batchSize = 500;

        $flush = function () use (&$batch, &$imported): void {
            if (empty($batch)) return;
            $ph = implode(',', array_fill(0, count($batch), '(?,?,?,?)'));
            Database::query(
                "INSERT INTO cpdv_verses (book, chapter, verse, text) VALUES {$ph}",
                array_merge(...$batch)
            );
            $imported += count($batch);
            $batch = [];
        };

        foreach ($data as $bookName => $chapters) {
            $osis = $nameMap[$bookName] ?? null;
            if (!$osis) { $skipped++; continue; }

            foreach ($chapters as $chStr => $verses) {
                $ch = (int) $chStr;
                foreach ($verses as $vStr => $text) {
                    $batch[] = [$osis, $ch, (int) $vStr, (string) $text];
                    if (count($batch) >= $batchSize) $flush();
                }
            }
        }
        $flush();

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Look up a Scripture passage from the local CPDV table.
     *
     * Accepts the same human-readable reference format used throughout the
     * lectionary (e.g. "Is 42:1-4, 6-7", "Acts 22:30; 23:6-11", "Ps 23:1-6").
     * Returns the assembled verse text, or null if the table is empty or the
     * reference cannot be resolved.
     */
    public static function lookupCPDV(string $humanRef): ?string
    {
        $humanRef = trim($humanRef);
        if (!$humanRef) return null;

        // Normalize semicolons to commas
        $humanRef = str_replace(';', ',', $humanRef);

        // Extract book abbreviation and verse remainder
        if (!preg_match('/^(\d\s+)?([A-Za-z]+\.?)\s+(.+)$/u', $humanRef, $m)) return null;
        $bookRaw   = trim($m[1] . $m[2]);
        $remainder = trim($m[3]);

        // Map abbreviation → OSIS (same table used by toApiRef)
        $books = self::bookAbbrevToOsis();
        $osis  = null;
        foreach ($books as $abbr => $id) {
            if (strcasecmp($bookRaw, $abbr) === 0) { $osis = $id; break; }
        }
        if (!$osis) return null;

        // Parse comma-separated verse segments, tracking current chapter
        $segments   = array_map('trim', explode(',', $remainder));
        $curChapter = null;
        $ranges     = []; // [[chapter, fromVerse, toVerse], ...]

        foreach ($segments as $seg) {
            $seg = preg_replace('/(\d)[a-d]\b/', '$1', $seg); // strip letter suffixes

            if (preg_match('/^(\d+):(\d+)-(\d+):(\d+)$/', $seg, $r)) {
                // Cross-chapter: 22:30-23:11
                for ($ch = (int)$r[1]; $ch <= (int)$r[3]; $ch++) {
                    $from = ($ch === (int)$r[1]) ? (int)$r[2] : 1;
                    $to   = ($ch === (int)$r[3]) ? (int)$r[4] : 999;
                    $ranges[] = [$ch, $from, $to];
                }
                $curChapter = (int)$r[3];
            } elseif (preg_match('/^(\d+):(\d+)-(\d+)$/', $seg, $r)) {
                $ranges[]   = [(int)$r[1], (int)$r[2], (int)$r[3]];
                $curChapter = (int)$r[1];
            } elseif (preg_match('/^(\d+):(\d+)$/', $seg, $r)) {
                $ranges[]   = [(int)$r[1], (int)$r[2], (int)$r[2]];
                $curChapter = (int)$r[1];
            } elseif ($curChapter && preg_match('/^(\d+)-(\d+):(\d+)$/', $seg, $r)) {
                // Continuation into new chapter: 4-2:3
                $ranges[]   = [$curChapter, (int)$r[1], 999];
                $ranges[]   = [(int)$r[2], 1, (int)$r[3]];
                $curChapter = (int)$r[2];
            } elseif ($curChapter && preg_match('/^(\d+)-(\d+)$/', $seg, $r)) {
                $ranges[]   = [$curChapter, (int)$r[1], (int)$r[2]];
            } elseif ($curChapter && preg_match('/^(\d+)$/', $seg, $r)) {
                $ranges[]   = [$curChapter, (int)$r[1], (int)$r[1]];
            }
        }

        if (empty($ranges)) return null;

        // The CPDV uses Vulgate (Latin) psalm numbering, which differs from the
        // Hebrew (Masoretic) numbering used by the NABRE and the USCCB lectionary.
        // For psalms 11–146 (NABRE): Vulgate number = NABRE number − 1.
        // Special cases (9/10, 114–116, 147) are rare in the lectionary; the −1
        // rule covers the vast majority of Sunday and weekday psalm citations.
        if ($osis === 'PSA') {
            $ranges = array_map(function (array $r): array {
                $ch = $r[0];
                if ($ch >= 11 && $ch <= 146) $ch--;
                return [$ch, $r[1], $r[2]];
            }, $ranges);
        }

        try {
            $lines = [];
            foreach ($ranges as [$ch, $from, $to]) {
                $rows = Database::fetchAll(
                    "SELECT text FROM cpdv_verses
                     WHERE book = ? AND chapter = ? AND verse BETWEEN ? AND ?
                     ORDER BY verse",
                    [$osis, $ch, $from, $to]
                );
                foreach ($rows as $row) {
                    $lines[] = trim($row['text']);
                }
            }
            return $lines ? implode("\n", $lines) : null;
        } catch (\Throwable $e) {
            // Table may not exist yet (migration 0028 pending)
            return null;
        }
    }

    // ── Reference conversion ─────────────────────────────────────────────────

    /**
     * Convert a human-readable Catholic lectionary reference
     * (e.g. "Is 42:1-4, 6-7") to api.bible passage ID format
     * (e.g. "ISA.42.1-ISA.42.4,ISA.42.6-ISA.42.7").
     *
     * Handles:
     *   - Single verse:          "Gen 1:1"
     *   - Same-chapter range:    "Gen 1:1-3"
     *   - Cross-chapter range:   "Gen 1:1-2:3"
     *   - Comma-separated parts: "Is 42:1-4, 6-7" or "Ps 23:1-3, 5"
     *   - Letter suffixes (abc): "Mt 5:1a-12b" → stripped
     */
    public static function toApiRef(string $humanRef): string
    {
        $books = self::bookAbbrevToOsis();

        $ref = trim($humanRef);

        // Extract book name and chapter:verse remainder
        // Handles prefixes like "1 ", "2 ", "3 " for numbered books
        if (!preg_match('/^(\d\s+)?([A-Za-z]+\.?)\s+(.+)$/u', $ref, $m)) {
            return $ref;
        }
        $bookRaw   = trim($m[1] . $m[2]);
        $remainder = trim($m[3]);

        // Look up OSIS ID (longest-match first via key order, case-insensitive)
        $osis = null;
        foreach ($books as $abbr => $id) {
            if (strcasecmp($bookRaw, $abbr) === 0) { $osis = $id; break; }
        }
        if (!$osis) return $ref;

        // Parse comma-separated segments; track current chapter across continuations
        $segments     = preg_split('/,\s*/', $remainder);
        $apiParts     = [];
        $curChapter   = null;

        foreach ($segments as $seg) {
            $seg = trim($seg);
            // Strip letter suffixes (a, b, c) from verse numbers for API
            $seg = preg_replace('/(\d)[a-d]\b/', '$1', $seg);

            if (preg_match('/^(\d+):(\d+)-(\d+):(\d+)$/', $seg, $r)) {
                // Cross-chapter: 1:1-2:3
                $apiParts[]   = "{$osis}.{$r[1]}.{$r[2]}-{$osis}.{$r[3]}.{$r[4]}";
                $curChapter   = $r[3];
            } elseif (preg_match('/^(\d+):(\d+)-(\d+)$/', $seg, $r)) {
                // Same-chapter range: 1:1-3
                $apiParts[]   = "{$osis}.{$r[1]}.{$r[2]}-{$osis}.{$r[1]}.{$r[3]}";
                $curChapter   = $r[1];
            } elseif (preg_match('/^(\d+):(\d+)$/', $seg, $r)) {
                // Single verse: 1:1
                $apiParts[]   = "{$osis}.{$r[1]}.{$r[2]}";
                $curChapter   = $r[1];
            } elseif ($curChapter && preg_match('/^(\d+)-(\d+):(\d+)$/', $seg, $r)) {
                // Continuation into new chapter: 5-2:3
                $apiParts[]   = "{$osis}.{$curChapter}.{$r[1]}-{$osis}.{$r[2]}.{$r[3]}";
                $curChapter   = $r[2];
            } elseif ($curChapter && preg_match('/^(\d+)-(\d+)$/', $seg, $r)) {
                // Continuation in same chapter: 5-7
                $apiParts[]   = "{$osis}.{$curChapter}.{$r[1]}-{$osis}.{$curChapter}.{$r[2]}";
            } elseif ($curChapter && preg_match('/^(\d+)$/', $seg, $r)) {
                // Single verse continuation: just a number
                $apiParts[]   = "{$osis}.{$curChapter}.{$r[1]}";
            }
        }

        return $apiParts ? implode(',', $apiParts) : $ref;
    }

    // ── Bible text fetching ──────────────────────────────────────────────────

    /**
     * Fetch passage text from bible-api.com (free, no key required).
     * Default translation: drb (Douay-Rheims Bible — traditional Catholic).
     * Results are cached in readings_cache for 30 days.
     *
     * $humanRef — human-readable reference, e.g. "Is 42:1-7" or "Jn 3:16-21"
     * Returns passage text string or null on failure.
     */
    public static function fetchPassage(string $humanRef): ?string
    {
        $humanRef = trim($humanRef);
        if (!$humanRef) return null;

        $translation = setting('readings_translation', 'drb');
        $cacheKey    = md5($translation . '|' . strtolower($humanRef));

        // Check cache first
        $cached = Database::fetch(
            "SELECT passage_text FROM readings_cache WHERE cache_key = ? AND expires_at > NOW()",
            [$cacheKey]
        );
        if ($cached) return $cached['passage_text'];

        // Normalise commas/semicolons to a single fetchable span.
        $fetchRef = self::spanRef($humanRef);

        // bible-api.com needs spaces encoded as + but colons, hyphens, and commas left literal.
        $encodedRef = str_replace(' ', '+', $fetchRef);
        $url        = 'https://bible-api.com/' . $encodedRef
                    . '?translation=' . rawurlencode($translation);

        // Prefer cURL (works even when allow_url_fopen is disabled on the server).
        $json = self::httpGet($url);
        if (!$json) return null;

        $data = json_decode($json, true);
        if (!is_array($data) || !empty($data['error'])) return null;

        // Build text with bracketed verse numbers.
        if (!empty($data['verses'])) {
            $lines = array_map(
                fn($v) => '[' . $v['verse'] . '] ' . trim($v['text']),
                $data['verses']
            );
            $text = implode(' ', $lines);
        } elseif (!empty($data['text'])) {
            $text = trim($data['text']);
        } else {
            return null;
        }

        if (!$text) return null;

        // Cache for 30 days (scripture text is immutable).
        $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
        Database::query(
            "INSERT INTO readings_cache (cache_key, passage_text, expires_at)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE passage_text = VALUES(passage_text),
                                     fetched_at   = NOW(),
                                     expires_at   = VALUES(expires_at)",
            [$cacheKey, $text, $expires]
        );

        return $text;
    }

    /**
     * Minimal HTTP GET helper.
     * Uses cURL when available (works even if allow_url_fopen = Off),
     * falls back to file_get_contents for environments without cURL.
     */
    private static function httpGet(string $url): string|false
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'User-Agent: ParishCMS/1.0',
                ],
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body  = curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            return ($errno === 0 && $body !== false && $body !== '') ? $body : false;
        }

        // Fallback: file_get_contents (requires allow_url_fopen = On)
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "Accept: application/json\r\nUser-Agent: ParishCMS/1.0\r\n",
            'timeout'       => 10,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        return ($body !== false && $body !== '') ? $body : false;
    }

    /**
     * Normalise a Scripture reference for bible-api.com:
     *
     * Same-chapter non-contiguous ranges → single span
     *   "Is 42:1-4, 6-7"       → "Is 42:1-7"
     *   "Ps 23:1-3, 5"         → "Ps 23:1-5"
     *
     * Semicolons (USCCB style) treated same as commas:
     *   "Acts 22:30; 23:6-11"  → first segment only ("Acts 22:30")
     *
     * Cross-chapter segments after comma/semicolon → first segment only
     * (bible-api.com handles single contiguous ranges best):
     *   "Nm 6:22-27, Ps 67"    → "Nm 6:22-27"
     */
    private static function spanRef(string $ref): string
    {
        // Normalise semicolons to commas
        $ref = str_replace(';', ',', $ref);

        if (!str_contains($ref, ',')) return $ref;

        $segments = array_map('trim', explode(',', $ref));
        $first    = $segments[0];

        // If any continuation segment contains a colon it introduces a new chapter/book.
        // Return only the first contiguous segment — bible-api.com handles it cleanly.
        foreach (array_slice($segments, 1) as $seg) {
            if (str_contains($seg, ':')) {
                return $first;
            }
        }

        // All continuations are verse-only (same chapter) — build a span.
        // "Is 42:1-4" + ["6-7"] → "Is 42:1-7"
        if (!preg_match('/^(.*?\d+):(.+)$/', $first, $m)) return $first;
        $prefix     = $m[1]; // "Is 42"
        $versesPart = $m[2]; // "1-4"

        $allText = $versesPart . ',' . implode(',', array_slice($segments, 1));
        preg_match_all('/\d+/', $allText, $nums);
        if (empty($nums[0])) return $first;

        $firstVerse = $nums[0][0];
        $lastVerse  = end($nums[0]);
        return "{$prefix}:{$firstVerse}-{$lastVerse}";
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function make(
        string $season, int $week, int $dow,
        string $sc, string $wc, string $title,
        string $lk, string $lkw
    ): array {
        return [
            'season'        => $season,
            'week'          => $week,
            'dow'           => $dow,
            'sunday_cycle'  => $sc,
            'weekday_cycle' => $wc,
            'title'         => $title,
            'lookup_key'    => $lk,
            'lookup_key_w'  => $lkw,
        ];
    }

    private static function christmasInfo(
        \DateTimeImmutable $d, \DateTimeImmutable $xmas,
        string $sc, string $wc, int $dow, string $ymd, int $year
    ): array {
        $diff = (int) $xmas->diff($d)->days;
        if ($diff === 0) $title = 'Christmas Day (Nativity of the Lord)';
        elseif ($diff < 8) $title = $diff === 6 && $dow === 0
            ? 'Feast of the Holy Family'
            : 'Christmas Octave — Day ' . ($diff + 1);
        elseif ($dow === 0) $title = 'Christmas Season — Sunday';
        else $title = 'Christmas Season — Day ' . ($diff + 1);
        return self::make('christmas', 1, $dow, $sc, $wc, $title, "DATE-{$ymd}", "S-christmas-1-{$sc}");
    }

    /** Book abbreviation → OSIS code map (shared by toApiRef and lookupCPDV). */
    private static function bookAbbrevToOsis(): array
    {
        static $books = [
            'Gn' => 'GEN',   'Gen' => 'GEN',   'Genesis' => 'GEN',
            'Ex' => 'EXO',   'Exod' => 'EXO',  'Exodus' => 'EXO',
            'Lv' => 'LEV',   'Lev' => 'LEV',   'Leviticus' => 'LEV',
            'Nm' => 'NUM',   'Num' => 'NUM',   'Numbers' => 'NUM',
            'Dt' => 'DEU',   'Deut' => 'DEU',  'Deuteronomy' => 'DEU',
            'Jos' => 'JOS',  'Josh' => 'JOS',  'Joshua' => 'JOS',
            'Jgs' => 'JDG',  'Judg' => 'JDG',  'Judges' => 'JDG',
            'Ru' => 'RUT',   'Ruth' => 'RUT',
            '1 Sm' => '1SA', '1 Sam' => '1SA', '1Sam' => '1SA',
            '2 Sm' => '2SA', '2 Sam' => '2SA', '2Sam' => '2SA',
            '1 Kgs' => '1KI','1Kgs' => '1KI',  '1 Kings' => '1KI',
            '2 Kgs' => '2KI','2Kgs' => '2KI',  '2 Kings' => '2KI',
            '1 Chr' => '1CH','1Chr' => '1CH',  '1 Chron' => '1CH',
            '2 Chr' => '2CH','2Chr' => '2CH',  '2 Chron' => '2CH',
            'Ezr' => 'EZR',  'Ezra' => 'EZR',
            'Neh' => 'NEH',  'Nehemiah' => 'NEH',
            'Tob' => 'TOB',  'Tobit' => 'TOB',
            'Jdt' => 'JDT',  'Judith' => 'JDT',
            'Est' => 'EST',  'Esth' => 'EST',   'Esther' => 'EST',
            '1 Mc' => '1MA', '1 Macc' => '1MA', '1Macc' => '1MA',
            '2 Mc' => '2MA', '2 Macc' => '2MA', '2Macc' => '2MA',
            'Jb' => 'JOB',   'Job' => 'JOB',
            'Ps' => 'PSA',   'Pss' => 'PSA',   'Psalm' => 'PSA',  'Psalms' => 'PSA',
            'Prv' => 'PRO',  'Prov' => 'PRO',  'Proverbs' => 'PRO',
            'Eccl' => 'ECC', 'Qoh' => 'ECC',   'Ecclesiastes' => 'ECC',
            'Sg' => 'SNG',   'Song' => 'SNG',  'Cant' => 'SNG',
            'Wis' => 'WIS',  'Wisdom' => 'WIS',
            'Sir' => 'SIR',  'Sirach' => 'SIR','Ecclus' => 'SIR',
            'Is' => 'ISA',   'Isa' => 'ISA',   'Isaiah' => 'ISA',
            'Jer' => 'JER',  'Jeremiah' => 'JER',
            'Lam' => 'LAM',  'Lamentations' => 'LAM',
            'Bar' => 'BAR',  'Baruch' => 'BAR',
            'Ez' => 'EZK',   'Ezek' => 'EZK',  'Ezekiel' => 'EZK',
            'Dn' => 'DAN',   'Dan' => 'DAN',   'Daniel' => 'DAN',
            'Hos' => 'HOS',  'Hosea' => 'HOS',
            'Jl' => 'JOL',   'Joel' => 'JOL',
            'Am' => 'AMO',   'Amos' => 'AMO',
            'Ob' => 'OBA',   'Obad' => 'OBA',  'Obadiah' => 'OBA',
            'Jon' => 'JON',  'Jonah' => 'JON',
            'Mi' => 'MIC',   'Mic' => 'MIC',   'Micah' => 'MIC',
            'Na' => 'NAM',   'Nah' => 'NAM',   'Nahum' => 'NAM',
            'Hab' => 'HAB',  'Habakkuk' => 'HAB',
            'Zep' => 'ZEP',  'Zeph' => 'ZEP',  'Zephaniah' => 'ZEP',
            'Hag' => 'HAG',  'Haggai' => 'HAG',
            'Zec' => 'ZEC',  'Zech' => 'ZEC',  'Zechariah' => 'ZEC',
            'Mal' => 'MAL',  'Malachi' => 'MAL',
            'Mt' => 'MAT',   'Matt' => 'MAT',  'Matthew' => 'MAT',
            'Mk' => 'MRK',   'Mark' => 'MRK',
            'Lk' => 'LUK',   'Luke' => 'LUK',
            'Jn' => 'JHN',   'John' => 'JHN',
            'Acts' => 'ACT',
            'Rom' => 'ROM',  'Romans' => 'ROM',
            '1 Cor' => '1CO','1Cor' => '1CO',  '1 Corinthians' => '1CO',
            '2 Cor' => '2CO','2Cor' => '2CO',  '2 Corinthians' => '2CO',
            'Gal' => 'GAL',  'Galatians' => 'GAL',
            'Eph' => 'EPH',  'Ephesians' => 'EPH',
            'Phil' => 'PHP', 'Philippians' => 'PHP',
            'Col' => 'COL',  'Colossians' => 'COL',
            '1 Thes' => '1TH','1 Thess' => '1TH','1Thess' => '1TH',
            '2 Thes' => '2TH','2 Thess' => '2TH','2Thess' => '2TH',
            '1 Tm' => '1TI', '1 Tim' => '1TI', '1Tim' => '1TI',
            '2 Tm' => '2TI', '2 Tim' => '2TI', '2Tim' => '2TI',
            'Ti' => 'TIT',   'Titus' => 'TIT',
            'Phlm' => 'PHM', 'Philem' => 'PHM','Philemon' => 'PHM',
            'Heb' => 'HEB',  'Hebrews' => 'HEB',
            'Jas' => 'JAS',  'James' => 'JAS',
            '1 Pt' => '1PE', '1 Pet' => '1PE', '1Pet' => '1PE',
            '2 Pt' => '2PE', '2 Pet' => '2PE', '2Pet' => '2PE',
            '1 Jn' => '1JN', '1Jn' => '1JN',
            '2 Jn' => '2JN', '2Jn' => '2JN',
            '3 Jn' => '3JN', '3Jn' => '3JN',
            'Jude' => 'JUD', 'Jud' => 'JUD',
            'Rv' => 'REV',   'Rev' => 'REV',   'Revelation' => 'REV', 'Apoc' => 'REV',
        ];
        return $books;
    }

    public static function ordinal(int $n): string
    {
        $s = match (true) {
            $n % 100 >= 11 && $n % 100 <= 13 => 'th',
            $n % 10 === 1 => 'st',
            $n % 10 === 2 => 'nd',
            $n % 10 === 3 => 'rd',
            default       => 'th',
        };
        return $n . $s;
    }
}
