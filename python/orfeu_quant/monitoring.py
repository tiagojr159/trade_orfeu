"""Point-in-time model input drift diagnostics; never retrains or promotes models."""

import math


def feature_drift_report(reference, current, reference_as_of, current_as_of, *, bins=10,
                         minimum_sample=30, psi_watch=0.10, psi_alert=0.25):
    """Compare dated feature samples with PSI and a two-sample KS diagnostic.

    PSI cutoffs are review heuristics, not statistical guarantees. Missing values
    form their own PSI bucket; KS is computed only on finite observations.
    """
    blocked = {"dynamic_rotation": False, "model_promotion": False, "retraining": False}
    if not isinstance(reference_as_of, str) or not isinstance(current_as_of, str):
        return {"status": "BLOCKED_MISSING_CUTOFF", **blocked}
    if current_as_of <= reference_as_of:
        return {"status": "BLOCKED_NON_CHRONOLOGICAL_WINDOWS", **blocked}
    if (not isinstance(reference, dict) or not isinstance(current, dict)
            or set(reference) != set(current) or not reference):
        return {"status": "BLOCKED_FEATURE_SCHEMA_MISMATCH", **blocked}
    if (not isinstance(bins, int) or bins < 2 or not isinstance(minimum_sample, int)
            or minimum_sample < 2 or not 0 <= psi_watch < psi_alert):
        return {"status": "BLOCKED_INVALID_CONFIGURATION", **blocked}

    try:
        import numpy as np
        from scipy.stats import ks_2samp
    except ImportError as error:
        return {"status": "BLOCKED_DEPENDENCY", "missing": error.name, **blocked}

    reports = []
    for name in sorted(reference):
        try:
            ref = np.asarray(reference[name], dtype=float).reshape(-1)
            cur = np.asarray(current[name], dtype=float).reshape(-1)
        except (TypeError, ValueError):
            return {"status": "BLOCKED_INVALID_FEATURE_DATA", "feature": name, **blocked}
        if not np.isfinite(ref[~np.isnan(ref)]).all() or not np.isfinite(cur[~np.isnan(cur)]).all():
            return {"status": "BLOCKED_INVALID_FEATURE_DATA", "feature": name, **blocked}
        ref = ref[~np.isnan(ref)]
        cur = cur[~np.isnan(cur)]
        if len(ref) < minimum_sample or len(cur) < minimum_sample:
            reports.append({"feature": name, "status": "BLOCKED_INSUFFICIENT_DATA",
                            "reference_count": len(ref), "current_count": len(cur)})
            continue

        original_ref_count = int(np.asarray(reference[name]).size)
        original_cur_count = int(np.asarray(current[name]).size)
        ref_missing = 1 - len(ref) / max(1, original_ref_count)
        cur_missing = 1 - len(cur) / max(1, original_cur_count)
        quantiles = np.linspace(0, 1, bins + 1)[1:-1]
        edges = np.unique(np.quantile(ref, quantiles))
        ref_counts = np.histogram(ref, bins=np.concatenate(([-np.inf], edges, [np.inf])))[0]
        cur_counts = np.histogram(cur, bins=np.concatenate(([-np.inf], edges, [np.inf])))[0]
        ref_parts = np.append(ref_counts / len(ref) * (1 - ref_missing), ref_missing)
        cur_parts = np.append(cur_counts / len(cur) * (1 - cur_missing), cur_missing)
        ref_parts = np.maximum(ref_parts, 1e-6)
        cur_parts = np.maximum(cur_parts, 1e-6)
        ref_parts /= ref_parts.sum()
        cur_parts /= cur_parts.sum()
        psi = float(np.sum((cur_parts - ref_parts) * np.log(cur_parts / ref_parts)))
        ks = ks_2samp(ref, cur, method="auto")
        status = "DRIFT_ALERT" if psi >= psi_alert else "DRIFT_WATCH" if psi >= psi_watch else "STABLE"
        reports.append({"feature": name, "status": status, "psi": psi,
                        "ks_statistic": float(ks.statistic), "ks_pvalue": float(ks.pvalue),
                        "reference_count": len(ref), "current_count": len(cur),
                        "reference_missing_rate": float(ref_missing),
                        "current_missing_rate": float(cur_missing)})

    if any(item["status"] == "BLOCKED_INSUFFICIENT_DATA" for item in reports):
        status = "PARTIAL_INSUFFICIENT_DATA"
    elif any(item["status"] == "DRIFT_ALERT" for item in reports):
        status = "DRIFT_ALERT"
    elif any(item["status"] == "DRIFT_WATCH" for item in reports):
        status = "DRIFT_WATCH"
    else:
        status = "STABLE"
    return {"status": status, "reference_as_of": reference_as_of,
            "current_as_of": current_as_of, "features": reports,
            "thresholds": {"psi_watch": psi_watch, "psi_alert": psi_alert,
                           "interpretation": "HEURISTIC_REVIEW_THRESHOLDS"}, **blocked}


