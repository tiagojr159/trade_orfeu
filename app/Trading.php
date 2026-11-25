<?php
declare(strict_types=1);

namespace Orfeu;

use RuntimeException;

final class Strategies
{
    public const RULE_BASELINES = ['MOMENTUM','RELATIVE_STRENGTH','MEAN_REVERSION','BREAKOUT','LOW_VOL','ADAPTIVE_REGIME',
        'EARNINGS_DRIFT','DIVIDEND_QUALITY','MACRO_ROTATION','META_CHAMPION'];

    public const NAMES = [
        'ORFEU_AI' => 'Ensemble preditivo', 'MULTIHORIZON' => 'Consenso de horizontes',
        'MOMENTUM' => 'Tendência EMA', 'RELATIVE_STRENGTH' => 'Força transversal',
        'MEAN_REVERSION' => 'Reversão à média', 'BREAKOUT' => 'Donchian e volume',
        'VWAP_RECLAIM' => 'Recuperação do VWAP', 'QUALITY_VALUE' => 'Qualidade operacional CVM',
        'EARNINGS_DRIFT' => 'Pós-resultado', 'LOW_VOL' => 'Baixa volatilidade',
        'ADAPTIVE_REGIME' => 'Regime adaptativo', 'DIVIDEND_QUALITY' => 'Dividendos sustentáveis',
        'MACRO_ROTATION' => 'Rotação macro', 'PORTFOLIO_ROTATION' => 'Rotação de carteira',
        'META_CHAMPION' => 'Seleção de sinais',
    ];

    public static function signal(string $key, array $f): string
    {
        $momentum = ($f['trend'] ?? 0) > 0 && ($f['mom20'] ?? 0) > 0 && ($f['volume_ratio'] ?? 0) > 1;
        $reversion = ($f['z20'] ?? 0) < -1.5 && ($f['rsi'] ?? 50) < 35 && ($f['mom60'] ?? -1) > -.10;
        switch ($key) {
            case 'ORFEU_AI':
                $active = $f['p_profitable'] >= .60;
                break;
            case 'MULTIHORIZON':
                if (!isset($f['edge1'], $f['edge20'])) {
                    return 'MISSING_HORIZONS';
                }
                $active = $f['edge1'] > 0 && $f['edge20'] >= 0;
                break;
            case 'MOMENTUM':
                $active = $momentum;
                break;
            case 'RELATIVE_STRENGTH':
                if (!isset($f['relative_rank'])) {
                    return 'MISSING_BENCHMARK';
                }
                $active = ($f['relative_rank'] ?? 0) >= .8 && $f['mom60'] > 0;
                break;
            case 'MEAN_REVERSION':
                $active = $reversion && ($f['vol20'] ?? 1) < .45;
                break;
            case 'BREAKOUT':
                $active = !empty($f['breakout']) && ($f['volume_ratio'] ?? 0) >= 1.3;
                break;
            case 'LOW_VOL':
                $active = ($f['vol20'] ?? 1) <= .25 && ($f['trend'] ?? 0) > 0;
                break;
            case 'ADAPTIVE_REGIME':
                if (!isset($f['regime'])) {
                    return 'MISSING_MARKET_REGIME';
                }
                $active = $f['regime'] === 'BULL' ? $momentum : ($f['regime'] === 'SIDEWAYS' && $reversion);
                break;
            case 'PORTFOLIO_ROTATION':
                $active = $f['rank'] <= 4;
                break;
            case 'VWAP_RECLAIM':
                if (!isset($f['intraday_vwap_entry'])) {
                    return 'MISSING_INTRADAY';
                }
                $active = $f['intraday_vwap_entry'];
                break;
            case 'QUALITY_VALUE':
                if (($f['fundamental_status'] ?? '') !== 'AVAILABLE') {
                    return 'MISSING_OR_STALE_FUNDAMENTALS';
                }
                $active = $f['net_margin'] > 0 && $f['ebit_margin'] > 0;
                break;
            case 'EARNINGS_DRIFT':
                if (!isset($f['earnings_evidence'])) {
                    return 'MISSING_EARNINGS_RELEASE_OR_REACTION';
                }
                $active = $f['earnings_evidence']['active'] && ($f['mom5'] ?? 0) > 0;
                break;
            case 'DIVIDEND_QUALITY':
                if (!isset($f['dividend_quality'])) {
                    return 'MISSING_PAYOUT_HISTORY';
                }
                $active = $f['dividend_quality'] && $f['trend'] > 0;
                break;
            case 'MACRO_ROTATION':
                if (!isset($f['macro_return'],$f['macro_cost'])) {
                    return 'MISSING_POINT_IN_TIME_FX_HISTORY';
                }
                $active = $f['macro_return'] > $f['macro_cost'] && $f['trend'] > 0;
                break;
            case 'META_CHAMPION':
                $votes = 0;
                foreach (['MOMENTUM','MEAN_REVERSION','BREAKOUT','LOW_VOL'] as $rule) {
                    $votes += (int) (self::signal($rule,$f) === 'SIGNAL_PASS');
                }
                $active = $votes >= 2;
                break;
            default:
                throw new RuntimeException('UNKNOWN_STRATEGY');
        }
        return $active ? 'SIGNAL_PASS' : 'STRATEGY_FILTER';
    }

