<?php
declare(strict_types=1);

namespace Orfeu;

use PDO;
use RuntimeException;

final class Store
{
    public $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function migrate(bool $dry): array
    {
        return lock($this->db, 'migration', function () use ($dry): array {
            $tables = array_fill_keys($this->db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_COLUMN), true);
            $journal = 'orf_schema_migration';
            $recorded = isset($tables[$journal])
                ? $this->db->query("SELECT step, checksum FROM $journal")->fetchAll(PDO::FETCH_KEY_PAIR) : [];
            $plan = [];
            foreach (glob(ROOT . '/database/migrations/*.sql') as $file) {
                foreach (array_filter(array_map('trim', explode(';', file_get_contents($file)))) as $sql) {
                    if (!preg_match('/^CREATE TABLE (orf_[a-z_]+) \(/', $sql, $match)) {
                        throw new RuntimeException('INVALID_MIGRATION_DDL');
                    }
                    $table = $match[1];
                    $step = basename($file) . ':' . $table;
                    $hash = hash('sha256', $sql);
                    if (isset($recorded[$step])) {
                        if ($recorded[$step] !== $hash || !isset($tables[$table])) {
                            throw new RuntimeException('MIGRATION_DRIFT_' . $table);
                        }
                        continue;
                    }
                    if (isset($tables[$table])) {
                        throw new RuntimeException('MIGRATION_COLLISION_' . $table);
                    }
                    $plan[$step] = [$sql, $hash];
                }
            }
            if (!$dry && $plan) {
                if (!isset($tables[$journal])) {
                    $this->db->exec("CREATE TABLE $journal (
                        step VARCHAR(150) PRIMARY KEY, checksum CHAR(64) NOT NULL,
                        applied_at DATETIME(6) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                }
                foreach ($plan as $step => [$sql, $hash]) {
                    $this->db->exec($sql);
                    query($this->db, "INSERT INTO $journal VALUES(?,?,?)", [$step, $hash, utc()]);
                }
            }
            return ['pending_steps' => array_keys($plan), 'dry_run' => $dry];
        });
    }

    public function calendar(): Calendar
    {
        return new Calendar($this->db->query('SELECT * FROM orf_session ORDER BY session_date')->fetchAll());
    }

    public function importCalendar(array $rows): int
    {
        return transaction($this->db, function () use ($rows): int {
            foreach ($rows as $row) {
                $old = query($this->db, 'SELECT * FROM orf_session WHERE session_date=? FOR UPDATE',
                    [$row['session_date']])->fetch();
                if ($old) {
                    foreach ($row as $key => $value) {
                        if ($value !== null ? (string) $old[$key] !== (string) $value : $old[$key] !== null) {
                            throw new RuntimeException('CALENDAR_CONFLICT_' . $row['session_date']);
                        }
                    }
                    continue;
                }
                query($this->db, 'INSERT INTO orf_session VALUES(?,?,?,?,?,?)', array_values($row));
            }
            return count($rows);
        });
    }

