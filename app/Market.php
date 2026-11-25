<?php
declare(strict_types=1);

namespace Orfeu;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class Calendar
{
    private $rows;

    public function __construct(array $rows)
    {
        $this->rows = array_column($rows, null, 'session_date');
        ksort($this->rows);
    }

    public static function brazilDate(string $time): string
    {
        return (new DateTimeImmutable($time, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d');
    }

    public static function row(string $date, ?string $open, ?string $end, ?string $close,
        bool $settles, string $source): array
    {
        day($date);
        if ($open !== null || $end !== null || $close !== null) {
            foreach ([$open, $end, $close] as $time) {
                if ($time === null || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time)) {
                    throw new RuntimeException('INVALID_SESSION');
                }
            }
            if (!($open < $end && $end <= $close)) {
                throw new RuntimeException('INVALID_SESSION');
            }
        }
        $convert = static function (?string $time) use ($date): ?string {
            if ($time === null) {
                return null;
            }
            $local = new DateTimeImmutable("$date $time", new DateTimeZone('America/Sao_Paulo'));
            return utc($local->format(DATE_ATOM));
        };
        return [
            'session_date' => $date, 'open_at' => $convert($open), 'continuous_end' => $convert($end),
            'close_at' => $convert($close), 'settles' => (int) $settles, 'source' => $source,
        ];
    }

    public function get(string $date): ?array
    {
        return $this->rows[$date] ?? null;
    }

    public function between(string $first, string $last): array
    {
        return array_values(array_filter($this->rows, static function (array $row) use ($first, $last): bool {
            return $row['session_date'] >= $first && $row['session_date'] <= $last;
        }));
    }

    public function marketState(string $asOf): string
    {
        $row = $this->get(self::brazilDate($asOf));
        if (!$row) {
            return 'CALENDAR_MISSING';
        }
        return $row['open_at'] !== null && $asOf >= $row['open_at'] && $asOf < $row['continuous_end']
            ? 'CONTINUOUS' : 'CLOSED';
    }

    public function next(string $date, int $count = 1, bool $settlement = false): ?array
    {
        if ($count < 1) {
            throw new RuntimeException('INVALID_OFFSET');
        }
        return $this->walk($date, $count, '+1 day', $settlement);
    }

    public function previous(string $date): ?array
    {
        return $this->walk($date, 1, '-1 day', false);
    }

    private function walk(string $date, int $count, string $step, bool $settlement): ?array
    {
        $cursor = new DateTimeImmutable($date, new DateTimeZone('UTC'));
        while (true) {
            $cursor = $cursor->modify($step);
            $row = $this->get($cursor->format('Y-m-d'));
            if (!$row) {
                return null;
            }
            if (($settlement ? $row['settles'] : $row['open_at'] !== null) && --$count === 0) {
                return $row;
            }
        }
    }

    public static function official2026(): array
    {
        $source = 'B3 Calendario 2026 e grades 03/11/2025, 09/03/2026 e 03/11/2026';
        $closed = array_fill_keys(['01-01', '02-16', '02-17', '04-03', '04-21', '05-01', '06-04',
            '09-07', '10-12', '11-02', '11-20', '12-24', '12-25', '12-31'], true);
        $rows = [];
        for ($date = new DateTimeImmutable('2026-01-01'); $date->format('Y') === '2026'; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            $active = (int) $date->format('N') <= 5 && !isset($closed[$date->format('m-d')]);
            $extended = $key < '2026-03-09' || $key >= '2026-11-03';
            $rows[] = self::row($key, $active ? ($key === '2026-02-18' ? '13:00' : '10:00') : null,
                $active ? ($extended ? '17:55' : '16:55') : null,
                $active ? ($extended ? '18:00' : '17:00') : null, $active, $source);
        }
        return $rows;
    }
}

function eligible(array $instrument): bool
{
    return ($instrument['catalog_status'] ?? $instrument['status'] ?? '') === 'ACTIVE' &&
        in_array($instrument['asset_type'] ?? '', ['ON', 'PN'], true) &&
        preg_match('/^BR[A-Z0-9]{9}[0-9]$/D', $instrument['isin'] ?? '') === 1 &&
        !empty($instrument['issuer_id']) && !empty($instrument['sector']) &&
        !empty($instrument['symbol']) && (int) ($instrument['lot_size'] ?? 0) > 0 &&
        (int) ($instrument['tick_cents'] ?? 0) > 0;
}

function validBar(array $bar): bool
{
    return $bar['low_px'] > 0 && $bar['low_px'] <= min($bar['open_px'], $bar['close_px']) &&
        $bar['high_px'] >= max($bar['open_px'], $bar['close_px']) && $bar['volume'] > 0 &&
        $bar['open_at'] < $bar['close_at'] && $bar['close_at'] <= $bar['available_at'];
}
