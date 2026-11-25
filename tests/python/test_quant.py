import datetime
import importlib.util
import hashlib
import json
import subprocess
import sys
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest

from orfeu_quant.data import utc, visible_revision
from orfeu_quant.metrics import equity_metrics
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
            result = subprocess.run(args, capture_output=True, text=True)
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
