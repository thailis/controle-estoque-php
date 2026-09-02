<?php
require_once 'conexao.php';

function h(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function numeroBr($valor, int $decimais = 0): string
{
    return number_format((float) $valor, $decimais, ',', '.');
}

function opcoesDistintasGeral(mysqli $conn, string $coluna): array
{
    $permitidas = ['fornecedor', 'projeto'];
    if (!in_array($coluna, $permitidas, true)) {
        return [];
    }

    $sql = "SELECT DISTINCT TRIM($coluna) AS valor
            FROM bomnova
            WHERE $coluna IS NOT NULL AND TRIM($coluna) <> ''
            ORDER BY valor";
    $resultado = mysqli_query($conn, $sql);
    $opcoes = [];
    while ($linha = mysqli_fetch_assoc($resultado)) {
        $opcoes[] = $linha['valor'];
    }
    return $opcoes;
}

function urlComGeral(array $alteracoes = []): string
{
    $parametros = $_GET;
    foreach ($alteracoes as $chave => $valor) {
        if ($valor === null || $valor === '') {
            unset($parametros[$chave]);
        } else {
            $parametros[$chave] = $valor;
        }
    }
    return '?' . http_build_query($parametros);
}

$busca = trim($_GET['busca'] ?? '');
$fornecedor = trim($_GET['fornecedor'] ?? '');
$projeto = trim($_GET['projeto'] ?? '');

$porPagina = (int) ($_GET['por_pagina'] ?? 20);
if (!in_array($porPagina, [10, 20, 50], true)) {
    $porPagina = 20;
}
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

$dataLimite = new DateTimeImmutable('2027-03-31');
$hoje = new DateTimeImmutable('today');

$erroGeral = null;
$componentes = [];
$totalComponentes = 0;
$fornecedores = [];
$projetos = [];
$dias = [];
$temEdiPorDia = [];
$demandaEdiBrutaPorDia = [];

try {
    $fornecedores = opcoesDistintasGeral($conn, 'fornecedor');
    $projetos = opcoesDistintasGeral($conn, 'projeto');

    $condicoes = ["b.codigo_componente IS NOT NULL", "TRIM(b.codigo_componente) <> ''", "(b.mrp IS NULL OR UPPER(TRIM(b.mrp)) <> 'N')"];
    $parametros = [];
    $tipos = '';

    if ($busca !== '') {
        $condicoes[] = '(TRIM(b.codigo_componente) LIKE ? OR b.descricao LIKE ? OR b.fornecedor LIKE ? OR TRIM(b.material) LIKE ?)';
        $termo = '%' . $busca . '%';
        array_push($parametros, $termo, $termo, $termo, $termo);
        $tipos .= 'ssss';
    }
    if ($fornecedor !== '') {
        $condicoes[] = 'TRIM(b.fornecedor) = ?';
        $parametros[] = $fornecedor;
        $tipos .= 's';
    }
    if ($projeto !== '') {
        $condicoes[] = 'TRIM(b.projeto) = ?';
        $parametros[] = $projeto;
        $tipos .= 's';
    }

    $sqlBase = "SELECT
                    TRIM(b.codigo_componente) AS codigo_componente,
                    MAX(COALESCE(NULLIF(TRIM(b.descricao), ''), 'Sem descrição')) AS descricao,
                    GROUP_CONCAT(DISTINCT NULLIF(TRIM(b.fornecedor), '') ORDER BY TRIM(b.fornecedor) SEPARATOR ', ') AS fornecedores,
                    GROUP_CONCAT(DISTINCT NULLIF(TRIM(b.projeto), '') ORDER BY TRIM(b.projeto) SEPARATOR ', ') AS projetos,
                    GROUP_CONCAT(DISTINCT NULLIF(TRIM(b.consumo), '') ORDER BY TRIM(b.consumo) SEPARATOR ', ') AS consumos,
                    COALESCE(MAX(est.estoque_atual), 0) AS estoque_atual
                FROM bomnova b
                LEFT JOIN (
                    SELECT TRIM(codigo_componente) AS codigo_componente,
                           SUM(COALESCE(CAST(estoque AS DECIMAL(18,4)), 0)) AS estoque_atual
                    FROM estoque
                    WHERE codigo_componente IS NOT NULL AND TRIM(codigo_componente) <> ''
                    GROUP BY TRIM(codigo_componente)
                ) est ON est.codigo_componente = TRIM(b.codigo_componente)
                WHERE " . implode(' AND ', $condicoes) . "
                GROUP BY TRIM(b.codigo_componente)
                ORDER BY TRIM(b.codigo_componente)";

    $stmtBase = mysqli_prepare($conn, $sqlBase);
    if ($tipos !== '') {
        mysqli_stmt_bind_param($stmtBase, $tipos, ...$parametros);
    }
    mysqli_stmt_execute($stmtBase);
    $resBase = mysqli_stmt_get_result($stmtBase);
    while ($linha = mysqli_fetch_assoc($resBase)) {
        $componentes[] = $linha;
    }
    mysqli_stmt_close($stmtBase);

    $totalComponentes = count($componentes);
    $totalPaginas = max(1, (int) ceil($totalComponentes / $porPagina));
    $pagina = min($pagina, $totalPaginas);
    $componentesPagina = array_slice($componentes, ($pagina - 1) * $porPagina, $porPagina);

    $exportando = ($_GET['exportar'] ?? '') === 'csv';
    // Na exportação, considera TODOS os componentes filtrados (ignora a paginação da tela).
    $componentesParaCalcular = $exportando ? $componentes : $componentesPagina;

    $codigosCalculo = array_column($componentesParaCalcular, 'codigo_componente');

    $programacaoPorComponente = [];
    $demandaPorComponente = [];
    $demandaEdiBrutaPorDia = [];

    if (!empty($codigosCalculo)) {
        $placeholders = implode(',', array_fill(0, count($codigosCalculo), '?'));
        $tiposCodigos = str_repeat('s', count($codigosCalculo));

        // Programação de entradas futuras dos componentes considerados
        $stmtProg = mysqli_prepare($conn, "
            SELECT TRIM(codigo_componente) AS codigo_componente, data, SUM(quantidade) AS quantidade
            FROM programacao
            WHERE TRIM(codigo_componente) IN ($placeholders)
              AND (atendido = 0 OR atendido IS NULL)
            GROUP BY TRIM(codigo_componente), data
        ");
        mysqli_stmt_bind_param($stmtProg, $tiposCodigos, ...$codigosCalculo);
        mysqli_stmt_execute($stmtProg);
        $resProg = mysqli_stmt_get_result($stmtProg);
        while ($linha = mysqli_fetch_assoc($resProg)) {
            $programacaoPorComponente[$linha['codigo_componente']][$linha['data']] = (float) $linha['quantidade'];
        }
        mysqli_stmt_close($stmtProg);

        // Demanda EDI (quantidade × consumo) dos componentes considerados, por data de início da semana
        $stmtDemanda = mysqli_prepare($conn, "
            SELECT TRIM(b.codigo_componente) AS codigo_componente, e.data_inicio AS data,
                   SUM(
                       COALESCE(CAST(e.quantidade AS DECIMAL(18,4)), 0)
                       * COALESCE(CAST(NULLIF(REPLACE(TRIM(b.consumo), ',', '.'), '') AS DECIMAL(18,6)), 0)
                   ) AS quantidade
            FROM bomnova b
            JOIN edi e ON TRIM(b.material) = TRIM(e.material)
            WHERE TRIM(b.codigo_componente) IN ($placeholders) AND (b.mrp IS NULL OR UPPER(TRIM(b.mrp)) <> 'N')
              AND (e.atendido = 0 OR e.atendido IS NULL)
            GROUP BY TRIM(b.codigo_componente), e.data_inicio
        ");
        mysqli_stmt_bind_param($stmtDemanda, $tiposCodigos, ...$codigosCalculo);
        mysqli_stmt_execute($stmtDemanda);
        $resDemanda = mysqli_stmt_get_result($stmtDemanda);
        while ($linha = mysqli_fetch_assoc($resDemanda)) {
            $demandaPorComponente[$linha['codigo_componente']][$linha['data']] = (float) $linha['quantidade'];
        }
        mysqli_stmt_close($stmtDemanda);

        // Quantidade BRUTA do EDI por data (sem multiplicar por consumo), só pra exibir
        // ao lado da bolinha de evento. Usa DISTINCT material+data+quantidade pra não
        // contar a mesma linha de EDI várias vezes quando vários componentes compartilham o material.
        $stmtDemandaBruta = mysqli_prepare($conn, "
            SELECT data, SUM(quantidade) AS quantidade
            FROM (
                SELECT DISTINCT e.material, e.data_inicio AS data, e.quantidade
                FROM bomnova b
                JOIN edi e ON TRIM(b.material) = TRIM(e.material)
                WHERE TRIM(b.codigo_componente) IN ($placeholders) AND (b.mrp IS NULL OR UPPER(TRIM(b.mrp)) <> 'N')
                  AND (e.atendido = 0 OR e.atendido IS NULL)
            ) AS materiais_distintos
            GROUP BY data
        ");
        mysqli_stmt_bind_param($stmtDemandaBruta, $tiposCodigos, ...$codigosCalculo);
        mysqli_stmt_execute($stmtDemandaBruta);
        $resDemandaBruta = mysqli_stmt_get_result($stmtDemandaBruta);
        $demandaEdiBrutaPorDia = [];
        while ($linha = mysqli_fetch_assoc($resDemandaBruta)) {
            $demandaEdiBrutaPorDia[$linha['data']] = (float) $linha['quantidade'];
        }
        mysqli_stmt_close($stmtDemandaBruta);
    }

    // Monta a lista de dias (mesma para todos os componentes) e o saldo projetado de cada um
    $cursor = $hoje;
    while ($cursor <= $dataLimite) {
        $dias[] = $cursor;
        $cursor = $cursor->modify('+1 day');
    }

    foreach ($componentesParaCalcular as &$componente) {
        $codigo = $componente['codigo_componente'];
        $progComponente = $programacaoPorComponente[$codigo] ?? [];
        $demandaComponente = $demandaPorComponente[$codigo] ?? [];

        // Movimentos com data anterior a hoje (atrasados) entram direto no saldo inicial,
        // em vez de serem ignorados por caírem fora do intervalo de colunas exibido.
        $hojeChave = $hoje->format('Y-m-d');
        $entradaAtrasada = 0.0;
        foreach ($progComponente as $dataMov => $qtd) {
            if ($dataMov < $hojeChave) {
                $entradaAtrasada += $qtd;
            }
        }
        $saidaAtrasada = 0.0;
        foreach ($demandaComponente as $dataMov => $qtd) {
            if ($dataMov < $hojeChave) {
                $saidaAtrasada += $qtd;
            }
        }

        $saldos = [];
        $saldoAnterior = (float) $componente['estoque_atual'] + $entradaAtrasada - $saidaAtrasada;
        foreach ($dias as $dia) {
            $chave = $dia->format('Y-m-d');
            $entrada = $progComponente[$chave] ?? 0.0;
            $saida = $demandaComponente[$chave] ?? 0.0;
            $saldo = $saldoAnterior + $entrada - $saida;
            $saldos[$chave] = $saldo;
            $saldoAnterior = $saldo;
        }
        $componente['saldos'] = $saldos;
    }
    unset($componente);

    // Marca os dias em que pelo menos um componente do conjunto calculado tem demanda EDI
    // (calculado antes da exportação para poder usar tanto no CSV quanto na tela)
    $temEdiPorDia = [];
    foreach ($dias as $dia) {
        $temEdiPorDia[$dia->format('Y-m-d')] = false;
    }
    foreach ($demandaPorComponente as $porData) {
        foreach ($porData as $data => $qtd) {
            if ($qtd > 0 && array_key_exists($data, $temEdiPorDia)) {
                $temEdiPorDia[$data] = true;
            }
        }
    }

    // Exportação CSV: gera o arquivo e encerra antes de renderizar HTML
    if ($exportando && $erroGeral === null) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="evolucao-estoque-' . date('Y-m-d-His') . '.csv"');
        echo "\xEF\xBB\xBF";
        $saida = fopen('php://output', 'w');

        // Linha 1: marcador (●) nos dias com demanda EDI
        $linhaMarcador = ['', '', '', '', '', ''];
        foreach ($dias as $dia) {
            $chave = $dia->format('Y-m-d');
            $linhaMarcador[] = $temEdiPorDia[$chave] ? '●' : '';
        }
        fputcsv($saida, $linhaMarcador, ';', '"', '');

        // Linha 2: número da semana
        $linhaSemana = ['', '', '', '', '', ''];
        foreach ($dias as $dia) {
            $linhaSemana[] = $dia->format('W');
        }
        fputcsv($saida, $linhaSemana, ';', '"', '');

        // Linha 3: cabeçalho com as datas
        $cabecalhoCsv = ['codigo_componente', 'descricao', 'fornecedores', 'projetos', 'consumo', 'estoque_hoje'];
        foreach ($dias as $dia) {
            $cabecalhoCsv[] = $dia->format('d/m/Y');
        }
        fputcsv($saida, $cabecalhoCsv, ';', '"', '');

        foreach ($componentesParaCalcular as $componente) {
            $linhaCsv = [
                $componente['codigo_componente'],
                $componente['descricao'],
                $componente['fornecedores'],
                $componente['projetos'],
                $componente['consumos'],
                numeroBr($componente['estoque_atual']),
            ];
            foreach ($dias as $dia) {
                $linhaCsv[] = numeroBr($componente['saldos'][$dia->format('Y-m-d')] ?? 0);
            }
            fputcsv($saida, $linhaCsv, ';', '"', '');
        }

        fclose($saida);
        exit;
    }

    // A partir daqui, só interessa a página atual (a exportação já saiu acima)
    $componentesPagina = $exportando ? $componentesPagina : $componentesParaCalcular;
} catch (Throwable $erro) {
    error_log('Erro na evolução geral: ' . $erro->getMessage());
    $erroGeral = 'Não foi possível carregar os dados. Verifique se a tabela "programacao" já foi criada.';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Evolução geral do estoque</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/dashboard.css" rel="stylesheet">
    <style>
        .evolucao-table { border-collapse: separate; border-spacing: 0; font-size: .78rem; }
        .evolucao-table th, .evolucao-table td {
            padding: 7px 9px;
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
        .evolucao-table thead tr:nth-child(1) th { top: 0; height: 16px; padding: 3px 9px; font-size: .72rem; font-weight: 700; white-space: nowrap; }
        .evolucao-table thead tr:nth-child(2) th { top: 24px; }
        .evolucao-table thead tr:nth-child(3) th { top: 57px; }
        .evolucao-table th:nth-child(-n+6),
        .evolucao-table td:nth-child(-n+6) {
            position: sticky;
            text-align: left;
            background: #fff;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .evolucao-table thead th:nth-child(-n+6) { background: #f5f8fb; z-index: 4; }
        .evolucao-table th:nth-child(1), .evolucao-table td:nth-child(1) { left: 0; width: 110px; min-width: 110px; max-width: 110px; z-index: 3; }
        .evolucao-table th:nth-child(2), .evolucao-table td:nth-child(2) { left: 110px; width: 220px; min-width: 220px; max-width: 220px; z-index: 3; }
        .evolucao-table th:nth-child(3), .evolucao-table td:nth-child(3) { left: 330px; width: 140px; min-width: 140px; max-width: 140px; z-index: 3; }
        .evolucao-table th:nth-child(4), .evolucao-table td:nth-child(4) { left: 470px; width: 150px; min-width: 150px; max-width: 150px; z-index: 3; }
        .evolucao-table th:nth-child(5), .evolucao-table td:nth-child(5) { left: 620px; width: 100px; min-width: 100px; max-width: 100px; z-index: 3; text-align: right; }
        .evolucao-table th:nth-child(6), .evolucao-table td:nth-child(6) { left: 720px; width: 100px; min-width: 100px; max-width: 100px; z-index: 3; text-align: right; box-shadow: 2px 0 0 #dce4ec; }
        .col-evento { background: #eaf8ee; }
        .col-hoje { background: #fff7c4 !important; }
        .saldo-negativo { color: #c53535; font-weight: 750; }
        .dot-edi {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #2f9e44;
            margin-right: 3px;
            vertical-align: middle;
        }
        .scroll-wrapper { max-height: 75vh; overflow: auto; border: 1px solid #dce4ec; border-radius: 12px; }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="container-fluid dashboard-container d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="eyebrow">Supply Chain • Planejamento de materiais</span>
                <h1>Evolução geral do estoque</h1>
                <p class="mb-0">Saldo projetado dia a dia, vários componentes lado a lado</p>
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
            </nav>
        </div>
    </header>

    <main class="container-fluid dashboard-container py-4">
        <?php if ($erroGeral !== null): ?>
            <div class="alert alert-danger" role="alert"><?php echo h($erroGeral); ?></div>
        <?php else: ?>

        <section class="filter-panel mb-4">
            <div class="section-heading">
                <div>
                    <span class="eyebrow text-primary">Filtros</span>
                    <h2>Escolha quais componentes ver</h2>
                </div>
                <a class="btn btn-outline-secondary btn-sm" href="evolucao_geral.php">Limpar filtros</a>
            </div>
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-12 col-lg-4">
                    <label class="form-label" for="busca">Componente, material ou descrição</label>
                    <input class="form-control" id="busca" name="busca" value="<?php echo h($busca); ?>" placeholder="Ex.: 12057429 ou clip">
                </div>
                <div class="col-6 col-md-4 col-lg-3">
                    <label class="form-label" for="fornecedor">Fornecedor</label>
                    <select class="form-select" id="fornecedor" name="fornecedor">
                        <option value="">Todos</option>
                        <?php foreach ($fornecedores as $opcao): ?>
                            <option value="<?php echo h($opcao); ?>" <?php echo $fornecedor === $opcao ? 'selected' : ''; ?>><?php echo h($opcao); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-4 col-lg-3">
                    <label class="form-label" for="projeto">Projeto</label>
                    <select class="form-select" id="projeto" name="projeto">
                        <option value="">Todos</option>
                        <?php foreach ($projetos as $opcao): ?>
                            <option value="<?php echo h($opcao); ?>" <?php echo $projeto === $opcao ? 'selected' : ''; ?>><?php echo h($opcao); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-4 col-lg-1 d-grid">
                    <button class="btn btn-primary" type="submit">Aplicar</button>
                </div>
            </form>
        </section>

        <section class="table-card">
            <div class="table-toolbar">
                <div>
                    <span class="eyebrow text-primary">Projeção</span>
                    <h2>Saldo dia a dia por componente</h2>
                    <p><?php echo numeroBr($totalComponentes); ?> componente(s) encontrado(s) • de <?php echo h($hoje->format('d/m/Y')); ?> até <?php echo h($dataLimite->format('d/m/Y')); ?> • coluna amarela = hoje</p>
                </div>
                <form method="GET" class="d-flex align-items-center gap-2">
                    <?php foreach ($_GET as $chave => $valor): ?>
                        <?php if (!in_array($chave, ['por_pagina', 'pagina', 'exportar'], true)): ?>
                            <input type="hidden" name="<?php echo h($chave); ?>" value="<?php echo h($valor); ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <a class="btn btn-outline-primary btn-sm" href="<?php echo h(urlComGeral(['exportar' => 'csv', 'pagina' => null])); ?>">Exportar CSV</a>
                    <label class="small text-muted text-nowrap" for="por_pagina">Componentes por página</label>
                    <select class="form-select form-select-sm" id="por_pagina" name="por_pagina" onchange="this.form.submit()">
                        <?php foreach ([10, 20, 50] as $quantidade): ?>
                            <option value="<?php echo $quantidade; ?>" <?php echo $porPagina === $quantidade ? 'selected' : ''; ?>><?php echo $quantidade; ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <div class="scroll-wrapper">
                <table class="evolucao-table mb-0">
                    <thead>
                        <tr>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <?php foreach ($dias as $dia): ?>
                                <?php $chave = $dia->format('Y-m-d'); ?>
                                <th class="linha-marcador <?php echo $chave === $hoje->format('Y-m-d') ? 'col-hoje' : ''; ?>">
                                    <?php if ($temEdiPorDia[$chave]): ?>
                                        <span class="dot-edi" title="Demanda EDI nesta data"></span><?php echo numeroBr($demandaEdiBrutaPorDia[$chave] ?? 0, 0); ?>
                                    <?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <th>Código</th>
                            <th>Descrição</th>
                            <th>Fornecedor</th>
                            <th>Projeto</th>
                            <th>Consumo</th>
                            <th>Estoque hoje</th>
                            <?php foreach ($dias as $dia): ?>
                                <?php $chave = $dia->format('Y-m-d'); ?>
                                <th class="<?php echo $chave === $hoje->format('Y-m-d') ? 'col-hoje' : ''; ?>">
                                    <?php echo $dia->format('W'); ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <?php foreach ($dias as $dia): ?>
                                <?php $chave = $dia->format('Y-m-d'); ?>
                                <th class="<?php echo $chave === $hoje->format('Y-m-d') ? 'col-hoje' : ''; ?>">
                                    <?php echo $dia->format('d/m/Y'); ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($componentesPagina)): ?>
                            <tr><td colspan="<?php echo 6 + count($dias); ?>" class="empty-state">Nenhum componente encontrado para os filtros selecionados.</td></tr>
                        <?php else: ?>
                            <?php foreach ($componentesPagina as $componente): ?>
                                <tr>
                                    <td><strong class="component-code"><?php echo h($componente['codigo_componente']); ?></strong></td>
                                    <td title="<?php echo h($componente['descricao']); ?>"><?php echo h($componente['descricao']); ?></td>
                                    <td title="<?php echo h($componente['fornecedores'] ?: 'Não informado'); ?>"><?php echo h($componente['fornecedores'] ?: 'Não informado'); ?></td>
                                    <td title="<?php echo h($componente['projetos'] ?: '—'); ?>"><?php echo h($componente['projetos'] ?: '—'); ?></td>
                                    <td class="text-end"><?php echo h($componente['consumos'] ?: '—'); ?></td>
                                    <td><?php echo numeroBr($componente['estoque_atual']); ?></td>
                                    <?php foreach ($dias as $dia): ?>
                                        <?php
                                            $chave = $dia->format('Y-m-d');
                                            $saldo = $componente['saldos'][$chave] ?? 0.0;
                                            $classes = [];
                                            if ($temEdiPorDia[$chave]) {
                                                $classes[] = 'col-evento';
                                            }
                                            if ($chave === $hoje->format('Y-m-d')) {
                                                $classes[] = 'col-hoje';
                                            }
                                            if ($saldo < 0) {
                                                $classes[] = 'saldo-negativo';
                                            }
                                        ?>
                                        <td class="<?php echo h(implode(' ', $classes)); ?>"><?php echo numeroBr($saldo); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPaginas > 1): ?>
                <nav class="pagination-bar" aria-label="Paginação da tabela">
                    <span>Página <?php echo $pagina; ?> de <?php echo $totalPaginas; ?></span>
                    <div class="btn-group">
                        <a class="btn btn-outline-secondary btn-sm <?php echo $pagina <= 1 ? 'disabled' : ''; ?>" href="<?php echo h(urlComGeral(['pagina' => max(1, $pagina - 1)])); ?>">Anterior</a>
                        <a class="btn btn-outline-secondary btn-sm <?php echo $pagina >= $totalPaginas ? 'disabled' : ''; ?>" href="<?php echo h(urlComGeral(['pagina' => min($totalPaginas, $pagina + 1)])); ?>">Próxima</a>
                    </div>
                </nav>
            <?php endif; ?>
        </section>

        <?php endif; ?>
    </main>
</body>
</html>
