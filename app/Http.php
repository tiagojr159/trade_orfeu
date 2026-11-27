<?php
declare(strict_types=1);

namespace Orfeu;

use RuntimeException;

final class ReadApi
{
    private $store;

    public function __construct(Store $store)
    {
        $this->store = $store;
    }

    private function decoded(array $rows): array
    {
        foreach ($rows as &$row) {
            if (isset($row['payload'])) {
                $row['details'] = json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
                unset($row['payload']);
            }
        }
        unset($row);
        return $rows;
    }

    public function dashboard(string $at): array
    {
        $db = $this->store->db;
        $date = Calendar::brazilDate($at);
        $session = $this->store->calendar()->get($date);
        $quotes = query($db, 'SELECT q.* FROM orf_quote q WHERE q.event_at<=? AND NOT EXISTS (SELECT 1 FROM orf_quote n
            WHERE n.symbol=q.symbol AND n.event_at<=? AND (n.event_at>q.event_at OR (n.event_at=q.event_at AND n.id>q.id)))
            ORDER BY q.symbol', [$at,$at])->fetchAll();
        $quotes = array_column($quotes,null,'symbol');
        $marketList = [];
        $reviews = $this->universe($at);
        $fixed = require ROOT . '/config/fixed-universe.php';
        foreach ($reviews['items'] as $member) {
            $detail = $member['details'];
            $marketList[] = ['id'=>$member['instrument_id'],'status'=>$member['status'],
                'quote'=>$quotes[$detail['symbol']] ?? null] + $detail;
        }
        foreach ($marketList as &$row) {
            if ($row['quote']) {
                $age = strtotime($at . ' UTC') - strtotime($row['quote']['event_at'] . ' UTC');
                $row['quote']['age_seconds'] = $age;
                $row['quote']['status'] = $age < 0 || $age > $fixed['max_quote_age_seconds'] ? 'STALE' : 'OBSERVED';
            }
        }
        unset($row);
        $season = $db->query('SELECT id,name,starts_at,initial_cents,paused FROM orf_season ORDER BY id DESC LIMIT 1')->fetch() ?: null;
        $accounts = $season ? $this->accounts((int) $season['id']) : [];
        $equity = $accounts ? array_sum(array_column($accounts,'equity_cents')) : null;
        $countBars = (int) $db->query('SELECT COUNT(*) FROM orf_bar')->fetchColumn();
        $cdi = $this->store->observations('MACRO',$date,$at)['CDI'] ?? [];
        $ibov = $this->store->observations('BENCHMARK',$date,$at)['IBOV'] ?? [];
        $sources = $db->query('SELECT * FROM orf_source_health ORDER BY source')->fetchAll();
        return [
            'mode' => 'PAPER_TRADING', 'as_of' => $at, 'data_status' => $countBars ? 'EOD_IMPORTED' : ($quotes ? 'QUOTES_ONLY' : 'NOT_INITIALIZED'),
            'universe_status' => $reviews['snapshot']['status'] ?? 'SEED_UNVALIDATED', 'source' => $quotes ? 'brapi.dev' : null,
            'market_status' => $this->store->calendar()->marketState($at), 'session' => $session,
            'season' => $season, 'accounts' => $accounts, 'items' => $marketList,
            'summary' => ['equity_cents' => $equity,'initial_cents' => $season ? (int) $season['initial_cents'] * count($accounts) : null,
                'accounts' => count($accounts), 'eod_bars' => $countBars,
                'fills_today' => (int) query($db,'SELECT COUNT(*) FROM orf_fill WHERE filled_at>=? AND filled_at<=?',
                    [$session['open_at'] ?? $at,$at])->fetchColumn()],
            'benchmarks' => ['IBOV' => $ibov ? ['date' => array_key_last($ibov)] + end($ibov) : null,
                'CDI' => $cdi ? ['date' => array_key_last($cdi)] + end($cdi) : null],
            'quant_intelligence' => QuantIntelligence::dashboard($this->store,$at,$countBars),
            'sources' => $sources, 'delay_seconds' => $quotes ? max(array_column($quotes,'delay_seconds')) : null,
            'model_version' => $reviews['snapshot']['model_version'] ?? null,
        ];
    }

