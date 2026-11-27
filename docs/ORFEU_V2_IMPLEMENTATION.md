# ORFEU V2

## Entrega verificada em 25/11/2026

| Fase | Resultado |
| --- | --- |
| P0-A | Lista fixa, migration 006, seed idempotente, vínculo cadastral explícito com referência e painel com as dez ações. A homologação cadastral ainda depende de evidência. |
| P0-B | Importação COTAHIST e calendário existentes. O diagnóstico encontrou zero barras, 365 datas de calendário e um ZIP ITR no inbox. Calendário histórico, sincronização cadastral e histórico de preços real ainda pendentes. |
| P0-C | Seis estratégias determinísticas podem gerar entradas experimentais sem modelo. Pesquisa pode selecionar explicitamente um artefato 5D ainda sem promoção. Conta shadow prospectiva, baselines para os demais robôs e validação real ainda pendentes. |
| P0-D | Fixtures existentes passaram com compra, taxa, venda, prazo de liquidação e cálculo de resultado em centavos. Regressão nova testa sinal sem modelo e expiração de compra fora da lista. Replay novo com mercado real indisponível. |
| P1 | Motor EOD existente, com ordens elegíveis na abertura da sessão do calendário e registro após disponibilidade da barra oficial. Permanecem pendentes o motor intraday, recuperação por fases, as cinco regras dependentes de novas fontes, LightGBM operacional e avaliação de previsões maduras. |
| P2 | Painel mostra lista fixa, obrigatoriedade, estado, cotação e atraso. Empacotador por lista permitida. Factory, meta-learning e validação prospectiva não entregues. |

## Banco e fontes

PHP CLI 7.4.33, MariaDB 10.4.27. O banco local tem 29 tabelas anteriores à migration 006, 15 contas reconciliadas, 365 datas de calendário, três cotações registradas e zero barras EOD, previsões, ordens e execuções. A lista V2 tem dez vínculos cadastrais por verificar. Os dados V1 e o patrimônio das contas foram preservados. Nenhum retorno por estratégia, retorno da cesta, comparação de rentabilidade, resultado fora da amostra ou treino real foi demonstrado.

