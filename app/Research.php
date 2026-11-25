<?php
declare(strict_types=1);

namespace Orfeu;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use ZipArchive;

function importCvm(Store $store, string $path, string $at): array
{
    $hash = hash_file('sha256',$path);
    return lock($store->db, 'cvm', static function () use ($store,$path,$at,$hash): array {
        $db = $store->db;
        if (query($db,'SELECT 1 FROM orf_raw_ingest WHERE raw_hash=?',[$hash])->fetchColumn()) {
            return ['status' => 'ALREADY_IMPORTED','observations' => 0];
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('INVALID_CVM_ZIP');
        }
        try {
            $form = null;
            $sheets = [];
            $expanded = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $name = $entry['name'];
                $expanded += $entry['size'];
                if ($expanded > 1024 * 1024 * 1024 || !preg_match('/^[a-zA-Z0-9_]+\.csv$/D',$name)) {
                    throw new RuntimeException('UNSAFE_CVM_ZIP');
                }
                if (preg_match('/^(itr|dfp)_cia_aberta_(\d{4})\.csv$/D',$name,$m)) {
                    if ($form) {
                        throw new RuntimeException('CVM_METADATA_CONFLICT');
                    }
                    $form = [$name,$m[1],$m[2]];
                }
                if (preg_match('/^(itr|dfp)_cia_aberta_(BPA|BPP|DRE|DFC_MD|DFC_MI)_con_(\d{4})\.csv$/D',$name,$m)) {
                    $sheets[$name] = $m[2];
                }
            }
            if (!$form || !$sheets) {
                throw new RuntimeException('MISSING_CVM_CONSOLIDATED_DATA');
            }
            $issuers = array_fill_keys($db->query('SELECT cvm_code FROM orf_issuer WHERE cvm_code IS NOT NULL')->fetchAll(\PDO::FETCH_COLUMN), true);
            $documents = [];
            foreach (zipCsv($zip,$form[0],$issuers) as $row) {
                $issuer = (int) ($row['CD_CVM'] ?? 0);
                if (!isset($issuers[$issuer])) {
                    continue;
                }
                $ref = day($row['DT_REFER']);
                $receipt = day($row['DT_RECEB']);
                $nextMidnight = (new DateTimeImmutable($receipt, new DateTimeZone('America/Sao_Paulo')))->modify('+1 day');
                $available = utc($nextMidnight->format(DATE_ATOM));
                if ($available <= $at) {
                    $documents[$issuer . ':' . $ref . ':' . $row['VERSAO']] = [$available,$row['ID_DOC']];
                }
            }
            return transaction($db, static function () use ($store,$db,$zip,$form,$sheets,$documents,$issuers,$at,$hash): array {
                $count = 0;
                foreach ($sheets as $name => $statement) {
                    foreach (zipCsv($zip,$name,$issuers) as $row) {
                        $issuer = (int) ($row['CD_CVM'] ?? 0);
                        $ref = $row['DT_REFER'] ?? '';
                        $version = $row['VERSAO'] ?? '';
                        $doc = $documents[$issuer . ':' . $ref . ':' . $version] ?? null;
                        if (!$doc || ($row['ORDEM_EXERC'] ?? '') !== 'ÚLTIMO' ||
                            ($row['ST_CONTA_FIXA'] ?? '') !== 'S') {
                            continue;
                        }
                        $scale = ['UNIDADE'=>1,'MIL'=>1000][$row['ESCALA_MOEDA']] ?? null;
                        if (!$scale || $row['MOEDA'] !== 'REAL' || !is_numeric($row['VL_CONTA'])) {
                            continue;
                        }
                        $value = $row['VL_CONTA'];
                        if (preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,20}))?$/D',$value,$m) !== 1) {
                            throw new RuntimeException('UNSUPPORTED_CVM_MONEY');
                        }
                        $places = $scale === 1000 ? 5 : 2;
                        $fraction = str_pad($m[3] ?? '',$places + 1,'0');
                        $cents = ((int) $m[2] * 100 * $scale + (int) substr($fraction,0,$places)
                            + ((int) $fraction[$places] >= 5 ? 1 : 0)) * ($m[1] ? -1 : 1);
                        $payload = ['brl_cents'=>$cents,'document_id'=>$doc[1],'version'=>(int) $version,
                            'description'=>$row['DS_CONTA'],'reported_scale'=>$row['ESCALA_MOEDA'],
                            'period_start'=>($row['DT_INI_EXERC'] ?? '') ?: null,'period_end'=>day($row['DT_FIM_EXERC']),
                            'availability'=>'NEXT_LOCAL_MIDNIGHT_AFTER_PROTOCOL'];
                        $store->observation('FUNDAMENTAL', "$issuer:$form[1]:$statement:" . $row['CD_CONTA'],
                            $ref,$doc[0],'CVM_' . strtoupper($form[1]),$payload,$at);
                        $count++;
                    }
                }
                query($db,'INSERT INTO orf_raw_ingest VALUES(?,?,?,?,?)',[$hash,'CVM_' . strtoupper($form[1]),$at,$count,0]);
                return ['status'=>'AVAILABLE','documents'=>count($documents),'observations'=>$count,'sha256'=>$hash];
            });
        } finally {
            $zip->close();
        }
    });
}

