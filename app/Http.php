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
        $seed = require ROOT . '/config/seeds.php';
        $catalog = $this->store->instruments($date,$at);
        $quotes = query($db, 'SELECT q.* FROM orf_quote q WHERE NOT EXISTS (SELECT 1 FROM orf_quote n
            WHERE n.symbol=q.symbol AND (n.event_at>q.event_at OR (n.event_at=q.event_at AND n.id>q.id)))
            ORDER BY q.symbol')->fetchAll();
        $quotes = array_column($quotes,null,'symbol');
        $marketList = [];
        $catalogBySymbol = array_column($catalog,null,'symbol');
        foreach ($seed as $symbol => $name) {
            $instrument = $catalogBySymbol[$symbol] ?? null;
            $marketList[] = ['symbol' => $symbol,'name' => $name,'id' => $instrument['id'] ?? null,
                'status' => 'MONITOR_ONLY','quote' => $quotes[$symbol] ?? null];
        }
        $reviews = $this->universe($at);
        if (($reviews['snapshot']['status'] ?? '') === 'MODELLED' || ($reviews['snapshot']['status'] ?? '') === 'DAILY_REVIEW') {
            $marketList = [];
            foreach ($reviews['items'] as $member) {
                if ($member['rank_no'] !== null) {
                    $detail = $member['details'];
                    $marketList[] = ['id' => $member['instrument_id'],'name' => $detail['name'],'symbol' => $detail['symbol'],
                        'status' => $member['status'],'quote' => $quotes[$detail['symbol']] ?? null] + $detail;
                }
            }
        }
        foreach ($marketList as &$row) {
            if ($row['quote']) {
                $age = strtotime($at . ' UTC') - strtotime($row['quote']['event_at'] . ' UTC');
                $row['quote']['age_seconds'] = $age;
                $row['quote']['status'] = $age > 900 ? 'STALE' : 'OBSERVED';
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
            'sources' => $sources, 'delay_seconds' => $quotes ? max(array_column($quotes,'delay_seconds')) : null,
            'model_version' => $reviews['snapshot']['model_version'] ?? null,
        ];
    }

    public function universe(string $at): array
    {
        $db = $this->store->db;
        $snapshot = query($db, 'SELECT * FROM orf_universe_snapshot WHERE available_at<=? AND effective_at<=?
            AND status<>"DAILY_REVIEW" ORDER BY effective_at DESC,id DESC LIMIT 1', [$at,$at])->fetch();
        if (!$snapshot || $snapshot['status'] === 'SEED_UNVALIDATED') {
            $seed = require ROOT . '/config/seeds.php';
            $items = [];
            foreach ($this->store->instruments(Calendar::brazilDate($at),$at) as $instrument) {
                if (isset($seed[$instrument['symbol']])) {
                    $items[] = ['instrument_id'=>$instrument['id'],'rank_no'=>null,'status'=>'MONITOR_ONLY','details'=>$instrument];
                }
            }
            return ['snapshot'=>$snapshot ?: ['status'=>'SEED_UNVALIDATED','model_version'=>null],'items'=>$items];
        }
        return ['snapshot' => $snapshot ?: null,'items' => $snapshot
            ? $this->decoded(query($db, 'SELECT instrument_id,rank_no,status,payload FROM orf_universe_member
                WHERE snapshot_id=? ORDER BY rank_no IS NULL,rank_no,instrument_id', [$snapshot['id']])->fetchAll()) : []];
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
            case 'health':
                return ['mode' => 'PAPER_TRADING','as_of' => $at,'data_status' => 'CONNECTED',
                    'items' => $db->query('SELECT * FROM orf_source_health')->fetchAll(),
                    'jobs' => $db->query('SELECT job,as_of,status,detail FROM orf_job_run ORDER BY id DESC LIMIT 30')->fetchAll(),
                    'alerts' => $this->decoded($db->query('SELECT as_of,code,payload FROM orf_alert ORDER BY id DESC LIMIT 30')->fetchAll())];
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
            default:
                if (preg_match('~^accounts/([1-9][0-9]{0,11})/equity$~D',$route,$m)) {
                    $items = $this->decoded(query($db, 'SELECT as_of,equity_cents,status,payload FROM orf_equity_snapshot
                        WHERE account_id=? ORDER BY as_of DESC' . $suffix,[(int) $m[1]])->fetchAll());
                } elseif (preg_match('~^instruments/([1-9][0-9]{0,11})(/predictions)?$~D',$route,$m)) {
                    if (isset($m[2])) {
                        $items = $this->decoded(query($db,'SELECT * FROM orf_prediction WHERE instrument_id=?
                            ORDER BY as_of DESC,id' . $suffix,[(int) $m[1]])->fetchAll());
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
