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
        'data:backfill','data:quotes','data:bcb','data:cvm','data:observations','season:create',
        'rank:refresh','tick','reconcile','research:features','research:export','research:import','model:import','model:promote',
        'backtest','report:daily'];
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
            $info['db'] = ['schema' => $db->query('SELECT DATABASE()')->fetchColumn(), 'version' => $db->query('SELECT VERSION()')->fetchColumn(),
                'tables' => count($db->query('SHOW FULL TABLES')->fetchAll())];
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
        case 'migrate':
            $result = $store->migrate(isset($options['dry-run']));
            break;
        case 'calendar:seed':
            $result = ['sessions_and_closures' => $store->importCalendar(Calendar::official2026())];
            break;
        case 'calendar:import':
            $rows = [];
            foreach (csvRows(inputFile($options)) as $r) {
                if (!in_array($r['settles'] ?? '', ['0','1'], true) || empty($r['source'])) {
                    throw new RuntimeException('INVALID_CALENDAR_CSV');
                }
                $rows[] = Calendar::row($r['session_date'],$r['open'] ?: null,$r['continuous_end'] ?: null,
                    $r['close'] ?: null,$r['settles'] === '1',$r['source']);
            }
            $result = ['sessions_and_closures' => $store->importCalendar($rows)];
            break;
        case 'universe:seed':
        case 'universe:import':
            $result = ['catalog_rows' => $store->catalog($cmd === 'universe:seed'
                ? ROOT . '/config/seed-catalog.csv' : inputFile($options))];
            break;
        case 'data:backfill':
            $result = lock($db, 'cotahist', static function () use ($store,$options,$at): array {
                $availability = isset($options['availability-file']) ? inputFile(['file'=>$options['availability-file']]) : null;
                return $store->importCotahist(inputFile($options), day($options['from'] ?? '2015-01-01'), $at,$availability);
            });
            break;
        case 'data:quotes':
            $result = $pipeline->collect($at, isset($options['sandbox']));
            break;
        case 'data:bcb':
            $result = $pipeline->bcb((int) ($options['series'] ?? 12),
                day($options['from'] ?? Calendar::brazilDate(utc('-15 days'))),
                day($options['to'] ?? Calendar::brazilDate($at)), $at);
            break;
        case 'data:cvm':
            $result = importCvm($store, inputFile($options), $at);
            break;
        case 'data:observations':
            $result = importObservations($store, inputFile($options), $at);
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
        case 'backtest':
            $result = (new Replay($store,$pipeline))->run(day($options['from'] ?? ''),day($options['to'] ?? ''),
                cents($options['capital'] ?? '1000'),$at,(int) ($options['cost-multiplier'] ?? 1));
            $payload = json($result);
            query($db,'INSERT INTO orf_research_run VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE id=id',
                [hash('sha256',$payload),'BACKTEST_EOD',$at,$payload]);
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
                query($db, 'INSERT INTO orf_research_run VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE id=id',
                    [$id,'RESEARCH',$at,json($artifact)]);
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
