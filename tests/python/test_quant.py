import datetime
import hashlib
import importlib.util
import json
import subprocess
import sys
import unittest
from pathlib import Path
from tempfile import TemporaryDirectory

from orfeu_quant.data import utc, visible_revision
from orfeu_quant.metrics import (
    bootstrap_performance,
    bootstrap_relative_performance,
    cross_sectional_ic,
    equity_metrics,
    return_metrics,
    trade_metrics,
)
from orfeu_quant.predict import predict
from orfeu_quant.splits import purged_split


class QuantTests(unittest.TestCase):
    def test_cli_checks_exported_snapshot_hash(self):
        fixture = json.loads((Path(__file__).parents[1] / "fixtures" / "linear.json").read_text())
        with TemporaryDirectory() as directory:
            model = Path(directory) / "model.json"
            model.write_text(json.dumps(fixture["model"]), encoding="utf-8")
            features = Path(directory) / "features.jsonl"
            payload = json.dumps(fixture["features"])
            snapshot = {"instrument_id": 1, "as_of": fixture["as_of"], "payload": payload,
                        "feature_hash": hashlib.sha256(payload.encode()).hexdigest()}
            args = [sys.executable, "-m", "orfeu_quant.predict", "--model", str(model),
                    "--sha256", hashlib.sha256(model.read_bytes()).hexdigest(), "--features", str(features),
                    "--as-of", fixture["as_of"]]
            features.write_text(json.dumps({"kind": "feature", "data": snapshot}) + "\n", encoding="utf-8")
            result = json.loads(subprocess.run(args, check=True, capture_output=True, text=True).stdout)
            self.assertAlmostEqual(result["prediction"]["p_up"], fixture["expected"]["p_up"], places=8)
            snapshot["payload"] += " "
            features.write_text(json.dumps({"kind": "feature", "data": snapshot}) + "\n", encoding="utf-8")
            result = subprocess.run(args, check=False, capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("FEATURE_HASH_MISMATCH", result.stderr)

    @unittest.skipUnless(importlib.util.find_spec("lightgbm"), "optional LightGBM runtime missing")
    def test_native_lightgbm_round_trip(self):
        from orfeu_quant.data import COLUMNS, SCHEMA
        from orfeu_quant.predict import load_challenger
        from orfeu_quant.train import fit_challenger

        def rows(count, offset):
            return [{"features": [(i + offset) % 30 / 30, *[j * i / 100 for j in range(1, 9)]],
                     "gross": ((i + offset) % 30 - 14) / 200}
                    for i in range(count)]

        test = rows(40, 7)
        report, artifact = fit_challenger(rows(100, 0), rows(50, 3), test, .005, export=True)
        artifact.update(schema=SCHEMA, columns=COLUMNS, cost=.005,
                        trained_until="2026-11-17T22:00:00Z", effective_at="2026-11-18T00:00:00Z")
        score = load_challenger(artifact)
        features = [dict(zip(COLUMNS, row["features"], strict=True)) for row in test]
        self.assertIsNone(score(features[0], "2026-11-17T22:00:00Z"))
        self.assertIsNone(score({}, "2026-11-18T10:00:00Z"))
        outcomes = [score(feature, "2026-11-18T10:00:00Z") for feature in features]
        mae = sum(abs(pred["mu_gross"] - row["gross"]) for pred, row in zip(outcomes, test, strict=True)) / len(test)
        brier = sum((pred["p_profitable"] - (row["gross"] > .005)) ** 2
                    for pred, row in zip(outcomes, test, strict=True)) / len(test)
        self.assertAlmostEqual(mae, report["return_mae"], places=10)
        self.assertAlmostEqual(brier, report["profitable"]["brier"], places=10)
        self.assertTrue(all(item["q10"] <= item["q50"] <= item["q90"] for item in outcomes))

    def test_fundamental_release_after_close_and_point_in_time(self):
        earlier = {"valid_from": "2026-01-01", "available_at": "2026-03-30T19:00:00Z", "status": "ACTIVE"}
        later = {"valid_from": "2026-01-01", "available_at": "2026-03-31T02:00:00Z", "status": "SUSPENDED"}
        self.assertIs(visible_revision([earlier, later], "2026-03-30", utc("2026-03-30T20:00:00Z")), earlier)
        self.assertIs(visible_revision([earlier, later], "2026-03-31", utc("2026-03-31T03:00:00Z")), later)

    def test_delisted_universe(self):
        initial = {"valid_from": "2019-01-01", "available_at": "2019-01-01T00:00:00Z", "status": "ACTIVE"}
        delisted = {"valid_from": "2023-03-31", "available_at": "2023-03-29T00:00:00Z", "status": "DELISTED"}
        self.assertIs(visible_revision([initial, delisted], "2023-03-30", utc("2023-03-30T17:00:00Z")), initial)
        self.assertIs(visible_revision([initial, delisted], "2023-03-31", utc("2023-03-31T17:00:00Z")), delisted)

    def test_purged_fold(self):
        first = datetime.date(2025, 1, 1)
        dates = [(first + datetime.timedelta(days=k)).isoformat() for k in range(230)]
        rows = []
        for i, date in enumerate(dates):
            start = utc(date + "T22:00:00Z")
            for asset in range(4):
                rows.append({"session": date, "as_of": start,
                             "label_end": start + datetime.timedelta(days=11),
                             "label_available_at": start + datetime.timedelta(days=18), "instrument_id": asset})
        train, cal, test = purged_split(rows, dates, dates[184], 6)
        self.assertLess(max(r["label_available_at"] for r in train), min(r["as_of"] for r in cal))
        self.assertLess(max(r["label_available_at"] for r in cal), min(r["as_of"] for r in test))
        self.assertEqual(min(r["session"] for r in test), dates[184])

    def test_metrics_initial_snapshot_and_missing_cdi(self):
        result = equity_metrics({"2026-01-01": 100000, "2026-01-02": 99000, "2026-01-03": 101800},
                                {"2026-01-02": .0005})
        self.assertAlmostEqual(result["total_return"], .018)
        self.assertAlmostEqual(result["max_drawdown"], -.01)
        self.assertEqual(result["cdi_coverage"], .5)
        self.assertIsNone(result["excess_sharpe"])

    def test_full_return_metrics_and_realized_trade_diagnostics(self):
        result = return_metrics([.02, -.01, .03, -.005], [.01, -.005, .01, 0],
                                [0, 0, 0, 0])
        self.assertEqual(result["observations"], 4)
        self.assertGreater(result["beta"], 0)
        self.assertIsNotNone(result["tracking_error"])
        self.assertIsNotNone(result["information_ratio"])
        self.assertIsNotNone(result["sortino"])
        trades = trade_metrics([100, -50, 0, 25])
        self.assertEqual(trades["win_rate"], .5)
        self.assertEqual(trades["profit_factor"], 2.5)
        self.assertEqual(trades["expectancy"], 18.75)

    def test_full_metrics_reject_misaligned_or_invalid_series(self):
        with self.assertRaisesRegex(ValueError, "MISALIGNED_BENCHMARK_RETURNS"):
            return_metrics([.01, .02], [.01])
        with self.assertRaisesRegex(ValueError, "INVALID_TRADE_PNL"):
            trade_metrics([float("nan")])
        self.assertEqual(trade_metrics([])["status"], "NO_TRADES")

    def test_block_bootstrap_is_seeded_and_reports_path_risk(self):
        observed = [.01, -.015, .006, .002, -.004] * 8
        first = bootstrap_performance(observed, replications=200, block_size=4, seed=7)
        second = bootstrap_performance(observed, replications=200, block_size=4, seed=7)
        self.assertEqual(first, second)
        self.assertEqual(first["status"], "RESEARCH_ONLY_BOOTSTRAP")
        self.assertLessEqual(first["total_return_p05"], first["total_return_p50"])
        self.assertGreaterEqual(first["probability_drawdown_limit_breach"], 0)

    def test_paired_block_bootstrap_reports_benchmark_uncertainty(self):
        strategy, benchmark = [.01, -.005, .015, .004, -.002] * 8, [.003, -.004, .005, .001, 0] * 8
        first = bootstrap_relative_performance(strategy, benchmark, replications=200, block_size=5, seed=17)
        second = bootstrap_relative_performance(strategy, benchmark, replications=200, block_size=5, seed=17)
        self.assertEqual(first, second)
        self.assertEqual(first["status"], "RESEARCH_ONLY_PAIRED_BLOCK_BOOTSTRAP")
        self.assertGreater(first["relative_total_return_p05"], 0)
        self.assertEqual(first["probability_underperforming"], 0)
        with self.assertRaisesRegex(ValueError, "WIPEOUT"):
            bootstrap_relative_performance([.01] * 20, [-1.0] + [.01] * 19, replications=100)

    def test_cross_sectional_information_coefficient_is_computed_per_session(self):
        dates, observed, forecast = [], [], []
        for day in ("2026-01-02", "2026-01-03", "2026-01-04"):
            dates.extend([day] * 4)
            observed.extend([.01, .02, .03, .04])
            forecast.extend([-4, -3, -2, -1])
        result = cross_sectional_ic(observed, forecast, dates)
        self.assertEqual(result["status"], "AVAILABLE")
        self.assertEqual(result["sessions"], 3)
        self.assertAlmostEqual(result["mean_ic"], 1.0)
        self.assertEqual(result["positive_session_fraction"], 1.0)

    def test_cross_sectional_ic_blocks_small_or_misaligned_data(self):
        result = cross_sectional_ic([.1, .2, .3], [.2, .1, .3], ["2026-01-02"] * 3)
        self.assertEqual(result["status"], "BLOCKED_INSUFFICIENT_CROSS_SECTIONS")
        with self.assertRaisesRegex(ValueError, "MISALIGNED"):
            cross_sectional_ic([.1], [.2, .3], ["2026-01-02"])

    def test_linear_trainer_reports_oos_permutation_ablation(self):
        import numpy as np
        from orfeu_quant.train import fit_linear

        rng = np.random.default_rng(29)

        def rows(first_day, day_count):
            result = []
            for day in range(first_day, first_day + day_count):
                for _ in range(4):
                    features = rng.normal(size=9)
                    gross = .018 * features[1] + rng.normal(0, .008)
                    result.append({"session": f"2026-{day // 28 + 1:02d}-{day % 28 + 1:02d}",
                                   "features": features.tolist(), "gross": float(gross)})
            return result

        _, metrics = fit_linear(rows(0, 40), rows(40, 20), rows(60, 20), .005)
        ablation = metrics["return"]["permutation_ablation"]
        self.assertEqual(ablation["status"], "RESEARCH_ONLY_PERMUTATION_ABLATION")
        self.assertEqual(len(ablation["features"]), 9)
        self.assertFalse(ablation["selection_used"])
        self.assertEqual(metrics["return"]["cross_sectional_ic"]["status"], "AVAILABLE")

    def test_random_forest_challenger_uses_calibration_split_and_stays_research_only(self):
        from orfeu_quant.train import fit_random_forest

        def rows(start, stop):
            return [{"features": [index / 100, (index % 7) / 7],
                     "gross": .02 if index % 2 else -.01} for index in range(start, stop)]

        result = fit_random_forest(rows(0, 120), rows(120, 180), rows(180, 240), .005)
        self.assertEqual(result["model"], "RANDOM_FOREST_RESEARCH_ONLY")
        self.assertEqual(result["classifiers"]["profitable"]["calibration"], "PLATT_ON_HELDOUT_CALIBRATION")
        self.assertIn("promotion_status", result)
        self.assertGreaterEqual(result["q10_q90_coverage"], 0)

    def test_xgboost_challenger_reports_missing_optional_runtime(self):
        from orfeu_quant.train import fit_xgboost

        if importlib.util.find_spec("xgboost") is None:
            self.assertEqual(fit_xgboost([], [], [], .005),
                             {"status": "BLOCKED_DEPENDENCY", "missing": "xgboost"})
        else:
            def rows(start, stop):
                return [{"features": [index / 100, (index % 7) / 7],
                         "gross": .02 if index % 2 else -.01} for index in range(start, stop)]

            result = fit_xgboost(rows(0, 120), rows(120, 180), rows(180, 240), .005)
            self.assertEqual(result["model"], "XGBOOST_RESEARCH_ONLY")
            self.assertEqual(result["status"], "AVAILABLE")

    def test_model_cutoff_probability_and_quantile_bounds(self):
        import json
        from pathlib import Path

        fixture = json.loads((Path(__file__).parents[1] / "fixtures" / "linear.json").read_text())
        model = fixture["model"]
        outcome = predict(model, fixture["features"], fixture["as_of"])
        for key, expected in fixture["expected"].items():
            self.assertAlmostEqual(outcome[key], expected, places=8)
        self.assertGreater(outcome["p_up"], 0)
        self.assertLess(outcome["p_up"], 1)
        self.assertLess(outcome["q10"], outcome["q50"])
        self.assertLess(outcome["q50"], outcome["q90"])
        self.assertIsNone(predict(model, fixture["features"], model["trained_until"]))
        self.assertIsNone(predict(model, {}, fixture["as_of"]))