def prediction_quality_drift(reference, current, reference_as_of, current_as_of, *,
                             labels_matured=False, minimum_sample=30,
                             brier_degradation=0.05, calibration_degradation=0.05):
    """Compare matured binary forecast quality across ordered evaluation windows."""
    blocked = {"dynamic_rotation": False, "model_promotion": False, "retraining": False}
    if not isinstance(reference_as_of, str) or not isinstance(current_as_of, str):
        return {"status": "BLOCKED_MISSING_CUTOFF", **blocked}
    if current_as_of <= reference_as_of:
        return {"status": "BLOCKED_NON_CHRONOLOGICAL_WINDOWS", **blocked}
    if not labels_matured:
        return {"status": "BLOCKED_IMMATURE_LABELS", **blocked}
    if (not isinstance(reference, dict) or not isinstance(current, dict)
            or set(reference) != {"probability", "label"}
            or set(current) != {"probability", "label"}):
        return {"status": "BLOCKED_INVALID_EVALUATION_SCHEMA", **blocked}
    if (not isinstance(minimum_sample, int) or minimum_sample < 2
            or not math.isfinite(brier_degradation) or brier_degradation < 0
            or not math.isfinite(calibration_degradation) or calibration_degradation < 0):
        return {"status": "BLOCKED_INVALID_CONFIGURATION", **blocked}

    def summarize(window):
        probabilities = list(window["probability"])
        labels = list(window["label"])
        if len(probabilities) != len(labels) or len(labels) < minimum_sample:
            return None
        if any(not isinstance(value, (int, float)) or not math.isfinite(value) or not 0 <= value <= 1
               for value in probabilities):
            return False
        if any(not isinstance(value, (int, float)) or value not in (0, 1) for value in labels):
            return False
        brier = sum((p - y) ** 2 for p, y in zip(probabilities, labels, strict=True)) / len(labels)
        calibration_gap = abs(sum(probabilities) / len(probabilities) - sum(labels) / len(labels))
        return {"observations": len(labels), "brier_score": brier,
                "calibration_gap": calibration_gap, "outcome_rate": sum(labels) / len(labels),
                "mean_probability": sum(probabilities) / len(probabilities)}

    baseline = summarize(reference)
    latest = summarize(current)
    if baseline is False or latest is False:
        return {"status": "BLOCKED_INVALID_EVALUATION_DATA", **blocked}
    if baseline is None or latest is None:
        return {"status": "BLOCKED_INSUFFICIENT_DATA", **blocked}
    brier_change = latest["brier_score"] - baseline["brier_score"]
    calibration_change = latest["calibration_gap"] - baseline["calibration_gap"]
    alert = (brier_change >= brier_degradation
             or calibration_change >= calibration_degradation)
    return {"status": "QUALITY_DRIFT_ALERT" if alert else "NO_MATERIAL_DEGRADATION",
            "reference_as_of": reference_as_of, "current_as_of": current_as_of,
            "reference": baseline, "current": latest,
            "brier_change": brier_change, "calibration_gap_change": calibration_change,
            "thresholds": {"brier_degradation": brier_degradation,
                           "calibration_degradation": calibration_degradation,
                           "interpretation": "HEURISTIC_REVIEW_THRESHOLDS"}, **blocked}
