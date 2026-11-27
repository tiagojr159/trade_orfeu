import math
import random
from statistics import mean, stdev


def equity_metrics(equity, cdi):
    dates = list(equity)
    values = list(equity.values())
    if (len(dates) < 2 or dates != sorted(dates) or len(set(dates)) != len(dates)
            or any(not isinstance(value, (int, float)) or not math.isfinite(value) or value <= 0
                   for value in values)):
        raise ValueError("INVALID_EQUITY_CURVE")
    previous = peak = values[0]
    returns, excess = [], []
    drawdown = 0
    for date in dates[1:]:
        value = equity[date]
        peak = max(peak, value)
        drawdown = min(drawdown, value / peak - 1)
        result = value / previous - 1
        returns.append(result)
        if date in cdi and cdi[date] is not None:
            rate = cdi[date]
            if not isinstance(rate, (int, float)) or not math.isfinite(rate) or rate <= -1:
                raise ValueError("INVALID_CDI_SERIES")
            excess.append(result - rate)
        previous = value
    complete = len(excess) == len(returns)
    dispersion = stdev(returns) if len(returns) > 1 else 0
    excess_dispersion = stdev(excess) if complete and len(excess) > 1 else 0
    total_return = previous / values[0] - 1
    periods = len(returns)
    annualized = (previous / values[0]) ** (252 / periods) - 1
    annualized_volatility = dispersion * math.sqrt(252) if dispersion > 0 else 0.0
    return {
        "total_return": total_return,
        "annualized_return": annualized,
        "annualized_volatility": annualized_volatility,
        "max_drawdown": drawdown,
        "calmar": annualized / abs(drawdown) if drawdown < 0 else None,
        "observations": periods,
        "cdi_observations": len(excess),
        "cdi_coverage": len(excess) / periods,
        "excess_sharpe": mean(excess) * math.sqrt(252) / excess_dispersion
        if complete and excess_dispersion > 0 else None,
        "cdi_total": math.prod(1 + cdi[date] for date in dates[1:]) - 1 if complete else None,
    }


def return_metrics(strategy_returns, benchmark_returns=None, risk_free_returns=None,
                   periods_per_year=252):
    """Return risk/relative metrics from aligned simple-return series.

    Inputs are ordered sequences at one consistent frequency. Missing or mismatched
    observations are rejected instead of silently being forward-filled.
    """
    strategy = _validated_returns(strategy_returns, "INVALID_STRATEGY_RETURNS")
    if not isinstance(periods_per_year, int) or periods_per_year <= 0:
        raise ValueError("INVALID_PERIODS_PER_YEAR")
    benchmark = None if benchmark_returns is None else _validated_returns(
        benchmark_returns, "INVALID_BENCHMARK_RETURNS")
    risk_free = None if risk_free_returns is None else _validated_returns(
        risk_free_returns, "INVALID_RISK_FREE_RETURNS")
    if benchmark is not None and len(benchmark) != len(strategy):
        raise ValueError("MISALIGNED_BENCHMARK_RETURNS")
    if risk_free is not None and len(risk_free) != len(strategy):
        raise ValueError("MISALIGNED_RISK_FREE_RETURNS")

    avg = mean(strategy)
    volatility = stdev(strategy) if len(strategy) > 1 else 0.0
    compounded = math.prod(1 + value for value in strategy) - 1
    excess = [value - rate for value, rate in zip(strategy, risk_free)] if risk_free else None
    excess_vol = stdev(excess) if excess is not None and len(excess) > 1 else 0.0
    downside = [min(0.0, value - (risk_free[i] if risk_free else 0.0))
                for i, value in enumerate(strategy)]
    downside_deviation = math.sqrt(sum(value * value for value in downside) / len(downside))

    beta = alpha = tracking_error = information_ratio = None
    if benchmark is not None:
        benchmark_mean = mean(benchmark)
        benchmark_variance = sum((value - benchmark_mean) ** 2 for value in benchmark) / (len(benchmark) - 1) if len(benchmark) > 1 else 0.0
        covariance = sum((a - avg) * (b - benchmark_mean)
                         for a, b in zip(strategy, benchmark)) / (len(strategy) - 1) if len(strategy) > 1 else 0.0
        if benchmark_variance > 0:
            beta = covariance / benchmark_variance
            alpha = (avg - beta * benchmark_mean) * periods_per_year
        active = [a - b for a, b in zip(strategy, benchmark)]
        tracking_error = (stdev(active) * math.sqrt(periods_per_year)
                          if len(active) > 1 else 0.0)
        information_ratio = (mean(active) * periods_per_year / tracking_error
                             if tracking_error > 0 else None)

    return {
        "observations": len(strategy),
        "total_return": compounded,
        "annualized_return_arithmetic": avg * periods_per_year,
        "annualized_volatility": volatility * math.sqrt(periods_per_year),
        "sharpe": (mean(excess) * math.sqrt(periods_per_year) / excess_vol
                   if excess is not None and excess_vol > 0 else None),
        "sortino": (mean(excess if excess is not None else strategy) * math.sqrt(periods_per_year)
                    / downside_deviation
                    if downside_deviation > 0 else None),
        "beta": beta,
        "annualized_alpha_arithmetic": alpha,
        "tracking_error": tracking_error,
        "information_ratio": information_ratio,
    }


