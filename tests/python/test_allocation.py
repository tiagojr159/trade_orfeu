import unittest

from orfeu_quant.allocation import covariance_allocations


class AllocationResearchTests(unittest.TestCase):
    def test_risk_parity_and_hrp_weights_are_normalized_and_non_operational(self):
        result = covariance_allocations(
            ["AAA3", "BBB3", "CCC3"],
            [[.04, .006, .004], [.006, .09, .012], [.004, .012, .16]],
            "2026-01-30T22:00:00Z",
        )
        self.assertEqual(result["status"], "ANALYTICAL_ONLY")
        self.assertFalse(result["dynamic_rotation"])
        for key in ("risk_parity", "hrp"):
            rows = result[key]
            self.assertAlmostEqual(sum(row["weight"] for row in rows), 1, places=10)
            self.assertTrue(all(row["weight"] > 0 for row in rows))
        risk = [row["risk_contribution"] for row in result["risk_parity"]]
        self.assertLess(max(risk) - min(risk), 1e-4)

    def test_covariance_allocators_block_bad_matrices(self):
        non_psd = covariance_allocations(["A", "B"], [[1, 2], [2, 1]], "2026-01-30")
        self.assertEqual(non_psd["status"], "BLOCKED_NON_PSD_COVARIANCE")
        bad_shape = covariance_allocations(["A", "B"], [[1]], "2026-01-30")
        self.assertEqual(bad_shape["status"], "BLOCKED_INVALID_COVARIANCE")


if __name__ == "__main__":
    unittest.main()
