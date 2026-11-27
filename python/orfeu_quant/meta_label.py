"""Research-only meta-labeling for already-triggered primary strategy signals."""

import argparse
import hashlib
import json
import math
import sys
from datetime import UTC, date, datetime
from pathlib import Path

from .data import COLUMNS, utc


def _as_utc(value):
    if not isinstance(value, datetime):
        return utc(value)
    return value.replace(tzinfo=UTC) if value.tzinfo is None else value.astimezone(UTC)


def matured_meta_samples(opportunities, as_of):
    """Keep only primary signals whose future, after-cost outcome is observable by cutoff."""
    cutoff = utc(as_of)
    rows, seen, excluded = [], set(), {"not_triggered": 0, "not_matured": 0}
    for item in opportunities:
        if not isinstance(item, dict):
            raise TypeError("INVALID_META_SIGNAL_RECORD")
        if item.get("signal_pass") is not True:
            excluded["not_triggered"] += 1
            continue
        signal_id = item.get("signal_id")
        if not isinstance(signal_id, str) or not signal_id or signal_id in seen:
            raise ValueError("INVALID_OR_DUPLICATE_META_SIGNAL_ID")
        seen.add(signal_id)
        try:
            decision_at = utc(item["decision_at"])
            feature_as_of = utc(item["feature_as_of"])
            label_end = utc(item["label_end"])
            label_available_at = utc(item["label_available_at"])
            gross = item["gross_return"]
            cost = item["roundtrip_cost"]
            features = item["features"]
            family = item["signal_family"]
        except (KeyError, TypeError, ValueError) as error:
            raise ValueError("INVALID_META_SIGNAL_RECORD") from error
        if (not isinstance(gross, (int, float)) or isinstance(gross, bool) or not math.isfinite(gross)
                or gross <= -1 or not isinstance(cost, (int, float)) or isinstance(cost, bool)
                or not math.isfinite(cost) or cost < 0 or not isinstance(family, str) or not family
                or not isinstance(features, dict)
                or any(name not in features for name in COLUMNS)):
            raise ValueError("INVALID_META_SIGNAL_PAYLOAD")
        values = [features[name] for name in COLUMNS]
        if any(not isinstance(value, (int, float)) or isinstance(value, bool)
               or not math.isfinite(value) for value in values):
            raise ValueError("INVALID_META_FEATURES")
        if not feature_as_of <= decision_at < label_end <= label_available_at:
            raise ValueError("INVALID_META_LABEL_TIMESTAMPS")
        if label_available_at > cutoff:
            excluded["not_matured"] += 1
            continue
        rows.append({"signal_id": signal_id, "signal_family": family,
                     "feature_as_of": feature_as_of, "decision_at": decision_at, "label_end": label_end,
                     "label_available_at": label_available_at, "features": values,
                     "session": decision_at.date().isoformat(), "as_of": decision_at,
                     "gross_return": float(gross), "roundtrip_cost": float(cost),
                     "meta_label": int(gross > cost)})
    return {"status": "AVAILABLE" if rows else "BLOCKED_NO_MATURED_PRIMARY_SIGNALS",
            "as_of": cutoff.isoformat(), "samples": rows, "excluded": excluded,
            "target": "PRIMARY_SIGNAL_PROFITABLE_AFTER_ROUNDTRIP_COSTS"}