def trade_metrics(trades):
    """Compute realized-trade diagnostics from finite net PnL values."""
    values = list(trades)
    if any(not isinstance(value, (int, float)) or not math.isfinite(value) for value in values):
        raise ValueError("INVALID_TRADE_PNL")
    if not values:
        return {"status": "NO_TRADES", "trades": 0, "win_rate": None,
                "profit_factor": None, "expectancy": None, "average_win": None,
                "average_loss": None}
    wins = [value for value in values if value > 0]
    losses = [value for value in values if value < 0]
    gross_loss = -sum(losses)
    return {
        "status": "AVAILABLE",
        "trades": len(values),
        "win_rate": len(wins) / len(values),
        "profit_factor": sum(wins) / gross_loss if gross_loss > 0 else None,
        "expectancy": mean(values),
        "average_win": mean(wins) if wins else None,
        "average_loss": mean(losses) if losses else None,
    }


def bootstrap_performance(returns, replications=2000, block_size=10, seed=2026,
                         mode="circular_block", drawdown_limit=-0.10):
    """Bootstrap path-level total return and max drawdown diagnostics.

    `circular_block` retains short-range dependence; `iid` is a simple return
    resampling benchmark. Results are research-only and inherit the input sample's
    biases and regime coverage.
    """
    sample = _validated_returns(returns, "INVALID_BOOTSTRAP_RETURNS")
    if (len(sample) < 20 or not isinstance(replications, int) or replications < 100
            or replications > 100_000 or not isinstance(block_size, int)
            or not 1 <= block_size <= len(sample) or mode not in {"iid", "circular_block"}
            or not math.isfinite(drawdown_limit) or not -1 < drawdown_limit < 0):
        raise ValueError("INVALID_BOOTSTRAP_PARAMETERS")
    rng = random.Random(seed)
    totals, drawdowns = [], []
    size = len(sample)
    for _ in range(replications):
        path = []
        while len(path) < size:
            start = rng.randrange(size)
            length = 1 if mode == "iid" else block_size
            path.extend(sample[(start + offset) % size] for offset in range(length))
        equity = peak = 1.0
        max_drawdown = 0.0
        for value in path[:size]:
            equity *= 1 + value
            peak = max(peak, equity)
            max_drawdown = min(max_drawdown, equity / peak - 1)
        totals.append(equity - 1)
        drawdowns.append(max_drawdown)
    totals.sort()
    drawdowns.sort()
    percentile = lambda values, p: values[min(len(values) - 1, math.ceil(p * len(values)) - 1)]
    return {
        "status": "RESEARCH_ONLY_BOOTSTRAP",
        "mode": mode,
        "observations": size,
        "replications": replications,
        "block_size": block_size if mode == "circular_block" else 1,
        "seed": seed,
        "total_return_p05": percentile(totals, .05),
        "total_return_p50": percentile(totals, .50),
        "total_return_p95": percentile(totals, .95),
        "max_drawdown_p05": percentile(drawdowns, .05),
        "max_drawdown_p50": percentile(drawdowns, .50),
        "max_drawdown_p95": percentile(drawdowns, .95),
        "probability_of_loss": sum(value < 0 for value in totals) / replications,
        "probability_drawdown_limit_breach": sum(value <= drawdown_limit for value in drawdowns) / replications,
    }


