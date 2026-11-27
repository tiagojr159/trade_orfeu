"""Bounded research search through the existing PHP paper execution engine."""

import argparse
import hashlib
import itertools
import json
import math
import random
import statistics
import subprocess
import sys
from datetime import date
from pathlib import Path

from .data import utc

ROOT = Path(__file__).resolve().parents[2]
SCHEMA = "ORFEU_FACTORY_REPORT_V1"
FAMILIES = {"MOMENTUM": (1.0, 1.3, 1.6), "MEAN_REVERSION": (1.5, 2.0, 2.5)}
SPEC_SCHEMA = "ORFEU_STRATEGY_SPEC_V1"


def window(text):
    start, end = (date.fromisoformat(value) for value in text.split(":"))
    if start >= end:
        raise ValueError("INVALID_FACTORY_WINDOW")
    return start.isoformat(), end.isoformat()


def development_windows(folds, holdout):
    if len(folds) < 2 or any(left[1] >= right[0] for left, right in itertools.pairwise(folds)):
        raise ValueError("DEVELOPMENT_FOLDS_MUST_BE_ORDERED_AND_DISJOINT")
    if folds[-1][1] >= holdout[0]:
        raise ValueError("HOLDOUT_OVERLAPS_DEVELOPMENT")
    return folds


def candidates(seed, budget, spec=None):
    rng = random.Random(seed)
    if spec is not None:
        grid = list(itertools.product(*(spec["parameters"][key]
                                        for key in ("threshold", "atr_stop", "holding"))))
        rng.shuffle(grid)
        for threshold, atr, holding in grid[:budget]:
            yield {"strategy": spec["strategy"], "threshold": threshold,
                   "atr_stop": atr, "holding": holding}
        return
    for strategy, thresholds in FAMILIES.items():
        grid = list(itertools.product(thresholds, (2.0, 2.75), (5, 15)))
        rng.shuffle(grid)
        for threshold, atr, holding in grid[:budget]:
            yield {"strategy": strategy, "threshold": threshold, "atr_stop": atr, "holding": holding}


def load_spec(path):
    """Load a bounded JSON strategy specification; expressions and code are not accepted."""
    try:
        spec = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as error:
        raise ValueError("INVALID_STRATEGY_SPEC_FILE") from error
    if (not isinstance(spec, dict)
            or set(spec) != {"schema", "strategy", "parameters", "folds", "holdout", "seed", "budget"}
            or spec["schema"] != SPEC_SCHEMA or not isinstance(spec["strategy"], str)
            or spec["strategy"] not in FAMILIES
            or not isinstance(spec["parameters"], dict)
            or set(spec["parameters"]) != {"threshold", "atr_stop", "holding"}):
        raise ValueError("INVALID_STRATEGY_SPEC_SCHEMA")
    ranges = {"threshold": (0.5, 4.0), "atr_stop": (1.0, 5.0), "holding": (1, 30)}
    for key, (minimum, maximum) in ranges.items():
        values = spec["parameters"][key]
        if (not isinstance(values, list) or not values or len(values) > 20
                or any(isinstance(value, bool) or not isinstance(value, (int, float))
                       or not math.isfinite(value) or value < minimum or value > maximum
                       or (key == "holding" and not isinstance(value, int)) for value in values)
                or len(set(values)) != len(values)):
            raise ValueError("INVALID_STRATEGY_SPEC_PARAMETERS")
    if (not isinstance(spec["seed"], int) or isinstance(spec["seed"], bool)
            or not isinstance(spec["budget"], int) or isinstance(spec["budget"], bool)
            or not 1 <= spec["budget"] <= 12):
        raise ValueError("INVALID_STRATEGY_SPEC_SEARCH_BUDGET")
    try:
        folds = [window("{}:{}".format(*item)) for item in spec["folds"]]
        holdout = window("{}:{}".format(*spec["holdout"]))
        development_windows(folds, holdout)
    except (TypeError, ValueError) as error:
        raise ValueError("INVALID_STRATEGY_SPEC_WINDOWS") from error
    return {**spec, "folds": folds, "holdout": holdout}


def code_hash():
    paths = [Path(__file__).resolve(), ROOT / "bin/orfeu.php", ROOT / "config/fixed-universe.php",
             *sorted((ROOT / "app").glob("*.php"))]
    digest = hashlib.sha256()
    for path in paths:
        digest.update(path.name.encode() + b"\0" + hashlib.sha256(path.read_bytes()).digest())
    return digest.hexdigest()