def fit_meta_labeler(train, calibration, test, as_of, *, minimum_train=50,
                     minimum_calibration=20, minimum_test=20):
    """Fit and calibrate a logistic meta-labeler on pre-split matured signals."""
    blocked = {"model_exported": False, "model_promotion": False, "orders_enabled": False}
    try:
        cutoff = utc(as_of)
        raw_partitions = (list(train), list(calibration), list(test))
        if any(any(not isinstance(row, dict) for row in part) for part in raw_partitions):
            raise ValueError("INVALID_META_PARTITION")
        partitions = tuple([
            {**row, "feature_as_of": _as_utc(row["feature_as_of"]),
             "decision_at": _as_utc(row["decision_at"]),
             "label_end": _as_utc(row["label_end"]), "label_available_at": _as_utc(row["label_available_at"])}
            for row in part
        ] for part in raw_partitions)
    except (KeyError, TypeError, ValueError):
        return {"status": "BLOCKED_INVALID_PARTITION", **blocked}
    minima = (minimum_train, minimum_calibration, minimum_test)
    if any(not isinstance(limit, int) or limit < 2 for limit in minima):
        return {"status": "BLOCKED_INVALID_SAMPLE_LIMITS", **blocked}
    if any(len(partition) < limit for partition, limit in zip(partitions, minima, strict=True)):
        return {"status": "BLOCKED_INSUFFICIENT_DATA", "samples": list(map(len, partitions)), **blocked}
    train_rows, cal_rows, test_rows = partitions
    all_rows = train_rows + cal_rows + test_rows
    ids, families = set(), set()
    for row in all_rows:
        signal_id, family = row.get("signal_id"), row.get("signal_family")
        if not isinstance(signal_id, str) or not signal_id or signal_id in ids:
            return {"status": "BLOCKED_DUPLICATE_OR_INVALID_SIGNAL_ID", **blocked}
        if not isinstance(family, str) or not family:
            return {"status": "BLOCKED_INVALID_SIGNAL_FAMILY", **blocked}
        ids.add(signal_id)
        families.add(family)
        gross, cost, label = row.get("gross_return"), row.get("roundtrip_cost"), row.get("meta_label")
        if (not isinstance(gross, (int, float)) or isinstance(gross, bool) or not math.isfinite(gross)
                or gross <= -1 or not isinstance(cost, (int, float)) or isinstance(cost, bool)
                or not math.isfinite(cost) or cost < 0 or label not in (0, 1)
                or label != int(gross > cost)):
            return {"status": "BLOCKED_INVALID_META_TARGET", **blocked}
        if not row["feature_as_of"] <= row["decision_at"] < row["label_end"] <= row["label_available_at"]:
            return {"status": "BLOCKED_INVALID_META_TIMESTAMPS", **blocked}
        if row["label_available_at"] > cutoff:
            return {"status": "BLOCKED_IMMATURE_TEST_LABELS", **blocked}
    if len(families) != 1:
        return {"status": "BLOCKED_MIXED_SIGNAL_FAMILIES", **blocked}
    if (max(row["label_available_at"] for row in train_rows)
            >= min(row["decision_at"] for row in cal_rows)
            or max(row["label_available_at"] for row in cal_rows)
            >= min(row["decision_at"] for row in test_rows)):
        return {"status": "BLOCKED_TEMPORAL_LEAKAGE", **blocked}
    if max(row["label_available_at"] for row in test_rows) > cutoff:
        return {"status": "BLOCKED_IMMATURE_TEST_LABELS", **blocked}

    import numpy as np
    from sklearn.linear_model import LogisticRegression
    from sklearn.metrics import brier_score_loss, log_loss
    from sklearn.preprocessing import StandardScaler

    def arrays(rows):
        x = np.asarray([row["features"] for row in rows], dtype=float)
        y = np.asarray([row["meta_label"] for row in rows], dtype=int)
        if x.ndim != 2 or x.shape[1] != len(COLUMNS) or not np.isfinite(x).all() or not np.isin(y, [0, 1]).all():
            raise ValueError("INVALID_META_PARTITION")
        return x, y

    try:
        x_train, y_train = arrays(train_rows)
        x_cal, y_cal = arrays(cal_rows)
        x_test, y_test = arrays(test_rows)
    except (KeyError, TypeError, ValueError):
        return {"status": "BLOCKED_INVALID_PARTITION", **blocked}
    if any(len(np.unique(labels)) < 2 for labels in (y_train, y_cal)):
        return {"status": "BLOCKED_CLASS_SUPPORT", **blocked}

    scaler = StandardScaler().fit(x_train)
    x_train, x_cal, x_test = map(scaler.transform, (x_train, x_cal, x_test))
    base = LogisticRegression(C=1, max_iter=500).fit(x_train, y_train)
    cal_logits = base.decision_function(x_cal).reshape(-1, 1)
    calibrator = LogisticRegression(C=1, max_iter=500).fit(cal_logits, y_cal)
    test_logits = base.decision_function(x_test).reshape(-1, 1)
    probability = calibrator.predict_proba(test_logits)[:, 1]
    baseline = np.full(len(y_test), float(np.mean(y_train)))
    metrics = {
        "brier": float(brier_score_loss(y_test, probability)),
        "log_loss": float(log_loss(y_test, probability, labels=[0, 1])),
        "historical_frequency_brier": float(brier_score_loss(y_test, baseline)),
        "test_prevalence": float(np.mean(y_test)),
        "predicted_probability_mean": float(np.mean(probability)),
    }
    return {"status": "RESEARCH_ONLY_OOS_META_LABEL", "target": "PRIMARY_SIGNAL_PROFITABLE_AFTER_COSTS",
            "signal_family": next(iter(families)),
            "samples": {"train": len(train_rows), "calibration": len(cal_rows), "test": len(test_rows)},
            "decision_windows": {name: [min(row["decision_at"] for row in rows).isoformat(),
                                          max(row["decision_at"] for row in rows).isoformat()]
                                 for name, rows in zip(("train", "calibration", "test"), partitions, strict=True)},
            "calibration": "PLATT_ON_SEPARATE_TEMPORAL_PARTITION", "metrics": metrics,
            "promote_only_if_beats_baseline": metrics["brier"] < metrics["historical_frequency_brier"],
            **blocked}


