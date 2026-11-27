import argparse
import hashlib
import json
import math
from importlib.metadata import version
from pathlib import Path
from statistics import mean, stdev

from .data import COLUMNS, SCHEMA, panel, read_export
from .metrics import cross_sectional_ic
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
        if fold.get("random_forest") is not None:
            collect("random_forest", {
                "up": fold["random_forest"]["up"],
                "profitable": fold["random_forest"]["profitable"],
                "return_mae": fold["random_forest"]["return_mae"],
                "q10_q90_coverage": fold["random_forest"]["q10_q90_coverage"],
            })
        if (fold.get("xgboost") or {}).get("status") == "AVAILABLE":
            collect("xgboost", {"up": fold["xgboost"]["up"],
                                 "profitable": fold["xgboost"]["profitable"],
                                 "return_mae": fold["xgboost"]["return_mae"],
                                 "q10_q90_coverage": fold["xgboost"]["q10_q90_coverage"]})
    return {
        metric: {"folds": len(samples), "mean": mean(samples),
                 "std": stdev(samples) if len(samples) > 1 else 0.0}
        for metric, samples in sorted(values.items())
    }


def permutation_ablation(features, sessions, realized, regressor, classifiers,
                        repetitions=5, seed=2026):
    """Within-session feature permutation; diagnostic only, never a selection rule."""
    import numpy as np

    matrix_values = np.asarray(features, dtype=float)
    outcomes = np.asarray(realized, dtype=float)
    dates = list(sessions)
    if (matrix_values.ndim != 2 or matrix_values.shape[1] != len(COLUMNS)
            or len(matrix_values) != len(outcomes) or len(outcomes) != len(dates)
            or len(outcomes) < 20 or not np.isfinite(matrix_values).all()
            or not np.isfinite(outcomes).all() or len(set(dates)) < 2
            or not isinstance(repetitions, int) or repetitions < 2):
        return {"status": "BLOCKED_INSUFFICIENT_OR_INVALID_DATA", "features": []}
    groups = [np.asarray([i for i, item in enumerate(dates) if item == day], dtype=int)
              for day in sorted(set(dates))]
    rng = np.random.default_rng(seed)
    baseline_return_mae = float(np.mean(np.abs(outcomes - regressor.predict(matrix_values))))
    baseline_brier = {}
    for key, (model, calibrator, labels) in classifiers.items():
        logits = model.decision_function(matrix_values).reshape(-1, 1)
        probabilities = calibrator.predict_proba(logits)[:, 1]
        baseline_brier[key] = float(np.mean((probabilities - labels) ** 2))

    reports = []
    for column, name in enumerate(COLUMNS):
        changes = {"return_mae": [], **{key + "_brier": [] for key in classifiers}}
        for _ in range(repetitions):
            permuted = matrix_values.copy()
            for indices in groups:
                if len(indices) > 1:
                    permuted[indices, column] = permuted[rng.permutation(indices), column]
            mae = float(np.mean(np.abs(outcomes - regressor.predict(permuted))))
            changes["return_mae"].append(mae - baseline_return_mae)
            for key, (model, calibrator, labels) in classifiers.items():
                logits = model.decision_function(permuted).reshape(-1, 1)
                probabilities = calibrator.predict_proba(logits)[:, 1]
                brier = float(np.mean((probabilities - labels) ** 2))
                changes[key + "_brier"].append(brier - baseline_brier[key])
        reports.append({"feature": name, **{
            metric + "_increase_mean": float(np.mean(values))
            for metric, values in changes.items()
        }, **{
            metric + "_increase_std": float(np.std(values, ddof=1))
            for metric, values in changes.items()
        }})
    return {"status": "RESEARCH_ONLY_PERMUTATION_ABLATION", "repetitions": repetitions,
            "sessions": len(groups), "observations": len(outcomes), "features": reports,
            "selection_used": False, "multiple_testing_adjustment": "NOT_APPLIED"}


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
    ablation_models = {}
    for key, threshold in (("up", 0), ("profitable", cost)):
        y_train, y_cal, y_test = (gross > threshold for gross in (g_train, g_cal, g_test))
        if len(np.unique(y_train)) < 2 or len(np.unique(y_cal)) < 2:
            raise ValueError("INSUFFICIENT_CLASS_SUPPORT")
        base = LogisticRegression(C=1, max_iter=500).fit(x_train, y_train)
        z_cal = base.decision_function(x_cal).reshape(-1, 1)
        calibrator = LogisticRegression(C=1, max_iter=500).fit(z_cal, y_cal)
        z_test = base.decision_function(x_test).reshape(-1, 1)
        probabilities = calibrator.predict_proba(z_test)[:, 1]
        ablation_models[key] = (base, calibrator, y_test)
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
        "cross_sectional_ic": cross_sectional_ic(
            g_test.tolist(), pred.tolist(), [row["session"] for row in test]),
        "permutation_ablation": permutation_ablation(
            x_test, [row["session"] for row in test], g_test, ret, ablation_models),
    }
    return artifact, metrics


