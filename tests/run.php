<?php
declare(strict_types=1);

namespace Orfeu;

use RuntimeException;
use Throwable;

require __DIR__ . '/../app/bootstrap.php';

function demand(bool $condition, string $detail = 'ASSERTION_FAILED'): void
{
    if (!$condition) {
        throw new RuntimeException($detail);
    }
}
function fails(callable $run, string $message): void
{
    try {
        $run();
    } catch (Throwable $e) {
        demand($e->getMessage() === $message, $e->getMessage());
        return;
    }
    throw new RuntimeException('EXPECTED_' . $message);
}
function instrument(int $id = 1): array
{
    return ['id'=>$id,'issuer_id'=>$id,'asset_type'=>'ON','isin'=>'BRPETRACNOR9','symbol'=>'PETR3',
        'odd_symbol'=>'PETR3F','status'=>'ACTIVE','lot_size'=>100,'tick_cents'=>1,'sector'=>'Energia'];
}
function scenario(): array
{
    $calendar = new Calendar(Calendar::official2026());
    $signal = $calendar->get('2026-11-18');
    $next = $calendar->next($signal['session_date']);
    $row = array_replace(instrument(), ['status'=>'TRADABLE','price'=>2000,'atr'=>.01,
        'rank'=>1,'trend'=>.1,'mom20'=>.1,'z20'=>0,'rsi'=>55,'mom60'=>.15,'relative'=>.04,
        'p_profitable'=>.70,'adv_shares'=>1000000,'edge'=>.04,'vol20'=>.1]);
    $policy = array_replace(PaperAccount::POLICY,['fee_bps'=>50,'odd_penalty_bps'=>0]);
    $book = new PaperAccount('fixture',$policy);
    $book->deposit(100000,$signal['open_at']);
    return [$calendar,$signal,$next,$row,$book];
}
function buyFixture(): array
{
    [$calendar,$signal,$next,$row,$book] = scenario();
    $asOf = utc($signal['close_at'] . ' +15 minutes');
    demand($book->decide('ORFEU_AI',$row,$book->mark([]),$signal,$next,$asOf,false)[0] === 'BUY');
    $opening = [1=>['020'=>['open_at'=>$next['open_at'],'open_px'=>2000]]];
    $book->execute($next,$opening,[1=>instrument()],$calendar->next($next['session_date'],2,true));
    demand(count($book->fills) === 1);
    return [$calendar,$signal,$next,$row,$book];
}