    public function catalog(string $file): int
    {
        return transaction($this->db, function () use ($file): int {
            $count = 0;
            foreach (csvRows($file) as $r) {
                if (!preg_match('/^BR[A-Z0-9]{9}[0-9]$/D', $r['isin'] ?? '') ||
                    !preg_match('/^[A-Z0-9]{4,12}$/D', $r['symbol'] ?? '') ||
                    !preg_match('/^[A-Z0-9]{4,12}$/D', $r['issuer_key'] ?? '') ||
                    !in_array($r['asset_type'] ?? '', ['ON', 'PN'], true) ||
                    !in_array($r['status'] ?? '', ['MONITOR_ONLY', 'ACTIVE', 'SUSPENDED', 'DELISTED', 'RECONCILIATION'], true) ||
                    empty($r['source']) || empty($r['sector']) || empty($r['name']) ||
                    !ctype_digit($r['lot_size'] ?? '') || !ctype_digit($r['tick_cents'] ?? '') ||
                    (int) $r['lot_size'] < 1 || (int) $r['tick_cents'] < 1 ||
                    (!empty($r['odd_symbol']) && !preg_match('/^[A-Z0-9]{4,12}$/D', $r['odd_symbol']))) {
                    throw new RuntimeException('INVALID_CATALOG_ROW');
                }
                $at = timestamp($r['available_at']);
                $date = day($r['valid_from']);
                query($this->db, 'INSERT INTO orf_issuer(issuer_key,name,cvm_code) VALUES (?,?,?)
                    ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)', [$r['issuer_key'], $r['name'], $r['cvm_code'] ?: null]);
                $issuer = (int) $this->db->lastInsertId();
                query($this->db, 'INSERT INTO orf_instrument(issuer_id,isin,sector,asset_type,lot_size,tick_cents)
                    VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',
                    [$issuer, $r['isin'], $r['sector'], $r['asset_type'], $r['lot_size'], $r['tick_cents']]);
                $id = (int) $this->db->lastInsertId();
                $existing = query($this->db, 'SELECT issuer_id,asset_type,sector,lot_size,tick_cents
                    FROM orf_instrument WHERE id=?', [$id])->fetch();
                if ((int) $existing['issuer_id'] !== $issuer || $existing['asset_type'] !== $r['asset_type'] ||
                    $existing['sector'] !== $r['sector'] || (int) $existing['lot_size'] !== (int) $r['lot_size'] ||
                    (int) $existing['tick_cents'] !== (int) $r['tick_cents']) {
                    throw new RuntimeException('INSTRUMENT_IDENTITY_CONFLICT');
                }
                $symbol = $r['odd_symbol'] ?: null;
                $oldSymbol = query($this->db, 'SELECT symbol,odd_symbol FROM orf_symbol_history
                    WHERE instrument_id=? AND valid_from=? AND available_at=?', [$id, $date, $at])->fetch();
                $oldStatus = query($this->db, 'SELECT status,source FROM orf_eligibility_history
                    WHERE instrument_id=? AND valid_from=? AND available_at=?', [$id, $date, $at])->fetch();
                if ($oldSymbol && ($oldSymbol['symbol'] !== $r['symbol'] || $oldSymbol['odd_symbol'] !== $symbol) ||
                    $oldStatus && ($oldStatus['status'] !== $r['status'] || $oldStatus['source'] !== $r['source'])) {
                    throw new RuntimeException('CATALOG_REVISION_CONFLICT');
                }
                if (!$oldSymbol) {
                    query($this->db, 'INSERT INTO orf_symbol_history VALUES (?,?,?,?,?)', [$id, $date, $r['symbol'], $at, $symbol]);
                }
                if (!$oldStatus) {
                    query($this->db, 'INSERT INTO orf_eligibility_history VALUES (?,?,?,?,?)', [$id, $date, $at, $r['status'], $r['source']]);
                }
                $count++;
            }
            return $count;
        });
    }

    public function instruments(string $date, string $cutoff): array
    {
        return array_column(query($this->db, 'SELECT i.*,e.name,e.cvm_code,s.symbol,s.odd_symbol,h.status
            FROM orf_instrument i JOIN orf_issuer e ON e.id=i.issuer_id
            JOIN orf_symbol_history s ON s.instrument_id=i.id
            JOIN orf_eligibility_history h ON h.instrument_id=i.id
            WHERE s.valid_from<=? AND s.available_at<=? AND h.valid_from<=? AND h.available_at<=?
            AND NOT EXISTS (SELECT 1 FROM orf_symbol_history n WHERE n.instrument_id=i.id
                AND n.valid_from<=? AND n.available_at<=?
                AND (n.valid_from>s.valid_from OR n.valid_from=s.valid_from AND n.available_at>s.available_at))
            AND NOT EXISTS (SELECT 1 FROM orf_eligibility_history n WHERE n.instrument_id=i.id
                AND n.valid_from<=? AND n.available_at<=?
                AND (n.valid_from>h.valid_from OR n.valid_from=h.valid_from AND n.available_at>h.available_at))
            ORDER BY i.id', [$date, $cutoff, $date, $cutoff, $date, $cutoff, $date, $cutoff])->fetchAll(), null, 'id');
    }

    public function fundamentalQuality(array $instruments, string $date, string $asOf): array
    {
        $codes = array_values(array_unique(array_filter(array_map(static function (array $instrument) {
            return isset($instrument['cvm_code']) && ctype_digit((string) $instrument['cvm_code'])
                ? (string) $instrument['cvm_code'] : null;
        }, $instruments))));
        if (!$codes) {
            return [];
        }
        $subjects = [];
        foreach ($codes as $code) {
            foreach (['3.01', '3.05', '3.09'] as $account) {
                $subjects[] = $code . ':itr:DRE:' . $account;
            }
        }
        $placeholders = implode(',', array_fill(0, count($subjects), '?'));
        $params = array_merge($subjects, [$date, $asOf]);
        $rows = query($this->db, 'SELECT subject,available_at,payload FROM orf_observation
            WHERE kind="FUNDAMENTAL" AND subject IN (' . $placeholders . ') AND ref_date<=? AND available_at<=?
            ORDER BY available_at,id', $params)->fetchAll();
        $periods = [];
        foreach ($rows as $row) {
            $payload = json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR);
            $parts = explode(':', $row['subject']);
            $account = end($parts);
            if (!in_array($account, ['3.01', '3.05', '3.09'], true) ||
                empty($payload['period_start']) || empty($payload['period_end']) ||
                !is_int($payload['brl_cents'] ?? null)) {
                continue;
            }
            $code = $parts[0];
            $periods[$code][$payload['period_end']][$account] = [
                'value' => $payload['brl_cents'], 'start' => $payload['period_start'],
                'available_at' => $row['available_at'], 'description' => $payload['description'] ?? '',
            ];
        }
        $out = [];
        foreach ($periods as $code => $byDate) {
            krsort($byDate);
            foreach ($byDate as $end => $accounts) {
                if (!isset($accounts['3.01'], $accounts['3.05'], $accounts['3.09'])) {
                    continue;
                }
                $revenue = $accounts['3.01'];
                $ebit = $accounts['3.05'];
                $profit = $accounts['3.09'];
                if ($revenue['value'] <= 0 || $revenue['start'] !== $ebit['start'] ||
                    $revenue['start'] !== $profit['start'] || $revenue['description'] === '' ||
                    $profit['description'] === '') {
                    continue;
                }
                $days = (int) (new \DateTimeImmutable($revenue['start']))
                    ->diff(new \DateTimeImmutable($end))->days;
                $age = (int) (new \DateTimeImmutable($end))->diff(new \DateTimeImmutable($date))->days;
                $profitDescription = strtolower($profit['description']);
                if ($days < 70 || $days > 110 || $age > 180 ||
                    strpos(strtolower($revenue['description']), 'receita') === false ||
                    (strpos($profitDescription, 'resultado') === false && strpos($profitDescription, 'lucro') === false)) {
                    continue;
                }
                $out[$code] = [
                    'net_margin' => $profit['value'] / $revenue['value'],
                    'ebit_margin' => $ebit['value'] / $revenue['value'],
                    'financial_period_end' => $end,
                    'financial_available_at' => max($revenue['available_at'], $ebit['available_at'], $profit['available_at']),
                ];
                break;
            }
        }
        return $out;
    }

    public function bars(int $id, string $asOf, string $date, string $market = '010'): array
    {
        return query($this->db, 'SELECT b.* FROM orf_bar b WHERE b.instrument_id=? AND b.market=?
            AND b.session_date<=? AND b.available_at<=? AND NOT EXISTS
            (SELECT 1 FROM orf_bar n WHERE n.instrument_id=b.instrument_id AND n.market=b.market
             AND n.session_date=b.session_date AND n.available_at<=?
             AND (n.available_at>b.available_at OR n.available_at=b.available_at AND n.id>b.id))
            ORDER BY b.session_date DESC LIMIT 61', [$id, $market, $date, $asOf, $asOf])->fetchAll();
    }

    public function daily(string $date, string $asOf): array
    {
        $rows = query($this->db, 'SELECT * FROM orf_bar WHERE session_date=? AND available_at<=?
            ORDER BY available_at,id', [$date, $asOf])->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[$row['instrument_id']][$row['market']] = $row;
        }
        return $out;
    }

    public function importCotahist(string $file, string $from, string $received, ?string $availabilityFile = null): array
    {
        $calendar = $this->calendar();
        $hash = hash_file('sha256', $file);
        if (!$hash) {
            throw new RuntimeException('COTAHIST_UNAVAILABLE');
        }
        $publication = [];
        if ($availabilityFile !== null) {
            foreach (csvRows($availabilityFile) as $entry) {
                $ref = day($entry['session_date'] ?? '');
                $available = timestamp($entry['published_at'] ?? '');
                $scheduled = $calendar->get($ref);
                if (isset($publication[$ref]) || !$scheduled || !$scheduled['close_at'] ||
                    $available < $scheduled['close_at'] || $available > $received ||
                    !hash_equals($hash,$entry['archive_sha256'] ?? '') || empty($entry['source_reference'])) {
                    throw new RuntimeException('INVALID_B3_PUBLICATION_EVIDENCE');
                }
                $publication[$ref] = [$available,$entry['source_reference']];
            }
        }
        $counts = ['inserted' => 0, 'rejected' => 0, 'unmatched' => 0, 'skipped' => 0];
        $date = null;
        $catalog = [];
        $batch = [];
        $flush = function () use (&$batch, &$counts): void {
            transaction($this->db, function () use (&$batch, &$counts): void {
                foreach ($batch as $record) {
                    $counts['inserted'] += query($this->db, 'INSERT INTO orf_bar
                        (instrument_id,session_date,market,open_at,close_at,available_at,received_at,
                        open_px,high_px,low_px,close_px,volume,financial_volume,raw_hash,provider)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id', $record)->rowCount();
                }
            });
            $batch = [];
        };
        foreach (Cotahist::lines($file) as $line) {
            try {
                $row = Cotahist::parse($line);
            } catch (RuntimeException $e) {
                $counts['rejected']++;
                continue;
            }
            if (!$row || $row['session_date'] < $from) {
                $counts['skipped']++;
                continue;
            }
            if ($date !== $row['session_date']) {
                $date = $row['session_date'];
                $session = $calendar->get($date);
                $catalog = $session && $session['close_at']
                    ? array_column($this->instruments($date, $received), null, 'isin') : [];
            }
            $instrument = $catalog[$row['isin']] ?? null;
            $symbolField = $row['market'] === '010' ? 'symbol' : 'odd_symbol';
            if (!$instrument || $instrument[$symbolField] !== $row['symbol']) {
                $counts['unmatched']++;
                continue;
            }
            $row += ['open_at' => $session['open_at'], 'close_at' => $session['close_at'],
                'available_at' => $publication[$date][0] ?? $received];
            if (!validBar($row) || $row['high_px'] > 100000000) {
                $counts['rejected']++;
                continue;
            }
            $batch[] = [$instrument['id'], $date, $row['market'], $row['open_at'], $row['close_at'],
                $row['available_at'], $received, $row['open_px'], $row['high_px'], $row['low_px'], $row['close_px'],
                $row['volume'], $row['financial_volume'], $row['raw_hash'], 'B3_COTAHIST'];
            if (count($batch) >= 500) {
                $flush();
            }
        }
        $flush();
        query($this->db, 'INSERT INTO orf_raw_ingest VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE raw_hash=raw_hash',
            [$hash, 'B3_COTAHIST', $received, array_sum($counts), $counts['rejected'] + $counts['unmatched']]);
        if ($publication) {
            saveRaw('B3_AVAILABILITY',json(['archive_sha256'=>$hash,'entries'=>$publication]));
        }
        return $counts + ['raw_hash' => $hash, 'historical_availability' => $publication ? 'DOCUMENTED_PUBLICATION' : 'RECEIVED_AT_ONLY'];
    }

    public function observation(string $kind, string $subject, string $ref, string $available,
        string $source, array $payload, string $received): void
    {
        $value = json($payload);
        query($this->db, 'INSERT INTO orf_observation(kind,subject,ref_date,available_at,received_at,source,raw_hash,payload)
            VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id',
            [$kind, $subject, day($ref), timestamp($available), $received, $source,
                hash('sha256', json([$available, $source, $value])), $value]);
    }

    public function observations(string $kind, string $date, string $asOf): array
    {
        $rows = query($this->db, 'SELECT subject,ref_date,available_at,payload FROM orf_observation
            WHERE kind=? AND ref_date<=? AND available_at<=? ORDER BY available_at,id', [$kind, $date, $asOf])->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[$row['subject']][$row['ref_date']] = json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR)
                + ['available_at' => $row['available_at']];
        }
        foreach ($out as &$versions) {
            ksort($versions);
        }
        unset($versions);
        return $out;
    }

    public function season(string $name, int $capital, string $at): array
    {
        if (!preg_match('/^[a-zA-Z0-9_.-]{1,80}$/D', $name) || $capital <= 0 || $capital > 100000000) {
            throw new RuntimeException('INVALID_SEASON');
        }
        return transaction($this->db, function () use ($name, $capital, $at): array {
            query($this->db, 'INSERT INTO orf_season (name,starts_at,initial_cents,policy) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)', [$name, $at, $capital, json(PaperAccount::POLICY)]);
            $id = (int) $this->db->lastInsertId();
            $season = query($this->db, 'SELECT * FROM orf_season WHERE id=? FOR UPDATE', [$id])->fetch();
            if ((int) $season['initial_cents'] !== $capital) {
                throw new RuntimeException('SEASON_CAPITAL_IMMUTABLE');
            }
            $count = 0;
            foreach (Strategies::NAMES as $strategy => $label) {
                if (query($this->db, 'SELECT id FROM orf_account WHERE season_id=? AND strategy_key=?', [$id, $strategy])->fetchColumn()) {
                    continue;
                }
                $account = new PaperAccount($id . ':' . $strategy, PaperAccount::POLICY);
                $account->deposit($capital, $season['starts_at']);
                query($this->db, 'INSERT INTO orf_account (season_id,strategy_key,strategy_version,state) VALUES (?,?,?,?)',
                    [$id, $strategy, '1.0', json($account->state)]);
                $accountId = (int) $this->db->lastInsertId();
                $this->persistAccount($accountId, $account);
                $this->equity($accountId, $season['starts_at'], $account->mark([]));
                $count++;
            }
            return ['id' => $id, 'created_accounts' => $count];
        });
    }

    public function persistAccount(int $id, PaperAccount $account): void
    {
        foreach ($account->events as $e) {
            $d = $e['deltas'];
            query($this->db, 'INSERT INTO orf_ledger_entry (account_id,event_key,event_at,kind,cash_delta,
                payable_delta,receivable_delta,position_cost_delta,reserve_delta,pnl_delta,payload)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$id,$e['key'],$e['at'],$e['kind'],$d['cash'],$d['payable'],
                $d['receivable'],$d['cost'],$d['reserved'],$d['realized'],json($e['payload'])]);
        }
        foreach ($account->orders as $order) {
            query($this->db, 'INSERT INTO orf_order VALUES (?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE status=VALUES(status),payload=VALUES(payload)',
                [$order['id'],$id,$order['instrument_id'],$order['decision_at'],$order['eligible_at'],$order['status'],json($order)]);
        }
        foreach ($account->fills as $fill) {
            query($this->db, 'INSERT INTO orf_fill VALUES (?,?,?,?,?)',
                [$fill['id'],$fill['order_id'],$fill['filled_at'],$fill['settlement'],json($fill)]);
        }
        query($this->db, 'UPDATE orf_account SET state=? WHERE id=?', [json($account->state), $id]);
    }

    public function equity(int $id, string $at, array $mark): void
    {
        query($this->db, 'INSERT INTO orf_equity_snapshot VALUES (?,?,?,?,?)',
            [$id, $at, $mark['equity_cents'], $mark['status'], json($mark)]);
    }

    public function reconcile(int $id, array $state): bool
    {
        $totals = query($this->db, 'SELECT SUM(cash_delta) cash, SUM(reserve_delta) reserved,
            SUM(payable_delta) payable, SUM(receivable_delta) receivable, SUM(position_cost_delta) cost,
            SUM(pnl_delta) realized FROM orf_ledger_entry WHERE account_id=?', [$id])->fetch();
        foreach ($totals as $key => $amount) {
            if ($amount === null || (int) $amount !== $state[$key]) {
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
}
