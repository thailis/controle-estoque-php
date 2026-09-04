<?php
// importacao/processos.php
require_once 'conexao.php';

function h(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function numeroBr($valor, int $decimais = 2): string
{
    return $valor === null || $valor === '' ? '—' : number_format((float) $valor, $decimais, ',', '.');
}

function dataBr(?string $data): string
{
    if ($data === null || $data === '') {
        return '—';
    }
    $obj = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
    return $obj ? $obj->format('d/m/Y') : $data;
}

// Aceita dd/mm/aaaa (formato mais comum vindo de Excel-BR) ou aaaa-mm-dd (ISO).
// Devolve sempre no formato aaaa-mm-dd (o que o banco espera), ou null se não reconhecer.
function parseDataProcessos(string $valor): ?string
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }
    $formatos = ['d/m/Y', 'Y-m-d'];
    foreach ($formatos as $formato) {
        $obj = DateTimeImmutable::createFromFormat('!' . $formato, $valor);
        if ($obj !== false) {
            return $obj->format('Y-m-d');
        }
    }
    return null;
}

// Mesma lógica de parse numérico BR usada no resto do sistema (aceita "1.234,56" e "1234.56").
function parseNumeroBrProcessos(string $valor): ?float
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }
    if (str_contains($valor, ',')) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    } elseif (substr_count($valor, '.') >= 1) {
        $partes = explode('.', $valor);
        $pareceMilhar = true;
        for ($i = 1; $i < count($partes); $i++) {
            if (strlen($partes[$i]) !== 3 || !ctype_digit($partes[$i])) {
                $pareceMilhar = false;
                break;
            }
        }
        if ($pareceMilhar) {
            $valor = str_replace('.', '', $valor);
        }
    }
    return is_numeric($valor) ? (float) $valor : null;
}

function normalizarTextoProcessos(string $texto): string
{
    $texto = mb_strtolower(trim($texto), 'UTF-8');
    $mapa = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', ' ' => '_', '-' => '_',
    ];
    return strtr($texto, $mapa);
}

$mensagens = [];
$importados = 0;
$erros = 0;

