<?php
declare(strict_types=1);

namespace Orfeu;

use RuntimeException;

final class StrategyEvidence
{
    public static function validate(string $kind, string $date, string $available, array $payload): void
    {
        $ref = $payload['evidence_ref'] ?? null;
        if (!is_string($ref) || trim($ref) === '' || strlen($ref) > 255) {
            throw new RuntimeException('MISSING_STRATEGY_EVIDENCE');
        }
        if ($kind === 'EARNINGS_RELEASE') {
            $published = timestamp($payload['released_at'] ?? '');
            if ($published > $available || Calendar::brazilDate($published) !== $date) {
                throw new RuntimeException('INVALID_EARNINGS_RELEASE');
            }
            $hasConsensus = array_key_exists('consensus_at',$payload) || array_key_exists('consensus_eps',$payload);
            if ($hasConsensus && (timestamp($payload['consensus_at'] ?? '') >= $published ||
                !self::number($payload['actual_eps'] ?? null) || !self::number($payload['consensus_eps'] ?? null) ||
                abs($payload['consensus_eps']) < .01)) {
                throw new RuntimeException('INVALID_EARNINGS_CONSENSUS');
            }
            return;
        }
        $start = day($payload['period_start'] ?? '');
        $days = (new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($date))->days;
        if ($date > Calendar::brazilDate($available) || $start >= $date || $days < 330 || $days > 400 ||
            !self::number($payload['payout_ratio'] ?? null) || $payload['payout_ratio'] < 0 ||
            !is_int($payload['free_cash_flow_cents'] ?? null) || !is_int($payload['net_distributions_cents'] ?? null) ||
            $payload['net_distributions_cents'] < 0) {
            throw new RuntimeException('INVALID_DIVIDEND_PROFILE');
        }
    }

    private static function number($value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    public static function earnings(array $observations, array $bars, float $cost): ?array
    {
        if (!$observations) {
            return null;
        }
        ksort($observations);
        $release = end($observations);
        $published = timestamp($release['released_at']);
        // Bars are newest first. Locate the first close that could react to the release.
        for ($index = min(4,count($bars)-2); $index >= 0; $index--) {
            if ($bars[$index+1]['close_at'] > $published || $bars[$index]['close_at'] <= $published) {
                continue;
            }
            $reaction = $bars[$index]['close_px'] / $bars[$index+1]['close_px'] - 1;
            $surprise = isset($release['consensus_eps'])
                ? ($release['actual_eps'] - $release['consensus_eps']) / abs($release['consensus_eps']) : null;
            return ['variant'=>$surprise === null ? 'POST_EARNINGS_PRICE_DRIFT' : 'PUBLISHED_EARNINGS_SURPRISE',
                'active'=>$reaction > max(.015,$cost) && ($surprise === null || $surprise > .05)];
        }
        return null;
    }

    public static function dividends(array $observations, array $payments, string $date): ?bool
    {
        if (!$observations) {
            return null;
        }
        ksort($observations);
        $end = array_key_last($observations);
        if ($end < (new \DateTimeImmutable($date))->modify('-180 days')->format('Y-m-d')) {
            return null;
        }
        $profile = $observations[$end];
        $paid = count(array_filter($payments,static function (array $event) use ($profile,$end): bool {
            return $event['payment_date'] >= $profile['period_start'] && $event['payment_date'] <= $end;
        }));
        return $paid >= 2 && $profile['payout_ratio'] <= .8 && $profile['net_distributions_cents'] > 0 &&
            $profile['free_cash_flow_cents'] >= $profile['net_distributions_cents'];
    }

    public static function macro(array $bars, array $fx): ?float
    {
        if (count($bars) < 61) {
            return null;
        }
        $moves = [];
        for ($index = 1; $index < 59; $index++) {
            $session = $bars[$index];
            $previous = $fx[$bars[$index+1]['session_date']] ?? null;
            $base = $fx[$bars[$index+2]['session_date']] ?? null;
            if (min($previous['value'] ?? 0,$base['value'] ?? 0) <= 0 ||
                $previous['available_at'] >= $session['open_at'] || $base['available_at'] >= $session['open_at']) {
                continue;
            }
            $moves[$index] = [log($previous['value']/$base['value']),
                log($session['close_px']/$bars[$index+1]['close_px'])];
        }
        $latest = $fx[$bars[0]['session_date']]['value'] ?? 0;
        $prior = $fx[$bars[1]['session_date']]['value'] ?? 0;
        if (min($latest,$prior) <= 0 || count($moves) < 40) {
            return null;
        }
        $current = log($latest/$prior);
        $x = array_sum(array_column($moves,0)) / count($moves);
        $y = array_sum(array_column($moves,1)) / count($moves);
        $variance = $covariance = 0.0;
        foreach ($moves as [$factor,$stock]) {
            $variance += ($factor-$x) ** 2;
            $covariance += ($factor-$x) * ($stock-$y);
        }
        return $variance > 1e-8 ? max(-3,min(3,$covariance/$variance)) * $current : null;
    }
}
