"""Point-in-time research primitives for the ORFEU Brazilian-equity laboratory.

The functions in this module are analytical only. They never create orders or
modify the paper portfolio. Market-data callers must provide dated observations
and source identifiers; missing evidence is returned as a blocked status.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import re
from datetime import date
from pathlib import Path
from statistics import mean, median, pstdev, stdev

from .data import utc

PAIR_DEFAULTS = {
    "cointegration_window": 252,
    "spread_window": 60,
    "entry_zscore": 2.0,
    "exit_zscore": 0.5,
    "stop_zscore": 3.5,
    "cointegration_pvalue": 0.05,
}

RESEARCH_PAIRS = (
    ("PETR3", "PETR4"), ("BBDC3", "BBDC4"), ("ITUB3", "ITUB4"),
    ("BBAS3", "ITUB4"), ("BBAS3", "BBDC4"), ("ITSA4", "ITUB4"),
    ("VALE3", "CMIN3"), ("GGBR4", "GOAU4"), ("SUZB3", "KLBN11"),
    ("PRIO3", "PETR4"),
)
OPERATIONAL_SYMBOLS = ("BBAS3", "VALE3", "PETR4", "MGLU3", "HAPV3", "BRKM5", "PCAR3", "SIMH3", "MOVI3", "LJQQ3")

FACTOR_SPECS = {
    "value": {"pe": -1, "pb": -1, "ev_ebitda": -1, "earnings_yield": 1, "fcf_yield": 1},
    "quality": {"roe": 1, "roic": 1, "operating_margin": 1, "earnings_stability": 1,
                "debt_to_equity": -1},
    "momentum": {"return_3m": 1, "return_6m": 1, "return_12m": 1,
                 "vol_adjusted_momentum": 1},
    "low_volatility": {"historical_volatility": -1, "beta": -1, "downside_deviation": -1,
                        "drawdown": -1},
    "liquidity": {"traded_value": 1, "trading_frequency": 1, "spread_bps": -1},
}
FACTOR_GROUPS = {
    "value": {"earnings_multiple": ("pe", "earnings_yield"), "book_value": ("pb",),
              "enterprise_value": ("ev_ebitda",), "free_cash_flow": ("fcf_yield",)},
    "quality": {"capital_returns": ("roe", "roic"), "operating_margin": ("operating_margin",),
                "earnings_stability": ("earnings_stability",), "leverage": ("debt_to_equity",)},
    "momentum": {"short_horizon": ("return_3m", "return_6m"), "long_horizon": ("return_12m",),
                 "vol_adjusted": ("vol_adjusted_momentum",)},
    "low_volatility": {"realized_downside": ("historical_volatility", "downside_deviation"),
                       "beta": ("beta",), "drawdown": ("drawdown",)},
    "liquidity": {"trading_activity": ("traded_value", "trading_frequency"),
                  "spread": ("spread_bps",)},
}
OPPORTUNITY_WEIGHTS = {
    "expected_net_return": .25,
    "calibrated_probability": .20,
    "momentum": .15,
    "quality": .15,
    "valuation": .10,
    "liquidity": .10,
    "risk": .05,
}


def _finite(values):
    return all(isinstance(v, (int, float)) and math.isfinite(v) for v in values)


def _aligned_prices(price_a, price_b, minimum=60):
    if len(price_a) != len(price_b) or len(price_a) < minimum:
        raise ValueError("INSUFFICIENT_OR_MISALIGNED_PAIR_HISTORY")
    if not _finite(price_a) or not _finite(price_b) or min(price_a) <= 0 or min(price_b) <= 0:
        raise ValueError("INVALID_PAIR_PRICES")


def benjamini_hochberg(pvalues):
    """Return monotone Benjamini-Hochberg adjusted p-values in input order."""
    if any(not math.isfinite(p) or p < 0 or p > 1 for p in pvalues):
        raise ValueError("INVALID_PVALUE")
    count = len(pvalues)
    adjusted = [1.0] * count
    ranked = sorted(enumerate(pvalues), key=lambda item: (item[1], item[0]))
    running = 1.0
    for rank in range(count, 0, -1):
        index, value = ranked[rank - 1]
        running = min(running, value * count / rank)
        adjusted[index] = min(1.0, running)
    return adjusted


def kalman_hedge_ratio(price_a, price_b, observation_variance=1e-3, process_variance=1e-5):
    """Estimate a time-varying intercept and hedge ratio with a 2-state Kalman filter."""
    _aligned_prices(price_a, price_b, minimum=2)
    if min(observation_variance, process_variance) <= 0:
        raise ValueError("INVALID_KALMAN_VARIANCE")
    state = [0.0, 1.0]
    covariance = [[1.0, 0.0], [0.0, 1.0]]
    result = []
    for a, b in zip(price_a, price_b):
        x, y = math.log(a), math.log(b)
        covariance[0][0] += process_variance
        covariance[1][1] += process_variance
        projected = state[0] + state[1] * y
        innovation = x - projected
        design = [1.0, y]
        ph = [covariance[0][0] + covariance[0][1] * y,
              covariance[1][0] + covariance[1][1] * y]
        variance = observation_variance + design[0] * ph[0] + design[1] * ph[1]
        gain = [v / variance for v in ph]
        state = [state[i] + gain[i] * innovation for i in range(2)]
        # Covariance update in its symmetric scalar-observation form.
        old = covariance
        covariance = [[old[i][j] - gain[i] * ph[j] for j in range(2)] for i in range(2)]
        covariance[0][1] = covariance[1][0] = (covariance[0][1] + covariance[1][0]) / 2
        filtered_spread = x - (state[0] + state[1] * y)
        result.append({"intercept": state[0], "hedge_ratio": state[1], "spread": filtered_spread,
                       "innovation": innovation, "innovation_variance": variance})
    return result


def _half_life(spread):
    lag, change = spread[:-1], [spread[i] - spread[i - 1] for i in range(1, len(spread))]
    center = mean(lag)
    denominator = sum((x - center) ** 2 for x in lag)
    if denominator <= 1e-15:
        return None
    slope = sum((x - center) * y for x, y in zip(lag, change)) / denominator
    return -math.log(2) / slope if slope < 0 else None


def ou_process_diagnostics(series):
    """Estimate a discrete OU/AR(1) mean-reversion process without trading claims."""
    values = list(series)
    if len(values) < 30 or not _finite(values):
        return {"status": "BLOCKED_INSUFFICIENT_OR_INVALID_SERIES", "half_life_sessions": None}
    lag, current = values[:-1], values[1:]
    center = mean(lag)
    variance = sum((value - center) ** 2 for value in lag)
    if variance <= 1e-15:
        return {"status": "BLOCKED_DEGENERATE_SERIES", "half_life_sessions": None}
    phi = sum((x - center) * (y - mean(current)) for x, y in zip(lag, current)) / variance
    intercept = mean(current) - phi * center
    residuals = [y - intercept - phi * x for x, y in zip(lag, current)]
    innovation_variance = sum(value * value for value in residuals) / max(1, len(residuals) - 2)
    if not 0 < phi < 1:
        return {"status": "NOT_STATIONARY_OU_DYNAMICS", "ar1_phi": phi,
                "half_life_sessions": None}
    kappa = -math.log(phi)
    equilibrium = intercept / (1 - phi)
    total_variance = sum((value - mean(current)) ** 2 for value in current)
    explained = 1 - sum(value * value for value in residuals) / total_variance if total_variance > 0 else None
    return {"status": "ANALYTICAL_ONLY", "ar1_phi": phi, "equilibrium": equilibrium,
            "mean_reversion_speed_per_session": kappa,
            "half_life_sessions": math.log(2) / kappa,
            "innovation_volatility": math.sqrt(innovation_variance),
            "continuous_diffusion_volatility": math.sqrt(2 * kappa * innovation_variance / (1 - phi**2)),
            "r_squared": explained, "trading_status": "DISABLED"}


def evaluate_pair(price_a, price_b, window=252, spread_window=60, pvalue_cutoff=0.05):
    """Evaluate a pair with OLS/Engle-Granger, ADF, Johansen and spread diagnostics.

    Statsmodels is intentionally optional. If it is absent, the result explicitly
    reports the dependency block instead of substituting an approximate test.
    """
    _aligned_prices(price_a, price_b, minimum=max(60, min(window, 252)))
    if window < 60 or spread_window < 20 or not 0 < pvalue_cutoff < 1:
        raise ValueError("INVALID_PAIR_PARAMETERS")
    try:
        import numpy as np
        from statsmodels.regression.linear_model import OLS
        from statsmodels.stats.diagnostic import breaks_cusumolsresid
        from statsmodels.tsa.stattools import adfuller, coint
        from statsmodels.tsa.vector_ar.vecm import coint_johansen
    except ImportError:
        return {"status": "BLOCKED_DEPENDENCY", "missing": "statsmodels", "candidate": False}
    a = np.log(np.asarray(price_a[-window:], dtype=float))
    b = np.log(np.asarray(price_b[-window:], dtype=float))
    if float(np.ptp(a)) <= 1e-10 or float(np.ptp(b)) <= 1e-10:
        return {"status": "BLOCKED_NUMERICAL_DEGENERACY", "candidate": False}
    design = np.column_stack((np.ones(len(b)), b))
    fit = OLS(a, design).fit()
    residual = a - fit.predict(design)
    try:
        eg_stat, eg_p, eg_critical = coint(a, b, trend="c", autolag="aic")
        level_a_adf = adfuller(a, regression="c", autolag="AIC")
        level_b_adf = adfuller(b, regression="c", autolag="AIC")
        adf_stat, adf_p, _, _, adf_critical, _ = adfuller(residual, regression="c", autolag="AIC")
        johansen = coint_johansen(np.column_stack((a, b)), det_order=0, k_ar_diff=1)
        _, break_p, _ = breaks_cusumolsresid(fit.resid, ddof=2)
    except (ValueError, np.linalg.LinAlgError):
        return {"status": "BLOCKED_NUMERICAL_DEGENERACY", "candidate": False}
    level_a_p = float(level_a_adf[1])
    level_b_p = float(level_b_adf[1])
    johansen_rank = int(sum(stat > critical for stat, critical in zip(johansen.lr1, johansen.cvt[:, 1])))
    spread = residual[-spread_window:]
    spread_sigma = float(np.std(spread, ddof=1))
    zscore = float((spread[-1] - np.mean(spread)) / spread_sigma) if spread_sigma > 0 else None
    dynamic = kalman_hedge_ratio(price_a[-window:], price_b[-window:])
    dynamic_spread = [row["spread"] for row in dynamic[20:]]
    dynamic_recent = dynamic_spread[-spread_window:]
    dynamic_sigma = pstdev(dynamic_recent)
    dynamic_zscore = ((dynamic_recent[-1] - mean(dynamic_recent)) / dynamic_sigma
                      if dynamic_sigma > 0 else None)
    half_life = _half_life(dynamic_spread)
    candidate = (level_a_p >= pvalue_cutoff and level_b_p >= pvalue_cutoff and eg_p < pvalue_cutoff
                 and adf_p < pvalue_cutoff and johansen_rank == 1 and break_p >= pvalue_cutoff
                 and half_life is not None and 1 <= half_life <= spread_window)
    return {
        "status": "CANDIDATE" if candidate else "REJECTED",
        "candidate": bool(candidate),
        "observations": len(a),
        "ols_intercept": float(fit.params[0]),
        "ols_hedge_ratio": float(fit.params[1]),
        "cointegration_statistic": float(eg_stat),
        "cointegration_pvalue": float(eg_p),
        "cointegration_critical_values": [float(x) for x in eg_critical],
        "level_a_adf_pvalue": level_a_p,
        "level_b_adf_pvalue": level_b_p,
        "johansen_rank_5pct": johansen_rank,
        "adf_statistic": float(adf_stat),
        "adf_pvalue": float(adf_p),
        "adf_critical_values": {key: float(value) for key, value in adf_critical.items()},
        "johansen_trace_statistics": [float(x) for x in johansen.lr1],
        "johansen_trace_critical_5pct": [float(x) for x in johansen.cvt[:, 1]],
        "spread_zscore": zscore,
        "dynamic_spread_zscore": dynamic_zscore,
        "dynamic_hedge_ratio": dynamic[-1]["hedge_ratio"],
        "half_life_sessions": half_life,
        "structural_break_pvalue": float(break_p),
        "structural_break_status": "NO_BREAK_DETECTED_BY_CUSUM" if break_p >= pvalue_cutoff else "BREAK_RISK",
    }


def correct_pair_tests(results):
    valid = [(i, row["cointegration_pvalue"]) for i, row in enumerate(results)
             if isinstance(row.get("cointegration_pvalue"), (int, float))]
    adjusted = benjamini_hochberg([p for _, p in valid])
    output = [dict(row) for row in results]
    for (index, _), qvalue in zip(valid, adjusted):
        output[index]["fdr_qvalue"] = qvalue
        output[index]["candidate"] = bool(output[index].get("candidate") and qvalue < 0.05)
        if output[index].get("status") == "CANDIDATE" and not output[index]["candidate"]:
            output[index]["status"] = "REJECTED_MULTIPLE_TEST_CORRECTION"
    return output


def pair_long_only_signal(metrics, symbol_a, symbol_b, operational_symbols, zscore=None):
    """Select only an in-universe cheap leg; never opens the classic short leg."""
    if metrics.get("status") != "CANDIDATE" or not metrics.get("candidate"):
        return {"status": "NO_SIGNAL", "reason": "PAIR_NOT_VALIDATED", "buy_symbol": None}
    z = (metrics.get("dynamic_spread_zscore", metrics.get("spread_zscore"))
         if zscore is None else zscore)
    entry = PAIR_DEFAULTS["entry_zscore"]
    if z is None or not math.isfinite(z) or abs(z) < entry:
        return {"status": "NO_SIGNAL", "reason": "ENTRY_THRESHOLD_NOT_MET", "buy_symbol": None}
    # spread = log(A) - beta*log(B) - intercept. Negative means A cheap; positive B cheap.
    cheap = symbol_a if z < 0 else symbol_b
    if cheap not in set(operational_symbols):
        return {"status": "ANALYTICAL_ONLY", "reason": "CHEAP_LEG_OUTSIDE_OPERATIONAL_UNIVERSE",
                "buy_symbol": None, "reference_symbol": cheap}
    return {"status": "LONG_ONLY_RESEARCH_SIGNAL", "reason": "RELATIVE_DIVERGENCE",
            "buy_symbol": cheap, "short_leg": None, "directional_exposure": True}


def _robust_z(values):
    finite = [float(x) for x in values if isinstance(x, (int, float)) and math.isfinite(x)]
    if not finite:
        return [None] * len(values)
    center = median(finite)
    mad = median([abs(x - center) for x in finite]) * 1.4826
    if mad <= 1e-12:
        sigma = pstdev(finite) if len(finite) > 1 else 0
        mad = sigma or 1.0
    return [max(-3.0, min(3.0, (float(x) - center) / mad))
            if isinstance(x, (int, float)) and math.isfinite(x) else None for x in values]


def factor_ranking(rows, as_of, weights=None):
    """Cross-sectional 0–100 factor scores, excluding records not available at cutoff."""
    weights = weights or {"value": .25, "quality": .25, "momentum": .25, "low_volatility": .15,
                          "liquidity": .10}
    if abs(sum(weights.values()) - 1.0) > 1e-8 or any(value < 0 for value in weights.values()):
        raise ValueError("INVALID_FACTOR_WEIGHTS")
    try:
        cutoff = utc(as_of)
    except (TypeError, ValueError):
        raise ValueError("INVALID_FACTOR_CUTOFF") from None
    latest = {}
    for source in rows:
        row = dict(source)
        symbol = row.get("symbol")
        try:
            available = utc(row["available_at"])
        except (KeyError, TypeError, ValueError):
            continue
        if not isinstance(symbol, str) or not symbol or available > cutoff:
            continue
        previous = latest.get(symbol)
        if previous is None or available >= previous[0]:
            latest[symbol] = (available, row)
    eligible = [row for _, row in latest.values()]
    sector_groups = {}
    for row in eligible:
        sector_groups.setdefault(row.get("sector", "UNKNOWN"), []).append(row)
    scores = {row["symbol"]: {"components": {}, "available_at": row["available_at"]} for row in eligible}
    for factor, groups in FACTOR_GROUPS.items():
        group_values = {row["symbol"]: [] for row in eligible}
        for fields in groups.values():
            values_by_symbol = {row["symbol"]: [] for row in eligible}
            for sector_rows in sector_groups.values():
                for field in fields:
                    direction = FACTOR_SPECS[factor][field]
                    transformed = [float(row[field]) * direction if isinstance(row.get(field), (int, float))
                                   and math.isfinite(row[field]) else None for row in sector_rows]
                    normalized = _robust_z(transformed)
                    for row, value in zip(sector_rows, normalized):
                        if value is not None:
                            values_by_symbol[row["symbol"]].append(value)
            for symbol, vals in values_by_symbol.items():
                if vals:
                    group_values[symbol].append(mean(vals))
        present = [mean(vals) for vals in group_values.values() if vals]
        center = mean(present) if present else 0.0
        spread = pstdev(present) if len(present) > 1 else 1.0
        for symbol, vals in group_values.items():
            if vals:
                normalized = max(-3.0, min(3.0, (mean(vals) - center) / (spread or 1.0)))
                scores[symbol]["components"][factor] = 50.0 + 50.0 * normalized / 3.0
    output = []
    for row in eligible:
        parts = scores[row["symbol"]]["components"]
        active = {key: weight for key, weight in weights.items() if key in parts}
        available_weight = sum(active.values())
        score = sum(parts[key] * weight for key, weight in active.items()) / available_weight if active else None
        output.append({"symbol": row["symbol"], "sector": row.get("sector"),
                       "available_at": scores[row["symbol"]]["available_at"], "score": score,
                       "components": parts, "component_coverage": available_weight,
                       "classification": "INSUFFICIENT_DATA" if score is None else
                       ("HIGH_RESEARCH" if score >= 70 else "MODERATE_RESEARCH" if score >= 55 else
                        "LOW_RESEARCH" if score >= 40 else "AVOID_NEW_POSITION"),
                       "trading_status": "ANALYTICAL_ONLY"})
    return sorted(output, key=lambda item: (item["score"] is None, -(item["score"] or 0), item["symbol"]))


def opportunity_score(components):
    """Combine seven already-normalized favorable components into a research score."""
    if not isinstance(components, dict):
        return {"status": "BLOCKED_INVALID_SCORE_COMPONENTS", "score": None,
                "trading_status": "DISABLED"}
    missing = sorted(set(OPPORTUNITY_WEIGHTS) - set(components))
    if missing:
        return {"status": "BLOCKED_INCOMPLETE_SCORE_COMPONENTS", "score": None,
                "missing_components": missing, "trading_status": "DISABLED"}
    invalid = [name for name in OPPORTUNITY_WEIGHTS
               if not isinstance(components[name], (int, float))
               or isinstance(components[name], bool)
               or not math.isfinite(components[name]) or not 0 <= components[name] <= 100]
    if invalid:
        return {"status": "BLOCKED_INVALID_SCORE_COMPONENTS", "score": None,
                "invalid_components": invalid, "trading_status": "DISABLED"}
    score = sum(components[name] * weight for name, weight in OPPORTUNITY_WEIGHTS.items())
    classification = ("VERY_HIGH_RESEARCH" if score >= 85 else
                      "HIGH_RESEARCH" if score >= 70 else
                      "MODERATE_RESEARCH" if score >= 55 else
                      "LOW_RESEARCH" if score >= 40 else "AVOID_NEW_POSITION")
    return {"status": "ANALYTICAL_ONLY", "score": score,
            "components": {name: components[name] for name in OPPORTUNITY_WEIGHTS},
            "weights": dict(OPPORTUNITY_WEIGHTS), "classification": classification,
            "interpretation": "EXPERIMENTAL_RESEARCH_SCORE_NOT_SUCCESS_PROBABILITY",
            "trading_status": "DISABLED"}


def correlation_heatmap(bars_by_symbol, symbols, as_of, minimum_returns=60):
    """Pearson heatmap on synchronized, hashed raw close returns; analytical only."""
    try:
        cutoff = utc(as_of)
    except (TypeError, ValueError):
        return {"status": "BLOCKED_INVALID_AS_OF", "trading_status": "DISABLED"}
    names = list(symbols)
    if (len(names) < 2 or len(names) != len(set(names))
            or not isinstance(minimum_returns, int) or minimum_returns < 2
            or not isinstance(bars_by_symbol, dict)):
        return {"status": "BLOCKED_INVALID_CORRELATION_INPUT", "trading_status": "DISABLED"}
    price_maps = {}
    missing = []
    for symbol in names:
        latest = {}
        for row in bars_by_symbol.get(symbol, []):
            try:
                session = row["session_date"]
                available = utc(row["available_at"])
                close = float(row["close_px"])
                raw_hash = str(row["raw_hash"])
                valid_date = date.fromisoformat(session).isoformat() == session
            except (KeyError, TypeError, ValueError):
                continue
            if (not valid_date or available > cutoff or not math.isfinite(close) or close <= 0
                    or row.get("provider") != "B3_COTAHIST"
                    or not re.fullmatch(r"[0-9a-fA-F]{64}", raw_hash)):
                continue
            prior = latest.get(session)
            if prior is None or available > prior[0]:
                latest[session] = (available, close)
        price_maps[symbol] = {session: value for session, (_, value) in latest.items()}
        if len(price_maps[symbol]) < minimum_returns + 1:
            missing.append(symbol)
    if missing:
        return {"status": "BLOCKED_INSUFFICIENT_OR_UNVERIFIED_HISTORY",
                "missing_symbols": missing, "trading_status": "DISABLED"}
    common = sorted(set.intersection(*(set(price_maps[name]) for name in names)))
    if len(common) < minimum_returns + 1:
        return {"status": "BLOCKED_INSUFFICIENT_SYNCHRONIZED_HISTORY",
                "common_sessions": len(common), "trading_status": "DISABLED"}
    returns = {symbol: [price_maps[symbol][common[index]] / price_maps[symbol][common[index - 1]] - 1
                       for index in range(1, len(common))] for symbol in names}
    deviations = {symbol: stdev(values) for symbol, values in returns.items()}
    if any(not math.isfinite(value) or value <= 0 for value in deviations.values()):
        return {"status": "BLOCKED_DEGENERATE_RETURN_SERIES", "trading_status": "DISABLED"}
    matrix = []
    for left in names:
        values = []
        for right in names:
            left_mean, right_mean = mean(returns[left]), mean(returns[right])
            covariance = sum((a - left_mean) * (b - right_mean)
                             for a, b in zip(returns[left], returns[right], strict=True)) / (len(common) - 2)
            values.append(max(-1.0, min(1.0,
                covariance / (deviations[left] * deviations[right]))))
        matrix.append({"symbol": left, "values": values})
    return {"status": "ANALYTICAL_ONLY_RAW_PRICE_RETURNS",
            "symbols": names, "matrix": matrix, "observations": len(common) - 1,
            "first_session": common[0], "last_session": common[-1],
            "price_basis": "RAW_CLOSE_PRICE; CORPORATE_ACTIONS_NOT_ADJUSTED",
            "trading_status": "DISABLED"}


def momentum_signal(closes, benchmark_closes=None, volumes=None, adv_window=20):
    if len(closes) < 253 or not _finite(closes) or min(closes) <= 0:
        return {"status": "BLOCKED_INSUFFICIENT_HISTORY", "signal": "NONE"}
    returns = {days: closes[-1] / closes[-days - 1] - 1 for days in (5, 21, 63, 126, 252)}
    long_ex_month = closes[-22] / closes[-253] - 1
    score = .2 * returns[21] + .3 * returns[63] + .3 * returns[126] + .2 * long_ex_month
    rel = None
    if benchmark_closes is not None and len(benchmark_closes) >= 253 and min(benchmark_closes) > 0:
        rel = (closes[-22] / closes[-253]) / (benchmark_closes[-22] / benchmark_closes[-253]) - 1
    volume_confirmed = (volumes is not None and len(volumes) >= adv_window + 1 and
                        mean(volumes[-adv_window:]) > 0 and volumes[-1] / mean(volumes[-adv_window:]) >= 1)
    return {"status": "AVAILABLE", "returns": returns, "long_momentum_ex_last_month": long_ex_month,
            "relative_strength_vs_ibov": rel, "score": score,
            "signal": "RESEARCH_LONG_CANDIDATE" if score > 0 and (rel is None or rel > 0)
            and volume_confirmed else "NO_ENTRY", "volume_confirmed": bool(volume_confirmed)}


def stat_arb_feature_snapshot(symbol, bars, benchmark_bars, as_of, identity_verified,
                              pair_context=None):
    """Build a dated stat-arb feature row from verified raw daily bars only."""
    cutoff = utc(as_of)
    blocked = {"status": "BLOCKED_INVALID_POINT_IN_TIME_INPUT"}

    def visible(rows):
        latest = {}
        for row in rows:
            try:
                session = row["session_date"]
                available = utc(row["available_at"])
                close_at = utc(row["close_at"])
            except (KeyError, TypeError, ValueError):
                continue
            if (session > cutoff.date().isoformat() or available > cutoff or close_at > cutoff
                    or available < close_at or row.get("price_basis") != "RAW_UNADJUSTED"
                    or not row.get("source_id")
                    or not re.fullmatch(r"[0-9a-fA-F]{64}", str(row.get("raw_sha256", "")))):
                continue
            previous = latest.get(session)
            if previous is None or utc(previous["available_at"]) < available:
                latest[session] = row
        return latest

    if not isinstance(symbol, str) or not symbol or identity_verified is not True:
        return {"status": "PENDING_IDENTITY", "symbol": symbol,
                "model_training_enabled": False, "trading_enabled": False}
    stock = visible(bars)
    benchmark = visible(benchmark_bars)
    sessions = sorted(set(stock) & set(benchmark))
    if len(sessions) < 64:
        return {"status": "BLOCKED_INSUFFICIENT_SYNCHRONIZED_HISTORY",
                "observations": len(sessions), "model_training_enabled": False,
                "trading_enabled": False}
    try:
        rows = [stock[session] for session in sessions]
        bench = [benchmark[session] for session in sessions]
        closes = [float(row["close"]) for row in rows]
        bench_closes = [float(row["close"]) for row in bench]
        highs = [float(row["high"]) for row in rows]
        lows = [float(row["low"]) for row in rows]
        volumes = [float(row["volume"]) for row in rows]
        values = [float(row["traded_value_brl"]) for row in rows]
    except (KeyError, TypeError, ValueError):
        return blocked
    if (not _finite(closes + bench_closes + highs + lows + volumes + values)
            or min(closes + bench_closes) <= 0 or min(volumes) < 0 or min(values) < 0
            or any(low > close or close > high for high, low, close in zip(highs, lows, closes))):
        return blocked
    tech = technical_indicator_pack(highs, lows, closes, volumes)
    if tech["status"] != "AVAILABLE":
        return {"status": "BLOCKED_TECHNICAL_FEATURES", "reason": tech["status"],
                "model_training_enabled": False, "trading_enabled": False}
    returns = [closes[index] / closes[index - 1] - 1 for index in range(1, len(closes))]
    bench_returns = [bench_closes[index] / bench_closes[index - 1] - 1
                     for index in range(1, len(bench_closes))]
    benchmark_variance = stdev(bench_returns[-60:]) ** 2
    beta = (sum((a - mean(returns[-60:])) * (b - mean(bench_returns[-60:]))
                for a, b in zip(returns[-60:], bench_returns[-60:])) / 59 / benchmark_variance
            if benchmark_variance > 0 else None)
    pair = None
    if isinstance(pair_context, dict):
        try:
            pair_available = utc(pair_context["available_at"])
            pair_sessions = pair_context["session_date"]
            if pair_available <= cutoff and pair_sessions == sessions[-1]:
                pair = pair_context
        except (KeyError, TypeError, ValueError):
            pass
    pvalue = None
    if pair is not None:
        pvalue = pair.get("adjusted_pvalue", pair.get("cointegration_pvalue"))
    pair_values = {
        "spread_zscore": pair.get("dynamic_spread_zscore", pair.get("spread_zscore")) if pair else None,
        "cointegration_pvalue": pair.get("fdr_qvalue", pvalue) if pair else None,
        "hedge_ratio": pair.get("dynamic_hedge_ratio", pair.get("ols_hedge_ratio")) if pair else None,
        "half_life": pair.get("half_life_sessions") if pair else None,
    }
    if any(value is not None and (not isinstance(value, (int, float))
                                  or not math.isfinite(value))
           for value in pair_values.values()):
        return blocked
    latest = rows[-1]
    features = {
        "return_1d": returns[-1],
        "return_5d": closes[-1] / closes[-6] - 1,
        "return_21d": closes[-1] / closes[-22] - 1,
        "return_63d": closes[-1] / closes[-64] - 1,
        "volatility_21d": pstdev(returns[-21:]) * math.sqrt(252),
        "volume_ratio": tech["volume_ratio_20"],
        "relative_strength": (closes[-1] / closes[-64]) / (bench_closes[-1] / bench_closes[-64]) - 1,
        "spread_zscore": pair_values["spread_zscore"],
        "cointegration_pvalue": pair_values["cointegration_pvalue"],
        "hedge_ratio": pair_values["hedge_ratio"],
        "half_life": pair_values["half_life"],
        "beta_ibovespa": beta,
        "rsi": tech["rsi_14"],
        "atr": tech["atr_14"],
        "macd": tech["macd_histogram"],
        "liquidity_score": math.log1p(max(0.0, latest["traded_value_brl"])),
    }
    return {"status": "ANALYTICAL_ONLY", "symbol": symbol, "session_date": sessions[-1],
            "available_at": latest["available_at"], "observations": len(sessions),
            "features": features,
            "lineage": {"stock_source_id": latest["source_id"],
                        "stock_raw_sha256": latest["raw_sha256"],
                        "benchmark_source_id": bench[-1]["source_id"],
                        "benchmark_raw_sha256": bench[-1]["raw_sha256"]},
            "model_training_enabled": False, "trading_enabled": False}


def technical_indicator_pack(highs, lows, closes, volumes):
    """Compute daily OHLCV trend, volatility, momentum, and volume indicators."""
    size = len(closes)
    if (size < 35 or len(highs) != size or len(lows) != size or len(volumes) != size
            or not _finite(highs) or not _finite(lows) or not _finite(closes) or not _finite(volumes)
            or min(closes) <= 0 or min(highs) <= 0 or min(lows) <= 0 or min(volumes) < 0
            or any(low > close or close > high for high, low, close in zip(highs, lows, closes))):
        return {"status": "BLOCKED_INVALID_OR_INSUFFICIENT_OHLCV"}
    true_ranges, plus_dm, minus_dm = [], [], []
    for index in range(1, size):
        up = highs[index] - highs[index - 1]
        down = lows[index - 1] - lows[index]
        plus_dm.append(up if up > down and up > 0 else 0.0)
        minus_dm.append(down if down > up and down > 0 else 0.0)
        true_ranges.append(max(highs[index] - lows[index], abs(highs[index] - closes[index - 1]),
                               abs(lows[index] - closes[index - 1])))
    period = 14
    atr = mean(true_ranges[-period:])
    tr_sum = sum(true_ranges[-period:])
    plus_di = 100 * sum(plus_dm[-period:]) / tr_sum if tr_sum > 0 else 0.0
    minus_di = 100 * sum(minus_dm[-period:]) / tr_sum if tr_sum > 0 else 0.0
    dx = []
    for index in range(-period, 0):
        local_tr = sum(true_ranges[index - period + 1:index + 1])
        pdi = 100 * sum(plus_dm[index - period + 1:index + 1]) / local_tr if local_tr else 0
        mdi = 100 * sum(minus_dm[index - period + 1:index + 1]) / local_tr if local_tr else 0
        dx.append(100 * abs(pdi - mdi) / (pdi + mdi) if pdi + mdi > 0 else 0.0)
    adx = mean(dx)

    def ema(values, span):
        result = values[0]
        alpha = 2 / (span + 1)
        for value in values[1:]:
            result += alpha * (value - result)
        return result

    recent = closes[-20:]
    center = mean(recent)
    deviation = pstdev(recent)
    macd_history = []
    fast = slow = closes[0]
    for value in closes:
        fast += 2 / 13 * (value - fast)
        slow += 2 / 27 * (value - slow)
        macd_history.append(fast - slow)
    macd = macd_history[-1]
    macd_signal = ema(macd_history[-9:], 9)
    changes = [closes[index] - closes[index - 1] for index in range(size - 14, size)]
    gains = sum(max(change, 0) for change in changes) / period
    losses = sum(max(-change, 0) for change in changes) / period
    rsi = 100 * gains / (gains + losses) if gains + losses else 50.0
    volume_average = mean(volumes[-21:-1])
    return {"status": "AVAILABLE", "atr_14": atr, "atr_pct": atr / closes[-1],
            "plus_di_14": plus_di, "minus_di_14": minus_di, "adx_14": adx,
            "bollinger_mid_20": center, "bollinger_upper_20_2sd": center + 2 * deviation,
            "bollinger_lower_20_2sd": center - 2 * deviation,
            "bollinger_zscore": (closes[-1] - center) / deviation if deviation else 0.0,
            "ema20_distance": closes[-1] / ema(closes, 20) - 1,
            "ema50_distance": closes[-1] / ema(closes, 50) - 1,
            "macd": macd, "macd_signal": macd_signal, "macd_histogram": macd - macd_signal,
            "rsi_14": rsi,
            "volume_ratio_20": volumes[-1] / volume_average if volume_average else None,
            "abnormal_volume": bool(volume_average > 0 and volumes[-1] / volume_average >= 2),
            "analytical_status": "INDICATORS_ONLY_NO_SIGNAL_OR_TRADE"}


def swing_diagnostics(highs, lows, closes, volumes, trend_ok, corporate_event_clear,
                      allowed_risk_brl=None, capital_brl=None, cash_brl=None, lot_size=100,
                      adv_shares=None, max_position_fraction=.10, max_volume_fraction=.05,
                      atr_multiple=2.0, max_holding_sessions=15, held_sessions=0, previous_stop=None):
    """Long-only breakout/pullback research, ATR risk, time stop and capped sizing.

    The signal is evaluated at the last close and, if eligible, is only a candidate
    for a later session. It never places or simulates an order.
    """
    size = len(closes)
    if (size < 51 or len(highs) != size or len(lows) != size or len(volumes) != size
            or not _finite(highs) or not _finite(lows) or not _finite(closes) or not _finite(volumes)
            or min(closes) <= 0 or min(highs) <= 0 or min(lows) <= 0 or min(volumes) < 0
            or any(low > close or close > high for high, low, close in zip(highs, lows, closes))
            or not isinstance(lot_size, int) or lot_size < 1 or max_holding_sessions < 1
            or not isinstance(max_holding_sessions, int) or not isinstance(held_sessions, int) or held_sessions < 0
            or (previous_stop is not None and (not _finite([previous_stop]) or previous_stop <= 0))
            or not _finite([atr_multiple, max_position_fraction, max_volume_fraction])
            or atr_multiple <= 0 or not 0 < max_position_fraction <= 1
            or not 0 < max_volume_fraction <= 1):
        return {"status": "BLOCKED_INVALID_OR_INSUFFICIENT_OHLCV", "signal": "NONE",
                "trading_status": "DISABLED"}
    true_ranges = []
    for index in range(1, size):
        true_ranges.append(max(highs[index] - lows[index], abs(highs[index] - closes[index - 1]),
                               abs(lows[index] - closes[index - 1])))
    atr = mean(true_ranges[-14:])
    ema20 = ema50 = closes[0]
    for close in closes:
        ema20 += 2 / 21 * (close - ema20)
        ema50 += 2 / 51 * (close - ema50)
    prior_high = max(highs[-21:-1])
    volume_average = mean(volumes[-21:-1])
    volume_ratio = volumes[-1] / volume_average if volume_average > 0 else None
    breakout = closes[-1] > prior_high and volume_ratio is not None and volume_ratio >= 1.3
    pullback = (ema20 <= closes[-1] <= ema20 + .5 * atr and closes[-1] > closes[-2]
                and volume_ratio is not None and volume_ratio >= 1.0)
    gates = bool(trend_ok) and bool(corporate_event_clear)
    entry_pattern = "BREAKOUT" if breakout else "PULLBACK" if pullback else None
    stop = max(float(previous_stop or 0), closes[-1] - atr_multiple * atr)
    exit_reason = ("EXIT_STOP" if previous_stop is not None and closes[-1] <= previous_stop else
                   "EXIT_TIME" if held_sessions >= max_holding_sessions else None)
    signal = (exit_reason if exit_reason else
              "RESEARCH_LONG_CANDIDATE" if gates and entry_pattern else "NO_ENTRY")
    quantity = 0
    size_status = "NOT_REQUESTED"
    inputs = (allowed_risk_brl, capital_brl, cash_brl, adv_shares)
    if all(value is not None for value in inputs) and signal == "RESEARCH_LONG_CANDIDATE":
        if (not _finite(inputs) or min(allowed_risk_brl, capital_brl, cash_brl, adv_shares) < 0
                or min(capital_brl, cash_brl) <= 0):
            return {"status": "BLOCKED_INVALID_SIZING_INPUT", "signal": "NONE",
                    "trading_status": "DISABLED"}
        risk_per_share = max(atr_multiple * atr, 1e-12)
        risk_cap = math.floor(allowed_risk_brl / risk_per_share)
        cash_cap = math.floor(cash_brl / closes[-1])
        position_cap = math.floor(capital_brl * max_position_fraction / closes[-1])
        liquidity_cap = math.floor(adv_shares * max_volume_fraction)
        quantity = max(0, min(risk_cap, cash_cap, position_cap, liquidity_cap) // lot_size * lot_size)
        size_status = "AVAILABLE_RESEARCH_ONLY" if quantity else "BLOCKED_BY_RISK_CASH_EXPOSURE_OR_LIQUIDITY"
    elif any(value is not None for value in inputs):
        size_status = "BLOCKED_INCOMPLETE_SIZING_INPUT"
    return {"status": "ANALYTICAL_ONLY", "signal": signal, "entry_pattern": entry_pattern,
            "entry_timing": "NEXT_SESSION_ONLY", "trend_gate": bool(trend_ok),
            "corporate_event_gate": bool(corporate_event_clear), "volume_ratio": volume_ratio,
            "atr": atr, "atr_multiple": atr_multiple, "stop_price": stop,
            "exit_reason": exit_reason, "max_holding_sessions": max_holding_sessions,
            "quantity_shares": quantity, "size_status": size_status,
            "trading_status": "DISABLED"}


def mean_reversion_diagnostics(closes, trend_ok, liquid, corporate_event_clear, volatility_ok):
    if len(closes) < 61 or not _finite(closes) or min(closes) <= 0:
        return {"status": "BLOCKED_INSUFFICIENT_HISTORY", "signal": "NONE"}
    sample = closes[-20:]
    sigma = pstdev(sample)
    z = (sample[-1] - mean(sample)) / sigma if sigma else 0.0
    rsi_window = closes[-15:]
    ups = sum(max(0, rsi_window[i] - rsi_window[i - 1]) for i in range(1, len(rsi_window)))
    downs = sum(max(0, rsi_window[i - 1] - rsi_window[i]) for i in range(1, len(rsi_window)))
    rsi = 100 * ups / (ups + downs) if ups + downs else 50.0
    log_prices = [math.log(x) for x in closes[-60:]]
    residuals = [log_prices[i] - mean(log_prices[i - 20:i]) for i in range(20, len(log_prices))]
    ou = ou_process_diagnostics(residuals)
    hl = ou.get("half_life_sessions") or _half_life(residuals)
    enabled = all((trend_ok, liquid, corporate_event_clear, volatility_ok))
    return {"status": "AVAILABLE", "zscore": z, "rsi": rsi, "half_life_sessions": hl,
            "ou_process": ou,
            "filters": {"trend": bool(trend_ok), "liquidity": bool(liquid),
                        "corporate_events_clear": bool(corporate_event_clear), "volatility": bool(volatility_ok)},
            "signal": "RESEARCH_LONG_CANDIDATE" if enabled and z < -2 and rsi < 35 else "NO_ENTRY"}


def cash_carry_theoretical(spot, dividends_pv, financing_rate, years, estimated_costs=0.0,
                           observed_future=None):
    values = (spot, dividends_pv, financing_rate, years, estimated_costs)
    if (not _finite(values) or spot <= 0 or dividends_pv < 0 or years < 0 or estimated_costs < 0
            or (observed_future is not None and (not _finite([observed_future]) or observed_future <= 0))):
        raise ValueError("INVALID_CASH_CARRY_INPUT")
    future = (spot - dividends_pv) * math.exp(financing_rate * years)
    net_basis = (observed_future - future - estimated_costs) if observed_future is not None else None
    return {"theoretical_future": future, "observed_future": observed_future,
            "net_basis_after_costs": net_basis,
            "analytical_status": "OBSERVATION_ONLY_REQUIRES_VERIFIED_FORWARD_AND_CONTRACT_DATA",
            "estimated_costs": estimated_costs, "trade_status": "DISABLED"}


def black_scholes_call(spot, strike, years, rate, volatility, dividend_yield=0.0):
    """European BSM call and Greeks for research; not an options execution signal."""
    if not _finite((spot, strike, years, rate, volatility, dividend_yield)) or min(spot, strike) <= 0 or years <= 0 or volatility <= 0:
        raise ValueError("INVALID_OPTION_INPUT")
    from math import erf, exp, log, pi, sqrt
    root = sqrt(years)
    d1 = (log(spot / strike) + (rate - dividend_yield + volatility**2 / 2) * years) / (volatility * root)
    d2 = d1 - volatility * root
    cdf = lambda x: (1 + erf(x / sqrt(2))) / 2
    pdf = lambda x: exp(-x * x / 2) / sqrt(2 * pi)
    price = spot * exp(-dividend_yield * years) * cdf(d1) - strike * exp(-rate * years) * cdf(d2)
    gamma = exp(-dividend_yield * years) * pdf(d1) / (spot * volatility * root)
    theta = (-spot * exp(-dividend_yield * years) * pdf(d1) * volatility / (2 * root)
             - rate * strike * exp(-rate * years) * cdf(d2)
             + dividend_yield * spot * exp(-dividend_yield * years) * cdf(d1))
    vega = spot * exp(-dividend_yield * years) * pdf(d1) * root
    rho = strike * years * exp(-rate * years) * cdf(d2)
    return {"call_value": price, "delta": exp(-dividend_yield * years) * cdf(d1),
            "gamma": gamma, "theta_per_year": theta, "vega_per_1_volatility": vega,
            "rho_per_1_rate": rho,
            "analytical_status": "EUROPEAN_BSM_REFERENCE_ONLY_AMERICAN_EXERCISE_AND_COSTS_UNMODELED"}


def implied_volatility_call(spot, strike, years, rate, call_price, dividend_yield=0.0,
                            tolerance=1e-8, max_iterations=100):
    """Invert European BSM call price by bisection; returns no order recommendation."""
    values = (spot, strike, years, rate, call_price, dividend_yield, tolerance)
    if (not _finite(values) or min(spot, strike) <= 0 or years <= 0 or call_price < 0
            or tolerance <= 0 or not isinstance(max_iterations, int) or max_iterations < 1):
        raise ValueError("INVALID_IMPLIED_VOLATILITY_INPUT")
    from math import exp
    lower_bound = max(0.0, spot * exp(-dividend_yield * years) - strike * exp(-rate * years))
    upper_bound = spot * exp(-dividend_yield * years)
    if call_price < lower_bound - tolerance or call_price >= upper_bound:
        return {"status": "BLOCKED_OUTSIDE_NO_ARBITRAGE_BOUNDS", "implied_volatility": None,
                "lower_bound": lower_bound, "upper_bound": upper_bound, "trading_status": "DISABLED"}
    low, high = 1e-6, 5.0
    iterations = 0
    for iterations in range(1, max_iterations + 1):
        mid = (low + high) / 2
        value = black_scholes_call(spot, strike, years, rate, mid, dividend_yield)["call_value"]
        if abs(value - call_price) <= tolerance:
            break
        if value < call_price:
            low = mid
        else:
            high = mid
    iv = (low + high) / 2
    if abs(black_scholes_call(spot, strike, years, rate, iv, dividend_yield)["call_value"] - call_price) > tolerance * 2:
        return {"status": "BLOCKED_NUMERICAL_FIT", "implied_volatility": None,
                "iterations": iterations, "trading_status": "DISABLED"}
    return {"status": "ANALYTICAL_ONLY", "implied_volatility": iv,
            "iterations": iterations, "model": "EUROPEAN_BSM_BISECTION",
            "trading_status": "DISABLED"}


def put_call_parity_diagnostic(spot, strike, years, rate, dividend_yield, call_price, put_price):
    """Measure European put-call parity residual; never emits an arbitrage order."""
    values = (spot, strike, years, rate, dividend_yield, call_price, put_price)
    if (not _finite(values) or min(spot, strike) <= 0 or years <= 0
            or min(call_price, put_price) < 0):
        raise ValueError("INVALID_PUT_CALL_PARITY_INPUT")
    from math import exp
    lhs = call_price - put_price
    rhs = spot * exp(-dividend_yield * years) - strike * exp(-rate * years)
    residual = lhs - rhs
    return {"observed_call_minus_put": lhs, "theoretical_parity": rhs,
            "parity_residual": residual, "absolute_residual": abs(residual),
            "analytical_status": "EUROPEAN_PARITY_REFERENCE_COSTS_AND_AMERICAN_EXERCISE_UNMODELED",
            "trading_status": "DISABLED"}


def binomial_option(spot, strike, years, rate, volatility, kind="call", dividend_yield=0.0,
                    steps=200):
    """CRR binomial reference for European/American calls or puts."""
    values = (spot, strike, years, rate, volatility, dividend_yield)
    if (not _finite(values) or min(spot, strike) <= 0 or years <= 0 or volatility <= 0
            or kind not in {"call", "put"} or not isinstance(steps, int) or not 1 <= steps <= 1000):
        raise ValueError("INVALID_BINOMIAL_OPTION_INPUT")
    dt = years / steps
    up = math.exp(volatility * math.sqrt(dt))
    down = 1 / up
    denominator = up - down
    probability = (math.exp((rate - dividend_yield) * dt) - down) / denominator
    if not 0 <= probability <= 1:
        raise ValueError("INVALID_RISK_NEUTRAL_PROBABILITY")
    discount = math.exp(-rate * dt)
    call_values = []
    put_values = []
    for up_moves in range(steps + 1):
        underlying = spot * up ** up_moves * down ** (steps - up_moves)
        call_values.append(max(0.0, underlying - strike))
        put_values.append(max(0.0, strike - underlying))
    for level in range(steps - 1, -1, -1):
        for up_moves in range(level + 1):
            underlying = spot * up ** up_moves * down ** (level - up_moves)
            call_continuation = discount * (probability * call_values[up_moves + 1]
                                               + (1 - probability) * call_values[up_moves])
            put_continuation = discount * (probability * put_values[up_moves + 1]
                                              + (1 - probability) * put_values[up_moves])
            call_values[up_moves] = max(call_continuation, underlying - strike)
            put_values[up_moves] = max(put_continuation, strike - underlying)
    result = call_values[0] if kind == "call" else put_values[0]
    return {"value": result, "kind": kind.upper(), "exercise_style": "AMERICAN",
            "steps": steps, "risk_neutral_probability": probability,
            "analytical_status": "CRR_BINOMIAL_REFERENCE_ONLY_COSTS_AND_MARKET_QUOTES_UNMODELED",
            "trading_status": "DISABLED"}


def orderbook_diagnostics(bid, ask, bid_depth, ask_depth):
    if not _finite((bid, ask, bid_depth, ask_depth)) or min(bid, bid_depth, ask_depth) < 0 or ask <= bid:
        raise ValueError("INVALID_ORDERBOOK")
    midpoint = (bid + ask) / 2
    return {"spread_bps": 10000 * (ask - bid) / midpoint,
            "order_book_imbalance": (bid_depth - ask_depth) / (bid_depth + ask_depth)
            if bid_depth + ask_depth else None,
            "execution_probability": None, "adverse_selection_estimate": None,
            "analytical_status": "NO_EXECUTION_PROBABILITY_WITHOUT_EVENT_LEVEL_HISTORY",
            "trading_status": "DISABLED"}


def regime_diagnostics(ibov_returns, volatility, selic_change=None, cdi=None, dollar_returns=None):
    if len(ibov_returns) < 63 or not _finite(ibov_returns) or not _finite(volatility):
        return {"status": "BLOCKED_INSUFFICIENT_DATA", "regime": None, "rotation_enabled": False}
    trend = sum(ibov_returns[-63:])
    vol = mean(volatility[-21:]) if len(volatility) >= 21 else None
    regime = "BULL" if trend > .05 else "BEAR" if trend < -.05 else "SIDEWAYS"
    return {"status": "RESEARCH_HEURISTIC", "regime": regime, "ibov_63d_return": trend,
            "volatility_21d": vol, "selic_change": selic_change, "cdi_available": cdi is not None,
            "dollar_available": dollar_returns is not None, "rotation_enabled": False,
            "production_model": "NOT_VALIDATED"}


def gmm_regime_diagnostics(rows, max_components=4):
    """BIC-selected Gaussian-mixture regimes over dated return/volatility observations."""
    if len(rows) < 40:
        return {"status": "BLOCKED_INSUFFICIENT_HISTORY", "regime": None, "rotation_enabled": False}
    fields = ("return_21d", "volatility_21d")
    dates = [row.get("session_date") for row in rows]
    if (not isinstance(max_components, int) or not 2 <= max_components <= 8
            or any(not date for date in dates) or dates != sorted(dates) or len(set(dates)) != len(dates)
            or any(not _finite([row.get(field) for field in fields]) for row in rows)):
        return {"status": "BLOCKED_INVALID_REGIME_PANEL", "regime": None, "rotation_enabled": False}
    try:
        import numpy as np
        from sklearn.mixture import GaussianMixture
        from sklearn.preprocessing import StandardScaler
    except ImportError as error:
        return {"status": "BLOCKED_DEPENDENCY", "missing": error.name,
                "regime": None, "rotation_enabled": False}
    matrix = np.asarray([[row[field] for field in fields] for row in rows], dtype=float)
    scaler = StandardScaler().fit(matrix)
    scaled = scaler.transform(matrix)
    upper = min(max_components, max(1, len(rows) // 20), len(np.unique(scaled, axis=0)))
    candidates = []
    for count in range(1, upper + 1):
        model = GaussianMixture(n_components=count, covariance_type="full", reg_covar=1e-5,
                                n_init=3, random_state=2026).fit(scaled)
        candidates.append((model.bic(scaled), count, model))
    bic, _, model = min(candidates, key=lambda item: (item[0], item[1]))
    labels = model.predict(scaled)
    probabilities = model.predict_proba(scaled)[-1]
    component_return = {index: float(matrix[labels == index, 0].mean()) for index in range(model.n_components)}
    ordered = sorted(component_return, key=lambda index: (component_return[index], index))
    current = int(labels[-1])
    if model.n_components == 1:
        regime = "UNRESOLVED_SINGLE_STATE"
    elif current == ordered[-1] and component_return[current] > 0:
        regime = "RISK_ON"
    elif current == ordered[0] and component_return[current] < 0:
        regime = "RISK_OFF"
    else:
        regime = "TRANSITION"
    return {"status": "ANALYTICAL_ONLY", "as_of": dates[-1], "method": "GAUSSIAN_MIXTURE_BIC",
            "selected_components": int(model.n_components), "bic": float(bic), "regime": regime,
            "state_id": current, "posterior_probability": float(probabilities[current]),
            "observations": len(rows), "rotation_enabled": False,
            "production_model": "NOT_VALIDATED"}


def cross_sectional_clusters(rows, eps=1.0, min_samples=2, variance_target=.9):
    """PCA + DBSCAN grouping for one point-in-time cross-section; no price forecast."""
    if len(rows) < 4:
        return {"status": "BLOCKED_INSUFFICIENT_CROSS_SECTION", "items": []}
    symbols = [row.get("symbol") for row in rows]
    fields = sorted(set.intersection(*(set(row) for row in rows)) - {"symbol", "available_at", "sector"})
    if (len(set(symbols)) != len(symbols) or any(not symbol for symbol in symbols) or len(fields) < 2
            or not isinstance(min_samples, int) or not 2 <= min_samples <= len(rows)
            or not math.isfinite(eps) or eps <= 0 or not 0 < variance_target <= 1
            or any(not row.get("available_at") for row in rows)):
        return {"status": "BLOCKED_INVALID_CROSS_SECTION", "items": []}
    cutoffs = {row["available_at"] for row in rows}
    if len(cutoffs) != 1:
        return {"status": "BLOCKED_MISALIGNED_CROSS_SECTION", "items": []}
    values = [[row[field] for field in fields] for row in rows]
    if any(not _finite(vector) for vector in values):
        return {"status": "BLOCKED_MISSING_OR_NONFINITE_FEATURES", "items": []}
    try:
        import numpy as np
        from sklearn.cluster import DBSCAN
        from sklearn.decomposition import PCA
        from sklearn.preprocessing import StandardScaler
    except ImportError as error:
        return {"status": "BLOCKED_DEPENDENCY", "missing": error.name, "items": []}
    scaled = StandardScaler().fit_transform(np.asarray(values, dtype=float))
    if float(np.max(np.std(scaled, axis=0))) <= 1e-12:
        return {"status": "BLOCKED_DEGENERATE_CROSS_SECTION", "items": []}
    pca = PCA(n_components=variance_target, svd_solver="full")
    reduced = pca.fit_transform(scaled)
    labels = DBSCAN(eps=eps, min_samples=min_samples).fit_predict(reduced)
    items = [{"symbol": symbol, "cluster": int(label), "outlier": int(label) == -1}
             for symbol, label in zip(symbols, labels)]
    return {"status": "ANALYTICAL_ONLY", "as_of": next(iter(cutoffs)), "method": "PCA_DBSCAN",
            "feature_names": fields, "components": int(pca.n_components_),
            "explained_variance_ratio": [float(value) for value in pca.explained_variance_ratio_],
            "clusters_found": len({int(label) for label in labels if label != -1}),
            "noise_points": sum(int(label) == -1 for label in labels), "items": items,
            "trading_status": "DISABLED"}


def hdbscan_clusters(matrix, min_cluster_size=3):
    """Optional HDBSCAN interface; reports a dependency block when unavailable."""
    if not isinstance(min_cluster_size, int) or min_cluster_size < 2:
        raise ValueError("INVALID_HDBSCAN_PARAMETERS")
    try:
        import hdbscan
        import numpy as np
        from sklearn.preprocessing import StandardScaler
    except ImportError as error:
        return {"status": "BLOCKED_DEPENDENCY", "missing": error.name, "labels": []}
    values = np.asarray(matrix, dtype=float)
    if values.ndim != 2 or values.shape[0] < min_cluster_size or values.shape[1] < 1 or not np.isfinite(values).all():
        return {"status": "BLOCKED_INVALID_MATRIX", "labels": []}
    scaled = StandardScaler().fit_transform(values)
    labels = hdbscan.HDBSCAN(min_cluster_size=min_cluster_size).fit_predict(scaled)
    return {"status": "ANALYTICAL_ONLY", "labels": [int(label) for label in labels],
            "clusters_found": len({int(label) for label in labels if label >= 0}),
            "noise_points": int(sum(label == -1 for label in labels)), "trading_status": "DISABLED"}


def garch_volatility(returns):
    """Fit zero-mean GARCH(1,1) by Gaussian maximum likelihood, with a stationary fit."""
    values = list(returns)
    if len(values) < 100 or not _finite(values) or min(values) <= -1:
        return {"status": "BLOCKED_INSUFFICIENT_OR_INVALID_HISTORY", "forecast_volatility": None}
    try:
        import numpy as np
        from scipy.optimize import minimize
    except ImportError as error:
        return {"status": "BLOCKED_DEPENDENCY", "missing": error.name, "forecast_volatility": None}
    scaled = np.asarray(values, dtype=float) * 100.0
    unconditional = float(np.mean(scaled * scaled))
    if not math.isfinite(unconditional) or unconditional <= 1e-12:
        return {"status": "BLOCKED_NUMERICAL_FIT", "forecast_volatility": None}
    lower_omega = max(unconditional * 1e-9, 1e-12)

    def likelihood(parameters):
        omega, alpha, beta = parameters
        variance = unconditional
        total = 0.0
        for value in scaled:
            if variance <= 0 or not math.isfinite(variance):
                return 1e100
            total += math.log(variance) + value * value / variance
            variance = omega + alpha * value * value + beta * variance
        return total if math.isfinite(total) else 1e100

    fit = minimize(likelihood,
                   [max(unconditional * .05, lower_omega), .08, .85],
                   method="SLSQP",
                   bounds=[(lower_omega, unconditional * 10), (0.0, .999), (0.0, .999)],
                   constraints=[{"type": "ineq", "fun": lambda parameters:
                                 .999 - parameters[1] - parameters[2]}],
                   options={"maxiter": 2000, "ftol": 1e-10})
    if (not fit.success or not _finite(fit.x) or fit.x[0] <= 0 or fit.x[1] < 0 or fit.x[2] < 0
            or fit.x[1] + fit.x[2] >= 1):
        return {"status": "BLOCKED_NUMERICAL_FIT", "forecast_volatility": None}
    omega, alpha, beta = map(float, fit.x)
    variance = unconditional
    for value in scaled:
        variance = omega + alpha * value * value + beta * variance
    forecast = math.sqrt(variance) / 100.0
    if not math.isfinite(forecast) or forecast < 0:
        return {"status": "BLOCKED_NUMERICAL_FIT", "forecast_volatility": None}
    return {"status": "ANALYTICAL_ONLY", "forecast_volatility": forecast,
            "model": "GARCH_1_1_GAUSSIAN_MLE", "omega": omega, "alpha": alpha,
            "beta": beta, "persistence": alpha + beta, "trading_status": "DISABLED"}


def risk_diagnostics(equity, daily_returns=None, alpha=.95):
    if not equity or not _finite(equity) or min(equity) <= 0 or not 0 < alpha < 1:
        return {"status": "BLOCKED_INSUFFICIENT_DATA"}
    peak, drawdown = equity[0], 0.0
    for value in equity:
        peak = max(peak, value)
        drawdown = min(drawdown, value / peak - 1)
    returns = daily_returns or [equity[i] / equity[i - 1] - 1 for i in range(1, len(equity))]
    if not returns or not _finite(returns):
        return {"status": "BLOCKED_INSUFFICIENT_DATA"}
    losses = sorted(-value for value in returns)
    index = min(len(losses) - 1, max(0, math.ceil(alpha * len(losses)) - 1))
    var = losses[index]
    cvar = mean([loss for loss in losses if loss >= var])
    return {"status": "AVAILABLE", "max_drawdown": drawdown, "historical_var": var,
            "historical_cvar": cvar, "observations": len(returns), "kill_switch": "NOT_TRIGGERED_BY_ANALYTIC_ONLY"}


def scan_export(path):
    """Scan the current point-in-time ORFEU research export; never fills missing prices."""
    from .data import read_export, utc

    source, source_hash = read_export(Path(path))
    manifest = source["manifest"][0]
    cutoff = utc(manifest["as_of"])
    latest_symbol = {}
    for row in source["symbol"]:
        available = utc(row["available_at"])
        if available <= cutoff:
            old = latest_symbol.get(int(row["instrument_id"]))
            if old is None or (row["valid_from"], available) > (old["valid_from"], old["_available"]):
                latest_symbol[int(row["instrument_id"])] = row | {"_available": available}
    instruments = {int(row["id"]): row for row in source["instrument"]}
    identity_by_symbol = {}
    for row in source.get("identity", []):
        try:
            instrument_id = int(row["instrument_id"])
            valid_from = row["valid_from"]
            valid_to = row.get("valid_to")
            available = utc(row["available_at"])
            evidence_hash = row["raw_hash"]
            if (available > cutoff or valid_from > cutoff.date().isoformat()
                    or valid_to is not None and valid_to < cutoff.date().isoformat()
                    or row.get("source") not in {"B3_ASSET_CATALOG", "B3_INSTRUMENT_CATALOG"}
                    or not row.get("evidence_ref")
                    or not re.fullmatch(r"[0-9a-fA-F]{64}", evidence_hash)):
                continue
            instrument = instruments.get(instrument_id, {})
            symbol_history = latest_symbol.get(instrument_id, {})
            symbol = row["symbol"]
            if (instrument.get("asset_type") not in ("ON", "PN")
                    or str(instrument.get("isin", "")).upper() != str(row["isin"]).upper()
                    or symbol_history.get("symbol") != symbol):
                continue
        except (KeyError, TypeError, ValueError):
            continue
        previous = identity_by_symbol.get(symbol)
        if previous is None or (valid_from, available) > (previous["valid_from"], previous["_available"]):
            identity_by_symbol[symbol] = row | {"_available": available}
    ids_by_symbol = {symbol: int(row["instrument_id"]) for symbol, row in identity_by_symbol.items()}
    verified_ids = set(ids_by_symbol.values())
    intervals_by_id = {}
    for identity in identity_by_symbol.values():
        intervals_by_id.setdefault(int(identity["instrument_id"]), []).append(identity)
    bars = {}
    for row in source["bar"]:
        instrument_id = int(row["instrument_id"])
        if (row["market"] != "010" or row.get("provider") != "B3_COTAHIST"
                or utc(row["available_at"]) > cutoff or instrument_id not in verified_ids
                or not re.fullmatch(r"[0-9a-fA-F]{64}", str(row.get("raw_hash", "")))):
            continue
        if not any(row["session_date"] >= identity["valid_from"]
                   and (identity.get("valid_to") is None or row["session_date"] <= identity["valid_to"])
                   for identity in intervals_by_id[instrument_id]):
            continue
        key = (instrument_id, row["session_date"])
        prior = bars.get(key)
        if prior is None or (utc(row["available_at"]), int(row.get("id", 0))) > (
                utc(prior["available_at"]), int(prior.get("id", 0))):
            bars[key] = row
    close_by_symbol = {}
    for symbol, instrument_id in ids_by_symbol.items():
        identity = identity_by_symbol[symbol]
        close_by_symbol[symbol] = {
            date: int(row["close_px"])
            for (row_id, date), row in bars.items()
            if row_id == instrument_id and int(row["close_px"]) > 0
            and date >= identity["valid_from"]
            and (identity.get("valid_to") is None or date <= identity["valid_to"])
        }
    pair_results = []
    for symbol_a, symbol_b in RESEARCH_PAIRS:
        item = {"symbol_a": symbol_a, "symbol_b": symbol_b, "status": "BLOCKED_INSUFFICIENT_HISTORY",
                "candidate": False, "observations": 0}
        if symbol_a not in ids_by_symbol or symbol_b not in ids_by_symbol:
            item["status"] = "BLOCKED_IDENTITY_NOT_VERIFIED"
        else:
            aligned = sorted(set(close_by_symbol[symbol_a]) & set(close_by_symbol[symbol_b]))
            item["observations"] = len(aligned)
            if len(aligned) >= PAIR_DEFAULTS["cointegration_window"]:
                evaluated = evaluate_pair(
                    [close_by_symbol[symbol_a][date] for date in aligned],
                    [close_by_symbol[symbol_b][date] for date in aligned],
                )
                item.update(evaluated)
                item["symbol_a"] = symbol_a
                item["symbol_b"] = symbol_b
                item["first_session"] = aligned[-PAIR_DEFAULTS["cointegration_window"]]
                item["last_session"] = aligned[-1]
        pair_results.append(item)
    pair_results = correct_pair_tests(pair_results)
    correlation_series = {symbol: [] for symbol in OPERATIONAL_SYMBOLS}
    for symbol in OPERATIONAL_SYMBOLS:
        instrument_id = ids_by_symbol.get(symbol)
        if instrument_id is None:
            continue
        for (row_id, session), row in bars.items():
            if row_id == instrument_id:
                correlation_series[symbol].append({
                    "session_date": session, "available_at": row["available_at"],
                    "close_px": row["close_px"], "provider": row["provider"],
                    "raw_hash": row["raw_hash"],
                })
    correlation_report = correlation_heatmap(
        correlation_series, OPERATIONAL_SYMBOLS, manifest["as_of"], minimum_returns=60)
    fixed_coverage = {
        symbol: {"sessions": len(close_by_symbol.get(symbol, {})),
                 "status": "BLOCKED_IDENTITY_NOT_VERIFIED" if symbol not in ids_by_symbol else
                 ("AVAILABLE" if len(close_by_symbol.get(symbol, {})) >= 253
                  else "BLOCKED_INSUFFICIENT_HISTORY")}
        for symbol in OPERATIONAL_SYMBOLS
    }
    bars_count = len(bars)
    raw_bars_count = sum(1 for row in source["bar"]
                         if row.get("market") == "010" and row.get("provider") == "B3_COTAHIST"
                         and utc(row["available_at"]) <= cutoff)
    run_status = ("RESEARCH_ONLY" if bars_count else
                  "BLOCKED_IDENTITY_EVIDENCE" if raw_bars_count else "BLOCKED_NO_EOD_DATA")
    return {
        "schema": "ORFEU_QUANT_RESEARCH_V1",
        "status": run_status,
        "as_of": manifest["as_of"], "source_sha256": source_hash,
        "price_source_policy": "B3_COTAHIST_REGULAR_MARKET_POINT_IN_TIME_ONLY",
        "bars_available": bars_count,
        "raw_bars_available_before_identity_gate": raw_bars_count,
        "symbols_with_verified_identity": sorted(ids_by_symbol),
        "identity_evidence_count": len(identity_by_symbol),
        "operational_history": fixed_coverage,
        "correlation_heatmap": correlation_report,
        "pair_tests": pair_results,
        "pair_candidates_after_fdr": sum(bool(row.get("candidate")) for row in pair_results),
        "multifactor_status": "BLOCKED_REQUIRES_POINT_IN_TIME_FUNDAMENTALS_AND_MARKET_HISTORY",
        "machine_learning_status": "BLOCKED_NO_MATURED_MARKET_LABELS",
        "backtest_status": "BLOCKED_NO_POINT_IN_TIME_EOD",
        "trading_enabled": False, "paper_trading_only": True,
        "note": "Analytical report only. No market values or outcomes are imputed.",
    }


def main(argv=None):
    parser = argparse.ArgumentParser(description="Run ORFEU Quant Intelligence on an exported dataset.")
    parser.add_argument("--export", required=True, help="Path to an ORFEU_DATA_V1 JSONL export")
    parser.add_argument("--output", required=True, help="New JSON report path; existing files are not overwritten")
    args = parser.parse_args(argv)
    report = scan_export(args.export)
    raw = json.dumps(report, ensure_ascii=True, sort_keys=True, separators=(",", ":")).encode("utf-8")
    destination = Path(args.output)
    destination.parent.mkdir(parents=True, exist_ok=True)
    with destination.open("xb") as stream:
        stream.write(raw)
    print(json.dumps({"status": report["status"], "report": str(destination),
                      "sha256": hashlib.sha256(raw).hexdigest(),
                      "bars": report["bars_available"], "pair_candidates": report["pair_candidates_after_fdr"]}))


if __name__ == "__main__":
    main()
