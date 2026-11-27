# ORFEU

Plataforma de pesquisa e negociação virtual de ações ON e PN brasileiras. O backend PHP e o painel em português usam MySQL/MariaDB. O laboratório Python roda à parte e entrega artefatos JSON com SHA-256.

## Acesso local

[XAMPP local na porta 8090](http://localhost:8090/trade_orfeu/public/)

No diretório do projeto, execute os comandos abaixo para uma instalação nova. Configure o banco existente no arquivo local `.env` a partir de `.env.example`.

```powershell
$php = 'C:/xampp/php/php.exe'
& $php bin/orfeu.php doctor
& $php bin/orfeu.php migrate --dry-run
& $php bin/orfeu.php migrate
& $php bin/orfeu.php calendar:seed
& $php bin/orfeu.php universe:seed
& $php bin/orfeu.php season:create --name=season-001 --capital=1000
& $php bin/orfeu.php data:quotes --sandbox
& $php bin/orfeu.php tick --dry-run
& $php bin/orfeu.php reconcile
```

A instalação local desta entrega já tem a temporada `season-001` com 15 contas independentes. O catálogo inicial tem dez ações em acompanhamento. Histórico incompleto, dados vencidos e falta de evidência aparecem no painel e bloqueiam entradas.

## Hospedagem

O [guia de implantação na HostGator](docs/OPERATIONS.md#hostgator-cpanel) cobre o banco do cPanel, a separação entre pasta pública e privada e a execução do PHP pelo Cron. O treino pode rodar na máquina local. O servidor importa um modelo JSON e aplica a inferência em PHP.

## Documentação

- [Relatório de implementação](docs/IMPLEMENTATION_REPORT.md)
- [Auditoria e camada Quant Intelligence V3](docs/QUANT_INTELLIGENCE_V3.md)
- [Operação e instalação](docs/OPERATIONS.md)
- [Dicionário de dados](docs/DATA_DICTIONARY.md)
- [Protocolo de pesquisa](docs/RESEARCH_PROTOCOL.md)
- [Hipóteses de execução B3](docs/B3_EXECUTION_ASSUMPTIONS.md)
- [Segurança](docs/SECURITY.md)