$cases = [
    'test_database_guard'=>static function (): void {
        fails(static function (): void { new Config(['DB_DATABASE'=>'prometheus']); },'DB_NOT_ORFEU');
        new Config(['APP_ENV'=>'hostgator','DB_DATABASE'=>'conta_orfeu']);
        fails(static function (): void {
            new Config(['APP_ENV'=>'hostgator','DB_DATABASE'=>'conta_orfeu','ALLOW_LOCAL_ROOT'=>'true']);
        },'ROOT_LOCAL_ONLY');
    },
    'test_no_live_mode'=>static function (): void {
        fails(static function (): void { new Config(['PAPER_TRADING_ONLY'=>'false']); },'PAPER_MODE_REQUIRED');
    },
    'test_only_b3_common_preferred_stock'=>static function (): void {
        demand(eligible(instrument()));
        foreach (['ETF','BDR','UNIT','OPTION','FII','INDEX','CRYPTO'] as $kind) {
            demand(!eligible(array_replace(instrument(),['asset_type'=>$kind])));
        }
        demand(!eligible([]));
        demand(!eligible(array_replace(instrument(),['isin'=>'US1234567890'])));
    },
    'test_holiday_and_settlement_holiday'=>static function (): void {
        $cal = new Calendar(Calendar::official2026());
        demand($cal->get('2026-11-20')['open_at'] === null);
        demand($cal->next('2026-11-19',2,true)['session_date'] === '2026-11-24');
        demand($cal->next('2026-11-19')['session_date'] === '2026-11-23');
    },
    'test_friday_next_session_and_special_session'=>static function (): void {
        $cal = new Calendar(Calendar::official2026());
        demand($cal->next('2026-02-13')['session_date'] === '2026-02-18');
        demand($cal->get('2026-02-18')['open_at'] === '2026-02-18 16:00:00.000000');
        demand($cal->next('2026-12-30') === null);
    },
    'test_after_market_and_utc_timezone'=>static function (): void {
        $cal = new Calendar(Calendar::official2026());
        demand($cal->marketState('2026-11-23 20:54:00.000000') === 'CONTINUOUS');
        demand($cal->marketState('2026-11-23 20:55:00.000000') === 'CLOSED');
        demand($cal->marketState('2026-11-23 21:30:00.000000') === 'CLOSED');
        demand(Calendar::row('2018-12-03','10:00','17:55','18:00',true,'B3')['open_at'] === '2018-12-03 12:00:00.000000');
    },
    'test_quote_429_and_timeout'=>static function (): void {
        foreach ([429,0] as $status) {
            $client = new Brapi('dummy',static function () use ($status): string {
                throw new HttpFailure($status ? 'SOURCE_HTTP_429' : 'SOURCE_TIMEOUT',$status,42);
            });
            fails(static function () use ($client): void { $client->quotes(['PETR4'],utc()); },
                $status ? 'SOURCE_HTTP_429' : 'SOURCE_TIMEOUT');
        }
    },
    'test_quote_schema_missing_symbol_provider_delay'=>static function (): void {
        $received = timestamp('2026-11-24T13:05:00Z');
        $bad = new Brapi('',static function (): string { return '{"data":[]}'; });
        fails(static function () use ($bad,$received): void { $bad->quotes(['PETR4'],$received); },'INVALID_QUOTE_SCHEMA');
        $good = new Brapi('',static function (): string {
            return '{"results":[{"symbol":"PETR4","currency":"BRL","regularMarketTime":"2026-11-24T12:50:00Z","regularMarketPrice":32.02}]}';
        });
        $r = $good->quotes(['PETR4','VALE3'],$received);
        demand($r['missing'] === ['VALE3'] && $r['rows'][0]['delay_seconds'] === 900);
        demand($r['rows'][0]['price_cents'] === 3202);
    },
    'test_cotahist_fixed_width'=>static function (): void {
        $line = str_repeat(' ',245);
        foreach ([0=>'0120261118',12=>'PETR4',24=>'010',39=>'PN',52=>'R$',56=>'0000000002000',
            69=>'0000000002100',82=>'0000000001900',108=>'0000000002050',152=>'000000000000012345',
            170=>'000000000020000000',210=>'0000001',230=>'BRPETRACNPR6'] as $idx=>$value) {
            $line = substr_replace($line,$value,$idx,strlen($value));
        }
        $row = Cotahist::parse($line . "\r\n");
        demand($row['session_date'] === '2026-11-18' && $row['symbol'] === 'PETR4' && $row['open_px'] === 2000);
        demand($row['market'] === '010' && $row['volume'] === 12345);
        demand(Cotahist::parse(substr_replace($line,'CI',39,2)) === null);
    },
    'test_no_future_bar_and_revision_point_in_time'=>static function (): void {
        $bars = [];
        $cal = new Calendar(Calendar::official2026());
        foreach ($cal->between('2026-01-01','2026-05-20') as $session) {
            if (!$session['open_at']) {
                continue;
            }
            $px = 2000 + count($bars);
            $bars[] = $session + ['open_px'=>$px,'close_px'=>$px,'low_px'=>$px-2,'high_px'=>$px+2,
                'volume'=>1000000,'financial_volume'=>5000000000,'raw_hash'=>str_repeat('a',64),'available_at'=>$session['close_at']];
        }
        $cutoff = timestamp('2026-04-20 23:00:00');
        $expected = Features::build($bars,$cutoff);
        demand($expected !== null);
        $revision = $bars[30];
        $revision['available_at'] = utc('2026-04-22T00:00:00Z');
        $revision['close_px']++;
        $bars[] = $revision;
        foreach ($bars as &$bar) {
            if ($bar['available_at'] > $cutoff) {
                $bar['close_px'] *= 10;
            }
        }
        demand(Features::build($bars,$cutoff) === $expected);
    },
    'test_ranking_reproducible_top10_distinct_issuer'=>static function (): void {
        $rows = [];
        for ($i=1;$i<=18;$i++) {
            $rows[$i] = array_replace(instrument($i),['issuer_id'=>$i===2 ? 1 : $i,'sector'=>'Setor '.($i % 5),
                'reason'=>'OK','mu_gross'=>.02,'p_profitable'=>.66,'q10'=>-.02,'roundtrip_cost'=>.005,
                'carry_cost'=>.002,'mu_se'=>.003,'promoted'=>true]);
        }
        $result = Ranking::select($rows);
        $picks = array_filter($result,static function (array $r): bool { return $r['rank'] !== null; });
        demand(count($picks) === 10 && !isset($picks[2]));
        demand(Ranking::select(array_reverse($rows,true))[1]['rank'] === $result[1]['rank']);
    },
    'test_no_forced_buy'=>static function (): void {
        [$calendar,$signal,$next,$row,$book] = scenario();
        $row['status'] = 'MONITOR_ONLY';
        demand($book->decide('ORFEU_AI',$row,$book->mark([]),$signal,$next,$signal['close_at'],false)[0] === 'NO_TRADE');
        demand($book->state['pending'] === []);
    },
    'test_signal_candle_not_executable_and_odd_lot'=>static function (): void {
        [$calendar,$signal,$next,$row,$book] = scenario();
        demand($book->decide('ORFEU_AI',$row,$book->mark([]),$signal,$next,$signal['close_at'],false)[0] === 'BUY');
        $book->execute($signal,[1=>['020'=>['open_at'=>$signal['open_at'],'open_px'=>1990]]],
            [1=>instrument()],$calendar->next('2026-11-18',2,true));
        demand($book->fills === []);
        demand(reset($book->orders)['market'] === '020');
        $book->execute($next,[1=>['010'=>['open_at'=>$next['open_at'],'open_px'=>1990]]],
            [1=>instrument()],$calendar->next('2026-11-19',2,true));
        demand(reset($book->orders)['status'] === 'EXPIRED' && $book->state['reserved'] === 0);
    },
    'test_suspension_and_order_expiry'=>static function (): void {
        [$calendar,$signal,$next,$row,$book] = scenario();
        $book->decide('ORFEU_AI',$row,$book->mark([]),$signal,$next,$signal['close_at'],false);
        $book->execute($next,[1=>['020'=>['open_at'=>$next['open_at'],'open_px'=>1990]]],
            [1=>array_replace(instrument(),['status'=>'SUSPENDED'])],$calendar->next('2026-11-19',2,true));
        demand(!$book->fills && !$book->state['pending'] && $book->available() === 100000);
    },
    'test_cash_no_double_fill_no_leverage_and_settlement'=>static function (): void {
        [$calendar,$signal,$next,$row,$book] = buyFixture();
        $equity = $book->mark([1=>2000])['equity_cents'];
        $fill = reset($book->fills);
        demand(is_int($fill['quantity']) && $fill['quantity'] > 0);
        demand($book->state['reserved'] === 0 && $book->state['cash'] === 100000 && $book->state['payable'] > 0);
        demand($equity === 100000 - $fill['fees_cents'] && $book->available() >= 0);
        $book->execute($next,[],[1=>instrument()],$calendar->next($next['session_date'],2,true));
        demand(count($book->fills) === 1);
        $book->settle('2026-11-23','2026-11-23 13:00:00.000000');
        demand($book->state['payable'] > 0);
        $book->settle('2026-11-24','2026-11-24 13:00:00.000000');
        demand($book->state['payable'] === 0 && $book->mark([1=>2000])['equity_cents'] === $equity);
    },
    'test_exit_only_and_realized_pnl'=>static function (): void {
        [$calendar,$signal,$next,$row,$book] = buyFixture();
        $row['status'] = 'EXIT_ONLY';
        $after = $calendar->next($next['session_date']);
        $buyFill = reset($book->fills);
        $book->close([1=>2200],$next['session_date']);
        demand($book->decide('ORFEU_AI',$row,$book->mark([1=>2200]),$next,$after,$next['close_at'],false)[0] === 'SELL');
        $book->execute($after,[1=>['020'=>['open_at'=>$after['open_at'],'open_px'=>2200]]],[1=>instrument()],
            $calendar->next($after['session_date'],2,true));
        $sell = end($book->fills);
        demand($sell['side'] === 'SELL' && $sell['quantity'] === $buyFill['quantity']);
        demand($book->state['positions'] === [] && $book->state['realized'] === $sell['realized_cents']);
        demand($sell['realized_cents'] === $sell['quantity'] * 200 - $buyFill['fees_cents'] - $sell['fees_cents']);
    },
    'test_split_and_reverse_split'=>static function (): void {
        [$calendar,$signal,$next,$row,$book] = buyFixture();
        $p = $book->state['positions'][1];
        $at = $next['close_at'];
        $base = ['instrument_id'=>1,'type'=>'SPLIT'];
        $book->corporate($base+['event_key'=>'forward','numerator'=>2,'denominator'=>1],$at);
        demand($book->state['positions'][1]['quantity'] === 2*$p['quantity']);
        $book->corporate($base+['event_key'=>'reverse','numerator'=>1,'denominator'=>2],$at);
        demand($book->state['positions'][1]['quantity'] === $p['quantity'] && $book->state['positions'][1]['cost'] === $p['cost']);
    },
    'test_dividend_entitlement_payment_and_idempotency'=>static function (): void {
        [$calendar,$signal,$next,$row,$book] = buyFixture();
        $before = $book->state['cash'];
        $event = ['event_key'=>'div1','instrument_id'=>1,'type'=>'DIVIDEND','amount_cents'=>200,'payment_date'=>'2026-11-26'];
        $book->corporate($event,$next['close_at']);
        $book->corporate($event,$next['close_at']);
        $expected = $book->state['positions'][1]['quantity'] * 200;
        demand($book->state['receivable'] === $expected && $book->state['cash'] === $before);
        $mark = $book->mark([1=>1800])['equity_cents'];
        $book->settle('2026-11-26','2026-11-26 13:00:00.000000');
        demand($book->state['receivable'] === 0 && $book->mark([1=>1800])['equity_cents'] === $mark);
    },
    'test_strategy_independence'=>static function (): void {
        [$calendar,$signal,$next,$row,$book] = buyFixture();
        $other = new PaperAccount('other',PaperAccount::POLICY);
        $other->deposit(100000,$signal['open_at']);
        demand(count(Strategies::NAMES) === 15 && $other->available() === 100000 && !$other->state['positions']);
    },
    'test_money_rounding_and_secret_redaction'=>static function (): void {
        demand(cents('10.2') === 1020 && cents('1000.00') === 100000);
        fails(static function (): void { cents('10.201'); },'INVALID_MONEY');
        demand(publicError(new RuntimeException('Sensitive text with credentials')) === 'INTERNAL_ERROR');
    },
    'test_php_python_parity_model_cutoff_and_missing_model'=>static function (): void {
        $fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/linear.json'),true,64,JSON_THROW_ON_ERROR);
        Model::check($fixture['model']);
        $result = Model::predict($fixture['model'],$fixture['features'],$fixture['as_of']);
        foreach ($fixture['expected'] as $key=>$value) {
            demand(abs($result[$key] - $value) < 1e-8);
        }
        demand(Model::predict($fixture['model'],[],$fixture['as_of']) === null);
        demand(Model::predict($fixture['model'],$fixture['features'],timestamp($fixture['model']['trained_until'])) === null);
    },
    'test_admin_auth_and_csrf'=>static function (): void {
        fails(static function (): void { adminAuthorize([],''); },'ADMIN_AUTH_REQUIRED');
        $session = ['role'=>'admin','expires'=>time()+60,'csrf'=>'test'];
        fails(static function () use ($session): void { adminAuthorize($session,'wrong'); },'ADMIN_INVALID_CSRF');
        adminAuthorize($session,'test');
    },
];