def fit_challenger(train, cal, test, cost, export=False):
    import numpy as np
    from lightgbm import LGBMClassifier, LGBMRegressor
    from sklearn.linear_model import LogisticRegression
    from sklearn.metrics import mean_absolute_error

    x_train, g_train = matrix(train)
    x_cal, g_cal = matrix(cal)
    x_test, g_test = matrix(test)
    params = {"n_estimators": 120, "learning_rate": .03, "max_depth": 5, "num_leaves": 15,
              "min_child_samples": 25, "random_state": 2026, "n_jobs": 2, "verbosity": -1}
    boosters, calibration, metrics = {}, {}, {}
    for name, threshold in (("up", 0), ("profitable", cost)):
        train_classes, cal_classes = g_train > threshold, g_cal > threshold
        if len(np.unique(train_classes)) != 2 or len(np.unique(cal_classes)) != 2:
            raise ValueError("CALIBRATION_CLASS_MISSING")
        boosters[name] = LGBMClassifier(**params).fit(x_train, train_classes).booster_
        cal_logits = boosters[name].predict(x_cal, raw_score=True).reshape(-1, 1)
        test_logits = boosters[name].predict(x_test, raw_score=True).reshape(-1, 1)
        calibrator = LogisticRegression(C=1).fit(cal_logits, cal_classes)
        calibration[name] = [float(calibrator.coef_[0, 0]), float(calibrator.intercept_[0])]
        metrics[name] = evaluate(calibrator.predict_proba(test_logits)[:, 1], g_test > threshold)
    boosters["return"] = LGBMRegressor(**params).fit(x_train, g_train).booster_
    quantiles = []
    for name, q in (("q10", .1), ("q50", .5), ("q90", .9)):
        boosters[name] = LGBMRegressor(objective="quantile", alpha=q, **params).fit(x_train, g_train).booster_
        quantiles.append(boosters[name].predict(x_test))
    ordered = np.sort(quantiles, axis=0)
    report = {
        "package": version("lightgbm"), **metrics,
        "return_mae": float(mean_absolute_error(g_test, boosters["return"].predict(x_test))),
        "q10_q90_coverage": float(np.mean((g_test >= ordered[0]) & (g_test <= ordered[2]))),
        "quantile_crossing": float(np.mean((quantiles[0] > quantiles[1]) | (quantiles[1] > quantiles[2]))),
        "parameters": params,
        "iterations": {key: booster.current_iteration() for key, booster in boosters.items()},
    }
    if not export:
        return report
    artifact = {
        "format": "ORFEU_LIGHTGBM_NATIVE_V1",
        "runtime": report["package"],
        "parameters": params,
        "iterations": report["iterations"],
        "boosters": {key: booster.model_to_string() for key, booster in boosters.items()},
        "calibration": calibration,
    }
    return report, artifact