function zipCsv(ZipArchive $zip, string $name, array $issuers): \Generator
{
    $stream = $zip->getStream($name);
    if (!$stream) {
        throw new RuntimeException('CVM_ENTRY_UNAVAILABLE');
    }
    try {
        $header = fgets($stream,65536);
        $keys = $header === false ? [] : str_getcsv(rtrim($header,"\r\n"),';','"','');
        if (!$keys || count($keys) !== count(array_unique($keys))) {
            throw new RuntimeException('INVALID_CVM_COLUMNS');
        }
        $issuerColumn = array_search('CD_CVM',$keys,true);
        if ($issuerColumn === false) {
            throw new RuntimeException('INVALID_CVM_COLUMNS');
        }
        $record = '';
        $quotes = 0;
        while (($line = fgets($stream,65536)) !== false) {
            $record .= $line;
            $quotes += substr_count($line,'"');
            if (strlen($record) > 1024 * 1024) {
                throw new RuntimeException('CVM_RECORD_LIMIT');
            }
            if ($quotes % 2 || substr($line,-1) !== "\n" && !feof($stream)) {
                continue;
            }
            $current = rtrim($record,"\r\n");
            $record = '';
            $quotes = 0;
            if ($current === '') {
                continue;
            }
            $leading = explode(';',$current,$issuerColumn+2);
            if (isset($leading[$issuerColumn]) &&
                strpos(implode(';',array_slice($leading,0,$issuerColumn+1)),'"') === false &&
                !isset($issuers[(int) $leading[$issuerColumn]])) {
                continue;
            }
            $values = str_getcsv($current,';','"','');
            if (count($values) !== count($keys)) {
                throw new RuntimeException('INVALID_CVM_CSV');
            }
            if (!isset($issuers[(int) $values[$issuerColumn]])) {
                continue;
            }
            $values = array_map(static function (string $field): string {
                return mb_check_encoding($field,'UTF-8') ? $field : mb_convert_encoding($field,'UTF-8','Windows-1252');
            },$values);
            yield array_combine($keys,$values);
        }
        if ($record !== '' || !feof($stream)) {
            throw new RuntimeException('INCOMPLETE_CVM_CSV');
        }
    } finally {
        fclose($stream);
    }
}

function importObservations(Store $store, string $file, string $at): array
{
    return transaction($store->db, static function () use ($store,$file,$at): array {
        $count = 0;
        foreach (csvRows($file) as $row) {
            $kind = $row['kind'] ?? '';
            $subject = $row['subject'] ?? '';
            if (!in_array($kind,['BENCHMARK','NEWS','CORPORATE_ACTION','MACRO','ACTION_COVERAGE','EARNINGS_RELEASE','DIVIDEND_PROFILE'],true) ||
                !preg_match('/^[A-Za-z0-9_.:-]{1,80}$/D',$subject) ||
                !preg_match('/^[A-Za-z0-9_.-]{1,40}$/D',$row['source'] ?? '')) {
                throw new RuntimeException('INVALID_OBSERVATION');
            }
            $effective = day($row['ref_date']);
            $available = timestamp($row['available_at']);
            $payload = json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
            if (!is_array($payload) || $available > $at) {
                throw new RuntimeException('INVALID_OBSERVATION_PAYLOAD');
            }
            if ($kind === 'BENCHMARK' && ($subject !== 'IBOV' || !is_numeric($payload['value'] ?? null) || $payload['value'] <= 0)) {
                throw new RuntimeException('INVALID_BENCHMARK');
            }
            if ($kind === 'MACRO' && (!is_numeric($payload['value'] ?? null) || !is_finite((float)$payload['value']) ||
                ($subject === 'CDI' && ($payload['unit'] ?? '') !== 'PERCENT_DAY') ||
                ($subject === 'USD_BRL' && (($payload['unit'] ?? '') !== 'BRL_PER_USD' || $payload['value'] <= 0)))) {
                throw new RuntimeException('INVALID_MACRO');
            }
            if (in_array($kind,['EARNINGS_RELEASE','DIVIDEND_PROFILE'],true)) {
                if (!ctype_digit($subject) ||
                    !query($store->db,'SELECT 1 FROM orf_instrument WHERE id=?',[$subject])->fetchColumn()) {
                    throw new RuntimeException('INVALID_STRATEGY_EVIDENCE_IDENTITY');
                }
                StrategyEvidence::validate($kind,$effective,$available,$payload);
            }
            if ($kind === 'CORPORATE_ACTION') {
                $id = $payload['instrument_id'] ?? 0;
                $type = $payload['type'] ?? '';
                if (!preg_match('/^[a-f0-9]{64}$/D',$payload['event_key'] ?? '') || !is_int($id) || $id <= 0 ||
                    !in_array($type,['DIVIDEND','SPLIT'],true)) {
                    throw new RuntimeException('INVALID_CORPORATE_ACTION');
                }
                if ($type === 'DIVIDEND') {
                    if (!is_int($payload['amount_cents'] ?? null) || $payload['amount_cents'] < 1 ||
                        day($payload['payment_date'] ?? '') < $effective) {
                        throw new RuntimeException('INVALID_DIVIDEND');
                    }
                } elseif (!is_int($payload['numerator'] ?? null) || !is_int($payload['denominator'] ?? null) ||
                    min($payload['numerator'],$payload['denominator']) < 1 || max($payload['numerator'],$payload['denominator']) > 10000) {
                    throw new RuntimeException('INVALID_SPLIT');
                }
                if ($subject !== $payload['event_key'] ||
                    !query($store->db,'SELECT 1 FROM orf_instrument WHERE id=?',[$id])->fetchColumn()) {
                    throw new RuntimeException('INVALID_CORPORATE_IDENTITY');
                }
            }
            if ($kind === 'ACTION_COVERAGE' && (day($payload['start'] ?? '') > $effective ||
                !ctype_digit($subject) || ($payload['verified'] ?? false) !== true)) {
                throw new RuntimeException('INVALID_ACTION_COVERAGE');
            }
            $store->observation($kind,$subject,$effective,$available,$row['source'],$payload,$at);
            $count++;
        }
        return ['status'=>'AVAILABLE','observations'=>$count];
    });
}

