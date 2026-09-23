<?php
require_once 'conexao.php';
require_once 'mrp_calculo.php';

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


$busca = trim($_GET['busca'] ?? '');
$fornecedor = trim($_GET['fornecedor'] ?? '');
$projeto = trim($_GET['projeto'] ?? '');
$meses = (int) ($_GET['meses'] ?? 5);
if (!in_array($meses, [3, 5, 6, 12], true)) {
    $meses = 5;
}

$hoje = new DateTimeImmutable('today');
$fimJanela = $hoje->modify("+$meses months");
$horizonteCalculo = $hoje->modify('+12 months'); // horizonte rolante (sempre 12 meses à frente de hoje) pra achar a real data de necessidade

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
        "(b.planejamento IS NULL OR UPPER(TRIM(b.planejamento)) <> 'N')",
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
            WHERE TRIM(b.codigo_componente) IN ($placeholders) AND (b.mrp IS NULL OR UPPER(TRIM(b.mrp)) <> 'N') AND (b.planejamento IS NULL OR UPPER(TRIM(b.planejamento)) <> 'N')
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
            $setupComp = $comp['setup'] !== null ? (float) $comp['setup'] : 0.0;

            // Estoque de segurança calculado automaticamente (sem dias cadastrados
            // manualmente) — ver calcularEstoqueSegurancaQtd().
            $segurancaQtd = calcularEstoqueSegurancaQtd(
                $demandaPorComponente[$codigo] ?? [],
                $hoje,
                (int) $comp['frozen_zone_dias'],
                (int) $comp['transit_time_dias'],
                $setupComp
            );

            $resultadoCalculo = calcularParcelasCompraPlanejamento(
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
                $setupComp,
                $segurancaQtd
            );
            $parcelas = $resultadoCalculo['parcelas'];

            if (empty($parcelas)) {
                continue;
            }

            $totalParcelas = count($parcelas);

            // Só entra na lista a parcela que precisa de ação dentro da janela de meses
            // escolhida (urgente sempre entra, mesmo com data "sugerida" = hoje). O número
            // da parcela (ex.: 2/5) reflete a posição dela no total do horizonte de 12
            // meses, mesmo que só uma parte apareça filtrada na tela.
            foreach ($parcelas as $indice => $p) {
                if ($p['status'] !== 'urgente' && $p['data_necessidade'] > $fimJanela) {
                    continue;
                }

                $resultados[] = [
                    'codigo_componente' => $codigo,
                    'descricao' => $comp['descricao'],
                    'fornecedores' => $comp['fornecedores'],
                    'projetos' => $comp['projetos'],
                    'estoque_atual' => (float) $comp['estoque_atual'],
                    'status' => $p['status'],
                    // Mostra a DATA DE NECESSIDADE (quando o saldo ficaria negativo/abaixo
                    // do estoque de segurança), não mais a data sugerida de compra (que é
                    // a necessidade menos lead time + transit time). Quem quiser saber
                    // quando fazer o pedido, já vê o Lead Time/Transit Time em Parâmetros
                    // de Compra e pode subtrair — aqui o foco passa a ser "quando falta".
                    'data' => $p['data_necessidade'],
                    'quantidade' => $p['quantidade'],
                    'quantidade_base' => $p['quantidade_base'],
                    'setup' => $p['setup'],
                    'estoque_seguranca_qtd' => $segurancaQtd,
                    'parcela' => $indice + 1,
                    'total_parcelas' => $totalParcelas,
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
        fputcsv($saida, ['Data de necessidade', 'Código', 'Descrição', 'Fornecedor', 'Projeto', 'Parcela', 'Estoque hoje', 'Quantidade sugerida', 'Setup (%)', 'Status'], ';', '"', '');
        foreach ($resultados as $r) {
            // Mostra a data de necessidade sempre, mesmo quando o status é "urgente" —
            // a coluna Status já indica a urgência, então não faz sentido esconder a data.
            $dataTexto = $r['data']->format('d/m/Y');
            fputcsv($saida, [
                $dataTexto,
                $r['codigo_componente'],
                $r['descricao'],
                $r['fornecedores'],
                $r['projetos'],
                $r['parcela'] . '/' . $r['total_parcelas'],
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

        /* Esta tela tem menos colunas que o dashboard principal, então a largura
           mínima de 1460px herdada de .mrp-table sobra e vira um vão vazio na tela.
           Reduz só aqui (sem alterar dashboard.css, usado também no Dashboard) e
           trava a largura das colunas de texto, com reticências para texto longo. */
        .mrp-table { min-width: 1100px; }
        .mrp-table td:nth-child(3), .mrp-table th:nth-child(3) { max-width: 260px; overflow: hidden; text-overflow: ellipsis; }
        .mrp-table td:nth-child(4), .mrp-table th:nth-child(4) { max-width: 160px; overflow: hidden; text-overflow: ellipsis; text-align: center; }
        .mrp-table td:nth-child(5), .mrp-table th:nth-child(5) { max-width: 200px; overflow: hidden; text-overflow: ellipsis; text-align: center; }

        /* Quantidade sugerida: o número precisa ficar sempre na mesma posição, com ou
           sem o badge de setup — senão a coluna centraliza o bloco inteiro (número +
           badge) e o número "pula" de lugar entre linhas. Fixa a largura do número e
           projeta o badge pra fora (posição absoluta), sem afetar o centro do número. */
        .qtd-sugerida-wrap { position: relative; display: inline-block; min-width: 56px; text-align: right; }
        .qtd-sugerida-num { display: inline-block; }
        .qtd-sugerida-badge {
            position: absolute;
            left: 100%;
            top: 50%;
            transform: translateY(-50%);
            margin-left: 6px;
            white-space: nowrap;
        }
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
                <a class="btn btn-outline-light btn-sm" href="pedido_compra.php">📄 Pedido de Compra</a>
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
                            <th>Data de necessidade</th>
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
                                    <?php /* Sempre mostra a data de necessidade, mesmo quando "urgente" — o badge de Status já
                                             diferencia a urgência; esconder a data aqui só tirava informação de quem olha a tela. */ ?>
                                    <td><?php echo h($r['data']->format('d/m/Y')); ?></td>
                                    <td>
                                        <strong class="component-code"><?php echo h($r['codigo_componente']); ?></strong>
                                        <?php if ($r['total_parcelas'] > 1): ?>
                                            <span class="badge bg-light text-secondary border ms-1" title="Este componente tem <?php echo $r['total_parcelas']; ?> pedidos escalonados no período calculado">parcela <?php echo $r['parcela']; ?>/<?php echo $r['total_parcelas']; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td title="<?php echo h($r['descricao']); ?>"><?php echo h($r['descricao']); ?></td>
                                    <td title="<?php echo h($r['fornecedores'] ?: 'Não informado'); ?>"><?php echo h($r['fornecedores'] ?: 'Não informado'); ?></td>
                                    <td title="<?php echo h($r['projetos'] ?: '—'); ?>"><?php echo h($r['projetos'] ?: '—'); ?></td>
                                    <td class="text-center"><?php echo numeroBr($r['estoque_atual'], 0); ?></td>
                                    <td class="text-center" <?php if ($r['setup'] > 0): ?>title="Já inclui <?php echo numeroBr($r['setup'], 0); ?>% de setup/scrap (base: <?php echo numeroBr($r['quantidade_base'], 0); ?>)"<?php endif; ?>>
                                        <span class="qtd-sugerida-wrap">
                                            <span class="qtd-sugerida-num"><?php echo numeroBr($r['quantidade'], 0); ?></span>
                                            <?php if ($r['setup'] > 0): ?><span class="badge bg-light text-secondary border qtd-sugerida-badge">+<?php echo numeroBr($r['setup'], 0); ?>%</span><?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="text-center"><span class="status-badge status-<?php echo h($r['status']); ?>" <?php if ($r['estoque_seguranca_qtd'] > 0): ?>title="Estoque de segurança calculado: <?php echo numeroBr($r['estoque_seguranca_qtd'], 0); ?> unidades"<?php endif; ?>><?php echo $r['status'] === 'urgente' ? 'Urgente' : 'Planejar'; ?></span></td>
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
