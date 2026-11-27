"""Point-in-time, common-window comparison for independent paper strategies."""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import sys
from datetime import date
from pathlib import Path

from .data import utc
from .metrics import equity_metrics, return_metrics

REQUIRED_BENCHMARKS = frozenset({
    "IBOV", "CDI", "BUY_AND_HOLD", "EQUAL_WEIGHTED", "STATIC_LONG_ONLY",
})


def _visible_curve(rows, cutoff, field):
    latest = {}
    for row in rows:
        try:
            session = row["session_date"]
            available = utc(row["available_at"])
            value = row[field]
            valid_session = (isinstance(session, str)
                             and date.fromisoformat(session).isoformat() == session)
        except (KeyError, TypeError, ValueError):
            continue
        if (not valid_session or available > cutoff
                or isinstance(value, bool) or not isinstance(value, (int, float))
                or not math.isfinite(value) or value <= 0):
            continue
        previous = latest.get(session)
        if previous is None or available >= previous[0]:
            latest[session] = (available, float(value))
    return {session: value for session, (_, value) in latest.items()}


def strategy_tournament(accounts, benchmarks, session_dates, as_of):
    """Compare cost-inclusive equity curves and required benchmarks on one common window.

    Input curves are point-in-time records. The function refuses missing benchmark
    classes or calendar gaps rather than forward-filling values or comparing unequal
    periods. Account curves must already include execution costs and cash flows.
    """
    try:
        cutoff = utc(as_of)
    except (TypeError, ValueError):
        return {"status": "BLOCKED_INVALID_AS_OF", "trading_enabled": False}
    if not isinstance(session_dates, (list, tuple)):
        return {"status": "BLOCKED_INVALID_SESSION_CALENDAR", "trading_enabled": False}
    dates = list(session_dates)
    try:
        valid_dates = all(date.fromisoformat(day).isoformat() == day for day in dates)
    except (TypeError, ValueError):
        valid_dates = False
    if len(dates) < 3 or not valid_dates or dates != sorted(set(dates)):
        return {"status": "BLOCKED_INVALID_SESSION_CALENDAR", "trading_enabled": False}
    if not isinstance(accounts, dict) or not accounts:
        return {"status": "BLOCKED_NO_STRATEGY_ACCOUNTS", "trading_enabled": False}
    if not isinstance(benchmarks, dict):
        return {"status": "BLOCKED_MISSING_BENCHMARKS",
                "missing": sorted(REQUIRED_BENCHMARKS), "trading_enabled": False}
    missing_benchmarks = sorted(REQUIRED_BENCHMARKS - set(benchmarks))
    if missing_benchmarks:
        return {"status": "BLOCKED_MISSING_BENCHMARKS", "missing": missing_benchmarks,
                "trading_enabled": False}

    account_curves = {}
    starts, ends = [], []
    for account_id, account in accounts.items():
        if (not isinstance(account_id, str) or not account_id or not isinstance(account, dict)
                or account.get("costs_included") is not True
                or not isinstance(account.get("rows"), list)):
            return {"status": "BLOCKED_ACCOUNT_COST_OR_SCHEMA", "account": account_id,
                    "trading_enabled": False}
        capital = account.get("initial_capital")
        if (not isinstance(capital, (int, float)) or isinstance(capital, bool)
                or not math.isfinite(capital) or capital <= 0):
            return {"status": "BLOCKED_INVALID_INITIAL_CAPITAL", "account": account_id,
                    "trading_enabled": False}
        curve = _visible_curve(account.get("rows", []), cutoff, "equity")
        if not curve:
            return {"status": "BLOCKED_NO_VISIBLE_ACCOUNT_EQUITY", "account": account_id,
                    "trading_enabled": False}
        account_curves[account_id] = {"capital": float(capital), "curve": curve}
        starts.append(min(curve))
        ends.append(max(curve))

    benchmark_curves = {}
    for benchmark in REQUIRED_BENCHMARKS:
        if not isinstance(benchmarks[benchmark], list):
            return {"status": "BLOCKED_INVALID_BENCHMARK_SERIES", "benchmark": benchmark,
                    "trading_enabled": False}
        curve = _visible_curve(benchmarks[benchmark], cutoff, "index_value")
        if not curve:
            return {"status": "BLOCKED_NO_VISIBLE_BENCHMARK", "benchmark": benchmark,
                    "trading_enabled": False}
        benchmark_curves[benchmark] = curve
        starts.append(min(curve))
        ends.append(max(curve))

    first, last = max(starts), min(ends)
    common_dates = [day for day in dates if first <= day <= last]
    if len(common_dates) < 3:
        return {"status": "BLOCKED_NO_COMMON_COMPARISON_WINDOW", "trading_enabled": False}
    for account_id, item in account_curves.items():
        if any(day not in item["curve"] for day in common_dates):
            return {"status": "BLOCKED_ACCOUNT_GAP_IN_COMMON_WINDOW", "account": account_id,
                    "trading_enabled": False}
    for benchmark, curve in benchmark_curves.items():
        if any(day not in curve for day in common_dates):
            return {"status": "BLOCKED_BENCHMARK_GAP_IN_COMMON_WINDOW", "benchmark": benchmark,
                    "trading_enabled": False}

    benchmark_returns = {}
    normalized_benchmarks = {}
    for name, curve in benchmark_curves.items():
        values = [curve[day] for day in common_dates]
        normalized_benchmarks[name] = [100 * value / values[0] for value in values]
        benchmark_returns[name] = [values[i] / values[i - 1] - 1 for i in range(1, len(values))]
    cdi_returns = benchmark_returns["CDI"]
    results = []
    for account_id, item in account_curves.items():
        values = [item["curve"][day] for day in common_dates]
        returns = [values[i] / values[i - 1] - 1 for i in range(1, len(values))]
        metrics = return_metrics(returns, benchmark_returns["IBOV"], cdi_returns)
        equity = dict(zip(common_dates, values, strict=True))
        cdi_by_date = dict(zip(common_dates[1:], cdi_returns, strict=True))
        path_metrics = equity_metrics(equity, cdi_by_date)
        comparisons = {
            name: return_metrics(returns, other_returns, cdi_returns)
            for name, other_returns in benchmark_returns.items()
        }
        results.append({
            "account_id": account_id,
            "initial_capital": item["capital"],
            "comparison_start": common_dates[0],
            "comparison_end": common_dates[-1],
            "normalized_equity": [100 * value / values[0] for value in values],
            "metrics": metrics | path_metrics,
            "benchmark_comparisons": comparisons,
        })
    results.sort(key=lambda row: (
        row["metrics"]["annualized_return"] is None,
        -(row["metrics"]["annualized_return"] or 0), row["account_id"]))
    for rank, row in enumerate(results, start=1):
        row["research_rank"] = rank
    return {
        "status": "ANALYTICAL_ONLY_COMMON_WINDOW",
        "comparison_start": common_dates[0], "comparison_end": common_dates[-1],
        "sessions": len(common_dates), "accounts": results,
        "normalized_benchmarks": normalized_benchmarks,
        "benchmark_names": sorted(REQUIRED_BENCHMARKS),
        "interpretation": "HISTORICAL_COMPARISON_NOT_A_PERFORMANCE_PROMISE",
        "trading_enabled": False,
    }


