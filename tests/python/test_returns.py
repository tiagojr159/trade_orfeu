import unittest

from orfeu_quant.returns import corporate_action_total_return


def bar(session, close, basis="RAW_UNADJUSTED"):
    return {"session": session, "close_cents": close, "basis": basis,
            "open_at": f"{session}T14:00:00Z", "available_at": f"{session}T22:00:00Z",
            "source_id": "B3_COTAHIST", "raw_hash": "a" * 64}


def coverage(bars):
    return {"verified": True, "complete": True, "start": bars[0]["session"], "end": bars[-1]["session"],
            "available_at": "2025-01-10T00:00:00Z", "evidence_ref": "coverage-fixture"}


class CorporateActionReturnTests(unittest.TestCase):
    def test_net_distribution_is_counted_once_from_raw_prices(self):
        bars = [bar("2025-01-02", 10000), bar("2025-01-03", 9500), bar("2025-01-06", 10000)]
        action = {"action_id": "cash-1", "type": "CASH_DISTRIBUTION_NET", "ex_date": "2025-01-03",
                  "available_at": "2025-01-02T16:00:00Z", "amount_net_cents_per_share": 500,
                  "evidence_ref": "issuer-notice"}
        result = corporate_action_total_return(bars, [action], coverage(bars),
                                               "2025-01-10T00:00:00Z", 100000)
        self.assertEqual(result["status"], "RESEARCH_ONLY_TOTAL_RETURN")
        self.assertEqual(result["equity"][1]["equity_cents"], 100000)
        self.assertAlmostEqual(result["total_return"], .05)
        self.assertEqual(result["applied_action_ids"], ["cash-1"])

    def test_split_preserves_value_and_scales_shares(self):
        bars = [bar("2025-01-02", 10000), bar("2025-01-03", 5000), bar("2025-01-06", 5100)]
        action = {"action_id": "split-1", "type": "SPLIT", "ex_date": "2025-01-03",
                  "available_at": "2025-01-02T16:00:00Z", "numerator": 2, "denominator": 1,
                  "evidence_ref": "issuer-notice"}
        result = corporate_action_total_return(bars, [action], coverage(bars),
                                               "2025-01-10T00:00:00Z", 100000)
        self.assertEqual(result["equity"][1]["equity_cents"], 100000)
        self.assertEqual(result["equity"][1]["shares"], 20)
        self.assertAlmostEqual(result["total_return"], .02)

    def test_blocks_adjusted_prices_incomplete_coverage_and_late_action_notice(self):
        bars = [bar("2025-01-02", 10000), bar("2025-01-03", 9500)]
        action = {"action_id": "cash-1", "type": "CASH_DISTRIBUTION_NET", "ex_date": "2025-01-03",
                  "available_at": "2025-01-03T15:00:00Z", "amount_net_cents_per_share": 500,
                  "evidence_ref": "issuer-notice"}
        late = corporate_action_total_return(bars, [action], coverage(bars), "2025-01-10T00:00:00Z", 100000)
        self.assertEqual(late["status"], "BLOCKED_ACTION_NOT_KNOWN_BEFORE_EX_DATE")
        adjusted = [bars[0], bar("2025-01-03", 10000, "ADJUSTED")]
        self.assertEqual(corporate_action_total_return(adjusted, [], coverage(adjusted),
                         "2025-01-10T00:00:00Z", 100000)["status"],
                         "BLOCKED_INVALID_OR_ADJUSTED_PRICE_BAR")
        incomplete = {**coverage(bars), "complete": False}
        self.assertEqual(corporate_action_total_return(bars, [], incomplete,
                         "2025-01-10T00:00:00Z", 100000)["status"],
                         "BLOCKED_INCOMPLETE_ACTION_COVERAGE")
        gap_bars = [bar("2025-01-02", 10000), bar("2025-01-06", 9900)]
        missing_session = {"action_id": "cash-off-calendar", "type": "CASH_DISTRIBUTION_NET",
                           "ex_date": "2025-01-03", "available_at": "2025-01-02T12:00:00Z",
                           "amount_net_cents_per_share": 10, "evidence_ref": "issuer-notice"}
        self.assertEqual(corporate_action_total_return(gap_bars, [missing_session], coverage(gap_bars),
                         "2025-01-10T00:00:00Z", 100000)["status"],
                         "BLOCKED_ACTION_DATE_NOT_IN_PRICE_SESSIONS")

    def test_blocks_future_prices_missing_lineage_and_fractional_split_cash_in_lieu(self):
        bars = [bar("2025-01-02", 10000), bar("2025-01-03", 5000)]
        future = [bars[0], {**bars[1], "available_at": "2025-02-01T00:00:00Z"}]
        self.assertEqual(corporate_action_total_return(future, [], coverage(future),
                         "2025-01-10T00:00:00Z", 100000)["status"], "BLOCKED_FUTURE_PRICE_BAR")
        action = {"action_id": "split-1", "type": "SPLIT", "ex_date": "2025-01-03",
                  "available_at": "2025-01-02T16:00:00Z", "numerator": 1, "denominator": 3,
                  "evidence_ref": "issuer-notice"}
        self.assertEqual(corporate_action_total_return(bars, [action], coverage(bars),
                         "2025-01-10T00:00:00Z", 100000)["status"],
                         "BLOCKED_FRACTIONAL_SHARE_CASH_IN_LIEU_REQUIRED")
        action.pop("evidence_ref")
        self.assertEqual(corporate_action_total_return(bars, [action], coverage(bars),
                         "2025-01-10T00:00:00Z", 100000)["status"], "BLOCKED_ACTION_WITHOUT_LINEAGE")


if __name__ == "__main__":
    unittest.main()