def temporal_partitions(rows, holdout, calibration_fraction=0.2):
    """Build ordered train/calibration/holdout partitions and purge overlapping labels."""
    try:
        holdout_date = date.fromisoformat(holdout)
    except (TypeError, ValueError) as error:
        raise ValueError("INVALID_META_HOLDOUT_DATE") from error
    if not isinstance(calibration_fraction, (int, float)) or not 0 < calibration_fraction < 0.5:
        raise ValueError("INVALID_META_CALIBRATION_FRACTION")
    sessions = sorted({row["decision_at"].date().isoformat() for row in rows})
    development = [session for session in sessions if session < holdout_date.isoformat()]
    holdout_sessions = [session for session in sessions if session >= holdout_date.isoformat()]
    boundary = int(len(development) * (1 - calibration_fraction))
    if boundary < 1 or not holdout_sessions or boundary >= len(development):
        raise ValueError("INSUFFICIENT_META_TEMPORAL_SESSIONS")
    train_dates, calibration_dates = set(development[:boundary]), set(development[boundary:])
    calibration_start = min(row["decision_at"] for row in rows
                            if row["decision_at"].date().isoformat() in calibration_dates)
    holdout_start = min(row["decision_at"] for row in rows
                        if row["decision_at"].date().isoformat() in set(holdout_sessions))
    train = [row for row in rows if row["decision_at"].date().isoformat() in train_dates
             and row["label_available_at"] < calibration_start]
    calibration = [row for row in rows if row["decision_at"].date().isoformat() in calibration_dates
                   and row["label_available_at"] < holdout_start]
    test = [row for row in rows if row["decision_at"].date().isoformat() in set(holdout_sessions)]
    if not train or not calibration or not test:
        raise ValueError("INSUFFICIENT_PURGED_META_PARTITIONS")
    return train, calibration, test


