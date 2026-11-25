import argparse
import hashlib
import json
import math
from statistics import mean, stdev
from importlib.metadata import version
from pathlib import Path

from .data import COLUMNS, SCHEMA, panel, read_export
from .splits import purged_split, purged_walk_forward


def evaluate(prob, realized):
    from sklearn.metrics import brier_score_loss, log_loss

    return {
        "brier": float(brier_score_loss(realized, prob)),
        "log_loss": float(log_loss(realized, prob, labels=[False, True])),
    }


def matrix(rows):
    import numpy as np

    features = np.asarray([row["features"] for row in rows], dtype=float)
    gross = np.asarray([row["gross"] for row in rows], dtype=float)
    if not np.isfinite(features).all() or not np.isfinite(gross).all():
        raise ValueError("NONFINITE_INPUT")
    return features, gross


def summarize_walk_forward(folds):
    values = {}

    def collect(prefix, item):
        if isinstance(item, dict):
            for key, value in item.items():
                collect(prefix + "." + key if prefix else key, value)
        elif isinstance(item, (int, float)) and not isinstance(item, bool) and math.isfinite(item):
            values.setdefault(prefix, []).append(float(item))

    for fold in folds:
        collect("linear", fold["metrics"])
        if fold["challenger"] is not None:
            collect("challenger", {
                "profitable": fold["challenger"]["profitable"],
                "return_mae": fold["challenger"]["return_mae"],
                "q10_q90_coverage": fold["challenger"]["q10_q90_coverage"],
            })
    return {
        metric: {"folds": len(samples), "mean": mean(samples),
                 "std": stdev(samples) if len(samples) > 1 else 0.0}
        for metric, samples in sorted(values.items())
    }


def fit_linear(train, cal, test, cost):
    import numpy as np
    from sklearn.linear_model import LogisticRegression, Ridge
    from sklearn.metrics import mean_absolute_error
    from sklearn.preprocessing import StandardScaler

    x_train, g_train = matrix(train)
    x_cal, g_cal = matrix(cal)
    x_test, g_test = matrix(test)
    scaler = StandardScaler().fit(x_train)
    x_train, x_cal, x_test = map(scaler.transform, (x_train, x_cal, x_test))
    artifact = {"mean": scaler.mean_.tolist(), "scale": scaler.scale_.tolist()}
    metrics = {}
    for key, threshold in (("up", 0), ("profitable", cost)):
        y_train, y_cal, y_test = (gross > threshold for gross in (g_train, g_cal, g_test))
        if len(np.unique(y_train)) < 2 or len(np.unique(y_cal)) < 2:
            raise ValueError("INSUFFICIENT_CLASS_SUPPORT")
        base = LogisticRegression(C=1, max_iter=500).fit(x_train, y_train)
        z_cal = base.decision_function(x_cal).reshape(-1, 1)
        calibrator = LogisticRegression(C=1, max_iter=500).fit(z_cal, y_cal)
        z_test = base.decision_function(x_test).reshape(-1, 1)
        probabilities = calibrator.predict_proba(z_test)[:, 1]
        artifact.update({
            key: base.coef_[0].tolist(), key + "_bias": float(base.intercept_[0]),
            key + "_a": float(calibrator.coef_[0, 0]), key + "_b": float(calibrator.intercept_[0]),
        })
        metrics[key] = {
            "model": evaluate(probabilities, y_test),
            "historical_frequency": evaluate(np.full(len(y_test), np.mean(y_train)), y_test),
            "momentum_sign": evaluate(np.where(x_test[:, 1] > 0, .55, .45), y_test),
        }
    ret = Ridge(alpha=10).fit(x_train, g_train)
    residuals = g_cal - ret.predict(x_cal)
    cal_sessions = sorted({row["session"] for row in cal})
    session_residuals = np.array([
        np.mean([r for row, r in zip(cal, residuals) if row["session"] == date])
        for date in cal_sessions
    ])
    blocks = [session_residuals[i:i + 5].mean() for i in range(0, len(session_residuals) - 4, 5)]
    if len(blocks) < 3:
        raise ValueError("INSUFFICIENT_CALIBRATION_BLOCKS")
    q10, q50, q90 = np.quantile(residuals, [.1, .5, .9]).tolist()
    artifact.update({
        "return": ret.coef_.tolist(), "return_bias": float(ret.intercept_),
        "q10": q10, "q50": q50, "q90": q90,
        "mu_se": float(np.std(blocks, ddof=1) / np.sqrt(len(blocks))),
    })
    pred = ret.predict(x_test)
    metrics["return"] = {
        "mae": float(mean_absolute_error(g_test, pred)),
        "historical_mean_mae": float(mean_absolute_error(g_test, np.full(len(g_test), g_train.mean()))),
        "q10_q90_coverage": float(np.mean((g_test >= pred + q10) & (g_test <= pred + q90))),
    }
    return artifact, metrics