// ---------- Importação de CSV ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo_csv'])) {
    if ($_FILES['arquivo_csv']['error'] !== UPLOAD_ERR_OK) {
        $mensagens[] = '❌ Falha no envio do arquivo.';
    } else {
        $caminho = $_FILES['arquivo_csv']['tmp_name'];
        $handle = fopen($caminho, 'r');
        if ($handle) {
            if (!empty($_POST['limpar_tabela'])) {
                mysqli_query($conn, 'TRUNCATE TABLE processos');
            }

            $primeiraLinha = fgets($handle);
            $separador = substr_count($primeiraLinha, ';') >= substr_count($primeiraLinha, ',') ? ';' : ',';
            rewind($handle);

            $cabecalhoOriginal = fgetcsv($handle, 0, $separador);
            if ($cabecalhoOriginal === false) {
                $mensagens[] = '❌ Não consegui ler o cabeçalho do arquivo.';
            } else {
                $cabecalho = array_map('normalizarTextoProcessos', $cabecalhoOriginal);

                $mapaColunas = [
                    'processo' => ['processo'],
                    'solicitacao' => ['solicitacao'],
                    'categoria' => ['categoria'],
                    'planta' => ['planta'],
                    'po' => ['po'],
                    'modal' => ['modal'],
                    'codigo_componente' => ['codigo_componente', 'componente'],
                    'descricao' => ['descricao'],
                    'quantidade' => ['quantidade'],
                    'hscode' => ['hscode'],
                    'ncm' => ['ncm'],
                    'fornecedor' => ['fornecedor'],
                    'preco' => ['preco'],
                    'total' => ['total'],
                    'moeda' => ['moeda'],
                    'tipo' => ['tipo'],
                    'ffw' => ['ffw'],
                    'obs' => ['obs', 'observacao', 'observacoes'],
                ];

                $indices = [];
                foreach ($mapaColunas as $campo => $candidatos) {
                    $indices[$campo] = null;
                    foreach ($candidatos as $c) {
                        $pos = array_search($c, $cabecalho, true);
                        if ($pos !== false) { $indices[$campo] = $pos; break; }
                    }
                }

                if ($indices['processo'] === null) {
                    $mensagens[] = "❌ Não encontrei a coluna do processo. Use 'processo' no cabeçalho.";
                } else {
                    $lote = [];
                    $flushLote = function () use ($conn, &$lote, &$importados, &$erros, &$mensagens) {
                        if (empty($lote)) return;
                        $campos = ['processo', 'status', 'solicitacao', 'categoria', 'planta', 'po', 'modal', 'codigo_componente', 'descricao', 'quantidade', 'hscode', 'ncm', 'fornecedor', 'preco', 'total', 'moeda', 'tipo', 'ffw', 'obs'];
                        $linhasSql = [];
                        $todosValores = [];
                        foreach ($lote as $linhaLote) {
                            $linhasSql[] = '(' . implode(',', array_fill(0, count($campos), '?')) . ')';
                            foreach ($linhaLote as $v) { $todosValores[] = $v; }
                        }
                        $sql = 'INSERT INTO processos (' . implode(',', $campos) . ') VALUES ' . implode(', ', $linhasSql);
                        $stmt = mysqli_prepare($conn, $sql);
                        $tipos = str_repeat('s', count($todosValores));
                        mysqli_stmt_bind_param($stmt, $tipos, ...$todosValores);
                        if (mysqli_stmt_execute($stmt)) {
                            $importados += count($lote);
                        } else {
                            $erros += count($lote);
                            $mensagens[] = '❌ Erro ao gravar lote: ' . mysqli_stmt_error($stmt);
                        }
                        mysqli_stmt_close($stmt);
                        $lote = [];
                    };

                    while (($linha = fgetcsv($handle, 0, $separador)) !== false) {
                        if (count(array_filter($linha, fn($v) => trim((string) $v) !== '')) === 0) {
                            continue;
                        }
                        $get = fn($campo) => $indices[$campo] !== null ? trim((string) ($linha[$indices[$campo]] ?? '')) : '';

                        $processo = $get('processo');
                        if ($processo === '') { continue; }

                        $quantidade = parseNumeroBrProcessos($get('quantidade'));
                        $preco = parseNumeroBrProcessos($get('preco'));
                        $total = parseNumeroBrProcessos($get('total'));
                        $solicitacao = parseDataProcessos($get('solicitacao'));

                        $lote[] = [
                            $processo,
                            'aberto', // status nunca vem do CSV — só o confirmar_entrega.php fecha ele
                            $solicitacao,
                            $get('categoria') ?: null,
                            $get('planta') ?: null,
                            $get('po') ?: null,
                            $get('modal') ?: null,
                            $get('codigo_componente') ?: null,
                            $get('descricao') ?: null,
                            $quantidade,
                            $get('hscode') ?: null,
                            $get('ncm') ?: null,
                            $get('fornecedor') ?: null,
                            $preco,
                            $total,
                            $get('moeda') ?: null,
                            $get('tipo') ?: null,
                            $get('ffw') ?: null,
                            $get('obs') ?: null,
                        ];

                        if (count($lote) >= 200) {
                            $flushLote();
                        }
                    }
                    $flushLote();

                    if ($importados > 0) {
                        $mensagens[] = "✅ $importados linha(s) importada(s) com sucesso.";
                    }
                    if ($erros > 0) {
                        $mensagens[] = "⚠️ $erros linha(s) com erro.";
                    }
                }
            }
            fclose($handle);
        }
    }
}

