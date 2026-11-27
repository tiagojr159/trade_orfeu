# Protocolo de pesquisa

Defina universo histórico, custo, calendário, horizonte e data do holdout antes de rodar. O padrão de ranking é 5D. O baseline linear estima probabilidade de alta, probabilidade de lucro após custo e retorno bruto. A calibração logística, os quantis residuais e a incerteza por blocos de sessões vêm de um bloco separado. O challenger LightGBM é opcional na estação de treino.

1. Importe histórico com evidência temporal, histórico de elegibilidade, benchmark B3, CDI e cobertura de eventos corporativos por instrumento. Reconcile eventos e use o arquivo correto para o primeiro timestamp disponível.
2. Gere as features históricas com `research:features --from=AAAA-MM-DD --to=AAAA-MM-DD` e exporte com `research:export --from=AAAA-MM-DD`. O Python seleciona apenas ações elegíveis no instante da decisão. O label usa a abertura oficial 020 do próximo pregão e a abertura H sessões adiante. Exige todas as sessões e cobertura de eventos. Períodos afetados por eventos são excluídos no label bruto V1.
3. Instale as dependências localmente. No PowerShell, crie o ambiente com `python -m venv .venv` e instale com `& .venv/Scripts/python.exe -m pip install -e '.[challenger,test]'`.
4. Rode o treino local abaixo. Os limites de amostra são requisitos técnicos iniciais do protocolo, e calibração e treino são purgados pela maturidade de seus labels. Não ajuste o processo com base no resultado do holdout. Quando os dados não passam pelos limites, o comando retorna `BLOCKED_INSUFFICIENT_DATA`.
5. Confira o SHA-256 e carregue os artefatos. Copie arquivos para uma pasta privada se for hospedar o dashboard longe do laboratório. O registro usa JSON de vetores e recebe o estado `SHADOW`. As rotinas web e o Cron PHP não executam Python.

```powershell
& .venv/Scripts/python.exe -m orfeu_quant.train --file=storage/exports/<hash>.jsonl --horizon=5D --holdout=AAAA-MM-DD --walk-forward-folds=3 --challenger
& $php bin/orfeu.php model:import --file=storage/models/<arquivo>.model.json --sha256=<sha256>
& $php bin/orfeu.php research:import --file=storage/models/<arquivo>.research.json --sha256=<sha256>
& $php bin/orfeu.php backtest --from=AAAA-MM-DD --to=AAAA-MM-DD --cost-multiplier=2
```

O replay usa a mesma execução diária, custos e contabilidade que o torneio, sem escrever na temporada. Use custos 1x, 2x e 3x para estresse. O relatório traz retorno composto, drawdown, cobertura, taxas, fills e benchmarks quando houver dados comparáveis. Investigue semelhança com os baselines de frequência, momentum e média histórica. O comparativo Ibovespa no Replay é fechamento a fechamento e deve ser interpretado com a cobertura informada.

A promoção manual exige relatório revisado `ORFEU_PROMOTION_V1` associado ao hash do modelo e do dataset, sem vazamento temporal, universo histórico, livro reconciliado e blocos OOS e prospectivo. Esse documento deve ser montado a partir de avaliações reais. O gate verifica janela, cobertura, amostra, comparativos com baseline e CDI, drawdown e custos 2x. Ele não certifica a autenticidade de métricas fornecidas por um operador. Execute `model:promote --model=<sha256> --evidence=<sha256>` apenas após a revisão. O instante da promoção é append-only.

O treinador suporta três ou mais folds walk-forward purgados, com janelas de teste anteriores e disjuntas em relação ao holdout final. Quando ativado, o LightGBM challenger é avaliado nas mesmas janelas do baseline linear. O artefato guarda métricas por fold e médias/desvios; isso descreve estabilidade histórica, sem substituir validação prospectiva. A execução em dados reais depende da importação B3 e da cobertura societária point-in-time.

O módulo `orfeu_quant.monitoring` calcula PSI/KS entre janelas de features e compara qualidade de previsão em labels maduros. Ainda não está ligado a janelas persistidas de produção. O treinador informa IC de Spearman por seção transversal diária, e permutação de features no OOS como diagnóstico não causal. `bootstrap_relative_performance` estima intervalos empíricos pareados por blocos para retorno ativo e retorno relativo ao benchmark.

O `orfeu_quant.meta_label` produz labels líquidos de sinais primários, purge por maturidade, calibração temporal, folds walk-forward OOF e holdout reservado. Faltam sinais reais e integração a um champion. `orfeu_quant.returns` calcula uma curva buy-and-hold com ações corporativas somente para preços brutos e cobertura completa; falta conectá-lo a séries B3, eventos e benchmarks reais. Ainda faltam ablação com retreino por feature e calibração prospectiva. Nenhuma dessas métricas foi calculada em séries de mercado reais; a seleção permanece como seed.

A busca também aceita uma especificação JSON `ORFEU_STRATEGY_SPEC_V1` com família, grades numéricas limitadas, folds de desenvolvimento, holdout, seed e orçamento. Ela não avalia expressões ou código fornecido pelo usuário. Execute com `python -m orfeu_quant.factory search --spec <arquivo.json> --output <diretório-novo> --as-of <timestamp>`. A especificação não remove a exigência de folds fora do tempo, volume de operações, reconciliação, custo de estresse e aprovação humana para qualquer etapa posterior.

O filtro de qualidade operacional usa margens trimestrais da DRE consolidada CVM (contas 3.01, 3.05 e 3.09), com o período e a disponibilidade preservados. Ele rejeita períodos acima de 180 dias e exige receita e resultado positivos para a conta especializada. Isso não é valuation, não é comparável entre setores por padrão e não é evidência suficiente para promover modelo ou seleção.