def fit_random_forest(train, cal, test, cost):
    """Calibrated Random Forest challenger; reports OOS metrics and never exports a model."""
    import numpy as np
    from sklearn.ensemble import RandomForestClassifier, RandomForestRegressor
    from sklearn.linear_model import LogisticRegression
    from sklearn.metrics import mean_absolute_error

    x_train, g_train = matrix(train)
    x_cal, g_cal = matrix(cal)
    x_test, g_test = matrix(test)
    params = {"n_estimators": 300, "min_samples_leaf": 10, "max_features": .7,
              "random_state": 2026, "n_jobs": 2, "class_weight": "balanced_subsample"}
    classifiers, calibrations, scores = {}, {}, {}
    for name, threshold in (("up", 0), ("profitable", cost)):
        y_train, y_cal, y_test = (gross > threshold for gross in (g_train, g_cal, g_test))
        if len(np.unique(y_train)) != 2 or len(np.unique(y_cal)) != 2:
            raise ValueError("CALIBRATION_CLASS_MISSING")
        model = RandomForestClassifier(**params).fit(x_train, y_train)
        cal_probability = np.clip(model.predict_proba(x_cal)[:, 1], 1e-6, 1 - 1e-6)
        cal_logit = np.log(cal_probability / (1 - cal_probability)).reshape(-1, 1)
        calibrator = LogisticRegression(C=1, max_iter=500).fit(cal_logit, y_cal)
        test_probability = np.clip(model.predict_proba(x_test)[:, 1], 1e-6, 1 - 1e-6)
        test_logit = np.log(test_probability / (1 - test_probability)).reshape(-1, 1)
        scores[name] = evaluate(calibrator.predict_proba(test_logit)[:, 1], y_test)
        classifiers[name] = {"trees": params["n_estimators"], "calibration": "PLATT_ON_HELDOUT_CALIBRATION"}
        calibrations[name] = [float(calibrator.coef_[0, 0]), float(calibrator.intercept_[0])]

    regressor_params = {key: value for key, value in params.items() if key != "class_weight"}
    regressor = RandomForestRegressor(**regressor_params).fit(x_train, g_train)
    cal_prediction = regressor.predict(x_cal)
    residuals = g_cal - cal_prediction
    q10, q50, q90 = np.quantile(residuals, [.1, .5, .9]).tolist()
    prediction = regressor.predict(x_test)
    return {
        "model": "RANDOM_FOREST_RESEARCH_ONLY", "package": version("scikit-learn"),
        "up": scores["up"], "profitable": scores["profitable"],
        "return_mae": float(mean_absolute_error(g_test, prediction)),
        "historical_mean_mae": float(mean_absolute_error(g_test, np.full(len(g_test), g_train.mean()))),
        "q10_q90_coverage": float(np.mean((g_test >= prediction + q10) & (g_test <= prediction + q90))),
        "calibration": calibrations, "classifiers": classifiers, "regressor": regressor_params,
        "interval_residual_quantiles": [float(q10), float(q50), float(q90)],
        "promotion_status": "NOT_EVALUATED_WITHOUT_MULTIPLE_REAL_OOS_FOLDS",
    }


