# ORFEU Quant Intelligence V3 - audit and research layer

## Current state

The existing ORFEU already has PHP/MySQL collection and a paper-only ledger, a fixed
portfolio selector, replay with execution costs, rule strategies, temporal evaluation,
and an optional LightGBM challenger. This increment adds analytical research primitives
in `python/orfeu_quant/intelligence.py` and a backend-driven readiness panel in the
dashboard. No function here submits orders, fabricates returns, or changes the 15 accounts.

The local database audited on 2026-11-26 has 365 sessions, 3 quotes, 15 accounts, but
zero EOD bars, zero predictions, and zero orders/fills. All 15 accounts reconcile.
Therefore no market statistics, strategy returns, or asset rankings were calculated.
The dashboard reports that evidence gap instead of presenting simulated results.
When a hashed research report exists, the dashboard also displays its dated pair-test
table with observation counts, Engle-Granger p-values, FDR q-values, dynamic Z-score,
and half-life. A blocked pair remains visible as blocked; the panel never substitutes
prices or fabricates a candidate.

## Methods added

- Dynamic Pairs Long-Only: OLS on log prices, Engle-Granger, residual ADF, Johansen,
  CUSUM structural stability, Benjamini-Hochberg correction, Kalman intercept/hedge
  ratio, Z-score, and approximate AR(1) half-life. The cheap leg may only produce a
  research buy when it belongs to the fixed operational universe. The other leg is never
  shorted; this remains directionally exposed and is not classical market-neutral pairs.
- Multifactor: robust sector normalization and outlier clipping across VALUE, QUALITY,
  MOMENTUM, LOW VOLATILITY, and LIQUIDITY. Rows are excluded after the `as_of` cutoff;
  related metrics such as P/E and earnings yield, ROE and ROIC, short horizon returns,
  and volatility/downside risk share a factor group so duplicates do not gain extra
  weight. Scores are analytical and do not authorize trades.
- Opportunity score: the seven components specified in V3 are combined with the
  documented weights into an experimental 0-100 research score. Missing or out-of-range
  components block scoring; thresholds are labels only and never authorize orders.
  The calibrated-probability input must itself come from a temporally validated model;
  the composite score is not a probability of success.
- Adaptive Momentum: 5, 21, 63, 126, and 252-session returns, relative strength, a
  skip-last-month long horizon component, and a volume confirmation requirement.
- OHLCV indicators: ATR, ADX, MACD, RSI, Bollinger bands, EMA distances, and prior-window
  abnormal-volume ratio are computed as one validated pack with aligned bars.
- Mean Reversion: Bollinger/price Z-score, RSI, a discrete OU/AR(1) equilibrium and
  half-life diagnostic, plus explicit trend, liquidity, corporate-event, and volatility
  gates. Non-stationary fitted spreads are flagged instead of treated as mean reverting.
- Quantitative Swing: Donchian breakout or EMA pullback, volume confirmation, ATR stop,
  trailing stop input, temporal exit, and position sizing capped by risk budget, cash,
  capital exposure, ADV participation, and lot size. Signals at the close are marked for
  the next session only; this diagnostic never places an order.
- Cash and Carry, options, and microstructure: theoretical futures reference and net basis
  when a verified contract quote is supplied, European Black-Scholes call and Greeks,
  implied-volatility inversion, European put-call parity residual, American CRR binomial
  call/put values, spread and order-book imbalance. These remain analytical;
  there are no derivatives trades or market-making execution. Daily candles do not
  estimate order-book fills.
- Regime and risk: explicitly unvalidated heuristic label plus a BIC-selected Gaussian
  mixture diagnostic on dated Ibovespa return/volatility observations. It reports the
  current-state posterior but does not rotate portfolios. Drawdown, historical VaR, and
  CVaR diagnostics are available when observations exist; dynamic rotation stays disabled.

