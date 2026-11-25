<?php
declare(strict_types=1);

namespace Orfeu;

use RuntimeException;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../app/bootstrap.php';

// All fixture tables shadow persistent names only on this disposable connection.
$db = (new Config())->connect();
$schemas = [
    '001_market.sql'=>['orf_session','orf_bar','orf_observation'],
    '002_research.sql'=>['orf_feature_snapshot','orf_prediction'],
    '007_prediction_evaluation.sql'=>['orf_prediction_evaluation'],
];
foreach ($schemas as $file => $tables) {
    $sql = file_get_contents(ROOT . '/database/migrations/' . $file);
    foreach ($tables as $table) {
        if (!preg_match('/CREATE TABLE ' . $table . ' \([^;]+/', $sql, $match)) {
            throw new RuntimeException('FIXTURE_SCHEMA_MISSING');
        }
        $ddl = preg_replace('/,?\s*FOREIGN KEY\([^)]*\) REFERENCES \w+\([^)]*\)/','',$match[0]);
        $db->exec(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl));
    }
}
$store = new Store($db);
$store->importCalendar(Calendar::official2026());
$calendar = $store->calendar();
$signal = $calendar->get('2026-11-18');
$entry = $calendar->next($signal['session_date']);
$exit = $calendar->next($entry['session_date']);
$asOf = utc($signal['close_at'] . ' +1 hour');
$feature = json(['session_date'=>$signal['session_date']]);
$hash = hash('sha256',$feature);
$prediction = json(['p_up'=>.8,'p_profitable'=>.7,'mu_gross'=>.06,'model_cost'=>.01,'q10'=>-.01,'q50'=>.06,'q90'=>.14]);
query($db, 'INSERT INTO orf_feature_snapshot VALUES (?,?,?,?)', [1,$asOf,$hash,$feature]);
query($db, 'INSERT INTO orf_prediction(instrument_id,as_of,target_end,model_version,horizon,feature_hash,payload)
    VALUES (?,?,?,?,?,?,?)', [1,$asOf,$exit['open_at'],str_repeat('a',64),'1D',$hash,$prediction]);

function fixtureDemand(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('PREDICTION_INTEGRATION_FAILED');
    }
}

$before = utc($exit['open_at'] . ' -1 minute');
fixtureDemand(PredictionEvaluation::run($store,$before,10)['processed'] === 0);
fixtureDemand(PredictionEvaluation::run($store,$exit['open_at'],10)['results']['WAITING'] === 1);
foreach ([$entry,$exit] as $index => $session) {
    $available = utc($session['close_at'] . ' +1 hour');
    query($db, 'INSERT INTO orf_bar VALUES (NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [1,$session['session_date'],'020',$session['open_at'],$session['close_at'],$available,$available,
            1000 + 100 * $index,1200,900,1100,100,110000,str_repeat((string) $index,64),'B3_COTAHIST']);
}
$ready = utc($exit['open_at'] . ' +1 day');
$store->observation('ACTION_COVERAGE','1',$exit['session_date'],utc($exit['close_at'] . ' +1 hour'),
    'FIXTURE',['verified'=>true,'start'=>$entry['session_date']],$ready);
fixtureDemand(PredictionEvaluation::run($store,$ready,10)['results']['MATURED'] === 1);
fixtureDemand(PredictionEvaluation::run($store,$ready,10)['processed'] === 0);
$summary = PredictionEvaluation::summary($store,$ready,10,0);
fixtureDemand(count($summary) === 1 && (int) $summary[0]['samples'] === 1);
fixtureDemand(abs((float) $summary[0]['brier_up']-.04) < 1e-10);
echo "PASS prediction maturity, retry, idempotency and grouped metrics in temporary tables\n";
