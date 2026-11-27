<?php
declare(strict_types=1);

namespace Orfeu;

use RuntimeException;

final class Replay
{
    private $store;
    private $pipeline;

    public function __construct(Store $store, Pipeline $pipeline)
    {
        $this->store = $store;
        $this->pipeline = $pipeline;
    }

    public function run(string $from, string $to, int $capital, string $at, int $costMultiplier,
        ?string $researchModel = null, bool $fixedRules = false, ?StrategyTrial $trial = null): array
    {
        if ($from >= $to || $to > Calendar::brazilDate($at) || !in_array($costMultiplier,[1,2,3],true) || $capital <= 0) {
            throw new RuntimeException('INVALID_REPLAY_RANGE');
        }
        if (($fixedRules && $researchModel !== null) || (($trial || $this->store->usesReconstructedEod()) && !$fixedRules)) {
            throw new RuntimeException('INCOMPATIBLE_REPLAY_OPTIONS');
        }
        if ($researchModel !== null && (!preg_match('/^[a-f0-9]{64}$/D',$researchModel) ||
            !query($this->store->db, 'SELECT 1 FROM orf_model_version WHERE id=? AND horizon=?',
                [$researchModel,'5D'])->fetchColumn())) {
            throw new RuntimeException('UNKNOWN_RESEARCH_MODEL');
        }
        $calendar = $this->store->calendar();
        $rows = $calendar->between($from,$to);
        $days = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;
        if (count($rows) !== $days) {
            throw new RuntimeException('BLOCKED_CALENDAR_RANGE');
        }
        $sessions = array_values(array_filter($rows, static function (array $s): bool { return $s['open_at'] !== null; }));
        if (!$sessions) {
            throw new RuntimeException('BLOCKED_INSUFFICIENT_SESSIONS');
        }
        $policy = PaperAccount::POLICY;
        foreach (['regular_penalty_bps','odd_penalty_bps','fee_bps'] as $key) {
            $policy[$key] *= $costMultiplier;
        }
        $books = $curves = $counts = [];
        $ledger = [];
        $strategyKeys = $fixedRules ? Strategies::RULE_BASELINES : array_keys(Strategies::NAMES);
        $variants = $trial ? ['candidate'=>$trial->strategy,'baseline'=>$trial->strategy] : array_combine($strategyKeys,$strategyKeys);
        foreach ($variants as $key => $strategy) {
            $books[$key] = new PaperAccount('replay:' . $key,$policy);
            $books[$key]->deposit($capital,$sessions[0]['open_at']);
            $curves[$key] = [$books[$key]->mark([]) + ['date'=>null]];
            $counts[$key] = ['fills'=>0,'orders'=>0,'stale_snapshots'=>0,'closed_trades'=>0];
            $ledger[$key] = array_fill_keys(['cash','reserved','payable','receivable','cost','realized'],0);
        }
        $processed = 0;
        $dataSessions = 0;
        $reconstructedRows = 0;
        $basketStart = $basketEnd = null;
        $basketComplete = $fixedRules;
        $prior = [];
        $inputs = hash_init('sha256');
        foreach ($sessions as $session) {
            $date = $session['session_date'];
            $next = $calendar->next($date);
            if (!$next || $session['close_at'] >= $at) {
                break;
            }
            $decisionAt = min($at, utc('@' . (strtotime($next['open_at'] . ' UTC') - 1)));
            if ($decisionAt <= $session['close_at']) {
                break;
            }
            $data = $this->store->daily($date,$decisionAt);
            $instruments = $this->store->instruments($date,$session['open_at']);
            $actions = $this->store->observations('CORPORATE_ACTION',$date,$session['open_at']);
            $due = $calendar->next($date,2,true);
            $rank = $data ? $this->pipeline->rank($session,$calendar,$decisionAt,false,$prior,$fixedRules,$fixedRules,
                $researchModel,$costMultiplier) : [];
            if ($trial) {
                $sourceFeatures = array_map(static function (array $row): array {
                    unset($row['status'],$row['reason'],$row['rule_ready'],$row['relative_rank'],$row['macro_cost'],
                        $row['macro_return'],$row['earnings_evidence'],$row['dividend_quality']);
                    return $row;
                },$rank);
                hash_update($inputs,json([$session,$next,$decisionAt,$due,$data,$instruments,$actions,$sourceFeatures]) . "\n");
            }
            $selected = array_filter($rank, static function (array $row): bool { return $row['rank'] !== null; });
            $rebalance = $fixedRules || !$prior || (new \DateTimeImmutable($date))->format('o-W') !==
                (new \DateTimeImmutable($next['session_date']))->format('o-W');
            if ($rebalance) {
                $prior = $selected;
            }
            foreach ($rank as $instrument => &$r) {
                if (!$rebalance) {
                    $r['rank'] = $prior[$instrument]['rank'] ?? null;
                    if ($r['rank'] !== null && $r['status'] === 'REJECTED') {
                        $r['status'] = 'MONITOR_ONLY';
                    }
                }
            }
            unset($r);
            uasort($rank,static function (array $a,array $b): int {
                return ($a['rank'] ?? 999) <=> ($b['rank'] ?? 999);
            });
            $prices = [];
            foreach ($data as $id => $markets) {
                foreach ($markets as $bar) {
                    $reconstructedRows += (int) (($bar['availability_evidence'] ?? '') === 'RECONSTRUCTED_EOD');
                }
                if (isset($markets['010'])) {
                    $prices[$id] = (int) $markets['010']['close_px'];
                }
            }
            if ($basketComplete) {
                $marks = $this->basketMarks($prices,$instruments,$decisionAt);
                if ($marks === null || ($basketStart !== null && array_keys($marks) !== array_keys($basketStart))) {
                    $basketComplete = false;
                } else {
                    $basketStart = $basketStart ?? $marks;
                    $basketEnd = $marks;
                }
            }
            foreach ($books as $key => $book) {
                foreach ($actions as $revisions) {
                    if (isset($revisions[$date])) {
                        $book->corporate($revisions[$date],$session['open_at']);
                    }
                }
                if ($session['settles']) {
                    $book->settle($date,$session['open_at']);
                }
                $book->execute($session,$data,$instruments,$due);
                $mark = $book->close($prices,$date,$decisionAt);
                $paused = (bool) RiskControls::events($mark,false,$policy);
                foreach ($rank as $id => $candidate) {
                    if (!isset($prices[$id]) || !eligible($instruments[$id] ?? [])) {
                        continue;
                    }
                    if ($candidate['rank'] === null && !$fixedRules) {
                        if (!isset($book->state['positions'][$id])) {
                            continue;
                        }
                        $candidate['status'] = 'EXIT_ONLY';
                    }
                    $candidate['price'] = $prices[$id];
                    $book->decide($variants[$key],$candidate,$mark,$session,$next,$decisionAt,$paused,
                        $key === 'candidate' ? $trial : null);
                }
                $curves[$key][] = $mark + ['date'=>$date];
                $counts[$key]['fills'] += count($book->fills);
                foreach ($book->fills as $fill) {
                    if ($fill['side'] === 'SELL' && !isset($book->state['positions'][$fill['instrument_id']])) {
                        $counts[$key]['closed_trades']++;
                    }
                }
                $counts[$key]['orders'] += count(array_filter($book->orders,static function (array $o): bool {
                    return $o['status'] === 'ACCEPTED';
                }));
                $counts[$key]['stale_snapshots'] += (int) ($mark['status'] === 'STALE');
                if ($trial) {
                    foreach ($book->events as $event) {
                        foreach ($event['deltas'] as $field => $amount) {
                            $ledger[$key][$field] += $amount;
                        }
                    }
                    if (!PaperAccount::matchesLedger($ledger[$key],$book->state)) {
                        throw new RuntimeException('BLOCKED_REPLAY_LEDGER_MISMATCH');
                    }
                }
                $book->orders = $book->fills = $book->events = [];
            }
            $processed++;
            $dataSessions += (int) (bool) $prices;
        }
        if (!$dataSessions) {
            throw new RuntimeException('BLOCKED_NO_POINT_IN_TIME_EOD');
        }
        $cdiRows = $this->store->observations('MACRO',$to,$at)['CDI'] ?? [];
        $ibovRows = $this->store->observations('BENCHMARK',$to,$at)['IBOV'] ?? [];
        $basketReturn = null;
        if ($basketComplete && $basketStart && $processed > 1) {
            $basketReturn = $this->basketReturn($basketStart,$basketEnd,$sessions[0]['session_date'],
                $sessions[$processed-1]['session_date'],$at);
        }
        $result = [
            'kind'=>'BACKTEST_EOD','status'=>'RESEARCH_ONLY','from'=>$from,'to'=>$to,'cost_multiplier'=>$costMultiplier,
            'sessions'=>$processed,'eod_sessions'=>$dataSessions,'capital_cents'=>$capital,
            'research_model'=>$researchModel,'universe_policy'=>$fixedRules ? 'FIXED_VOLATILE_10_V1' : 'HISTORICAL_CATALOG',
            'selection_bias'=>$fixedRules && $from < '2026-11-24' ? 'RETROSPECTIVE_FIXED_UNIVERSE' : null,
            'availability_policy'=>$this->store->usesReconstructedEod() ? 'RECONSTRUCTED_EOD' : 'AS_RECORDED',
            'reconstruction_policy'=>$this->store->usesReconstructedEod() ? Store::EOD_RECONSTRUCTION : null,
            'reconstructed_session_bar_rows'=>$reconstructedRows,'strategy_results'=>[],
            'basket_equal_weight_close_to_close_theoretical'=>$basketReturn,
        ];
        if ($trial) {
            hash_update($inputs,json([$cdiRows,$ibovRows]) . "\n");
            $result['replay_input_sha256'] = hash_final($inputs);
            $result['ledger_reconciled'] = true;
        }
        foreach ($curves as $key => $curve) {
            $last = end($curve);
            $growth = 1.0;
            $cdiCount = 0;
            $excess = [];
            $dailyReturns = [];
            foreach (array_slice($curve, 1) as $entry) {
                if ($entry['status'] === 'AVAILABLE' && $entry['daily_return'] !== null &&
                    is_finite((float) $entry['daily_return'])) {
                    $dailyReturns[] = (float) $entry['daily_return'];
                }
            }
            foreach (array_slice($curve,1) as $entry) {
                $cdi = $cdiRows[$entry['date']]['value'] ?? null;
                if ($cdi !== null && $entry['status'] === 'AVAILABLE') {
                    $daily = $cdi / 100;
                    $growth *= 1 + $daily;
                    $excess[] = $entry['daily_return'] - $daily;
                    $cdiCount++;
                }
            }
            $mean = $cdiCount ? array_sum($excess) / $cdiCount : 0;
            $variance = 0.0;
            foreach ($excess as $item) {
                $variance += ($item - $mean) ** 2;
            }
            $returnMean = $dailyReturns ? array_sum($dailyReturns) / count($dailyReturns) : 0.0;
            $returnVariance = 0.0;
            foreach ($dailyReturns as $item) {
                $returnVariance += ($item - $returnMean) ** 2;
            }
            $dailyVolatility = count($dailyReturns) > 1
                ? sqrt($returnVariance / (count($dailyReturns) - 1)) : 0.0;
            $maxDrawdown = min(array_column(array_slice($curve, 1), 'drawdown'));
            $annualizedReturn = $processed > 0 && count($dailyReturns) === $processed && $last['equity_cents'] > 0
                ? ($last['equity_cents'] / $capital) ** (252 / $processed) - 1 : null;
            $startDate = $curve[1]['date'];
            $start = $ibovRows[$startDate]['value'] ?? null;
            $end = $ibovRows[$last['date']]['value'] ?? null;
            $result['strategy_results'][$key] = $counts[$key] + [
                'equity_cents'=>$last['equity_cents'],'total_return'=>$last['equity_cents'] / $capital - 1,
                'annualized_return'=>$annualizedReturn,
                'annualized_volatility'=>$dailyVolatility * sqrt(252),
                'return_observations'=>count($dailyReturns),
                'calmar'=>$annualizedReturn !== null && $maxDrawdown < 0
                    ? $annualizedReturn / abs($maxDrawdown) : null,
                'max_drawdown'=>$maxDrawdown,
                'fees_cents'=>$last['fees_cents'],'realized_cents'=>$last['realized_cents'],
                'unrealized_cents'=>$last['unrealized_cents'],'cdi_total'=>$cdiCount === $processed ? $growth - 1 : null,
                'cdi_coverage'=>$cdiCount / $processed,'ibov_close_to_close'=>$start && $end ? $end / $start - 1 : null,
                'sharpe_cdi'=>$cdiCount === $processed && $cdiCount > 1 && $variance > 0
                    ? sqrt(252) * $mean / sqrt($variance / ($cdiCount - 1)) : null,
            ];
        }
        return $result;
    }

