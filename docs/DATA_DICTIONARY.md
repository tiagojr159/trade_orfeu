# Dicionário de dados

| Dados | Tabelas | Unidade ou chave temporal |
| --- | --- | --- |
| Emissor e ação | `orf_issuer`, `orf_instrument` | ID interno e ISIN estável de ação ON/PN |
| Símbolo e elegibilidade | `orf_symbol_history`, `orf_eligibility_history` | Vigência local e disponibilidade UTC; revisão posterior preservada |
| Sessão B3 | `orf_session` | Data São Paulo, abertura, fim contínuo e fechamento em UTC, flag de liquidação |
| Barras diárias | `orf_bar` | OHLC em centavos BRL, quantidade inteira, volume financeiro em centavos, mercado 010/020 |
| Cotações | `orf_quote` | Cotação de acompanhamento em centavos, evento, disponibilidade e atraso registrado em segundos |
| Linhagem | `orf_raw_ingest`, `orf_source_health` | SHA-256, contagens, última tentativa, sucesso e próximo polling |
| Eventos e vintages | `orf_observation` | `kind`, `subject`, data de referência, instante disponível, instante recebido e conteúdo versionado |
| Features e previsões | `orf_feature_snapshot`, `orf_prediction`, `orf_model_version`, `orf_promotion` | Snapshot por data/cutoff e hash, probabilidade e retorno em fração |
| Seleção | `orf_universe_snapshot`, `orf_universe_member` | `as_of`, `available_at`, `effective_at`, versão, rank e motivos |
| Temporada e virtual | `orf_season`, `orf_account`, `orf_decision`, `orf_order`, `orf_fill` | Cada estratégia tem conta e caixa próprios, preço em centavos e quantidade inteira |
| Livro e patrimônio | `orf_ledger_entry`, `orf_equity_snapshot` | Saldos em centavos. Payable, receivable e reserva são componentes separados |
| Pesquisa e status | `orf_research_run`, `orf_job_run`, `orf_alert`, `orf_admin_audit` | Relatórios por hash e registros temporais de execução |
| Controles | `orf_rate_bucket`, `orf_schema_migration` | Janelas de autenticação e checksums de DDL |

Formato para importar observações manualmente em CSV UTF-8 com cabeçalho `kind,subject,ref_date,available_at,source,payload`. A coluna `payload` contém um objeto JSON.

- `BENCHMARK`, sujeito `IBOV`, com `{"value":123456}`, em pontos.
- `MACRO`, sujeito `CDI`, com `{"value":0.05,"unit":"PERCENT_DAY"}`, valores em percentual diário.
- `NEWS`, sujeito do ativo ou emissor, com texto, identificação da publicação e link oficial conforme origem.
- `CORPORATE_ACTION`, sujeito igual ao SHA-256 `event_key`, intervalo econômico definido na data `ref_date`. Conteúdo obrigatório com `instrument_id` e `type`. Para `DIVIDEND`, inclua `amount_cents` por papel e `payment_date`. Para `SPLIT`, inclua `numerator` e `denominator`.
- `ACTION_COVERAGE`, sujeito igual ao ID numérico do instrumento, `{"start":"2026-01-02","verified":true}`. A data de referência define a última sessão coberta. A instituição que fornece o arquivo deve conferir todos os eventos no intervalo.

Nas demonstrações CVM, `subject` identifica CVM, formulário, demonstrativo e conta contábil. O payload retém versão, escala original, período e ID do documento. A disponibilidade conservadora é a meia-noite local seguinte à data de protocolo. No SGS sem vintage, a disponibilidade é o recebimento da consulta.

`orf_identity_evidence` stores auditable research mappings from ticker and ISIN to an internal instrument with effective dates, verification availability, source reference, and SHA-256 of the authorized evidence file. For fixed-basket symbols, `orf_fixed_watchlist` keeps the operational identity link; the verification command updates both stores in one transaction. Exports omit evidence unavailable at their as-of cutoff.
