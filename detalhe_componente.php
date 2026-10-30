<?php
require_once 'conexao.php';
require_once 'mrp_calculo.php';
require_once 'substituicoes.php';

require_once 'auth.php';
exigirLogin();
function h(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function numeroBr($valor, int $decimais = 2): string
{
    return number_format((float) $valor, $decimais, ',', '.');
}

$codigo = trim($_GET['codigo'] ?? '');

// Lista de componentes da BOM (MRP ≠ N) pra sugerir no campo de busca
$componentesLista = [];
$resLista = mysqli_query($conn, "
    SELECT TRIM(codigo_componente) AS codigo,
           COALESCE(MAX(CASE WHEN mrp IS NULL OR UPPER(TRIM(mrp)) <> 'N' THEN NULLIF(TRIM(descricao), '') END), MAX(NULLIF(TRIM(descricao), ''))) AS descricao
    FROM bomnova
    WHERE codigo_componente IS NOT NULL AND TRIM(codigo_componente) <> ''
    GROUP BY TRIM(codigo_componente)
    ORDER BY codigo
");
if ($resLista) {
    while ($l = mysqli_fetch_assoc($resLista)) {
        $componentesLista[$l['codigo']] = (string) ($l['descricao'] ?? '');
    }
}

if ($codigo === '') {
    // Sem componente informado: mostra só o campo de busca
    ?><!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Evolução do estoque</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/dashboard.css" rel="stylesheet"></head><body>
    <main class="container py-5" style="max-width:640px">
        <h1 class="h4 mb-3">Evolução do estoque</h1>
        <form method="GET" class="d-flex gap-2">
            <input class="form-control" name="codigo" list="lista-componentes-detalhe" placeholder="Digite o código do componente" autofocus required>
            <button class="btn btn-primary" type="submit">Ver</button>
        </form>
        <datalist id="lista-componentes-detalhe">
            <?php foreach ($componentesLista as $cod => $desc): ?><option value="<?php echo h($cod); ?>"><?php echo h($desc); ?></option><?php endforeach; ?>
        </datalist>
        <p class="mt-3"><a href="index.php">← Voltar ao Dashboard</a></p>
    </main></body></html><?php
    exit;
}

$hoje = new DateTimeImmutable('today');
// Horizonte rolante (hoje + 180 dias) — antes era uma data fixa (31/03/2027), que ia
// encolhendo conforme o tempo passava até sumir. Rolante mantém sempre a mesma "distância"
// pra frente, e fica entre o Dashboard (90 dias) e o Planejamento/MRP (12 meses).
$dataLimite = $hoje->modify('+180 days');

// Substituição de código (coluna "Substitui" da BOM)
$subs = carregarSubstituicoes($conn);
$substituidoPor = codigoSubstitutoFinal($subs, $codigo);       // este código é antigo → foi trocado por...
$codigosAntigos = $substituidoPor === null ? codigosAntigosDe($subs, $codigo) : []; // este é o novo → consome...
$codigosGrupo = array_merge([$codigo], $codigosAntigos);
$estoquePorAntigo = [];

$erroDetalhe = null;
$infoComponente = ['descricao' => '', 'fornecedores' => '', 'projetos' => ''];
$estoqueAtual = 0.0;
$programacaoPorData = [];      // só linhas LIBERADAS — usada na tabela de saldo da tela
$programacaoTodasPorData = []; // liberadas + bloqueadas — usada na simulação de compra (igual ao Planejamento)
$entradasBloqueadas = 0.0;
$demandaPorData = [];
$parametrosCompra = null;
$parcelasSugeridas = [];
$saldoComSugestaoPorData = [];
$segurancaQtd = 0.0;
$estoqueMinQtd = 0.0;
$estoqueMaxQtd = 0.0;

try {
    $stmtInfo = mysqli_prepare($conn, "
        SELECT
            MAX(COALESCE(NULLIF(TRIM(descricao), ''), 'Sem descrição')) AS descricao,
            GROUP_CONCAT(DISTINCT NULLIF(TRIM(fornecedor), '') ORDER BY TRIM(fornecedor) SEPARATOR ', ') AS fornecedores,
            GROUP_CONCAT(DISTINCT NULLIF(TRIM(projeto), '') ORDER BY TRIM(projeto) SEPARATOR ', ') AS projetos
        FROM bomnova
        WHERE TRIM(codigo_componente) = ? AND (mrp IS NULL OR UPPER(TRIM(mrp)) <> 'N')
    ");
    mysqli_stmt_bind_param($stmtInfo, 's', $codigo);
    mysqli_stmt_execute($stmtInfo);
    $resInfo = mysqli_stmt_get_result($stmtInfo);
    $linhaInfo = mysqli_fetch_assoc($resInfo);
    if ($linhaInfo) {
        $infoComponente = $linhaInfo;
    }
    mysqli_stmt_close($stmtInfo);

    // Com substituição, o estoque/programação/demanda dos códigos antigos entram
    // junto com os deste componente ($codigosGrupo = este + antigos).
    $phGrupo = implode(',', array_fill(0, count($codigosGrupo), '?'));
    $tiposGrupo = str_repeat('s', count($codigosGrupo));

    $stmtEstoque = mysqli_prepare($conn, "
        SELECT TRIM(codigo_componente) AS codigo, SUM(COALESCE(CAST(estoque AS DECIMAL(18,4)), 0)) AS estoque_atual
        FROM estoque
        WHERE TRIM(codigo_componente) IN ($phGrupo)
        GROUP BY TRIM(codigo_componente)
    ");
    mysqli_stmt_bind_param($stmtEstoque, $tiposGrupo, ...$codigosGrupo);
    mysqli_stmt_execute($stmtEstoque);
    $resEstoque = mysqli_stmt_get_result($stmtEstoque);
    while ($linhaEst = mysqli_fetch_assoc($resEstoque)) {
        $estoqueAtual += (float) $linhaEst['estoque_atual'];
        if ((string) $linhaEst['codigo'] !== $codigo) {
            $estoquePorAntigo[(string) $linhaEst['codigo']] = (float) $linhaEst['estoque_atual'];
        }
    }
    mysqli_stmt_close($stmtEstoque);

    // Programação de entradas futuras (chegadas previstas), somadas por data
    $stmtProg = mysqli_prepare($conn, "
        SELECT data,
               SUM(CASE WHEN COALESCE(bloqueado, 0) = 0 THEN quantidade ELSE 0 END) AS quantidade,
               SUM(quantidade) AS quantidade_todas
        FROM programacao
        WHERE TRIM(codigo_componente) IN ($phGrupo)
          AND (atendido = 0 OR atendido IS NULL)
        GROUP BY data
    ");
    mysqli_stmt_bind_param($stmtProg, $tiposGrupo, ...$codigosGrupo);
    mysqli_stmt_execute($stmtProg);
    $resProg = mysqli_stmt_get_result($stmtProg);
    while ($linha = mysqli_fetch_assoc($resProg)) {
        $programacaoPorData[$linha['data']] = (float) $linha['quantidade'];
        $programacaoTodasPorData[$linha['data']] = (float) $linha['quantidade_todas'];
        $entradasBloqueadas += (float) $linha['quantidade_todas'] - (float) $linha['quantidade'];
    }
    mysqli_stmt_close($stmtProg);

    // Demanda do EDI (quantidade EDI × consumo da BOM), na data de início de cada semana
    $stmtDemanda = mysqli_prepare($conn, "
        SELECT e.data_inicio AS data,
               SUM(
                   COALESCE(CAST(e.quantidade AS DECIMAL(18,4)), 0)
                   * COALESCE(CAST(NULLIF(REPLACE(TRIM(b.consumo), ',', '.'), '') AS DECIMAL(18,6)), 0)
               ) AS quantidade
        FROM bomnova b
        JOIN edi e ON TRIM(b.material) = TRIM(e.material)
        WHERE TRIM(b.codigo_componente) IN ($phGrupo) AND (b.mrp IS NULL OR UPPER(TRIM(b.mrp)) <> 'N')
          AND (b.planejamento IS NULL OR UPPER(TRIM(b.planejamento)) <> 'N')
          AND (e.atendido = 0 OR e.atendido IS NULL)
        GROUP BY e.data_inicio
    ");
    mysqli_stmt_bind_param($stmtDemanda, $tiposGrupo, ...$codigosGrupo);
    mysqli_stmt_execute($stmtDemanda);
    $resDemanda = mysqli_stmt_get_result($stmtDemanda);
    while ($linha = mysqli_fetch_assoc($resDemanda)) {
        $demandaPorData[$linha['data']] = (float) $linha['quantidade'];
    }
    mysqli_stmt_close($stmtDemanda);

    // Parâmetros de compra do componente (MOQ, Lead Time, Estoque Mínimo/Máximo, setup) —
    // usados pra simular "o que aconteceria se você comprasse agora", igual ao Planejamento
    // de Compras, mas sem precisar registrar nada na tabela "programacao". Só entra em ação
    // se os 5 parâmetros obrigatórios estiverem preenchidos (mesma regra do Planejamento).
    $stmtParam = mysqli_prepare($conn, "
        SELECT moq, frozen_zone_dias, transit_time_dias, estoque_min_dias, estoque_max_dias, setup, congelamento_dias
        FROM parametros_compra
        WHERE TRIM(codigo_componente) = ?
          AND moq IS NOT NULL AND frozen_zone_dias IS NOT NULL AND transit_time_dias IS NOT NULL
          AND estoque_min_dias IS NOT NULL AND estoque_max_dias IS NOT NULL
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmtParam, 's', $codigo);
    mysqli_stmt_execute($stmtParam);
    $resParam = mysqli_stmt_get_result($stmtParam);
    $linhaParam = mysqli_fetch_assoc($resParam);
    if ($linhaParam) {
        $parametrosCompra = $linhaParam;
    }
    mysqli_stmt_close($stmtParam);
} catch (Throwable $erro) {
    error_log('Erro no detalhe do componente: ' . $erro->getMessage());
    $erroDetalhe = 'Não foi possível carregar os dados deste componente. Verifique se a tabela "programacao" já foi criada (veja sql_programacao.sql).';
}

// Frozen Zone (período de congelamento do pedido): entregas com data até
// hoje + N dias já não podem ser alteradas. Não entra no cálculo da compra —
// só pinta a linha "Entrada programada": amarelo = congelado, laranja = ainda
// dá pra alterar, branco = sem pedido.
$congelamentoDias = ($parametrosCompra !== null && $parametrosCompra['congelamento_dias'] !== null)
    ? (int) $parametrosCompra['congelamento_dias'] : null;
$limiteFrozen = $congelamentoDias !== null ? $hoje->modify('+' . $congelamentoDias . ' days') : null;

// Monta a projeção dia a dia: saldo(dia) = saldo(dia anterior) + entrada do dia - saída do dia
// O ponto de partida (hoje) usa o estoque físico atual como saldo "anterior", já somando
// movimentos com data anterior a hoje (atrasados), que senão ficariam fora do intervalo exibido.
$dias = [];
if ($erroDetalhe === null) {
    $hojeChave = $hoje->format('Y-m-d');
    $entradaAtrasada = 0.0;
    foreach ($programacaoPorData as $dataMov => $qtd) {
        if ($dataMov < $hojeChave) {
            $entradaAtrasada += $qtd;
        }
    }
    $saidaAtrasada = 0.0;
    foreach ($demandaPorData as $dataMov => $qtd) {
        if ($dataMov < $hojeChave) {
            $saidaAtrasada += $qtd;
        }
    }

    $saldoAnterior = $estoqueAtual + $entradaAtrasada - $saidaAtrasada;
    $cursor = $hoje;
    while ($cursor <= $dataLimite) {
        $chave = $cursor->format('Y-m-d');
        $entrada = $programacaoPorData[$chave] ?? 0.0;
        $saida = $demandaPorData[$chave] ?? 0.0;
        $saldo = $saldoAnterior + $entrada - $saida;

        $dias[] = [
            'data' => $cursor,
            'entrada' => $entrada,
            'saida' => $saida,
            'saldo' => $saldo,
        ];

        $saldoAnterior = $saldo;
        $cursor = $cursor->modify('+1 day');
    }

    // Prévia "e se eu comprasse agora": roda o MESMO cálculo do Planejamento de Compras
    // (calcularParcelasCompraPlanejamento, em mrp_calculo.php), mas só pra este componente
    // e sem gravar nada — é uma simulação, não uma programação real. O horizonte usa 12
    // meses rolantes (igual ao Planejamento) pra achar a real data de necessidade, mesmo
    // que a tabela exibida (Evolução do Estoque) use um horizonte mais curto ($dataLimite,
    // hoje + 180 dias rolantes).
    if ($parametrosCompra !== null && $substituidoPor === null) {
        $setupComp = $parametrosCompra['setup'] !== null ? (float) $parametrosCompra['setup'] : 0.0;
        $segurancaQtd = calcularEstoqueSegurancaQtd(
            $demandaPorData,
            $hoje,
            (int) $parametrosCompra['frozen_zone_dias'],
            (int) $parametrosCompra['transit_time_dias'],
            $setupComp
        );

        // Estoque Mínimo/Máximo convertidos de "dias" pra quantidade, pra mostrar na tela —
        // mesma conta que o Planejamento de Compras faz internamente (soma a demanda dos
        // próximos N dias a partir de hoje), só que fixada em "hoje" em vez de recalculada
        // dia a dia dentro da simulação. É um retrato do "quanto isso representa agora",
        // não o valor exato usado em cada dia da simulação (que muda com a demanda).
        $hojeChaveInfo = $hoje->format('Y-m-d');
        $fimJanelaMinInfo = $hoje->modify('+' . (int) $parametrosCompra['estoque_min_dias'] . ' days')->format('Y-m-d');
        foreach ($demandaPorData as $dataMov => $qtd) {
            if ($dataMov >= $hojeChaveInfo && $dataMov < $fimJanelaMinInfo) {
                $estoqueMinQtd += $qtd;
            }
        }
        $fimJanelaMaxInfo = $hoje->modify('+' . (int) $parametrosCompra['estoque_max_dias'] . ' days')->format('Y-m-d');
        foreach ($demandaPorData as $dataMov => $qtd) {
            if ($dataMov >= $hojeChaveInfo && $dataMov < $fimJanelaMaxInfo) {
                $estoqueMaxQtd += $qtd;
            }
        }

        $horizontePreview = $hoje->modify('+12 months');
        $resultadoPreview = calcularParcelasCompraPlanejamento(
            $estoqueAtual,
            $programacaoTodasPorData, // bloqueadas entram: o Planejamento/MRP não muda com o bloqueio
            $demandaPorData,
            $hoje,
            $horizontePreview,
            (float) $parametrosCompra['moq'],
            (int) $parametrosCompra['frozen_zone_dias'],
            (int) $parametrosCompra['transit_time_dias'],
            (int) $parametrosCompra['estoque_min_dias'],
            (int) $parametrosCompra['estoque_max_dias'],
            $setupComp,
            $segurancaQtd
        );
        $parcelasSugeridas = $resultadoPreview['parcelas'];
        $saldoComSugestaoPorData = $resultadoPreview['saldo_por_dia'];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Evolução do estoque | <?php echo h($codigo); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/dashboard.css" rel="stylesheet">
    <style>
        .evolucao-table { border-collapse: separate; border-spacing: 0; font-size: .8rem; }
        .evolucao-table th, .evolucao-table td {
            padding: 8px 10px;
            text-align: right;
            white-space: nowrap;
            border-bottom: 1px solid #e9eef3;
            border-right: 1px solid #f0f3f6;
        }
        .evolucao-table thead th {
            position: sticky;
            background: #f5f8fb;
            z-index: 2;
        }
        .evolucao-table thead tr:nth-child(1) th { top: 0; height: 16px; padding: 2px 10px; }
        .evolucao-table thead tr:nth-child(2) th { top: 18px; }
        .evolucao-table thead tr:nth-child(3) th { top: 53px; }
        .evolucao-table th:first-child,
        .evolucao-table td:first-child {
            position: sticky;
            left: 0;
            z-index: 3;
            text-align: left;
            background: #f5f8fb;
            min-width: 190px;
            box-shadow: 2px 0 0 #dce4ec;
        }
        .evolucao-table thead th:first-child { z-index: 4; }
        .col-evento { background: #eaf8ee; }
        .col-hoje { background: #fff7c4 !important; }
        .linha-saldo td { font-weight: 750; }
        .saldo-negativo { color: #c53535; }
        .valor-vazio { color: #b7c1cb; }
        .dot-edi {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #2f9e44;
            margin-right: 3px;
            vertical-align: middle;
        }
        .scroll-wrapper { max-height: 70vh; overflow: auto; border: 1px solid #dce4ec; border-radius: 12px; }
        .linha-sugestao td { font-weight: 750; color: #1c6e2d; border-top: 2px dashed #2f9e44; }
        .linha-sugestao td.saldo-negativo { color: #c53535; }
        .col-compra-sugerida { background: #e4f7e9 !important; }
        /* Frozen Zone na linha "Entrada programada" (vence o amarelo-claro de hoje / verde de EDI) */
        .evolucao-table td.entrada-congelada { background: #ffe600 !important; font-weight: 750; color: #3d3500; }
        .evolucao-table td.entrada-alteravel { background: #ffb366 !important; font-weight: 750; color: #4a2600; }
        .legenda-frozen span { display: inline-block; width: 14px; height: 14px; border-radius: 3px; vertical-align: middle; margin: 0 4px 0 12px; }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="container-fluid dashboard-container d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="eyebrow">Supply Chain • Planejamento de materiais</span>
                <h1>Evolução do estoque</h1>
                <p class="mb-0">Saldo projetado dia a dia até <?php echo h($dataLimite->format('d/m/Y')); ?></p>
            </div>
            <nav class="d-flex flex-wrap gap-2" aria-label="Ações do sistema">
                <a class="btn btn-light btn-sm" href="index.php">🏠 Dashboard</a>
                <a class="btn btn-outline-light btn-sm" href="estoque.php">Estoque</a>
                <a class="btn btn-outline-light btn-sm" href="edi.php">EDI</a>
                <a class="btn btn-outline-light btn-sm" href="bomnova.php">BOM</a>
                <a class="btn btn-outline-light btn-sm" href="programacao.php">Programação</a>
                <a class="btn btn-outline-light btn-sm" href="parametros_compra.php">Parâmetros</a>
                <a class="btn btn-outline-light btn-sm" href="evolucao_geral.php">Evolução geral</a>
                <a class="btn btn-outline-light btn-sm" href="planejamento_compras.php">Planejamento de compras</a>
                <a class="btn btn-outline-light btn-sm" href="pedido_compra.php">📄 Pedido de Compra</a>
            </nav>
        </div>
    </header>

    <main class="container-fluid dashboard-container py-4">
        <?php if ($erroDetalhe !== null): ?>
            <div class="alert alert-danger" role="alert"><?php echo h($erroDetalhe); ?></div>
        <?php else: ?>

        <section class="filter-panel mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <span class="eyebrow text-primary">Componente</span>
                    <h2 class="mb-2"><?php echo h($codigo); ?></h2>
                </div>
                <form method="GET" class="d-flex gap-2" style="min-width:320px">
                    <input class="form-control form-control-sm" name="codigo" list="lista-componentes-detalhe" placeholder="Ver outro componente..." autocomplete="off" required>
                    <button class="btn btn-primary btn-sm" type="submit">Ver</button>
                </form>
                <datalist id="lista-componentes-detalhe">
                    <?php foreach ($componentesLista as $cod => $desc): ?><option value="<?php echo h($cod); ?>"><?php echo h($desc); ?></option><?php endforeach; ?>
                </datalist>
            </div>
            <p class="mb-1"><strong>Descrição:</strong> <?php echo h($infoComponente['descricao'] ?: '—'); ?></p>
            <p class="mb-1"><strong>Fornecedor(es):</strong> <?php echo h($infoComponente['fornecedores'] ?: 'Não informado'); ?></p>
            <p class="mb-1"><strong>Projeto(s):</strong> <?php echo h($infoComponente['projetos'] ?: '—'); ?></p>
            <p class="mb-0"><strong>Estoque físico atual (hoje):</strong> <?php echo numeroBr($estoqueAtual); ?><?php if (!empty($codigosAntigos)): ?> <small class="text-muted">(inclui <?php echo h(implode(', ', $codigosAntigos)); ?>)</small><?php endif; ?></p>
        </section>

        <?php if ($entradasBloqueadas > 0): ?>
            <div class="alert alert-danger" role="alert">
                ⛔ <strong><?php echo numeroBr($entradasBloqueadas, 0); ?></strong> peça(s) de entradas programadas estão <strong>Bloqueadas</strong> na <a href="programacao.php?busca=<?php echo urlencode($codigo); ?>">Programação</a> e não entram no saldo abaixo.
                A compra sugerida (mesma lógica do Planejamento) continua considerando essas chegadas.
            </div>
        <?php endif; ?>

        <?php if ($substituidoPor !== null): ?>
            <div class="alert alert-warning" role="alert">
                🔁 Este código foi <strong>substituído por <a href="detalhe_componente.php?codigo=<?php echo urlencode($substituidoPor); ?>"><?php echo h($substituidoPor); ?></a></strong> na BOM.
                Ele não gera sugestão de compra — o saldo dele é consumido primeiro pelo <?php echo h($substituidoPor); ?>.
            </div>
        <?php elseif (!empty($codigosAntigos)): ?>
            <div class="alert alert-warning" role="alert">
                🔁 Substitui
                <?php foreach ($codigosAntigos as $i => $antigo): ?><?php echo $i > 0 ? ', ' : ''; ?><a href="detalhe_componente.php?codigo=<?php echo urlencode($antigo); ?>"><strong><?php echo h($antigo); ?></strong></a> (estoque <?php echo numeroBr($estoquePorAntigo[$antigo] ?? 0, 0); ?>)<?php endforeach; ?>.
                O estoque, as entradas programadas e a demanda do código antigo já estão somados abaixo — a compra do <?php echo h($codigo); ?> só aparece depois que esse saldo for consumido.
            </div>
        <?php endif; ?>

        <?php if ($substituidoPor !== null): ?>
        <?php elseif ($parametrosCompra === null): ?>
            <div class="alert alert-secondary" role="alert">
                Este componente não tem os 5 parâmetros de compra preenchidos (MOQ, Lead Time, Transit Time,
                Estoque Mínimo e Estoque Máximo) em <a href="parametros_compra.php">Parâmetros de Compra</a>,
                então não é possível simular o efeito de uma compra sugerida aqui.
            </div>
        <?php else: ?>
            <section class="metrics-grid mb-4" aria-label="Parâmetros de compra e estoque">
                <article class="metric-card metric-neutral">
                    <span class="metric-label">Estoque atual</span>
                    <strong><?php echo numeroBr($estoqueAtual, 0); ?></strong>
                    <small>Físico, hoje</small>
                </article>
                <article class="metric-card metric-info">
                    <span class="metric-label">Estoque mínimo</span>
                    <strong><?php echo numeroBr($estoqueMinQtd, 0); ?></strong>
                    <small><?php echo (int) $parametrosCompra['estoque_min_dias']; ?> dias de demanda</small>
                </article>
                <article class="metric-card metric-warning">
                    <span class="metric-label">Estoque de segurança</span>
                    <strong><?php echo numeroBr($segurancaQtd, 0); ?></strong>
                    <small>Calculado automaticamente</small>
                </article>
                <article class="metric-card metric-excesso">
                    <span class="metric-label">Estoque máximo</span>
                    <strong><?php echo numeroBr($estoqueMaxQtd, 0); ?></strong>
                    <small><?php echo (int) $parametrosCompra['estoque_max_dias']; ?> dias de demanda</small>
                </article>
                <article class="metric-card metric-neutral">
                    <span class="metric-label">Frozen Zone</span>
                    <strong><?php echo $parametrosCompra['congelamento_dias'] !== null ? (int) $parametrosCompra['congelamento_dias'] : '—'; ?></strong>
                    <small><?php echo $congelamentoDias !== null ? 'dias • alterar só entregas após ' . h($limiteFrozen->format('d/m/Y')) : 'não cadastrado em Parâmetros'; ?></small>
                </article>
                <article class="metric-card metric-neutral">
                    <span class="metric-label">Lead Time total</span>
                    <strong><?php echo (int) $parametrosCompra['frozen_zone_dias'] + (int) $parametrosCompra['transit_time_dias']; ?></strong>
                    <small>Lead Time (<?php echo (int) $parametrosCompra['frozen_zone_dias']; ?>) + Transit Time (<?php echo (int) $parametrosCompra['transit_time_dias']; ?>)</small>
                </article>
            </section>

        <?php if (empty($parcelasSugeridas)): ?>
            <div class="alert alert-success" role="alert">
                Com a programação já colocada, nenhuma compra nova é necessária nos próximos 12 meses — o saldo
                não fica abaixo do Estoque Mínimo + Segurança em nenhum momento.
            </div>
        <?php else: ?>
            <section class="table-card mb-4">
                <div class="table-toolbar">
                    <div>
                        <span class="eyebrow text-primary">Simulação</span>
                        <h2>Compra sugerida (prévia, ainda não registrada)</h2>
                        <p>Mesma lógica do <a href="planejamento_compras.php">Planejamento de Compras</a> — a linha
                            tracejada verde na tabela abaixo mostra o efeito dessa compra no saldo, sem precisar
                            registrar nada em Programação.</p>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Data sugerida do pedido</th>
                                <th>Data de necessidade (recebimento)</th>
                                <th>Quantidade</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($parcelasSugeridas as $p): ?>
                                <tr>
                                    <td>
                                        <?php if ($p['status'] === 'urgente'): ?>
                                            <span class="badge bg-danger">Urgente</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Programada</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo h($p['data']->format('d/m/Y')); ?></td>
                                    <td><?php echo h($p['data_necessidade']->format('d/m/Y')); ?></td>
                                    <td><?php echo numeroBr($p['quantidade'], 0); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
        <?php endif; ?>

        <section class="table-card">
            <div class="table-toolbar">
                <div>
                    <span class="eyebrow text-primary">Projeção</span>
                    <h2>Saldo dia a dia</h2>
                    <p class="legenda-frozen">De <?php echo h($hoje->format('d/m/Y')); ?> até <?php echo h($dataLimite->format('d/m/Y')); ?> • coluna amarela-clara = hoje
                        <?php if ($limiteFrozen !== null): ?>
                            • Entrada programada:
                            <span style="background:#ffe600"></span>congelada (Frozen Zone até <?php echo h($limiteFrozen->format('d/m/Y')); ?>)
                            <span style="background:#ffb366"></span>pode alterar
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="scroll-wrapper">
                <table class="evolucao-table mb-0">
                    <thead>
                        <tr>
                            <th></th>
                            <?php foreach ($dias as $dia): ?>
                                <th class="linha-marcador <?php echo $dia['data']->format('Y-m-d') === $hoje->format('Y-m-d') ? 'col-hoje' : ''; ?>">
                                    <?php if ($dia['saida'] > 0): ?><span class="dot-edi" title="Tem demanda EDI nesta data"></span><?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <th>Semana</th>
                            <?php foreach ($dias as $dia): ?>
                                <?php
                                    $classesCab = [];
                                    if ($dia['saida'] > 0) { $classesCab[] = 'col-evento'; }
                                    if ($dia['data']->format('Y-m-d') === $hoje->format('Y-m-d')) { $classesCab[] = 'col-hoje'; }
                                ?>
                                <th class="<?php echo h(implode(' ', $classesCab)); ?>">
                                    <?php echo $dia['data']->format('W'); ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <th>Data</th>
                            <?php foreach ($dias as $dia): ?>
                                <?php
                                    $classesCab = [];
                                    if ($dia['saida'] > 0) { $classesCab[] = 'col-evento'; }
                                    if ($dia['data']->format('Y-m-d') === $hoje->format('Y-m-d')) { $classesCab[] = 'col-hoje'; }
                                ?>
                                <th class="<?php echo h(implode(' ', $classesCab)); ?>">
                                    <?php echo $dia['data']->format('d/m/Y'); ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Entrada programada</td>
                            <?php foreach ($dias as $dia): ?>
                                <?php
                                    $classesCel = [];
                                    $tituloEntrada = '';
                                    if ($dia['saida'] > 0) { $classesCel[] = 'col-evento'; }
                                    if ($dia['data']->format('Y-m-d') === $hoje->format('Y-m-d')) { $classesCel[] = 'col-hoje'; }
                                    if ($dia['entrada'] != 0 && $limiteFrozen !== null) {
                                        if ($dia['data'] <= $limiteFrozen) {
                                            $classesCel[] = 'entrada-congelada';
                                            $tituloEntrada = 'Dentro do Frozen Zone — pedido congelado, não pode mais ser alterado';
                                        } else {
                                            $classesCel[] = 'entrada-alteravel';
                                            $tituloEntrada = 'Fora do Frozen Zone — dá pra alterar até ' . $dia['data']->modify('-' . $congelamentoDias . ' days')->format('d/m/Y');
                                        }
                                    }
                                ?>
                                <td class="<?php echo h(implode(' ', $classesCel)); ?>"<?php if ($tituloEntrada !== ''): ?> title="<?php echo h($tituloEntrada); ?>"<?php endif; ?>>
                                    <?php echo $dia['entrada'] != 0 ? numeroBr($dia['entrada'], 0) : '<span class="valor-vazio">—</span>'; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <td>Saída (demanda EDI)</td>
                            <?php foreach ($dias as $dia): ?>
                                <?php
                                    $classesCel = [];
                                    if ($dia['saida'] > 0) { $classesCel[] = 'col-evento'; }
                                    if ($dia['data']->format('Y-m-d') === $hoje->format('Y-m-d')) { $classesCel[] = 'col-hoje'; }
                                ?>
                                <td class="<?php echo h(implode(' ', $classesCel)); ?>">
                                    <?php echo $dia['saida'] > 0 ? numeroBr($dia['saida'], 0) : '<span class="valor-vazio">—</span>'; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <tr class="linha-saldo">
                            <td>Saldo projetado</td>
                            <?php foreach ($dias as $dia): ?>
                                <?php
                                    $classes = [];
                                    if ($dia['saida'] > 0) {
                                        $classes[] = 'col-evento';
                                    }
                                    if ($dia['data']->format('Y-m-d') === $hoje->format('Y-m-d')) {
                                        $classes[] = 'col-hoje';
                                    }
                                    if ($dia['saldo'] < 0) {
                                        $classes[] = 'saldo-negativo';
                                    }
                                ?>
                                <td class="<?php echo h(implode(' ', $classes)); ?>"><?php echo numeroBr($dia['saldo'], 0); ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php if (!empty($parcelasSugeridas)): ?>
                        <?php
                            // Datas em que uma parcela sugerida ficaria disponível (usadas só
                            // pra destacar a coluna correspondente nesta linha extra).
                            $datasDisponibilidade = [];
                            foreach ($parcelasSugeridas as $p) {
                                $datasDisponibilidade[$p['data_disponibilidade']->format('Y-m-d')] = true;
                            }
                        ?>
                        <tr class="linha-sugestao" title="Saldo simulado se a(s) compra(s) sugerida(s) acima fossem colocadas agora">
                            <td>Saldo com compra sugerida</td>
                            <?php foreach ($dias as $dia): ?>
                                <?php
                                    $chaveDia = $dia['data']->format('Y-m-d');
                                    $classes = [];
                                    if ($dia['saida'] > 0) {
                                        $classes[] = 'col-evento';
                                    }
                                    if ($chaveDia === $hoje->format('Y-m-d')) {
                                        $classes[] = 'col-hoje';
                                    }
                                    if (isset($datasDisponibilidade[$chaveDia])) {
                                        $classes[] = 'col-compra-sugerida';
                                    }
                                    $saldoSugerido = $saldoComSugestaoPorData[$chaveDia] ?? $dia['saldo'];
                                    if ($saldoSugerido < 0) {
                                        $classes[] = 'saldo-negativo';
                                    }
                                ?>
                                <td class="<?php echo h(implode(' ', $classes)); ?>"><?php echo numeroBr($saldoSugerido, 0); ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php endif; ?>
    </main>
</body>
</html>
