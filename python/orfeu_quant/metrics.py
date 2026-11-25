import math
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
