"""Causal total-return accounting from raw prices and verified corporate actions."""

import math

from .data import utc


def corporate_action_total_return(bars, actions, coverage, as_of, starting_capital_cents):
    """Build a buy-and-hold equity curve without double-counting adjusted prices.

    Bars use integer cents and must be explicitly raw/unadjusted. Cash distributions
    use net cents per share. Splits that create fractional shares are blocked because
    this function has no cash-in-lieu settlement evidence.
    """
    blocked = {"status": "BLOCKED_INVALID_TOTAL_RETURN_INPUT"}
    try:
        cutoff = utc(as_of)
    except (TypeError, ValueError):
        return {"status": "BLOCKED_INVALID_AS_OF"}
    if (not isinstance(starting_capital_cents, int) or isinstance(starting_capital_cents, bool)
            or starting_capital_cents <= 0 or not isinstance(bars, list) or len(bars) < 2
            or not isinstance(actions, list) or not isinstance(coverage, dict)):
        return blocked
    if coverage.get("verified") is not True or coverage.get("complete") is not True:
        return {"status": "BLOCKED_INCOMPLETE_ACTION_COVERAGE"}
    try:
        if (coverage["start"] != bars[0]["session"] or coverage["end"] != bars[-1]["session"]
                or utc(coverage["available_at"]) > cutoff or not coverage.get("evidence_ref")):
            return {"status": "BLOCKED_ACTION_COVERAGE_CUTOFF_OR_RANGE"}
    except (KeyError, TypeError, ValueError):
        return {"status": "BLOCKED_INVALID_ACTION_COVERAGE"}

    sessions, grouped_actions = [], {}
    try:
        for bar in bars:
            session = bar["session"]
            if (bar.get("basis") != "RAW_UNADJUSTED" or not isinstance(session, str)
                    or not isinstance(bar["close_cents"], int) or bar["close_cents"] <= 0
                    or not bar.get("source_id") or len(bar.get("raw_hash", "")) != 64
                    or any(character not in "0123456789abcdef" for character in bar["raw_hash"].lower())):
                return {"status": "BLOCKED_INVALID_OR_ADJUSTED_PRICE_BAR"}
            if utc(bar["available_at"]) > cutoff:
                return {"status": "BLOCKED_FUTURE_PRICE_BAR"}
            if sessions and session <= sessions[-1]:
                return {"status": "BLOCKED_UNORDERED_OR_DUPLICATE_SESSIONS"}
            sessions.append(session)

        action_ids = set()
        for action in actions:
            action_id = action["action_id"]
            if not isinstance(action_id, str) or not action_id or action_id in action_ids:
                return {"status": "BLOCKED_DUPLICATE_OR_INVALID_ACTION_ID"}
            action_ids.add(action_id)
            ex_date = action["ex_date"]
            if ex_date not in sessions:
                if sessions[0] <= ex_date <= sessions[-1]:
                    return {"status": "BLOCKED_ACTION_DATE_NOT_IN_PRICE_SESSIONS"}
                continue
            if ex_date == sessions[0]:
                return {"status": "BLOCKED_ACTION_ON_INITIAL_PURCHASE_SESSION"}
            bar = bars[sessions.index(ex_date)]
            if utc(action["available_at"]) > utc(bar["open_at"]):
                return {"status": "BLOCKED_ACTION_NOT_KNOWN_BEFORE_EX_DATE"}
            if not action.get("evidence_ref"):
                return {"status": "BLOCKED_ACTION_WITHOUT_LINEAGE"}
            grouped_actions.setdefault(ex_date, []).append(action)
    except (KeyError, TypeError, ValueError):
        return blocked

    shares = starting_capital_cents // bars[0]["close_cents"]
    if shares < 1:
        return {"status": "BLOCKED_INSUFFICIENT_INITIAL_CAPITAL"}
    cash = starting_capital_cents - shares * bars[0]["close_cents"]
    initial_equity = starting_capital_cents
    equity = [{"session": bars[0]["session"], "equity_cents": initial_equity,
               "shares": shares, "cash_cents": cash, "return": 0.0}]
    applied = []
    for bar in bars[1:]:
        for action in grouped_actions.get(bar["session"], []):
            if action["type"] == "CASH_DISTRIBUTION_NET":
                amount = action.get("amount_net_cents_per_share")
                if not isinstance(amount, int) or isinstance(amount, bool) or amount < 0:
                    return {"status": "BLOCKED_INVALID_DISTRIBUTION_AMOUNT"}
                cash += shares * amount
            elif action["type"] == "SPLIT":
                numerator, denominator = action.get("numerator"), action.get("denominator")
                if (not isinstance(numerator, int) or isinstance(numerator, bool) or numerator <= 0
                        or not isinstance(denominator, int) or isinstance(denominator, bool) or denominator <= 0):
                    return {"status": "BLOCKED_INVALID_SPLIT_RATIO"}
                scaled = shares * numerator
                if scaled % denominator:
                    return {"status": "BLOCKED_FRACTIONAL_SHARE_CASH_IN_LIEU_REQUIRED"}
                shares = scaled // denominator
            else:
                return {"status": "BLOCKED_UNSUPPORTED_CORPORATE_ACTION"}
            applied.append(action["action_id"])
        value = shares * bar["close_cents"] + cash
        previous = equity[-1]["equity_cents"]
        equity.append({"session": bar["session"], "equity_cents": value,
                       "shares": shares, "cash_cents": cash,
                       "return": value / previous - 1})
    returns = [row["return"] for row in equity[1:]]
    total_return = equity[-1]["equity_cents"] / initial_equity - 1
    if not math.isfinite(total_return) or any(not math.isfinite(value) for value in returns):
        return {"status": "BLOCKED_NONFINITE_TOTAL_RETURN"}
    return {"status": "RESEARCH_ONLY_TOTAL_RETURN", "basis": "RAW_PRICES_PLUS_NET_ACTIONS",
            "as_of": cutoff.isoformat(), "observations": len(returns), "equity": equity,
            "returns": returns, "total_return": total_return,
            "applied_action_ids": applied, "orders_enabled": False,
            "fractional_cash_in_lieu_supported": False}
