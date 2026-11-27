"""Synthetic fixtures for common-window strategy tournament calculations."""

import contextlib
import io
import json
import tempfile
import unittest
from pathlib import Path

from orfeu_quant.tournament import main, strategy_tournament

BENCHMARKS = ("IBOV", "CDI", "BUY_AND_HOLD", "EQUAL_WEIGHTED", "STATIC_LONG_ONLY")


def point(session, value, available=None, field="equity"):
    return {"session_date": session, field: value,
            "available_at": available or f"{session}T22:00:00Z"}


def inputs():
    sessions = ["2026-01-01", "2026-01-02", "2026-01-03", "2026-01-04"]
    accounts = {
        "old": {"initial_capital": 1000, "costs_included": True, "rows": [
            point(day, value) for day, value in zip(sessions, [1000, 1020, 1010, 1040], strict=True)]},
        "new": {"initial_capital": 500, "costs_included": True, "rows": [
            point(day, value) for day, value in zip(sessions[1:], [500, 510, 530], strict=True)]},
    }
    benchmarks = {
        name: [point(day, value, field="index_value")
               for day, value in zip(sessions, levels, strict=True)]
        for name, levels in zip(BENCHMARKS, [
            [100, 101, 99, 102], [100, 100.01, 100.02, 100.03],
            [100, 100.2, 100.1, 100.3], [100, 100.3, 100.2, 100.4],
            [100, 100.1, 100.2, 100.3],
        ], strict=True)
    }
    return sessions, accounts, benchmarks


class TournamentTests(unittest.TestCase):
    def test_compares_independent_accounts_on_same_normalized_window(self):
        sessions, accounts, benchmarks = inputs()
        accounts["old"]["rows"].append(point("2026-01-04", 5000,
                                              "2026-01-05T22:00:00Z"))
        result = strategy_tournament(accounts, benchmarks, sessions, "2026-01-04T23:59:00Z")
        self.assertEqual(result["status"], "ANALYTICAL_ONLY_COMMON_WINDOW")
        self.assertEqual(result["comparison_start"], "2026-01-02")
        self.assertEqual(result["comparison_end"], "2026-01-04")
        self.assertEqual(result["sessions"], 3)
        self.assertEqual(result["accounts"][0]["account_id"], "new")
        self.assertEqual(result["accounts"][0]["normalized_equity"][0], 100)
        self.assertEqual(result["accounts"][0]["metrics"]["observations"], 2)
        self.assertEqual(len(result["accounts"][0]["benchmark_comparisons"]), 5)
        self.assertFalse(result["trading_enabled"])

    def test_blocks_missing_benchmarks_costs_and_common_window_gaps(self):
        sessions, accounts, benchmarks = inputs()
        result = strategy_tournament(accounts, {"IBOV": benchmarks["IBOV"]}, sessions,
                                     "2026-01-04T23:59:00Z")
        self.assertEqual(result["status"], "BLOCKED_MISSING_BENCHMARKS")
        accounts["new"]["costs_included"] = False
        result = strategy_tournament(accounts, benchmarks, sessions, "2026-01-04T23:59:00Z")
        self.assertEqual(result["status"], "BLOCKED_ACCOUNT_COST_OR_SCHEMA")
        accounts["new"]["costs_included"] = True
        accounts["new"]["rows"].pop(1)
        result = strategy_tournament(accounts, benchmarks, sessions, "2026-01-04T23:59:00Z")
        self.assertEqual(result["status"], "BLOCKED_ACCOUNT_GAP_IN_COMMON_WINDOW")

    def test_cli_writes_hashed_versioned_report_without_overwriting(self):
        sessions, accounts, benchmarks = inputs()
        payload = {"schema": "ORFEU_TOURNAMENT_INPUT_V1", "as_of": "2026-01-04T23:59:00Z",
                   "session_dates": sessions, "accounts": accounts, "benchmarks": benchmarks}
        with tempfile.TemporaryDirectory() as temp:
            source = Path(temp) / "input.json"
            output = Path(temp) / "report.json"
            source.write_text(json.dumps(payload), encoding="utf-8")
            stdout = io.StringIO()
            with contextlib.redirect_stdout(stdout):
                result = main(["--input", str(source), "--output", str(output)])
            self.assertEqual(result, 0)
            report = json.loads(output.read_text(encoding="utf-8"))
            self.assertEqual(report["schema"], "ORFEU_TOURNAMENT_REPORT_V1")
            self.assertEqual(report["status"], "ANALYTICAL_ONLY_COMMON_WINDOW")
            self.assertEqual(len(report["source_sha256"]), 64)
            with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                self.assertEqual(main(["--input", str(source), "--output", str(output)]), 2)


if __name__ == "__main__":
    unittest.main()