    public function universe(string $at): array
    {
        $db = $this->store->db;
        $fixed = require ROOT . '/config/fixed-universe.php';
        $catalog = $this->store->instruments(Calendar::brazilDate($at),$at);
        $coverage = query($db, 'SELECT instrument_id,COUNT(DISTINCT session_date) AS sessions FROM orf_bar
            WHERE market=? AND available_at<=? GROUP BY instrument_id', ['010',$at])->fetchAll(\PDO::FETCH_KEY_PAIR);
        $latest = query($db, 'SELECT * FROM orf_universe_snapshot WHERE status=? AND available_at<=?
            ORDER BY as_of DESC,id DESC LIMIT 1', ['FIXED_REVIEW',$at])->fetch();
        $reviews = $latest ? array_column($this->decoded(query($db, 'SELECT instrument_id,rank_no,status,payload
            FROM orf_universe_member WHERE snapshot_id=?', [$latest['id']])->fetchAll()), null, 'instrument_id') : [];
        $items = [];
        foreach ($this->store->fixedWatchlist($at) as $symbol => $member) {
            $id = $member['instrument_id'];
            $instrument = $catalog[$id] ?? null;
            $review = $reviews[$id] ?? [];
            $status = 'PENDING_IDENTITY';
            if ($instrument && $instrument['symbol'] === $symbol) {
                $status = eligible($instrument) ? ($review['status'] ?? 'PENDING_HISTORY') : $instrument['status'];
            }
            $items[] = ['instrument_id'=>$id,'rank_no'=>$review['rank_no'] ?? null,'status'=>$status,
                'details'=>$member + ['eod_sessions'=>(int) ($coverage[$id] ?? 0),'evaluated_at'=>$latest['as_of'] ?? null]
                    + ($review['details'] ?? [])];
        }
        return ['snapshot'=>['status'=>'FIXED_WATCHLIST','version'=>$fixed['version'],
            'model_version'=>$latest['model_version'] ?? null],'items'=>$items];
    }

    public function accounts(int $season): array
    {
        return $this->decoded(query($this->store->db, 'SELECT a.id,a.strategy_key,a.strategy_version,
            s.initial_cents,e.as_of,e.equity_cents,e.status,e.payload
            FROM orf_account a JOIN orf_season s ON s.id=a.season_id
            JOIN orf_equity_snapshot e ON e.account_id=a.id AND e.as_of=(
                SELECT MAX(n.as_of) FROM orf_equity_snapshot n WHERE n.account_id=a.id)
            WHERE a.season_id=? ORDER BY e.equity_cents DESC,a.id', [$season])->fetchAll());
    }

    public function route(string $route, string $at, int $page, int $limit, ?int $account): array
    {
        $db = $this->store->db;
        $skip = ($page - 1) * $limit;
        $pagination = ['page' => $page,'limit' => $limit];
        $suffix = " LIMIT $limit OFFSET $skip";
        $items = [];
        switch ($route) {
            case 'dashboard':
                return $this->dashboard($at);
            case 'quant/intelligence':
                $barCount = (int) $db->query('SELECT COUNT(*) FROM orf_bar')->fetchColumn();
                return QuantIntelligence::dashboard($this->store,$at,$barCount);
            case 'health':
                $checkpoints = new SessionJobs($this->store);
                $riskEvents = $db->query("SHOW TABLES LIKE 'orf_risk_event'")->fetchColumn() === 'orf_risk_event'
                    ? $this->decoded(query($db, 'SELECT r.id,r.account_id,a.strategy_key,r.session_date,r.as_of,
                        r.event_type,r.severity,r.payload FROM orf_risk_event r JOIN orf_account a ON a.id=r.account_id
                        WHERE r.as_of<=? ORDER BY r.as_of DESC,r.id DESC LIMIT 30', [$at])->fetchAll()) : [];
                return ['mode' => 'PAPER_TRADING','as_of' => $at,'data_status' => 'CONNECTED',
                    'items' => $db->query('SELECT * FROM orf_source_health')->fetchAll(),
                    'eod_checkpoints'=>$checkpoints->installed() ? $checkpoints->latest() : [],
                    'jobs' => $db->query('SELECT job,as_of,status,detail FROM orf_job_run ORDER BY id DESC LIMIT 30')->fetchAll(),
                    'alerts' => $this->decoded($db->query('SELECT as_of,code,payload FROM orf_alert ORDER BY id DESC LIMIT 30')->fetchAll()),
                    'risk_events'=>$riskEvents];
            case 'predictions/latest':
                $rows = query($db, 'SELECT p.id,p.instrument_id,i.symbol,p.as_of,p.target_end,p.horizon,p.model_version,p.payload
                    FROM orf_prediction p JOIN orf_instrument i ON i.id=p.instrument_id
                    JOIN (SELECT instrument_id,horizon,MAX(id) AS id FROM orf_prediction WHERE as_of<=? GROUP BY instrument_id,horizon) latest
                    ON latest.id=p.id ORDER BY i.symbol,p.horizon', [$at])->fetchAll();
                return ['as_of'=>$at,'data_status'=>$rows ? 'AVAILABLE' : 'EMPTY','items'=>$this->decoded($rows)];
            case 'news/latest':
                if (!News::installed($this->store)) {
                    return ['as_of'=>$at,'data_status'=>'UNAVAILABLE','code'=>'BLOCKED_NEWS_MIGRATION','items'=>[]];
                }
                $news = query($db,'SELECT source,symbol,title,description,url,published_at,available_at,
                    sentiment,sentiment_label FROM orf_news WHERE available_at<=? ORDER BY published_at DESC,id DESC LIMIT 100',[$at])->fetchAll();
                return ['as_of'=>$at,'data_status'=>$news ? 'AVAILABLE' : 'EMPTY','items'=>$news];
            case 'universe/current':
                return ['as_of' => $at] + $this->universe($at);
            case 'universe/history':
                $items = $db->query('SELECT * FROM orf_universe_snapshot ORDER BY id DESC' . $suffix)->fetchAll();
                break;
            case 'accounts':
                $season = (int) $db->query('SELECT COALESCE(MAX(id),0) FROM orf_season')->fetchColumn();
                $items = array_slice($this->accounts($season), $skip, $limit);
                break;
            case 'orders':
            case 'trades':
                $where = $account ? ' WHERE o.account_id=?' : '';
                $sql = $route === 'orders' ? 'SELECT o.* FROM orf_order o' :
                    'SELECT f.*,o.account_id,o.instrument_id FROM orf_fill f JOIN orf_order o ON o.id=f.order_id';
                $items = $this->decoded(query($db,$sql . $where . ($route === 'orders' ? ' ORDER BY o.decision_at DESC,o.id' :
                    ' ORDER BY f.filled_at DESC,f.id') . $suffix, $account ? [$account] : [])->fetchAll());
                break;
            case 'matrix':
                $items = $this->decoded($db->query('SELECT a.strategy_key,d.* FROM orf_decision d
                    JOIN orf_account a ON a.id=d.account_id WHERE a.season_id=(SELECT MAX(id) FROM orf_season)
                    AND d.as_of=(SELECT MAX(n.as_of) FROM orf_decision n WHERE n.account_id=d.account_id
                    AND n.instrument_id=d.instrument_id) ORDER BY d.as_of DESC,d.account_id,d.instrument_id' . $suffix)->fetchAll());
                break;
            case 'research/runs':
                $items = $this->decoded($db->query('SELECT * FROM orf_research_run ORDER BY created_at DESC,id' . $suffix)->fetchAll());
                break;
            case 'research/models':
                $items = $db->query('SELECT id,horizon,status,trained_until,effective_at FROM orf_model_version
                    ORDER BY effective_at DESC,id' . $suffix)->fetchAll();
                break;
            case 'research/prediction-metrics':
                if (!PredictionEvaluation::installed($this->store)) {
                    return ['as_of'=>$at,'data_status'=>'UNAVAILABLE','code'=>'BLOCKED_PREDICTION_MIGRATION','items'=>[]];
                }
                $items = PredictionEvaluation::summary($this->store,$at,$limit,$skip);
                break;
            default:
                if (preg_match('~^accounts/([1-9][0-9]{0,11})/equity$~D',$route,$m)) {
                    $items = $this->decoded(query($db, 'SELECT as_of,equity_cents,status,payload FROM orf_equity_snapshot
                        WHERE account_id=? ORDER BY as_of DESC' . $suffix,[(int) $m[1]])->fetchAll());
                } elseif (preg_match('~^instruments/([1-9][0-9]{0,11})(/predictions)?$~D',$route,$m)) {
                    if (isset($m[2])) {
                        $installed = PredictionEvaluation::installed($this->store);
                        $sql = $installed
                            ? 'SELECT p.*,e.status evaluation_status,e.evaluated_at,e.payload evaluation_payload
                                FROM orf_prediction p LEFT JOIN orf_prediction_evaluation e
                                ON e.prediction_id=p.id AND e.policy=? AND e.evaluated_at<=?'
                            : 'SELECT p.* FROM orf_prediction p';
                        $params = $installed ? [PredictionEvaluation::POLICY,$at] : [];
                        $items = $this->decoded(query($db,$sql . ' WHERE p.instrument_id=? AND p.as_of<=?
                            ORDER BY p.as_of DESC,p.id' . $suffix,array_merge($params,[(int) $m[1],$at]))->fetchAll());
                        foreach ($items as &$item) {
                            $item['evaluation_status'] = $item['evaluation_status'] ?? 'WAITING';
                            $item['evaluation'] = isset($item['evaluation_payload'])
                                ? json_decode($item['evaluation_payload'],true,64,JSON_THROW_ON_ERROR) : null;
                            unset($item['evaluation_payload']);
                        }
                        unset($item);
                    } else {
                        $date = Calendar::brazilDate($at);
                        $instrument = $this->store->instruments($date,$at)[(int) $m[1]] ?? null;
                        if (!$instrument) {
                            throw new RuntimeException('NOT_FOUND');
                        }
                        return ['as_of' => $at,'instrument' => $instrument,
                            'items' => $this->store->bars((int) $m[1],$at,$date)];
                    }
                } else {
                    throw new RuntimeException('NOT_FOUND');
                }
        }
        return ['as_of' => $at,'data_status' => $items ? 'AVAILABLE' : 'EMPTY','items' => $items,'pagination' => $pagination];
    }
}