function exportResearch(Store $store, string $from, string $at): array
{
    $dir = ROOT . '/storage/exports';
    if (!is_dir($dir) && !mkdir($dir,0700,true)) {
        throw new RuntimeException('EXPORT_DIRECTORY_UNAVAILABLE');
    }
    $tmp = tempnam($dir,'research-');
    $stream = fopen($tmp,'wb');
    if (!$stream) {
        throw new RuntimeException('EXPORT_UNAVAILABLE');
    }
    $db = $store->db;
    $counts = [];
    $emit = static function (string $kind, array $value) use ($stream,&$counts): void {
        $line = json(['kind'=>$kind,'data'=>$value]) . "\n";
        if (fwrite($stream,$line) !== strlen($line)) {
            throw new RuntimeException('EXPORT_WRITE_FAILED');
        }
        $counts[$kind] = ($counts[$kind] ?? 0) + 1;
    };
    try {
        transaction($db,static function () use ($db,$from,$at,$emit): void {
            $emit('manifest',['schema'=>'ORFEU_DATA_V1','as_of'=>$at,'from'=>$from,'origin'=>'ORFEU_MYSQL',
                'feature_schema'=>Features::VERSION,'cost_policy'=>PaperAccount::POLICY]);
            $queries = [
                'session'=>['SELECT * FROM orf_session WHERE session_date>=? ORDER BY session_date',[$from]],
                'instrument'=>['SELECT * FROM orf_instrument ORDER BY id',[]],
                'symbol'=>['SELECT * FROM orf_symbol_history WHERE available_at<=? ORDER BY instrument_id,valid_from,available_at',[$at]],
                'eligibility'=>['SELECT * FROM orf_eligibility_history WHERE available_at<=? ORDER BY instrument_id,valid_from,available_at',[$at]],
                'bar'=>['SELECT * FROM orf_bar WHERE session_date>=? AND available_at<=? ORDER BY session_date,instrument_id,market,id',[$from,$at]],
                'observation'=>['SELECT * FROM orf_observation WHERE ref_date>=? AND available_at<=? ORDER BY ref_date,id',[$from,$at]],
                'feature'=>['SELECT * FROM orf_feature_snapshot WHERE as_of>=? AND as_of<=? ORDER BY as_of,instrument_id,feature_hash',[$from,$at]],
            ];
            $db->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
            try {
                foreach ($queries as $kind => [$sql,$params]) {
                    $statement = query($db,$sql,$params);
                    while ($row = $statement->fetch()) {
                        $emit($kind,$row);
                    }
                    $statement->closeCursor();
                }
            } finally {
                $db->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);
            }
        });
        fclose($stream);
        $stream = null;
        $id = hash_file('sha256',$tmp);
        $dest = $dir . '/' . $id . '.jsonl';
        if (!is_file($dest) && !rename($tmp,$dest)) {
            throw new RuntimeException('EXPORT_WRITE_FAILED');
        }
        return ['status'=>'EXPORTED','file'=>'storage/exports/' . basename($dest),'sha256'=>$id,'rows'=>$counts];
    } finally {
        if ($stream) {
            fclose($stream);
        }
        if (is_file($tmp)) {
            unlink($tmp);
        }
    }
}