O token brapi não está configurado. A cobertura histórica depende do plano, e os campos de preços brutos precisam ser distinguidos dos ajustados. Para COTAHIST, fornecer arquivos obtidos pelo operador ou por canal licenciado. A B3 veda coleta automatizada nessa página. [Documentação histórica da brapi](https://brapi.dev/docs/acoes/historico) · [Cotações históricas da B3](https://www.b3.com.br/pt_br/market-data-e-indices/servicos-de-dados/market-data/historico/mercado-a-vista/cotacoes-historicas/)

## Arquivos

- `config/fixed-universe.php`, `database/migrations/006_fixed_watchlist.sql`, `app/Store.php` definem a lista, persistência e verificação cadastral.
- `app/Pipeline.php`, `app/Trading.php`, `app/Replay.php` aplicam a lista ao torneio, avaliam regras experimentais e permitem replay explícito de candidato 5D quando o artefato estava disponível no recorte histórico.
- `app/Http.php`, `public/assets/orfeu.js` exibem a lista fixa com estados independentes de cotações.
- `bin/orfeu.php`, `bin/install-hostgator.php`, `public/index.php` expõem diagnóstico, seed, vínculo e inicialização para banco vazio.
- `bin/package-hostgator.php` produz `storage/orfeu-hostgator.zip`. O manifesto do ZIP foi inspecionado e exclui credenciais, histórico, perfis de navegador e ambiente Python.
- `tests/run.php` inclui duas regressões novas.

## Operação

Em instalação existente, faça o backup autorizado antes de aplicar a migration aditiva e troque os arquivos da aplicação na mesma janela. Na pasta privada, execute com o PHP CLI do ambiente:

```sh
php bin/orfeu.php doctor
php bin/orfeu.php migrate --dry-run
php bin/orfeu.php migrate
php bin/orfeu.php universe:seed-fixed --dry-run
php bin/orfeu.php universe:seed-fixed
php bin/orfeu.php reconcile
```

Após conferir a identidade em fonte oficial, importe o catálogo e registre ticker, ISIN, vigência, referência e hash do arquivo autorizado com `identity:verify`; o comando também registra o vínculo da cesta fixa quando aplicável. O cadastro não promove o ativo a ACTIVE. Importe calendário, cobertura de eventos e COTAHIST pelos comandos do [guia de operação](OPERATIONS.md). Os baselines exigem identidade verificada, situação ACTIVE, barras contíguas, liquidez, preço mínimo, cobertura de eventos e amplitude maior que o custo estimado. As regras de força relativa e regime dependem também de benchmark. O PHP processa as execuções EOD com o preço de abertura da sessão elegível quando os dados oficiais são importados.

Para desfazer a atualização, pause a Cron, restaure a versão anterior do PHP e confira `doctor` e `reconcile`. A migration é aditiva, portanto o rollback do código pode preservar a tabela nova. Preserve as operações já contabilizadas.

## Verificações

- `C:/xampp/php/php.exe tests/run.php` passou em 24 de 24 casos.
- `PYTHONPATH=python python -m unittest discover -s tests/python` passou em 5 de 5, usando o Python de `.venv`.
- PHP `-l`, `node --check public/assets/orfeu.js` e `git diff --check` passaram.
- `doctor`, `tick --dry-run`, `migrate --dry-run`, `universe:seed-fixed --dry-run` e `reconcile` foram executados. A migration 006 permanece pendente no banco original. Não houve teste de aplicação da migration em um schema isolado.

## Parte 2/4 — histórico e replay EOD

- `calendar:sync --file=... --from=... --to=... --dry-run` valida um CSV do operador com todos os dias civis da janela, incluindo feriados e fins de semana. Após revisão, retire `--dry-run` para gravar. Datas ausentes ou conflitantes são recusadas.
- `data:backfill --file=... --from=... --dry-run` valida a estrutura e a contagem do COTAHIST e informa quantos registros encontram sessão e identidade no catálogo. A importação real grava em uma transação, de modo que uma falha no trailer não deixa barras parciais. Os contadores distinguem barras com publicação documentada das conhecidas apenas no recebimento. [Layout oficial COTAHIST](https://www.b3.com.br/data/files/33/67/B9/50/D84057102C784E47AC094EA8/SeriesHistoricas_Layout.pdf)
- `data:coverage --from=2026-11-01 --to=2026-11-24` mostra lacunas de calendário e cobertura dos mercados padrão e fracionário por ação. O diagnóstico funciona antes da migration 006 e marca vínculos cadastrados mas ainda não verificados.
- `backtest --fixed-rules --from=2026-11-03 --to=2026-11-24 --cost-multiplier=2` usa a lista fixa, identidades conhecidas no momento da decisão e seis regras experimentais, sem modelo. O custo de stress entra na decisão e no preenchimento. Períodos anteriores à seleção da lista são marcados como retrospectivos. A disponibilidade usada é a registrada. A hipótese `RECONSTRUCTED_EOD` permanece pendente.

Resultado observado no recorte local: 15 pregões concluídos, zero barras da lista e nenhum vínculo verificado. O replay retornou `BLOCKED_NO_POINT_IN_TIME_EOD`. O inbox continha um ITR da CVM, sem arquivo histórico de preços. A execução real depende de COTAHIST autorizado, calendário, catálogo e, para o replay histórico, evidência de disponibilidade. O banco e as 15 contas foram preservados.

Passaram 25 testes PHP e 5 Python. Com arquivos sintéticos em modo de teste, o CLI aceitou um calendário completo e recusou uma lacuna e um COTAHIST truncado. O pacote da HostGator foi atualizado e suas 30 entradas foram inspecionadas.

## Parte 3/4 — previsões e avaliação

- A migration 007 acrescenta `orf_prediction_evaluation`. O avaliador PHP usa a abertura no mercado fracionário, a primeira barra disponível por pregão e cobertura documentada de eventos. Um evento societário na janela torna o resultado não observável sob esta política. Uma avaliação final é imutável. A métrica financeira usa o custo do modelo e não representa lucro de carteira.
- Após o backup e as migrations 006 e 007, `php bin/orfeu.php predictions:evaluate --limit=100` processa um lote. O comando `tick` da Cron também avalia previsões, sem alteração do agendamento. O endpoint `/api/v1/research/prediction-metrics` apresenta tamanho da amostra, Brier, log loss, acerto direcional, erro absoluto comparado à previsão zero e cobertura do intervalo por modelo e horizonte. O detalhe do ativo mostra o estado da avaliação.
- O treinador Python local exporta o candidato LightGBM em formato nativo, com calibração separada para alta e lucro, quantis e manifesto de versão. A inferência em lote valida o SHA-256 do modelo e dos snapshots de exportação. A hospedagem continua usando PHP; o candidato LightGBM fica em pesquisa local. A importação assinada de previsões externas e a promoção do candidato permanecem pendentes. [Serialização nativa do LightGBM](https://lightgbm.readthedocs.io/en/stable/pythonapi/lightgbm.Booster.html)

```powershell
$env:PYTHONPATH = 'python'
& .venv/Scripts/python.exe -m pip install -e '.[challenger]'
& .venv/Scripts/python.exe -m orfeu_quant.train --file <EXPORTACAO_JSONL> --holdout <DATA> --challenger
& .venv/Scripts/python.exe -m orfeu_quant.predict --model <ARTEFATO_JSON> --sha256 <SHA256> --features <EXPORTACAO_JSONL> --as-of <CORTE_UTC>
```

A exportação local retornou `NO_MATURE_LABELS`, com zero amostras. Nenhum desempenho fora da amostra, modelo de mercado treinado ou ganho financeiro foi demonstrado. O teste de integração do avaliador usou tabelas temporárias do MariaDB e verificou maturação, reprocessamento e métricas. As 15 contas reconciliaram. A migration 007 foi inspecionada em `--dry-run` e não aplicada ao banco original. O pacote PHP atualizado contém 32 entradas.

Passaram 26 testes PHP, a integração MariaDB e 6 testes Python, incluindo a validação do hash de snapshots no CLI. O teste de paridade nativa do LightGBM foi pulado porque o ambiente local não tinha o runtime e a instalação das dependências não concluiu.

## Parte 4/4 — fábrica de estratégias

- `python/orfeu_quant/factory.py` faz busca determinística e limitada para Momentum e Reversão à Média. Registra todos os testes executados, inclusive negativos e falhas, com parâmetros, hash do código, hash dos dados usados por janela e semente. O ranking exige 40 operações encerradas no conjunto de desenvolvimento, cobertura de pelo menos 95% dos pregões por janela, marcações sem atraso e CDI completo. A comparação usa a regra original da família e o CDI no mesmo período.
- `app/StrategyTrial.php` delimita os parâmetros aceitos. `php bin/orfeu.php research:trial` chama o simulador PHP em uma transação de leitura. `app/Replay.php`, `app/Trading.php` e `app/Store.php` reutilizam o livro de ordens, liquidação, custos e reconciliação. Os testes de fábrica usam carteiras em memória independentes por janela e não alteram o torneio.
- O desenvolvimento reserva a janela de validação sem reproduzi-la. A validação exige o SHA-256 do candidato congelado, roda com custo normal e dobrado e registra os resultados com criação exclusiva no diretório do artefato. Uma aprovação apenas indica revisão para observação prospectiva. A promoção existente segue sob controle administrativo.
- Os resumos JSON da fábrica podem ser importados pelo comando `research:import --file=... --sha256=...` e aparecem na aba Pesquisa. O pacote HostGator inclui o simulador e continua destinado ao PHP. A busca Python roda na estação de pesquisa.

Exemplo de desenvolvimento com dois períodos e uma janela de validação separada:

```powershell
$env:PYTHONPATH = 'python'
& .venv/Scripts/python.exe -m orfeu_quant.factory search --php=C:/xampp/php/php.exe --fold=2026-10-01:2026-10-30 --fold=2026-11-03:2026-11-13 --holdout=2026-11-16:2026-11-24 --as-of=2026-11-25T04:00:00Z --budget=1 --output=storage/research/factory-v2-4
```

Com um resumo no estado `FROZEN`, use `factory validate --frozen <development.json> --sha256 <HASH> --as-of <CORTE_UTC> --php <PHP_CLI>`. Use um novo diretório para uma nova busca. Preserve os logs do desenvolvimento e da validação juntos.

A busca real registrou duas tentativas em duas janelas, todas bloqueadas por `BLOCKED_NO_POINT_IN_TIME_EOD`. O resumo ficou em `NO_CANDIDATE`. A validação reservada não foi executada. O teste de integração sintético teve operações encerradas, livro reconciliado e retorno menor com custo dobrado, usando os mesmos dados. Passaram 26 testes PHP, duas integrações MariaDB com tabelas temporárias e 9 testes Python. O teste nativo do LightGBM continuou indisponível. As 15 contas originais reconciliaram sem divergências.

O aceite integral da V2 depende de histórico de mercado autorizado, cadastro, calendário e cobertura de eventos. Continuam pendentes `RECONSTRUCTED_EOD`, execução intraday, observação prospectiva em contas separadas, meta-learning, validação nativa do LightGBM, as regras dependentes de novas fontes e o comparativo buy-and-hold executável da cesta. A fábrica informa ajuste de múltiplos testes como indisponível. Nenhuma rentabilidade foi validada. As migrations 006 e 007 continuam pendentes no banco original.

## Incremento adicional — disponibilidade histórica e estratégias com evidência

### Reconstrução para pesquisa

A migration 008 acrescenta `orf_reconstructed_eod`, com uma hipótese versionada por instrumento, sessão e mercado. O importador exige um arquivo diário, SHA-256, sessão e referência ao fornecedor ou arquivo primário. Confirma que todos os registros pertencem à sessão declarada e recusa revisões concorrentes para a mesma série. A política presume visibilidade cinco minutos antes da abertura do pregão seguinte. Mantém `available_at` e `received_at` originais. Os relatórios indicam a política e a quantidade de linhas reconstruídas nas sessões do replay.

```sh
php bin/orfeu.php data:backfill --file=storage/inbox/ARQUIVO_DIARIO.zip --reconstruct-session=AAAA-MM-DD --archive-sha256=HASH --evidence-ref=REFERENCIA --dry-run
php bin/orfeu.php backtest --fixed-rules --reconstructed-eod --from=AAAA-MM-DD --to=AAAA-MM-DD
```

Após o backup e a migration 008, retire `--dry-run` do comando de importação para registrar a hipótese. O replay usa a disponibilidade registrada de eventos, informações cadastrais, proventos e índices. A hipótese só é usada em replay de regras fixas e não serve como evidência de promoção. Foi testada no MariaDB 10.4. O caminho de pesquisa usa funções de janela, disponíveis também no MySQL 8. [MariaDB ROW_NUMBER](https://mariadb.com/docs/server/reference/sql-functions/special-functions/window-functions/row_number) · [MySQL 8](https://dev.mysql.com/doc/refman/8.0/en/window-functions-usage.html)

### Regras EOD e referência da cesta

- `EARNINGS_DRIFT` usa surpresa de consenso anterior à divulgação, quando fornecida. Sem consenso, avalia a reação ao resultado como `POST_EARNINGS_PRICE_DRIFT`. Para importar, use `EARNINGS_RELEASE` com ID do instrumento em `subject`, data local da divulgação em `ref_date`, e `released_at`, `evidence_ref` no JSON. Os campos opcionais de consenso são `consensus_at`, `actual_eps` e `consensus_eps`.
- `DIVIDEND_QUALITY` usa `DIVIDEND_PROFILE` com `period_start`, `payout_ratio`, `free_cash_flow_cents`, `net_distributions_cents` e `evidence_ref`. Exige pagamentos documentados no período e tendência positiva.
- `MACRO_ROTATION` estima uma sensibilidade simples ao dólar com dados defasados, apenas quando há pares históricos com disponibilidade comprovada antes da sessão correspondente. `data:bcb --series=1 --dry-run` valida a fonte sem persistir. A importação grava o recebimento real, sem atribuir publicação histórica. [Banco Central, série SGS 1](https://dadosabertos.bcb.gov.br/dataset/1-taxa-de-cambio---livre---dolar-americano-venda---diario)
- `META_CHAMPION` usa votação experimental de regras, sem probabilidade calibrada. O payload de decisões identifica a variante usada. O modelo de meta-learning permanece pendente.
- O replay da lista fixa calcula o retorno teórico de uma cesta equiponderada entre o primeiro e o último fechamento. Exige as dez identidades verificadas, barras para todas as sessões, histórico de eventos coberto e ausência de eventos no período. O valor fica indisponível sem esses dados. A referência teórica não representa uma carteira negociável com lotes e custos.

### Resultado

Passaram 27 testes PHP, duas integrações MariaDB e 9 testes Python. A integração com tabelas temporárias comprovou importação idempotente, preservação dos timestamps e isolamento da hipótese. A série de câmbio retornou duas observações reais em uma consulta sem gravação. As 15 contas reconciliaram sem divergências. O banco original segue com as migrations 006 a 008 pendentes e sem histórico B3 suficiente. Intraday, contas de observação prospectiva e validação nativa do LightGBM permanecem pendentes.

## Incremento adicional — recuperação EOD, replay intraday e risco

### Cron e recuperação

A migration 009 cria `orf_job_checkpoint`. A Cron continua usando `php bin/orfeu.php tick`. Cada chamada processa até cinco sessões em ordem cronológica. O lock do banco protege o processamento, os checkpoints registram tentativas e o estado por conta impede repetir decisões, execuções e lançamentos. A coleta de cotações e a leitura de arquivos diários ficam fora do lock do torneio. Arquivos inválidos recebem um erro e os demais podem continuar.

Quando a próxima abertura já ocorreu, o processamento registra a lacuna. Só recupera execuções de ordens já existentes quando há barra oficial do mercado correto com disponibilidade registrada antes da abertura seguinte. O registro mostra separadamente o horário da execução simulada, o horário em que foi processada e o hash da barra. Sem essa evidência, a ordem expira. O preço de abertura obtido de OHLC é explicitamente marcado como aproximação. O painel mostra os checkpoints na área de saúde.

Cinco erros consecutivos deixam a sessão aguardando revisão. Dados ainda ausentes seguem em espera e não contam como falha de processamento. Após corrigir a causa, use `php bin/orfeu.php jobs:retry-eod --session=AAAA-MM-DD`.

### Intraday e custos

`research:intraday` reproduz uma sessão concluída e um ativo verificado por vez. Exige barras completas de 5 ou 15 minutos do mercado fracionário, fonte autorizada, SHA-256 e referência de evidência. A simulação usa o VWAP calculado com o financeiro e o volume negociado. Uma decisão só pode usar a abertura de uma barra posterior à disponibilidade da barra de sinal. A posição tenta sair antes do fim do pregão contínuo. Se não houver preço admissível, o resultado indica posição aberta. A ordem e o preenchimento são simulados. A abertura da barra é uma aproximação, com limites de caixa, lote, tick e volume já conhecido.

O CSV usa o símbolo fracionário e o ISIN do catálogo. Os preços e o financeiro são em centavos. Os timestamps são UTC e registram a disponibilidade na fonte e a recepção real no coletor. O replay recusa atrasos superiores a 120 segundos e sessões incompletas.

```text
symbol,isin,market,provider,event_start,event_end,available_at,received_at,open_px,high_px,low_px,close_px,volume,turnover_cents
```

```sh
php bin/orfeu.php research:intraday --file=storage/inbox/BARRAS.csv --symbol=PETR4 --session=AAAA-MM-DD --minutes=5 --sha256=HASH --evidence-ref=REFERENCIA
```

`app/ExecutionCost.php` concentra o cálculo em inteiros de preço adverso, arredondamento ao tick, tarifa e quantidade compatível com a reserva. `PaperAccount` prioriza saídas, limita novas entradas pela exposição e risco total e reduz o tamanho em volatilidade alta. Uma elevação do stop usa somente marcação conhecida e é registrada no livro. Esses limites são hipóteses do simulador, não garantias de perda máxima.

### Verificação

Passaram 30 testes PHP, duas integrações MariaDB e 9 testes Python. O teste nativo do LightGBM continuou indisponível. As fixtures comprovaram recuperação cronológica limitada, ausência de ordens retroativas, preenchimento parcial tardio, expiração sem evidência e nova tentativa explícita após falhas repetidas. O teste intraday confirmou entrada e saída dentro do pregão em aberturas posteriores ao sinal. As 15 contas existentes reconciliaram sem divergências.

O diagnóstico do banco original confirmou zero barras EOD, zero ordens e zero execuções. As migrations 006 a 009 foram inspecionadas em modo de teste e continuam pendentes de aplicação após backup. Permanecem pendentes uma fonte intraday integrada à Cron, o replay com dados reais, as contas prospectivas separadas, meta-learning e a validação nativa do LightGBM.