// ---------- Cadastro manual ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cadastro_manual') {
    $processo = trim($_POST['processo_manual'] ?? '');
    if ($processo === '') {
        $mensagens[] = '❌ Informe o número do processo.';
    } else {
        $stmt = mysqli_prepare($conn, "
            INSERT INTO processos (processo, status, solicitacao, categoria, planta, po, modal, codigo_componente, descricao, quantidade, hscode, ncm, fornecedor, preco, total, moeda, tipo, ffw, obs)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $status = 'aberto'; // nunca digitado — só o confirmar_entrega.php fecha (vira "finalizado")
        $solicitacao = parseDataProcessos(trim($_POST['solicitacao_manual'] ?? ''));
        $categoria = trim($_POST['categoria_manual'] ?? '') ?: null;
        $planta = trim($_POST['planta_manual'] ?? '') ?: null;
        $po = trim($_POST['po_manual'] ?? '') ?: null;
        $modal = trim($_POST['modal_manual'] ?? '') ?: null;
        $codigoComponente = trim($_POST['codigo_componente_manual'] ?? '') ?: null;
        $descricao = trim($_POST['descricao_manual'] ?? '') ?: null;
        $quantidade = parseNumeroBrProcessos(trim($_POST['quantidade_manual'] ?? ''));
        $hscode = trim($_POST['hscode_manual'] ?? '') ?: null;
        $ncm = trim($_POST['ncm_manual'] ?? '') ?: null;
        $fornecedor = trim($_POST['fornecedor_manual'] ?? '') ?: null;
        $preco = parseNumeroBrProcessos(trim($_POST['preco_manual'] ?? ''));
        $total = parseNumeroBrProcessos(trim($_POST['total_manual'] ?? ''));
        $moeda = trim($_POST['moeda_manual'] ?? '') ?: null;
        $tipo = trim($_POST['tipo_manual'] ?? '') ?: null;
        $ffw = trim($_POST['ffw_manual'] ?? '') ?: null;
        $obs = trim($_POST['obs_manual'] ?? '') ?: null;

        // Tipos: processo(s) status(s) solicitacao(s) categoria(s) planta(s) po(s) modal(s)
        // codigo_componente(s) descricao(s) quantidade(d) hscode(s) ncm(s) fornecedor(s)
        // preco(d) total(d) moeda(s) tipo(s) ffw(s) obs(s)
        mysqli_stmt_bind_param(
            $stmt, 'sssssssssdsssddsss',
            $processo, $status, $solicitacao, $categoria, $planta, $po, $modal, $codigoComponente,
            $descricao, $quantidade, $hscode, $ncm, $fornecedor, $preco, $total, $moeda, $tipo, $ffw, $obs
        );
        if (mysqli_stmt_execute($stmt)) {
            $mensagens[] = "✅ Processo \"$processo\" cadastrado.";
        } else {
            $mensagens[] = '❌ Erro ao cadastrar: ' . mysqli_stmt_error($stmt);
        }
        mysqli_stmt_close($stmt);
    }
}

// ---------- Edição ----------
// Identifica a linha por "id" (não por "processo"), já que um mesmo processo
// pode ter mais de uma linha (vários componentes/itens no mesmo embarque).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar_registro') {
    $idEditar = (int) ($_POST['id_editar'] ?? 0);
    if ($idEditar <= 0) {
        $mensagens[] = '❌ Registro inválido pra edição.';
    } else {
        $stmtEditar = mysqli_prepare($conn, "
            UPDATE processos
            SET solicitacao = ?, categoria = ?, planta = ?, po = ?, modal = ?, codigo_componente = ?,
                descricao = ?, quantidade = ?, hscode = ?, ncm = ?, fornecedor = ?, preco = ?, total = ?,
                moeda = ?, tipo = ?, ffw = ?, obs = ?
            WHERE id = ?
        ");
        $solicitacaoEd = parseDataProcessos(trim($_POST['solicitacao_editado'] ?? ''));
        $categoriaEd = trim($_POST['categoria_editado'] ?? '') ?: null;
        $plantaEd = trim($_POST['planta_editado'] ?? '') ?: null;
        $poEd = trim($_POST['po_editado'] ?? '') ?: null;
        $modalEd = trim($_POST['modal_editado'] ?? '') ?: null;
        $codigoComponenteEd = trim($_POST['codigo_componente_editado'] ?? '') ?: null;
        $descricaoEd = trim($_POST['descricao_editado'] ?? '') ?: null;
        $quantidadeEd = parseNumeroBrProcessos(trim($_POST['quantidade_editado'] ?? ''));
        $hscodeEd = trim($_POST['hscode_editado'] ?? '') ?: null;
        $ncmEd = trim($_POST['ncm_editado'] ?? '') ?: null;
        $fornecedorEd = trim($_POST['fornecedor_editado'] ?? '') ?: null;
        $precoEd = parseNumeroBrProcessos(trim($_POST['preco_editado'] ?? ''));
        $totalEd = parseNumeroBrProcessos(trim($_POST['total_editado'] ?? ''));
        $moedaEd = trim($_POST['moeda_editado'] ?? '') ?: null;
        $tipoEd = trim($_POST['tipo_editado'] ?? '') ?: null;
        $ffwEd = trim($_POST['ffw_editado'] ?? '') ?: null;
        $obsEd = trim($_POST['obs_editado'] ?? '') ?: null;

        // Tipos: solicitacao(s) categoria(s) planta(s) po(s) modal(s) codigo_componente(s)
        // descricao(s) quantidade(d) hscode(s) ncm(s) fornecedor(s) preco(d) total(d)
        // moeda(s) tipo(s) ffw(s) obs(s) id(i)
        mysqli_stmt_bind_param(
            $stmtEditar, 'sssssssdsssddssssi',
            $solicitacaoEd, $categoriaEd, $plantaEd, $poEd, $modalEd, $codigoComponenteEd,
            $descricaoEd, $quantidadeEd, $hscodeEd, $ncmEd, $fornecedorEd, $precoEd, $totalEd,
            $moedaEd, $tipoEd, $ffwEd, $obsEd, $idEditar
        );
        if (mysqli_stmt_execute($stmtEditar)) {
            $mensagens[] = '✅ Registro atualizado.';
        } else {
            $mensagens[] = '❌ Erro ao atualizar: ' . mysqli_stmt_error($stmtEditar);
        }
        mysqli_stmt_close($stmtEditar);
    }
}