The existing ML trainer has calibrated logistic regression as a baseline and an optional
LightGBM challenger on purged temporal folds. `--random-forest` and `--xgboost` add
challengers using the same train/calibration/test splits and Platt calibration; their
reports are analytical and the trainer does not export their models for production.
XGBoost is optional and reports `BLOCKED_DEPENDENCY` when absent. Risk Parity and HRP
weight calculators now accept only a dated covariance matrix, return research allocations,
and keep dynamic rotation disabled; no verified point-in-time covariance is currently
available. VectorBT is not promoted without execution evidence. The cross-sectional
PCA/DBSCAN diagnostic returns groups for a synchronized feature snapshot and is analytical
only. HDBSCAN has an optional-runtime interface that reports `BLOCKED_DEPENDENCY` if its
package is not installed. GARCH(1,1) uses constrained Gaussian maximum likelihood and
blocks numerical failures; SciPy is declared in the quant extra. Statsmodels is optional for pair tests; if absent, evaluation returns
`BLOCKED_DEPENDENCY` instead of substituting an approximate test.

`python/orfeu_quant/monitoring.py` adds dated input-feature drift diagnostics using PSI
and two-sample KS, plus prediction-quality comparison using matured binary labels, Brier
score, and calibration gap. Review thresholds are configurable heuristics, not promotion
criteria. Missing observations are measured separately, and undersized samples,
non-chronological windows, and immature labels are blocked. The routines only report
drift; they cannot retrain, promote a model, change allocations, or submit orders. They
still need integration with persisted production feature windows and mature labels before
they can run as operational monitors.

The trainer's linear return report now includes daily cross-sectional Spearman IC when a
test session has at least three valid assets; it reports the mean, dispersion, positive
session fraction, and annualized IC information ratio across dates. `metrics.py` also
offers a paired circular-block bootstrap for aligned strategy and benchmark returns,
reporting empirical intervals for annualized active return and relative compounded return.
The linear trainer additionally reports within-session permutation ablation deltas for
return MAE and calibrated Brier scores, with repeated permutations and no use in selection.
This importance diagnostic can understate correlated features and is not causal. These
are descriptive research outputs and have not been evaluated on real ORFEU history.

`python/orfeu_quant/meta_label.py` provides a separate meta-label research flow for
already-triggered primary signals. It labels a signal as successful only when its
matured gross outcome exceeds recorded round-trip costs, purges labels that overlap the
calibration or holdout boundary, calibrates on a separate temporal partition, and emits
purged walk-forward OOF folds plus a final untouched holdout, with Brier/log-loss beside
a historical-frequency baseline. The `ORFEU_META_SIGNAL_V1`
JSONL input requires a manifest and signal-level decision, label-availability, cost, and
feature evidence whose availability timestamp cannot exceed the signal decision. The
CLI writes a hashable report only; it never exports a model or enables orders. No such
real primary-signal dataset is currently available.

`python/orfeu_quant/returns.py` computes a buy-and-hold equity curve from raw unadjusted
prices and net cash-distribution/split events. It requires verified complete action
coverage, source hashes, and action announcements available before the ex-date opening;
it blocks adjusted prices, missing coverage, unaligned action dates, and fractional-share
cash-in-lieu cases. Distribution proceeds remain cash (no reinvestment assumption), and
the function is analytical only. It is not yet connected to a real historical-action
provider or benchmark series.

`stat_arb_feature_snapshot` builds the 16 requested point-in-time features from raw,
hashed stock and benchmark OHLCV rows. It requires verified instrument identity,
synchronized history, and availability no later than the decision cutoff; it selects
the latest eligible revision and excludes later revisions. Pair diagnostics are joined
only when their availability and session match. Its output remains analytical and
explicitly disables model training and trading.

`python/orfeu_quant/tournament.py` compares independent strategy equity curves on one
common B3-session window, rebases accounts created at different dates to the same index,
checks that account equity already includes execution costs, and requires aligned
Ibovespa, CDI, buy-and-hold, equal-weighted, and static Long-Only benchmark curves.
Late revisions are excluded and any calendar gap blocks the comparison. No portfolio
history or full benchmark set currently exists to produce a market tournament result.
Its CLI consumes `ORFEU_TOURNAMENT_INPUT_V1` JSON and writes a new hash-linked
`ORFEU_TOURNAMENT_REPORT_V1` file without overwriting existing reports:

```powershell
& .venv/Scripts/python.exe -m orfeu_quant.tournament --input storage/research/tournament-input.json `
  --output storage/research/tournament-report.json
