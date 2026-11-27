<?php
declare(strict_types=1);

namespace Orfeu;

use RuntimeException;

final class Features
{
    public const VERSION = 'eod-raw-v1';
    public const COLUMNS = ['mom5', 'mom20', 'mom60', 'vol20', 'trend', 'rsi', 'z20', 'relative', 'atr'];

    public static function build(array $bars, string $asOf): ?array
    {
        $known = [];
        foreach ($bars as $bar) {
            if ($bar['available_at'] > $asOf || !validBar($bar)) {
                continue;
            }
            $old = $known[$bar['session_date']] ?? null;
            if ($old === null || [$bar['available_at'], $bar['id'] ?? 0] > [$old['available_at'], $old['id'] ?? 0]) {
                $known[$bar['session_date']] = $bar;
            }
        }
        ksort($known);
        $known = array_slice(array_values($known), -61);
        if (count($known) < 61) {
            return null;
        }
        $c = array_map('intval', array_column($known, 'close_px'));
        $last = $c[60];
        $gain = $loss = $range = 0.0;
        $returns = [];
        for ($i = 1; $i <= 60; $i++) {
            $r = log($c[$i] / $c[$i - 1]);
            if (abs($r) > 0.25) {
                return null;
            }
            if ($i > 40) {
                $returns[] = $r;
            }
            if ($i > 46) {
                $gain += max(0, $c[$i] - $c[$i - 1]);
                $loss += max(0, $c[$i - 1] - $c[$i]);
                $range += max($known[$i]['high_px'], $c[$i - 1]) - min($known[$i]['low_px'], $c[$i - 1]);
            }
        }
        $mean = array_sum($returns) / 20;
        $deviation = static function (array $items, float $average): float {
            return sqrt(array_sum(array_map(static function ($value) use ($average): float {
                return ($value - $average) ** 2;
            }, $items)) / count($items));
        };
        $recent = array_slice($c, -20);
        $average = array_sum($recent) / 20;
        $std = $deviation($recent, $average);
        $adv = array_column(array_slice($known, -20), 'financial_volume');
        sort($adv, SORT_NUMERIC);
        $ema21 = $ema50 = $c[0];
        foreach ($c as $price) {
            $ema21 += 2 / 22 * ($price - $ema21);
            $ema50 += 2 / 51 * ($price - $ema50);
        }
        return [
            'version' => self::VERSION, 'session_date' => $known[60]['session_date'],
            'price' => $last, 'lineage' => hash('sha256', json(array_column($known, 'raw_hash'))),
            'mom5' => log($last / $c[55]), 'mom20' => log($last / $c[40]), 'mom60' => log($last / $c[0]),
            'vol20' => $deviation($returns, $mean) * sqrt(252), 'trend' => $ema21 / $ema50 - 1,
            'rsi' => $gain + $loss > 0 ? 100 * $gain / ($gain + $loss) : 50.0,
            'z20' => $std > 0 ? ($last - $average) / $std : 0.0,
            'atr' => $range / 14 / $last,
            'breakout' => $last > max(array_column(array_slice($known, -21, 20), 'high_px')),
            'volume_ratio' => $adv[9] + $adv[10] > 0 ? $known[60]['financial_volume'] * 2 / ($adv[9] + $adv[10]) : null,
            'adv_cents' => ($adv[9] + $adv[10]) / 2,
            'adv_shares' => (int) (array_sum(array_column(array_slice($known, -20), 'volume')) / 20),
        ];
    }
}

