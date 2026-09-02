<?php
require_once 'conexao.php';

function h(mixed $valor): string
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

// Mesma lógica de simulação usada no dashboard (index.php): saldo dia a dia,
// aplicando programação e demanda na data exata de cada evento, com taxa de
// demanda média estável e trava de saldo físico negativo.
function calcularDataSugeridaCompraPlanejamento(
    float $estoqueAtual,
    array $programacaoPorData,
    array $demandaPorData,
    DateTimeImmutable $hoje,
    DateTimeImmutable $horizonteFim,
    float $moq,
    int $frozenDias,
    int $transitDias,
    int $minDias,
    int $maxDias
): array {
    $dias = [];
    $cursor = $hoje;
    while ($cursor <= $horizonteFim) {
        $dias[] = $cursor;
        $cursor = $cursor->modify('+1 day');
    }
    $n = count($dias);
    if ($n === 0) {
        return ['status' => 'ok', 'data' => null, 'quantidade' => 0.0];
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

    $saldoPorDia = [];
    $demandaPorDia = [];
    $saldoAnterior = $estoqueAtual + $entradaAtrasada - $saidaAtrasada;
    foreach ($dias as $i => $dia) {
        $chave = $dia->format('Y-m-d');
        $entrada = $programacaoPorData[$chave] ?? 0.0;
        $saida = $demandaPorData[$chave] ?? 0.0;
        $saldo = $saldoAnterior + $entrada - $saida;
        $saldoPorDia[$i] = $saldo;
        $demandaPorDia[$i] = $saida;
        $saldoAnterior = $saldo;
    }

    $prefixo = array_fill(0, $n + 1, 0.0);
    for ($i = 0; $i < $n; $i++) {
        $prefixo[$i + 1] = $prefixo[$i] + $demandaPorDia[$i];
    }

    $inicioBusca = $hoje->modify('+' . ($frozenDias + $transitDias) . ' days');

    for ($i = 0; $i < $n; $i++) {
        if ($dias[$i] < $inicioBusca) {
            continue;
        }
        if ($saldoPorDia[$i] < 0) {
            $dataNecessidade = $dias[$i];
            $dataSugerida = $dataNecessidade->modify('-' . ($frozenDias + $transitDias) . ' days');

            $janelaLocal = min($n, $i + $maxDias);
            $demandaJanelaMax = $prefixo[$janelaLocal] - $prefixo[$i];

            $quantidadeAlvo = $demandaJanelaMax > 0
                ? $demandaJanelaMax - $saldoPorDia[$i]
                : -$saldoPorDia[$i];
            $quantidadeSugerida = $moq > 0 ? max($moq, ceil($quantidadeAlvo / $moq) * $moq) : max(0, $quantidadeAlvo);

            if ($dataSugerida <= $hoje) {
                return ['status' => 'urgente', 'data' => $hoje, 'quantidade' => $quantidadeSugerida];
            }
            return ['status' => 'programada', 'data' => $dataSugerida, 'quantidade' => $quantidadeSugerida];
        }
    }

    return ['status' => 'ok', 'data' => null, 'quantidade' => 0.0];
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
            MAX(p.estoque_max_dias) AS estoque_max_dias
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
              AND (atendido = 0 OR atendido IS NULL)
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
              AND (e.atendido = 0 OR e.atendido IS NULL)
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
            $resultado = calcularDataSugeridaCompraPlanejamento(
                (float) $comp['estoque_atual'],
                $programacaoPorComponente[$codigo] ?? [],
                $demandaPorComponente[$codigo] ?? [],
                $hoje,
                $horizonteCalculo,
                (float) $comp['moq'],
                (int) $comp['frozen_zone_dias'],
                (int) $comp['transit_time_dias'],
                (int) $comp['estoque_min_dias'],
                (int) $comp['estoque_max_dias']
            );

            // Só entra na lista se precisa de ação dentro da janela de meses escolhida
            if ($resultado['status'] === 'ok') {
                continue;
            }
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
                'quantidade' => $resultado['quantidade'],
            ];
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
        fputcsv($saida, ['Data sugerida', 'Código', 'Descrição', 'Fornecedor', 'Projeto', 'Estoque hoje', 'Quantidade sugerida', 'Status'], ';', '"', '');
        foreach ($resultados as $r) {
            $dataTexto = $r['status'] === 'urgente' ? 'URGENTE' : $r['data']->format('d/m/Y');
            fputcsv($saida, [
                $dataTexto,
                $r['codigo_componente'],
                $r['descricao'],
                $r['fornecedores'],
                $r['projetos'],
                numeroBr($r['estoque_atual'], 0),
                numeroBr($r['quantidade'], 0),
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

        /* Esta tela tem menos colunas que o dashboard principal, então a largura
           mínima de 1460px herdada de .mrp-table sobra e vira um vão vazio na tela.
           Reduz só aqui (sem alterar dashboard.css, usado também no Dashboard) e
           trava a largura das colunas de texto, com reticências para texto longo. */
        .mrp-table { min-width: 1100px; }
        .mrp-table td:nth-child(3), .mrp-table th:nth-child(3) { max-width: 260px; overflow: hidden; text-overflow: ellipsis; }
        .mrp-table td:nth-child(4), .mrp-table th:nth-child(4) { max-width: 160px; overflow: hidden; text-overflow: ellipsis; }
        .mrp-table td:nth-child(5), .mrp-table th:nth-child(5) { max-width: 200px; overflow: hidden; text-overflow: ellipsis; text-align: center; }
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
                    <p><?php echo count($resultados); ?> componente(s) precisam de ação • de <?php echo h($hoje->format('d/m/Y')); ?> até <?php echo h($fimJanela->format('d/m/Y')); ?></p>
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
                            <th class="text-center">Estoque hoje</th>
                            <th class="text-center">Quantidade sugerida</th>
                            <th class="text-center">Status</th>
                            <th>Evolução</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($resultados)): ?>
                            <tr><td colspan="9" class="empty-state">Nenhuma compra necessária no período — ou nenhum componente com parâmetros cadastrados ainda.</td></tr>
                        <?php else: ?>
                            <?php $mesAtual = null; ?>
                            <?php foreach ($resultados as $r): ?>
                                <?php
                                    $mesLabel = $r['status'] === 'urgente' ? 'URGENTE — AGORA' : ucfirst($r['data']->format('F \d\e Y'));
                                    if ($mesLabel !== $mesAtual):
                                        $mesAtual = $mesLabel;
                                ?>
                                    <tr class="mes-divisor"><td colspan="9"><?php echo h($mesLabel); ?></td></tr>
                                <?php endif; ?>
                                <tr>
                                    <td><?php echo $r['status'] === 'urgente' ? '—' : h($r['data']->format('d/m/Y')); ?></td>
                                    <td><strong class="component-code"><?php echo h($r['codigo_componente']); ?></strong></td>
                                    <td title="<?php echo h($r['descricao']); ?>"><?php echo h($r['descricao']); ?></td>
                                    <td title="<?php echo h($r['fornecedores'] ?: 'Não informado'); ?>"><?php echo h($r['fornecedores'] ?: 'Não informado'); ?></td>
                                    <td title="<?php echo h($r['projetos'] ?: '—'); ?>"><?php echo h($r['projetos'] ?: '—'); ?></td>
                                    <td class="text-center"><?php echo numeroBr($r['estoque_atual'], 0); ?></td>
                                    <td class="text-center"><?php echo numeroBr($r['quantidade'], 0); ?></td>
                                    <td class="text-center"><span class="status-badge status-<?php echo h($r['status']); ?>"><?php echo $r['status'] === 'urgente' ? 'Urgente' : 'Planejar'; ?></span></td>
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
