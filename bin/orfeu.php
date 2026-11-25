<?php
declare(strict_types=1);

namespace Orfeu;

use RuntimeException;
use Throwable;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../app/bootstrap.php';

$cmd = $argv[1] ?? 'help';
$options = [];
foreach (array_slice($argv, 2) as $arg) {
    if (!preg_match('/^--([a-z][a-z0-9-]*)(?:=(.*))?$/D', $arg, $m) || isset($options[$m[1]])) {
        fwrite(STDERR, "INVALID_OPTION\n");
        exit(1);
    }
    $options[$m[1]] = $m[2] ?? true;
}

function inputFile(array $options): string
{
    $file = $options['file'] ?? '';
    if (!is_string($file) || !is_file($file) || !is_readable($file)) {
        throw new RuntimeException('INPUT_FILE_REQUIRED');
    }
    return $file;
}

try {
    if (isset($options['mode']) && $options['mode'] !== 'PAPER_TRADING') {
        throw new RuntimeException('PAPER_MODE_REQUIRED');
    }
    $commands = ['doctor','migrate','calendar:seed','calendar:import','universe:seed','universe:import',
        'data:backfill','data:quotes','data:bcb','data:cvm','data:observations','data:news','season:create',
        'rank:refresh','tick','reconcile','research:features','research:export','research:import','model:import','model:promote',
        'backtest','report:daily','universe:seed-fixed','universe:verify-fixed','data:coverage','calendar:sync','predictions:evaluate',
        'research:trial','research:intraday','jobs:retry-eod'];
    if ($cmd === 'help') {
        echo implode(PHP_EOL, $commands) . PHP_EOL;
        exit(0);
    }
    if (!in_array($cmd, $commands, true)) {
        throw new RuntimeException('UNKNOWN_COMMAND');
    }
    $config = new Config();
    $at = utc();
    if ($cmd === 'doctor') {
        $info = ['php' => PHP_VERSION, 'extensions' => array_map('extension_loaded', ['pdo_mysql','curl','openssl','zip','mbstring']),
            'extension_names' => ['pdo_mysql','curl','openssl','zip','mbstring'], 'clock_utc' => $at,
            'mode' => 'PAPER_TRADING', 'brapi_token_configured' => $config->get('BRAPI_TOKEN') !== '',
            'local_root' => $config->get('ALLOW_LOCAL_ROOT') === 'true'];
        try {
            $db = $config->connect();
            $tables = $db->query('SHOW FULL TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            $counts = [];
            foreach (['orf_session','orf_bar','orf_quote','orf_prediction','orf_prediction_evaluation',
                'orf_order','orf_fill','orf_account','orf_fixed_watchlist','orf_job_checkpoint'] as $table) {
                $counts[$table] = in_array($table,$tables,true) ? (int) $db->query("SELECT COUNT(*) FROM $table")->fetchColumn() : null;
            }
            $info['db'] = ['schema' => $db->query('SELECT DATABASE()')->fetchColumn(), 'version' => $db->query('SELECT VERSION()')->fetchColumn(),
                'tables' => count($tables),'row_counts'=>$counts];
            $info['market'] = in_array('orf_session',$tables,true) ? (new Store($db))->calendar()->marketState($at) : null;
        } catch (Throwable $e) {
            $info['db'] = publicError($e);
        }
        echo json($info) . PHP_EOL;
        exit(is_array($info['db']) ? 0 : 2);
    }
    $db = $config->connect();
    $store = new Store($db);
    $pipeline = new Pipeline($store, $config);
    switch ($cmd) {
        case 'jobs:retry-eod':
            $sessionDate = day($options['session'] ?? '');
            $jobs = new SessionJobs($store);
            if (!$jobs->installed()) {
                throw new RuntimeException('BLOCKED_SCHEDULER_MIGRATION');
            }
            $result = lock($db,'pipeline',static function () use ($jobs,$sessionDate,$at): array {
                return ['status'=>$jobs->retryDead($sessionDate,$at) ? 'RETRY_QUEUED' : 'NO_DEAD_JOB','session'=>$sessionDate];
            });
            break;
        case 'predictions:evaluate':
            $limit = filter_var($options['limit'] ?? 100,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000]]);
            if (!$limit) {
                throw new RuntimeException('INVALID_LIMIT');
            }
            if (!PredictionEvaluation::installed($store)) {
                throw new RuntimeException('BLOCKED_PREDICTION_MIGRATION');
            }
            $result = PredictionEvaluation::run($store,$at,$limit);
            break;
        case 'universe:seed-fixed':
            $result = $store->seedFixedWatchlist($at, isset($options['dry-run']));
            break;
        case 'universe:verify-fixed':
            $id = filter_var($options['instrument'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if (!$id || !is_string($options['symbol'] ?? null) || !is_string($options['evidence'] ?? null)) {
                throw new RuntimeException('INVALID_WATCHLIST_IDENTITY');
            }
            $store->verifyFixedIdentity($options['symbol'], $id, $options['evidence'], $at);
            $result = ['status'=>'VERIFIED','symbol'=>$options['symbol'],'instrument_id'=>$id];
            break;
        case 'migrate':
            $result = $store->migrate(isset($options['dry-run']));
            break;
        case 'calendar:seed':
            $result = ['sessions_and_closures' => $store->importCalendar(Calendar::official2026())];
            break;
        case 'calendar:import':
        case 'calendar:sync':
            $sync = $cmd === 'calendar:sync';
            $from = $sync ? day($options['from'] ?? '') : null;
            $to = $sync ? day($options['to'] ?? '') : null;
            if ($sync && $from > $to) {
                throw new RuntimeException('INVALID_CALENDAR_RANGE');
            }
            $rows = [];
            foreach (csvRows(inputFile($options)) as $r) {
                if (!isset($r['session_date'],$r['open'],$r['continuous_end'],$r['close']) ||
                    !in_array($r['settles'] ?? '', ['0','1'], true) || empty($r['source'])) {
                    throw new RuntimeException('INVALID_CALENDAR_CSV');
                }
                $date = day($r['session_date']);
                if ($sync && ($date < $from || $date > $to)) {
                    continue;
                }
                if (isset($rows[$date])) {
                    throw new RuntimeException('DUPLICATE_CALENDAR_DATE');
                }
                $rows[$date] = Calendar::row($date,$r['open'] ?: null,$r['continuous_end'] ?: null,
                    $r['close'] ?: null,$r['settles'] === '1',$r['source']);
            }
            if ($sync && count($rows) !== (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1) {
                throw new RuntimeException('BLOCKED_CALENDAR_RANGE');
            }
            $dry = isset($options['dry-run']);
            $result = ['sessions_and_closures' => $store->importCalendar($rows,$dry),'dry_run'=>$dry];
            break;
        case 'universe:seed':
        case 'universe:import':
            $result = ['catalog_rows' => $store->catalog($cmd === 'universe:seed'
                ? ROOT . '/config/seed-catalog.csv' : inputFile($options))];
            break;
        case 'data:backfill':
            $result = lock($db, 'cotahist', static function () use ($store,$options,$at): array {
                $availability = isset($options['availability-file']) ? inputFile(['file'=>$options['availability-file']]) : null;
                $reconstruction = null;
                if (isset($options['reconstruct-session']) || isset($options['archive-sha256']) || isset($options['evidence-ref'])) {
                    foreach (['reconstruct-session','archive-sha256','evidence-ref'] as $key) {
                        if (!is_string($options[$key] ?? null)) {
                            throw new RuntimeException('INVALID_RECONSTRUCTION_EVIDENCE');
                        }
                    }
                    $reconstruction = ['session'=>$options['reconstruct-session'],'sha256'=>$options['archive-sha256'],
                        'reference'=>$options['evidence-ref']];
                }
                return $store->importCotahist(inputFile($options), day($options['from'] ?? '2015-01-01'), $at,$availability,
                    isset($options['dry-run']),$reconstruction);
            });
            break;
        case 'data:coverage':
            $to = day($options['to'] ?? Calendar::brazilDate($at));
            $result = $store->fixedCoverage(day($options['from'] ?? substr($to,0,4) . '-01-01'),$to,$at);
            break;
        case 'data:quotes':
            $result = $pipeline->collect($at, isset($options['sandbox']));
            break;
        case 'data:bcb':
            $result = $pipeline->bcb((int) ($options['series'] ?? 12),
                day($options['from'] ?? Calendar::brazilDate(utc('-15 days'))),
                day($options['to'] ?? Calendar::brazilDate($at)), $at, isset($options['dry-run']));
            break;
        case 'data:cvm':
            $result = importCvm($store, inputFile($options), $at);
            break;
        case 'data:observations':
            $result = importObservations($store, inputFile($options), $at);
            break;
        case 'data:news':
            $result = News::collect($store,$config,$at,isset($options['dry-run']));
            break;
        case 'season:create':
            $result = $store->season($options['name'] ?? 'season-001',
                cents($options['capital'] ?? $config->get('INITIAL_CAPITAL_BRL','1000.00')), $at);
            break;
        case 'rank:refresh':
            $result = lock($db, 'pipeline', static function () use ($store,$pipeline,$at): array {
                $cal = $store->calendar();
                $date = Calendar::brazilDate($at);
                $session = $cal->get($date);
                if (!$session || !$session['close_at'] || $session['close_at'] > $at) {
                    $session = $cal->previous($date);
                }
                if (!$session || !($next = $cal->next($session['session_date']))) {
                    throw new RuntimeException('BLOCKED_CALENDAR');
                }
                $rows = $pipeline->saveRank($pipeline->rank($session,$cal,$at),$session,$next,$at);
                return ['session' => $session['session_date'], 'items' => array_values($rows)];
            });
            break;
        case 'tick':
            $result = $pipeline->tick($at, isset($options['dry-run']));
            if (!isset($options['dry-run']) && $store->calendar()->marketState($at) === 'CONTINUOUS') {
                $result['news'] = News::collect($store,$config,$at);
            }
            if (!isset($options['dry-run']) && PredictionEvaluation::installed($store)) {
                try {
                    $result['prediction_evaluation'] = PredictionEvaluation::run($store,$at,100);
                } catch (Throwable $error) {
                    $result['prediction_evaluation'] = ['status'=>'ERROR','code'=>publicError($error)];
                }
            }
            if (!isset($options['dry-run']) && strpos($result['status'],'BLOCKED') === 0) {
                $recent = query($db,'SELECT 1 FROM orf_job_run WHERE job="TICK" AND status=? AND as_of>=? LIMIT 1',
                    [$result['status'],utc('-1 hour')])->fetchColumn();
                if (!$recent) {
                    query($db,'INSERT INTO orf_job_run(job,as_of,status,detail) VALUES(?,?,?,?)',
                        ['TICK',$at,$result['status'],$result['session'] ?? Calendar::brazilDate($at)]);
                }
            }
            break;
        case 'reconcile':
            $failed = [];
            $rows = $db->query('SELECT id,state FROM orf_account ORDER BY id')->fetchAll();
            foreach ($rows as $row) {
                if (!$store->reconcile((int) $row['id'], json_decode($row['state'],true,64,JSON_THROW_ON_ERROR))) {
                    $failed[] = (int) $row['id'];
                }
            }
            $result = ['accounts' => count($rows), 'mismatch' => $failed, 'status' => $failed ? 'ERROR' : 'OK'];
            break;
        case 'research:export':
            $result = exportResearch($store, day($options['from'] ?? '2015-01-01'), $at);
            break;
        case 'research:features':
            $result = lock($db,'features',static function () use ($pipeline,$options,$at): array {
                return $pipeline->historicalFeatures(day($options['from'] ?? ''),day($options['to'] ?? ''),$at);
            });
            break;
        case 'model:promote':
            $result = promote($store,$options['model'] ?? '',$options['evidence'] ?? '',$at);
            break;
        case 'research:intraday':
            foreach (['symbol','session','sha256','evidence-ref'] as $key) {
                if (!is_string($options[$key] ?? null)) {
                    throw new RuntimeException('INVALID_INTRADAY_EVIDENCE');
                }
            }
            $reference = trim($options['evidence-ref']);
            $symbol = $options['symbol'];
            $date = day($options['session']);
            $minutes = filter_var($options['minutes'] ?? 5,FILTER_VALIDATE_INT);
            if (!$reference || strlen($reference) > 255 || !in_array($minutes,[5,15],true) ||
                !preg_match('/^[a-f0-9]{64}$/D',$options['sha256'])) {
                throw new RuntimeException('INVALID_INTRADAY_EVIDENCE');
            }
            $calendar = $store->calendar();
            $session = $calendar->get($date);
            $settlement = $calendar->next($date,2,true);
            if (!$session || !$session['continuous_end'] || $session['continuous_end'] > $at || !$settlement) {
                throw new RuntimeException('BLOCKED_INTRADAY_CALENDAR');
            }
            $member = $store->fixedWatchlist($session['open_at'])[$symbol] ?? [];
            $id = $member['instrument_id'] ?? 0;
            $instrument = $store->instruments($date,$session['open_at'])[$id] ?? [];
            if (!eligible($instrument) || ($instrument['symbol'] ?? null) !== $symbol || empty($instrument['odd_symbol'])) {
                throw new RuntimeException('BLOCKED_INTRADAY_IDENTITY_OR_ODD_LOT');
            }
            $coverage = $store->observations('ACTION_COVERAGE',$date,$session['open_at'])[$id][$date] ?? [];
            if (($coverage['verified'] ?? false) !== true) {
                throw new RuntimeException('BLOCKED_INTRADAY_ACTION_COVERAGE');
            }
            foreach ($store->observations('CORPORATE_ACTION',$date,$at) as $events) {
                if ((string) ($events[$date]['instrument_id'] ?? '') === (string) $id) {
                    throw new RuntimeException('BLOCKED_INTRADAY_CORPORATE_ACTION');
                }
            }
            $bars = IntradayReplay::load(inputFile($options),$options['sha256'],$instrument['odd_symbol'],$instrument['isin'],
                $session,$minutes*60,$at);
            $result = IntradayReplay::simulate($bars,$session,$instrument,$settlement,$minutes*60) +
                ['session'=>$date,'symbol'=>$symbol,'provider'=>$bars[0]['provider'],'minutes'=>$minutes,
                    'archive_sha256'=>$options['sha256'],'evidence_ref'=>$reference];
            break;
        case 'research:trial':
            foreach (['strategy','threshold','atr-stop','holding','as-of','from','to'] as $required) {
                if (!is_string($options[$required] ?? null)) {
                    throw new RuntimeException('INVALID_STRATEGY_TRIAL');
                }
            }
            $holding = filter_var($options['holding'],FILTER_VALIDATE_INT);
            $cost = filter_var($options['cost-multiplier'] ?? 1,FILTER_VALIDATE_INT);
            $cutoff = timestamp($options['as-of']);
            if (!$holding || !$cost || !is_numeric($options['threshold']) || !is_numeric($options['atr-stop']) ||
                $cutoff > $at) {
                throw new RuntimeException('INVALID_STRATEGY_TRIAL');
            }
            $trial = new StrategyTrial($options['strategy'],(float) $options['threshold'],(float) $options['atr-stop'],$holding);
            $from = day($options['from']);
            $to = day($options['to']);
            if ($to >= Calendar::brazilDate($cutoff)) {
                throw new RuntimeException('BLOCKED_INCOMPLETE_TRIAL_WINDOW');
            }
            $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $result = transaction($db,static function () use ($store,$pipeline,$from,$to,$cutoff,$cost,$trial): array {
                return (new Replay($store,$pipeline))->run($from,$to,100000,$cutoff,$cost,null,true,$trial);
            });
            break;
        case 'backtest':
            if (isset($options['research-model']) && !is_string($options['research-model'])) {
                throw new RuntimeException('UNKNOWN_RESEARCH_MODEL');
            }
            if (isset($options['reconstructed-eod'])) {
                if (!isset($options['fixed-rules'])) {
                    throw new RuntimeException('RECONSTRUCTION_REQUIRES_FIXED_RULES');
                }
                $store = $store->reconstructedReplay($at);
                $pipeline = new Pipeline($store,$config);
            }
            $result = (new Replay($store,$pipeline))->run(day($options['from'] ?? ''),day($options['to'] ?? ''),
                cents($options['capital'] ?? '1000'),$at,(int) ($options['cost-multiplier'] ?? 1),$options['research-model'] ?? null,
                isset($options['fixed-rules']));
            $payload = json($result);
            query($db,'INSERT INTO orf_research_run VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE id=id',
                [hash('sha256',$payload),isset($options['reconstructed-eod']) ? 'RECONSTRUCTED_EOD' : 'BACKTEST_EOD',$at,$payload]);
            break;
        case 'research:import':
        case 'model:import':
            $raw = file_get_contents(inputFile($options), false, null, 0, 8 * 1024 * 1024 + 1);
            if (!$raw || strlen($raw) > 8 * 1024 * 1024 ||
                !preg_match('/^[a-f0-9]{64}$/D', $options['sha256'] ?? '') ||
                !hash_equals($options['sha256'], hash('sha256',$raw))) {
                throw new RuntimeException('ARTIFACT_HASH_MISMATCH');
            }
            $artifact = json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            $id = hash('sha256',$raw);
            if ($cmd === 'model:import') {
                Model::check($artifact);
                if (timestamp($artifact['trained_until']) >= $at) {
                    throw new RuntimeException('FUTURE_MODEL');
                }
                query($db, 'INSERT INTO orf_model_version VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',
                    [$id,$artifact['horizon'],'SHADOW',timestamp($artifact['trained_until']),
                        max($at,timestamp($artifact['effective_at'])),json($artifact)]);
            } else {
                $kind = ($artifact['schema'] ?? null) === 'ORFEU_FACTORY_REPORT_V1' ? 'STRATEGY_FACTORY' : 'RESEARCH';
                query($db, 'INSERT INTO orf_research_run VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE id=id',
                    [$id,$kind,$at,json($artifact)]);
            }
            $result = ['id' => $id,'status' => $cmd === 'model:import' ? 'SHADOW' : 'IMPORTED'];
            break;
        case 'report:daily':
            $result = (new ReadApi($store))->dashboard($at);
            break;
    }
    echo json($result) . PHP_EOL;
    $status = $result['status'] ?? '';
    exit(strpos($status,'BLOCKED') === 0 || $status === 'ERROR' ? 2 : 0);
} catch (Throwable $e) {
    $code = publicError($e);
    fwrite(STDERR, json(['status' => 'ERROR', 'code' => $code]) . PHP_EOL);
    exit(strpos($code,'BLOCKED') === 0 ? 2 : 1);
}