def bootstrap_relative_performance(strategy_returns, benchmark_returns, replications=2000,
                                   block_size=10, seed=2026):
    """Paired circular-block bootstrap for uncertainty versus an aligned benchmark."""
    strategy = _validated_returns(strategy_returns, "INVALID_STRATEGY_RETURNS")
    benchmark = _validated_returns(benchmark_returns, "INVALID_BENCHMARK_RETURNS")
    if len(strategy) != len(benchmark):
        raise ValueError("MISALIGNED_BENCHMARK_RETURNS")
    if any(value <= -1 for value in benchmark):
        raise ValueError("BENCHMARK_WIPEOUT_RELATIVE_RETURN_UNDEFINED")
    if (len(strategy) < 20 or not isinstance(replications, int) or not 100 <= replications <= 100_000
            or not isinstance(block_size, int) or not 1 <= block_size <= len(strategy)
            or not isinstance(seed, int) or isinstance(seed, bool)):
        raise ValueError("INVALID_RELATIVE_BOOTSTRAP_PARAMETERS")
    rng = random.Random(seed)
    size = len(strategy)
    active_means, relative_totals = [], []
    for _ in range(replications):
        indices = []
        while len(indices) < size:
            start = rng.randrange(size)
            indices.extend((start + offset) % size for offset in range(block_size))
        selected = indices[:size]
        strat_wealth = math.prod(1 + strategy[index] for index in selected)
        benchmark_wealth = math.prod(1 + benchmark[index] for index in selected)
        active_means.append(mean(strategy[index] - benchmark[index] for index in selected) * 252)
        relative_totals.append(strat_wealth / benchmark_wealth - 1)
    active_means.sort()
    relative_totals.sort()
    percentile = lambda values, p: values[min(len(values) - 1, math.ceil(p * len(values)) - 1)]
    return {
        "status": "RESEARCH_ONLY_PAIRED_BLOCK_BOOTSTRAP",
        "observations": size, "replications": replications, "block_size": block_size, "seed": seed,
        "annualized_active_return_p05": percentile(active_means, .05),
        "annualized_active_return_p50": percentile(active_means, .50),
        "annualized_active_return_p95": percentile(active_means, .95),
        "relative_total_return_p05": percentile(relative_totals, .05),
        "relative_total_return_p50": percentile(relative_totals, .50),
        "relative_total_return_p95": percentile(relative_totals, .95),
        "probability_underperforming": sum(value < 0 for value in relative_totals) / replications,
        "interpretation": "EMPIRICAL_INTERVAL_CONDITIONAL_ON_OBSERVED_SAMPLE",
    }


def cross_sectional_ic(actual_returns, predicted_returns, sessions, minimum_assets=3):
    """Daily Spearman rank IC; dates are scored independently to avoid pooled time leakage."""
    actual = _validated_returns(actual_returns, "INVALID_ACTUAL_RETURNS")
    predicted = list(predicted_returns)
    if any(not isinstance(value, (int, float)) or not math.isfinite(value) for value in predicted):
        raise ValueError("INVALID_PREDICTED_RETURNS")
    dates = list(sessions)
    if len(actual) != len(predicted) or len(actual) != len(dates):
        raise ValueError("MISALIGNED_CROSS_SECTIONAL_DATA")
    if not isinstance(minimum_assets, int) or minimum_assets < 3:
        raise ValueError("INVALID_MINIMUM_CROSS_SECTION")
    if any(not isinstance(date, str) or not date for date in dates):
        raise ValueError("INVALID_CROSS_SECTIONAL_SESSION")

    from scipy.stats import spearmanr

    groups = {}
    for date, observed, forecast in zip(dates, actual, predicted, strict=True):
        groups.setdefault(date, [[], []])
        groups[date][0].append(observed)
        groups[date][1].append(forecast)
    daily = []
    for date, (observed, forecast) in sorted(groups.items()):
        if (len(observed) < minimum_assets or len(set(observed)) < 2
                or len(set(forecast)) < 2):
            continue
        value = float(spearmanr(forecast, observed).statistic)
        if math.isfinite(value):
            daily.append({"session": date, "assets": len(observed), "ic": value})
    values = [item["ic"] for item in daily]
    dispersion = stdev(values) if len(values) > 1 else 0.0
    return {
        "status": "AVAILABLE" if len(values) >= 2 else "BLOCKED_INSUFFICIENT_CROSS_SECTIONS",
        "sessions": len(values), "daily": daily,
        "mean_ic": mean(values) if values else None,
        "median_ic": sorted(values)[len(values) // 2] if values else None,
        "ic_standard_deviation": dispersion if values else None,
        "annualized_ic_information_ratio": mean(values) / dispersion * math.sqrt(252)
        if len(values) > 1 and dispersion > 0 else None,
        "positive_session_fraction": sum(value > 0 for value in values) / len(values) if values else None,
        "multiple_testing_adjustment": "NOT_APPLIED_PER_SESSION_PVALUES_NOT_REPORTED",
    }


def _validated_returns(values, error_code):
    result = list(values)
    if (not result or any(not isinstance(value, (int, float)) or not math.isfinite(value)
                          or value < -1 for value in result)):
        raise ValueError(error_code)
    return result
