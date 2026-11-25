<?php
declare(strict_types=1);

namespace Orfeu;

use RuntimeException;

final class PredictionEvaluation
{
    public const POLICY = 'ODD_OPEN_FIRST_VINTAGE_V1';

    public static function installed(Store $store): bool
    {
        return $store->db->query("SHOW TABLES LIKE 'orf_prediction_evaluation'")->fetchColumn() === 'orf_prediction_evaluation';
    }

    public static function label(array $bars, ?string $coverageAt, bool $hasEvent, string $at): array
    {
        if ($hasEvent) {
            return ['status'=>'UNOBSERVABLE','reason'=>'CORPORATE_ACTION_IN_WINDOW'];
        }
        if ($coverageAt === null || $coverageAt > $at || !$bars) {
            return ['status'=>'WAITING','reason'=>'MISSING_ACTION_COVERAGE_OR_BARS'];
        }
        $available = $coverageAt;
        foreach ($bars as $bar) {
            if (!$bar || $bar['available_at'] > $at || !validBar($bar)) {
                return ['status'=>'WAITING','reason'=>'MISSING_OR_INVALID_OPENING_BAR'];
            }
            $available = max($available, $bar['available_at']);
        }
        $first = reset($bars);
        $last = end($bars);
        return ['status'=>'MATURED','available_at'=>$available,
            'gross'=>(int) $last['open_px'] / (int) $first['open_px'] - 1,
            'bar_hashes'=>array_column($bars,'raw_hash')];
    }