def main(argv=None):
    parser = argparse.ArgumentParser(description="Compare ORFEU strategy curves on a common research window.")
    parser.add_argument("--input", required=True, help="ORFEU_TOURNAMENT_INPUT_V1 JSON")
    parser.add_argument("--output", required=True, help="New report path; existing files are not overwritten")
    args = parser.parse_args(argv)
    try:
        input_path = Path(args.input)
        raw = input_path.read_bytes()
        payload = json.loads(raw)
        if not isinstance(payload, dict) or payload.get("schema") != "ORFEU_TOURNAMENT_INPUT_V1":
            raise ValueError("INVALID_TOURNAMENT_INPUT_SCHEMA")
        report = strategy_tournament(payload.get("accounts"), payload.get("benchmarks"),
                                     payload.get("session_dates"), payload.get("as_of"))
        report = {"schema": "ORFEU_TOURNAMENT_REPORT_V1",
                  "source_sha256": hashlib.sha256(raw).hexdigest(), **report}
        destination = Path(args.output)
        destination.parent.mkdir(parents=True, exist_ok=True)
        with destination.open("xb") as stream:
            stream.write(json.dumps(report, ensure_ascii=True, sort_keys=True,
                                    separators=(",", ":")).encode("utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError, ValueError) as error:
        print(json.dumps({"status": "BLOCKED", "reason": str(error)}), file=sys.stderr)
        return 2
    print(json.dumps({"status": report["status"], "report": str(destination),
                      "source_sha256": report["source_sha256"],
                      "accounts": len(report.get("accounts", []))}))
    return 2 if report["status"].startswith("BLOCKED") else 0


if __name__ == "__main__":
    raise SystemExit(main())
