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

// Aceita dd/mm/aaaa (o que o usuário digita) ou aaaa-mm-dd (formato nativo do
// <input type="date">). Devolve sempre aaaa-mm-dd (formato que o banco espera).
function parseDataFollow(string $valor): ?string
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }
    foreach (['Y-m-d', 'd/m/Y'] as $formato) {
        $obj = DateTimeImmutable::createFromFormat('!' . $formato, $valor);
        if ($obj !== false) {
            return $obj->format('Y-m-d');
        }
    }
    return null;
}

// Condição nunca é digitada — é sempre calculada comparando HOJE (data da
// consulta) com a data prevista. O que importa é quanto tempo já passou
// desde a data esperada (hoje − prevista), não o contrário:
//   - hoje ainda não chegou na prevista (ou é o próprio dia dela)  -> "Em tempo"
//   - hoje já passou da prevista, até 7 dias depois                -> "Atenção"
//   - hoje já passou da prevista há mais de 7 dias                 -> "Atrasado"
//   - sem data prevista cadastrada                                 -> sem condição (—)
// "hoje" nunca é fixo — é sempre a data em que a página é carregada, então a
// classificação de cada linha desliza sozinha conforme os dias passam.
function calcularCondicaoFollow(?string $prevista): ?string
{
    if ($prevista === null || $prevista === '') {
        return null;
    }
    $dataPrevista = DateTimeImmutable::createFromFormat('!Y-m-d', $prevista);
    if (!$dataPrevista) {
        return null;
    }
    $hoje = new DateTimeImmutable('today');
    // Positivo quando hoje é DEPOIS da prevista (atrasado); negativo/zero quando
    // hoje ainda não chegou (ou é igual) na prevista.
    $diasAtraso = (int) $dataPrevista->diff($hoje)->format('%r%a');

    if ($diasAtraso <= 0) {
        return 'em tempo';
    }
    if ($diasAtraso <= 7) {
        return 'atencao';
    }
    return 'atrasado';
}

$mensagens = [];

