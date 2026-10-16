<?php
// importacao/comparativos.php
//
// Comparativo de cotações de frete entre agentes.
// Cada COMPARATIVO (tabela "comparativos") guarda o que vale pra cotação
// inteira: Carga (20´ / 40 HC / AIR), PTAX USD / EU / RMB e Processo.
// Cada AGENTE cotado é uma linha em "comparativo_itens": Agente, Data, BRL,
// Spread (%), USD, EU, RMB e se foi o Escolhido.
//
// Total R$ = BRL + (USD × PTAX USD + EU × PTAX EU + RMB × PTAX RMB) × (1 + Spread%)
// Último preço = Total do agente escolhido no comparativo ANTERIOR com a
//                mesma Carga (e o nome desse agente).
// Saving = Total − Último preço (negativo = mais barato que da última vez).
// O agente de menor Total é pintado como sugestão — mas o Escolhido pode ser
// qualquer um.
require_once 'conexao.php';

const CARGAS_COMPARATIVO = ['20´', '40 HC', 'AIR'];

function h(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function dataBr(?string $data): string
{
    if ($data === null || $data === '') {
        return '—';
    }
    $obj = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
    return $obj ? $obj->format('d/m/Y') : $data;
}

function parseDataComp(string $valor): ?string
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }
    foreach (['d/m/Y', 'Y-m-d'] as $formato) {
        $obj = DateTimeImmutable::createFromFormat('!' . $formato, $valor);
        if ($obj !== false) {
            return $obj->format('Y-m-d');
        }
    }
    return null;
}

// Aceita "1.234,56", "1234,56", "1234.56" e "5,10". Ignora R$, $, €, ¥, % e espaços.
function parseNumeroComp(string $valor): ?float
{
    $valor = trim(str_replace(['R$', '$', '€', '¥', '%', ' '], '', $valor));
    if ($valor === '') {
        return null;
    }
    if (str_contains($valor, ',')) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $valor)) {
        $valor = str_replace('.', '', $valor);
    }
    return is_numeric($valor) ? (float) $valor : null;
}

function numeroComp($valor, int $decimais = 2): string
{
    return $valor === null || $valor === '' ? '' : number_format((float) $valor, $decimais, ',', '.');
}

// PTAX/Spread: mostra até 4 casas, mas no mínimo 2 (5,10 / 5,1234 / 0,50).
function numeroFlex($valor): string
{
    if ($valor === null || $valor === '') {
        return '';
    }
    $texto = number_format((float) $valor, 4, ',', '.');
    [$inteiro, $dec] = explode(',', $texto);
    $dec = rtrim($dec, '0');
    if (strlen($dec) < 2) {
        $dec = str_pad($dec, 2, '0');
    }
    return $inteiro . ',' . $dec;
}

// Normaliza o que for digitado pra uma das cargas da lista (ou null se não bater).
function normalizarCarga(string $valor): ?string
{
    $chave = strtoupper(preg_replace('/[\s\'´`’"]/u', '', $valor));
    $mapa = ['20' => '20´', '20FT' => '20´', '20DC' => '20´', '40HC' => '40 HC', '40' => '40 HC', 'AIR' => 'AIR', 'AEREO' => 'AIR', 'AÉREO' => 'AIR'];
    return $mapa[$chave] ?? null;
}

function calcularTotalComp(array $item, array $comp): float
{
    $estrangeiro = (float) ($item['usd'] ?? 0) * (float) ($comp['ptax_usd'] ?? 0)
        + (float) ($item['eu'] ?? 0) * (float) ($comp['ptax_eu'] ?? 0)
        + (float) ($item['rmb'] ?? 0) * (float) ($comp['ptax_rmb'] ?? 0);
    return (float) ($item['brl'] ?? 0) + $estrangeiro * (1 + (float) ($item['spread'] ?? 0) / 100);
}

$mensagens = [];