$cases['test_fixed_universe_symbols'] = static function (): void {
    $fixed = require ROOT . '/config/fixed-universe.php';
    demand(array_keys($fixed['members']) === ['BBAS3','VALE3','PETR4','MGLU3','HAPV3',
        'BRKM5','PCAR3','SIMH3','MOVI3','LJQQ3']);
    demand($fixed['mandatory_symbols'] === ['BBAS3','VALE3','PETR4']);
};
$cases['test_rule_baseline_without_model_and_opening_watchlist_gate'] = static function (): void {
    [$calendar,$session,$next,$row,$book] = scenario();
    $candidate = array_replace($row, ['status'=>'EXPERIMENTAL_BASELINE','volume_ratio'=>1.2]);
    unset($candidate['p_profitable'],$candidate['edge']);
    demand($book->decide('ORFEU_AI',$candidate,$book->mark([]),$session,$next,$session['close_at'],false)[0] === 'NO_TRADE');
    demand($book->decide('MOMENTUM',$candidate,$book->mark([]),$session,$next,$session['close_at'],false)[0] === 'BUY');
    $book->execute($next,[1=>['020'=>['open_at'=>$next['open_at'],'open_px'=>2000]]],
        [1=>instrument()],$calendar->next($next['session_date'],2,true),false,[]);
    demand($book->fills === [] && $book->state['reserved'] === 0);
};