def replay(php, params, period, cutoff, cost, timeout):
    start, end = period
    command = [php, str(ROOT / "bin/orfeu.php"), "research:trial", f"--from={start}", f"--to={end}",
               f"--as-of={cutoff}", f"--cost-multiplier={cost}"]
    command.extend(f"--{key.replace('_', '-')}={value}" for key, value in params.items())
    try:
        response = subprocess.run(command, check=False, cwd=ROOT, capture_output=True, text=True, timeout=timeout)
    except subprocess.TimeoutExpired:
        return {"status": "ERROR", "code": "TRIAL_TIMEOUT"}
    except OSError:
        return {"status": "ERROR", "code": "PHP_CLI_UNAVAILABLE"}
    try:
        result = json.loads(response.stdout if response.returncode == 0 else response.stderr)
        if not isinstance(result, dict):
            raise TypeError("non-object replay")
        return result
    except (TypeError, ValueError):
        return {"status": "ERROR", "code": "INVALID_REPLAY_RESPONSE"}


def comparable_excess(run):
    if run.get("status") != "RESEARCH_ONLY" or not run.get("sessions") or run.get("ledger_reconciled") is not True:
        return None
    results = run["strategy_results"]
    candidate, baseline = results["candidate"], results["baseline"]
    if (run["eod_sessions"] / run["sessions"] < .95 or candidate["stale_snapshots"] or baseline["stale_snapshots"]
            or candidate["cdi_total"] is None or baseline["cdi_total"] is None):
        return None
    references = [baseline["total_return"], candidate["cdi_total"]]
    basket = run.get("basket_equal_weight_close_to_close_theoretical")
    if basket is not None:
        references.append(basket)
    values = [candidate["total_return"], candidate["max_drawdown"], *references]
    if not all(math.isfinite(value) for value in values):
        return None
    return candidate["total_return"] - max(references)


def objective(runs):
    excess = [comparable_excess(run) for run in runs]
    if not excess or any(value is None for value in excess):
        return None, "INSUFFICIENT_COMPARABLE_DATA"
    results = [run["strategy_results"]["candidate"] for run in runs]
    if sum(item["closed_trades"] for item in results) < 40:
        return None, "INSUFFICIENT_CLOSED_TRADES"
    drawdown = max(-item["max_drawdown"] for item in results)
    score = statistics.median(excess) - .7 * drawdown - .3 * statistics.pstdev(excess)
    return score, None


def write_record(stream, item):
    stream.write(json.dumps(item, sort_keys=True, allow_nan=False) + "\n")
    stream.flush()


def search(args):
    spec_path = getattr(args, "spec", None)
    if spec_path is not None:
        if args.fold or args.holdout is not None:
            raise ValueError("SPEC_CANNOT_MIX_WITH_WINDOW_ARGUMENTS")
        spec = load_spec(spec_path)
        folds, holdout, seed, budget = spec["folds"], spec["holdout"], spec["seed"], spec["budget"]
    else:
        if not args.fold or args.holdout is None:
            raise ValueError("DEVELOPMENT_WINDOWS_REQUIRED")
        spec = None
        folds, holdout, seed, budget = args.fold, args.holdout, args.seed, args.budget
    dev = development_windows(folds, holdout)
    if dev[-1][1] >= utc(args.as_of).date().isoformat():
        raise ValueError("DEVELOPMENT_NOT_COMPLETE")
    args.output.mkdir(parents=True, exist_ok=True)
    code = code_hash()
    fingerprints = {}
    trials = []
    with (args.output / "trials.jsonl").open("x", encoding="utf-8") as logs:
        write_record(logs, {"schema": SCHEMA, "development": dev, "holdout_reserved": holdout,
                            "as_of": args.as_of, "seed": seed, "code_sha256": code,
                            "strategy_spec_sha256": hashlib.sha256(spec_path.read_bytes()).hexdigest()
                            if spec_path is not None else None})
        for params in candidates(seed, budget, spec):
            runs = []
            for period in dev:
                result = replay(args.php, params, period, args.as_of, 1, args.timeout)
                fingerprint = result.get("replay_input_sha256")
                if fingerprint and fingerprints.setdefault(period, fingerprint) != fingerprint:
                    result = {"status": "ERROR", "code": "REPLAY_INPUTS_CHANGED"}
                runs.append(result)
            score, reason = objective(runs)
            trial = {"candidate": params, "folds": runs, "score": score, "reason": reason}
            trials.append(trial)
            write_record(logs, trial)
            if any(run.get("code") == "REPLAY_INPUTS_CHANGED" for run in runs):
                break
    changed = any(run.get("code") == "REPLAY_INPUTS_CHANGED" for trial in trials for run in trial["folds"])
    ranked = sorted((trial for trial in trials if trial["score"] is not None),
                    key=lambda trial: trial["score"], reverse=True)
    selected = ranked[0]["candidate"] if ranked and ranked[0]["score"] > 0 and not changed else None
    report = {"schema": SCHEMA, "phase": "DEVELOPMENT", "status": "FROZEN" if selected else "NO_CANDIDATE",
              "candidate": selected, "as_of": args.as_of, "holdout": holdout, "development": dev,
              "code_sha256": code, "seed": seed, "trials": len(trials),
              "fold_input_sha256": [{"period": period, "sha256": value} for period, value in fingerprints.items()],
              "reason": "REPLAY_INPUTS_CHANGED" if changed else None,
              "blocked_codes": sorted({run["code"] for trial in trials for run in trial["folds"] if "code" in run}),
              "multiple_testing_adjustment": None}
    with (args.output / "development.json").open("x", encoding="utf-8") as output:
        write_record(output, report)
    print(json.dumps(report))
    return 0 if selected else 2