```

The input contains the official `session_dates`, one curve per account with
`initial_capital`, `costs_included: true`, and rows `{session_date, equity, available_at}`;
the five benchmark curves use `{session_date, index_value, available_at}` rows. The CLI
returns exit code 2 and a blocked report when evidence is incomplete.

## Data and validation

The provider layer continues to use existing PHP interfaces and sources for brapi, CVM,
SGS/BCB, and COTAHIST. brapi quotes are observations, not substitutes for official bars.
The research pair catalogue includes all ten requested pairs without adding them to the
fixed portfolio. The operational portfolio remains BBAS3, VALE3, PETR4, MGLU3, HAPV3,
BRKM5, PCAR3, SIMH3, MOVI3, and LJQQ3.

The B3 Daily Bulletin for 2026-11-16 includes reference constituents such as BBAS3,
PETR4, VALE3, MGLU3, MOVI3, SIMH3, and LJQQ3. Index constituents do not certify regular
market trading for the full fixed basket, current local identity mappings, liquidity, or
pair quality. The API still reports `PENDING_IDENTITY` for all ten fixed symbols, so
none was silently mapped from its ticker alone. Each symbol and ISIN must be verified in
the local catalog before use. Sources: [B3 Daily Bulletin 2026-11-16](https://arquivos.b3.com.br/bdi/download/bdi/2026-11-16/BDI_02_20261116.pdf)
and [B3 asset catalog](https://www.b3.com.br/pt_br/market-data-e-indices/servicos-de-dados/market-data/consultas/ativos/).

Synthetic fixtures in `tests/python/test_intelligence.py` check mathematical invariants
only; they are not performance evidence. Before market results can be produced, import
historical bars, benchmark, CDI, corporate actions, and historical eligibility with
lineage and availability timestamps. Then run walk-forward, purging/embargo, execution
costs, and out-of-sample comparison under `RESEARCH_PROTOCOL.md`.

## Run against an ORFEU export

```powershell
& $php bin/orfeu.php research:export --from=2015-01-01
$env:PYTHONPATH = 'python'
& .venv/Scripts/python.exe -m orfeu_quant.intelligence --export storage/exports/<sha256>.jsonl --output storage/research/quant-v3.json
& $php bin/orfeu.php research:import --file=storage/research/quant-v3.json --sha256=<report-sha256>
```

The run performed here used the actual database export, reported zero COTAHIST bars,
zero pair candidates, and `BLOCKED_NO_EOD_DATA`. The existing 5D trainer returned
`NO_MATURE_LABELS` with zero samples on the same export. Seven CVM ITR records supplied
partial margins for the 2026-09-30 period at their recorded `available_at` timestamps;
those raw ratios are visible separately from any overall factor ranking.

An unauthenticated brapi history probe returned 247 daily points for each of four symbols,
but no raw OHLC fields. These points were not imported or used as execution prices. Its
documentation describes adjusted OHLC and raw fields as a Pro feature; the B3 historical
quotes page offers COTAHIST downloads and prohibits automated collection from that page.
The B3 FAQ directs customers to authorized distributors for historical products not
exposed on the site; licensed history or user-provided authorized files are needed before
backfill. See [brapi history documentation](https://brapi.dev/docs/acoes/historico),
[B3 historical quotes](https://www.b3.com.br/pt_br/market-data-e-indices/servicos-de-dados/market-data/historico/mercado-a-vista/cotacoes-historicas/),
and [B3 UP2DATA FAQ](https://b3.com.br/pt_br/market-data-e-indices/servicos-de-dados/up2data/perguntas-frequentes/).

## Restrictions

`PAPER_TRADING_ONLY=true`; live trading, short selling, leverage, derivatives, and
automatic rotation remain disabled. Existing account and replay risk controls remain in
place. The Quant Intelligence panel reads readiness from the backend and suppresses
strategy scores and results until required evidence exists.

### Correlation heatmap

The research report includes a Pearson matrix for the fixed ten symbols only after
point-in-time identity is verified and each series has at least 60 synchronized
COTAHIST close-to-close returns with raw-file hashes. The dashboard colors positive and
negative cells and preserves explicit blocked statuses. Values use raw close prices;
splits and cash distributions are not adjusted, so this is a preliminary price-return
correlation view and must not be interpreted as total-return risk. The panel is currently
blocked because the local database has no verified identity evidence or COTAHIST bars.