$cases['test_cotahist_requires_complete_envelope'] = static function (): void {
    $header = str_pad('00COTAHIST.2026BOVESPA 20261125',245);
    $record = str_pad('01',245);
    $trailer = str_pad('99' . substr($header,2,29) . '00000000003',245);
    demand(iterator_to_array(Cotahist::marketRecords([$header,$record,$trailer])) === [$record]);
    fails(static function () use ($header,$record): void {
        iterator_to_array(Cotahist::marketRecords([$header,$record]));
    },'COTAHIST_TRAILER_MISSING');
    fails(static function () use ($header,$record,$trailer): void {
        iterator_to_array(Cotahist::marketRecords([$header,$record,$record,$trailer]));
    },'INVALID_COTAHIST_TRAILER');
    fails(static function () use ($header,$record,$trailer): void {
        iterator_to_array(Cotahist::marketRecords([$header,$record,$trailer,$record]));
    },'INVALID_COTAHIST_ENVELOPE');
};

$cases['test_prediction_maturity_and_scoring'] = static function (): void {
    $first = ['open_px'=>1000,'low_px'=>900,'high_px'=>1100,'close_px'=>1050,'volume'=>10,
        'open_at'=>utc('2026-11-19T13:00:00Z'),'close_at'=>utc('2026-11-19T20:00:00Z'),
        'available_at'=>utc('2026-11-19T21:00:00Z'),'raw_hash'=>'first'];
    $last = array_replace($first,['open_px'=>1100,'open_at'=>utc('2026-11-23T13:00:00Z'),
        'close_at'=>utc('2026-11-23T20:00:00Z'),'available_at'=>utc('2026-11-23T21:00:00Z'),'raw_hash'=>'last']);
    $coverageAt = utc('2026-11-23T22:00:00Z');
    $bars = [$first,$last];
    demand(PredictionEvaluation::label($bars,$coverageAt,false,$last['available_at'])['status'] === 'WAITING');
    demand(PredictionEvaluation::label($bars,$coverageAt,true,$coverageAt)['status'] === 'UNOBSERVABLE');
    $label = PredictionEvaluation::label($bars,$coverageAt,false,$coverageAt);
    demand($label['status'] === 'MATURED' && $label['bar_hashes'] === ['first','last']);
    $prediction = ['p_up'=>.8,'p_profitable'=>.7,'mu_gross'=>.06,'model_cost'=>.01,'q10'=>-.01,'q50'=>.06,'q90'=>.14];
    $scores = PredictionEvaluation::score($prediction,$label['gross']);
    demand(abs($scores['brier_up']-.04) < 1e-10 && abs($scores['net_after_model_cost']-.09) < 1e-10);
    demand($scores['interval_covered'] === 1 && $scores['direction_correct'] === 1);
    fails(static function () use ($prediction): void {
        PredictionEvaluation::score(array_replace($prediction,['p_up'=>1.1]),.1);
    },'INVALID_PREDICTION_PAYLOAD');
};