    public static function holding(string $key): int
    {
        return ['DIVIDEND_QUALITY'=>40,'EARNINGS_DRIFT'=>10,'MACRO_ROTATION'=>15,'MOMENTUM'=>20,
            'RELATIVE_STRENGTH'=>20,'LOW_VOL'=>20,'PORTFOLIO_ROTATION'=>20][$key] ?? 5;
    }

    public static function variant(string $key, array $row): ?string
    {
        return [
            'EARNINGS_DRIFT'=>$row['earnings_evidence']['variant'] ?? null,
            'DIVIDEND_QUALITY'=>isset($row['dividend_quality']) ? 'DOCUMENTED_PAYOUT_RULE' : null,
            'MACRO_ROTATION'=>isset($row['macro_return']) ? 'LAGGED_FX_RULE' : null,
            'META_CHAMPION'=>'EXPERIMENTAL_RULE_VOTE',
        ][$key] ?? null;
    }

    public static function evidenceExit(string $key, array $row): ?string
    {
        if ($key === 'EARNINGS_DRIFT' && array_key_exists('mom5',$row) && $row['mom5'] <= 0) {
            return 'EARNINGS_DRIFT_FADED';
        }
        if ($key === 'DIVIDEND_QUALITY' && ($row['dividend_quality'] ?? null) === false) {
            return 'DIVIDEND_QUALITY_DETERIORATED';
        }
        if ($key === 'MACRO_ROTATION' && isset($row['macro_return'],$row['macro_cost']) &&
            $row['macro_return'] < -$row['macro_cost']) {
            return 'MACRO_SIGNAL_REVERSED';
        }
        return null;
    }
}

final class PaperAccount
{
    public const POLICY = [
        'version' => 'EOD_NEXT_OPEN_V1', 'max_positions' => 4, 'weight_bps' => 3000,
        'sector_bps' => 4000, 'risk_bps' => 100, 'cash_buffer_bps' => 500,
        'max_exposure_bps'=>9000,'max_total_risk_bps'=>300,'vol_target_bps'=>3500,
        'regular_penalty_bps' => 8, 'odd_penalty_bps' => 18, 'fee_bps' => 4,
    ];

    public $state;
    public $events = [];
    public $orders = [];
    public $fills = [];
    private $key;
    private $policy;

