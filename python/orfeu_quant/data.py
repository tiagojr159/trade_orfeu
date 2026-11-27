import hashlib
import json
from bisect import bisect_left
from collections import defaultdict
from datetime import UTC, datetime

SCHEMA = "eod-raw-v1"
COLUMNS = ["mom5", "mom20", "mom60", "vol20", "trend", "rsi", "z20", "relative", "atr"]


def utc(value):
    result = datetime.fromisoformat(value)
    return result.astimezone(UTC) if result.tzinfo else result.replace(tzinfo=UTC)


def read_export(path):
    checksum = hashlib.sha256()
    data = defaultdict(list)
    with path.open("rb") as stream:
        for line in stream:
            checksum.update(line)
            item = json.loads(line)
            data[item["kind"]].append(item["data"])
    if len(data["manifest"]) != 1:
        raise ValueError("INVALID_DATA_MANIFEST")
    manifest = data["manifest"][0]
    if manifest["schema"] != "ORFEU_DATA_V1" or manifest["feature_schema"] != SCHEMA:
        raise ValueError("INVALID_DATA_SCHEMA")
    return data, checksum.hexdigest()


def visible_revision(rows, date, as_of):
    choices = [
        item for item in rows
        if item["valid_from"] <= date and utc(item["available_at"]) <= as_of
    ]
    return max(choices, key=lambda r: (r["valid_from"], utc(r["available_at"])), default=None)


def panel(data, horizon):
    sessions = sorted((r for r in data["session"] if r["open_at"]), key=lambda r: r["session_date"])
    dates = [r["session_date"] for r in sessions]
    if len(dates) != len(set(dates)):
        raise ValueError("DUPLICATE_SESSION")
    export_at = utc(data["manifest"][0]["as_of"])
    eligibility, event_dates, coverage = defaultdict(list), defaultdict(list), defaultdict(list)
    for item in data["eligibility"]:
        eligibility[int(item["instrument_id"])].append(item)
    instrument_ids = {
        int(row["id"]) for row in data["instrument"]
        if row["asset_type"] in ("ON", "PN") and row["isin"].startswith("BR")
    }
    for item in data["observation"]:
        if item["kind"] == "CORPORATE_ACTION":
            event_dates[int(json.loads(item["payload"])["instrument_id"])].append(item["ref_date"])
        elif item["kind"] == "ACTION_COVERAGE":
            content = json.loads(item["payload"])
            if content.get("verified") is True:
                coverage[int(item["subject"])].append(
                    (content["start"], item["ref_date"], utc(item["available_at"]))
                )
    opening = defaultdict(list)
    for item in data["bar"]:
        if item["market"] == "020" and item["provider"] == "B3_COTAHIST":
            opening[(int(item["instrument_id"]), item["session_date"])].append(item)

    samples = {}
    for snapshot in sorted(data["feature"], key=lambda row: utc(row["as_of"])):
        instrument = int(snapshot["instrument_id"])
        if instrument not in instrument_ids:
            continue
        features = json.loads(snapshot["payload"])
        if features.get("version") != SCHEMA or not all(col in features for col in COLUMNS):
            continue
        date, decision = features["session_date"], utc(snapshot["as_of"])
        status = visible_revision(eligibility[instrument], date, decision)
        if not status or status["status"] != "ACTIVE":
            continue
        index = bisect_left(dates, date)
        if index + horizon + 1 >= len(dates) or dates[index] != date:
            continue
        start, end = sessions[index + 1], sessions[index + 1 + horizon]
        entry, exit_at = utc(start["open_at"]), utc(end["open_at"])
        if not (utc(sessions[index]["close_at"]) <= decision < entry):
            continue
        # The label uses the earliest stored vintage and matures when all evidence is available.
        start_date, end_date = start["session_date"], end["session_date"]
        action_stamp = min(
            (stamp for first, last, stamp in coverage[instrument] if first <= start_date and last >= end_date),
            default=None,
        )
        if action_stamp is None or any(start_date <= event <= end_date for event in event_dates[instrument]):
            continue
        path = [
            min(opening[(instrument, ref)], key=lambda bar: (utc(bar["available_at"]), int(bar["id"])), default=None)
            for ref in dates[index + 1:index + horizon + 2]
        ]
        if any(not bar or int(bar["open_px"]) <= 0 for bar in path):
            continue
        matured = max(action_stamp, *(utc(bar["available_at"]) for bar in path))
        if not exit_at <= matured <= export_at:
            continue
        samples.setdefault((instrument, date), {
            "instrument_id": instrument, "session": date, "as_of": decision, "entry_at": entry,
            "label_end": exit_at, "label_available_at": matured,
            "gross": int(path[-1]["open_px"]) / int(path[0]["open_px"]) - 1,
            "features": [features[col] for col in COLUMNS],
        })
    return list(samples.values())
