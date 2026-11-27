"""Research-only covariance allocations. The output never changes ORFEU portfolios."""

import math


def covariance_allocations(symbols, covariance, as_of):
    """Return risk-budgeting and HRP weights from one dated covariance matrix."""
    names = list(symbols)
    if len(names) < 2 or len(set(names)) != len(names) or any(not name for name in names):
        return {"status": "BLOCKED_INVALID_SYMBOLS", "dynamic_rotation": False}
    if not isinstance(as_of, str) or not as_of:
        return {"status": "BLOCKED_MISSING_COVARIANCE_CUTOFF", "dynamic_rotation": False}
    try:
        import numpy as np
        from scipy.cluster.hierarchy import leaves_list, linkage
        from scipy.optimize import minimize
        from scipy.spatial.distance import squareform
    except ImportError as error:
        return {"status": "BLOCKED_DEPENDENCY", "missing": error.name, "dynamic_rotation": False}

    matrix = np.asarray(covariance, dtype=float)
    count = len(names)
    if (matrix.shape != (count, count) or not np.isfinite(matrix).all()
            or not np.allclose(matrix, matrix.T, atol=1e-10) or np.any(np.diag(matrix) <= 0)):
        return {"status": "BLOCKED_INVALID_COVARIANCE", "dynamic_rotation": False}
    eigenvalues = np.linalg.eigvalsh(matrix)
    if float(eigenvalues.min()) < -1e-8:
        return {"status": "BLOCKED_NON_PSD_COVARIANCE", "dynamic_rotation": False}

    budget = np.full(count, 1 / count)
    inverse_volatility = 1 / np.sqrt(np.diag(matrix))
    initial = inverse_volatility / inverse_volatility.sum()

    def risk_budget_objective(weights):
        variance = float(weights @ matrix @ weights)
        if variance <= 0 or np.any(weights <= 0):
            return math.inf
        return .5 * variance - float(budget @ np.log(weights))

    def risk_budget_gradient(weights):
        return matrix @ weights - budget / weights

    result = minimize(risk_budget_objective, initial, jac=risk_budget_gradient, method="L-BFGS-B",
                      bounds=[(1e-8, None)] * count, options={"maxiter": 1000, "ftol": 1e-12})
    if not result.success or not np.isfinite(result.x).all():
        return {"status": "BLOCKED_RISK_PARITY_OPTIMIZER", "dynamic_rotation": False}
    risk_parity = result.x / result.x.sum()

    volatility = np.sqrt(np.diag(matrix))
    correlation = matrix / np.outer(volatility, volatility)
    correlation = np.clip((correlation + correlation.T) / 2, -1, 1)
    distance = np.sqrt(np.maximum(0, (1 - correlation) / 2))
    np.fill_diagonal(distance, 0)
    tree = linkage(squareform(distance, checks=True), method="single")
    order = [int(item) for item in leaves_list(tree)]
    hrp = np.ones(count)
    clusters = [order]
    while clusters:
        split_clusters = []
        for cluster in clusters:
            if len(cluster) <= 1:
                continue
            midpoint = len(cluster) // 2
            left, right = cluster[:midpoint], cluster[midpoint:]

            def cluster_variance(indices):
                sub = matrix[np.ix_(indices, indices)]
                local = 1 / np.diag(sub)
                local /= local.sum()
                return float(local @ sub @ local)

            var_left, var_right = cluster_variance(left), cluster_variance(right)
            left_weight = var_right / (var_left + var_right)
            hrp[left] *= left_weight
            hrp[right] *= 1 - left_weight
            split_clusters.extend((left, right))
        clusters = split_clusters
    hrp /= hrp.sum()

    def weight_rows(weights):
        contributions = weights * (matrix @ weights)
        contributions /= contributions.sum()
        return [{"symbol": names[index], "weight": float(weights[index]),
                 "risk_contribution": float(contributions[index])}
                for index in range(count)]

    return {"status": "ANALYTICAL_ONLY", "as_of": as_of,
            "risk_parity": weight_rows(risk_parity), "hrp": weight_rows(hrp),
            "hierarchical_order": [names[index] for index in order],
            "dynamic_rotation": False, "trading_status": "DISABLED"}