    public function __construct(string $key, array $policy, array $state = [])
    {
        $this->key = $key;
        $this->policy = $policy;
        $this->state = $state ?: [
            'cash' => 0, 'reserved' => 0, 'payable' => 0, 'receivable' => 0, 'cost' => 0,
            'realized' => 0, 'fees' => 0, 'positions' => [], 'pending' => [], 'obligations' => [],
            'applied_actions' => [], 'peak' => 0, 'last_day' => null, 'last_equity' => null,
        ];
    }

    public function deposit(int $amount, string $at): void
    {
        if ($amount <= 0 || $this->state['cash'] !== 0) {
            throw new RuntimeException('INVALID_DEPOSIT');
        }
        $this->event('initial', 'VIRTUAL_DEPOSIT', $at, ['cash' => $amount], []);
        $this->state['peak'] = $amount;
        $this->state['last_equity'] = $amount;
    }

    private function event(string $key, string $kind, string $at, array $deltas, array $payload): void
    {
        $movement = array_replace(['cash' => 0, 'payable' => 0, 'receivable' => 0,
            'cost' => 0, 'reserved' => 0, 'realized' => 0], $deltas);
        if ($movement['cash'] - $movement['payable'] + $movement['receivable'] + $movement['cost'] !== $movement['realized'] &&
            $kind !== 'VIRTUAL_DEPOSIT') {
            throw new RuntimeException('UNBALANCED_EVENT');
        }
        $this->events[] = ['key' => hash('sha256', $key), 'at' => $at, 'kind' => $kind,
            'deltas' => $movement, 'payload' => $payload];
        foreach ($movement as $field => $amount) {
            $this->state[$field] += $amount;
        }
        if ($this->available() < 0 || min($this->state['reserved'], $this->state['payable'],
            $this->state['receivable'], $this->state['cost']) < 0) {
            throw new RuntimeException('INVALID_ACCOUNT_BALANCE');
        }
    }

    public function available(): int
    {
        return $this->state['cash'] - $this->state['reserved'] - $this->state['payable'];
    }

    public static function matchesLedger(array $totals, array $state): bool
    {
        foreach (['cash','reserved','payable','receivable','cost','realized'] as $key) {
            if (!isset($totals[$key]) || (int) $totals[$key] !== $state[$key]) {
                return false;
            }
        }
        $payable = $receivable = $reserved = $cost = 0;
        foreach ($state['obligations'] as $item) {
            if ($item['side'] === 'BUY') {
                $payable += $item['amount'];
            } else {
                $receivable += $item['amount'];
            }
        }
        foreach ($state['pending'] as $item) {
            $reserved += $item['budget'];
        }
        foreach ($state['positions'] as $item) {
            if ($item['quantity'] < 1) {
                return false;
            }
            $cost += $item['cost'];
        }
        return $payable === $state['payable'] && $receivable === $state['receivable'] &&
            $reserved === $state['reserved'] && $cost === $state['cost'] &&
            $state['cash'] >= $reserved + $payable;
    }

    public function mark(array $prices): array
    {
        $marked = 0;
        $status = 'AVAILABLE';
        foreach ($this->state['positions'] as $id => &$position) {
            if (isset($prices[$id])) {
                $position['mark'] = $prices[$id];
            } else {
                $status = 'STALE';
            }
            $marked += $position['mark'] * $position['quantity'];
        }
        unset($position);
        return [
            'equity_cents' => $this->state['cash'] + $this->state['receivable'] - $this->state['payable'] + $marked,
            'cash_cents' => $this->state['cash'], 'available_cents' => $this->available(),
            'reserved_cents' => $this->state['reserved'], 'payable_cents' => $this->state['payable'],
            'receivable_cents' => $this->state['receivable'], 'marked_cents' => $marked,
            'realized_cents' => $this->state['realized'], 'unrealized_cents' => $marked - $this->state['cost'],
            'fees_cents' => $this->state['fees'], 'status' => $status,
            'positions' => $this->state['positions'],
        ];
    }