def validate(args):
    raw = args.frozen.read_bytes()
    if hashlib.sha256(raw).hexdigest() != args.sha256:
        raise ValueError("FACTORY_ARTIFACT_HASH_MISMATCH")
    frozen = json.loads(raw)
    if frozen["schema"] != SCHEMA or frozen["status"] != "FROZEN" or frozen["code_sha256"] != code_hash():
        raise ValueError("FACTORY_CANDIDATE_NOT_FROZEN_FOR_THIS_CODE")
    holdout = tuple(frozen["holdout"])
    development_windows([tuple(item) for item in frozen["development"]], holdout)
    if utc(args.as_of) < utc(frozen["as_of"]) or holdout[1] >= utc(args.as_of).date().isoformat():
        raise ValueError("HOLDOUT_NOT_COMPLETE")
    path = args.frozen.parent / f"holdout-{args.sha256}.jsonl"
    summary = path.with_suffix(".json")
    if summary.exists():
        raise FileExistsError("HOLDOUT_ALREADY_RECORDED")
    with path.open("x", encoding="utf-8") as output:
        write_record(output, {"status": "STARTED", "artifact_sha256": args.sha256, "as_of": args.as_of})
        runs = []
        for cost in (1, 2):
            run = replay(args.php, frozen["candidate"], holdout, args.as_of, cost, args.timeout)
            runs.append(run)
            write_record(output, {"cost_multiplier": cost, "result": run})
        excess = [comparable_excess(run) for run in runs]
        fingerprint = runs[0].get("replay_input_sha256")
        same_inputs = bool(fingerprint) and fingerprint == runs[1].get("replay_input_sha256")
        passed = same_inputs and all(value is not None and value > 0 for value in excess) and all(
            run["strategy_results"]["candidate"]["closed_trades"] > 0 and
            run["strategy_results"]["candidate"]["max_drawdown"] >= -.10 for run in runs
        )
        report = {"schema": SCHEMA, "phase": "HOLDOUT", "status": "REVIEW_FOR_SHADOW" if passed else "REJECTED",
                  "artifact_sha256": args.sha256, "holdout": holdout, "excess": excess,
                  "same_inputs_under_stress": same_inputs, "auto_promoted": False}
        write_record(output, report)
    with summary.open("x", encoding="utf-8") as output:
        write_record(output, report)
    print(json.dumps(report))
    return 0 if passed else 2


def main():
    parser = argparse.ArgumentParser()
    commands = parser.add_subparsers(dest="command", required=True)
    development = commands.add_parser("search")
    development.add_argument("--fold", type=window, action="append")
    development.add_argument("--holdout", type=window)
    development.add_argument("--spec", type=Path)
    development.add_argument("--output", type=Path, required=True)
    development.add_argument("--seed", type=int, default=2026)
    development.add_argument("--budget", type=int, choices=range(1, 13), default=4)
    validation = commands.add_parser("validate")
    validation.add_argument("--frozen", type=Path, required=True)
    validation.add_argument("--sha256", required=True)
    for command in (development, validation):
        command.add_argument("--php", default="php")
        command.add_argument("--as-of", required=True)
        command.add_argument("--timeout", type=int, default=120)
    args = parser.parse_args()
    if not 1 <= args.timeout <= 600:
        parser.error("INVALID_TIMEOUT")
    try:
        return search(args) if args.command == "search" else validate(args)
    except (ValueError, OSError, KeyError) as error:
        print(json.dumps({"status": "ERROR", "code": type(error).__name__, "reason": str(error)}), file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