    public static function score(array $prediction, float $gross): array
    {
        foreach (['p_up','p_profitable','mu_gross','model_cost','q10','q50','q90'] as $field) {
            $value = $prediction[$field] ?? null;
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                throw new RuntimeException('INVALID_PREDICTION_PAYLOAD');
            }
        }
        $up = (float) $prediction['p_up'];
        $profitable = (float) $prediction['p_profitable'];
        $cost = (float) $prediction['model_cost'];
        if (min($up,$profitable,$cost) < 0 || max($up,$profitable) > 1 ||
            $prediction['q10'] > $prediction['q50'] || $prediction['q50'] > $prediction['q90']) {
            throw new RuntimeException('INVALID_PREDICTION_PAYLOAD');
        }
        $brier = static function (float $p, bool $actual): float {
            return ($p - (int) $actual) ** 2;
        };
        $logLoss = static function (float $p, bool $actual): float {
            return -log(max(1e-15,$actual ? $p : 1-$p));
        };
        return ['brier_up'=>$brier($up,$gross > 0),'brier_profitable'=>$brier($profitable,$gross > $cost),
            'log_loss_up'=>$logLoss($up,$gross > 0),'log_loss_profitable'=>$logLoss($profitable,$gross > $cost),
            'direction_correct'=>(int) (($up >= .5) === ($gross > 0)),
            'absolute_error'=>abs($prediction['mu_gross'] - $gross),'zero_absolute_error'=>abs($gross),
            'interval_covered'=>(int) ($gross >= $prediction['q10'] && $gross <= $prediction['q90']),
            'net_after_model_cost'=>$gross-$cost];
    }

    public static function summary(Store $store, string $at, int $limit, int $offset): array
    {
        $columns = [];
        foreach (['brier_up','brier_profitable','log_loss_up','log_loss_profitable','direction_correct',
            'absolute_error','zero_absolute_error','interval_covered'] as $metric) {
            $columns[] = "AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(e.payload,'$.scores.$metric')) AS DECIMAL(28,15))) AS $metric";
        }
        return query($store->db, 'SELECT p.model_version,p.horizon,COUNT(*) samples,MAX(e.evaluated_at) last_evaluated_at,'
            . implode(',',$columns) . ' FROM orf_prediction_evaluation e JOIN orf_prediction p ON p.id=e.prediction_id
            WHERE e.policy=? AND e.status=? AND e.evaluated_at<=? GROUP BY p.model_version,p.horizon
            ORDER BY last_evaluated_at DESC,p.model_version,p.horizon LIMIT ' . $limit . ' OFFSET ' . $offset,
            [self::POLICY,'MATURED',$at])->fetchAll();
    }

    public static function run(Store $store, string $at, int $limit): array
    {
        $db = $store->db;
        return lock($db,'prediction_evaluation',static function () use ($db,$store,$at,$limit): array {
            $rows = query($db, 'SELECT p.*,f.payload AS feature_payload FROM orf_prediction p
                LEFT JOIN orf_feature_snapshot f ON f.instrument_id=p.instrument_id AND f.as_of=p.as_of AND f.feature_hash=p.feature_hash
                LEFT JOIN orf_prediction_evaluation e
                ON e.prediction_id=p.id AND e.policy=? WHERE p.target_end<=? AND
                (e.prediction_id IS NULL OR (e.status=? AND e.next_check_at<=?))
                ORDER BY COALESCE(e.next_check_at,p.target_end),p.id LIMIT ' . $limit,
                [self::POLICY,$at,'WAITING',$at])->fetchAll();
            $counts = ['MATURED'=>0,'WAITING'=>0,'UNOBSERVABLE'=>0];
            $calendar = $store->calendar();
            foreach ($rows as $row) {
                $result = self::evaluate($store,$calendar,$row,$at);
                $status = $result['status'];
                $next = $status === 'WAITING' ? utc($at . ' +1 day') : null;
                query($db, 'INSERT INTO orf_prediction_evaluation VALUES (?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE evaluated_at=IF(status="WAITING",VALUES(evaluated_at),evaluated_at),
                    next_check_at=IF(status="WAITING",VALUES(next_check_at),next_check_at),
                    payload=IF(status="WAITING",VALUES(payload),payload),status=IF(status="WAITING",VALUES(status),status)',
                    [$row['id'],self::POLICY,$status,$at,$next,json($result)]);
                $counts[$status]++;
            }
            return ['policy'=>self::POLICY,'processed'=>count($rows),'results'=>$counts];
        });
    }

    private static function evaluate(Store $store, Calendar $calendar, array $row, string $at): array
    {
        if (!in_array($row['horizon'],['1D','5D','20D'],true)) {
            return ['status'=>'UNOBSERVABLE','reason'=>'INVALID_HORIZON'];
        }
        $id = $row['instrument_id'];
        $feature = json_decode($row['feature_payload'] ?? 'null',true);
        $signalDate = $feature['session_date'] ?? null;
        if (!is_string($signalDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$signalDate)) {
            return ['status'=>'UNOBSERVABLE','reason'=>'FEATURE_SESSION_MISSING'];
        }
        $signal = $calendar->get($signalDate);
        $first = $calendar->next($signalDate);
        $lastDate = Calendar::brazilDate($row['target_end']);
        if (!$signal || !$first) {
            return ['status'=>'WAITING','reason'=>'CALENDAR_MISSING'];
        }
        $last = $calendar->next($first['session_date'],(int) $row['horizon']);
        if (!$last) {
            return ['status'=>'WAITING','reason'=>'CALENDAR_MISSING'];
        }
        if (!$signal['close_at'] || $row['as_of'] < $signal['close_at'] ||
            $first['open_at'] <= $row['as_of'] || $last['open_at'] !== $row['target_end']) {
            return ['status'=>'UNOBSERVABLE','reason'=>'PREDICTION_SESSION_MISMATCH'];
        }
        $firstDate = $first['session_date'];
        $coverage = null;
        $hasEvent = false;
        foreach (query($store->db, 'SELECT kind,subject,ref_date,available_at,payload FROM orf_observation
            WHERE available_at<=? AND ((kind=? AND subject=? AND ref_date>=?) OR
            (kind=? AND ref_date BETWEEN ? AND ?))',
            [$at,'ACTION_COVERAGE',(string) $id,$lastDate,'CORPORATE_ACTION',$firstDate,$lastDate]) as $obs) {
            $payload = json_decode($obs['payload'],true,64,JSON_THROW_ON_ERROR);
            if ($obs['kind'] === 'CORPORATE_ACTION') {
                $hasEvent = $hasEvent || (string) ($payload['instrument_id'] ?? '') === (string) $id;
            } elseif (($payload['verified'] ?? false) === true && ($payload['start'] ?? '9999-12-31') <= $firstDate) {
                $coverage = $coverage === null ? $obs['available_at'] : min($coverage,$obs['available_at']);
            }
        }
        $bars = [];
        foreach ($calendar->between($firstDate,$lastDate) as $session) {
            if (!$session['open_at']) {
                continue;
            }
            $bars[] = query($store->db, 'SELECT * FROM orf_bar WHERE instrument_id=? AND session_date=?
                AND market=? AND provider=? AND open_at=? AND available_at<=? ORDER BY available_at,id LIMIT 1',
                [$id,$session['session_date'],'020','B3_COTAHIST',$session['open_at'],$at])->fetch() ?: null;
        }
        $label = self::label($bars,$coverage,$hasEvent,$at);
        if ($label['status'] === 'MATURED') {
            try {
                $label['scores'] = self::score(json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR),$label['gross']);
            } catch (RuntimeException | \JsonException | \TypeError $error) {
                return ['status'=>'UNOBSERVABLE','reason'=>'INVALID_PREDICTION_PAYLOAD'];
            }
        }
        return $label;
    }
}