$cases['test_contextual_eod_strategies_require_their_own_inputs'] = static function (): void {
    $feature = ['mom5'=>.04,'mom20'=>.1,'trend'=>.02,'volume_ratio'=>1.5,'breakout'=>true,'vol20'=>.32];
    demand(Strategies::signal('EARNINGS_DRIFT',$feature) === 'MISSING_EARNINGS_RELEASE_OR_REACTION');
    demand(Strategies::signal('DIVIDEND_QUALITY',$feature) === 'MISSING_PAYOUT_HISTORY');
    demand(Strategies::signal('MACRO_ROTATION',$feature) === 'MISSING_POINT_IN_TIME_FX_HISTORY');
    demand(Strategies::signal('META_CHAMPION',$feature) === 'SIGNAL_PASS');
    demand(Strategies::signal('META_CHAMPION',[]) === 'STRATEGY_FILTER');
    $release = ['released_at'=>'2026-11-18T23:00:00Z','evidence_ref'=>'SYNTHETIC'];
    StrategyEvidence::validate('EARNINGS_RELEASE','2026-11-18',utc('2026-11-18T23:00:00Z'),$release);
    $reaction = StrategyEvidence::earnings(['2026-11-18'=>$release],[
        ['close_at'=>utc('2026-11-19T20:00:00Z'),'close_px'=>1060],
        ['close_at'=>utc('2026-11-18T20:00:00Z'),'close_px'=>1000],
    ],.01);
    $feature['earnings_evidence'] = $reaction;
    demand(Strategies::signal('EARNINGS_DRIFT',$feature) === 'SIGNAL_PASS');
    demand(Strategies::variant('EARNINGS_DRIFT',$feature) === 'POST_EARNINGS_PRICE_DRIFT');
    fails(static function () use ($release): void {
        StrategyEvidence::validate('EARNINGS_RELEASE','2026-11-18',utc('2026-11-18T23:00:00Z'),$release +
            ['actual_eps'=>2.0,'consensus_eps'=>1.5,'consensus_at'=>'2026-11-18T23:01:00Z']);
    },'INVALID_EARNINGS_CONSENSUS');
    demand(Strategies::signal('DIVIDEND_QUALITY',$feature+['dividend_quality'=>true]) === 'SIGNAL_PASS');
    demand(Strategies::evidenceExit('DIVIDEND_QUALITY',['dividend_quality'=>false]) === 'DIVIDEND_QUALITY_DETERIORATED');
    demand(Strategies::signal('MACRO_ROTATION',$feature+['macro_return'=>.02,'macro_cost'=>.01]) === 'SIGNAL_PASS');
    demand(Strategies::signal('MACRO_ROTATION',$feature+['macro_return'=>.005,'macro_cost'=>.01]) === 'STRATEGY_FILTER');
};

