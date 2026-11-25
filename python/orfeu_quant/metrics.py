import math
from statistics import mean, stdev


def equity_metrics(equity, cdi):
    dates = list(equity)
    if len(dates) < 2 or dates != sorted(dates) or min(equity.values()) <= 0:
        raise ValueError("INVALID_EQUITY_CURVE")
    previous = peak = equity[dates[0]]
    returns, excess = [], []
    drawdown = 0
    for date in dates[1:]:
        value = equity[date]
        peak = max(peak, value)
        drawdown = min(drawdown, value / peak - 1)
        result = value / previous - 1
        returns.append(result)
        if date in cdi and cdi[date] is not None:
            excess.append(result - cdi[date])
        previous = value
    complete = len(excess) == len(returns)
    dispersion = stdev(excess) if complete and len(excess) > 1 else 0
    return {
        "total_return": previous / equity[dates[0]] - 1,
        "max_drawdown": drawdown,
        "observations": len(returns),
        "cdi_coverage": len(excess) / len(returns),
        "excess_sharpe": mean(excess) * math.sqrt(252) / dispersion if dispersion > 0 else None,
        "cdi_total": math.prod(1 + cdi[date] for date in dates[1:]) - 1 if complete else None,
    }
