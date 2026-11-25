import argparse
import hashlib
import json
import math
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
    with args.features.open(encoding="utf-8") as stream:
        for line in stream:
            row = json.loads(line)
            row["prediction"] = predict(model, row["features"], args.as_of)
            print(json.dumps(row, allow_nan=False))


if __name__ == "__main__":
    main()
