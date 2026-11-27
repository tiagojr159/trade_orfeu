import argparse
import hashlib
import json
import unittest
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest.mock import patch

from orfeu_quant import factory


def replay_result(trades=20):
    baseline = {"total_return": .01, "max_drawdown": -.02, "stale_snapshots": 0,
                "cdi_total": .02, "closed_trades": 2}
    candidate = dict(baseline, total_return=.10, closed_trades=trades)
    return {"status": "RESEARCH_ONLY", "sessions": 10, "eod_sessions": 10, "ledger_reconciled": True,
            "replay_input_sha256": "a" * 64, "strategy_results": {"baseline": baseline, "candidate": candidate}}


class FactoryTests(unittest.TestCase):
    def test_grid_is_bounded_and_reproducible(self):
        candidates = list(factory.candidates(11, 4))
        self.assertEqual(len(candidates), 8)
        self.assertEqual(candidates, list(factory.candidates(11, 4)))
        self.assertEqual(len({json.dumps(item, sort_keys=True) for item in candidates}), 8)

    def test_declarative_spec_is_bounded_and_reproducible(self):
        spec = {"schema": factory.SPEC_SCHEMA, "strategy": "MOMENTUM",
                "parameters": {"threshold": [1.0, 1.5], "atr_stop": [2.0], "holding": [5, 10]},
                "folds": [["2025-01-01", "2025-03-31"], ["2025-04-01", "2025-06-30"]],
                "holdout": ["2025-07-01", "2025-09-30"], "seed": 9, "budget": 3}
        with TemporaryDirectory() as directory:
            path = Path(directory) / "strategy.json"
            path.write_text(json.dumps(spec), encoding="utf-8")
            loaded = factory.load_spec(path)
        selected = list(factory.candidates(loaded["seed"], loaded["budget"], loaded))
        self.assertEqual(len(selected), 3)
        self.assertTrue(all(item["strategy"] == "MOMENTUM" for item in selected))
        self.assertEqual(selected, list(factory.candidates(loaded["seed"], loaded["budget"], loaded)))

    def test_declarative_spec_rejects_unsafe_or_unbounded_values(self):
        spec = {"schema": factory.SPEC_SCHEMA, "strategy": "MEAN_REVERSION",
                "parameters": {"threshold": ["__import__('os')"], "atr_stop": [2.0], "holding": [5]},
                "folds": [["2025-01-01", "2025-03-31"], ["2025-04-01", "2025-06-30"]],
                "holdout": ["2025-07-01", "2025-09-30"], "seed": 9, "budget": 3}
        with TemporaryDirectory() as directory:
            path = Path(directory) / "unsafe.json"
            path.write_text(json.dumps(spec), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "PARAMETERS"):
                factory.load_spec(path)

    def test_objective_rejects_insufficient_trades_and_data(self):
        self.assertEqual(factory.objective([replay_result(19), replay_result(20)])[1], "INSUFFICIENT_CLOSED_TRADES")
        self.assertEqual(factory.objective([{"status": "ERROR"}])[1], "INSUFFICIENT_COMPARABLE_DATA")
        self.assertGreater(factory.objective([replay_result(), replay_result()])[0], 0)

    def test_holdout_is_separate_and_runs_once_with_stress(self):
        folds = [("2026-01-02", "2026-01-30"), ("2026-02-02", "2026-02-27")]
        holdout = ("2026-03-02", "2026-03-31")
        with self.assertRaisesRegex(ValueError, "OVERLAPS"):
            factory.development_windows(folds, folds[-1])
        with TemporaryDirectory() as directory, patch.object(factory, "replay", side_effect=lambda *_: replay_result()) as run:
            output = Path(directory)
            args = argparse.Namespace(fold=folds, holdout=holdout, output=output, seed=7, budget=1,
                                      php="php", as_of="2026-04-10T12:00:00Z", timeout=1)
            with patch("builtins.print"):
                self.assertEqual(factory.search(args), 0)
            self.assertTrue(all(call.args[2] in folds for call in run.call_args_list))
            self.assertEqual(len(run.call_args_list), 4)
            run.reset_mock()
            frozen = output / "development.json"
            args.frozen, args.sha256 = frozen, hashlib.sha256(frozen.read_bytes()).hexdigest()
            with patch("builtins.print"):
                self.assertEqual(factory.validate(args), 0)
            self.assertEqual([(call.args[2], call.args[4]) for call in run.call_args_list], [(holdout, 1), (holdout, 2)])
            with self.assertRaises(FileExistsError):
                factory.validate(args)
            self.assertEqual(run.call_count, 2)