    private function basketMarks(array $prices, array $instruments, string $at): ?array
    {
        $marks = [];
        foreach ($this->store->fixedWatchlist($at) as $symbol => $member) {
            $id = $member['instrument_id'];
            if ($id === null || isset($marks[$id]) || ($instruments[$id]['symbol'] ?? null) !== $symbol ||
                ($prices[$id] ?? 0) <= 0) {
                return null;
            }
            $marks[$id] = $prices[$id];
        }
        return $marks;
    }

    private function basketReturn(array $start, array $end, string $from, string $to, string $at): ?float
    {
        $coverage = $this->store->observations('ACTION_COVERAGE',$to,$at);
        foreach ($start as $instrument => $price) {
            $record = $coverage[$instrument][$to] ?? [];
            if (($record['verified'] ?? false) !== true || ($record['start'] ?? '9999-12-31') > $from) {
                return null;
            }
        }
        foreach ($this->store->observations('CORPORATE_ACTION',$to,$at) as $dates) {
            foreach ($dates as $date => $event) {
                if ($date >= $from && isset($start[$event['instrument_id'] ?? 0])) {
                    return null;
                }
            }
        }
        $sum = 0.0;
        foreach ($start as $id => $price) {
            $sum += $end[$id] / $price - 1;
        }
        return $sum / count($start);
    }
}