// ---------- Cadastro manual de embarque ----------
// Só permite vincular a um processo JÁ EXISTENTE em "processos" — não dá pra
// criar um Follow "solto". O componente/descrição NUNCA são digitados aqui:
// são sempre puxados ao vivo da tabela processos (por isso nem aparecem como
// campo de formulário — só como preview, via JS, e reconferidos no servidor).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cadastro_manual_follow') {
    $processoEscolhido = trim($_POST['processo_manual'] ?? '');

    if ($processoEscolhido === '') {
        $mensagens[] = '❌ Escolha um processo já cadastrado em Processos.';
    } else {
        // Confirma no servidor que o processo existe de verdade — nunca confia
        // só no que veio do <select> do navegador.
        $stmtCheck = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM processos WHERE processo = ?");
        mysqli_stmt_bind_param($stmtCheck, 's', $processoEscolhido);
        mysqli_stmt_execute($stmtCheck);
        $existeProcesso = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmtCheck))['total'] > 0;
        mysqli_stmt_close($stmtCheck);

        if (!$existeProcesso) {
            $mensagens[] = "❌ O processo \"$processoEscolhido\" não existe em Processos. Cadastre ele lá primeiro.";
        } else {
            $stmt = mysqli_prepare($conn, "
                INSERT INTO follow (processo, origem, destino, transit_dias, ft_dias, armador, trk, pickup, etd, eta, prevista, efetiva, requerente, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $origem = trim($_POST['origem_manual'] ?? '') ?: null;
            $destino = trim($_POST['destino_manual'] ?? '') ?: null;
            $transitDias = trim($_POST['transit_dias_manual'] ?? '');
            $transitDias = $transitDias !== '' ? (int) $transitDias : null;
            $ftDias = trim($_POST['ft_dias_manual'] ?? '');
            $ftDias = $ftDias !== '' ? (int) $ftDias : null;
            $armador = trim($_POST['armador_manual'] ?? '') ?: null;
            $trk = trim($_POST['trk_manual'] ?? '') ?: null;
            $pickup = parseDataFollow($_POST['pickup_manual'] ?? '');
            $etd = parseDataFollow($_POST['etd_manual'] ?? '');
            $eta = parseDataFollow($_POST['eta_manual'] ?? '');
            $prevista = parseDataFollow($_POST['prevista_manual'] ?? '');
            $efetiva = parseDataFollow($_POST['efetiva_manual'] ?? '');
            $requerente = trim($_POST['requerente_manual'] ?? '') ?: null;
            // Condição não é mais digitada — sempre calculada a partir da data
            // prevista (ver calcularCondicaoFollow), então nem entra no INSERT.
            // Status não é mais digitado — todo embarque novo nasce "aberto".
            // Só vira "fechado" quando confirmado na tela confirmar_entrega.php
            // (que também alimenta o estoque do MRP nesse momento).
            $status = 'aberto';

            mysqli_stmt_bind_param(
                $stmt, 'sssiisssssssss',
                $processoEscolhido, $origem, $destino, $transitDias, $ftDias, $armador, $trk,
                $pickup, $etd, $eta, $prevista, $efetiva, $requerente, $status
            );
            if (mysqli_stmt_execute($stmt)) {
                $mensagens[] = "✅ Embarque do processo \"$processoEscolhido\" cadastrado.";
            } else {
                $mensagens[] = '❌ Erro ao cadastrar: ' . mysqli_stmt_error($stmt);
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// ---------- Edição ----------
// Não permite editar "processo" (é o vínculo com Processos, não deve mudar)
// nem "status" (é sempre calculado pelo confirmar_entrega.php, nunca digitado).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar_follow') {
    $idEditar = (int) ($_POST['id_editar'] ?? 0);
    if ($idEditar <= 0) {
        $mensagens[] = '❌ Registro inválido pra edição.';
    } else {
        $stmtEditar = mysqli_prepare($conn, "
            UPDATE follow
            SET origem = ?, destino = ?, transit_dias = ?, ft_dias = ?, armador = ?, trk = ?,
                pickup = ?, etd = ?, eta = ?, prevista = ?, efetiva = ?, requerente = ?
            WHERE id = ?
        ");
        $origemEd = trim($_POST['origem_editado'] ?? '') ?: null;
        $destinoEd = trim($_POST['destino_editado'] ?? '') ?: null;
        $transitDiasEd = trim($_POST['transit_dias_editado'] ?? '');
        $transitDiasEd = $transitDiasEd !== '' ? (int) $transitDiasEd : null;
        $ftDiasEd = trim($_POST['ft_dias_editado'] ?? '');
        $ftDiasEd = $ftDiasEd !== '' ? (int) $ftDiasEd : null;
        $armadorEd = trim($_POST['armador_editado'] ?? '') ?: null;
        $trkEd = trim($_POST['trk_editado'] ?? '') ?: null;
        $pickupEd = parseDataFollow($_POST['pickup_editado'] ?? '');
        $etdEd = parseDataFollow($_POST['etd_editado'] ?? '');
        $etaEd = parseDataFollow($_POST['eta_editado'] ?? '');
        $previstaEd = parseDataFollow($_POST['prevista_editado'] ?? '');
        $efetivaEd = parseDataFollow($_POST['efetiva_editado'] ?? '');
        $requerenteEd = trim($_POST['requerente_editado'] ?? '') ?: null;
        // Condição não é mais editável — sempre calculada a partir da prevista.

        mysqli_stmt_bind_param(
            $stmtEditar, 'ssiissssssssi',
            $origemEd, $destinoEd, $transitDiasEd, $ftDiasEd, $armadorEd, $trkEd,
            $pickupEd, $etdEd, $etaEd, $previstaEd, $efetivaEd, $requerenteEd, $idEditar
        );
        if (mysqli_stmt_execute($stmtEditar)) {
            mysqli_stmt_close($stmtEditar);
            $paginaVolta = (int) ($_POST['pagina_atual'] ?? 1);
            $buscaVolta = (string) ($_POST['busca_atual'] ?? '');
            $statusFiltroVolta = (string) ($_POST['status_atual'] ?? 'todos');
            header('Location: follow.php?pagina=' . $paginaVolta . '&busca=' . urlencode($buscaVolta) . '&status=' . urlencode($statusFiltroVolta) . '&editado=1');
            exit;
        } else {
            $mensagens[] = '❌ Erro ao atualizar: ' . mysqli_stmt_error($stmtEditar);
            mysqli_stmt_close($stmtEditar);
        }
    }
}

// Lista de processos existentes, pra popular o <select> do cadastro manual —
// já traz componente/descrição junto, pro preview automático via JS.
$processosDisponiveis = [];
$resProcessos = mysqli_query($conn, "SELECT processo, codigo_componente, descricao FROM processos ORDER BY processo");
while ($linhaProc = mysqli_fetch_assoc($resProcessos)) {
    $processosDisponiveis[] = $linhaProc;
}

$porPagina = 50;
$pagina = isset($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$offset = ($pagina - 1) * $porPagina;

$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$filtroStatus = isset($_GET['status']) ? trim($_GET['status']) : 'todos'; // todos | pendente | integrado
$filtroCondicao = isset($_GET['condicao']) ? trim($_GET['condicao']) : 'todos'; // todos | em_tempo | atencao | atrasado
$editandoId = (int) ($_GET['editar'] ?? 0);

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
// Mesma regra de calcularCondicaoFollow(), só que em SQL, pra poder filtrar
// antes da paginação. DATEDIFF(CURDATE(), prevista) = hoje − prevista =
// quantos dias já passaram da data esperada (positivo = atrasado).
if ($filtroCondicao === 'em_tempo') {
    $condicoes[] = "f.prevista IS NOT NULL AND DATEDIFF(CURDATE(), f.prevista) <= 0";
} elseif ($filtroCondicao === 'atencao') {
    $condicoes[] = "f.prevista IS NOT NULL AND DATEDIFF(CURDATE(), f.prevista) BETWEEN 1 AND 7";
} elseif ($filtroCondicao === 'atrasado') {
    $condicoes[] = "f.prevista IS NOT NULL AND DATEDIFF(CURDATE(), f.prevista) > 7";
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
    SELECT f.*, p.codigo_componente, p.descricao, p.quantidade, p.status AS status_processo
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
                <p class="mb-0">IAP - Importação Avançada em Planilhas</p>
            </div>
            <nav class="d-flex flex-wrap gap-2" aria-label="Ações do sistema">
                <a class="btn btn-light btn-sm" href="follow.php">Follow</a>
                <a class="btn btn-outline-light btn-sm" href="processos.php">Processos</a>
                <a class="btn btn-outline-light btn-sm" href="pagamento.php">Pagamento</a>
                <a class="btn btn-outline-light btn-sm" href="confirmar_entrega.php">Confirmar entrega</a>
            </nav>
        </div>
    </header>

    <main class="container-fluid dashboard-container py-4">

        <?php if (isset($_GET['editado'])): ?>
            <div class="alert alert-success">✅ Embarque atualizado com sucesso.</div>
        <?php endif; ?>

        <?php if (!empty($mensagens)): ?>
            <div class="alert alert-info">
                <?php foreach ($mensagens as $msg): ?>
                    <div><?php echo h($msg); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="filter-panel mb-4">
            <details>
                <summary class="fw-bold" style="cursor:pointer;">➕ Novo embarque (cadastro manual)</summary>
                <form method="POST" class="row g-3 mt-3">
                    <input type="hidden" name="acao" value="cadastro_manual_follow">

                    <div class="col-md-4">
                        <label class="form-label">Processo *</label>
                        <select name="processo_manual" id="processo_manual" class="form-select" required onchange="atualizarPreviewProcesso()">
                            <option value="">Escolha um processo já cadastrado...</option>
                            <?php foreach ($processosDisponiveis as $p): ?>
                                <option value="<?php echo h($p['processo']); ?>" data-componente="<?php echo h($p['codigo_componente'] ?? ''); ?>" data-descricao="<?php echo h($p['descricao'] ?? ''); ?>">
                                    <?php echo h($p['processo']); ?><?php echo $p['codigo_componente'] ? ' — ' . h($p['codigo_componente']) : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($processosDisponiveis)): ?>
                            <small class="text-danger">Nenhum processo cadastrado ainda — <a href="processos.php">cadastre um processo primeiro</a>.</small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Componente (puxado do processo)</label>
                        <input type="text" id="preview_componente" class="form-control" disabled placeholder="—">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Descrição (puxado do processo)</label>
                        <input type="text" id="preview_descricao" class="form-control" disabled placeholder="—">
                    </div>

                    <div class="col-12"><hr class="my-1"></div>

                    <div class="col-md-3">
                        <label class="form-label">Origem</label>
                        <input type="text" name="origem_manual" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Destino</label>
                        <input type="text" name="destino_manual" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Transit (dias)</label>
                        <input type="text" name="transit_dias_manual" id="transit_dias_manual" class="form-control" oninput="calcularDatasFollow()">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">FT (dias)</label>
                        <input type="text" name="ft_dias_manual" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Armador</label>
                        <input type="text" name="armador_manual" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Rastreio (TRK)</label>
                        <input type="text" name="trk_manual" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Requerente</label>
                        <input type="text" name="requerente_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Pickup</label>
                        <input type="text" name="pickup_manual" class="form-control" placeholder="dd/mm/aaaa">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">ETD</label>
                        <input type="text" name="etd_manual" id="etd_manual" class="form-control" placeholder="dd/mm/aaaa" oninput="calcularDatasFollow()">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">ETA <small class="text-muted">(ETD + transit)</small></label>
                        <input type="text" id="eta_manual_display" class="form-control" readonly placeholder="—">
                        <input type="hidden" name="eta_manual" id="eta_manual">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Prevista <small class="text-muted">(ETA + 7d)</small></label>
                        <input type="text" id="prevista_manual_display" class="form-control" readonly placeholder="—">
                        <input type="hidden" name="prevista_manual" id="prevista_manual">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Efetiva <small class="text-muted">(só quando chegar)</small></label>
                        <input type="text" name="efetiva_manual" class="form-control" placeholder="dd/mm/aaaa">
                    </div>

                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Salvar</button>
                    </div>
                </form>
            </details>
        </section>

        <section class="filter-panel mb-4">
            <div class="section-heading">
                <div>
                    <span class="eyebrow text-primary">Filtros</span>
                    <h2>Buscar embarques</h2>
                </div>
            </div>
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Processo, rastreio, armador ou requerente</label>
                    <input type="text" name="busca" class="form-control" value="<?php echo h($busca); ?>" placeholder="Ex.: yp0000 ou MSC">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="todos" <?php echo $filtroStatus === 'todos' ? 'selected' : ''; ?>>Todos</option>
                        <option value="pendente" <?php echo $filtroStatus === 'pendente' ? 'selected' : ''; ?>>Pendentes de integração</option>
                        <option value="integrado" <?php echo $filtroStatus === 'integrado' ? 'selected' : ''; ?>>Já integrados ao MRP</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Condição</label>
                    <select name="condicao" class="form-select">
                        <option value="todos" <?php echo $filtroCondicao === 'todos' ? 'selected' : ''; ?>>Todas</option>
                        <option value="em_tempo" <?php echo $filtroCondicao === 'em_tempo' ? 'selected' : ''; ?>>Em tempo</option>
                        <option value="atencao" <?php echo $filtroCondicao === 'atencao' ? 'selected' : ''; ?>>Atenção</option>
                        <option value="atrasado" <?php echo $filtroCondicao === 'atrasado' ? 'selected' : ''; ?>>Atrasado</option>
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
                <table class="table mrp-table mb-0" style="min-width: 1700px;">
                    <thead>
                        <tr>
                            <th>Status</th>
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
                            <th>Condição</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="14" class="empty-state">Nenhum embarque encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <?php
                                    $emEdicao = $editandoId === (int) $r['id'];
                                    $linkVoltar = '?pagina=' . $pagina . '&busca=' . urlencode($busca) . '&status=' . urlencode($filtroStatus) . '&condicao=' . urlencode($filtroCondicao);
                                    $statusFollow = $r['status'] ?: 'aberto';
                                    $condicaoCalc = calcularCondicaoFollow($r['prevista']);
                                    $condicaoClasse = ['em tempo' => 'status-ok', 'atencao' => 'status-atencao', 'atrasado' => 'status-critico'][$condicaoCalc] ?? 'status-sem_demanda';
                                    $condicaoTexto = ['em tempo' => 'Em tempo', 'atencao' => 'Atenção', 'atrasado' => 'Atrasado'][$condicaoCalc] ?? '—';
                                    // Status unificado: se o processo estiver cancelado, isso
                                    // SOBREPÕE o status do follow (mesmo que já estivesse fechado).
                                    $statusProcessoValor = strtolower(trim((string) ($r['status_processo'] ?? 'aberto')));
                                    if ($statusProcessoValor === 'cancelado') {
                                        $statusTexto = 'Cancelado'; $statusClasseUnificada = 'status-critico';
                                    } elseif ($statusFollow === 'fechado') {
                                        $statusTexto = 'Fechado'; $statusClasseUnificada = 'status-ok';
                                    } else {
                                        $statusTexto = 'Aberto'; $statusClasseUnificada = 'status-atencao';
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <span class="status-badge <?php echo $statusClasseUnificada; ?>" title="<?php echo $statusProcessoValor === 'cancelado' ? 'Processo cancelado em Processos' : ($statusFollow === 'fechado' ? 'Fechado e integrado ao MRP em ' . h($r['integrado_em'] ?? '') : 'Fecha automaticamente ao confirmar a entrega'); ?>"><?php echo h($statusTexto); ?></span>
                                    </td>
                                    <td><span class="component-code"><?php echo h($r['processo']); ?></span></td>
                                    <td title="<?php echo h($r['descricao'] ?? ''); ?>"><?php echo h($r['codigo_componente'] ?? '—'); ?></td>

                                    <?php if ($emEdicao): ?>
                                        <td colspan="9">
                                            <form method="POST" class="row g-2 align-items-end py-2">
                                                <input type="hidden" name="acao" value="editar_follow">
                                                <input type="hidden" name="id_editar" value="<?php echo (int) $r['id']; ?>">
                                                <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                                                <input type="hidden" name="busca_atual" value="<?php echo h($busca); ?>">
                                                <input type="hidden" name="status_atual" value="<?php echo h($filtroStatus); ?>">
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Origem</label>
                                                    <input type="text" name="origem_editado" class="form-control form-control-sm" value="<?php echo h($r['origem'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Destino</label>
                                                    <input type="text" name="destino_editado" class="form-control form-control-sm" value="<?php echo h($r['destino'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">Transit</label>
                                                    <input type="text" name="transit_dias_editado" class="form-control form-control-sm" value="<?php echo h($r['transit_dias'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">FT</label>
                                                    <input type="text" name="ft_dias_editado" class="form-control form-control-sm" value="<?php echo h($r['ft_dias'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Armador</label>
                                                    <input type="text" name="armador_editado" class="form-control form-control-sm" value="<?php echo h($r['armador'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Rastreio</label>
                                                    <input type="text" name="trk_editado" class="form-control form-control-sm" value="<?php echo h($r['trk'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Requerente</label>
                                                    <input type="text" name="requerente_editado" class="form-control form-control-sm" value="<?php echo h($r['requerente'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Pickup</label>
                                                    <input type="text" name="pickup_editado" class="form-control form-control-sm" placeholder="dd/mm/aaaa" value="<?php echo $r['pickup'] ? h(dataBr($r['pickup'])) : ''; ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">ETD</label>
                                                    <input type="text" name="etd_editado" class="form-control form-control-sm" placeholder="dd/mm/aaaa" value="<?php echo $r['etd'] ? h(dataBr($r['etd'])) : ''; ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">ETA</label>
                                                    <input type="text" name="eta_editado" class="form-control form-control-sm" placeholder="dd/mm/aaaa" value="<?php echo $r['eta'] ? h(dataBr($r['eta'])) : ''; ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Prevista</label>
                                                    <input type="text" name="prevista_editado" class="form-control form-control-sm" placeholder="dd/mm/aaaa" value="<?php echo $r['prevista'] ? h(dataBr($r['prevista'])) : ''; ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Efetiva</label>
                                                    <input type="text" name="efetiva_editado" class="form-control form-control-sm" placeholder="dd/mm/aaaa" value="<?php echo $r['efetiva'] ? h(dataBr($r['efetiva'])) : ''; ?>">
                                                </div>
                                                <div class="col-md-12 d-flex gap-2 mt-1">
                                                    <button type="submit" class="btn btn-success btn-sm">Salvar</button>
                                                    <a href="<?php echo $linkVoltar; ?>" class="btn btn-outline-secondary btn-sm">Cancelar</a>
                                                </div>
                                            </form>
                                        </td>
                                        <td><span class="status-badge <?php echo $condicaoClasse; ?>"><?php echo h($condicaoTexto); ?></span></td>
                                        <td></td>
                                    <?php else: ?>
                                        <td><?php echo h($r['origem'] ?: '—'); ?></td>
                                        <td><?php echo h($r['destino'] ?: '—'); ?></td>
                                        <td><?php echo h($r['armador'] ?: '—'); ?></td>
                                        <td><?php echo h($r['trk'] ?: '—'); ?></td>
                                        <td><?php echo dataBr($r['etd']); ?></td>
                                        <td><?php echo dataBr($r['eta']); ?></td>
                                        <td><?php echo dataBr($r['prevista']); ?></td>
                                        <td><?php echo dataBr($r['efetiva']); ?></td>
                                        <td><?php echo h($r['requerente'] ?: '—'); ?></td>
                                        <td><span class="status-badge <?php echo $condicaoClasse; ?>"><?php echo h($condicaoTexto); ?></span></td>
                                        <td>
                                            <a href="<?php echo $linkVoltar; ?>&editar=<?php echo (int) $r['id']; ?>" class="btn btn-outline-secondary btn-sm">Editar</a>
                                        </td>
                                    <?php endif; ?>
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
                                    <a class="page-link" href="?pagina=<?php echo $p; ?>&busca=<?php echo urlencode($busca); ?>&status=<?php echo urlencode($filtroStatus); ?>&condicao=<?php echo urlencode($filtroCondicao); ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </section>

        <footer class="dashboard-footer">Controle de Importação — site independente do MRP, integração via processo controlado.</footer>
    </main>
    <script>
        // Ao escolher um processo no <select>, mostra o componente/descrição
        // que já estão cadastrados nele — não precisa (nem dá) pra redigitar.
        function atualizarPreviewProcesso() {
            const select = document.getElementById('processo_manual');
            const opcao = select.options[select.selectedIndex];
            document.getElementById('preview_componente').value = opcao.dataset.componente || '—';
            document.getElementById('preview_descricao').value = opcao.dataset.descricao || '—';
        }

        function formatarDataBrJs(iso) {
            const [y, m, d] = iso.split('-');
            return `${d}/${m}/${y}`;
        }

        // Converte texto "dd/mm/aaaa" num objeto Date. Devolve null se não
        // reconhecer o formato (campo vazio, incompleto, ou ainda sendo digitado).
        function parseDataBrJs(texto) {
            const partes = String(texto || '').trim().split('/');
            if (partes.length !== 3) return null;
            const [d, m, a] = partes.map(p => parseInt(p, 10));
            if (!d || !m || !a || String(a).length !== 4) return null;
            const data = new Date(a, m - 1, d);
            // Confere que a data existe de verdade (ex.: rejeita 31/02/2026)
            if (data.getFullYear() !== a || data.getMonth() !== m - 1 || data.getDate() !== d) return null;
            return data;
        }

        // ETA = ETD + Transit (dias). Prevista = ETA + 7 dias. Os dois são
        // sempre calculados — nunca digitados diretamente pelo usuário.
        function calcularDatasFollow() {
            const etdTexto = document.getElementById('etd_manual').value; // dd/mm/aaaa
            const transitValor = parseInt(document.getElementById('transit_dias_manual').value, 10) || 0;

            const campoEtaOculto = document.getElementById('eta_manual');
            const campoEtaVisivel = document.getElementById('eta_manual_display');
            const campoPrevistaOculto = document.getElementById('prevista_manual');
            const campoPrevistaVisivel = document.getElementById('prevista_manual_display');

            const etdData = parseDataBrJs(etdTexto);
            if (!etdData) {
                campoEtaOculto.value = '';
                campoEtaVisivel.value = '';
                campoPrevistaOculto.value = '';
                campoPrevistaVisivel.value = '';
                return;
            }

            const etaData = new Date(etdData);
            etaData.setDate(etaData.getDate() + transitValor);
            const etaIso = etaData.toISOString().slice(0, 10);
            campoEtaOculto.value = etaIso;
            campoEtaVisivel.value = formatarDataBrJs(etaIso);

            const previstaData = new Date(etaData);
            previstaData.setDate(previstaData.getDate() + 7);
            const previstaIso = previstaData.toISOString().slice(0, 10);
            campoPrevistaOculto.value = previstaIso;
            campoPrevistaVisivel.value = formatarDataBrJs(previstaIso);
        }
    </script>
</body>
</html>
