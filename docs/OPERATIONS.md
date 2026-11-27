# Operação e implantação

## HostGator cPanel

1. No cPanel, crie o banco com sufixo `_orfeu` e um usuário dedicado. Conceda ao usuário acesso ao schema criado. O nome completo em `DB_DATABASE` deve ser o que aparece no cPanel, por exemplo `conta_orfeu`. [Banco MySQL no cPanel](https://suporte.hostgator.com.br/hc/pt-br/articles/30813397059987-Como-criar-um-banco-de-dados-no-cPanel).
2. Selecione uma versão mantida no MultiPHP e confirme que a CLI do Cron usa a mesma versão. O mínimo técnico local é PHP 7.4. Em hospedagem use um PHP 8 mantido pelo provedor. Verifique `pdo_mysql`, `curl`, `openssl`, `zip` e `mbstring`. [MultiPHP na HostGator](https://suporte.hostgator.com.br/hc/pt-br/articles/30807998885395-Como-alterar-a-vers%C3%A3o-do-PHP-na-hospedagem).
3. Faça upload de `app/`, `bin/`, `config/`, `database/` e do conteúdo de `public/` mantendo as duas áreas descritas abaixo. Habilite arquivos ocultos no gerenciador do cPanel para incluir a regra `.htaccess` de `public/`. Ative HTTPS.
4. Na pasta privada, preencha `.env` usando `.env.hostgator.example`. Use `APP_ENV=hostgator`, `ALLOW_LOCAL_ROOT=false` e a senha dedicada. O nome vazio ou de outro projeto é recusado. Inclua credenciais e dados do provedor apenas nessa configuração privada.
5. No Terminal do cPanel ou SSH, execute `doctor`, `migrate --dry-run`, `migrate`, `calendar:seed`, `universe:seed`, `season:create --name=season-001 --capital=1000` e `reconcile` com o PHP CLI do plano. Verifique `/api/v1/health` no endereço implantado.
6. Configure a frequência autorizada no seu plano pelo painel Trabalhos Cron. O exemplo usa 10 minutos. Use o caminho real do PHP CLI e o diretório inicial informado pelo cPanel. [Trabalhos Cron](https://suporte.hostgator.com.br/hc/pt-br/articles/30810523318419-Como-gerenciar-um-trabalho-Cron) · [Caminhos Cron e PHP](https://suporte.hostgator.com.br/hc/pt-br/articles/30812660878867-Quais-s%C3%A3o-os-caminhos-para-a-execu%C3%A7%C3%A3o-de-comandos-em-um-Cron-Job).

Se puder apontar a raiz do site para `/home/conta/orfeu/public`, conserve a estrutura do repositório. Para publicar dentro de `public_html`, use o layout abaixo. Crie `private-root.php` na pasta pública com o conteúdo `<?php return '/home/conta/orfeu';`. Substitua o caminho pelo diretório privado real.

```text
/home/conta/orfeu/                   PHP interno, bin, config, database, storage e .env
/home/conta/public_html/orfeu/       Arquivos de public/, .htaccess e private-root.php
```

Exemplo de comando no Cron, com caminho de PHP ilustrativo.

```sh
ORFEU_PHP=/opt/cpanel/ea-php85/root/usr/bin/php /bin/sh /home/conta/orfeu/bin/tick-hostgator.sh
```

Desative `display_errors` na hospedagem. Os jobs gravam logs rotativos em `storage/logs`; os arquivos devem ser graváveis pelo usuário do PHP CLI. Backfills grandes, treino e exportações intensivas devem rodar fora de uma requisição web. A implantação remota exige acesso ao cPanel e confirmação dos limites do plano.

## Dados e calendário

O calendário incluído cobre 2026. Importe anos adicionais antes de voltar no histórico ou atravessar o fim do ano. O CSV aceita `session_date,open,continuous_end,close,settles,source`, com horários em São Paulo e uma linha por dia, incluindo feriados e fins de semana. Uma lacuna de calendário bloqueia a busca da próxima sessão.

```powershell
& $php bin/orfeu.php calendar:import --file=storage/inbox/calendario.csv
& $php bin/orfeu.php universe:import --file=storage/inbox/catalogo-b3.csv
& $php bin/orfeu.php data:backfill --file=storage/inbox/COTAHIST_A2026.ZIP --from=2026-01-01
& $php bin/orfeu.php data:cvm --file=storage/inbox/itr_cia_aberta_2026.zip
& $php bin/orfeu.php data:bcb --series=12 --from=2026-11-01 --to=2026-11-24
& $php bin/orfeu.php data:observations --file=storage/inbox/observacoes.csv
& $php bin/orfeu.php rank:refresh
& $php bin/orfeu.php tick --once
```

`universe:import` usa o cabeçalho de `config/seed-catalog.csv`. Valide fonte oficial, espécie ON/PN, emissor, lote, fracionário e vigência antes de marcar `ACTIVE`. Para replay inclua históricos de símbolos e de elegibilidade, IPOs e deslistadas. Um ZIP COTAHIST deve conter um arquivo `COTAHIST*.TXT` com preços brutos e fator unitário. A importação preserva o ISIN, resolve símbolo pelo catálogo e mantém os mercados 010 e 020 separados.

Para liberar pesquisa de um ticker, registre a fonte oficial que associa ticker, ISIN e instrumento. `identity:verify` calcula o SHA-256 do arquivo de evidência local, confere símbolo e ISIN contra o cadastro ORFEU e registra a vigência temporal. Para os dez ativos fixos, o mesmo comando também atualiza o vínculo da cesta. Use apenas arquivos obtidos com autorização; pesquisa de pares permanece bloqueada enquanto faltar essa evidência.

```powershell
& $php bin/orfeu.php identity:verify --dry-run --instrument=123 --symbol=PETR4 `
  --isin=BRPETRACNPR6 --valid-from=2020-01-01 --source=B3_ASSET_CATALOG `
  --evidence-file=storage/inbox/catalogo-b3.csv --evidence-ref="registro da fonte oficial"
```

Por padrão, a disponibilidade da barra é a data de ingestão. Para pesquisa histórica forneça `--availability-file=...` com colunas `session_date,published_at,archive_sha256,source_reference`. Cada linha precisa descrever a mesma versão do arquivo efetivamente publicada naquele horário. Preserve o documento de origem e a primeira publicação. Um arquivo anual obtido hoje pode conter correções e não comprova publicação histórica. Evidência de publicação deve acompanhar a primeira importação.

O importador CVM recebe ZIP ITR/DFP oficial e seleciona demonstrações consolidadas e contas fixas dos emissores com `cvm_code`. O importador SGS suporta 11, 12 e 433. A brapi usa v1 com Bearer opcional e guarda cotações com timestamp do fornecedor. O sandbox permite uma parte das ações. O usuário do token deve verificar plano e direitos de armazenamento e uso. [ITR CVM](https://dados.cvm.gov.br/dataset/cia_aberta-doc-itr) · [DFP CVM](https://dados.cvm.gov.br/dataset/cia_aberta-doc-dfp) · [Cotações históricas B3](https://www.b3.com.br/pt_br/market-data-e-indices/servicos-de-dados/market-data/historico/mercado-a-vista/cotacoes-historicas/) · [Autenticação e limites da brapi](https://brapi.dev/docs/authentication).

## Rotina local e rollback

No Agendador de Tarefas do Windows, execute `powershell.exe -NoProfile -File C:\xampp\htdocs\trade_orfeu\bin\tick-windows.ps1`. Defina intervalo conforme o plano do provedor. A coleta de cotações ocorre dentro da sessão contínua. Em cada execução fora do pregão, o tick verifica a pasta privada `storage/inbox/` e pode importar o próximo ZIP diário `COTAHIST_DDDMMAAAA.ZIP` ainda sem hash registrado. Deposite arquivos completos nesse diretório; use os comandos manuais para arquivos anuais, divulgações e reprocessamento. O relógio do servidor fica livre para uso do cPanel e o ORFEU converte tudo para UTC e São Paulo.

Antes de atualizar, faça backup do schema ORFEU no ambiente alvo e guarde a versão anterior do código. Para rollback de código, pare o agendamento, restaure a versão anterior e rode `doctor` e `reconcile`. As migrations são aditivas. Se o MySQL interromper DDL entre a criação e o checksum, a execução subsequente sinaliza colisão para inspeção. O saldo e os lançamentos existentes devem ser preservados.

## API e verificação

`GET /api/v1/dashboard` fornece fonte, sessão, carteira, 15 contas, preços observados e referência CDI. Outras rotas incluem `universe/current`, `universe/history`, `instruments/{id}`, `instruments/{id}/predictions`, `accounts`, `accounts/{id}/equity`, `orders`, `trades`, `matrix`, `research/runs`, `research/models` e `health`. Use `?page=1&limit=50` onde se aplica. Ordens e negócios aceitam `account`.
