<?php
declare(strict_types=1);

namespace Orfeu;

use RuntimeException;

final class IntradayReplay
{
    public static function load(string $file, string $sha256, string $symbol, string $isin, array $session,
        int $interval, string $at): array
    {
        if (!in_array($interval,[300,900],true) || !hash_equals($sha256,(string) hash_file('sha256',$file)) ||
            !$session['open_at'] || !$session['continuous_end']) {
            throw new RuntimeException('INVALID_INTRADAY_ARCHIVE');
        }
        $open = strtotime($session['open_at'] . ' UTC');
        $end = strtotime($session['continuous_end'] . ' UTC');
        $bars = [];
        $latestVisible = null;
        $provider = null;
        foreach (csvRows($file) as $row) {
            if (($row['symbol'] ?? null) !== $symbol || ($row['isin'] ?? null) !== $isin ||
                ($row['market'] ?? null) !== '020' || !preg_match('/^[a-zA-Z0-9_.-]{1,40}$/D',$row['provider'] ?? '')) {
                throw new RuntimeException('INVALID_INTRADAY_INSTRUMENT');
            }
            $provider = $provider ?? $row['provider'];
            if ($row['provider'] !== $provider) {
                throw new RuntimeException('MIXED_INTRADAY_PROVIDERS');
            }
            $slot = $open + count($bars)*$interval;
            $start = timestamp($row['event_start'] ?? '');
            $close = timestamp($row['event_end'] ?? '');
            $available = timestamp($row['available_at'] ?? '');
            $received = timestamp($row['received_at'] ?? '');
            if ($start !== utc('@' . $slot) || $close !== utc('@' . ($slot+$interval)) || $slot+$interval > $end) {
                throw new RuntimeException('BLOCKED_INTRADAY_GAPS_OR_OUTSIDE_SESSION');
            }
            if ($available < $close || $available > $received || $received > $at ||
                ($latestVisible !== null && $received <= $latestVisible)) {
                throw new RuntimeException('INVALID_INTRADAY_TIMESTAMPS');
            }
            if ($received > utc($close . ' +120 seconds')) {
                throw new RuntimeException('BLOCKED_STALE_INTRADAY');
            }
            $latestVisible = $received;
            foreach (['open_px'=>100000000,'high_px'=>100000000,'low_px'=>100000000,'close_px'=>100000000,
                'volume'=>1000000000,'turnover_cents'=>100000000000000000] as $field=>$max) {
                $row[$field] = filter_var($row[$field] ?? '',FILTER_VALIDATE_INT,
                    ['options'=>['min_range'=>1,'max_range'=>$max]]);
                if (!$row[$field]) {
                    throw new RuntimeException('INVALID_INTRADAY_NUMBERS');
                }
            }
            $vwap = $row['turnover_cents'] / $row['volume'];
            $bar = ['open_at'=>$start,'close_at'=>$close,'available_at'=>$received,'source_available_at'=>$available,
                'raw_hash'=>hash('sha256',json($row))] + $row;
            if (!validBar($bar) || $vwap < $row['low_px'] || $vwap > $row['high_px']) {
                throw new RuntimeException('INVALID_INTRADAY_BAR');
            }
            $bars[] = $bar;
        }
        if (!$bars || count($bars) !== intdiv($end-$open,$interval)) {
            throw new RuntimeException('BLOCKED_INTRADAY_GAPS_OR_OUTSIDE_SESSION');
        }
        if (!hash_equals($sha256,(string) hash_file('sha256',$file))) {
            throw new RuntimeException('INTRADAY_ARCHIVE_CHANGED');
        }
        return $bars;
    }

    public static function simulate(array $bars, array $session, array $instrument, array $settlement, int $interval): array
    {
        $book = new PaperAccount('intraday:' . $session['session_date'] . ':' . $instrument['id'],PaperAccount::POLICY);
        $book->deposit(100000,$session['open_at']);
        $id = (int) $instrument['id'];
        $continuousEnd = strtotime($session['continuous_end'] . ' UTC');
        $value = $volume = 0;
        $prevAbove = null;
        $prevVolume = 0;
        $decisions = [];
        foreach ($bars as $index=>$bar) {
            $fillSlot = array_replace($session,['open_at'=>$bar['open_at']]);
            $book->execute($fillSlot,[$id=>['020'=>$bar]],[$id=>$instrument],$settlement,false,null,
                $bar['available_at'],false,'INTRADAY_FRACTIONAL_BAR_OPEN_PROXY');
            $value += $bar['turnover_cents'];
            $volume += $bar['volume'];
            $vwap = $value/$volume;
            $above = $bar['close_px'] > $vwap;
            $forceExit = $bar['available_at'] >= utc('@' . ($continuousEnd - 3*$interval));
            $nextSlot = strtotime($bar['close_at'] . ' UTC');
            while (utc('@' . $nextSlot) <= $bar['available_at']) {
                $nextSlot += $interval;
            }
            $entry = !$forceExit && $index >= 5 && $prevAbove === false && $above &&
                $prevVolume > 0 && $bar['volume'] > 1.2*($prevVolume/$index);
            $position = isset($book->state['positions'][$id]);
            if ($nextSlot+$interval <= $continuousEnd && ($entry || $position)) {
                $riskDistance = max(.01,($bar['high_px']-$bar['low_px'])/$bar['close_px']);
                $candidate = array_replace($instrument,['status'=>$position && (!$above || $forceExit) ? 'EXIT_ONLY' : 'TRADABLE',
                    'price'=>$bar['close_px'],'atr'=>$riskDistance,'adv_shares'=>$bar['volume']*10,
                    'intraday_vwap_entry'=>$entry]);
                $target = array_replace($session,['open_at'=>utc('@' . $nextSlot)]);
                [$action,$reason] = $book->decide('VWAP_RECLAIM',$candidate,$book->mark([$id=>$bar['close_px']]),
                    $session,$target,$bar['available_at'],false,null,$bar['open_at']);
                $decisions[] = ['as_of'=>$bar['available_at'],'action'=>$action,'reason'=>$reason,'vwap_cents'=>$vwap];
            }
            $prevAbove = $above;
            $prevVolume += $bar['volume'];
        }
        $totals = array_fill_keys(['cash','reserved','payable','receivable','cost','realized'],0);
        foreach ($book->events as $event) {
            foreach ($event['deltas'] as $field=>$amount) {
                $totals[$field] += $amount;
            }
        }
        if (!PaperAccount::matchesLedger($totals,$book->state)) {
            throw new RuntimeException('BLOCKED_REPLAY_LEDGER_MISMATCH');
        }
        $mark = $book->mark([$id=>end($bars)['close_px']]);
        return ['status'=>'RESEARCH_ONLY','strategy'=>'VWAP_RECLAIM','decisions'=>$decisions,
            'fills'=>array_values($book->fills),'equity'=>$mark,'ledger_reconciled'=>true,
            'session_end_status'=>$book->state['positions'] ? 'OPEN_POSITION_UNRESOLVED' : 'FLAT'];
    }
}
