"""Synthetic fixtures for math invariants only; never market-performance evidence."""

import json
import math
import random
import tempfile
import unittest
from datetime import date, timedelta
from importlib.util import find_spec
from pathlib import Path

from orfeu_quant.intelligence import (
    benjamini_hochberg,
    binomial_option,
    black_scholes_call,
    cash_carry_theoretical,
    correlation_heatmap,
    cross_sectional_clusters,
    evaluate_pair,
    factor_ranking,
    garch_volatility,
    gmm_regime_diagnostics,
    hdbscan_clusters,
    implied_volatility_call,
    kalman_hedge_ratio,
    opportunity_score,
    ou_process_diagnostics,
    pair_long_only_signal,
    put_call_parity_diagnostic,
    risk_diagnostics,
    scan_export,
    stat_arb_feature_snapshot,
    swing_diagnostics,
    technical_indicator_pack,
)


class QuantIntelligenceTests(unittest.TestCase):
    def test_correlation_heatmap_uses_synchronized_verified_raw_close_returns(self):
        series = {"AAA3": [], "BBB3": []}
        for index in range(61):
            session = (date(2026, 1, 1) + timedelta(days=index)).isoformat()
            close = 100 + index + math.sin(index / 3)
            common = {"session_date": session, "available_at": f"{session}T22:00:00Z",
                      "provider": "B3_COTAHIST", "raw_hash": "a" * 64}
            series["AAA3"].append({**common, "close_px": close})
            series["BBB3"].append({**common, "close_px": close * 2})
        report = correlation_heatmap(series, ["AAA3", "BBB3"], "2026-04-01T00:00:00Z")
        self.assertEqual(report["status"], "ANALYTICAL_ONLY_RAW_PRICE_RETURNS")
        self.assertEqual(report["observations"], 60)
        self.assertAlmostEqual(report["matrix"][0]["values"][1], 1.0)
        self.assertIn("CORPORATE_ACTIONS_NOT_ADJUSTED", report["price_basis"])
        self.assertEqual(report["trading_status"], "DISABLED")
        pending = correlation_heatmap({"AAA3": series["AAA3"]}, ["AAA3", "BBB3"],
                                      "2026-04-01T00:00:00Z")
        self.assertEqual(pending["status"], "BLOCKED_INSUFFICIENT_OR_UNVERIFIED_HISTORY")

    def test_stat_arb_snapshot_is_point_in_time_complete_and_analytical_only(self):
        bars, benchmark = [], []
        for index in range(70):
            session = (date(2026, 1, 1) + timedelta(days=index)).isoformat()
            close = 100 + index * .2 + math.sin(index / 3)
            bclose = 100 + index * .1 + math.cos(index / 5)
            common = {"session_date": session, "available_at": f"{session}T21:00:00Z",
                      "close_at": f"{session}T20:00:00Z", "price_basis": "RAW_UNADJUSTED",
                      "source_id": "fixture", "raw_sha256": "a" * 64}
            bars.append({**common, "high": close + 1, "low": close - 1, "close": close,
                         "volume": 1000 + index, "traded_value_brl": 100000 + index})
            benchmark.append({**common, "high": bclose + 1, "low": bclose - 1, "close": bclose,
                              "volume": 2000 + index, "traded_value_brl": 200000 + index})
        pair = {"session_date": bars[-1]["session_date"],
                "available_at": "2026-03-11T22:00:00Z", "dynamic_spread_zscore": 1.7,
                "cointegration_pvalue": .04, "fdr_qvalue": .08, "dynamic_hedge_ratio": 1.2,
                "half_life_sessions": 12}
        result = stat_arb_feature_snapshot("AAA3", bars, benchmark,
                                           "2026-03-20T23:59:00Z", True, pair)
        self.assertEqual(result["status"], "ANALYTICAL_ONLY")
        self.assertEqual(len(result["features"]), 16)
        self.assertTrue(math.isfinite(result["features"]["beta_ibovespa"]))
        self.assertEqual(result["features"]["spread_zscore"], 1.7)
        self.assertEqual(result["features"]["cointegration_pvalue"], .08)
        self.assertEqual(result["features"]["hedge_ratio"], 1.2)
        self.assertFalse(result["model_training_enabled"])
        self.assertFalse(result["trading_enabled"])

    def test_stat_arb_snapshot_requires_identity_and_rejects_future_revisions(self):
        bars, benchmark = [], []
        for index in range(70):
            session = (date(2026, 1, 1) + timedelta(days=index)).isoformat()
            close = 100 + index * .1
            base = {"session_date": session, "available_at": f"{session}T21:00:00Z",
                    "close_at": f"{session}T20:00:00Z", "price_basis": "RAW_UNADJUSTED",
                    "source_id": "fixture", "raw_sha256": "b" * 64,
                    "high": close + 1, "low": close - 1, "close": close, "volume": 1000,
                    "traded_value_brl": 100000}
            bars.append(base)
            benchmark.append({**base, "close": close + math.sin(index / 4)})
        cutoff = "2026-03-11T23:59:00Z"
        pending = stat_arb_feature_snapshot("AAA3", bars, benchmark, cutoff, False)
        self.assertEqual(pending["status"], "PENDING_IDENTITY")
        bars.append({**bars[-1], "available_at": "2026-04-01T21:00:00Z",
                     "close_at": "2026-01-01T20:00:00Z", "close": 900})
        result = stat_arb_feature_snapshot("AAA3", bars, benchmark, cutoff, True)
        self.assertEqual(result["status"], "ANALYTICAL_ONLY")
        self.assertEqual(result["session_date"], "2026-03-11")

    def test_research_export_requires_audited_identity_evidence_for_pair_history(self):
        as_of = "2026-12-31T23:59:00Z"
        lines = [{"kind": "manifest", "data": {"schema": "ORFEU_DATA_V1", "as_of": as_of,
                                                    "feature_schema": "eod-raw-v1"}}]
        symbols = ((1, "PETR3", "BRPETRACNOR9", "ON"),
                   (2, "PETR4", "BRPETRACNPR6", "PN"))
        for instrument_id, symbol, isin, asset_type in symbols:
            lines.append({"kind": "instrument", "data": {"id": instrument_id, "isin": isin,
                                                               "asset_type": asset_type}})
            lines.append({"kind": "symbol", "data": {"instrument_id": instrument_id,
                "valid_from": "2026-01-01", "symbol": symbol, "available_at": "2026-01-01T00:00:00Z"}})
            lines.append({"kind": "identity", "data": {"instrument_id": instrument_id, "symbol": symbol,
                "isin": isin, "valid_from": "2026-01-01", "valid_to": None,
                "available_at": "2026-01-02T00:00:00Z", "source": "B3_ASSET_CATALOG",
                "evidence_ref": "B3 catalog snapshot", "raw_hash": "c" * 64}})
        for index in range(60):
            session = (date(2026, 1, 1) + timedelta(days=index)).isoformat()
            for instrument_id, _, _, _ in symbols:
                close = 10000 + instrument_id * 100 + index * (3 + instrument_id)
                lines.append({"kind": "bar", "data": {"instrument_id": instrument_id,
                    "session_date": session, "market": "010", "provider": "B3_COTAHIST",
                    "available_at": f"{session}T22:00:00Z", "close_px": close, "raw_hash": "d" * 64}})
        lines.extend([
            {"kind": "instrument", "data": {"id": 3, "isin": "BRVALEACNOR0", "asset_type": "ON"}},
            {"kind": "symbol", "data": {"instrument_id": 3, "valid_from": "2026-01-01",
                "symbol": "VALE3", "available_at": "2026-01-01T00:00:00Z"}},
        ])
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / "export.jsonl"
            path.write_text("".join(json.dumps(line) + "\n" for line in lines), encoding="utf-8")
            report = scan_export(path)
        self.assertEqual(report["symbols_with_verified_identity"], ["PETR3", "PETR4"])
        self.assertEqual(report["identity_evidence_count"], 2)
        self.assertEqual(report["correlation_heatmap"]["status"],
                         "BLOCKED_INSUFFICIENT_OR_UNVERIFIED_HISTORY")
        self.assertEqual(report["bars_available"], 120)
        self.assertEqual(report["pair_tests"][0]["status"], "BLOCKED_INSUFFICIENT_HISTORY")
        self.assertEqual(report["pair_tests"][1]["status"], "BLOCKED_IDENTITY_NOT_VERIFIED")
        self.assertEqual(report["operational_history"]["VALE3"]["status"],
                         "BLOCKED_IDENTITY_NOT_VERIFIED")

    def test_benjamini_hochberg_is_monotone_and_restores_input_order(self):
        adjusted = benjamini_hochberg([.04, .01, .03, .8])
        self.assertAlmostEqual(adjusted[0], .05333333333333334)
        self.assertAlmostEqual(adjusted[1], .04)
        self.assertAlmostEqual(adjusted[2], .05333333333333334)
        self.assertAlmostEqual(adjusted[3], .8)

    def test_kalman_hedge_ratio_stays_finite(self):
        prices_a = [100 + i for i in range(30)]
        prices_b = [50 + i / 2 for i in range(30)]
        estimates = kalman_hedge_ratio(prices_a, prices_b)
        self.assertEqual(len(estimates), 30)
        self.assertTrue(all(abs(row["hedge_ratio"]) < 100 for row in estimates))
        self.assertTrue(all(row["innovation_variance"] > 0 for row in estimates))

    def test_ornstein_uhlenbeck_fit_reports_half_life_and_rejects_trend(self):
        rng = random.Random(801)
        values = [2.0]
        for _ in range(299):
            values.append(2 + .7 * (values[-1] - 2) + rng.gauss(0, .2))
        fit = ou_process_diagnostics(values)
        self.assertEqual(fit["status"], "ANALYTICAL_ONLY")
        self.assertGreater(fit["half_life_sessions"], 0)
        self.assertAlmostEqual(fit["equilibrium"], 2, delta=.2)
        trend = ou_process_diagnostics([index * .1 for index in range(60)])
        self.assertEqual(trend["status"], "NOT_STATIONARY_OU_DYNAMICS")

    def test_factor_ranking_respects_public_availability_cutoff(self):
        rows = [
            {"symbol": "AAA3", "sector": "A", "available_at": "2026-01-01T16:30:00+01:00",
             "pe": 10, "pb": 1, "roe": .2, "return_3m": .1, "historical_volatility": .2,
             "traded_value": 100},
            {"symbol": "BBB3", "sector": "A", "available_at": "2026-01-01T16:30:00Z",
             "pe": 5, "pb": .8, "roe": .3, "return_3m": .2, "historical_volatility": .1,
             "traded_value": 200},
        ]
        ranked = factor_ranking(rows, "2026-01-01T16:00:00Z")
        self.assertEqual([item["symbol"] for item in ranked], ["AAA3"])
        self.assertEqual(ranked[0]["trading_status"], "ANALYTICAL_ONLY")

    def test_factor_ranking_groups_correlated_earnings_multiple_metrics(self):
        rows = [
            {"symbol": "AAA3", "sector": "A", "available_at": "2026-01-01T00:00:00Z",
             "pe": 10, "earnings_yield": .1},
            {"symbol": "BBB3", "sector": "A", "available_at": "2026-01-01T00:00:00Z",
             "pe": 20, "earnings_yield": .05},
        ]
        both = factor_ranking(rows, "2026-01-02T00:00:00Z")
        pe_only = factor_ranking([{key: value for key, value in row.items()
                                  if key != "earnings_yield"} for row in rows],
                                 "2026-01-02T00:00:00Z")
        self.assertEqual([row["components"]["value"] for row in both],
                         [row["components"]["value"] for row in pe_only])

    def test_factor_ranking_selects_latest_visible_revision_per_symbol(self):
        rows = [
            {"symbol": "AAA3", "sector": "A", "available_at": "2026-01-01T12:00:00Z", "pe": 10},
            {"symbol": "AAA3", "sector": "A", "available_at": "2026-01-01T13:00:00Z", "pe": 5},
            {"symbol": "AAA3", "sector": "A", "available_at": "2026-01-01T15:00:00Z", "pe": 1},
        ]
        ranked = factor_ranking(rows, "2026-01-01T14:00:00Z")
        self.assertEqual(len(ranked), 1)
        self.assertEqual(ranked[0]["available_at"], "2026-01-01T13:00:00Z")

    def test_opportunity_score_uses_all_seven_normalized_components_and_blocks_gaps(self):
        components = {name: 100.0 for name in (
            "expected_net_return", "calibrated_probability", "momentum", "quality",
            "valuation", "liquidity", "risk")}
        result = opportunity_score(components)
        self.assertEqual(result["score"], 100)
        self.assertEqual(result["classification"], "VERY_HIGH_RESEARCH")
        self.assertIn("NOT_SUCCESS_PROBABILITY", result["interpretation"])
        self.assertEqual(result["trading_status"], "DISABLED")
        incomplete = opportunity_score({key: value for key, value in components.items()
                                        if key != "risk"})
        self.assertEqual(incomplete["status"], "BLOCKED_INCOMPLETE_SCORE_COMPONENTS")
        invalid = opportunity_score({**components, "liquidity": 101})
        self.assertEqual(invalid["status"], "BLOCKED_INVALID_SCORE_COMPONENTS")

    def test_pca_dbscan_clusters_are_point_in_time_and_analytical(self):
        rows = [{"symbol": f"A{i}", "available_at": "2026-01-05T22:00:00Z",
                 "sector": "S", "momentum": i * .01, "volatility": i * .02}
                for i in range(6)]
        result = cross_sectional_clusters(rows, eps=.5, min_samples=2)
        self.assertEqual(result["status"], "ANALYTICAL_ONLY")
        self.assertEqual(result["method"], "PCA_DBSCAN")
        self.assertEqual(len(result["items"]), len(rows))
        self.assertEqual(result["trading_status"], "DISABLED")
        rows[-1]["available_at"] = "2026-01-06T22:00:00Z"
        self.assertEqual(cross_sectional_clusters(rows)["status"], "BLOCKED_MISALIGNED_CROSS_SECTION")

    def test_optional_hdbscan_and_garch_report_runtime_state(self):
        cluster = hdbscan_clusters([[0, 0], [1, 1], [2, 2]], min_cluster_size=2)
        self.assertIn(cluster["status"], {"ANALYTICAL_ONLY", "BLOCKED_DEPENDENCY"})
        garch = garch_volatility([.001 * ((index % 7) - 3) for index in range(120)])
        self.assertEqual(garch["status"], "ANALYTICAL_ONLY")
        self.assertEqual(garch["model"], "GARCH_1_1_GAUSSIAN_MLE")
        self.assertGreater(garch["forecast_volatility"], 0)
        self.assertLess(garch["persistence"], 1)

    def test_gmm_regime_selection_is_research_only_and_rotation_stays_off(self):
        rows = [{"session_date": f"2026-01-{index + 1:02d}",
                 "return_21d": .02 if index < 30 else -.03,
                 "volatility_21d": .1 if index < 30 else .35}
                for index in range(60)]
        result = gmm_regime_diagnostics(rows)
        self.assertEqual(result["status"], "ANALYTICAL_ONLY")
        self.assertIn(result["regime"], {"RISK_ON", "RISK_OFF", "TRANSITION", "UNRESOLVED_SINGLE_STATE"})
        self.assertFalse(result["rotation_enabled"])
        self.assertGreaterEqual(result["posterior_probability"], 0)

    def test_swing_breakout_uses_next_session_and_capped_lot_sizing(self):
        closes = [100 + index * .1 for index in range(60)]
        closes[-1] = closes[-2] + 2
        highs = [value + .4 for value in closes]
        lows = [value - .4 for value in closes]
        volumes = [1000] * 59 + [1500]
        result = swing_diagnostics(highs, lows, closes, volumes, True, True,
                                   allowed_risk_brl=1000, capital_brl=200000, cash_brl=200000,
                                   adv_shares=10000)
        self.assertEqual(result["signal"], "RESEARCH_LONG_CANDIDATE")
        self.assertEqual(result["entry_pattern"], "BREAKOUT")
        self.assertEqual(result["entry_timing"], "NEXT_SESSION_ONLY")
        self.assertEqual(result["quantity_shares"], 100)
        self.assertEqual(result["trading_status"], "DISABLED")
        timed_exit = swing_diagnostics(highs, lows, closes, volumes, True, True, held_sessions=15)
        self.assertEqual(timed_exit["signal"], "EXIT_TIME")

    def test_ohlcv_pack_produces_required_trend_reversion_and_volume_indicators(self):
        closes = [100 + index * .2 + math.sin(index / 3) for index in range(60)]
        highs = [value + .8 for value in closes]
        lows = [value - .7 for value in closes]
        volumes = [1000] * 59 + [2400]
        result = technical_indicator_pack(highs, lows, closes, volumes)
        self.assertEqual(result["status"], "AVAILABLE")
        for field in ("adx_14", "macd_histogram", "bollinger_upper_20_2sd",
                      "bollinger_lower_20_2sd", "atr_14", "volume_ratio_20"):
            self.assertTrue(math.isfinite(result[field]))
        self.assertTrue(result["abnormal_volume"])
        self.assertEqual(technical_indicator_pack(highs[:-1], lows, closes, volumes)["status"],
                         "BLOCKED_INVALID_OR_INSUFFICIENT_OHLCV")

    @unittest.skipUnless(find_spec("sklearn"), "optional scikit-learn runtime missing")
    def test_garch_blocks_short_history_without_fitting(self):
        self.assertEqual(garch_volatility([.01, -.02])["status"],
                         "BLOCKED_INSUFFICIENT_OR_INVALID_HISTORY")

    def test_pair_research_signal_never_creates_short_leg(self):
        candidate = {"status": "CANDIDATE", "candidate": True, "spread_zscore": 2.5}
        signal = pair_long_only_signal(candidate, "PETR3", "PETR4", ["PETR4"])
        self.assertEqual(signal["buy_symbol"], "PETR4")
        self.assertIsNone(signal["short_leg"])
        self.assertTrue(signal["directional_exposure"])

    @unittest.skipUnless(find_spec("statsmodels"), "optional Statsmodels runtime missing")
    def test_pair_statistics_run_on_isolated_math_fixture(self):
        rng = random.Random(2401)
        log_b = [math.log(100.0)]
        residual = [0.0]
        for _ in range(251):
            log_b.append(log_b[-1] + rng.gauss(0, .012))
            residual.append(.55 * residual[-1] + rng.gauss(0, .004))
        price_a = [math.exp(.4 + 1.15 * b + error) for b, error in zip(log_b, residual)]
        price_b = [math.exp(value) for value in log_b]
        result = evaluate_pair(price_a, price_b)
        self.assertIn(result["status"], {"CANDIDATE", "REJECTED", "BLOCKED_NUMERICAL_DEGENERACY"})
        if result["status"] != "BLOCKED_NUMERICAL_DEGENERACY":
            self.assertEqual(result["observations"], 252)
            self.assertTrue(math.isfinite(result["cointegration_pvalue"]))
            self.assertTrue(math.isfinite(result["dynamic_spread_zscore"]))

    def test_option_reference_and_risk_outputs_are_analytic_only(self):
        option = black_scholes_call(100, 100, 1, .05, .2)
        self.assertGreater(option["call_value"], 0)
        self.assertIn("REFERENCE_ONLY", option["analytical_status"])
        parity = put_call_parity_diagnostic(100, 100, 1, .05, 0, option["call_value"],
                                            option["call_value"] - (100 - 100 * math.exp(-.05)))
        self.assertAlmostEqual(parity["parity_residual"], 0, places=10)
        american = binomial_option(100, 100, 1, .05, .2, "call", steps=200)
        self.assertAlmostEqual(american["value"], option["call_value"], delta=.05)
        self.assertEqual(binomial_option(100, 100, 1, .05, .2, "put")["exercise_style"], "AMERICAN")
        iv = implied_volatility_call(100, 100, 1, .05, option["call_value"])
        self.assertAlmostEqual(iv["implied_volatility"], .2, places=6)
        self.assertEqual(implied_volatility_call(100, 100, 1, .05, 101)["status"],
                         "BLOCKED_OUTSIDE_NO_ARBITRAGE_BOUNDS")
        carry = cash_carry_theoretical(100, 2, .05, .5, estimated_costs=1, observed_future=103)
        self.assertAlmostEqual(carry["net_basis_after_costs"],
                               103 - (98 * math.exp(.025)) - 1, places=10)
        self.assertEqual(carry["trade_status"], "DISABLED")
        risk = risk_diagnostics([100, 98, 103, 99], alpha=.75)
        self.assertEqual(risk["status"], "AVAILABLE")
        self.assertLess(risk["max_drawdown"], 0)
        self.assertEqual(risk["kill_switch"], "NOT_TRIGGERED_BY_ANALYTIC_ONLY")


if __name__ == "__main__":
    unittest.main()