final class Model
{
    public static function check(array $m): void
    {
        if (($m['schema'] ?? '') !== Features::VERSION || ($m['columns'] ?? []) !== Features::COLUMNS ||
            !in_array($m['horizon'] ?? '', ['1D', '5D', '20D'], true) ||
            timestamp($m['trained_until'] ?? '') > timestamp($m['effective_at'] ?? '')) {
            throw new RuntimeException('INVALID_MODEL_SCHEMA');
        }
        foreach (['mean', 'scale', 'up', 'profitable', 'return'] as $key) {
            if (count($m[$key] ?? []) !== count(Features::COLUMNS) || array_values($m[$key]) !== $m[$key]) {
                throw new RuntimeException('INVALID_MODEL_COEFFICIENTS');
            }
            foreach ($m[$key] as $value) {
                self::number($value);
                if ($key === 'scale' && $value <= 0) {
                    throw new RuntimeException('INVALID_MODEL_SCALE');
                }
            }
        }
        foreach (['up_bias', 'profitable_bias', 'return_bias', 'up_a', 'up_b', 'profitable_a', 'profitable_b',
            'q10', 'q50', 'q90', 'mu_se', 'cost'] as $key) {
            self::number($m[$key] ?? null);
        }
        if ($m['q10'] > $m['q50'] || $m['q50'] > $m['q90'] || min($m['mu_se'], $m['cost']) < 0) {
            throw new RuntimeException('INVALID_MODEL_DISTRIBUTION');
        }
    }

    private static function number($value): float
    {
        if ((!is_float($value) && !is_int($value)) || !is_finite((float) $value)) {
            throw new RuntimeException('INVALID_MODEL_NUMBER');
        }
        return (float) $value;
    }

    public static function predict(array $m, array $features, string $asOf): ?array
    {
        if (timestamp($m['trained_until']) >= $asOf || timestamp($m['effective_at']) > $asOf) {
            return null;
        }
        $values = [];
        foreach (Features::COLUMNS as $i => $col) {
            if (!isset($features[$col])) {
                return null;
            }
            $values[] = (self::number($features[$col]) - $m['mean'][$i]) / $m['scale'][$i];
        }
        $dot = static function (string $key) use ($m, $values): float {
            $score = $m[$key . '_bias'];
            foreach ($values as $i => $value) {
                $score += $value * $m[$key][$i];
            }
            return self::number($score);
        };
        $prob = static function (string $key) use ($dot, $m): float {
            $z = self::number($m[$key . '_a'] * $dot($key) + $m[$key . '_b']);
            return 1 / (1 + exp(-max(-35, min(35, $z))));
        };
        $mu = $dot('return');
        return [
            'p_up' => $prob('up'), 'p_profitable' => $prob('profitable'), 'mu_gross' => $mu,
            'q10' => $mu + $m['q10'], 'q50' => $mu + $m['q50'], 'q90' => $mu + $m['q90'],
            'mu_se' => $m['mu_se'], 'model_cost' => $m['cost'],
        ];
    }
}

final class Ranking
{
    public static function select(array $rows, array $prior = []): array
    {
        $out = [];
        $ordered = [];
        foreach ($rows as $id => $row) {
            $out[$id] = ['status' => 'REJECTED', 'reason' => $row['reason'] ?? 'MISSING_MODEL', 'rank' => null, 'catalog_status' => $row['status']] + $row;
            if (!eligible($row) || $row['reason'] !== 'OK') {
                continue;
            }
            $edge = $row['mu_gross'] - $row['roundtrip_cost'] - $row['carry_cost'];
            $tail = $row['q10'] - $row['roundtrip_cost'] - $row['carry_cost'];
            $score = $edge - .25 * max(0, -$tail) - .5 * $row['mu_se'] -
                (isset($prior[$id]) ? 0 : $row['roundtrip_cost'] / 2 + .001);
            $allowed = $row['promoted'] && $edge - .5 * $row['mu_se'] > 0 &&
                $row['p_profitable'] >= .55 && $tail >= -.08;
            $out[$id]['edge'] = $edge;
            $out[$id]['score'] = $score;
            $out[$id]['reason'] = $allowed ? 'EDGE_VALID' : 'SHADOW_OR_INSUFFICIENT_EDGE';
            $ordered[$id] = $score;
        }
        uksort($ordered, static function ($a, $b) use ($ordered): int {
            return ($ordered[$b] <=> $ordered[$a]) ?: ($a <=> $b);
        });
        $issuers = $sectors = [];
        $rank = 0;
        foreach ($ordered as $id => $score) {
            $row = &$out[$id];
            if ($rank >= 10 || isset($issuers[$row['issuer_id']]) || ($sectors[$row['sector']] ?? 0) >= 3) {
                $row['reason'] = 'DIVERSIFICATION_OR_TOP_LIMIT';
                unset($row);
                continue;
            }
            $row['rank'] = ++$rank;
            $row['status'] = $row['reason'] === 'EDGE_VALID' ? 'TRADABLE' : 'MONITOR_ONLY';
            $issuers[$row['issuer_id']] = true;
            $sectors[$row['sector']] = ($sectors[$row['sector']] ?? 0) + 1;
            unset($row);
        }
        return $out;
    }
}