    public function settle(string $date, string $at): void
    {
        foreach ($this->state['obligations'] as $key => $item) {
            if ($item['date'] > $date) {
                continue;
            }
            $amount = $item['amount'];
            $deltas = $item['side'] === 'BUY'
                ? ['cash' => -$amount, 'payable' => -$amount]
                : ['cash' => $amount, 'receivable' => -$amount];
            $this->event('settle:' . $key, 'SETTLEMENT', $at, $deltas, $item);
            unset($this->state['obligations'][$key]);
        }
    }

    public function corporate(array $action, string $at): void
    {
        $key = $action['event_key'];
        $id = $action['instrument_id'];
        if (isset($this->state['applied_actions'][$key])) {
            return;
        }
        $pos = $this->state['positions'][$id] ?? null;
        if ($pos) {
            if ($action['type'] === 'DIVIDEND') {
                $amount = $pos['quantity'] * $action['amount_cents'];
                $this->event($key, 'DIVIDEND_ENTITLEMENT', $at, ['receivable' => $amount, 'realized' => $amount], $action);
                $this->state['obligations'][$key] = ['side' => 'DIVIDEND', 'amount' => $amount, 'date' => $action['payment_date']];
            } else {
                $scaled = $pos['quantity'] * $action['numerator'];
                if ($scaled % $action['denominator'] !== 0) {
                    throw new RuntimeException('FRACTIONAL_CORPORATE_ACTION_REVIEW');
                }
                $pos['quantity'] = intdiv($scaled, $action['denominator']);
                foreach (['mark', 'stop'] as $field) {
                    $pos[$field] = max(1, (int) round($pos[$field] * $action['denominator'] / $action['numerator']));
                }
                $this->state['positions'][$id] = $pos;
                $this->event($key, 'SHARE_CONVERSION', $at, [], $action);
            }
        }
        $this->state['applied_actions'][$key] = true;
    }