function promote(Store $store, string $model, string $evidence, string $at): array
{
    if (!preg_match('/^[a-f0-9]{64}$/D',$model) || !preg_match('/^[a-f0-9]{64}$/D',$evidence)) {
        throw new RuntimeException('INVALID_PROMOTION_IDS');
    }
    $db = $store->db;
    return lock($db,'pipeline',static function () use ($db,$model,$evidence,$at): array {
        return transaction($db,static function () use ($db,$model,$evidence,$at): array {
            $m = query($db,'SELECT * FROM orf_model_version WHERE id=? AND effective_at<=?',[$model,$at])->fetch();
            $r = query($db,'SELECT payload FROM orf_research_run WHERE id=?',[$evidence])->fetchColumn();
            if (!$m || !$r) {
                throw new RuntimeException('PROMOTION_EVIDENCE_MISSING');
            }
            $report = json_decode($r,true,64,JSON_THROW_ON_ERROR);
            $artifact = json_decode($m['payload'],true,64,JSON_THROW_ON_ERROR);
            $p = $report['prospective'] ?? [];
            $oos = $report['holdout'] ?? [];
            foreach ([$p,$oos] as $gate) {
                foreach (['model_net','baseline_net','cdi_net','coverage','drawdown','stress2x_net'] as $metric) {
                    if (!isset($gate[$metric]) || !is_numeric($gate[$metric]) || !is_finite((float) $gate[$metric])) {
                        throw new RuntimeException('PROMOTION_EVIDENCE_INCOMPLETE');
                    }
                }
                if (($gate['sessions'] ?? 0) < 30 || ($gate['independent_decisions'] ?? 0) < 30 ||
                    $gate['coverage'] < .8 || $gate['coverage'] > 1 || $gate['model_net'] <= max(0,$gate['baseline_net'],$gate['cdi_net']) ||
                    $gate['drawdown'] < -.10 || $gate['drawdown'] > 0 || $gate['stress2x_net'] < 0) {
                    throw new RuntimeException('PROMOTION_GATE_FAILED');
                }
            }
            if (($report['schema'] ?? '') !== 'ORFEU_PROMOTION_V1' || ($report['model_id'] ?? '') !== $model ||
                ($report['dataset_sha256'] ?? '') !== ($artifact['dataset_sha256'] ?? null) ||
                ($report['universe'] ?? '') !== 'POINT_IN_TIME_B3' || ($report['ledger_reconciled'] ?? false) !== true ||
                ($report['holdout_untouched'] ?? false) !== true ||
                timestamp($oos['start'] ?? '') <= $m['trained_until'] ||
                timestamp($oos['end'] ?? '') < timestamp($oos['start']) ||
                timestamp($p['start'] ?? '') <= max($m['effective_at'],timestamp($oos['end'])) ||
                timestamp($p['end'] ?? '') <= timestamp($p['start']) || timestamp($p['end']) >= $at) {
                throw new RuntimeException('PROMOTION_CAUSALITY_FAILED');
            }
            query($db,'INSERT INTO orf_promotion(horizon,model_id,evidence_id,effective_at) VALUES(?,?,?,?)',
                [$m['horizon'],$model,$evidence,$at]);
            query($db,'INSERT INTO orf_admin_audit(as_of,action,payload) VALUES(?,?,?)',
                [$at,'MODEL_PROMOTED',json(['model'=>$model,'evidence'=>$evidence])]);
            return ['status'=>'PROMOTED','model_id'=>$model,'effective_at'=>$at];
        });
    });
}