// ---------- Exportação CSV ----------
if (isset($_GET['exportar'])) {
    $busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
    $where = '';
    $params = [];
    if ($busca !== '') {
        $where = "WHERE processo LIKE ? OR codigo_componente LIKE ? OR fornecedor LIKE ?";
        $like = '%' . $busca . '%';
        $params = [$like, $like, $like];
    }
    $sqlExport = "SELECT * FROM processos $where ORDER BY criado_em DESC";
    if (!empty($params)) {
        $stmtExport = mysqli_prepare($conn, $sqlExport);
        mysqli_stmt_bind_param($stmtExport, 'sss', ...$params);
        mysqli_stmt_execute($stmtExport);
        $resultExport = mysqli_stmt_get_result($stmtExport);
    } else {
        $resultExport = mysqli_query($conn, $sqlExport);
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="processos.csv"');
    echo "\xEF\xBB\xBF";
    $saida = fopen('php://output', 'w');
    fputcsv($saida, ['processo', 'status', 'solicitacao', 'categoria', 'planta', 'po', 'modal', 'codigo_componente', 'descricao', 'quantidade', 'hscode', 'ncm', 'fornecedor', 'preco', 'total', 'moeda', 'tipo', 'ffw', 'obs'], ';', '"', '');
    while ($linha = mysqli_fetch_assoc($resultExport)) {
        fputcsv($saida, [
            $linha['processo'], $linha['status'], $linha['solicitacao'], $linha['categoria'], $linha['planta'],
            $linha['po'], $linha['modal'], $linha['codigo_componente'], $linha['descricao'],
            $linha['quantidade'] !== null ? number_format((float) $linha['quantidade'], 2, ',', '') : '',
            $linha['hscode'], $linha['ncm'], $linha['fornecedor'],
            $linha['preco'] !== null ? number_format((float) $linha['preco'], 2, ',', '') : '',
            $linha['total'] !== null ? number_format((float) $linha['total'], 2, ',', '') : '',
            $linha['moeda'], $linha['tipo'], $linha['ffw'], $linha['obs'],
        ], ';', '"', '');
    }
    fclose($saida);
    exit;
}

// ---------- Listagem ----------
$porPagina = 50;
$pagina = isset($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$offset = ($pagina - 1) * $porPagina;
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$editandoId = (int) ($_GET['editar'] ?? 0);

$where = '';
$params = [];
$tipos = '';
if ($busca !== '') {
    $where = "WHERE processo LIKE ? OR codigo_componente LIKE ? OR fornecedor LIKE ? OR descricao LIKE ?";
    $like = '%' . $busca . '%';
    $params = [$like, $like, $like, $like];
    $tipos = 'ssss';
}

$stmtTotal = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM processos $where");
if (!empty($params)) { mysqli_stmt_bind_param($stmtTotal, $tipos, ...$params); }
mysqli_stmt_execute($stmtTotal);
$total = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmtTotal))['total'];
mysqli_stmt_close($stmtTotal);
$totalPaginas = max(1, (int) ceil($total / $porPagina));