$cases['test_intraday_vwap_uses_later_fractional_open_and_flattens'] = static function (): void {
    $session = Calendar::row('2026-11-19','10:00','11:30','11:35',true,'FIXTURE');
    $open = strtotime($session['open_at'] . ' UTC');
    $file = tempnam(sys_get_temp_dir(),'orfeu-vwap-');
    $rows = ["symbol,isin,market,provider,event_start,event_end,available_at,received_at,open_px,high_px,low_px,close_px,volume,turnover_cents"];
    try {
        for ($i=0; $i<18; $i++) {
            $start = utc('@' . ($open+300*$i));
            $end = utc('@' . ($open+300*($i+1)));
            $received = utc($end . ' +15 seconds');
            $close = $i >= 5 && $i <= 8 ? 1020 : 990;
            $volume = $i === 5 ? 30000 : 10000;
            $openPx = $i === 7 ? 1012 : 1000;
            $rows[] = implode(',', ['PETR3F','BRPETRACNOR9','020','FIXTURE',$start,$end,$received,$received,
                $openPx,1025,985,$close,$volume,$volume*1000]);
        }
        file_put_contents($file,implode("\n",$rows) . "\n");
        $hash = hash_file('sha256',$file);
        $at = utc('2026-11-20T21:00:00Z');
        $bars = IntradayReplay::load($file,$hash,'PETR3F','BRPETRACNOR9',$session,300,$at);
        $result = IntradayReplay::simulate($bars,$session,instrument(),['session_date'=>'2026-11-24'],300);
        demand(count($result['fills']) === 2 && $result['ledger_reconciled']);
        demand($result['session_end_status'] === 'FLAT');
        demand($result['fills'][0]['reference_cents'] === 1012);
        demand($result['fills'][0]['filled_at'] === $bars[7]['open_at']);
        demand($result['fills'][0]['filled_at'] > $result['decisions'][0]['as_of']);
        demand($result['fills'][1]['filled_at'] < $session['continuous_end']);
        array_pop($rows);
        file_put_contents($file,implode("\n",$rows) . "\n");
        fails(static function () use ($file,$session,$at): void {
            IntradayReplay::load($file,hash_file('sha256',$file),'PETR3F','BRPETRACNOR9',$session,300,$at);
        },'BLOCKED_INTRADAY_GAPS_OR_OUTSIDE_SESSION');
    } finally {
        unlink($file);
    }
};
$cases['test_integer_execution_cost_and_affordability'] = static function (): void {
    demand(ExecutionCost::price(1000,5,18,true) === 1005);
    demand(ExecutionCost::price(1000,5,18,false) === 995);
    demand(ExecutionCost::charge(1005,10,4,true) === ['fee'=>5,'cash'=>10055]);
    demand(ExecutionCost::affordable(10054,1005,4) === 9);
    fails(static function (): void { ExecutionCost::price(PHP_INT_MAX,1,18,true); },'COST_OVERFLOW');
};
$cases['test_risk_caps_exposure_and_scales_down_in_high_volatility'] = static function (): void {
    [, $signal,$next,$row,$base] = scenario();
    $limited = new PaperAccount('fixture-limited',array_replace(PaperAccount::POLICY,['max_exposure_bps'=>1]));
    $limited->deposit(100000,$signal['open_at']);
    demand($limited->decide('ORFEU_AI',$row,$limited->mark([]),$signal,$next,$signal['close_at'],false) ===
        ['NO_TRADE','AGGREGATE_RISK_OR_EXPOSURE_LIMIT']);
    $high = new PaperAccount('fixture-high',PaperAccount::POLICY);
    $high->deposit(100000,$signal['open_at']);
    demand($base->decide('ORFEU_AI',$row,$base->mark([]),$signal,$next,$signal['close_at'],false)[0] === 'BUY');
    demand($high->decide('ORFEU_AI',array_replace($row,['vol20'=>1.0]),$high->mark([]),
        $signal,$next,$signal['close_at'],false)[0] === 'BUY');
    demand(reset($high->orders)['quantity'] < reset($base->orders)['quantity']);
};
$cases['test_sector_cap_and_aggregate_risk_gate'] = static function (): void {
    [, $signal,$next,$row,$book] = scenario();
    $sameSector = array_replace($row,['id'=>2,'issuer_id'=>2]);
    demand($book->decide('ORFEU_AI',$row,$book->mark([]),$signal,$next,$signal['close_at'],false)[0] === 'BUY');
    demand($book->decide('ORFEU_AI',$sameSector,$book->mark([]),$signal,$next,$signal['close_at'],false)[0] === 'BUY');
    $reserved = array_values($book->state['pending']);
    demand($reserved[0]['sector'] === $reserved[1]['sector']);
    demand($reserved[0]['budget'] + $reserved[1]['budget'] <= 40000);
    demand($reserved[1]['budget'] <= 10000);

    $riskLimited = new PaperAccount('risk-limited',array_replace(PaperAccount::POLICY,
        ['max_total_risk_bps'=>0]));
    $riskLimited->deposit(100000,$signal['open_at']);
    demand($riskLimited->decide('ORFEU_AI',$row,$riskLimited->mark([]),$signal,$next,
        $signal['close_at'],false) === ['NO_TRADE','AGGREGATE_RISK_OR_EXPOSURE_LIMIT']);
    demand($riskLimited->state['pending'] === [] && $riskLimited->state['reserved'] === 0);
};
$cases['test_ledger_reconciliation_detects_corrupted_components'] = static function (): void {
    [, $signal,$next,$row,$book] = scenario();
    $book->decide('ORFEU_AI',$row,$book->mark([]),$signal,$next,$signal['close_at'],false);
    $keys = ['cash','reserved','payable','receivable','cost','realized'];
    $totals = array_intersect_key($book->state,array_fill_keys($keys,true));
    demand(PaperAccount::matchesLedger($totals,$book->state));
    $totals['cash']--;
    demand(!PaperAccount::matchesLedger($totals,$book->state));

    [, , , , $filled] = buyFixture();
    $totals = array_intersect_key($filled->state,array_fill_keys($keys,true));
    demand(PaperAccount::matchesLedger($totals,$filled->state));
    $filled->state['positions'][1]['cost']++;
    demand(!PaperAccount::matchesLedger($totals,$filled->state));
};
$cases['test_kill_switch_thresholds_are_configurable_and_auditable'] = static function (): void {
    $policy = array_replace(PaperAccount::POLICY,['max_portfolio_drawdown_bps'=>800,'max_daily_loss_bps'=>250]);
    $events = RiskControls::events(['status'=>'AVAILABLE','drawdown'=>-.081,'daily_return'=>-.026],false,$policy);
    demand(count($events) === 2 && $events[0]['type'] === 'MAX_PORTFOLIO_DRAWDOWN' &&
        $events[0]['severity'] === 'KILL_SWITCH' && $events[1]['type'] === 'MAX_DAILY_LOSS');
    demand(RiskControls::events(['status'=>'MISSING','drawdown'=>-.50],false,$policy) === []);
    demand(RiskControls::events(['status'=>'AVAILABLE'],true,$policy)[0]['type'] === 'SEASON_PAUSED');
    demand(RiskControls::events(['status'=>'AVAILABLE','drawdown'=>-.50],false,
        array_replace($policy,['kill_switch_enabled'=>false])) === []);
    demand(RiskControls::events(['status'=>'AVAILABLE','drawdown'=>-.11],false,
        ['max_exposure_bps'=>9000])[0]['type'] === 'MAX_PORTFOLIO_DRAWDOWN');
};

$errors = 0;
foreach ($cases as $name=>$test) {
    try {
        $test();
        echo "PASS $name\n";
    } catch (Throwable $error) {
        $errors++;
        echo "FAIL $name " . $error->getMessage() . "\n";
    }
}
echo (count($cases)-$errors) . '/' . count($cases) . " passed\n";
exit($errors > 0 ? 1 : 0);