    public function decide(string $strategy, array $row, array $marked, array $session, array $next,
        string $asOf, bool $paused, ?StrategyTrial $trial = null, ?string $slotKey = null): array
    {
        if ($trial && $trial->strategy !== $strategy) {
            throw new RuntimeException('TRIAL_STRATEGY_MISMATCH');
        }
        $id = (int) $row['id'];
        $position = $this->state['positions'][$id] ?? null;
        foreach ($this->state['pending'] as $order) {
            if ($order['instrument_id'] === $id) {
                return ['HOLD', 'PENDING_ORDER'];
            }
        }
        $buy = !$position;
        $plannedRisk = 0;
        if (!$buy) {
            $reason = $row['price'] <= $position['stop'] ? 'STOP_AT_CLOSE' : null;
            $holding = $trial ? $trial->holding : Strategies::holding($strategy);
            if ($reason === null && ($row['status'] === 'EXIT_ONLY' || $position['age'] >= $holding)) {
                $reason = $row['status'] === 'EXIT_ONLY' ? 'EXIT_ONLY' : 'MAX_HOLDING';
            }
            $reason = $reason ?? Strategies::evidenceExit($strategy,$row);
            if ($reason === null && ($row['edge'] ?? 0) >= 0 &&
                ($strategy !== 'MEAN_REVERSION' || ($row['z20'] ?? -1) < 0)) {
                return ['HOLD', 'WITHIN_EXIT_POLICY'];
            }
            $quantity = $position['quantity'];
            $budget = 0;
            $stop = $position['stop'];
        } else {
            $baseline = $row['status'] === 'EXPERIMENTAL_BASELINE' && in_array($strategy, Strategies::RULE_BASELINES, true);
            if ($paused || (!$baseline && $row['status'] !== 'TRADABLE') || $marked['status'] !== 'AVAILABLE') {
                if (!$paused && $row['status'] === 'EXPERIMENTAL_BASELINE' && !$baseline) {
                    return ['NO_TRADE', 'STRATEGY_REQUIRES_ADDITIONAL_EVIDENCE'];
                }
                return ['NO_TRADE', $paused ? 'PAUSED_OR_DRAWDOWN' : ($row['reason'] ?? 'INSUFFICIENT_DATA')];
            }
            if ($marked['equity_cents'] <= 0 || count($this->state['positions']) + count($this->state['pending']) >= $this->policy['max_positions']) {
                return ['NO_TRADE', 'POSITION_LIMIT'];
            }
            $reason = $trial ? ($trial->signal($row) ? 'SIGNAL_PASS' : 'STRATEGY_FILTER') : Strategies::signal($strategy, $row);
            if ($reason !== 'SIGNAL_PASS') {
                return ['NO_TRADE', $reason];
            }
            $sector = $exposure = $portfolioRisk = 0;
            foreach ($this->state['positions'] as $p) {
                $value = $p['quantity'] * $p['mark'];
                $exposure += $value;
                $portfolioRisk += $p['quantity'] * max(0,$p['mark']-$p['stop']);
                if ($p['sector'] === $row['sector']) {
                    $sector += $value;
                }
            }
            foreach ($this->state['pending'] as $p) {
                if ($p['side'] === 'BUY') {
                    $exposure += $p['budget'];
                    $portfolioRisk += $p['planned_risk_cents'] ?? $p['budget'];
                    if ($p['sector'] === $row['sector']) {
                        $sector += $p['budget'];
                    }
                }
            }
            $equity = $marked['equity_cents'];
            $cap = static function (int $bps) use ($equity): int {
                return intdiv($equity * $bps, 10000);
            };
            $budget = max(0, min($cap($this->policy['weight_bps']), $cap($this->policy['sector_bps']) - $sector,
                $cap($this->policy['max_exposure_bps'] ?? self::POLICY['max_exposure_bps']) - $exposure,
                $this->available() - $cap($this->policy['cash_buffer_bps'])));
            $atrStop = $trial ? $trial->atrStop : 2;
            $stop = max(1, (int) floor($row['price'] * (1 - max(.02, $atrStop * $row['atr']))));
            $unitRisk = max(1, $row['price'] - $stop + (int) ceil($row['price'] * .01));
            $target = ($this->policy['vol_target_bps'] ?? self::POLICY['vol_target_bps']) / 10000;
            $scale = min(1.0,$target/max($target,$row['vol20'] ?? 0.0));
            $riskCash = min((int) floor($cap($this->policy['risk_bps'])*$scale),
                $cap($this->policy['max_total_risk_bps'] ?? self::POLICY['max_total_risk_bps'])-$portfolioRisk);
            if ($riskCash < $unitRisk || $budget < $row['price']) {
                return ['NO_TRADE','AGGREGATE_RISK_OR_EXPOSURE_LIMIT'];
            }
            $quantity = min(intdiv($budget, max(1, (int) ceil($row['price'] * 1.005))),
                intdiv($riskCash,$unitRisk),intdiv($row['adv_shares'],1000));
            $plannedRisk = $quantity*$unitRisk;
        }
        $lot = (int) $row['lot_size'];
        $market = $quantity >= $lot ? '010' : '020';
        if ($market === '010') {
            $quantity = intdiv($quantity, $lot) * $lot;
        }
        if ($quantity < 1 || ($market === '020' && empty($row['odd_symbol']))) {
            return ['NO_TRADE', $quantity < 1 ? 'INSUFFICIENT_CAPITAL_OR_LIQUIDITY' : 'ODD_LOT_UNVERIFIED'];
        }
        $order = [
            'id' => hash('sha256', json([$this->key, $id, $session['session_date'], $buy] +
                ($slotKey === null ? [] : [4=>$slotKey]))),
            'instrument_id' => $id, 'sector' => $row['sector'], 'decision_at' => $asOf,
            'eligible_at' => $next['open_at'], 'session' => $next['session_date'],
            'side' => $buy ? 'BUY' : 'SELL', 'quantity' => $quantity, 'remaining' => $quantity,
            'market' => $market, 'lot' => $lot, 'tick' => (int) $row['tick_cents'],
            'budget' => $budget, 'max_qty' => intdiv((int) ($row['adv_shares'] ?? 0), 1000),
            'stop' => $stop, 'status' => 'ACCEPTED', 'reason' => $reason ?? 'EDGE_DETERIORATED',
            'planned_risk_cents'=>$plannedRisk,'risk_model'=>'AGGREGATE_RISK_V2',
        ];
        if ($asOf >= $next['open_at']) {
            return ['NO_TRADE', 'MISSED_OPEN'];
        }
        $this->state['pending'][$order['id']] = $order;
        $this->orders[$order['id']] = $order;
        if ($budget > 0) {
            $this->event('reserve:' . $order['id'], 'RESERVE', $asOf, ['reserved' => $budget], []);
        }
        return [$order['side'], $order['reason']];
    }