def walk_forward_meta_validation(rows, holdout, as_of, folds=3):
    """Evaluate rolling meta-label OOF folds, then evaluate the reserved final holdout."""
    from bisect import bisect_left

    from .splits import purged_walk_forward

    sessions = sorted({row["session"] for row in rows})
    positions = {session: index for index, session in enumerate(sessions)}
    gap = max((max(1, bisect_left(sessions, row["label_end"].date().isoformat())
               - positions[row["session"]]) for row in rows), default=1)
    try:
        temporal_folds = purged_walk_forward(rows, sessions, holdout, gap, folds)
        outcomes = [fit_meta_labeler(train, calibration, test, as_of)
                    for train, calibration, test in temporal_folds]
    except ValueError as error:
        return {"status": "BLOCKED_WALK_FORWARD", "reason": str(error), "folds": [],
                "holdout": None, "model_exported": False, "orders_enabled": False}
    if any(item["status"] != "RESEARCH_ONLY_OOS_META_LABEL" for item in outcomes):
        return {"status": "BLOCKED_WALK_FORWARD_FOLD", "folds": outcomes,
                "holdout": None, "model_exported": False, "orders_enabled": False}

    try:
        train, calibration, test = temporal_partitions(rows, holdout)
    except ValueError as error:
        return {"status": "BLOCKED_FINAL_HOLDOUT_SPLIT", "reason": str(error), "folds": outcomes,
                "holdout": None, "model_exported": False, "orders_enabled": False}
    final = fit_meta_labeler(train, calibration, test, as_of)
    if final["status"] != "RESEARCH_ONLY_OOS_META_LABEL":
        return {"status": "BLOCKED_FINAL_HOLDOUT", "folds": outcomes,
                "holdout": final, "model_exported": False, "orders_enabled": False}
    metrics = ("brier", "log_loss", "historical_frequency_brier")
    summary = {metric: {"mean": sum(row["metrics"][metric] for row in outcomes) / len(outcomes),
                        "folds": len(outcomes)} for metric in metrics}
    return {"status": "RESEARCH_ONLY_OOF_META_LABEL", "walk_forward": outcomes,
            "walk_forward_summary": summary, "holdout": final,
            "holdout_used_for_selection": False, "model_exported": False,
            "orders_enabled": False}


def read_meta_export(path):
    """Read a checksummed ORFEU_META_SIGNAL_V1 JSONL evidence file."""
    digest = hashlib.sha256()
    manifest, opportunities = [], []
    try:
        with path.open("rb") as stream:
            for line in stream:
                digest.update(line)
                item = json.loads(line)
                if item.get("kind") == "manifest":
                    manifest.append(item.get("data"))
                elif item.get("kind") == "meta_signal":
                    opportunities.append(item.get("data"))
                else:
                    raise ValueError("INVALID_META_EXPORT_RECORD")
    except (OSError, json.JSONDecodeError, AttributeError) as error:
        raise ValueError("INVALID_META_EXPORT_FILE") from error
    if (len(manifest) != 1 or not isinstance(manifest[0], dict)
            or manifest[0].get("schema") != "ORFEU_META_SIGNAL_V1"):
        raise ValueError("INVALID_META_EXPORT_MANIFEST")
    return opportunities, manifest[0], digest.hexdigest()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--export", type=Path, required=True)
    parser.add_argument("--as-of", required=True)
    parser.add_argument("--holdout", required=True)
    parser.add_argument("--folds", type=int, choices=range(2, 11), default=3)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    try:
        opportunities, manifest, fingerprint = read_meta_export(args.export)
        if utc(manifest["as_of"]) > utc(args.as_of):
            raise ValueError("META_EXPORT_AFTER_AS_OF")
        dataset = matured_meta_samples(opportunities, args.as_of)
        if dataset["status"] != "AVAILABLE":
            report = {"status": dataset["status"], "dataset_sha256": fingerprint,
                      "samples": 0, "excluded": dataset["excluded"],
                      "model_exported": False, "orders_enabled": False}
        else:
            report = walk_forward_meta_validation(dataset["samples"], args.holdout, args.as_of, args.folds)
            report.update({"dataset_sha256": fingerprint, "as_of": utc(args.as_of).isoformat(),
                           "holdout": args.holdout, "excluded": dataset["excluded"]})
        args.output.parent.mkdir(parents=True, exist_ok=True)
        payload = json.dumps(report, sort_keys=True, allow_nan=False).encode()
        with args.output.open("xb") as stream:
            stream.write(payload + b"\n")
        print(json.dumps({"status": report["status"], "report": str(args.output),
                          "report_sha256": hashlib.sha256(payload + b"\n").hexdigest()}))
        return 0 if report["status"] == "RESEARCH_ONLY_OOF_META_LABEL" else 2
    except (KeyError, OSError, ValueError) as error:
        print(json.dumps({"status": "BLOCKED", "reason": str(error)}), file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