/** Research catalogue and data readiness for the quant-intelligence dashboard. */
final class QuantIntelligence
{
    public static function dashboard(Store $store, string $at, int $barCount): array
    {
        $db = $store->db;
        $definitions = [
            ['key'=>'DYNAMIC_PAIRS_LONG_ONLY','name'=>'Dynamic Pairs Long-Only','mode'=>'ANALYTICAL_AND_LONG_ONLY_RESEARCH',
                'requirements'=>['252 sessões sincronizadas','statsmodels','histórico point-in-time e FDR'],
                'restriction'=>'Compra apenas a perna subavaliada dentro do universo fixo; exposição direcional.'],
            ['key'=>'ML_STATISTICAL_ARBITRAGE','name'=>'ML Statistical Arbitrage','mode'=>'ANALYTICAL_ONLY',
                'requirements'=>['labels maduros','validação temporal purgada','amostra OOS'],
                'restriction'=>'Sem novas ordens; classificadores precisam superar o baseline fora da amostra.'],
            ['key'=>'MULTIFACTOR_LONG_ONLY','name'=>'Multifactor Long-Only','mode'=>'LONG_ONLY_RESEARCH',
                'requirements'=>['fundamentos CVM com data de publicação','cobertura setorial','histórico de mercado'],
                'restriction'=>'Compra somente; sinal Short apenas informativo.'],
            ['key'=>'ADAPTIVE_MOMENTUM','name'=>'Adaptive Momentum','mode'=>'LONG_ONLY_RESEARCH',
                'requirements'=>['253 sessões por ativo','benchmark histórico','custos'],
                'restriction'=>'Rotação dinâmica permanece desabilitada.'],
            ['key'=>'MEAN_REVERSION','name'=>'Mean Reversion','mode'=>'LONG_ONLY_RESEARCH',
                'requirements'=>['61 sessões','liquidez','eventos corporativos','filtro de tendência'],
                'restriction'=>'Queda isolada não cria entrada.'],
            ['key'=>'QUANTITATIVE_SWING','name'=>'Quantitative Swing Trading','mode'=>'LONG_ONLY_RESEARCH',
                'requirements'=>['histórico EOD ou intraday','custos','walk-forward'],
                'restriction'=>'Stops e dimensionamento existentes não substituem validação OOS.'],
            ['key'=>'CASH_CARRY','name'=>'Cash and Carry','mode'=>'OBSERVATION_ONLY',
                'requirements'=>['contrato futuro','curva CDI/Selic','proventos e custos'],
                'restriction'=>'Derivativos não entram nas carteiras virtuais.'],
            ['key'=>'OPTIONS_ANALYSIS','name'=>'Options Arbitrage Analysis','mode'=>'ANALYTICAL_ONLY',
                'requirements'=>['cadeia histórica de opções','volatilidade implícita','proventos'],
                'restriction'=>'Sem operações simuladas com opções.'],
            ['key'=>'MARKET_MAKING_ANALYSIS','name'=>'Market Making Analysis','mode'=>'ANALYTICAL_ONLY',
                'requirements'=>['livro de ofertas histórico','eventos de ordens e fills'],
                'restriction'=>'Candles diários não validam market making; sem venda descoberta.'],
        ];
        $symbolHistory = (int) query($db, 'SELECT COUNT(DISTINCT instrument_id) FROM orf_bar WHERE market=? AND available_at<=?',
            ['010',$at])->fetchColumn();
        $historyStatus = $barCount > 0 ? 'DATA_PRESENT_REQUIRES_COVERAGE_REVIEW' : 'BLOCKED_NO_EOD_DATA';
        foreach ($definitions as &$item) {
            $item['status'] = $barCount > 0 ? 'RESEARCH_DATA_REVIEW_REQUIRED' : $historyStatus;
            if ($item['key'] === 'CASH_CARRY') {
                $item['status'] = 'OBSERVATION_ONLY_MISSING_FUTURES_DATA';
            } elseif ($item['key'] === 'OPTIONS_ANALYSIS') {
                $item['status'] = 'ANALYTICAL_ONLY_MISSING_OPTIONS_DATA';
            } elseif ($item['key'] === 'MARKET_MAKING_ANALYSIS') {
                $item['status'] = 'ANALYTICAL_ONLY_MISSING_ORDERBOOK_DATA';
            }
        }
        unset($item);
        $latest = query($db, 'SELECT id,created_at,payload FROM orf_research_run WHERE kind=?
            ORDER BY created_at DESC,id DESC LIMIT 1', ['RESEARCH'])->fetch();
        $report = $latest ? json_decode($latest['payload'],true,64,JSON_THROW_ON_ERROR) : null;
        $isQuantReport = ($report['schema'] ?? '') === 'ORFEU_QUANT_RESEARCH_V1';
        $date = Calendar::brazilDate($at);
        $instruments = $store->instruments($date,$at);
        $fundamentals = $store->fundamentalQuality($instruments,$date,$at);
        $quality = [];
        foreach ($instruments as $instrument) {
            $code = (string) ($instrument['cvm_code'] ?? '');
            if ($code !== '' && isset($fundamentals[$code])) {
                $quality[] = ['symbol'=>$instrument['symbol'],'sector'=>$instrument['sector'],
                    'net_margin'=>$fundamentals[$code]['net_margin'],'operating_margin'=>$fundamentals[$code]['ebit_margin'],
                    'period_end'=>$fundamentals[$code]['financial_period_end'],
                    'available_at'=>$fundamentals[$code]['financial_available_at'],'source'=>'CVM_ITR'];
            }
        }
        return ['as_of'=>$at, 'paper_trading_only'=>true, 'live_trading_enabled'=>false,
            'short_selling_enabled'=>false, 'leverage_enabled'=>false, 'derivatives_trading_enabled'=>false,
            'dynamic_rotation'=>false, 'eod_bar_count'=>$barCount, 'symbols_with_eod_history'=>$symbolHistory,
            'market_results_status'=>$isQuantReport ? ($report['status'] ?? 'NOT_CALCULATED') : 'NOT_CALCULATED',
            'latest_report'=>$isQuantReport ? ['id'=>$latest['id'],'created_at'=>$latest['created_at'],
                'source_sha256'=>$report['source_sha256'] ?? null,'bars_available'=>$report['bars_available'] ?? 0,
                'raw_bars_available_before_identity_gate'=>$report['raw_bars_available_before_identity_gate'] ?? 0,
                'identity_evidence_count'=>$report['identity_evidence_count'] ?? 0,
                'as_of'=>$report['as_of'] ?? null,
                'pair_candidates'=>$report['pair_candidates_after_fdr'] ?? 0,
                'pair_tests'=>$report['pair_tests'] ?? [],
                  'correlation_heatmap'=>$report['correlation_heatmap'] ?? ['status'=>'BLOCKED_NOT_CALCULATED'],
                'operational_history'=>$report['operational_history'] ?? []] : null,
            'quality_snapshot'=>['status'=>$quality ? 'PARTIAL_ANALYTICAL_ONLY' : 'BLOCKED_NO_POINT_IN_TIME_FUNDAMENTALS',
                'scope'=>'AVAILABLE_CVM_CATALOG_NOT_FIXED_PORTFOLIO_RANKING','items'=>$quality],
            'items'=>$definitions];
    }
}