    public function execute(array $session, array $opening, array $instruments, ?array $settlement, bool $paused = false,
        ?array $buyIds = null, ?string $processedAt = null, bool $lateRecovery = false,
        string $priceEvidence = 'OPENING_OHLC_PROXY'): void
    {
        $pending = $this->state['pending'];
        uasort($pending,static function (array $left,array $right): int {
            return [$left['side'] === 'BUY',$left['decision_at'],$left['id']] <=>
                [$right['side'] === 'BUY',$right['decision_at'],$right['id']];
        });
        foreach ($pending as $key => $order) {
            if ($order['session'] > $session['session_date'] ||
                ($order['session'] === $session['session_date'] && $order['eligible_at'] > $session['open_at'])) {
                continue;
            }
            $id = $order['instrument_id'];
            $buy = $order['side'] === 'BUY';
            $bar = $opening[$id][$order['market']] ?? null;
            $usable = $bar && $order['eligible_at'] === $session['open_at'] &&
                $bar['open_at'] === $session['open_at'] && $order['decision_at'] < $bar['open_at'] &&
                $order['session'] === $session['session_date'] && $settlement &&
                eligible($instruments[$id] ?? []) && (!$buy || (!$paused && ($buyIds === null ||
                    (string) ($buyIds[$instruments[$id]['symbol']] ?? '') === (string) $id)));
            $status = 'EXPIRED';
            $executed = 0;
            if ($usable) {
                $evidence = ['price_evidence'=>$priceEvidence];
                if ($processedAt !== null) {
                    $evidence += ['processed_at'=>$processedAt,'late_recovery'=>$lateRecovery,
                        'source_bar_hash'=>$bar['raw_hash'] ?? null,'source_available_at'=>$bar['available_at'] ?? null];
                }
                $executed = $this->fill($order, (int) $bar['open_px'], $settlement['session_date'],$evidence);
                if ($executed > 0) {
                    $status = $executed === $order['quantity'] ? 'FILLED' : 'PARTIAL_EXPIRED';
                }
            }
            $order['remaining'] -= $executed;
            $order['status'] = $status;
            $this->orders[$key] = $order;
            unset($this->state['pending'][$key]);
            if ($buy && $executed === 0) {
                $this->event('release:' . $key, 'RELEASE', $session['open_at'], ['reserved' => -$order['budget']], []);
            }
        }
    }

