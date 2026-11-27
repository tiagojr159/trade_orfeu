import argparse
import hashlib
import json
import math
from importlib.metadata import version
from pathlib import Path

from .data import COLUMNS, SCHEMA, utc


def predict(model, feature, as_of):
    if model["schema"] != SCHEMA or model["columns"] != COLUMNS:
        raise ValueError("INVALID_MODEL_SCHEMA")
    cutoff = utc(as_of)
    if utc(model["trained_until"]) >= cutoff or utc(model["effective_at"]) > cutoff:
        return None
    if any(column not in feature or feature[column] is None for column in COLUMNS):
        return None
    scaled = [
        (feature[column] - mu) / scale
        for column, mu, scale in zip(COLUMNS, model["mean"], model["scale"], strict=True)
    ]

    def score(key):
        value = model[key + "_bias"] + sum(
            v * weight for v, weight in zip(scaled, model[key], strict=True)
        )
        if not math.isfinite(value):
            raise ValueError("INVALID_MODEL_NUMBER")
        return value

    def probability(key):
        logit = model[key + "_a"] * score(key) + model[key + "_b"]
        return 1 / (1 + math.exp(-max(-35, min(35, logit))))

    mu = score("return")
    return {
        "p_up": probability("up"),
        "p_profitable": probability("profitable"),
        "mu_gross": mu,
        "q10": mu + model["q10"],
        "q50": mu + model["q50"],
        "q90": mu + model["q90"],
        "mu_se": model["mu_se"],
        "model_cost": model["cost"],
    }


def load_challenger(model):
    import numpy as np
    from lightgbm import Booster

    if model["schema"] != SCHEMA or model["columns"] != COLUMNS or model["runtime"] != version("lightgbm"):
        raise ValueError("INVALID_CHALLENGER_RUNTIME_OR_SCHEMA")
    keys = ("up", "profitable", "return", "q10", "q50", "q90")
    boosters = {key: Booster(model_str=model["boosters"][key]) for key in keys}
    if any(booster.num_feature() != len(COLUMNS) or booster.current_iteration() != model["iterations"][key]
           for key, booster in boosters.items()):
        raise ValueError("INVALID_CHALLENGER_BOOSTER")

    def run(feature, as_of):
        cutoff = utc(as_of)
        if utc(model["trained_until"]) >= cutoff or utc(model["effective_at"]) > cutoff:
            return None
        values = [feature.get(column) for column in COLUMNS]
        if any(type(value) not in (int, float) or not math.isfinite(value) for value in values):
            return None
        x = np.asarray([values], dtype=float)
        raw = {key: float(booster.predict(x, raw_score=key in ("up", "profitable"), num_threads=1)[0])
               for key, booster in boosters.items()}
        if not all(math.isfinite(value) for value in raw.values()):
            raise ValueError("INVALID_MODEL_NUMBER")

        def probability(key):
            a, b = model["calibration"][key]
            z = a * raw[key] + b
            if not math.isfinite(z):
                raise ValueError("INVALID_MODEL_NUMBER")
            return 1 / (1 + math.exp(-max(-35, min(35, z))))

        q10, q50, q90 = sorted(raw[key] for key in ("q10", "q50", "q90"))
        return {"p_up": probability("up"), "p_profitable": probability("profitable"),
                "mu_gross": raw["return"], "q10": q10, "q50": q50, "q90": q90,
                "model_cost": model["cost"], "evidence_level": "RESEARCH_CHALLENGER"}

    return run


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--model", type=Path, required=True)
    parser.add_argument("--sha256", required=True)
    parser.add_argument("--features", type=Path, required=True)
    parser.add_argument("--as-of", required=True)
    args = parser.parse_args()
    raw = args.model.read_bytes()
    if hashlib.sha256(raw).hexdigest() != args.sha256:
        parser.error("ARTIFACT_HASH_MISMATCH")
    model = json.loads(raw)
    try:
        score = load_challenger(model) if model.get("format") == "ORFEU_LIGHTGBM_NATIVE_V1" else (
            lambda feature, as_of: predict(model, feature, as_of)
        )
    except ModuleNotFoundError:
        parser.error("MODEL_RUNTIME_UNAVAILABLE")
    with args.features.open(encoding="utf-8") as stream:
        for line in stream:
            row = json.loads(line)
            if "kind" in row:
                if row["kind"] != "feature":
                    continue
                snapshot = row["data"]
                source = snapshot["payload"]
                if hashlib.sha256(source.encode("utf-8")).hexdigest() != snapshot["feature_hash"]:
                    parser.error("FEATURE_HASH_MISMATCH")
                row = {key: snapshot[key] for key in ("instrument_id", "as_of", "feature_hash")}
                row["features"] = json.loads(source)
            as_of = row.get("as_of", args.as_of)
            if utc(as_of) > utc(args.as_of):
                parser.error("FUTURE_FEATURE_SNAPSHOT")
            row["prediction"] = score(row["features"], as_of)
            row["model_sha256"] = args.sha256
            row["horizon"] = model["horizon"]
            print(json.dumps(row, allow_nan=False))


if __name__ == "__main__":
    main()