$sql = "SELECT * FROM processos $where ORDER BY criado_em DESC LIMIT ? OFFSET ?";
$stmt = mysqli_prepare($conn, $sql);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $tipos . 'ii', ...array_merge($params, [$porPagina, $offset]));
} else {
    mysqli_stmt_bind_param($stmt, 'ii', $porPagina, $offset);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$rows = [];
while ($row = mysqli_fetch_assoc($result)) { $rows[] = $row; }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Processos | Controle de Importação</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/dashboard.css" rel="stylesheet">
    <style>
        /* Espaçamento extra — a tabela tem muitas colunas, então o padding
           padrão do dashboard.css (12px) fica meio apertado nessa tela. */
        #tabela-processos td, #tabela-processos th { padding: 14px 16px; }
        #tabela-processos td { font-size: .85rem; }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="container-fluid dashboard-container d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="eyebrow">Controle de Importação • Processos</span>
                <h1>Processos</h1>
                <p class="mb-0"><?php echo numeroBr($total, 0); ?> processo(s) na base</p>
            </div>
            <nav class="d-flex flex-wrap gap-2" aria-label="Ações do sistema">
                <a class="btn btn-outline-light btn-sm" href="follow.php">Follow</a>
                <a class="btn btn-light btn-sm" href="processos.php">Processos</a>
                <a class="btn btn-outline-light btn-sm" href="pagamento.php">Pagamento</a>
                <a class="btn btn-outline-light btn-sm" href="confirmar_entrega.php">Confirmar entrega</a>
            </nav>
        </div>
    </header>

    <main class="container-fluid dashboard-container py-4">

        <?php if (!empty($mensagens)): ?>
            <div class="alert alert-info">
                <?php foreach ($mensagens as $msg): ?>
                    <div><?php echo h($msg); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="filter-panel mb-4">
            <details>
                <summary class="fw-bold" style="cursor:pointer;">📤 Importar novo arquivo CSV</summary>
                <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end mt-3">
                    <div class="col-md-6">
                        <label class="form-label">Arquivo CSV</label>
                        <input type="file" name="arquivo_csv" class="form-control" accept=".csv" required>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check mt-4">
                            <input type="checkbox" name="limpar_tabela" id="limpar_tabela" class="form-check-input">
                            <label class="form-check-label" for="limpar_tabela">Limpar tabela antes de importar</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100">Importar</button>
                    </div>
                </form>
                <p class="mt-3 mb-0" style="font-size:.8rem; color:var(--muted);">
                    Colunas esperadas (primeira linha = cabeçalho, qualquer ordem):<br>
                    <code>processo, solicitacao, categoria, planta, po, modal, codigo_componente, descricao, quantidade, hscode, ncm, fornecedor, preco, total, moeda, tipo, ffw, obs</code><br>
                    Só <code>processo</code> é obrigatório — as demais colunas podem faltar. <strong>Não existe coluna <code>status</code></strong>: todo processo nasce "aberto" automaticamente, e só vira "finalizado" quando o embarque correspondente é confirmado na tela Confirmar Entrega. Separador: vírgula ou ponto e vírgula (detectado automaticamente).
                </p>
            </details>
        </section>

        <section class="filter-panel mb-4">
            <details>
                <summary class="fw-bold" style="cursor:pointer;">➕ Novo processo (cadastro manual)</summary>
                <form method="POST" class="row g-3 mt-3">
                    <input type="hidden" name="acao" value="cadastro_manual">
                    <div class="col-md-2">
                        <label class="form-label">Processo *</label>
                        <input type="text" name="processo_manual" class="form-control" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Solicitação</label>
                        <input type="text" name="solicitacao_manual" class="form-control" placeholder="dd/mm/aaaa">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Categoria</label>
                        <input type="text" name="categoria_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Planta</label>
                        <input type="text" name="planta_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">PO</label>
                        <input type="text" name="po_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Modal</label>
                        <input type="text" name="modal_manual" class="form-control" placeholder="aereo / maritimo">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Componente</label>
                        <input type="text" name="codigo_componente_manual" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Descrição</label>
                        <input type="text" name="descricao_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Quantidade</label>
                        <input type="text" name="quantidade_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">HS Code</label>
                        <input type="text" name="hscode_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">NCM</label>
                        <input type="text" name="ncm_manual" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Fornecedor</label>
                        <input type="text" name="fornecedor_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Preço</label>
                        <input type="text" name="preco_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Total</label>
                        <input type="text" name="total_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Moeda</label>
                        <input type="text" name="moeda_manual" class="form-control" placeholder="usd">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Tipo</label>
                        <input type="text" name="tipo_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">FFW</label>
                        <input type="text" name="ffw_manual" class="form-control">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Observações</label>
                        <input type="text" name="obs_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Salvar</button>
                    </div>
                </form>
            </details>
        </section>

        <section class="filter-panel mb-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label class="form-label">Buscar por processo, componente, fornecedor ou descrição</label>
                    <input type="text" name="busca" class="form-control" value="<?php echo h($busca); ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Buscar</button>
                </div>
                <div class="col-md-2">
                    <a href="?exportar=1&busca=<?php echo urlencode($busca); ?>" class="btn btn-outline-secondary w-100">Exportar CSV</a>
                </div>
            </form>
        </section>

        <section class="table-card">
            <div class="table-toolbar">
                <div>
                    <span class="eyebrow text-primary">Resultado</span>
                    <h2>Processos</h2>
                    <p><?php echo numeroBr($total, 0); ?> encontrado(s)</p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table mrp-table mb-0" id="tabela-processos" style="min-width: 1900px;">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Processo</th>
                            <th>Solicitação</th>
                            <th>Categoria</th>
                            <th>Planta</th>
                            <th>PO</th>
                            <th>Modal</th>
                            <th>Componente</th>
                            <th>Descrição</th>
                            <th>Quantidade</th>
                            <th>HS Code</th>
                            <th>NCM</th>
                            <th>Fornecedor</th>
                            <th>Preço</th>
                            <th>Total</th>
                            <th>Moeda</th>
                            <th>Tipo</th>
                            <th>FFW</th>
                            <th>Obs</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="20" class="empty-state">Nenhum processo encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <?php
                                    $statusFinalizado = strtolower(trim((string) ($r['status'] ?? ''))) === 'finalizado';
                                    $emEdicao = $editandoId === (int) $r['id'];
                                    $linkVoltar = '?pagina=' . $pagina . '&busca=' . urlencode($busca);
                                ?>
                                <tr>
                                    <td>
                                        <span class="status-badge <?php echo $statusFinalizado ? 'status-ok' : 'status-atencao'; ?>">
                                            <?php echo $statusFinalizado ? 'Finalizado' : 'Aberto'; ?>
                                        </span>
                                    </td>
                                    <td><span class="component-code"><?php echo h($r['processo']); ?></span></td>

                                    <?php if ($emEdicao): ?>
                                        <td colspan="17">
                                            <form method="POST" class="row g-2 align-items-end py-2">
                                                <input type="hidden" name="acao" value="editar_registro">
                                                <input type="hidden" name="id_editar" value="<?php echo (int) $r['id']; ?>">
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Solicitação</label>
                                                    <input type="text" name="solicitacao_editado" class="form-control form-control-sm" value="<?php echo h($r['solicitacao'] ? dataBr($r['solicitacao']) : ''); ?>" placeholder="dd/mm/aaaa">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Categoria</label>
                                                    <input type="text" name="categoria_editado" class="form-control form-control-sm" value="<?php echo h($r['categoria'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">Planta</label>
                                                    <input type="text" name="planta_editado" class="form-control form-control-sm" value="<?php echo h($r['planta'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">PO</label>
                                                    <input type="text" name="po_editado" class="form-control form-control-sm" value="<?php echo h($r['po'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">Modal</label>
                                                    <input type="text" name="modal_editado" class="form-control form-control-sm" value="<?php echo h($r['modal'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Componente</label>
                                                    <input type="text" name="codigo_componente_editado" class="form-control form-control-sm" value="<?php echo h($r['codigo_componente'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label small mb-0">Descrição</label>
                                                    <input type="text" name="descricao_editado" class="form-control form-control-sm" value="<?php echo h($r['descricao'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">Quantidade</label>
                                                    <input type="text" name="quantidade_editado" class="form-control form-control-sm" value="<?php echo $r['quantidade'] !== null ? numeroBr($r['quantidade'], 0) : ''; ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">HS Code</label>
                                                    <input type="text" name="hscode_editado" class="form-control form-control-sm" value="<?php echo h($r['hscode'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">NCM</label>
                                                    <input type="text" name="ncm_editado" class="form-control form-control-sm" value="<?php echo h($r['ncm'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Fornecedor</label>
                                                    <input type="text" name="fornecedor_editado" class="form-control form-control-sm" value="<?php echo h($r['fornecedor'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">Preço</label>
                                                    <input type="text" name="preco_editado" class="form-control form-control-sm" value="<?php echo $r['preco'] !== null ? numeroBr($r['preco']) : ''; ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">Total</label>
                                                    <input type="text" name="total_editado" class="form-control form-control-sm" value="<?php echo $r['total'] !== null ? numeroBr($r['total']) : ''; ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">Moeda</label>
                                                    <input type="text" name="moeda_editado" class="form-control form-control-sm" value="<?php echo h($r['moeda'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">Tipo</label>
                                                    <input type="text" name="tipo_editado" class="form-control form-control-sm" value="<?php echo h($r['tipo'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">FFW</label>
                                                    <input type="text" name="ffw_editado" class="form-control form-control-sm" value="<?php echo h($r['ffw'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Obs</label>
                                                    <input type="text" name="obs_editado" class="form-control form-control-sm" value="<?php echo h($r['obs'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-12 d-flex gap-2 mt-1">
                                                    <button type="submit" class="btn btn-success btn-sm">Salvar</button>
                                                    <a href="<?php echo $linkVoltar; ?>" class="btn btn-outline-secondary btn-sm">Cancelar</a>
                                                </div>
                                            </form>
                                        </td>
                                        <td></td>
                                    <?php else: ?>
                                        <td><?php echo dataBr($r['solicitacao']); ?></td>
                                        <td><?php echo h($r['categoria'] ?: '—'); ?></td>
                                        <td><?php echo h($r['planta'] ?: '—'); ?></td>
                                        <td><?php echo h($r['po'] ?: '—'); ?></td>
                                        <td><?php echo h($r['modal'] ?: '—'); ?></td>
                                        <td><?php echo h($r['codigo_componente'] ?: '—'); ?></td>
                                        <td class="description-cell" title="<?php echo h($r['descricao'] ?? ''); ?>"><?php echo h($r['descricao'] ?: '—'); ?></td>
                                        <td><?php echo numeroBr($r['quantidade'], 0); ?></td>
                                        <td><?php echo h($r['hscode'] ?: '—'); ?></td>
                                        <td><?php echo h($r['ncm'] ?: '—'); ?></td>
                                        <td><?php echo h($r['fornecedor'] ?: '—'); ?></td>
                                        <td><?php echo numeroBr($r['preco']); ?></td>
                                        <td><?php echo numeroBr($r['total']); ?></td>
                                        <td><?php echo h($r['moeda'] ?: '—'); ?></td>
                                        <td><?php echo h($r['tipo'] ?: '—'); ?></td>
                                        <td><?php echo h($r['ffw'] ?: '—'); ?></td>
                                        <td class="description-cell" title="<?php echo h($r['obs'] ?? ''); ?>"><?php echo h($r['obs'] ?: '—'); ?></td>
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
                                    <a class="page-link" href="?pagina=<?php echo $p; ?>&busca=<?php echo urlencode($busca); ?>"><?php echo $p; ?></a>
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
