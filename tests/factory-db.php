<?php
declare(strict_types=1);

namespace Orfeu;

use RuntimeException;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../app/bootstrap.php';

// Temporary tables make the synthetic replay independent of the tournament database rows.
$config = new Config();
$db = $config->connect();
$fixtureTables = [
    '001_market.sql'=>['orf_session','orf_issuer','orf_instrument','orf_symbol_history',
        'orf_eligibility_history','orf_bar','orf_observation','orf_raw_ingest'],
    '006_fixed_watchlist.sql'=>['orf_fixed_watchlist'],
    '008_reconstructed_eod.sql'=>['orf_reconstructed_eod'],
    '009_job_checkpoint.sql'=>['orf_job_checkpoint'],
    '002_research.sql'=>['orf_model_version','orf_feature_snapshot','orf_prediction','orf_universe_member','orf_universe_snapshot'],
    '003_paper.sql'=>['orf_season','orf_account','orf_ledger_entry','orf_order','orf_fill','orf_equity_snapshot',
        'orf_decision','orf_job_run','orf_alert'],
    '004_promotion.sql'=>['orf_promotion'],
];
foreach ($fixtureTables as $file => $tables) {
    $sql = file_get_contents(ROOT . '/database/migrations/' . $file);
    foreach ($tables as $table) {
        if (!preg_match('/CREATE TABLE ' . $table . ' \([^;]+/',$sql,$match)) {
            throw new RuntimeException('FIXTURE_TABLE_MISSING');
        }
        $ddl = preg_replace('/,\s*FOREIGN KEY\s*\([^)]*\) REFERENCES \w+\([^)]*\)/','',$match[0]);
        $db->exec(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl));
    }
}
$store = new Store($db);
$store->importCalendar(Calendar::official2026());
$seededAt = utc('2026-01-01T12:00:00Z');
query($db,'INSERT INTO orf_issuer VALUES (?,?,?,?)',[1,'FIXTURE','Synthetic fixture',null]);
query($db,'INSERT INTO orf_instrument VALUES (?,?,?,?,?,?,?)',[1,1,'BRPETRACNPR6','Fixture','PN',100,1]);
query($db,'INSERT INTO orf_symbol_history VALUES (?,?,?,?,?)',[1,'2026-01-01','PETR4',$seededAt,'PETR4F']);
query($db,'INSERT INTO orf_eligibility_history VALUES (?,?,?,?,?)',[1,'2026-01-01',$seededAt,'ACTIVE','FIXTURE']);
$store->seedFixedWatchlist($seededAt,false);
$store->verifyFixedIdentity('PETR4',1,'FIXTURE',$seededAt);
$sessions = array_values(array_filter(Calendar::official2026(),static function (array $row): bool {
    return $row['open_at'] !== null;
}));
foreach (array_slice($sessions,0,100) as $index => $session) {
    $date = $session['session_date'];
    $available = utc($session['close_at'] . ' +15 minutes');
    $price = 1000 + 3 * $index;
    $financial = $index % 3 === 0 ? 1600000000 : 650000000;
    foreach (['010','020'] as $market) {
        query($db,'INSERT INTO orf_bar VALUES (NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [1,$date,$market,$session['open_at'],$session['close_at'],$available,$available,
                $price,$price+35,$price-35,$price+2,1000000,$financial,hash('sha256',$date . $market),'FIXTURE']);
    }
    $store->observation('ACTION_COVERAGE','1',$date,$available,'FIXTURE',
        ['verified'=>true,'start'=>'2026-01-01'],$available);
    $store->observation('MACRO','CDI',$date,$available,'FIXTURE',
        ['value'=>.01,'unit'=>'PERCENT_DAY'],$available);
}
$replay = new Replay($store,new Pipeline($store,$config));
$trial = new StrategyTrial('MOMENTUM',1.3,2,5);
$from = $sessions[62]['session_date'];
$to = $sessions[96]['session_date'];
$at = utc($sessions[98]['close_at']);
$normal = $replay->run($from,$to,100000,$at,1,null,true,$trial);
$stress = $replay->run($from,$to,100000,$at,2,null,true,$trial);
if (!$normal['ledger_reconciled'] || !$stress['ledger_reconciled'] ||
    $normal['strategy_results']['candidate']['closed_trades'] < 1 ||
    $normal['replay_input_sha256'] !== $stress['replay_input_sha256'] ||
    $normal['strategy_results']['candidate']['total_return'] <= $stress['strategy_results']['candidate']['total_return']) {
    throw new RuntimeException('FACTORY_FIXTURE_REPLAY_FAILED');
}
echo "PASS factory replay with closed simulated trades, matching inputs and higher execution costs\n";

