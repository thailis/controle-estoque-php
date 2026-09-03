<?php
// importacao/follow.php
//
// Listagem geral de rastreio logístico (follow) — mostra TUDO, pendente e já
// integrado ao MRP, diferente do confirmar_entrega.php (que só mostra pendências).
// Layout segue o mesmo padrão visual das telas do MRP: cabeçalho com gradiente,
// barra de busca/filtro, tabela paginada.
require_once 'conexao.php';

function h(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function numeroBr($valor, int $decimais = 0): string
{
    return $valor === null ? '—' : number_format((float) $valor, $decimais, ',', '.');
}

function dataBr(?string $data): string
{
    if ($data === null || $data === '') {
        return '—';
    }
    $obj = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
    return $obj ? $obj->format('d/m/Y') : $data;
}

$porPagina = 50;
$pagina = isset($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$offset = ($pagina - 1) * $porPagina;

$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$filtroStatus = isset($_GET['status']) ? trim($_GET['status']) : 'todos'; // todos | pendente | integrado

$condicoes = [];
$params = [];
$tipos = '';

if ($busca !== '') {
    $condicoes[] = "(f.processo LIKE ? OR f.trk LIKE ? OR f.armador LIKE ? OR f.requerente LIKE ?)";
    $like = '%' . $busca . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $tipos .= 'ssss';
}
if ($filtroStatus === 'pendente') {
    $condicoes[] = "f.integrado_mrp = 0";
} elseif ($filtroStatus === 'integrado') {
    $condicoes[] = "f.integrado_mrp = 1";
}

$where = !empty($condicoes) ? ('WHERE ' . implode(' AND ', $condicoes)) : '';

// Total pra paginação
$sqlTotal = "SELECT COUNT(*) AS total FROM follow f $where";
$stmtTotal = mysqli_prepare($conn, $sqlTotal);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmtTotal, $tipos, ...$params);
}
mysqli_stmt_execute($stmtTotal);
$total = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmtTotal))['total'];
mysqli_stmt_close($stmtTotal);
$totalPaginas = max(1, (int) ceil($total / $porPagina));

// Total de pendentes, pra mostrar no cabeçalho independente do filtro aplicado
$totalPendentes = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM follow WHERE integrado_mrp = 0"))['total'];

$sql = "
    SELECT f.*, p.codigo_componente, p.descricao, p.quantidade
    FROM follow f
    LEFT JOIN processos p ON p.processo = f.processo
    $where
    ORDER BY f.criado_em DESC
    LIMIT ? OFFSET ?
";
$stmt = mysqli_prepare($conn, $sql);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $tipos . 'ii', ...array_merge($params, [$porPagina, $offset]));
} else {
    mysqli_stmt_bind_param($stmt, 'ii', $porPagina, $offset);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$rows = [];
while ($row = mysqli_fetch_assoc($result)) {
    $rows[] = $row;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Follow | Controle de Importação</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/dashboard.css" rel="stylesheet">
</head>
<body>
    <header class="topbar">
        <div class="container-fluid dashboard-container d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="eyebrow">Controle de Importação • Rastreio logístico</span>
                <h1>Follow</h1>
                <p class="mb-0"><?php echo numeroBr($total); ?> embarque(s) na base • <?php echo numeroBr($totalPendentes); ?> pendente(s) de integração com o MRP</p>
            </div>
            <nav class="d-flex flex-wrap gap-2" aria-label="Ações do sistema">
                <a class="btn btn-light btn-sm" href="follow.php">Follow</a>
                <a class="btn btn-outline-light btn-sm" href="processos.php">Processos</a>
                <a class="btn btn-outline-light btn-sm" href="#">Pagamento</a>
                <a class="btn btn-outline-light btn-sm" href="confirmar_entrega.php">Confirmar entrega</a>
            </nav>
        </div>
    </header>

    <main class="container-fluid dashboard-container py-4">

        <section class="filter-panel mb-4">
            <div class="section-heading">
                <div>
                    <span class="eyebrow text-primary">Filtros</span>
                    <h2>Buscar embarques</h2>
                </div>
            </div>
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">Processo, rastreio, armador ou requerente</label>
                    <input type="text" name="busca" class="form-control" value="<?php echo h($busca); ?>" placeholder="Ex.: yp0000 ou MSC">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="todos" <?php echo $filtroStatus === 'todos' ? 'selected' : ''; ?>>Todos</option>
                        <option value="pendente" <?php echo $filtroStatus === 'pendente' ? 'selected' : ''; ?>>Pendentes de integração</option>
                        <option value="integrado" <?php echo $filtroStatus === 'integrado' ? 'selected' : ''; ?>>Já integrados ao MRP</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100">Buscar</button>
                    <a href="follow.php" class="btn btn-outline-secondary">Limpar</a>
                </div>
            </form>
        </section>

        <section class="table-card">
            <div class="table-toolbar">
                <div>
                    <span class="eyebrow text-primary">Resultado</span>
                    <h2>Embarques</h2>
                    <p><?php echo numeroBr($total); ?> encontrado(s)</p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table mrp-table mb-0">
                    <thead>
                        <tr>
                            <th>Processo</th>
                            <th>Componente</th>
                            <th>Origem</th>
                            <th>Destino</th>
                            <th>Armador</th>
                            <th>Rastreio (TRK)</th>
                            <th>ETD</th>
                            <th>ETA</th>
                            <th>Prevista</th>
                            <th>Efetiva</th>
                            <th>Requerente</th>
                            <th>Status MRP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="12" class="empty-state">Nenhum embarque encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td><span class="component-code"><?php echo h($r['processo']); ?></span></td>
                                    <td title="<?php echo h($r['descricao'] ?? ''); ?>"><?php echo h($r['codigo_componente'] ?? '—'); ?></td>
                                    <td><?php echo h($r['origem'] ?: '—'); ?></td>
                                    <td><?php echo h($r['destino'] ?: '—'); ?></td>
                                    <td><?php echo h($r['armador'] ?: '—'); ?></td>
                                    <td><?php echo h($r['trk'] ?: '—'); ?></td>
                                    <td><?php echo dataBr($r['etd']); ?></td>
                                    <td><?php echo dataBr($r['eta']); ?></td>
                                    <td><?php echo dataBr($r['prevista']); ?></td>
                                    <td><?php echo dataBr($r['efetiva']); ?></td>
                                    <td><?php echo h($r['requerente'] ?: '—'); ?></td>
                                    <td>
                                        <?php if ((int) $r['integrado_mrp'] === 1): ?>
                                            <span class="status-badge status-ok" title="Integrado em <?php echo h($r['integrado_em'] ?? ''); ?>">Integrado</span>
                                        <?php else: ?>
                                            <span class="status-badge status-atencao">Pendente</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPaginas > 1): ?>
                <div class="pagination-bar">
                    <span>Página <?php echo $pagina; ?> de <?php echo $totalPaginas; ?></span>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
                                <li class="page-item <?php echo $p === $pagina ? 'active' : ''; ?>">
                                    <a class="page-link" href="?pagina=<?php echo $p; ?>&busca=<?php echo urlencode($busca); ?>&status=<?php echo urlencode($filtroStatus); ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </section>

        <footer class="dashboard-footer">Controle de Importação — site independente do MRP, integração via processo controlado.</footer>
    </main>
</body>
</html>
