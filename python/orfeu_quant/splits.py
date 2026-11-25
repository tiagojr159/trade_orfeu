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


def purged_walk_forward(rows, sessions, holdout, gap, folds=3):
    """Build disjoint rolling test windows strictly before the untouched holdout."""
    sessions = sorted(set(sessions))
    if holdout not in sessions or gap < 0 or not isinstance(folds, int) or folds < 2 or folds > 10:
        raise ValueError("INVALID_WALK_FORWARD")
    holdout_index = sessions.index(holdout)
    first_test = max(120 + 2 * gap, int(holdout_index * .60))
    available = holdout_index - first_test
    if available < folds * 20:
        raise ValueError("INSUFFICIENT_WALK_FORWARD_SESSIONS")
    width = available // folds
    output = []
    for index in range(folds):
        start = first_test + index * width
        end = holdout_index if index == folds - 1 else first_test + (index + 1) * width
        train, calibration, future = purged_split(rows, sessions, sessions[start], gap)
        test = [row for row in future if sessions[start] <= row["session"] < sessions[end]]
        if len(test) < 50:
            raise ValueError("INSUFFICIENT_WALK_FORWARD_SAMPLES")
        output.append((train, calibration, test))
    return output
