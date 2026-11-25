# Protocolo de pesquisa

Defina universo histórico, custo, calendário, horizonte e data do holdout antes de rodar. O padrão de ranking é 5D. O baseline linear estima probabilidade de alta, probabilidade de lucro após custo e retorno bruto. A calibração logística, os quantis residuais e a incerteza por blocos de sessões vêm de um bloco separado. O challenger LightGBM é opcional na estação de treino.

1. Importe histórico com evidência temporal, histórico de elegibilidade, benchmark B3, CDI e cobertura de eventos corporativos por instrumento. Reconcile eventos e use o arquivo correto para o primeiro timestamp disponível.
2. Gere as features históricas com `research:features --from=AAAA-MM-DD --to=AAAA-MM-DD` e exporte com `research:export --from=AAAA-MM-DD`. O Python seleciona apenas ações elegíveis no instante da decisão. O label usa a abertura oficial 020 do próximo pregão e a abertura H sessões adiante. Exige todas as sessões e cobertura de eventos. Períodos afetados por eventos são excluídos no label bruto V1.
3. Instale as dependências localmente. No PowerShell, crie o ambiente com `python -m venv .venv` e instale com `& .venv/Scripts/python.exe -m pip install -e '.[challenger,test]'`.
4. Rode o treino local abaixo. Os limites de amostra são requisitos técnicos iniciais do protocolo, e calibração e treino são purgados pela maturidade de seus labels. Não ajuste o processo com base no resultado do holdout. Quando os dados não passam pelos limites, o comando retorna `BLOCKED_INSUFFICIENT_DATA`.
5. Confira o SHA-256 e carregue os artefatos. Copie arquivos para uma pasta privada se for hospedar o dashboard longe do laboratório. O registro usa JSON de vetores e recebe o estado `SHADOW`. As rotinas web e o Cron PHP não executam Python.

```powershell
& .venv/Scripts/python.exe -m orfeu_quant.train --file=storage/exports/<hash>.jsonl --horizon=5D --holdout=AAAA-MM-DD --challenger
& $php bin/orfeu.php model:import --file=storage/models/<arquivo>.model.json --sha256=<sha256>
& $php bin/orfeu.php research:import --file=storage/models/<arquivo>.research.json --sha256=<sha256>
& $php bin/orfeu.php backtest --from=AAAA-MM-DD --to=AAAA-MM-DD --cost-multiplier=2
```

O replay usa a mesma execução diária, custos e contabilidade que o torneio, sem escrever na temporada. Use custos 1x, 2x e 3x para estresse. O relatório traz retorno composto, drawdown, cobertura, taxas, fills e benchmarks quando houver dados comparáveis. Investigue semelhança com os baselines de frequência, momentum e média histórica. O comparativo Ibovespa no Replay é fechamento a fechamento e deve ser interpretado com a cobertura informada.

A promoção manual exige relatório revisado `ORFEU_PROMOTION_V1` associado ao hash do modelo e do dataset, sem vazamento temporal, universo histórico, livro reconciliado e blocos OOS e prospectivo. Esse documento deve ser montado a partir de avaliações reais. O gate verifica janela, cobertura, amostra, comparativos com baseline e CDI, drawdown e custos 2x. Ele não certifica a autenticidade de métricas fornecidas por um operador. Execute `model:promote --model=<sha256> --evidence=<sha256>` apenas após a revisão. O instante da promoção é append-only.

Ficam pendentes ciclos completos de walk-forward, ablation, calibração prospectiva, análise de IC e drift, estimação de confiança de desempenho do portfólio, retorno total causal com proventos, fábrica DSL e meta-label. A seleção inicial segue como seed até que exista evidência de mercado.