def fit_xgboost(train, cal, test, cost):
    """Optional calibrated XGBoost challenger on the baseline's purged temporal splits."""
    try:
        import xgboost as xgb
    except ImportError:
        return {"status": "BLOCKED_DEPENDENCY", "missing": "xgboost"}
    import numpy as np
    from sklearn.linear_model import LogisticRegression
    from sklearn.metrics import mean_absolute_error

    x_train, g_train = matrix(train)
    x_cal, g_cal = matrix(cal)
    x_test, g_test = matrix(test)
    params = {"n_estimators": 300, "max_depth": 3, "learning_rate": .03,
              "subsample": .8, "colsample_bytree": .8, "reg_lambda": 1.0,
              "random_state": 2026, "n_jobs": 2, "verbosity": 0}
    scores, calibration = {}, {}
    for name, threshold in (("up", 0), ("profitable", cost)):
        y_train, y_cal, y_test = (gross > threshold for gross in (g_train, g_cal, g_test))
        if len(np.unique(y_train)) != 2 or len(np.unique(y_cal)) != 2:
            raise ValueError("CALIBRATION_CLASS_MISSING")
        model = xgb.XGBClassifier(objective="binary:logistic", eval_metric="logloss", **params)
        model.fit(x_train, y_train)
        cal_probability = np.clip(model.predict_proba(x_cal)[:, 1], 1e-6, 1 - 1e-6)
        cal_logit = np.log(cal_probability / (1 - cal_probability)).reshape(-1, 1)
        calibrator = LogisticRegression(C=1, max_iter=500).fit(cal_logit, y_cal)
        test_probability = np.clip(model.predict_proba(x_test)[:, 1], 1e-6, 1 - 1e-6)
        test_logit = np.log(test_probability / (1 - test_probability)).reshape(-1, 1)
        scores[name] = evaluate(calibrator.predict_proba(test_logit)[:, 1], y_test)
        calibration[name] = [float(calibrator.coef_[0, 0]), float(calibrator.intercept_[0])]
    regressor = xgb.XGBRegressor(objective="reg:squarederror", **params).fit(x_train, g_train)
    residuals = g_cal - regressor.predict(x_cal)
    q10, q50, q90 = np.quantile(residuals, [.1, .5, .9]).tolist()
    prediction = regressor.predict(x_test)
    return {
        "status": "AVAILABLE", "model": "XGBOOST_RESEARCH_ONLY", "package": xgb.__version__,
        "up": scores["up"], "profitable": scores["profitable"], "calibration": calibration,
        "return_mae": float(mean_absolute_error(g_test, prediction)),
        "historical_mean_mae": float(mean_absolute_error(g_test, np.full(len(g_test), g_train.mean()))),
        "q10_q90_coverage": float(np.mean((g_test >= prediction + q10) & (g_test <= prediction + q90))),
        "interval_residual_quantiles": [float(q10), float(q50), float(q90)],
        "parameters": params, "promotion_status": "NOT_EVALUATED_WITHOUT_MULTIPLE_REAL_OOS_FOLDS",
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
    parser.add_argument("--random-forest", action="store_true",
                        help="evaluate a calibrated Random Forest challenger on the same purged folds")
    parser.add_argument("--xgboost", action="store_true",
                        help="evaluate an optional calibrated XGBoost challenger on the same purged folds")
    args = parser.parse_args()
    if not 0 < args.cost < .1:
        parser.error("cost must be a fraction in (0, 0.1)")
    data, fingerprint = read_export(args.file)
    horizon = int(args.horizon[:-1])
    rows = panel(data, horizon)
    if not rows:
        print(json.dumps({"status": "BLOCKED_INSUFFICIENT_DATA", "reason": "NO_MATURE_LABELS", "samples": 0}))
        return 2
    challenger_artifact = None
    try:
        sessions = [row["session"] for row in rows]
        train, cal, test = purged_split(rows, sessions, args.holdout, horizon + 1)
        artifact, metrics = fit_linear(train, cal, test, args.cost)
        challenger = None
        if args.challenger:
            challenger, challenger_artifact = fit_challenger(train, cal, test, args.cost, export=True)
        random_forest = fit_random_forest(train, cal, test, args.cost) if args.random_forest else None
        xgboost = fit_xgboost(train, cal, test, args.cost) if args.xgboost else None
        walk_forward = []
        if args.walk_forward_folds:
            for fold_train, fold_cal, fold_test in purged_walk_forward(
                    rows, sessions, args.holdout, horizon + 1, args.walk_forward_folds):
                _, fold_metrics = fit_linear(fold_train, fold_cal, fold_test, args.cost)
                fold_challenger = fit_challenger(fold_train, fold_cal, fold_test, args.cost) \
                    if args.challenger else None
                fold_random_forest = fit_random_forest(fold_train, fold_cal, fold_test, args.cost) \
                    if args.random_forest else None
                fold_xgboost = fit_xgboost(fold_train, fold_cal, fold_test, args.cost) if args.xgboost else None
                walk_forward.append({
                    "train_end": max(row["session"] for row in fold_train),
                    "test_start": min(row["session"] for row in fold_test),
                    "test_end": max(row["session"] for row in fold_test),
                    "train_samples": len(fold_train), "calibration_samples": len(fold_cal),
                    "test_samples": len(fold_test), "metrics": fold_metrics,
                    "challenger": fold_challenger, "random_forest": fold_random_forest,
                    "xgboost": fold_xgboost,
                })
    except ModuleNotFoundError as error:
        print(json.dumps({"status": "MODEL_RUNTIME_UNAVAILABLE", "dependency": error.name}))
        return 2
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
           "random_forest": random_forest, "xgboost": xgboost,
           "walk_forward": walk_forward, "walk_forward_summary": summarize_walk_forward(walk_forward)}
    stem = args.horizon + "-" + hashlib.sha256(json.dumps(run, sort_keys=True).encode()).hexdigest()[:16]
    model = args.output / (stem + ".model.json")
    report = args.output / (stem + ".research.json")
    result = {"status": "SHADOW", "model": str(model), "model_sha256": write_artifact(model, artifact),
              "report": str(report), "report_sha256": write_artifact(report, run)}
    if challenger_artifact:
        challenger_artifact.update({key: artifact[key] for key in (
            "horizon", "schema", "columns", "trained_until", "effective_at", "cost", "dataset_sha256", "fold")})
        challenger_path = args.output / (stem + ".lightgbm.json")
        result["challenger_model"] = str(challenger_path)
        result["challenger_sha256"] = write_artifact(challenger_path, challenger_artifact)
    print(json.dumps(result))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