def fit_challenger(train, cal, test, cost):
    import numpy as np
    from lightgbm import LGBMClassifier, LGBMRegressor
    from sklearn.linear_model import LogisticRegression
    from sklearn.metrics import mean_absolute_error

    x_train, g_train = matrix(train)
    x_cal, g_cal = matrix(cal)
    x_test, g_test = matrix(test)
    params = dict(n_estimators=120, learning_rate=.03, max_depth=5, num_leaves=15,
                  min_child_samples=25, random_state=2026, n_jobs=2, verbosity=-1)
    base = LGBMClassifier(**params).fit(x_train, g_train > cost)
    cal_logits = base.booster_.predict(x_cal, raw_score=True).reshape(-1, 1)
    test_logits = base.booster_.predict(x_test, raw_score=True).reshape(-1, 1)
    calibrator = LogisticRegression(C=1).fit(cal_logits, g_cal > cost)
    probabilities = calibrator.predict_proba(test_logits)[:, 1]
    return_model = LGBMRegressor(**params).fit(x_train, g_train)
    quantiles = [
        LGBMRegressor(objective="quantile", alpha=q, **params).fit(x_train, g_train).booster_.predict(x_test)
        for q in (.1, .5, .9)
    ]
    ordered = np.sort(quantiles, axis=0)
    return {
        "package": version("lightgbm"), "profitable": evaluate(probabilities, g_test > cost),
        "return_mae": float(mean_absolute_error(g_test, return_model.booster_.predict(x_test))),
        "q10_q90_coverage": float(np.mean((g_test >= ordered[0]) & (g_test <= ordered[2]))),
        "parameters": params,
    }


def write_artifact(path, value):
    raw = (json.dumps(value, ensure_ascii=False, allow_nan=False, sort_keys=True, indent=2) + "\n").encode()
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("xb") as output:
        output.write(raw)
    return hashlib.sha256(raw).hexdigest()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--file", required=True, type=Path)
    parser.add_argument("--horizon", choices=["1D", "5D", "20D"], default="5D")
    parser.add_argument("--holdout", required=True)
    parser.add_argument("--walk-forward-folds", default=3, type=int)
    parser.add_argument("--cost", default=.005, type=float)
    parser.add_argument("--output", default=Path("storage/models"), type=Path)
    parser.add_argument("--challenger", action="store_true")
    args = parser.parse_args()
    if not 0 < args.cost < .1:
        parser.error("cost must be a fraction in (0, 0.1)")
    data, fingerprint = read_export(args.file)
    horizon = int(args.horizon[:-1])
    rows = panel(data, horizon)
    try:
        sessions = [row["session"] for row in rows]
        train, cal, test = purged_split(rows, sessions, args.holdout, horizon + 1)
        artifact, metrics = fit_linear(train, cal, test, args.cost)
        challenger = fit_challenger(train, cal, test, args.cost) if args.challenger else None
        walk_forward = []
        if args.walk_forward_folds:
            for fold_train, fold_cal, fold_test in purged_walk_forward(
                    rows, sessions, args.holdout, horizon + 1, args.walk_forward_folds):
                _, fold_metrics = fit_linear(fold_train, fold_cal, fold_test, args.cost)
                fold_challenger = fit_challenger(fold_train, fold_cal, fold_test, args.cost) \
                    if args.challenger else None
                walk_forward.append({
                    "train_end": max(row["session"] for row in fold_train),
                    "test_start": min(row["session"] for row in fold_test),
                    "test_end": max(row["session"] for row in fold_test),
                    "train_samples": len(fold_train), "calibration_samples": len(fold_cal),
                    "test_samples": len(fold_test), "metrics": fold_metrics,
                    "challenger": fold_challenger,
                })
    except ValueError as error:
        print(json.dumps({"status": "BLOCKED_INSUFFICIENT_DATA", "reason": str(error), "samples": len(rows)}))
        return 2
    fold = {
        name: {"samples": len(items), "start": min(row["session"] for row in items),
               "end": max(row["session"] for row in items)}
        for name, items in [("train", train), ("calibration", cal), ("holdout", test)]
    }
    effective = data["manifest"][0]["as_of"]
    artifact.update({
        "horizon": args.horizon, "schema": SCHEMA, "columns": COLUMNS,
        "trained_until": max(r["label_available_at"] for r in train + cal).isoformat(),
        "effective_at": effective, "cost": args.cost, "dataset_sha256": fingerprint, "fold": fold,
        "dependencies": {"scikit-learn": version("scikit-learn"), "numpy": version("numpy")},
    })
    run = {"status": "SHADOW", "dataset_sha256": fingerprint, "horizon": args.horizon,
           "cost": args.cost, "fold": fold, "metrics": metrics, "challenger": challenger,
           "walk_forward": walk_forward, "walk_forward_summary": summarize_walk_forward(walk_forward)}
    stem = args.horizon + "-" + hashlib.sha256(json.dumps(run, sort_keys=True).encode()).hexdigest()[:16]
    model = args.output / (stem + ".model.json")
    report = args.output / (stem + ".research.json")
    print(json.dumps({"status": "SHADOW", "model": str(model), "model_sha256": write_artifact(model, artifact),
                      "report": str(report), "report_sha256": write_artifact(report, run)}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
