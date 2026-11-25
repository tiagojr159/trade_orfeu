def purged_split(rows, sessions, holdout, gap):
    sessions = sorted(set(sessions))
    if holdout not in sessions or gap < 0:
        raise ValueError("INVALID_HOLDOUT")
    boundary = sessions.index(holdout)
    if len(sessions) - boundary < 20 or boundary < 120 + gap * 2:
        raise ValueError("INSUFFICIENT_SESSIONS")
    calibration = int(boundary * .8)
    train_dates = set(sessions[:calibration - gap])
    calibration_dates = set(sessions[calibration:boundary - gap])
    test_dates = set(sessions[boundary:])
    test = [row for row in rows if row["session"] in test_dates]
    train = [row for row in rows if row["session"] in train_dates]
    cal = [row for row in rows if row["session"] in calibration_dates]
    if not train or not cal or not test:
        raise ValueError("INSUFFICIENT_MATURED_DATA")
    cal_time = min(row["as_of"] for row in cal)
    test_time = min(row["as_of"] for row in test)
    train = [row for row in train if max(row["label_end"], row["label_available_at"]) < cal_time]
    cal = [row for row in cal if max(row["label_end"], row["label_available_at"]) < test_time]
    if len(train) < 200 or len(cal) < 50 or len(test) < 50:
        raise ValueError("INSUFFICIENT_PURGED_SAMPLES")
    return train, cal, test