    private function fill(array $o, int $reference, string $settlement, array $evidence): int
    {
        $buy = $o['side'] === 'BUY';
        $adverse = $this->policy[$o['market'] === '020' ? 'odd_penalty_bps' : 'regular_penalty_bps'];
        $price = ExecutionCost::price($reference,$o['tick'],$adverse,$buy);
        if ($price <= 0) {
            return 0;
        }
        $position = $this->state['positions'][$o['instrument_id']] ?? null;
        $qty = min($o['remaining'], $o['max_qty']);
        if ($buy) {
            $qty = min($qty, ExecutionCost::affordable($o['budget'],$price,$this->policy['fee_bps']));
        } else {
            $qty = min($qty, $position['quantity'] ?? 0);
        }
        if ($o['market'] === '010') {
            $qty = intdiv($qty, $o['lot']) * $o['lot'];
        }
        if ($qty <= 0) {
            return 0;
        }
        $charge = ExecutionCost::charge($price,$qty,$this->policy['fee_bps'],$buy);
        $fee = $charge['fee'];
        $amount = $charge['cash'];
        $id = $o['instrument_id'];
        $key = hash('sha256', $o['id'] . ':open');
        $fill = [
            'id' => $key, 'order_id' => $o['id'], 'filled_at' => $o['eligible_at'], 'settlement' => $settlement,
            'side' => $o['side'], 'quantity' => $qty, 'market' => $o['market'], 'instrument_id' => $id,
            'reference_cents' => $reference, 'price_cents' => $price, 'fees_cents' => $fee,
            'slippage_cents' => abs($price - $reference) * $qty, 'model' => $this->policy['version'],
            'realized_cents' => null, 'fee_bps'=>$this->policy['fee_bps'],'adverse_bps'=>$adverse,
        ] + $evidence;
        if ($buy) {
            if ($amount > $o['budget']) {
                return 0;
            }
            $this->state['positions'][$id] = ['quantity' => $qty, 'cost' => $amount, 'mark' => $reference,
                'stop' => $o['stop'], 'sector' => $o['sector'], 'age' => 0];
            // Conversion of reserved order cash into a purchase payable is atomic.
            $this->event($key, 'BUY_FILL', $o['eligible_at'], ['payable' => $amount,
                'cost' => $amount, 'reserved' => -$amount], $fill);
            $this->event('reserve-remainder:' . $key, 'RELEASE', $o['eligible_at'],
                ['reserved' => -($o['budget'] - $amount)], []);
        } else {
            $cost = intdiv($position['cost'] * $qty, $position['quantity']);
            $fill['realized_cents'] = $amount - $cost;
            $position['quantity'] -= $qty;
            $position['cost'] -= $cost;
            if ($position['quantity'] === 0) {
                unset($this->state['positions'][$id]);
            } else {
                $this->state['positions'][$id] = $position;
            }
            $this->event($key, 'SELL_FILL', $o['eligible_at'], ['receivable' => $amount,
                'cost' => -$cost, 'realized' => $amount - $cost], $fill);
        }
        $this->state['fees'] += $fee;
        $this->state['obligations'][$key] = ['side' => $o['side'], 'amount' => $amount, 'date' => $settlement];
        $this->fills[$key] = $fill;
        return $qty;
    }

    public function close(array $prices, string $date, ?string $asOf = null): array
    {
        $marked = $this->mark($prices);
        $equity = $marked['equity_cents'];
        $last = $this->state['last_equity'];
        $this->state['peak'] = max($this->state['peak'], $equity);
        $marked['drawdown'] = $this->state['peak'] > 0 ? $equity / $this->state['peak'] - 1 : null;
        $marked['daily_return'] = $last > 0 ? $equity / $last - 1 : null;
        if ($marked['status'] === 'AVAILABLE') {
            $this->state['last_equity'] = $equity;
        }
        $this->state['last_day'] = $date;
        foreach ($this->state['positions'] as $id=>&$pos) {
            $pos['age']++;
            if (!isset($prices[$id])) {
                continue;
            }
            $previous = $pos['stop'];
            $pos['stop'] = max($previous,(int) floor($pos['mark']*.95));
            if ($asOf !== null && $pos['stop'] > $previous) {
                $this->event('trailing:' . $id . ':' . $date,'STOP_UPDATED',$asOf,[],
                    ['instrument_id'=>$id,'previous_stop_cents'=>$previous,'stop_cents'=>$pos['stop'],'mark_cents'=>$pos['mark']]);
            }
        }
        unset($pos);
        unset($pos);
        return $marked;
    }
}
