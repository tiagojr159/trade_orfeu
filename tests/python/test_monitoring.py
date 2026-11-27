import unittest

import numpy as np
from orfeu_quant.monitoring import feature_drift_report, prediction_quality_drift


class DriftMonitoringTests(unittest.TestCase):
    def setUp(self):
        self.reference = {"return_21d": np.random.default_rng(42).normal(0, 1, 500)}

    def test_stable_distribution_stays_in_review_range(self):
        current = {"return_21d": np.random.default_rng(43).normal(0, 1, 500)}
        result = feature_drift_report(self.reference, current, "2025-01-01", "2025-06-01")
        self.assertEqual(result["status"], "STABLE")
        self.assertEqual(result["features"][0]["status"], "STABLE")
        self.assertFalse(result["model_promotion"])
        self.assertFalse(result["retraining"])

    def test_shifted_distribution_alerts_without_adapting_model(self):
        current = {"return_21d": np.random.default_rng(44).normal(2, 1, 500)}
        result = feature_drift_report(self.reference, current, "2025-01-01", "2025-06-01")
        self.assertEqual(result["status"], "DRIFT_ALERT")
        self.assertGreater(result["features"][0]["psi"], 0.25)
        self.assertFalse(result["dynamic_rotation"])

    def test_missing_values_are_counted_and_small_windows_are_blocked(self):
        current = {"return_21d": np.array([np.nan] * 480 + [0.0] * 20)}
        result = feature_drift_report(self.reference, current, "2025-01-01", "2025-06-01")
        self.assertEqual(result["status"], "PARTIAL_INSUFFICIENT_DATA")
        self.assertEqual(result["features"][0]["status"], "BLOCKED_INSUFFICIENT_DATA")

    def test_invalid_time_or_feature_schema_blocks_comparison(self):
        self.assertEqual(
            feature_drift_report(self.reference, self.reference, "2025-06-01", "2025-01-01")["status"],
            "BLOCKED_NON_CHRONOLOGICAL_WINDOWS",
        )
        self.assertEqual(
            feature_drift_report(self.reference, {"rsi": [1, 2]}, "2025-01-01", "2025-06-01")["status"],
            "BLOCKED_FEATURE_SCHEMA_MISMATCH",
        )

    def test_prediction_quality_uses_only_mature_labels(self):
        reference = {"probability": [0.8] * 50, "label": [1] * 40 + [0] * 10}
        current = {"probability": [0.8] * 50, "label": [1] * 40 + [0] * 10}
        blocked = prediction_quality_drift(reference, current, "2025-01-01", "2025-06-01")
        self.assertEqual(blocked["status"], "BLOCKED_IMMATURE_LABELS")
        result = prediction_quality_drift(reference, current, "2025-01-01", "2025-06-01",
                                          labels_matured=True)
        self.assertEqual(result["status"], "NO_MATERIAL_DEGRADATION")
        self.assertFalse(result["model_promotion"])

    def test_prediction_quality_degradation_is_flagged_not_auto_retrained(self):
        reference = {"probability": [0.8] * 50, "label": [1] * 40 + [0] * 10}
        current = {"probability": [0.8] * 50, "label": [0] * 50}
        result = prediction_quality_drift(reference, current, "2025-01-01", "2025-06-01",
                                          labels_matured=True)
        self.assertEqual(result["status"], "QUALITY_DRIFT_ALERT")
        self.assertGreater(result["brier_change"], 0.05)
        self.assertFalse(result["retraining"])


if __name__ == "__main__":
    unittest.main()
