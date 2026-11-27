<?php
declare(strict_types=1);

namespace Orfeu;

use RuntimeException;
use Throwable;

final class Pipeline
{
    private $store;
    private $config;

    public function __construct(Store $store, Config $config)
    {
        $this->store = $store;
        $this->config = $config;
    }

    public function collect(string $asOf, bool $sandbox = false): array
    {
        $db = $this->store->db;
        $token = $this->config->get('BRAPI_TOKEN');
        $source = $sandbox ? 'BRAPI_TEST' : 'BRAPI';
        return lock($db, $source, function () use ($db, $asOf, $token, $sandbox, $source): array {
            $today = Calendar::brazilDate($asOf);
            $health = query($db, 'SELECT * FROM orf_source_health WHERE source=?', [$source])->fetch();
            $count = $health && $health['request_day'] === $today ? (int) $health['requests'] : 0;
            if ($health && $health['next_attempt'] > $asOf || $count >= max(1, (int) $this->config->get('BRAPI_MAX_PER_DAY', '100'))) {
                return ['status' => 'SKIPPED_NOT_DUE_OR_QUOTA'];
            }
            $fixed = require ROOT . '/config/fixed-universe.php';
            $seed = $fixed['members'];
            if ($token === '' || $sandbox) {
                $out = array_values(array_intersect(array_keys($seed), ['PETR4', 'ITUB4', 'VALE3']));
            } else {
                $out = array_keys($seed);
            }
            // One batch per job, rotating the licensed list when larger than ten.
            $batches = array_chunk($out, $token === '' || $sandbox ? 1 : 10);
            if (!$batches) {
                return ['status' => 'NO_SYMBOLS'];
            }
            $symbols = $batches[$count % count($batches)];
            $interval = max(60, (int) $this->config->get('BRAPI_POLL_SECONDS', '300'));
            $next = static function (int $delay) use ($asOf): string {
                return utc('@' . (strtotime($asOf . ' UTC') + $delay));
            };
            $last = $health['last_success'] ?? null;
            $save = static function (string $status, ?string $success, int $delay, string $detail)
                use ($db, $source, $asOf, $next, $today, $count): void {
                query($db, 'INSERT INTO orf_source_health VALUES(?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE status=VALUES(status),last_attempt=VALUES(last_attempt),
                    last_success=VALUES(last_success),next_attempt=VALUES(next_attempt),request_day=VALUES(request_day),
                    requests=VALUES(requests),detail=VALUES(detail)',
                    [$source,$status,$asOf,$success,$next($delay),$today,$count+1,$detail]);
            };
            $save('IN_PROGRESS', $last, $interval, '');
            try {
                $client = new Brapi($sandbox ? '' : $token, null, $this->config->get('BRAPI_CA_FILE'));
                $result = $client->quotes($symbols, $asOf);
                $hash = saveRaw($source, $result['raw']);
                $status = $result['missing'] ? 'PARTIAL' : ($token === '' || $sandbox ? 'LIMITED' : 'AVAILABLE');
                transaction($db, static function () use ($db,$hash,$source,$asOf,$result,$save,$status,$interval): void {
                    query($db, 'INSERT INTO orf_raw_ingest VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE raw_hash=raw_hash',
                        [$hash,$source,$asOf,count($result['rows']),count($result['missing'])]);
                    foreach ($result['rows'] as $row) {
                        query($db, 'INSERT INTO orf_quote(symbol,event_at,available_at,price_cents,delay_seconds,raw_hash)
                            VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id', array_values($row));
                    }
                    $save($status, $asOf, $interval, implode(',', $result['missing']));
                });
                return ['status' => $status, 'symbols' => $symbols, 'quotes' => count($result['rows'])];
            } catch (HttpFailure $e) {
                $save('BLOCKED_SOURCE', $last, max($interval, $e->retryAfter, in_array($e->status, [401,403], true) ? 3600 : 600)
                    + random_int(0, 30), $e->getMessage());
                return ['status' => 'BLOCKED_SOURCE', 'reason' => $e->getMessage()];
            } catch (Throwable $e) {
                $save('ERROR', $last, max($interval, 600), publicError($e));
                return ['status' => 'ERROR', 'reason' => publicError($e)];
            }
        });
    }

    public function bcb(int $series, string $from, string $to, string $at, bool $dry = false): array
    {
        $map = [1=>['USD_BRL','BRL_PER_USD'],11=>['SELIC','PERCENT_DAY'],
            12=>['CDI','PERCENT_DAY'],433=>['IPCA','PERCENT_MONTH']];
        if (!isset($map[$series]) || day($from) > day($to) || $to > Calendar::brazilDate($at) ||
            (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days > 3650) {
            throw new RuntimeException('INVALID_BCB_RANGE');
        }
        $format = static function (string $d): string {
            return (new \DateTimeImmutable($d))->format('d/m/Y');
        };
        $raw = https("https://api.bcb.gov.br/dados/serie/bcdata.sgs.$series/dados?" .
            http_build_query(['formato' => 'json','dataInicial' => $format($from),'dataFinal' => $format($to)]),
            [], $this->config->get('BRAPI_CA_FILE'));
        $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_values($data) !== $data) {
            throw new RuntimeException('INVALID_BCB_SCHEMA');
        }
        [$subject,$unit] = $map[$series];
        $values = [];
        foreach ($data as $row) {
            if (!preg_match('~^(\d\d)/(\d\d)/(\d{4})$~D', $row['data'] ?? '', $m) ||
                !is_numeric($row['valor'] ?? null) || !is_finite((float) $row['valor']) ||
                ($series === 1 && $row['valor'] <= 0)) {
                throw new RuntimeException('INVALID_BCB_SCHEMA');
            }
            $date = day("$m[3]-$m[2]-$m[1]");
            if ($date < $from || $date > $to || isset($values[$date])) {
                throw new RuntimeException('INVALID_BCB_OBSERVATION_DATE');
            }
            $values[$date] = (float) $row['valor'];
        }
        if ($dry) {
            return ['observations'=>count($values),'subject'=>$subject,'unit'=>$unit,'dry_run'=>true];
        }
        $hash = saveRaw('BCB_SGS', $raw);
        return transaction($this->store->db, function () use ($values,$series,$at,$hash,$subject,$unit): array {
            $same = query($this->store->db, 'SELECT 1 FROM orf_raw_ingest WHERE raw_hash=?', [$hash])->fetchColumn();
            if ($same) {
                return ['observations' => 0, 'status' => 'ALREADY_IMPORTED'];
            }
            $inserted = 0;
            foreach ($values as $date => $value) {
                $latest = query($this->store->db,'SELECT payload FROM orf_observation WHERE kind=? AND subject=?
                    AND ref_date=? AND source=? ORDER BY available_at DESC,id DESC LIMIT 1',
                    ['MACRO',$subject,$date,'BCB_SGS'])->fetchColumn();
                if ($latest && (float) (json_decode($latest,true)['value'] ?? NAN) === $value) {
                    continue;
                }
                $this->store->observation('MACRO', $subject, $date, $at, 'BCB_SGS',
                    ['value' => $value, 'unit' => $unit,
                        'series' => $series, 'availability' => 'RECEIVED_AT_ONLY'], $at);
                $inserted++;
            }
            query($this->store->db, 'INSERT INTO orf_raw_ingest VALUES (?,?,?,?,?)', [$hash,'BCB_SGS',$at,count($values),0]);
            return ['observations' => $inserted, 'status' => 'AVAILABLE'];
        });
    }

    public function rank(array $session, Calendar $calendar, string $asOf, bool $persist = true, ?array $prior = null,
        bool $featuresOnly = false, bool $tournament = false, ?string $researchModel = null,
        int $costMultiplier = 1): array
    {
        if ($persist && $this->store->usesReconstructedEod()) {
            throw new RuntimeException('RECONSTRUCTION_RESEARCH_ONLY');
        }
        $db = $this->store->db;
        $date = $session['session_date'];
        $instruments = $this->store->instruments($date, $asOf);
        $fixed = require ROOT . '/config/fixed-universe.php';
        $verified = $tournament ? array_column($this->store->fixedWatchlist($asOf), 'instrument_id', 'symbol') : [];
        $fundamentals = $this->store->fundamentalQuality($instruments, $date, $asOf);
        $models = [];
        $artifactRows = $featuresOnly ? [] : query($db, 'SELECT m.*,p.effective_at promoted_at FROM orf_model_version m
            LEFT JOIN orf_promotion p ON p.id=(SELECT n.id FROM orf_promotion n WHERE n.horizon=m.horizon
                AND n.effective_at<=? ORDER BY n.effective_at DESC,n.id DESC LIMIT 1) AND p.model_id=m.id
            WHERE m.trained_until<? AND m.effective_at<=?
            ORDER BY p.effective_at IS NULL,m.effective_at DESC,m.id DESC', [$asOf,$asOf,$asOf])->fetchAll();
        foreach ($artifactRows as $artifact) {
            if ($researchModel !== null && $artifact['id'] !== $researchModel) {
                continue;
            }
            if (!isset($models[$artifact['horizon']])) {
                $m = json_decode($artifact['payload'], true, 64, JSON_THROW_ON_ERROR);
                Model::check($m);
                $models[$artifact['horizon']] = $artifact + ['model' => $m];
            }
        }
        $benchmark = $this->store->observations('BENCHMARK', $date, $asOf)['IBOV'] ?? [];
        $macro = $this->store->observations('MACRO', $date, $asOf)['CDI'] ?? [];
        $cdi = array_slice($macro, -1, 1, true);
        $cdiValue = $cdi ? reset($cdi) : null;
        $cdiFresh = $cdi && key($cdi) >= ($calendar->previous($date)['session_date'] ?? $date);
        $coverage = $this->store->observations('ACTION_COVERAGE', $date, $asOf);
        $earnings = $dividends = $fx = $payments = $eventDates = [];
        if ($tournament) {
            $earnings = $this->store->observations('EARNINGS_RELEASE',$date,$asOf);
            $dividends = $this->store->observations('DIVIDEND_PROFILE',$date,$asOf);
            $fx = $this->store->observations('MACRO',$date,$asOf)['USD_BRL'] ?? [];
            foreach ($this->store->observations('CORPORATE_ACTION',$date,$asOf) as $event => $versions) {
                ksort($versions);
                $latest = end($versions);
                $eventId = (int) ($latest['instrument_id'] ?? 0);
                $eventDates[$eventId][] = array_key_last($versions);
                if (($latest['type'] ?? '') === 'DIVIDEND') {
                    $payments[$eventId][$event] = $latest;
                }
            }
        }
        $rows = [];
        $relative = [];
        $first = $calendar->previous($date);
        for ($i = 1; $first && $i < 60; $i++) {
            $first = $calendar->previous($first['session_date']);
        }
        foreach ($instruments as $id => $instrument) {
            if ($tournament && (string) ($verified[$instrument['symbol']] ?? '') !== (string) $id) {
                continue;
            }
            $row = $instrument;
            $reason = 'INELIGIBLE_' . $instrument['status'];
            $bars = $this->store->bars((int) $id, $asOf, $date);
            $feature = Features::build($bars, $asOf);
            if ($feature && (!$first || $bars[60]['session_date'] !== $first['session_date'])) {
                $feature = null;
            }
            if ($feature && isset($benchmark[$date]['value'], $benchmark[$bars[20]['session_date']]['value'])) {
                $base = $benchmark[$bars[20]['session_date']]['value'];
                $end = $benchmark[$date]['value'];
                if ($base > 0 && $end > 0) {
                    $feature['relative'] = $feature['mom20'] - log($end / $base);
                    $feature['regime'] = $feature['vol20'] >= .55 ? 'STRESS' :
                        ($end / $base > 1.01 ? 'BULL' : 'SIDEWAYS');
                }
            }
            if ($feature) {
                $cvmCode = (string) ($instrument['cvm_code'] ?? '');
                if (isset($fundamentals[$cvmCode])) {
                    $feature += $fundamentals[$cvmCode] + ['fundamental_status' => 'AVAILABLE'];
                } else {
                    $feature['fundamental_status'] = 'MISSING_OR_STALE';
                }
                $featureJson = json($feature);
                $hash = hash('sha256', $featureJson);
                if ($persist) {
                    query($db, 'INSERT INTO orf_feature_snapshot VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE feature_hash=feature_hash',
                        [$id,$asOf,$hash,$featureJson]);
                }
                $row += $feature;
                $cost = (PaperAccount::POLICY['odd_penalty_bps'] + PaperAccount::POLICY['fee_bps']) / 10000 * 2;
                $baseCost = $cost + 2 / $feature['price'];
                $cost = $cost * $costMultiplier + 2 / $feature['price'];
                $carry = $cdiFresh ? max(0, (1 + $cdiValue['value'] / 100) ** 5 - 1) : null;
                $modelUsable = false;
                foreach ($models as $horizon => $artifact) {
                    $target = $calendar->next($date, (int) $horizon + 1);
                    $prediction = $target ? Model::predict($artifact['model'], $feature, $asOf) : null;
                    if (!$prediction || $asOf >= ($calendar->next($date)['open_at'] ?? '')) {
                        continue;
                    }
                    if ($persist) {
                        query($db, 'INSERT INTO orf_prediction(instrument_id,as_of,target_end,model_version,horizon,feature_hash,payload)
                            VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',
                            [$id,$asOf,$target['open_at'],$artifact['id'],$horizon,$hash,json($prediction)]);
                    }
                    if ($horizon === '5D') {
                        $actionCoverage = $coverage[$id][$date] ?? [];
                        $row += $prediction + ['roundtrip_cost' => $cost, 'carry_cost' => $carry,
                            'promoted' => ($artifact['promoted_at'] !== null ||
                                (!$persist && !$tournament && $researchModel === $artifact['id'])) &&
                                ($actionCoverage['verified'] ?? false) === true && $actionCoverage['start'] <= $first['session_date'] &&
                                abs($prediction['model_cost'] - $baseCost) <= .001];
                        $modelUsable = true;
                    } elseif ($carry !== null && (!$tournament || $artifact['promoted_at'] !== null)) {
                        $row['edge' . (int) $horizon] = $prediction['mu_gross'] - $cost - $carry * ((int) $horizon / 5);
                    }
                }
                if (eligible($instrument)) {
                    $min = cents($this->config->get('UNIVERSE_MIN_ADV_BRL', '5000000'));
                    $reason = $tournament && $feature['price'] < $fixed['min_price_cents'] ? 'PRICE_BELOW_MINIMUM' :
                        ($feature['session_date'] !== $date ? 'STALE_BAR' :
                        ($feature['adv_cents'] < $min ? 'LOW_LIQUIDITY' :
                        (!$modelUsable ? 'MISSING_MODEL_OR_FEATURES' : ($carry === null ? 'CDI_MISSING' : 'OK'))));
                    if ($reason === 'OK' && isset($row['relative'])) {
                        $relative[$id] = $row['relative'];
                    }
                    if ($tournament) {
                        $actionCoverage = $coverage[$id][$date] ?? [];
                        $row['minimum_adv_cents'] = $min;
                        $row['action_coverage_ok'] = ($actionCoverage['verified'] ?? false) === true &&
                            ($actionCoverage['start'] ?? '9999-12-31') <= $first['session_date'];
                        $row['rule_ready'] = $feature['session_date'] === $date &&
                            $feature['adv_cents'] >= $min && $feature['price'] >= $fixed['min_price_cents'] &&
                            $feature['atr'] > $cost && $row['action_coverage_ok'];
                        if ($row['rule_ready'] && isset($row['relative'])) {
                            $relative[$id] = $row['relative'];
                        }
                        if ($row['rule_ready']) {
                            $events = $eventDates[$id] ?? [];
                            $clean = static function (string $since) use ($events): bool {
                                foreach ($events as $event) {
                                    if ($event >= $since) {
                                        return false;
                                    }
                                }
                                return true;
                            };
                            if ($clean($bars[5]['session_date'])) {
                                $row['earnings_evidence'] = StrategyEvidence::earnings($earnings[$id] ?? [],$bars,$cost);
                            }
                            $row['dividend_quality'] = StrategyEvidence::dividends($dividends[$id] ?? [],$payments[$id] ?? [],$date);
                            if ($clean($first['session_date'])) {
                                $row['macro_return'] = StrategyEvidence::macro($bars,$fx);
                                $row['macro_cost'] = $cost;
                            }
                        }
                    }
                }
            } elseif (eligible($instrument)) {
                $reason = 'INSUFFICIENT_CONTIGUOUS_HISTORY';
            }
            $rows[$id] = $row + ['reason' => $reason];
        }
        asort($relative);
        $index = 0;
        foreach ($relative as $id => $value) {
            $rows[$id]['relative_rank'] = ++$index / count($relative);
        }
        $prior = $prior ?? $this->effectiveUniverse($asOf);
        $ranked = Ranking::select($rows, array_filter($prior, static function ($row): bool { return $row['rank'] !== null; }));
        if ($tournament) {
            foreach ($ranked as &$row) {
                if (!empty($row['rule_ready']) && $row['status'] !== 'TRADABLE') {
                    $row['status'] = 'EXPERIMENTAL_BASELINE';
                    $row['reason'] = 'RULE_ONLY';
                    unset($row['edge']);
                }
            }
            unset($row);
        }
        return $ranked;
    }

    public function effectiveUniverse(string $at): array
    {
        $members = query($this->store->db, 'SELECT instrument_id,payload FROM orf_universe_member WHERE snapshot_id=(
            SELECT id FROM orf_universe_snapshot WHERE effective_at<=? AND available_at<=? AND status="MODELLED"
            ORDER BY effective_at DESC,as_of DESC,id DESC LIMIT 1)', [$at,$at])->fetchAll();
        $out = [];
        foreach ($members as $member) {
            $out[$member['instrument_id']] = json_decode($member['payload'], true, 64, JSON_THROW_ON_ERROR);
        }
        return $out;
    }

    public function saveRank(array $rows, array $session, array $next, string $at, bool $fixed = false): array
    {
        $db = $this->store->db;
        $selected = array_filter($rows, static function (array $r): bool { return $r['rank'] !== null; });
        $status = $fixed ? 'FIXED_REVIEW' : ($selected ? 'MODELLED' : 'SEED_UNVALIDATED');
        $prior = $fixed ? [] : $this->effectiveUniverse($at);
        $firstOfWeek = substr($session['session_date'], 0, 4) !== substr($next['session_date'], 0, 4) ||
            (new \DateTimeImmutable($session['session_date']))->format('W') !== (new \DateTimeImmutable($next['session_date']))->format('W');
        $rankHash = hash('sha256', json([$status,$session['session_date'],$rows]));
        $version = query($db, 'SELECT model_version FROM orf_prediction WHERE as_of=? AND horizon="5D" LIMIT 1', [$at])->fetchColumn();
        transaction($db, static function () use ($db,$at,$next,$status,$version,$rankHash,$rows,$prior,$firstOfWeek): void {
            $effectiveStatus = $status === 'MODELLED' && $prior && !$firstOfWeek ? 'DAILY_REVIEW' : $status;
            $added = query($db, 'INSERT INTO orf_universe_snapshot(as_of,available_at,effective_at,status,model_version,fingerprint)
                VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',
                [$at,$at,$next['open_at'],$effectiveStatus,$version ?: null,$rankHash])->rowCount();
            if ($added) {
                $id = (int) $db->lastInsertId();
                foreach ($rows as $instrument => $row) {
                    query($db, 'INSERT INTO orf_universe_member VALUES (?,?,?,?,?)',
                        [$id,$instrument,$row['rank'],$row['status'],json($row)]);
                }
            }
        });
        if ($prior && !$firstOfWeek) {
            foreach ($rows as $id => &$row) {
                if (isset($prior[$id]) && $prior[$id]['rank'] !== null && eligible($row)) {
                    $row['rank'] = $prior[$id]['rank'];
                    $row['status'] = $row['reason'] === 'EDGE_VALID' ? 'TRADABLE' : 'MONITOR_ONLY';
                } else {
                    $row['rank'] = null;
                    $row['status'] = 'REJECTED';
                }
            }
            unset($row);
        }
        return $rows;
    }

    public function tick(string $at, bool $dry = false): array
    {
        $calendar = $this->store->calendar();
        $market = $calendar->marketState($at);
        $date = Calendar::brazilDate($at);
        if ($dry) {
            return ['status'=>'DRY_RUN','market'=>$market,'date'=>$date,'mode'=>'PAPER_TRADING'];
        }
        $jobs = new SessionJobs($this->store);
        if (!$jobs->installed()) {
            return ['status'=>'BLOCKED_SCHEDULER_MIGRATION'];
        }
        $sources = ['quotes'=>['status'=>'SKIPPED_NOT_DUE'],'inbox'=>['status'=>'SKIPPED_NOT_DUE']];
        try {
            if ($market === 'CONTINUOUS') {
                $sources['quotes'] = $this->collect($at);
            } elseif ($market === 'CLOSED') {
                $sources['inbox'] = $this->importDailyInbox($at);
            }
        } catch (Throwable $error) {
            $sources[$market === 'CONTINUOUS' ? 'quotes' : 'inbox'] = ['status'=>'ERROR','code'=>publicError($error)];
        }
        return lock($this->store->db,'pipeline',function () use ($at,$date,$jobs,$sources): array {
            $calendar = $this->store->calendar();
            $today = $calendar->get($date);
            if (!$today) {
                return ['status'=>'BLOCKED_CALENDAR'] + $sources;
            }
            $latest = $today['close_at'] && $today['close_at'] <= $at ? $today : $calendar->previous($date);
            if (!$latest) {
                return ['status'=>'WAITING_EOD','accounts_settled'=>$this->settleDue($calendar,$date,$at)] + $sources;
            }
            $processed = [];
            $status = 'SKIPPED_NOT_DUE';
            $session = $jobs->due($calendar,$latest,$at);
            for ($count=0; $count<5 && $session && $session['session_date'] <= $latest['session_date']; $count++) {
                $key = $session['session_date'];
                $next = $calendar->next($key);
                if (!$next) {
                    $status = 'BLOCKED_NEXT_SESSION';
                    break;
                }
                $missed = $at >= $next['open_at'];
                $checkpoint = $jobs->checkpoint($key);
                if (($checkpoint['status'] ?? '') === 'DEAD') {
                    $status = 'BLOCKED_EOD_DEAD_LETTER';
                    break;
                }
                $retryAt = $checkpoint['next_attempt_at'] ?? null;
                if ($retryAt !== null && $retryAt > $at) {
                    $status = 'WAITING_EOD';
                    break;
                }
                $jobs->start($key,$at);
                try {
                    $snapshotAt = $missed ? utc($at . ' -' . (5-$count) . ' microseconds') : $at;
                    $result = $this->processEodSession($calendar,$session,$next,$at,$snapshotAt,$missed);
                } catch (Throwable $error) {
                    $jobs->failed($key,$at,publicError($error));
                    throw $error;
                }
                $status = $result['status'];
                $terminal = in_array($status,['SUCCESS','EOD_GAP'],true);
                transaction($this->store->db,function () use ($jobs,$key,$at,$terminal,$status): void {
                    $jobs->finish($key,$at,$terminal ? ($status === 'EOD_GAP' ? 'GAP' : 'DONE') : 'WAITING',
                        $status,$terminal ? null : utc($at . ' +10 minutes'));
                    if ($terminal) {
                        query($this->store->db,'INSERT INTO orf_job_run(job,as_of,status,detail) VALUES (?,?,?,?)',
                            ['EOD',$at,$status,$key]);
                    }
                });
                $processed[] = $result;
                if (!$terminal) {
                    break;
                }
                $session = $next;
            }
            $caughtUp = $session && $session['session_date'] > $latest['session_date'];
            return ['status'=>$status,'session'=>$processed ? end($processed)['session'] : null,'eod_sessions'=>$processed,
                'accounts_settled'=>$caughtUp ? $this->settleDue($calendar,$date,$at) : 0] + $sources;
        });
    }

    private function processEodSession(Calendar $calendar, array $session, array $next, string $at, string $snapshotAt,
        bool $missed): array
    {
        $daily = $this->store->daily($session['session_date'], $at);
        if (!$daily && !$missed) {
            return ['status'=>'BLOCKED_EOD_DATA','session'=>$session['session_date']];
        }
        $db = $this->store->db;
        $ranked = [];
        if (!$missed) {
            $ranked = $this->rank($session, $calendar, $at, true, null, false, true);
            $this->saveRank($ranked, $session, $next, $at, true);
        }
        $openEvidence = $daily;
        foreach ($openEvidence as &$markets) {
            $markets = array_filter($markets,static function (array $bar) use ($missed,$next): bool {
                return $bar['provider'] === 'B3_COTAHIST' && (!$missed || $bar['available_at'] < $next['open_at']);
            });
        }
        unset($markets);
        $seasons = query($db, 'SELECT * FROM orf_season WHERE starts_at<=? ORDER BY id',
            [$session['close_at']])->fetchAll();
        $prices = [];
        foreach ($daily as $id => $bars) {
            if (isset($bars['010'])) {
                $prices[$id] = (int) $bars['010']['close_px'];
            }
        }
        $actions = $this->store->observations('CORPORATE_ACTION', $session['session_date'], $session['open_at']);
        $instruments = $this->store->instruments($session['session_date'], $session['open_at']);
        $buyIds = array_column($this->store->fixedWatchlist($session['open_at']), 'instrument_id', 'symbol');
        $settlement = $calendar->next($session['session_date'], 2, true);
        $blockedAccounts = 0;
        foreach ($seasons as $season) {
            $ids = query($db, 'SELECT id FROM orf_account WHERE season_id=? ORDER BY id',
                [$season['id']])->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                $ok = transaction($db, function () use ($db,$id,$season,$session,$next,$openEvidence,$actions,$ranked,$prices,
                    $instruments,$settlement,$at,$snapshotAt,$buyIds,$missed): bool {
                    $row = query($db, 'SELECT * FROM orf_account WHERE id=? FOR UPDATE', [$id])->fetch();
                    $state = json_decode($row['state'], true, 64, JSON_THROW_ON_ERROR);
                    if ($state['last_day'] !== null && $state['last_day'] >= $session['session_date']) {
                        return true;
                    }
                    if (!$this->store->reconcile((int) $id, $state)) {
                        query($db, 'INSERT INTO orf_alert(as_of,code,payload) VALUES(?,?,?)',
                            [$at,'LEDGER_MISMATCH',json(['account_id' => $id])]);
                        return false;
                    }
                    $policy = json_decode($season['policy'], true, 64, JSON_THROW_ON_ERROR);
                    $book = new PaperAccount($season['id'] . ':' . $row['strategy_key'], $policy, $state);
                    foreach ($actions as $versions) {
                        if (isset($versions[$session['session_date']])) {
                            $book->corporate($versions[$session['session_date']], $session['open_at']);
                        }
                    }
                    if ($session['settles']) {
                        $book->settle($session['session_date'], $session['open_at']);
                    }
                    $book->execute($session, $openEvidence, $instruments, $settlement, (bool) $season['paused'], $buyIds,$at,$missed);
                    $mark = $book->close($prices, $session['session_date'],$at);
                    $riskEvents = RiskControls::events($mark,(bool) $season['paused'],$policy);
                    $paused = (bool) $riskEvents;
                    foreach ($riskEvents as $event) {
                        query($db, 'INSERT IGNORE INTO orf_risk_event
                            (account_id,session_date,as_of,event_type,severity,payload) VALUES(?,?,?,?,?,?)',
                            [$id,$session['session_date'],$at,$event['type'],$event['severity'],json([
                                'equity_cents'=>$mark['equity_cents'] ?? null,
                                'drawdown'=>$mark['drawdown'] ?? null,
                                'daily_return'=>$mark['daily_return'] ?? null] + $event)]);
                    }
                    $candidates = $ranked;
                    foreach ($book->state['positions'] as $instrument => $position) {
                        if (isset($instruments[$instrument]) && !isset($candidates[$instrument])) {
                            $candidates[$instrument] = array_replace($instruments[$instrument],
                                ['rank'=>null,'status'=>'EXIT_ONLY']);
                        }
                    }
                    if ($missed) {
                        $candidates = [];
                    }
                    uasort($candidates, static function (array $a,array $b) use ($book): int {
                        return [!isset($book->state['positions'][$a['id']]),$a['rank'] ?? 999,$a['id']] <=>
                            [!isset($book->state['positions'][$b['id']]),$b['rank'] ?? 999,$b['id']];
                    });
                    foreach ($candidates as $instrument => $candidate) {
                        if (!isset($prices[$instrument]) || !eligible($instruments[$instrument] ?? [])) {
                            [$action,$reason] = ['NO_TRADE','STALE_OR_SUSPENDED'];
                        } else {
                            $candidate['price'] = $prices[$instrument];
                            [$action,$reason] = $book->decide($row['strategy_key'],$candidate,$mark,$session,$next,$at,(bool) $paused);
                        }
                        query($db, 'INSERT INTO orf_decision VALUES (?,?,?,?,?,?)',
                            [$id,$instrument,$at,$action,$reason,json(['edge'=>$candidate['edge'] ?? null,
                                'evidence_level'=>($candidate['status'] === 'EXPERIMENTAL_BASELINE' &&
                                    in_array($row['strategy_key'], Strategies::RULE_BASELINES, true)) ? 'RULE_ONLY' : null,
                                'signal_variant'=>Strategies::variant($row['strategy_key'],$candidate)])]);
                    }
                    $this->store->persistAccount((int) $id, $book);
                    $mark['session_date'] = $session['session_date'];
                    $mark['late_processing'] = $missed;
                    $mark['recorded_at'] = $at;
                    $this->store->equity((int) $id, $snapshotAt, $mark);
                    return true;
                });
                $blockedAccounts += (int) !$ok;
            }
        }
        return ['status'=>$missed || $blockedAccounts ? 'EOD_GAP' : 'SUCCESS','session'=>$session['session_date'],
            'seasons'=>count($seasons),'late_recovery'=>$missed,'blocked_accounts'=>$blockedAccounts];
    }

    private function importDailyInbox(string $at): array
    {
        $db = $this->store->db;
        $files = glob(ROOT . '/storage/inbox/COTAHIST_D*.ZIP') ?: [];
        if (!$files) {
            return ['status'=>'NO_FILES'];
        }
        return lock($db,'cotahist',function () use ($db,$files,$at): array {
            $results = [];
            foreach ($files as $file) {
                if (filemtime($file) > time() - 60 || !preg_match('/^COTAHIST_D\d{8}\.ZIP$/iD',basename($file))) {
                    continue;
                }
                $hash = hash_file('sha256',$file);
                if (!$hash || query($db,'SELECT 1 FROM orf_raw_ingest WHERE raw_hash=?',[$hash])->fetchColumn()) {
                    continue;
                }
                $detail = basename($file) . ':' . substr($hash,0,12);
                $recentFailure = query($db,'SELECT 1 FROM orf_job_run WHERE job=? AND status=? AND detail=? AND as_of>?',
                    ['COTAHIST','ERROR',$detail,utc($at . ' -1 hour')])->fetchColumn();
                if ($recentFailure) {
                    continue;
                }
                try {
                    $result = $this->store->importCotahist($file,'2015-01-01',$at);
                    $status = $result['inserted'] ? 'SUCCESS' : 'NO_MATCHED_ROWS';
                    $results[] = ['file'=>basename($file),'status'=>$status];
                } catch (Throwable $error) {
                    $status = 'ERROR';
                    $results[] = ['file'=>basename($file),'status'=>$status,'code'=>publicError($error)];
                }
                query($db,'INSERT INTO orf_job_run(job,as_of,status,detail) VALUES(?,?,?,?)',
                    ['COTAHIST',$at,$status,$detail]);
                if (count($results) >= 3) {
                    break;
                }
            }
            return ['status'=>$results ? 'CHECKED' : 'NO_NEW_FILES','files'=>$results];
        });
    }

    private function settleDue(Calendar $calendar, string $date, string $at): int
    {
        $session = $calendar->get($date);
        if (!$session['settles'] || $session['open_at'] === null || $session['open_at'] > $at) {
            return 0;
        }
        $db = $this->store->db;
        $rows = query($db,'SELECT a.id,a.state FROM orf_account a JOIN orf_season s ON s.id=a.season_id
            WHERE s.starts_at<=? ORDER BY a.id',[$at])->fetchAll();
        $settled = 0;
        foreach ($rows as $row) {
            $state = json_decode($row['state'],true,64,JSON_THROW_ON_ERROR);
            if (!(($state['payable'] ?? 0) + ($state['receivable'] ?? 0))) {
                continue;
            }
            $settled += transaction($db,function () use ($db,$row,$date,$at): int {
                $account = query($db,'SELECT a.*,s.policy FROM orf_account a JOIN orf_season s ON s.id=a.season_id
                    WHERE a.id=? FOR UPDATE',[$row['id']])->fetch();
                $state = json_decode($account['state'],true,64,JSON_THROW_ON_ERROR);
                if (!$this->store->reconcile((int) $row['id'],$state)) {
                    return 0;
                }
                $policy = json_decode($account['policy'],true,64,JSON_THROW_ON_ERROR);
                $book = new PaperAccount($account['season_id'] . ':' . $account['strategy_key'],$policy,$state);
                $book->settle($date,$at);
                if (!$book->events) {
                    return 0;
                }
                $this->store->persistAccount((int) $row['id'],$book);
                return 1;
            });
        }
        return $settled;
    }

    public function historicalFeatures(string $from, string $to, string $at): array
    {
        if ($from > $to || $to > Calendar::brazilDate($at)) {
            throw new RuntimeException('INVALID_RESEARCH_RANGE');
        }
        $cal = $this->store->calendar();
        $count = 0;
        foreach ($cal->between($from,$to) as $session) {
            if (!$session['close_at'] || !($next = $cal->next($session['session_date']))) {
                continue;
            }
            $asOf = min($at,utc('@' . (strtotime($next['open_at'] . ' UTC') - 1)));
            if ($asOf <= $session['close_at'] || !$this->store->daily($session['session_date'],$asOf)) {
                continue;
            }
            $this->rank($session,$cal,$asOf,true,[],true);
            $count++;
        }
        return ['status'=>$count ? 'AVAILABLE' : 'BLOCKED_NO_POINT_IN_TIME_EOD','sessions'=>$count];
    }
}

function publicError(Throwable $error): string
{
    $message = $error->getMessage();
    return $error instanceof RuntimeException && preg_match('/^[A-Z][A-Z0-9_.:-]{2,95}$/D', $message)
        ? $message : 'INTERNAL_ERROR';
}
