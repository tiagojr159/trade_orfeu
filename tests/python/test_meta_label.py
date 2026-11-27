import json
import sys
import unittest
from datetime import date, timedelta
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest.mock import patch

import numpy as np
from orfeu_quant.data import COLUMNS
from orfeu_quant.meta_label import (
    fit_meta_labeler,
    main,
    matured_meta_samples,
    read_meta_export,
    temporal_partitions,
)


def opportunity(signal_id, day, rng, signal_pass=True, available_offset=1):
    features = rng.normal(size=len(COLUMNS))
    # The synthetic primary strategy trigger is supplied by the fixture. Outcome is
    # deliberately correlated with mom20 solely to test supervised plumbing.
    gross = 0.04 if features[1] > 0 else -0.02
    start = date(2025, 1, 1) + timedelta(days=day)
    next_day = start + timedelta(days=1)
    return {
        "signal_id": signal_id, "signal_family": "MOMENTUM", "signal_pass": signal_pass,
        "feature_as_of": f"{start.isoformat()}T21:00:00Z",
        "decision_at": f"{start.isoformat()}T21:00:00Z",
        "label_end": f"{next_day.isoformat()}T20:00:00Z",
        "label_available_at": f"{(next_day + timedelta(days=available_offset - 1)).isoformat()}T21:00:00Z",
        "gross_return": gross, "roundtrip_cost": 0.005,
        "features": dict(zip(COLUMNS, features.tolist(), strict=True)),
    }


class MetaLabelTests(unittest.TestCase):
    def test_dataset_only_uses_triggered_and_matured_net_outcomes(self):
        rng = np.random.default_rng(8)
        rows = [opportunity("pass", 1, rng), opportunity("not-triggered", 2, rng, False),
                opportunity("future", 3, rng, available_offset=400)]
        result = matured_meta_samples(rows, "2025-06-01T00:00:00Z")
        self.assertEqual(result["status"], "AVAILABLE")
        self.assertEqual([row["signal_id"] for row in result["samples"]], ["pass"])
        self.assertEqual(result["samples"][0]["meta_label"],
                         int(result["samples"][0]["gross_return"] > result["samples"][0]["roundtrip_cost"]))
        self.assertEqual(result["excluded"], {"not_triggered": 1, "not_matured": 1})
        bad = opportunity("future-feature", 4, rng)
        bad["feature_as_of"] = "2025-01-06T00:00:00Z"
        with self.assertRaisesRegex(ValueError, "TIMESTAMPS"):
            matured_meta_samples([bad], "2025-06-01T00:00:00Z")

    def test_temporally_separated_meta_labeler_is_research_only(self):
        rng = np.random.default_rng(19)
        groups = []
        for start, count in ((0, 100), (130, 40), (190, 40)):
            records = [opportunity(f"{start}-{i}", start + i, rng) for i in range(count)]
            groups.append(matured_meta_samples(records, "2026-01-01T00:00:00Z")["samples"])
        result = fit_meta_labeler(*groups, "2026-01-01T00:00:00Z")
        self.assertEqual(result["status"], "RESEARCH_ONLY_OOS_META_LABEL")
        self.assertEqual(result["calibration"], "PLATT_ON_SEPARATE_TEMPORAL_PARTITION")
        self.assertFalse(result["model_exported"])
        self.assertFalse(result["orders_enabled"])
        self.assertTrue(result["promote_only_if_beats_baseline"])

    def test_temporal_partitions_purge_labels_at_boundaries(self):
        rng = np.random.default_rng(25)
        records = [opportunity(f"id-{i}", i, rng) for i in range(230)]
        samples = matured_meta_samples(records, "2026-01-01T00:00:00Z")["samples"]
        train, calibration, test = temporal_partitions(samples, "2025-07-10")
        self.assertLess(max(row["label_available_at"] for row in train),
                        min(row["decision_at"] for row in calibration))
        self.assertLess(max(row["label_available_at"] for row in calibration),
                        min(row["decision_at"] for row in test))

    def test_meta_export_checks_manifest_and_preserves_source_fingerprint(self):
        with TemporaryDirectory() as directory:
            path = Path(directory) / "meta.jsonl"
            payload = (json.dumps({"kind": "manifest", "data": {"schema": "ORFEU_META_SIGNAL_V1",
                                                                     "as_of": "2026-01-01T00:00:00Z"}}) + "\n"
                       + json.dumps({"kind": "meta_signal", "data": {"signal_id": "s1"}}) + "\n")
            path.write_text(payload, encoding="utf-8")
            signals, manifest, digest = read_meta_export(path)
            self.assertEqual(manifest["schema"], "ORFEU_META_SIGNAL_V1")
            self.assertEqual(signals, [{"signal_id": "s1"}])
            self.assertEqual(len(digest), 64)

    def test_meta_label_cli_writes_hashed_research_report_without_model_artifact(self):
        rng = np.random.default_rng(31)
        records = [opportunity(f"cli-{i}-{asset}", i, rng)
                   for i in range(230) for asset in range(4)]
        with TemporaryDirectory() as directory:
            root = Path(directory)
            export = root / "signals.jsonl"
            lines = [json.dumps({"kind": "manifest", "data": {"schema": "ORFEU_META_SIGNAL_V1",
                                                                    "as_of": "2026-01-01T00:00:00Z"}})]
            lines.extend(json.dumps({"kind": "meta_signal", "data": record}) for record in records)
            export.write_text("\n".join(lines) + "\n", encoding="utf-8")
            output = root / "report.json"
            argv = ["meta_label", "--export", str(export), "--as-of", "2026-01-01T00:00:00Z",
                    "--holdout", "2025-07-10", "--folds", "3", "--output", str(output)]
            with patch.object(sys, "argv", argv):
                self.assertEqual(main(), 0)
            report = json.loads(output.read_text(encoding="utf-8"))
            self.assertEqual(report["status"], "RESEARCH_ONLY_OOF_META_LABEL")
            self.assertEqual(len(report["walk_forward"]), 3)
            self.assertFalse(report["holdout_used_for_selection"])
            self.assertFalse(report["model_exported"])
            self.assertFalse(report["orders_enabled"])

    def test_meta_labeler_blocks_temporal_leakage_and_immature_test_labels(self):
        rng = np.random.default_rng(22)
        groups = []
        for start, count in ((0, 60), (90, 30), (140, 30)):
            records = [opportunity(f"{start}-{i}", start + i, rng) for i in range(count)]
            groups.append(matured_meta_samples(records, "2026-01-01T00:00:00Z")["samples"])
        groups[1][0]["decision_at"] = groups[0][-1]["label_available_at"] - timedelta(minutes=1)
        groups[1][0]["feature_as_of"] = groups[1][0]["decision_at"]
        result = fit_meta_labeler(*groups, "2026-01-01T00:00:00Z")
        self.assertEqual(result["status"], "BLOCKED_TEMPORAL_LEAKAGE")

        groups[1][0]["decision_at"] = groups[0][-1]["label_available_at"] + timedelta(days=1)
        groups[1][0]["feature_as_of"] = groups[1][0]["decision_at"]
        result = fit_meta_labeler(*groups, "2025-01-01T00:00:00Z")
        self.assertEqual(result["status"], "BLOCKED_IMMATURE_TEST_LABELS")


if __name__ == "__main__":
    unittest.main()
