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
      <a href="#previsoes">Previsões</a>
      <a href="#noticias">Notícias</a>
      <a href="#torneio">Torneio</a>
      <a href="#matriz">Matriz de decisões</a>
      <a href="#negocios">Ordens e negócios</a>
      <a href="#laboratorio">Laboratório</a>
      <a href="#fontes">Monitoramento</a>
      <a href="#logs">Logs</a>
      <a href="#quant">ORFEU Quant Intelligence</a>
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
    <section id="previsoes" class="panel"><div class="panel-title"><h2>Previsões de mercado</h2><span class="muted">Probabilidades do último modelo disponível</span></div>
      <p class="details">A probabilidade de queda é o complemento da probabilidade de alta. O resultado depende de histórico EOD e modelo disponível; não é garantia de retorno.</p>
      <div class="table-wrap"><table><thead><tr><th>Ação</th><th>Horizonte</th><th>Prob. de alta</th><th>Prob. de queda</th><th>Retorno bruto estimado</th><th>Modelo</th><th>Gerada em</th></tr></thead><tbody id="prediction-rows"></tbody></table></div>
      <p id="predictions-empty" class="details muted">Sem previsões registradas. São necessários histórico diário importado e modelo compatível.</p>
    </section>
    <section id="quant" class="panel"><div class="panel-title"><h2>ORFEU Quant Intelligence</h2><span id="quant-status" class="tag">Aguardando dados</span></div>
      <p id="quant-summary" class="details muted">O estado das estrat&#233;gias ser&#225; calculado pelo backend a partir dos dados dispon&#237;veis.</p>
      <div class="table-wrap"><table><thead><tr><th>Estrat&#233;gia</th><th>Estado dos dados</th><th>Modo</th><th>Requisito e restri&#231;&#227;o</th></tr></thead><tbody id="quant-rows"></tbody></table></div>
      <div class="panel-title"><h2>Cointegra&#231;&#227;o de pares</h2><span id="pair-status" class="muted">Sem relat&#243;rio</span></div>
      <p id="pairs-empty" class="details muted">Os testes aparecem ap&#243;s an&#225;lise de barras point-in-time e identidades verificadas. A lista &#233; anal&#237;tica; n&#227;o abre posi&#231;&#245;es vendidas.</p>
      <div class="table-wrap"><table><thead><tr><th>Par</th><th>Estado</th><th>Sess&#245;es</th><th>p-valor</th><th>q-valor FDR</th><th>Z-score din&#226;mico</th><th>Half-life</th></tr></thead><tbody id="pair-rows"></tbody></table></div>
      <div class="panel-title"><h2>Correlação dos retornos</h2><span id="correlation-status" class="muted">Aguardando dados</span></div>
      <p id="correlation-empty" class="details muted">A matriz exige histórico sincronizado e identidade verificada para os dez ativos. Usa retornos de fechamento bruto, sem ajuste por eventos societários; não habilita operações.</p>
      <div class="table-wrap"><table><thead id="correlation-head"></thead><tbody id="correlation-rows"></tbody></table></div>
      <div class="panel-title"><h2>Qualidade operacional parcial (CVM ITR)</h2><span id="quality-status" class="muted">Aguardando dados</span></div>
      <p class="details muted">Somente margens divulgadas e dispon&#237;veis no corte; n&#227;o representa ranking multifatorial nem altera a carteira fixa.</p>
      <div class="table-wrap"><table><thead><tr><th>A&#231;&#227;o do cat&#225;logo</th><th>Margem operacional</th><th>Margem l&#237;quida</th><th>Per&#237;odo</th><th>Dispon&#237;vel em</th></tr></thead><tbody id="quality-rows"></tbody></table></div>
    </section>
    <section id="noticias" class="panel"><div class="panel-title"><h2>Notícias da B3</h2><span id="news-status" class="muted">Carregando</span></div>
      <div id="news-data" class="news-list details"></div>
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
