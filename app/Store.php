<?php
declare(strict_types=1);

namespace Orfeu;

use PDO;
use RuntimeException;

final class Store
{
    public $db;
    private $reconstructionCutoff = null;
    public const EOD_RECONSTRUCTION = 'DAILY_ARCHIVE_NEXT_OPEN_V1';

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function reconstructedReplay(string $cutoff): self
    {
        try {
            $this->db->query('SELECT bar_id FROM orf_reconstructed_eod LIMIT 0');
        } catch (\PDOException $error) {
            if ((string) $error->getCode() === '42S02') {
                throw new RuntimeException('BLOCKED_RECONSTRUCTION_MIGRATION',0,$error);
            }
            throw $error;
        }
        $research = clone $this;
        $research->reconstructionCutoff = $cutoff;
        return $research;
    }

    public function usesReconstructedEod(): bool
    {
        return $this->reconstructionCutoff !== null;
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

    public function fixedWatchlist(string $at): array
    {
        $fixed = require ROOT . '/config/fixed-universe.php';
        $verified = query($this->db, 'SELECT symbol,instrument_id FROM orf_fixed_watchlist
            WHERE version=? AND identity_verified_at<=?', [$fixed['version'],$at])->fetchAll(PDO::FETCH_KEY_PAIR);
        $members = [];
        foreach ($fixed['members'] as $symbol => $name) {
            $members[$symbol] = ['symbol'=>$symbol,'name'=>$name,'instrument_id'=>$verified[$symbol] ?? null,
                'mandatory'=>in_array($symbol,$fixed['mandatory_symbols'],true)];
        }
        return $members;
    }

    public function seedFixedWatchlist(string $at, bool $dry): array
    {
        $fixed = require ROOT . '/config/fixed-universe.php';
        $symbols = array_keys($fixed['members']);
        if (!$dry) {
            transaction($this->db, function () use ($symbols,$fixed,$at): void {
                foreach ($symbols as $index => $symbol) {
                    query($this->db, 'INSERT INTO orf_fixed_watchlist(version,symbol,position_no,created_at)
                        VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE symbol=symbol', [$fixed['version'],$symbol,$index+1,$at]);
                }
                $actual = query($this->db, 'SELECT symbol FROM orf_fixed_watchlist WHERE version=? ORDER BY position_no',
                    [$fixed['version']])->fetchAll(PDO::FETCH_COLUMN);
                if ($actual !== $symbols) {
                    throw new RuntimeException('FIXED_WATCHLIST_CONFLICT');
                }
            });
        }
        return ['version'=>$fixed['version'],'symbols'=>$symbols,'dry_run'=>$dry];
    }

    public function fixedCoverage(string $from, string $to, string $at): array
    {
        if ($from > $to || $to > Calendar::brazilDate($at) ||
            (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days > 7305) {
            throw new RuntimeException('INVALID_COVERAGE_RANGE');
        }
        $fixed = require ROOT . '/config/fixed-universe.php';
        $watchlistExists = $this->db->query("SHOW TABLES LIKE 'orf_fixed_watchlist'")->fetchColumn() === 'orf_fixed_watchlist';
        $verified = $watchlistExists ? $this->fixedWatchlist($at) : [];
        $catalog = array_column($this->instruments($to,$at),null,'symbol');
        $calendar = $this->calendar();
        $sessions = [];
        $missingDays = [];
        for ($date = new \DateTimeImmutable($from); $date->format('Y-m-d') <= $to; $date = $date->modify('+1 day')) {
            $day = $date->format('Y-m-d');
            $row = $calendar->get($day);
            if (!$row) {
                $missingDays[] = $day;
            } elseif ($row['close_at'] !== null && $row['close_at'] <= $at) {
                $sessions[$day] = true;
            }
        }
        $items = [];
        foreach ($fixed['members'] as $symbol => $name) {
            $instrumentId = $verified[$symbol]['instrument_id'] ?? null;
            $observedId = $instrumentId ?? $catalog[$symbol]['id'] ?? null;
            $rows = $observedId === null ? [] : query($this->db, 'SELECT session_date,market,
                MIN(available_at) first_available,MAX(available_at<received_at) publication_before_receipt
                FROM orf_bar WHERE instrument_id=? AND session_date BETWEEN ? AND ? AND available_at<=? AND received_at<=?
                GROUP BY session_date,market ORDER BY session_date', [$observedId,$from,$to,$at,$at])->fetchAll();
            $regular = [];
            $fractional = [];
            $documented = 0;
            foreach ($rows as $row) {
                if (!isset($sessions[$row['session_date']])) {
                    continue;
                }
                if ($row['market'] === '010') {
                    $regular[$row['session_date']] = true;
                    $documented += (int) $row['publication_before_receipt'];
                } elseif ($row['market'] === '020') {
                    $fractional[$row['session_date']] = true;
                }
            }
            $gaps = array_keys(array_diff_key($sessions,$regular));
            $items[] = ['symbol'=>$symbol,'instrument_id'=>$observedId,'identity_verified'=>$instrumentId !== null,
                'regular_sessions'=>count($regular),'fractional_sessions'=>count($fractional),
                'regular_sessions_available_before_receipt'=>$documented,
                'known_session_coverage'=>$sessions && $observedId !== null ? count($regular)/count($sessions) : null,
                'gap_count'=>$observedId !== null ? count($gaps) : null,
                'first_gaps'=>$observedId !== null ? array_slice($gaps, 0, 10) : [],
                'status'=>$instrumentId === null ? 'PENDING_IDENTITY' : ($missingDays ? 'CALENDAR_INCOMPLETE' :
                    (!$regular ? 'NO_EOD_BARS' : ($gaps ? 'HISTORY_GAPS' : 'HISTORY_PRESENT')))];
        }
        return ['version'=>$fixed['version'],'from'=>$from,'to'=>$to,'completed_sessions'=>count($sessions),
            'missing_calendar_days'=>count($missingDays),'first_missing_days'=>array_slice($missingDays, 0, 10),
            'watchlist_schema_ready'=>$watchlistExists,'items'=>$items];
    }

    public function verifyFixedIdentity(string $symbol, int $id, string $evidence, string $at): void
    {
        $fixed = require ROOT . '/config/fixed-universe.php';
        $instrument = $this->instruments(Calendar::brazilDate($at),$at)[$id] ?? null;
        if (!isset($fixed['members'][$symbol]) || ($instrument['symbol'] ?? null) !== $symbol ||
            trim($evidence) === '' || strlen($evidence) > 255) {
            throw new RuntimeException('INVALID_WATCHLIST_IDENTITY');
        }
        $updated = query($this->db, 'UPDATE orf_fixed_watchlist SET instrument_id=?,identity_verified_at=?,evidence_ref=?
            WHERE version=? AND symbol=? AND instrument_id IS NULL',
            [$id,$at,$evidence,$fixed['version'],$symbol])->rowCount();
        if (!$updated) {
            throw new RuntimeException('WATCHLIST_UNSEEDED_OR_IDENTITY_ALREADY_VERIFIED');
        }
    }

    public function importCalendar(array $rows, bool $dry = false): int
    {
        return transaction($this->db, function () use ($rows,$dry): int {
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
                if (!$dry) {
                    query($this->db, 'INSERT INTO orf_session VALUES(?,?,?,?,?,?)', array_values($row));
                }
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
        if ($this->usesReconstructedEod()) {
            return $this->reconstructedBars($asOf,$date,$id,$market);
        }
        return query($this->db, 'SELECT b.* FROM orf_bar b WHERE b.instrument_id=? AND b.market=?
            AND b.session_date<=? AND b.available_at<=? AND NOT EXISTS
            (SELECT 1 FROM orf_bar n WHERE n.instrument_id=b.instrument_id AND n.market=b.market
             AND n.session_date=b.session_date AND n.available_at<=?
             AND (n.available_at>b.available_at OR n.available_at=b.available_at AND n.id>b.id))
            ORDER BY b.session_date DESC LIMIT 61', [$id, $market, $date, $asOf, $asOf])->fetchAll();
    }

    public function daily(string $date, string $asOf): array
    {
        $rows = $this->usesReconstructedEod() ? $this->reconstructedBars($asOf,$date) :
            query($this->db, 'SELECT * FROM orf_bar WHERE session_date=? AND available_at<=?
                ORDER BY available_at,id', [$date, $asOf])->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[$row['instrument_id']][$row['market']] = $row;
        }
        return $out;
    }

    private function reconstructedBars(string $asOf, string $date, ?int $id = null, string $market = '010'): array
    {
        $effective = 'CASE WHEN h.assumed_at IS NOT NULL AND b.available_at>? THEN h.assumed_at ELSE b.available_at END';
        $filter = $id === null ? 'b.session_date=?' : 'b.instrument_id=? AND b.market=? AND b.session_date<=?';
        $params = array_merge([$asOf,$this->reconstructionCutoff,$this->reconstructionCutoff],
            $id === null ? [$date] : [$id,$market,$date], [$asOf,$asOf]);
        $rows = query($this->db, "SELECT visible.* FROM (
            SELECT b.*,h.archive_sha256 AS reconstruction_archive,$effective AS research_available_at,
                ROW_NUMBER() OVER (PARTITION BY b.instrument_id,b.market,b.session_date
                    ORDER BY CASE WHEN b.available_at<=? THEN 0 ELSE 1 END, b.available_at DESC,b.id DESC) AS vintage_rank
            FROM orf_bar b LEFT JOIN orf_reconstructed_eod h ON h.bar_id=b.id AND h.approved_at<=?
            WHERE b.received_at<=? AND $filter AND (b.available_at<=? OR h.assumed_at<=?)
        ) visible WHERE vintage_rank=1 ORDER BY session_date DESC,instrument_id,market" . ($id === null ? '' : ' LIMIT 61'),
            array_merge([$asOf],$params))->fetchAll();
        foreach ($rows as &$row) {
            $row['recorded_available_at'] = $row['available_at'];
            $row['available_at'] = $row['research_available_at'];
            $row['availability_evidence'] = $row['available_at'] === $row['recorded_available_at']
                ? 'AS_RECORDED' : 'RECONSTRUCTED_EOD';
            unset($row['research_available_at'],$row['vintage_rank']);
        }
        unset($row);
        return $rows;
    }

    public function importCotahist(string $file, string $from, string $received, ?string $availabilityFile = null,
        bool $dry = false, ?array $reconstruction = null): array
    {
        $work = function () use ($file,$from,$received,$availabilityFile,$dry,$reconstruction): array {
            return $this->loadCotahist($file,$from,$received,$availabilityFile,$dry,$reconstruction);
        };
        return $dry ? $work() : transaction($this->db, $work);
    }

    private function loadCotahist(string $file, string $from, string $received, ?string $availabilityFile, bool $dry,
        ?array $reconstruction): array
    {
        $calendar = $this->calendar();
        $hash = hash_file('sha256', $file);
        if (!$hash) {
            throw new RuntimeException('COTAHIST_UNAVAILABLE');
        }
        $assumedAt = null;
        if ($reconstruction !== null) {
            $reference = trim($reconstruction['reference']);
            $reconstructionDate = day($reconstruction['session']);
            $session = $calendar->get($reconstructionDate);
            $next = $calendar->next($reconstructionDate);
            if ($availabilityFile !== null || $reference === '' || strlen($reference) > 255 ||
                !hash_equals($hash,$reconstruction['sha256']) || !$session || !$session['close_at'] || !$next) {
                throw new RuntimeException('INVALID_RECONSTRUCTION_EVIDENCE');
            }
            $assumedAt = utc($next['open_at'] . ' -5 minutes');
            if ($assumedAt <= $session['close_at'] || $assumedAt >= $received) {
                throw new RuntimeException('INVALID_RECONSTRUCTION_WINDOW');
            }
            if (!$dry) {
                $this->reconstructedReplay($received);
            }
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
        $counts = ['matched' => 0, 'documented_rows' => 0, 'received_only_rows' => 0, 'inserted' => 0,
            'rejected' => 0, 'unmatched' => 0, 'calendar_missing' => 0, 'market_closed' => 0, 'skipped' => 0];
        $date = null;
        $catalog = [];
        $batch = [];
        $flush = function () use (&$batch,&$counts,$dry,$assumedAt,$hash,$reconstruction,$received): void {
            if (!$dry) {
                foreach ($batch as $record) {
                    $counts['inserted'] += query($this->db, 'INSERT INTO orf_bar
                        (instrument_id,session_date,market,open_at,close_at,available_at,received_at,
                        open_px,high_px,low_px,close_px,volume,financial_volume,raw_hash,provider)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id', $record)->rowCount();
                    if ($assumedAt !== null) {
                        $this->approveReconstruction($record,$assumedAt,$received,$hash,$reconstruction['reference']);
                    }
                }
            }
            $batch = [];
        };
        foreach (Cotahist::marketRecords(Cotahist::lines($file)) as $line) {
            if ($assumedAt !== null && substr($line,2,8) !== str_replace('-','',$reconstructionDate)) {
                throw new RuntimeException('RECONSTRUCTION_REQUIRES_DAILY_ARCHIVE');
            }
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
            if (!$session || !$session['close_at']) {
                $counts[$session ? 'market_closed' : 'calendar_missing']++;
                continue;
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
            $counts['matched']++;
            $counts[isset($publication[$date]) ? 'documented_rows' : 'received_only_rows']++;
            $batch[] = [$instrument['id'], $date, $row['market'], $row['open_at'], $row['close_at'],
                $row['available_at'], $received, $row['open_px'], $row['high_px'], $row['low_px'], $row['close_px'],
                $row['volume'], $row['financial_volume'], $row['raw_hash'], 'B3_COTAHIST'];
            if (count($batch) >= 500) {
                $flush();
            }
        }
        $flush();
        if (!hash_equals($hash, (string) hash_file('sha256', $file))) {
            throw new RuntimeException('COTAHIST_CHANGED_DURING_IMPORT');
        }
        if (!$dry) {
            $rejected = $counts['rejected'] + $counts['unmatched'] + $counts['calendar_missing'] + $counts['market_closed'];
            query($this->db, 'INSERT INTO orf_raw_ingest VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE raw_hash=raw_hash',
                [$hash, 'B3_COTAHIST', $received, $counts['matched'] + $counts['skipped'] + $rejected, $rejected]);
            if ($publication) {
                saveRaw('B3_AVAILABILITY',json(['archive_sha256'=>$hash,'entries'=>$publication]));
            }
        }
        $availability = $counts['documented_rows'] ? ($counts['received_only_rows'] ? 'MIXED' : 'DOCUMENTED_PUBLICATION')
            : 'RECEIVED_AT_ONLY';
        return $counts + ['dry_run'=>$dry,'raw_hash'=>$hash,'historical_availability'=>$availability,
            'reconstruction_policy'=>$assumedAt === null ? null : self::EOD_RECONSTRUCTION,'assumed_at'=>$assumedAt];
    }

    private function approveReconstruction(array $record, string $assumedAt, string $approvedAt, string $hash,
        string $reference): void
    {
        [$id,$date,$market] = $record;
        $bar = query($this->db, 'SELECT id,available_at,received_at FROM orf_bar WHERE instrument_id=?
            AND session_date=? AND market=? AND raw_hash=?', [$id,$date,$market,$record[13]])->fetch();
        if (!$bar || $bar['available_at'] !== $bar['received_at'] || $bar['available_at'] <= $assumedAt) {
            return;
        }
        $old = query($this->db, 'SELECT bar_id,assumed_at,archive_sha256 FROM orf_reconstructed_eod
            WHERE instrument_id=? AND session_date=? AND market=?', [$id,$date,$market])->fetch();
        if ($old) {
            if ((string) $old['bar_id'] !== (string) $bar['id'] || $old['assumed_at'] !== $assumedAt ||
                $old['archive_sha256'] !== $hash) {
                throw new RuntimeException('RECONSTRUCTION_VINTAGE_CONFLICT');
            }
            return;
        }
        if (query($this->db,'SELECT 1 FROM orf_bar WHERE instrument_id=? AND session_date=? AND market=?
            AND id<>? AND received_at<=? LIMIT 1',[$id,$date,$market,$bar['id'],$bar['received_at']])->fetchColumn()) {
            throw new RuntimeException('RECONSTRUCTION_VINTAGE_CONFLICT');
        }
        query($this->db, 'INSERT INTO orf_reconstructed_eod VALUES (?,?,?,?,?,?,?,?,?)',
            [$bar['id'],$id,$date,$market,$assumedAt,$approvedAt,$hash,$reference,self::EOD_RECONSTRUCTION]);
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
        $args = [$kind,$date,$asOf];
        $cutoff = '';
        if ($this->usesReconstructedEod()) {
            $cutoff = ' AND received_at<=?';
            $args[] = $this->reconstructionCutoff;
        }
        $rows = query($this->db, 'SELECT subject,ref_date,available_at,payload FROM orf_observation
            WHERE kind=? AND ref_date<=? AND available_at<=?' . $cutoff . ' ORDER BY available_at,id', $args)->fetchAll();
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
        return PaperAccount::matchesLedger($totals,$state);
    }
}
