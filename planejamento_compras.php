<?php
require_once 'conexao.php';

function h($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function numeroBr($valor, int $decimais = 2): string
{
    return number_format((float) $valor, $decimais, ',', '.');
}

function opcoesDistintasPlanejamento(mysqli $conn, string $coluna): array
{
    $permitidas = ['fornecedor', 'projeto'];
    if (!in_array($coluna, $permitidas, true)) {
        return [];
    }
    $sql = "SELECT DISTINCT TRIM($coluna) AS valor FROM bomnova WHERE $coluna IS NOT NULL AND TRIM($coluna) <> '' ORDER BY valor";
    $resultado = mysqli_query($conn, $sql);
    $opcoes = [];
    while ($linha = mysqli_fetch_assoc($resultado)) {
        $opcoes[] = $linha['valor'];
    }
    return $opcoes;
}

function urlComPlanejamento(array $alteracoes = []): string
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

// Netting MRP dia a dia (lot-for-lot com min/max de cobertura), igual ao
// espírito do dashboard (index.php), mas em vez de devolver UM pedido que
// tenta cobrir toda a janela de "estoque_max_dias" de uma vez, devolve uma
// LISTA de pedidos: sempre que o saldo simulado fica negativo em algum dia
// (respeitando o lead time = frozen zone + transit time), é gerado um pedido
// dimensionado para cobrir só até "estoque_max_dias" à frente daquele ponto.
// Esse pedido é então "aplicado" na simulação (como se tivesse chegado) e a
// simulação continua — se surgir uma nova necessidade mais adiante (outro
// evento de EDI fora da cobertura do pedido anterior), um novo pedido é
// gerado, com sua própria data e quantidade. Isso produz um calendário de
// compras escalonado conforme a data real de cada demanda EDI, em vez de uma
// única remessa antecipando tudo.
function calcularPedidosSugeridosPlanejamento(
    float $estoqueAtual,
    array $programacaoPorData,
    array $demandaPorData,
    DateTimeImmutable $hoje,
    DateTimeImmutable $horizonteFim,
    float $moq,
    int $frozenDias,
    int $transitDias,
    int $minDias,
    int $maxDias,
    float $setupPercentual = 0.0
): array {
    $dias = [];
    $cursor = $hoje;
    while ($cursor <= $horizonteFim) {
        $dias[] = $cursor;
        $cursor = $cursor->modify('+1 day');
    }
    $n = count($dias);
    if ($n === 0) {
        return [];
    }

    $hojeChave = $hoje->format('Y-m-d');
    $entradaAtrasada = 0.0;
    foreach ($programacaoPorData as $d => $q) {
        if ($d < $hojeChave) { $entradaAtrasada += $q; }
    }
    $saidaAtrasada = 0.0;
    foreach ($demandaPorData as $d => $q) {
        if ($d < $hojeChave) { $saidaAtrasada += $q; }
    }

    $entradaPorDia = array_fill(0, $n, 0.0);
    $demandaPorDia = array_fill(0, $n, 0.0);
    foreach ($dias as $i => $dia) {
        $chave = $dia->format('Y-m-d');
        $entradaPorDia[$i] = $programacaoPorData[$chave] ?? 0.0;
        $demandaPorDia[$i] = $demandaPorData[$chave] ?? 0.0;
    }

    // Prefixo de demanda para somar rapidamente a demanda de qualquer janela futura.
    $prefixo = array_fill(0, $n + 1, 0.0);
    for ($i = 0; $i < $n; $i++) {
        $prefixo[$i + 1] = $prefixo[$i] + $demandaPorDia[$i];
    }

    $leadTime = $frozenDias + $transitDias;
    $inicioBusca = $hoje->modify('+' . $leadTime . ' days');

    $pedidos = [];
    $saldo = $estoqueAtual + $entradaAtrasada - $saidaAtrasada;

    for ($i = 0; $i < $n; $i++) {
        $saldo += $entradaPorDia[$i] - $demandaPorDia[$i];

        if ($saldo >= 0 || $dias[$i] < $inicioBusca) {
            continue;
        }

        // Necessidade detectada no dia $i: dimensiona um pedido que cobre
        // essa falta até "maxDias" dias à frente (não o horizonte inteiro).
        $dataNecessidade = $dias[$i];
        $dataPedido = $dataNecessidade->modify('-' . $leadTime . ' days');
        $urgente = $dataPedido <= $hoje;
        $dataPedidoEfetiva = $urgente ? $hoje : $dataPedido;

        $janelaLocal = min($n, $i + $maxDias);
        $demandaJanela = $prefixo[$janelaLocal] - $prefixo[$i];

        $quantidadeAlvo = $demandaJanela > 0 ? ($demandaJanela - $saldo) : (-$saldo);
        $quantidadeBase = $moq > 0
            ? max($moq, ceil($quantidadeAlvo / $moq) * $moq)
            : max(0.0, $quantidadeAlvo);

        if ($quantidadeBase <= 0) {
            continue;
        }

        // Setup/scrap: só acresce a quantidade que vai pro pedido de compra
        // (o que o comprador precisa pedir ao fornecedor). O cálculo de
        // saldo/necessidade/data continua igual, sem essa % — é só um
        // acréscimo por cima da sugestão já calculada.
        $quantidadeSugerida = $setupPercentual > 0
            ? $quantidadeBase * (1 + $setupPercentual / 100)
            : $quantidadeBase;

        $pedidos[] = [
            'status' => $urgente ? 'urgente' : 'programada',
            'data' => $dataPedidoEfetiva,
            'data_necessidade' => $dataNecessidade,
            'quantidade' => $quantidadeSugerida,
        ];

        // "Aplica" o pedido na simulação (chega a tempo de cobrir o dia $i)
        // para que a busca continue e detecte a PRÓXIMA necessidade real,
        // em vez de somar tudo num pedido só. Usa a quantidade BASE (sem
        // setup), pois o setup é só uma margem de compra — não representa
        // estoque físico extra disponível pra cobrir demanda futura.
        $saldo += $quantidadeBase;
    }

    return $pedidos;
}

$busca = trim($_GET['busca'] ?? '');
$fornecedor = trim($_GET['fornecedor'] ?? '');
$projeto = trim($_GET['projeto'] ?? '');
$meses = (int) ($_GET['meses'] ?? 5);
if (!in_array($meses, [3, 5, 6, 12], true)) {
    $meses = 5;
}

$hoje = new DateTimeImmutable('today');
$fimJanela = $hoje->modify("+$meses months");
$horizonteCalculo = new DateTimeImmutable('2027-12-31'); // horizonte amplo pra achar a real data de necessidade

$erroPlanejamento = null;
$resultados = [];
$fornecedores = [];
$projetos = [];

try {
    $fornecedores = opcoesDistintasPlanejamento($conn, 'fornecedor');
    $projetos = opcoesDistintasPlanejamento($conn, 'projeto');

    $condicoes = [
        "b.codigo_componente IS NOT NULL", "TRIM(b.codigo_componente) <> ''",
        "(b.mrp IS NULL OR UPPER(TRIM(b.mrp)) <> 'N')",
    ];
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

    // Só entra no planejamento quem tem os 5 parâmetros de compra preenchidos
    $sqlComponentes = "SELECT
            TRIM(b.codigo_componente) AS codigo_componente,
            MAX(COALESCE(NULLIF(TRIM(b.descricao), ''), 'Sem descrição')) AS descricao,
            GROUP_CONCAT(DISTINCT NULLIF(TRIM(b.fornecedor), '') ORDER BY TRIM(b.fornecedor) SEPARATOR ', ') AS fornecedores,
            GROUP_CONCAT(DISTINCT NULLIF(TRIM(b.projeto), '') ORDER BY TRIM(b.projeto) SEPARATOR ', ') AS projetos,
            COALESCE(MAX(est.estoque_atual), 0) AS estoque_atual,
            MAX(p.moq) AS moq,
            MAX(p.frozen_zone_dias) AS frozen_zone_dias,
            MAX(p.transit_time_dias) AS transit_time_dias,
            MAX(p.estoque_min_dias) AS estoque_min_dias,
            MAX(p.estoque_max_dias) AS estoque_max_dias,
            MAX(p.setup) AS setup
        FROM bomnova b
        INNER JOIN parametros_compra p
            ON TRIM(p.codigo_componente) = TRIM(b.codigo_componente)
            AND p.moq IS NOT NULL AND p.frozen_zone_dias IS NOT NULL AND p.transit_time_dias IS NOT NULL
            AND p.estoque_min_dias IS NOT NULL AND p.estoque_max_dias IS NOT NULL
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

    $stmtComponentes = mysqli_prepare($conn, $sqlComponentes);
    if ($tipos !== '') {
        mysqli_stmt_bind_param($stmtComponentes, $tipos, ...$parametros);
    }
    mysqli_stmt_execute($stmtComponentes);
    $resComponentes = mysqli_stmt_get_result($stmtComponentes);
    $componentes = [];
    while ($linha = mysqli_fetch_assoc($resComponentes)) {
        $componentes[$linha['codigo_componente']] = $linha;
    }
    mysqli_stmt_close($stmtComponentes);

    if (!empty($componentes)) {
        $codigos = array_keys($componentes);
        $placeholders = implode(',', array_fill(0, count($codigos), '?'));
        $tiposCodigos = str_repeat('s', count($codigos));

        $programacaoPorComponente = [];
        $stmtProg = mysqli_prepare($conn, "
            SELECT TRIM(codigo_componente) AS codigo_componente, data, SUM(quantidade) AS quantidade
            FROM programacao
            WHERE TRIM(codigo_componente) IN ($placeholders)
            GROUP BY TRIM(codigo_componente), data
        ");
        mysqli_stmt_bind_param($stmtProg, $tiposCodigos, ...$codigos);
        mysqli_stmt_execute($stmtProg);
        $resProg = mysqli_stmt_get_result($stmtProg);
        while ($linha = mysqli_fetch_assoc($resProg)) {
            $programacaoPorComponente[$linha['codigo_componente']][$linha['data']] = (float) $linha['quantidade'];
        }
        mysqli_stmt_close($stmtProg);

        $demandaPorComponente = [];
        $stmtDemanda = mysqli_prepare($conn, "
            SELECT TRIM(b.codigo_componente) AS codigo_componente, e.data_inicio AS data,
                   SUM(
                       COALESCE(CAST(e.quantidade AS DECIMAL(18,4)), 0)
                       * COALESCE(CAST(NULLIF(REPLACE(TRIM(b.consumo), ',', '.'), '') AS DECIMAL(18,6)), 0)
                   ) AS quantidade
            FROM bomnova b
            JOIN edi e ON TRIM(b.material) = TRIM(e.material)
            WHERE TRIM(b.codigo_componente) IN ($placeholders) AND (b.mrp IS NULL OR UPPER(TRIM(b.mrp)) <> 'N')
            GROUP BY TRIM(b.codigo_componente), e.data_inicio
        ");
        mysqli_stmt_bind_param($stmtDemanda, $tiposCodigos, ...$codigos);
        mysqli_stmt_execute($stmtDemanda);
        $resDemanda = mysqli_stmt_get_result($stmtDemanda);
        while ($linha = mysqli_fetch_assoc($resDemanda)) {
            $demandaPorComponente[$linha['codigo_componente']][$linha['data']] = (float) $linha['quantidade'];
        }
        mysqli_stmt_close($stmtDemanda);

        foreach ($componentes as $codigo => $comp) {
            $pedidos = calcularPedidosSugeridosPlanejamento(
                (float) $comp['estoque_atual'],
                $programacaoPorComponente[$codigo] ?? [],
                $demandaPorComponente[$codigo] ?? [],
                $hoje,
                $horizonteCalculo,
                (float) $comp['moq'],
                (int) $comp['frozen_zone_dias'],
                (int) $comp['transit_time_dias'],
                (int) $comp['estoque_min_dias'],
                (int) $comp['estoque_max_dias'],
                $comp['setup'] !== null ? (float) $comp['setup'] : 0.0
            );

            $totalParcelas = count($pedidos);
            foreach ($pedidos as $indice => $resultado) {
                // Só entra na lista se precisa de ação dentro da janela de meses
                // escolhida. Pedidos urgentes sempre aparecem (precisam de ação
                // agora); pedidos programados só aparecem se caírem dentro da
                // janela de meses selecionada no filtro.
                if ($resultado['status'] === 'programada' && $resultado['data'] > $fimJanela) {
                    continue;
                }

                $resultados[] = [
                    'codigo_componente' => $codigo,
                    'descricao' => $comp['descricao'],
                    'fornecedores' => $comp['fornecedores'],
                    'projetos' => $comp['projetos'],
                    'estoque_atual' => (float) $comp['estoque_atual'],
                    'status' => $resultado['status'],
                    'data' => $resultado['data'],
                    'data_necessidade' => $resultado['data_necessidade'],
                    'quantidade' => $resultado['quantidade'],
                    'parcela' => $indice + 1,
                    'total_parcelas' => $totalParcelas,
                    'setup' => $comp['setup'] !== null ? (float) $comp['setup'] : 0.0,
                ];
            }
        }

        usort($resultados, function ($a, $b) {
            if ($a['status'] === 'urgente' && $b['status'] !== 'urgente') return -1;
            if ($b['status'] === 'urgente' && $a['status'] !== 'urgente') return 1;
            return $a['data'] <=> $b['data'];
        });
    }

    // Exportação CSV: usa os mesmos resultados já calculados e filtrados
    if (($_GET['exportar'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="planejamento-compras-' . date('Y-m-d-His') . '.csv"');
        echo "\xEF\xBB\xBF";
        $saida = fopen('php://output', 'w');
        fputcsv($saida, ['Data sugerida', 'Necessidade (EDI)', 'Parcela', 'Código', 'Descrição', 'Fornecedor', 'Projeto', 'Estoque hoje', 'Quantidade sugerida', 'Setup (%)', 'Status'], ';', '"', '');
        foreach ($resultados as $r) {
            $dataTexto = $r['status'] === 'urgente' ? 'URGENTE' : $r['data']->format('d/m/Y');
            fputcsv($saida, [
                $dataTexto,
                $r['data_necessidade']->format('d/m/Y'),
                $r['parcela'] . '/' . $r['total_parcelas'],
                $r['codigo_componente'],
                $r['descricao'],
                $r['fornecedores'],
                $r['projetos'],
                numeroBr($r['estoque_atual'], 0),
                numeroBr($r['quantidade'], 0),
                $r['setup'] > 0 ? numeroBr($r['setup'], 0) : '',
                $r['status'] === 'urgente' ? 'Urgente' : 'Planejar',
            ], ';', '"', '');
        }
        fclose($saida);
        exit;
    }
} catch (Throwable $erro) {
    error_log('Erro no planejamento de compras: ' . $erro->getMessage());
    $erroPlanejamento = 'Não foi possível carregar o planejamento. Verifique se a tabela "parametros_compra" já foi criada.';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Planejamento de Compras</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/dashboard.css" rel="stylesheet">
    <style>
        .status-urgente { color: #c53535; background: #fff0f0; }
        .status-programada { color: #a96600; background: #fff7df; }
        .mes-divisor td { background: #f5f8fb; font-weight: 750; color: #405164; }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="container-fluid dashboard-container d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="eyebrow">Supply Chain • Planejamento de materiais</span>
                <h1>Planejamento de Compras</h1>
                <p class="mb-0">Calendário de compras sugeridas com base nos parâmetros cadastrados</p>
            </div>
            <nav class="d-flex flex-wrap gap-2" aria-label="Ações do sistema">
                <a class="btn btn-light btn-sm" href="index.php">Dashboard</a>
                <a class="btn btn-outline-light btn-sm" href="parametros_compra.php">Parâmetros</a>
            </nav>
        </div>
    </header>

    <main class="container-fluid dashboard-container py-4">
        <?php if ($erroPlanejamento !== null): ?>
            <div class="alert alert-danger" role="alert"><?php echo h($erroPlanejamento); ?></div>
        <?php else: ?>

        <section class="filter-panel mb-4">
            <div class="section-heading">
                <div>
                    <span class="eyebrow text-primary">Filtros</span>
                    <h2>Escolha o período e o escopo</h2>
                </div>
                <a class="btn btn-outline-secondary btn-sm" href="planejamento_compras.php">Limpar filtros</a>
            </div>
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-12 col-lg-4">
                    <label class="form-label" for="busca">Componente, material ou descrição</label>
                    <input class="form-control" id="busca" name="busca" value="<?php echo h($busca); ?>" placeholder="Ex.: 12057429 ou clip">
                </div>
                <div class="col-6 col-md-3 col-lg-3">
                    <label class="form-label" for="fornecedor">Fornecedor</label>
                    <select class="form-select" id="fornecedor" name="fornecedor">
                        <option value="">Todos</option>
                        <?php foreach ($fornecedores as $opcao): ?>
                            <option value="<?php echo h($opcao); ?>" <?php echo $fornecedor === $opcao ? 'selected' : ''; ?>><?php echo h($opcao); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-3">
                    <label class="form-label" for="projeto">Projeto</label>
                    <select class="form-select" id="projeto" name="projeto">
                        <option value="">Todos</option>
                        <?php foreach ($projetos as $opcao): ?>
                            <option value="<?php echo h($opcao); ?>" <?php echo $projeto === $opcao ? 'selected' : ''; ?>><?php echo h($opcao); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-1">
                    <label class="form-label" for="meses">Meses</label>
                    <select class="form-select" id="meses" name="meses">
                        <?php foreach ([3, 5, 6, 12] as $m): ?>
                            <option value="<?php echo $m; ?>" <?php echo $meses === $m ? 'selected' : ''; ?>><?php echo $m; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-1 d-grid">
                    <button class="btn btn-primary" type="submit">Aplicar</button>
                </div>
            </form>
        </section>

        <section class="table-card">
            <div class="table-toolbar">
                <div>
                    <span class="eyebrow text-primary">Calendário</span>
                    <h2>Compras necessárias nos próximos <?php echo $meses; ?> meses</h2>
                    <?php $componentesUnicos = count(array_unique(array_column($resultados, 'codigo_componente'))); ?>
                    <p><?php echo $componentesUnicos; ?> componente(s) • <?php echo count($resultados); ?> pedido(s) escalonado(s) sugerido(s) • de <?php echo h($hoje->format('d/m/Y')); ?> até <?php echo h($fimJanela->format('d/m/Y')); ?></p>
                </div>
                <a class="btn btn-outline-primary btn-sm" href="<?php echo h(urlComPlanejamento(['exportar' => 'csv'])); ?>">Exportar CSV</a>
            </div>

            <div class="table-responsive">
                <table class="table mrp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Data sugerida</th>
                            <th>Código</th>
                            <th>Descrição</th>
                            <th>Fornecedor</th>
                            <th>Projeto</th>
                            <th>Necessidade (EDI)</th>
                            <th class="text-end">Estoque hoje</th>
                            <th class="text-end">Quantidade sugerida</th>
                            <th>Status</th>
                            <th>Evolução</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($resultados)): ?>
                            <tr><td colspan="10" class="empty-state">Nenhuma compra necessária no período — ou nenhum componente com parâmetros cadastrados ainda.</td></tr>
                        <?php else: ?>
                            <?php $mesAtual = null; ?>
                            <?php foreach ($resultados as $r): ?>
                                <?php
                                    $mesLabel = $r['status'] === 'urgente' ? 'URGENTE — AGORA' : ucfirst($r['data']->format('F \d\e Y'));
                                    if ($mesLabel !== $mesAtual):
                                        $mesAtual = $mesLabel;
                                ?>
                                    <tr class="mes-divisor"><td colspan="10"><?php echo h($mesLabel); ?></td></tr>
                                <?php endif; ?>
                                <tr>
                                    <td><?php echo $r['status'] === 'urgente' ? '—' : h($r['data']->format('d/m/Y')); ?></td>
                                    <td>
                                        <strong class="component-code"><?php echo h($r['codigo_componente']); ?></strong>
                                        <?php if ($r['total_parcelas'] > 1): ?>
                                            <span class="badge bg-light text-secondary border ms-1" title="Este componente tem <?php echo $r['total_parcelas']; ?> pedidos escalonados no período calculado">parcela <?php echo $r['parcela']; ?>/<?php echo $r['total_parcelas']; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo h($r['descricao']); ?></td>
                                    <td><?php echo h($r['fornecedores'] ?: 'Não informado'); ?></td>
                                    <td><?php echo h($r['projetos'] ?: '—'); ?></td>
                                    <td><?php echo h($r['data_necessidade']->format('d/m/Y')); ?></td>
                                    <td class="text-end"><?php echo numeroBr($r['estoque_atual'], 0); ?></td>
                                    <td class="text-end" <?php if ($r['setup'] > 0): ?>title="Já inclui <?php echo numeroBr($r['setup'], 0); ?>% de setup/scrap"<?php endif; ?>>
                                        <?php echo numeroBr($r['quantidade'], 0); ?>
                                        <?php if ($r['setup'] > 0): ?><span class="badge bg-light text-secondary border ms-1">+<?php echo numeroBr($r['setup'], 0); ?>%</span><?php endif; ?>
                                    </td>
                                    <td><span class="status-badge status-<?php echo h($r['status']); ?>"><?php echo $r['status'] === 'urgente' ? 'Urgente' : 'Planejar'; ?></span></td>
                                    <td><a class="btn btn-outline-primary btn-sm" href="detalhe_componente.php?codigo=<?php echo urlencode($r['codigo_componente']); ?>">Ver evolução</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php endif; ?>
    </main>
</body>
</html>
