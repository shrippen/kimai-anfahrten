<?php
// Studio Weber demo world: loads world.json next to this file.
// Rules: today = DEMO_TODAY or the real date, anchor = Monday of today's week,
// date = anchor + offset days, text = value[lang] for {de, en} values.

final class DemoWorld
{
    public array $data;
    public \DateTimeImmutable $today;
    public \DateTimeImmutable $anchor;
    public int $todayOffset;

    /** $today: YYYY-MM-DD, "today" for the real date; null reads DEMO_TODAY. */
    public function __construct(public string $lang = 'de', ?string $path = null, ?string $today = null)
    {
        $this->data = json_decode(file_get_contents($path ?? __DIR__ . '/world.json'), true, 512, JSON_THROW_ON_ERROR);
        $tz = new \DateTimeZone($this->data['timezone']);
        $fixed = $today ?? (getenv('DEMO_TODAY') ?: null);
        $fixed = $fixed === 'today' ? null : $fixed;
        $this->today = new \DateTimeImmutable($fixed ?: 'today', $tz);
        $this->today = $this->today->setTime(0, 0);
        $this->anchor = $this->today->modify('-' . ((int) $this->today->format('N') - 1) . ' days');
        $this->todayOffset = (int) $this->anchor->diff($this->today)->days;
    }

    public function date(int $offset, string $time = '00:00'): \DateTimeImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        return $this->anchor->modify(sprintf('%+d days', $offset))->setTime($h, $m);
    }

    public function t(mixed $value): mixed
    {
        if (is_array($value) && (array_key_exists('de', $value) || array_key_exists('en', $value))) {
            return $value[$this->lang] ?? $value['en'] ?? '';
        }
        return $value;
    }

    /** @return array<string, array> */
    public function byId(string $kind): array
    {
        return array_column($this->data[$kind], null, 'id');
    }

    public static function minutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return $h * 60 + $m;
    }

    /** Timesheets up to now, without rows that overlap a running timer. */
    public function pastTimesheets(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', $this->today->getTimezone());
        $nowMin = (int) $now->format('G') * 60 + (int) $now->format('i');
        $running = array_column($this->data['running'], 'start', 'user');
        $out = [];
        foreach ($this->data['timesheets'] as $row) {
            if ($row['day'] > $this->todayOffset) {
                continue;
            }
            if ($row['day'] === $this->todayOffset) {
                $limit = isset($running[$row['user']]) ? min(self::minutes($running[$row['user']]), $nowMin) : $nowMin;
                if (self::minutes($row['end']) > $limit) {
                    continue;
                }
            }
            $out[] = $row;
        }
        return $out;
    }
}