$archive = tempnam(sys_get_temp_dir(),'orfeu-recon-');
if ($archive === false) {
    throw new RuntimeException('FIXTURE_FILE_UNAVAILABLE');
}
try {
    $session = $sessions[101];
    $date = $session['session_date'];
    $next = $sessions[102];
    $record = str_repeat(' ',245);
    foreach ([0=>'01' . str_replace('-','',$date),12=>'PETR4',24=>'010',39=>'PN',52=>'R$',
        56=>'0000000002000',69=>'0000000002100',82=>'0000000001900',108=>'0000000002050',
        152=>'000000000000012345',170=>'000000000020000000',210=>'0000001',230=>'BRPETRACNPR6'] as $offset=>$value) {
        $record = substr_replace($record,$value,$offset,strlen($value));
    }
    $header = str_pad('00COTAHIST.2026BOVESPA ' . str_replace('-','',$date),245);
    $trailer = str_pad('99' . substr($header,2,29) . '00000000003',245);
    file_put_contents($archive,implode("\n",[$header,$record,$trailer]) . "\n");
    $received = utc($next['close_at'] . ' +2 days');
    $evidence = ['session'=>$date,'sha256'=>hash_file('sha256',$archive),'reference'=>'SYNTHETIC FIXTURE'];
    $import = $store->importCotahist($archive,$date,$received,null,false,$evidence);
    $store->importCotahist($archive,$date,$received,null,false,$evidence);
    $decision = utc($next['open_at'] . ' -1 second');
    $research = $store->reconstructedReplay($received);
    $hypothesis = $research->daily($date,$decision)[1]['010'] ?? null;
    if ($import['inserted'] !== 1 || $store->daily($date,$decision) ||
        $research->daily($date,utc($next['open_at'] . ' -6 minutes')) ||
        $store->reconstructedReplay(utc($received . ' -1 second'))->daily($date,$decision) ||
        ($hypothesis['availability_evidence'] ?? null) !== 'RECONSTRUCTED_EOD' ||
        $hypothesis['recorded_available_at'] !== $received || $hypothesis['received_at'] !== $received ||
        $research->bars(1,$decision,$date)[0]['session_date'] !== $date ||
        (int) $db->query('SELECT COUNT(*) FROM orf_reconstructed_eod')->fetchColumn() !== 1) {
        throw new RuntimeException('RECONSTRUCTION_ISOLATION_FAILED');
    }
    echo "PASS daily archive reconstruction, replay visibility, idempotency and unchanged original timestamps\n";
} finally {
    unlink($archive);
}

$season = $store->season('fixture-scheduler',100000,$seededAt);
$books = query($db,'SELECT * FROM orf_account WHERE season_id=? AND strategy_key IN (?,?) ORDER BY id',
    [$season['id'],'MOMENTUM','ORFEU_AI'])->fetchAll();
$input = $store->instruments($sessions[87]['session_date'],$sessions[87]['close_at'])[1];
$input = array_replace($input,['status'=>'TRADABLE','price'=>1200,'atr'=>.01,'trend'=>.05,'mom20'=>.05,
    'volume_ratio'=>1.7,'p_profitable'=>.8,'adv_shares'=>1000000]);
foreach ($books as $index=>$row) {
    $account = new PaperAccount($season['id'] . ':' . $row['strategy_key'],PaperAccount::POLICY,
        json_decode($row['state'],true,64,JSON_THROW_ON_ERROR));
    $previous = $sessions[87+$index];
    $target = $sessions[88+$index];
    if ($account->decide($row['strategy_key'],$input,$account->mark([]),$previous,$target,$previous['close_at'],false)[0] !== 'BUY') {
        throw new RuntimeException('SCHEDULER_FIXTURE_ORDER_FAILED');
    }
    $store->persistAccount((int) $row['id'],$account);
}
query($db,'UPDATE orf_bar SET provider=? WHERE session_date=?',['B3_COTAHIST',$sessions[88]['session_date']]);
$pipeline = new Pipeline($store,$config);
$now = utc($sessions[94]['close_at'] . ' +30 minutes');
$first = $pipeline->tick($now);
$second = $pipeline->tick(utc($now . ' +1 minute'));
$third = $pipeline->tick(utc($now . ' +2 minutes'));
$fill = json_decode($db->query('SELECT payload FROM orf_fill')->fetchColumn() ?: 'null',true);
$orders = query($db,'SELECT status FROM orf_order WHERE eligible_at<=? ORDER BY eligible_at',[$now])->fetchAll(\PDO::FETCH_COLUMN);
$verifiedStatus = query($db,'SELECT status FROM orf_job_checkpoint ORDER BY session_date')->fetchAll(\PDO::FETCH_COLUMN);
if (count($first['eod_sessions']) !== 5 || count($second['eod_sessions']) !== 2 ||
    $third['eod_sessions'] !== [] || $orders !== ['PARTIAL_EXPIRED','EXPIRED'] ||
    ($fill['late_recovery'] ?? false) !== true || $fill['filled_at'] !== $sessions[88]['open_at'] ||
    $fill['processed_at'] !== $now || $verifiedStatus !== ['GAP','GAP','GAP','GAP','GAP','GAP','DONE'] ||
    (int) query($db,'SELECT COUNT(*) FROM orf_decision WHERE as_of<?',[$now])->fetchColumn() !== 0) {
    throw new RuntimeException('SCHEDULER_RECOVERY_FIXTURE_FAILED ' . json([$orders,$fill,$verifiedStatus]));
}
foreach ($db->query('SELECT id,state FROM orf_account') as $account) {
    if (!$store->reconcile((int) $account['id'],json_decode($account['state'],true,64,JSON_THROW_ON_ERROR))) {
        throw new RuntimeException('SCHEDULER_LEDGER_FIXTURE_FAILED');
    }
}
echo "PASS scheduler bounded recovery, no retroactive decisions, one late fill and expiration without evidence\n";

$jobs = new SessionJobs($store);
$failingDate = $sessions[95]['session_date'];
for ($attempt=0; $attempt<5; $attempt++) {
    $jobs->start($failingDate,$now);
    $jobs->failed($failingDate,$now,'FIXTURE_ERROR');
}
if (($jobs->checkpoint($failingDate)['status'] ?? null) !== 'DEAD' ||
    !$jobs->retryDead($failingDate,$now) || $jobs->retryDead($failingDate,$now) ||
    (int) $jobs->checkpoint($failingDate)['failures'] !== 0) {
    throw new RuntimeException('SCHEDULER_RETRY_FIXTURE_FAILED');
}
echo "PASS scheduler repeated errors require explicit retry\n";
