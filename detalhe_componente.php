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

$codigo = trim($_GET['codigo'] ?? '');
if ($codigo === '') {
    http_response_code(400);
    die('Código do componente não informado. Volte ao <a href="index.php">Dashboard</a> e clique em "Ver evolução" em um componente.');
}

$dataLimite = new DateTimeImmutable('2027-03-31');
$hoje = new DateTimeImmutable('today');

$erroDetalhe = null;
$infoComponente = ['descricao' => '', 'fornecedores' => '', 'projetos' => ''];
$estoqueAtual = 0.0;
$programacaoPorData = [];
$demandaPorData = [];

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

    $stmtEstoque = mysqli_prepare($conn, "
        SELECT SUM(COALESCE(CAST(estoque AS DECIMAL(18,4)), 0)) AS estoque_atual
        FROM estoque
        WHERE TRIM(codigo_componente) = ?
    ");
    mysqli_stmt_bind_param($stmtEstoque, 's', $codigo);
    mysqli_stmt_execute($stmtEstoque);
    $estoqueAtual = (float) (mysqli_fetch_assoc(mysqli_stmt_get_result($stmtEstoque))['estoque_atual'] ?? 0);
    mysqli_stmt_close($stmtEstoque);

    // Programação de entradas futuras (chegadas previstas), somadas por data
    $stmtProg = mysqli_prepare($conn, "
        SELECT data, SUM(quantidade) AS quantidade
        FROM programacao
        WHERE TRIM(codigo_componente) = ?
        GROUP BY data
    ");
    mysqli_stmt_bind_param($stmtProg, 's', $codigo);
    mysqli_stmt_execute($stmtProg);
    $resProg = mysqli_stmt_get_result($stmtProg);
    while ($linha = mysqli_fetch_assoc($resProg)) {
        $programacaoPorData[$linha['data']] = (float) $linha['quantidade'];
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
        WHERE TRIM(b.codigo_componente) = ? AND (b.mrp IS NULL OR UPPER(TRIM(b.mrp)) <> 'N')
        GROUP BY e.data_inicio
    ");
    mysqli_stmt_bind_param($stmtDemanda, 's', $codigo);
    mysqli_stmt_execute($stmtDemanda);
    $resDemanda = mysqli_stmt_get_result($stmtDemanda);
    while ($linha = mysqli_fetch_assoc($resDemanda)) {
        $demandaPorData[$linha['data']] = (float) $linha['quantidade'];
    }
    mysqli_stmt_close($stmtDemanda);
} catch (Throwable $erro) {
    error_log('Erro no detalhe do componente: ' . $erro->getMessage());
    $erroDetalhe = 'Não foi possível carregar os dados deste componente. Verifique se a tabela "programacao" já foi criada (veja sql_programacao.sql).';
}

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
    </style>
</head>
<body>
    <header class="topbar">
        <div class="container-fluid dashboard-container d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="eyebrow">Supply Chain • Planejamento de materiais</span>
                <h1>Evolução do estoque</h1>
                <p class="mb-0">Saldo projetado dia a dia até 31/03/2027</p>
            </div>
            <nav class="d-flex flex-wrap gap-2" aria-label="Ações do sistema">
                <a class="btn btn-light btn-sm" href="index.php">Voltar ao Dashboard</a>
                <a class="btn btn-outline-light btn-sm" href="evolucao_geral.php">Evolução geral</a>
                <a class="btn btn-outline-light btn-sm" href="planejamento_compras.php">Planejamento de compras</a>
                <a class="btn btn-outline-light btn-sm" href="programacao.php">Programação</a>
            </nav>
        </div>
    </header>

    <main class="container-fluid dashboard-container py-4">
        <?php if ($erroDetalhe !== null): ?>
            <div class="alert alert-danger" role="alert"><?php echo h($erroDetalhe); ?></div>
        <?php else: ?>

        <section class="filter-panel mb-4">
            <span class="eyebrow text-primary">Componente</span>
            <h2 class="mb-2"><?php echo h($codigo); ?></h2>
            <p class="mb-1"><strong>Descrição:</strong> <?php echo h($infoComponente['descricao'] ?: '—'); ?></p>
            <p class="mb-1"><strong>Fornecedor(es):</strong> <?php echo h($infoComponente['fornecedores'] ?: 'Não informado'); ?></p>
            <p class="mb-1"><strong>Projeto(s):</strong> <?php echo h($infoComponente['projetos'] ?: '—'); ?></p>
            <p class="mb-0"><strong>Estoque físico atual (hoje):</strong> <?php echo numeroBr($estoqueAtual); ?></p>
        </section>

        <section class="table-card">
            <div class="table-toolbar">
                <div>
                    <span class="eyebrow text-primary">Projeção</span>
                    <h2>Saldo dia a dia</h2>
                    <p>De <?php echo h($hoje->format('d/m/Y')); ?> até <?php echo h($dataLimite->format('d/m/Y')); ?> • coluna amarela = hoje</p>
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
                                    if ($dia['saida'] > 0) { $classesCel[] = 'col-evento'; }
                                    if ($dia['data']->format('Y-m-d') === $hoje->format('Y-m-d')) { $classesCel[] = 'col-hoje'; }
                                ?>
                                <td class="<?php echo h(implode(' ', $classesCel)); ?>">
                                    <?php echo $dia['entrada'] > 0 ? numeroBr($dia['entrada'], 0) : '<span class="valor-vazio">—</span>'; ?>
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
                    </tbody>
                </table>
            </div>
        </section>

        <?php endif; ?>
    </main>
</body>
</html>