// ---------- Edição inline (duplo clique) ----------
// Campos com prefixo "c_" são do comparativo (data-id = id do comparativo);
// os demais são do agente (data-id = id da linha em comparativo_itens).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'ajax_editar_campo') {
    header('Content-Type: application/json; charset=UTF-8');

    $id = (int) ($_POST['id'] ?? 0);
    $campo = (string) ($_POST['campo'] ?? '');
    $valor = trim((string) ($_POST['valor'] ?? ''));

    $camposItemNumero = ['brl', 'spread', 'usd', 'eu', 'rmb'];
    $camposCompNumero = ['c_ptax_usd', 'c_ptax_eu', 'c_ptax_rmb'];

    if ($id <= 0) {
        echo json_encode(['ok' => false, 'erro' => 'Registro inválido.']);
        exit;
    }

    if ($campo === 'agente') {
        if ($valor === '') {
            echo json_encode(['ok' => false, 'erro' => 'O agente não pode ficar vazio.']);
            exit;
        }
        $stmt = mysqli_prepare($conn, "UPDATE comparativo_itens SET agente = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $valor, $id);
        $exibido = $valor;
    } elseif ($campo === 'data') {
        $valorSalvo = $valor === '' ? null : parseDataComp($valor);
        if ($valor !== '' && $valorSalvo === null) {
            echo json_encode(['ok' => false, 'erro' => 'Data inválida. Use dd/mm/aaaa.']);
            exit;
        }
        $stmt = mysqli_prepare($conn, "UPDATE comparativo_itens SET data = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $valorSalvo, $id);
        $exibido = dataBr($valorSalvo);
    } elseif (in_array($campo, $camposItemNumero, true)) {
        $valorSalvo = $valor === '' ? null : parseNumeroComp($valor);
        if ($valor !== '' && $valorSalvo === null) {
            echo json_encode(['ok' => false, 'erro' => 'Valor numérico inválido.']);
            exit;
        }
        $stmt = mysqli_prepare($conn, "UPDATE comparativo_itens SET `$campo` = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'di', $valorSalvo, $id);
        $exibido = $campo === 'spread' ? numeroFlex($valorSalvo) . '%' : numeroComp($valorSalvo);
    } elseif (in_array($campo, $camposCompNumero, true)) {
        $valorSalvo = $valor === '' ? null : parseNumeroComp($valor);
        if ($valor !== '' && $valorSalvo === null) {
            echo json_encode(['ok' => false, 'erro' => 'Valor numérico inválido.']);
            exit;
        }
        $coluna = substr($campo, 2);
        $stmt = mysqli_prepare($conn, "UPDATE comparativos SET `$coluna` = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'di', $valorSalvo, $id);
        $exibido = numeroFlex($valorSalvo);
    } elseif ($campo === 'c_carga') {
        $carga = normalizarCarga($valor);
        if ($carga === null) {
            echo json_encode(['ok' => false, 'erro' => 'Carga inválida. Use: ' . implode(', ', CARGAS_COMPARATIVO) . '.']);
            exit;
        }
        $stmt = mysqli_prepare($conn, "UPDATE comparativos SET carga = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $carga, $id);
        $exibido = $carga;
    } elseif ($campo === 'c_processo') {
        $valorSalvo = $valor === '' ? null : $valor;
        $stmt = mysqli_prepare($conn, "UPDATE comparativos SET processo = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $valorSalvo, $id);
        $exibido = $valorSalvo ?? '—';
    } else {
        echo json_encode(['ok' => false, 'erro' => 'Campo inválido.']);
        exit;
    }

    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    echo json_encode(['ok' => true, 'exibido' => $exibido]);
    exit;
}

// ---------- Marcar / desmarcar Escolhido (AJAX) ----------
// Só 1 agente escolhido por comparativo: marcar um desmarca os outros.
// Clicar de novo no escolhido desmarca.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'ajax_escolher') {
    header('Content-Type: application/json; charset=UTF-8');
    $idItem = (int) ($_POST['id'] ?? 0);
    $stmt = mysqli_prepare($conn, "SELECT comparativo_id, escolhido FROM comparativo_itens WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $idItem);
    mysqli_stmt_execute($stmt);
    $item = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$item) {
        echo json_encode(['ok' => false, 'erro' => 'Registro não encontrado.']);
        exit;
    }
    $compId = (int) $item['comparativo_id'];
    $stmt = mysqli_prepare($conn, "UPDATE comparativo_itens SET escolhido = 0 WHERE comparativo_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $compId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if ((int) $item['escolhido'] !== 1) {
        $stmt = mysqli_prepare($conn, "UPDATE comparativo_itens SET escolhido = 1 WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $idItem);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ---------- Excluir agente (e o comparativo, se ficar vazio) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'ajax_excluir_item') {
    header('Content-Type: application/json; charset=UTF-8');
    $idItem = (int) ($_POST['id'] ?? 0);
    $stmt = mysqli_prepare($conn, "SELECT comparativo_id FROM comparativo_itens WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $idItem);
    mysqli_stmt_execute($stmt);
    $item = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$item) {
        echo json_encode(['ok' => false, 'erro' => 'Registro não encontrado.']);
        exit;
    }
    $compId = (int) $item['comparativo_id'];
    $stmt = mysqli_prepare($conn, "DELETE FROM comparativo_itens WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $idItem);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM comparativo_itens WHERE comparativo_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $compId);
    mysqli_stmt_execute($stmt);
    $restantes = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];
    mysqli_stmt_close($stmt);
    if ($restantes === 0) {
        $stmt = mysqli_prepare($conn, "DELETE FROM comparativos WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $compId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ---------- Novo comparativo / incluir agentes num existente ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'novo_comparativo') {
    $compExistente = (int) ($_POST['comparativo_existente'] ?? 0);
    $hoje = date('Y-m-d');

    $agentes = $_POST['agente'] ?? [];
    $linhas = [];
    foreach ($agentes as $i => $agente) {
        $agente = trim((string) $agente);
        if ($agente === '') {
            continue;
        }
        $linhas[] = [
            'agente' => $agente,
            'brl' => parseNumeroComp((string) ($_POST['brl'][$i] ?? '')),
            'spread' => parseNumeroComp((string) ($_POST['spread'][$i] ?? '')),
            'usd' => parseNumeroComp((string) ($_POST['usd'][$i] ?? '')),
            'eu' => parseNumeroComp((string) ($_POST['eu'][$i] ?? '')),
            'rmb' => parseNumeroComp((string) ($_POST['rmb'][$i] ?? '')),
        ];
    }

    $compId = null;
    if (empty($linhas)) {
        $mensagens[] = '❌ Informe pelo menos um agente.';
    } elseif ($compExistente > 0) {
        $stmt = mysqli_prepare($conn, "SELECT id FROM comparativos WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $compExistente);
        mysqli_stmt_execute($stmt);
        $existe = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if ($existe) {
            $compId = $compExistente;
        } else {
            $mensagens[] = '❌ Comparativo não encontrado.';
        }
    } else {
        $carga = normalizarCarga((string) ($_POST['carga'] ?? ''));
        if ($carga === null) {
            $mensagens[] = '❌ Escolha a Carga (' . implode(', ', CARGAS_COMPARATIVO) . ').';
        } else {
            $ptaxUsd = parseNumeroComp((string) ($_POST['ptax_usd'] ?? ''));
            $ptaxEu = parseNumeroComp((string) ($_POST['ptax_eu'] ?? ''));
            $ptaxRmb = parseNumeroComp((string) ($_POST['ptax_rmb'] ?? ''));
            $processo = trim((string) ($_POST['processo'] ?? '')) ?: null;
            $stmt = mysqli_prepare($conn, "INSERT INTO comparativos (carga, ptax_usd, ptax_eu, ptax_rmb, processo) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'sddds', $carga, $ptaxUsd, $ptaxEu, $ptaxRmb, $processo);
            mysqli_stmt_execute($stmt);
            $compId = (int) mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);
        }
    }

    if ($compId) {
        $stmt = mysqli_prepare($conn, "INSERT INTO comparativo_itens (comparativo_id, agente, data, brl, spread, usd, eu, rmb) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($linhas as $l) {
            mysqli_stmt_bind_param($stmt, 'issddddd', $compId, $l['agente'], $hoje, $l['brl'], $l['spread'], $l['usd'], $l['eu'], $l['rmb']);
            mysqli_stmt_execute($stmt);
        }
        mysqli_stmt_close($stmt);
        header('Location: comparativos.php?salvo=' . count($linhas));
        exit;
    }
}

// ---------- Carrega tudo (o histórico é pequeno) ----------
$comparativos = [];
$res = mysqli_query($conn, "SELECT * FROM comparativos ORDER BY id");
while ($c = mysqli_fetch_assoc($res)) {
    $c['itens'] = [];
    $comparativos[(int) $c['id']] = $c;
}
$res = mysqli_query($conn, "SELECT * FROM comparativo_itens ORDER BY id");
while ($it = mysqli_fetch_assoc($res)) {
    $cid = (int) $it['comparativo_id'];
    if (isset($comparativos[$cid])) {
        $comparativos[$cid]['itens'][] = $it;
    }
}

// Totais, escolhido e "último preço" (comparativo anterior com a mesma carga
// e com agente escolhido). Percorre em ordem de criação guardando o último
// escolhido de cada carga.
$ultimoPorCarga = [];
foreach ($comparativos as $cid => &$c) {
    $c['ultimo'] = $ultimoPorCarga[$c['carga']] ?? null;
    $menorTotal = null;
    $escolhidoAtual = null;
    foreach ($c['itens'] as &$it) {
        $it['total'] = calcularTotalComp($it, $c);
        $it['saving'] = $c['ultimo'] ? $it['total'] - $c['ultimo']['total'] : null;
        if ($menorTotal === null || $it['total'] < $menorTotal) {
            $menorTotal = $it['total'];
        }
        if ((int) $it['escolhido'] === 1) {
            $escolhidoAtual = ['total' => $it['total'], 'agente' => $it['agente']];
        }
    }
    unset($it);
    $c['menor_total'] = $menorTotal;
    if ($escolhidoAtual) {
        $ultimoPorCarga[$c['carga']] = $escolhidoAtual;
    }
}
unset($c);

// ---------- Filtros + paginação (mais recentes primeiro) ----------
$busca = trim($_GET['busca'] ?? '');
$filtroCarga = trim($_GET['carga'] ?? '');
$lista = array_reverse($comparativos, true);
$lista = array_filter($lista, function ($c) use ($busca, $filtroCarga) {
    if ($filtroCarga !== '' && $c['carga'] !== $filtroCarga) {
        return false;
    }
    if ($busca !== '') {
        $alvo = mb_strtolower(($c['processo'] ?? '') . ' ' . implode(' ', array_column($c['itens'], 'agente')), 'UTF-8');
        if (!str_contains($alvo, mb_strtolower($busca, 'UTF-8'))) {
            return false;
        }
    }
    return true;
});
$porPagina = 30;
$total = count($lista);
$totalPaginas = max(1, (int) ceil($total / $porPagina));
$pagina = min($totalPaginas, max(1, (int) ($_GET['pagina'] ?? 1)));
$listaPagina = array_slice($lista, ($pagina - 1) * $porPagina, $porPagina, true);

$agentesConhecidos = [];
$res = mysqli_query($conn, "SELECT DISTINCT agente FROM comparativo_itens ORDER BY agente");
while ($l = mysqli_fetch_assoc($res)) { $agentesConhecidos[] = $l['agente']; }

$processosConhecidos = [];
$res = mysqli_query($conn, "SELECT DISTINCT processo FROM processos WHERE processo IS NOT NULL AND TRIM(processo) <> '' ORDER BY processo DESC");
if ($res) {
    while ($l = mysqli_fetch_assoc($res)) { $processosConhecidos[] = $l['processo']; }
}

$ultimosComparativos = array_slice(array_reverse($comparativos, true), 0, 30, true);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comparativos | Controle de Importação</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/dashboard.css" rel="stylesheet">
    <style>
        #tabela-comparativos td, #tabela-comparativos th { padding: 10px 12px; vertical-align: middle; white-space: nowrap; }
        #tabela-comparativos td { font-size: .85rem; }
        #tabela-comparativos th { text-align: center; }
        #tabela-comparativos .num { text-align: right; }
        #tabela-comparativos .centro { text-align: center; }
        #tabela-comparativos tr.inicio-grupo td { border-top: 2px solid #8fa3b8; }
        #tabela-comparativos td.grupo { background: #f6f8fb; text-align: center; }
        #tabela-comparativos td.sugestao { background: #fff3a6; font-weight: 700; }
        #tabela-comparativos td.escolhido-sim { background: #92d050; }
        #tabela-comparativos .moeda { color: #66788a; margin-right: 4px; }
        #tabela-comparativos .ultimo-agente { display: block; font-size: .75rem; color: #3c4c5c; font-weight: 700; }
        #tabela-comparativos .saving-neg { color: #b42318; }
        #tabela-comparativos .saving-pos { color: #1d7a3e; }
        .btn-escolher { border: 1px solid #b9c6d3; background: #fff; border-radius: 4px; width: 34px; height: 26px; font-weight: 700; cursor: pointer; }
        .btn-escolher.marcado { background: #1d7a3e; border-color: #1d7a3e; color: #fff; }
        .btn-remover-linha { border: none; background: none; color: #c53535; font-size: 1.05rem; cursor: pointer; line-height: 1; }
        .legenda span { display: inline-block; width: 14px; height: 14px; vertical-align: middle; border-radius: 3px; margin: 0 4px 0 12px; }
        table.form-agentes td { padding: 4px; }
        table.form-agentes th { font-size: .78rem; color: #3c4c5c; padding: 4px; }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="container-fluid dashboard-container d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="eyebrow">Controle de Importação • Comparativos</span>
                <h1>Comparativos</h1>
                <p class="mb-0"><?php echo count($comparativos); ?> comparativo(s) no histórico</p>
            </div>
            <nav class="d-flex flex-wrap gap-2" aria-label="Ações do sistema">
                <a class="btn btn-outline-light btn-sm" href="follow.php">Follow</a>
                <a class="btn btn-outline-light btn-sm" href="processos.php">Processos</a>
                <a class="btn btn-outline-light btn-sm" href="pagamento.php">Pagamento</a>
                <a class="btn btn-outline-light btn-sm" href="confirmar_entrega.php">Confirmar entrega</a>
                <a class="btn btn-light btn-sm" href="comparativos.php">Comparativos</a>
                <a class="btn btn-outline-light btn-sm" href="commercial_invoice.php">📄 Commercial Invoice</a>
                <a class="btn btn-outline-light btn-sm" href="packing_list.php">📦 Packing List</a>
            </nav>
        </div>
    </header>

    <main class="container-fluid dashboard-container py-4">

        <?php if (isset($_GET['salvo'])): ?>
            <div class="alert alert-success">✅ <?php echo (int) $_GET['salvo']; ?> agente(s) salvo(s) no comparativo.</div>
        <?php endif; ?>
        <?php if (!empty($mensagens)): ?>
            <div class="alert alert-info">
                <?php foreach ($mensagens as $msg): ?><div><?php echo h($msg); ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="filter-panel mb-4">
            <details <?php echo empty($comparativos) ? 'open' : ''; ?>>
                <summary class="fw-bold" style="cursor:pointer;">➕ Novo comparativo / incluir agente</summary>
                <form method="POST" class="row g-3 mt-3" id="form-novo-comparativo">
                    <input type="hidden" name="acao" value="novo_comparativo">
                    <div class="col-md-4">
                        <label class="form-label">Incluir em</label>
                        <select name="comparativo_existente" id="comparativo_existente" class="form-select" onchange="alternarComparativoExistente()">
                            <option value="">➕ Novo comparativo</option>
                            <?php foreach ($ultimosComparativos as $cid => $c): ?>
                                <option value="<?php echo $cid; ?>">#<?php echo $cid; ?> — <?php echo h($c['carga']); ?> — <?php echo h(implode(', ', array_column($c['itens'], 'agente'))); ?><?php echo $c['processo'] ? ' — ' . h($c['processo']) : ''; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8"></div>

                    <div class="col-md-2 campo-cabecalho">
                        <label class="form-label">Carga *</label>
                        <select name="carga" id="carga_nova" class="form-select" required>
                            <option value="">Selecione...</option>
                            <?php foreach (CARGAS_COMPARATIVO as $cg): ?>
                                <option value="<?php echo h($cg); ?>"><?php echo h($cg); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 campo-cabecalho">
                        <label class="form-label">PTAX USD</label>
                        <input type="text" name="ptax_usd" id="ptax_usd" class="form-control" placeholder="5,10" oninput="recalcularPrevias()">
                    </div>
                    <div class="col-md-2 campo-cabecalho">
                        <label class="form-label">PTAX EU</label>
                        <input type="text" name="ptax_eu" id="ptax_eu" class="form-control" placeholder="5,30" oninput="recalcularPrevias()">
                    </div>
                    <div class="col-md-2 campo-cabecalho">
                        <label class="form-label">PTAX RMB</label>
                        <input type="text" name="ptax_rmb" id="ptax_rmb" class="form-control" placeholder="1,20" oninput="recalcularPrevias()">
                    </div>
                    <div class="col-md-2 campo-cabecalho">
                        <label class="form-label">Processo <small class="text-muted">(pode ser depois)</small></label>
                        <input type="text" name="processo" class="form-control" list="lista-processos" autocomplete="off">
                    </div>

                    <div class="col-12">
                        <table class="form-agentes w-100">
                            <thead>
                                <tr>
                                    <th style="width:22%">Agente *</th><th>BRL</th><th>Spread (%)</th><th>USD</th><th>EU</th><th>RMB</th>
                                    <th class="campo-cabecalho">Total R$ (prévia)</th><th></th>
                                </tr>
                            </thead>
                            <tbody id="corpo-agentes"></tbody>
                        </table>
                        <button type="button" class="btn btn-outline-secondary btn-sm mt-2" onclick="adicionarAgente()">➕ Adicionar agente</button>
                        <small class="text-muted ms-2">A Data é preenchida com hoje e pode ser alterada depois com duplo clique.</small>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Salvar</button>
                    </div>
                </form>
                <datalist id="lista-agentes">
                    <?php foreach ($agentesConhecidos as $ag): ?><option value="<?php echo h($ag); ?>"><?php endforeach; ?>
                </datalist>
                <datalist id="lista-processos">
                    <?php foreach ($processosConhecidos as $pr): ?><option value="<?php echo h($pr); ?>"><?php endforeach; ?>
                </datalist>
            </details>
        </section>

        <section class="filter-panel mb-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Buscar por agente ou processo</label>
                    <input type="text" name="busca" class="form-control" value="<?php echo h($busca); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Carga</label>
                    <select name="carga" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach (CARGAS_COMPARATIVO as $cg): ?>
                            <option value="<?php echo h($cg); ?>" <?php echo $filtroCarga === $cg ? 'selected' : ''; ?>><?php echo h($cg); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Buscar</button>
                </div>
                <div class="col-md-2">
                    <a href="comparativos.php" class="btn btn-outline-secondary w-100">Limpar filtros</a>
                </div>
            </form>
        </section>

        <section class="table-card">
            <div class="table-toolbar">
                <div>
                    <span class="eyebrow text-primary">Resultado</span>
                    <h2>Comparativos</h2>
                    <p class="legenda mb-0"><?php echo $total; ?> encontrado(s)
                        <span style="background:#fff3a6"></span>sugestão (menor total)
                        <span style="background:#92d050"></span>escolhido
                    </p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table mrp-table mb-0" id="tabela-comparativos" style="min-width: 1900px;">
                    <thead>
                        <tr>
                            <th>Agente</th><th>Data</th><th>BRL</th><th>Spread</th><th>USD</th><th>EU</th><th>RMB</th>
                            <th>PTAX USD</th><th>PTAX EU</th><th>PTAX RMB</th><th>Carga</th>
                            <th>Total - R$</th><th>Último preço</th><th>Saving</th><th>Processo</th><th>Escolhido</th><th>Excluir</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($listaPagina)): ?>
                            <tr><td colspan="17" class="empty-state">Nenhum comparativo ainda.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($listaPagina as $cid => $c): ?>
                            <?php $qtd = max(1, count($c['itens'])); ?>
                            <?php foreach ($c['itens'] as $i => $it): ?>
                                <?php
                                    $idItem = (int) $it['id'];
                                    $ehSugestao = count($c['itens']) > 1 && abs($it['total'] - $c['menor_total']) < 0.005;
                                    $ehEscolhido = (int) $it['escolhido'] === 1;
                                ?>
                                <tr id="item-<?php echo $idItem; ?>" class="<?php echo $i === 0 ? 'inicio-grupo' : ''; ?>">
                                    <td class="celula-editavel fw-bold" data-id="<?php echo $idItem; ?>" data-campo="agente" data-valor-bruto="<?php echo h($it['agente']); ?>"><?php echo h($it['agente']); ?></td>
                                    <td class="celula-editavel centro" data-id="<?php echo $idItem; ?>" data-campo="data" data-valor-bruto="<?php echo $it['data'] ? h(dataBr($it['data'])) : ''; ?>"><?php echo dataBr($it['data']); ?></td>
                                    <td class="celula-editavel num" data-id="<?php echo $idItem; ?>" data-campo="brl" data-valor-bruto="<?php echo h(numeroComp($it['brl'])); ?>"><?php echo $it['brl'] !== null ? '<span class="moeda">R$</span>' . numeroComp($it['brl']) : ''; ?></td>
                                    <td class="celula-editavel num" data-id="<?php echo $idItem; ?>" data-campo="spread" data-valor-bruto="<?php echo h(numeroFlex($it['spread'])); ?>"><?php echo $it['spread'] !== null ? numeroFlex($it['spread']) . '%' : ''; ?></td>
                                    <td class="celula-editavel num" data-id="<?php echo $idItem; ?>" data-campo="usd" data-valor-bruto="<?php echo h(numeroComp($it['usd'])); ?>"><?php echo $it['usd'] !== null ? '<span class="moeda">$</span>' . numeroComp($it['usd']) : ''; ?></td>
                                    <td class="celula-editavel num" data-id="<?php echo $idItem; ?>" data-campo="eu" data-valor-bruto="<?php echo h(numeroComp($it['eu'])); ?>"><?php echo $it['eu'] !== null ? '<span class="moeda">€</span>' . numeroComp($it['eu']) : ''; ?></td>
                                    <td class="celula-editavel num" data-id="<?php echo $idItem; ?>" data-campo="rmb" data-valor-bruto="<?php echo h(numeroComp($it['rmb'])); ?>"><?php echo $it['rmb'] !== null ? '<span class="moeda">¥</span>' . numeroComp($it['rmb']) : ''; ?></td>
                                    <?php if ($i === 0): ?>
                                        <td rowspan="<?php echo $qtd; ?>" class="celula-editavel grupo" data-id="<?php echo $cid; ?>" data-campo="c_ptax_usd" data-valor-bruto="<?php echo h(numeroFlex($c['ptax_usd'])); ?>"><?php echo $c['ptax_usd'] !== null ? 'R$ ' . numeroFlex($c['ptax_usd']) : '—'; ?></td>
                                        <td rowspan="<?php echo $qtd; ?>" class="celula-editavel grupo" data-id="<?php echo $cid; ?>" data-campo="c_ptax_eu" data-valor-bruto="<?php echo h(numeroFlex($c['ptax_eu'])); ?>"><?php echo $c['ptax_eu'] !== null ? 'R$ ' . numeroFlex($c['ptax_eu']) : '—'; ?></td>
                                        <td rowspan="<?php echo $qtd; ?>" class="celula-editavel grupo" data-id="<?php echo $cid; ?>" data-campo="c_ptax_rmb" data-valor-bruto="<?php echo h(numeroFlex($c['ptax_rmb'])); ?>"><?php echo $c['ptax_rmb'] !== null ? 'R$ ' . numeroFlex($c['ptax_rmb']) : '—'; ?></td>
                                        <td rowspan="<?php echo $qtd; ?>" class="celula-editavel grupo fw-bold" data-id="<?php echo $cid; ?>" data-campo="c_carga" data-valor-bruto="<?php echo h($c['carga']); ?>" title="Use: <?php echo h(implode(', ', CARGAS_COMPARATIVO)); ?>"><?php echo h($c['carga']); ?></td>
                                    <?php endif; ?>
                                    <td class="num <?php echo $ehSugestao ? 'sugestao' : ''; ?>"><span class="moeda">R$</span><?php echo numeroComp($it['total']); ?></td>
                                    <?php if ($i === 0): ?>
                                        <td rowspan="<?php echo $qtd; ?>" class="grupo">
                                            <?php if ($c['ultimo']): ?>
                                                R$ <?php echo numeroComp($c['ultimo']['total']); ?>
                                                <span class="ultimo-agente"><?php echo h($c['ultimo']['agente']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted" title="Nenhum comparativo anterior com essa carga e agente escolhido">—</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="num <?php echo $ehSugestao ? 'sugestao' : ''; ?>">
                                        <?php if ($it['saving'] !== null): ?>
                                            <span class="<?php echo $it['saving'] < 0 ? 'saving-neg' : 'saving-pos'; ?>"><?php echo $it['saving'] < 0 ? '-' : ''; ?>R$ <?php echo numeroComp(abs($it['saving'])); ?></span>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                    <?php if ($i === 0): ?>
                                        <td rowspan="<?php echo $qtd; ?>" class="celula-editavel grupo" data-id="<?php echo $cid; ?>" data-campo="c_processo" data-valor-bruto="<?php echo h($c['processo'] ?? ''); ?>"><?php echo h($c['processo'] ?: '—'); ?></td>
                                    <?php endif; ?>
                                    <td class="centro <?php echo $ehEscolhido ? 'escolhido-sim' : ''; ?>">
                                        <button type="button" class="btn-escolher <?php echo $ehEscolhido ? 'marcado' : ''; ?>" onclick="escolherAgente(<?php echo $idItem; ?>)" title="<?php echo $ehEscolhido ? 'Escolhido — clique pra desmarcar' : 'Marcar como escolhido'; ?>"><?php echo $ehEscolhido ? 'X' : ''; ?></button>
                                    </td>
                                    <td class="centro">
                                        <button type="button" class="btn-remover-linha" onclick="excluirAgente(<?php echo $idItem; ?>)" title="Excluir este agente">✕</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
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
                                    <a class="page-link" href="?pagina=<?php echo $p; ?>&busca=<?php echo urlencode($busca); ?>&carga=<?php echo urlencode($filtroCarga); ?>"><?php echo $p; ?></a>
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
        function parseNumJs(texto) {
            let t = String(texto || '').replace(/R\$|\$|€|¥|%|\s/g, '');
            if (!t) return 0;
            if (t.includes(',')) t = t.replace(/\./g, '').replace(',', '.');
            else if (/^-?\d{1,3}(\.\d{3})+$/.test(t)) t = t.replace(/\./g, '');
            const n = parseFloat(t);
            return isNaN(n) ? 0 : n;
        }
        function fmtBr(n) {
            return n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function adicionarAgente() {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><input type="text" name="agente[]" class="form-control form-control-sm" list="lista-agentes" autocomplete="off"></td>
                <td><input type="text" name="brl[]" class="form-control form-control-sm" oninput="recalcularPrevias()"></td>
                <td><input type="text" name="spread[]" class="form-control form-control-sm" placeholder="2 = 2%" oninput="recalcularPrevias()"></td>
                <td><input type="text" name="usd[]" class="form-control form-control-sm" oninput="recalcularPrevias()"></td>
                <td><input type="text" name="eu[]" class="form-control form-control-sm" oninput="recalcularPrevias()"></td>
                <td><input type="text" name="rmb[]" class="form-control form-control-sm" oninput="recalcularPrevias()"></td>
                <td class="campo-cabecalho"><input type="text" class="form-control form-control-sm previa-total" readonly placeholder="—"></td>
                <td><button type="button" class="btn-remover-linha" onclick="this.closest('tr').remove()" title="Remover">✕</button></td>
            `;
            document.getElementById('corpo-agentes').appendChild(tr);
            alternarComparativoExistente();
        }

        // Prévia do Total de cada agente no formulário (mesma fórmula do servidor)
        function recalcularPrevias() {
            const ptaxUsd = parseNumJs(document.getElementById('ptax_usd').value);
            const ptaxEu = parseNumJs(document.getElementById('ptax_eu').value);
            const ptaxRmb = parseNumJs(document.getElementById('ptax_rmb').value);
            document.querySelectorAll('#corpo-agentes tr').forEach((tr) => {
                const v = (n) => parseNumJs(tr.querySelector(`[name="${n}[]"]`).value);
                const total = v('brl') + (v('usd') * ptaxUsd + v('eu') * ptaxEu + v('rmb') * ptaxRmb) * (1 + v('spread') / 100);
                tr.querySelector('.previa-total').value = 'R$ ' + fmtBr(total);
            });
        }

        // "Incluir em" um comparativo existente: Carga/PTAX/Processo já são do
        // comparativo escolhido, então esses campos somem do formulário.
        function alternarComparativoExistente() {
            const existente = document.getElementById('comparativo_existente').value !== '';
            document.querySelectorAll('#form-novo-comparativo .campo-cabecalho').forEach((el) => {
                el.style.display = existente ? 'none' : '';
            });
            document.getElementById('carga_nova').required = !existente;
        }

        function postar(dados) {
            return fetch('comparativos.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(dados).toString(),
            }).then((r) => r.json());
        }

        // Recarrega mantendo a posição da rolagem (Total, Último preço e
        // Saving dependem de vários campos, então é mais seguro recalcular tudo).
        function recarregarMantendoScroll() {
            try { sessionStorage.setItem('comparativos_scroll', String(window.scrollY)); } catch (e) {}
            window.location.reload();
        }
        window.addEventListener('DOMContentLoaded', () => {
            let salvo = null;
            try { salvo = sessionStorage.getItem('comparativos_scroll'); sessionStorage.removeItem('comparativos_scroll'); } catch (e) {}
            if (salvo !== null) window.scrollTo(0, parseInt(salvo, 10) || 0);
        });

        function escolherAgente(id) {
            postar({ acao: 'ajax_escolher', id: id })
                .then((json) => { if (!json.ok) { alert(json.erro || 'Não foi possível atualizar.'); return; } recarregarMantendoScroll(); })
                .catch(() => alert('Erro de conexão. Tente de novo.'));
        }

        function excluirAgente(id) {
            if (!confirm('Excluir este agente do comparativo?')) return;
            postar({ acao: 'ajax_excluir_item', id: id })
                .then((json) => { if (!json.ok) { alert(json.erro || 'Não foi possível excluir.'); return; } recarregarMantendoScroll(); })
                .catch(() => alert('Erro de conexão. Tente de novo.'));
        }

        // Depois de editar qualquer célula com duplo clique, recalcula a tela
        document.addEventListener('inline-edit:salvo', recarregarMantendoScroll);

        // Começa com 4 linhas de agente
        for (let i = 0; i < 4; i++) adicionarAgente();
    </script>
    <script>
        window.INLINE_EDIT_ENDPOINT = 'comparativos.php';
        window.INLINE_EDIT_ACAO = 'ajax_editar_campo';
    </script>
    <script src="assets/inline-edit.js"></script>
</body>
</html>
