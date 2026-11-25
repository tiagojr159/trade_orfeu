<?php
if (!defined('Orfeu\\ROOT')) {
    http_response_code(404);
    exit;
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ORFEU · Monitor de ações</title>
  <link rel="stylesheet" href="assets/orfeu.css">
  <script src="assets/orfeu.js" defer></script>
</head>
<body>
  <aside class="sidebar">
    <a class="brand" href="#visao"><span class="brand-mark" aria-hidden="true">◇</span><span>ORFEU<small>Pesquisa quantitativa</small></span></a>
    <nav aria-label="Seções">
      <a href="#visao">Visão geral</a>
      <a href="#universo">Universo B3</a>
      <a href="#torneio">Torneio</a>
      <a href="#matriz">Matriz de decisões</a>
      <a href="#negocios">Ordens e negócios</a>
      <a href="#laboratorio">Laboratório</a>
      <a href="#fontes">Monitoramento</a>
      <a href="#logs">Logs</a>
    </nav>
    <div class="sidebar-foot"><span class="dot"></span> Negociação virtual</div>
  </aside>
  <main>
    <header class="top"><span>B3 / Mercado à vista</span><span id="market-status" class="tag">Verificando sessão</span></header>
    <section id="visao">
      <div class="intro"><div><p class="eyebrow">INTELIGÊNCIA DE MERCADO</p><h1>Visão geral</h1></div><p id="as-of" class="muted"></p></div>
      <p id="notice" class="notice" role="status">Consultando o banco local.</p>
      <div class="stats">
        <div class="stat"><span>Patrimônio virtual</span><strong id="equity">—</strong><small id="capital">Capital inicial · —</small></div>
        <div class="stat"><span>Resultado acumulado</span><strong id="pnl">—</strong><small id="return">Retorno · —</small></div>
        <div class="stat"><span>Contas independentes</span><strong id="accounts">—</strong><small id="fills">Execuções hoje · —</small></div>
        <div class="stat"><span>CDI diário</span><strong id="cdi">—</strong><small id="ibov">Ibovespa · —</small></div>
      </div>
      <div class="panel curve"><div class="panel-title"><h2>Evolução patrimonial</h2><label>Estratégia <select id="account-picker"><option value="">Selecione</option></select></label></div>
      <canvas id="chart" role="img" aria-label="Curva patrimonial da estratégia selecionada"></canvas><p id="chart-status" class="muted"></p></div>
    </section>
    <section id="universo" class="panel"><div class="panel-title"><h2>Universo B3</h2><span id="universe-status" class="tag">Aguardando dados</span></div>
      <div class="table-wrap"><table><thead><tr><th>Ação</th><th>Empresa</th><th>Preço</th><th>Horário da cotação</th><th>Prob. de lucro</th><th>Estado</th></tr></thead><tbody id="markets"></tbody></table></div>
      <div id="instrument-detail" class="details" hidden></div>
    </section>
    <section id="torneio" class="panel"><div class="panel-title"><h2>Torneio de estratégias</h2><span class="muted" id="season"></span></div>
      <div class="table-wrap"><table><thead><tr><th>Estratégia</th><th>Patrimônio</th><th>Caixa disponível</th><th>Realizado</th><th>Não realizado</th><th>Taxas</th></tr></thead><tbody id="strategy-rows"></tbody></table></div>
    </section>
    <section id="matriz" class="panel"><div class="panel-title"><h2>Matriz de decisões</h2></div><div id="matrix-data" class="details"></div></section>
    <section id="negocios" class="panel"><div class="panel-title"><h2>Ordens e negócios</h2><button type="button" id="export-trades">Exportar página CSV</button></div><div id="trade-data" class="details"></div></section>
    <section id="laboratorio" class="panel"><div class="panel-title"><h2>Laboratório</h2></div><div id="research-data" class="details"></div></section>
    <section id="fontes" class="panel"><div class="panel-title"><h2>Monitoramento</h2></div><div id="health-data" class="details"></div></section>
    <section id="logs" class="panel"><div class="panel-title"><h2>Logs de execução</h2><span class="muted">Atualiza junto com o painel</span></div>
      <div class="table-wrap"><table><thead><tr><th>Horário</th><th>Origem</th><th>Estado</th><th>Detalhe</th></tr></thead><tbody id="log-rows"></tbody></table></div>
      <p id="logs-empty" class="muted" hidden>Nenhum evento de coleta ou execução registrado.</p>
    </section>
  </main>
</body>
</html>
