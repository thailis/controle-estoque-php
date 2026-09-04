<?php
// importacao/pagamento.php
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

function parseDataPagamento(string $valor): ?string
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

function parseNumeroBrPagamento(string $valor): ?float
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

function normalizarTextoPagamento(string $texto): string
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

// ---------- Toggle de Liquidação OR / Liquidação NA ----------
// Regra de consistência: OR aberto sempre implica NA aberto (não existe OR
// aberto com NA fechado). Por isso:
//  - Reabrir OR (fechado -> aberto) também força NA de volta pra aberto junto.
//  - Fechar NA só é permitido se OR já estiver fechado — senão, bloqueia.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['acao'] ?? '', ['toggle_liquidacao_or', 'toggle_liquidacao_na'], true)) {
    $idPagamento = (int) ($_POST['id'] ?? 0);
    $acaoToggle = $_POST['acao'];

    $stmtAtual = mysqli_prepare($conn, "SELECT liquidacao_or, liquidacao_na FROM pagamento WHERE id = ?");
    mysqli_stmt_bind_param($stmtAtual, 'i', $idPagamento);
    mysqli_stmt_execute($stmtAtual);
    $atual = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtAtual));
    mysqli_stmt_close($stmtAtual);

    if (!$atual) {
        $mensagens[] = '❌ Registro de pagamento não encontrado.';
    } else {
        $orAtual = strtolower(trim((string) ($atual['liquidacao_or'] ?? ''))) === 'fechado' ? 'fechado' : 'aberto';
        $naAtual = strtolower(trim((string) ($atual['liquidacao_na'] ?? ''))) === 'fechado' ? 'fechado' : 'aberto';

        if ($acaoToggle === 'toggle_liquidacao_or') {
            if ($orAtual === 'aberto') {
                // Fechar OR — não mexe na NA (continua aberto, como já estava)
                $novoOr = 'fechado';
                $novoNa = $naAtual;
            } else {
                // Reabrir OR — força NA de volta pra aberto junto (regra de consistência)
                $novoOr = 'aberto';
                $novoNa = 'aberto';
            }
            $stmtUpdate = mysqli_prepare($conn, "UPDATE pagamento SET liquidacao_or = ?, liquidacao_na = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmtUpdate, 'ssi', $novoOr, $novoNa, $idPagamento);
            mysqli_stmt_execute($stmtUpdate);
            mysqli_stmt_close($stmtUpdate);
        } else { // toggle_liquidacao_na
            if ($naAtual === 'aberto') {
                if ($orAtual !== 'fechado') {
                    $mensagens[] = '❌ Não é possível fechar a Liquidação NA enquanto a Liquidação OR estiver aberta. Feche a OR primeiro.';
                } else {
                    $stmtUpdate = mysqli_prepare($conn, "UPDATE pagamento SET liquidacao_na = 'fechado' WHERE id = ?");
                    mysqli_stmt_bind_param($stmtUpdate, 'i', $idPagamento);
                    mysqli_stmt_execute($stmtUpdate);
                    mysqli_stmt_close($stmtUpdate);
                }
            } else {
                // Reabrir NA é sempre permitido (não afeta a OR)
                $stmtUpdate = mysqli_prepare($conn, "UPDATE pagamento SET liquidacao_na = 'aberto' WHERE id = ?");
                mysqli_stmt_bind_param($stmtUpdate, 'i', $idPagamento);
                mysqli_stmt_execute($stmtUpdate);
                mysqli_stmt_close($stmtUpdate);
            }
        }
    }
}

// ---------- Importação de CSV ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo_csv'])) {
    if ($_FILES['arquivo_csv']['error'] !== UPLOAD_ERR_OK) {
        $mensagens[] = '❌ Falha no envio do arquivo.';
    } else {
        $caminho = $_FILES['arquivo_csv']['tmp_name'];
        $handle = fopen($caminho, 'r');
        if ($handle) {
            if (!empty($_POST['limpar_tabela'])) {
                mysqli_query($conn, 'TRUNCATE TABLE pagamento');
            }

            $primeiraLinha = fgets($handle);
            $separador = substr_count($primeiraLinha, ';') >= substr_count($primeiraLinha, ',') ? ';' : ',';
            rewind($handle);

            $cabecalhoOriginal = fgetcsv($handle, 0, $separador);
            if ($cabecalhoOriginal === false) {
                $mensagens[] = '❌ Não consegui ler o cabeçalho do arquivo.';
            } else {
                $cabecalho = array_map('normalizarTextoPagamento', $cabecalhoOriginal);

                $mapaColunas = [
                    'processo' => ['processo'],
                    'status' => ['status'],
                    'po' => ['po'],
                    'fornecedor' => ['fornecedor'],
                    'moeda' => ['moeda'],
                    'total' => ['total'],
                    'advanced1' => ['advanced1'],
                    'advanced2' => ['advanced2'],
                    'balance' => ['balance'],
                    'liquidacao_or' => ['liquidacao_or'],
                    'despachante' => ['despachante'],
                    'numerario_inicial' => ['numerario_inicial'],
                    'valor_inicial' => ['valor_inicial'],
                    'numerario_final' => ['numerario_final'],
                    'valor_final' => ['valor_final'],
                    'diferenca' => ['diferenca'],
                    'liquidacao_na' => ['liquidacao_na'],
                    'rb' => ['rb'],
                    'oa' => ['oa'],
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
                        $campos = ['processo', 'status', 'po', 'fornecedor', 'moeda', 'total', 'advanced1', 'advanced2', 'balance', 'liquidacao_or', 'despachante', 'numerario_inicial', 'valor_inicial', 'numerario_final', 'valor_final', 'diferenca', 'liquidacao_na', 'rb', 'oa'];
                        $linhasSql = [];
                        $todosValores = [];
                        foreach ($lote as $linhaLote) {
                            $linhasSql[] = '(' . implode(',', array_fill(0, count($campos), '?')) . ')';
                            foreach ($linhaLote as $v) { $todosValores[] = $v; }
                        }
                        $sql = 'INSERT INTO pagamento (' . implode(',', $campos) . ') VALUES ' . implode(', ', $linhasSql);
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

                        $lote[] = [
                            $processo,
                            $get('status') ?: null,
                            $get('po') ?: null,
                            $get('fornecedor') ?: null,
                            $get('moeda') ?: null,
                            parseNumeroBrPagamento($get('total')),
                            parseNumeroBrPagamento($get('advanced1')),
                            parseNumeroBrPagamento($get('advanced2')),
                            parseNumeroBrPagamento($get('balance')),
                            $get('liquidacao_or') ?: null,
                            $get('despachante') ?: null,
                            parseDataPagamento($get('numerario_inicial')),
                            parseNumeroBrPagamento($get('valor_inicial')),
                            parseDataPagamento($get('numerario_final')),
                            parseNumeroBrPagamento($get('valor_final')),
                            parseNumeroBrPagamento($get('diferenca')),
                            $get('liquidacao_na') ?: null,
                            $get('rb') ?: null,
                            $get('oa') ?: null,
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
        $mensagens[] = '❌ Escolha um processo já cadastrado em Processos.';
    } else {
        // Os campos em comum com "processos" (status, po, fornecedor, moeda, total)
        // NUNCA vêm do formulário — são sempre buscados direto da tabela processos,
        // que é a fonte única pra esses dados. O <select> no formulário só decide
        // QUAL processo; o preview em tela é só visual, o servidor sempre reconfirma.
        $stmtProc = mysqli_prepare($conn, "SELECT status, po, fornecedor, moeda, total FROM processos WHERE processo = ? LIMIT 1");
        mysqli_stmt_bind_param($stmtProc, 's', $processo);
        mysqli_stmt_execute($stmtProc);
        $dadosProcesso = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtProc));
        mysqli_stmt_close($stmtProc);

        if (!$dadosProcesso) {
            $mensagens[] = "❌ O processo \"$processo\" não existe em Processos. Cadastre ele lá primeiro.";
        } else {
        $stmt = mysqli_prepare($conn, "
            INSERT INTO pagamento (processo, status, po, fornecedor, moeda, total, advanced1, advanced2, balance, liquidacao_or, despachante, numerario_inicial, valor_inicial, numerario_final, valor_final, diferenca, liquidacao_na, rb, oa)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $status = $dadosProcesso['status'];
        $po = $dadosProcesso['po'];
        $fornecedor = $dadosProcesso['fornecedor'];
        $moeda = $dadosProcesso['moeda'];
        $total = $dadosProcesso['total'];
        $advanced1 = parseNumeroBrPagamento(trim($_POST['advanced1_manual'] ?? ''));
        $advanced2 = parseNumeroBrPagamento(trim($_POST['advanced2_manual'] ?? ''));
        $balance = parseNumeroBrPagamento(trim($_POST['balance_manual'] ?? ''));
        // Liquidação OR e NA nunca são digitadas — todo pagamento novo nasce com
        // as duas "aberto". A partir daí, só o botão de alternância na listagem muda.
        $liquidacaoOr = 'aberto';
        $despachante = trim($_POST['despachante_manual'] ?? '') ?: null;
        $numerarioInicial = parseDataPagamento(trim($_POST['numerario_inicial_manual'] ?? ''));
        $valorInicial = parseNumeroBrPagamento(trim($_POST['valor_inicial_manual'] ?? ''));
        $numerarioFinal = parseDataPagamento(trim($_POST['numerario_final_manual'] ?? ''));
        $valorFinal = parseNumeroBrPagamento(trim($_POST['valor_final_manual'] ?? ''));
        $diferenca = parseNumeroBrPagamento(trim($_POST['diferenca_manual'] ?? ''));
        $liquidacaoNa = 'aberto';
        $rb = trim($_POST['rb_manual'] ?? '') ?: null;
        $oa = trim($_POST['oa_manual'] ?? '') ?: null;

        // Tipos: processo(s) status(s) po(s) fornecedor(s) moeda(s) total(d) advanced1(d) advanced2(d)
        // balance(d) liquidacao_or(s) despachante(s) numerario_inicial(s) valor_inicial(d)
        // numerario_final(s) valor_final(d) diferenca(d) liquidacao_na(s) rb(s) oa(s)
        mysqli_stmt_bind_param(
            $stmt, 'sssssddddsssdsddsss',
            $processo, $status, $po, $fornecedor, $moeda, $total, $advanced1, $advanced2, $balance,
            $liquidacaoOr, $despachante, $numerarioInicial, $valorInicial, $numerarioFinal, $valorFinal,
            $diferenca, $liquidacaoNa, $rb, $oa
        );
        if (mysqli_stmt_execute($stmt)) {
            $mensagens[] = "✅ Pagamento do processo \"$processo\" cadastrado.";
        } else {
            $mensagens[] = '❌ Erro ao cadastrar: ' . mysqli_stmt_error($stmt);
        }
        mysqli_stmt_close($stmt);
        }
    }
}

// ---------- Edição ----------
// Não edita: processo (vínculo com Processos), status/po/fornecedor/moeda/total
// (vêm de Processos, só lá se corrige), liquidacao_or/liquidacao_na (têm botão
// de alternância próprio). Balance e Diferença são RECALCULADOS aqui de novo,
// nunca aceitos direto do formulário — mesma regra da criação.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar_pagamento') {
    $idEditar = (int) ($_POST['id_editar'] ?? 0);
    if ($idEditar <= 0) {
        $mensagens[] = '❌ Registro inválido pra edição.';
    } else {
        $stmtAtualPag = mysqli_prepare($conn, "SELECT processo FROM pagamento WHERE id = ?");
        mysqli_stmt_bind_param($stmtAtualPag, 'i', $idEditar);
        mysqli_stmt_execute($stmtAtualPag);
        $atualPag = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtAtualPag));
        mysqli_stmt_close($stmtAtualPag);

        if (!$atualPag) {
            $mensagens[] = '❌ Registro de pagamento não encontrado.';
        } else {
            $stmtTotalProc = mysqli_prepare($conn, "SELECT total FROM processos WHERE processo = ? LIMIT 1");
            mysqli_stmt_bind_param($stmtTotalProc, 's', $atualPag['processo']);
            mysqli_stmt_execute($stmtTotalProc);
            $totalProc = (float) (mysqli_fetch_assoc(mysqli_stmt_get_result($stmtTotalProc))['total'] ?? 0);
            mysqli_stmt_close($stmtTotalProc);

            $advanced1Ed = parseNumeroBrPagamento(trim($_POST['advanced1_editado'] ?? '')) ?? 0.0;
            $advanced2Ed = parseNumeroBrPagamento(trim($_POST['advanced2_editado'] ?? '')) ?? 0.0;
            $despachanteEd = trim($_POST['despachante_editado'] ?? '') ?: null;
            $numerarioInicialEd = parseDataPagamento(trim($_POST['numerario_inicial_editado'] ?? ''));
            $valorInicialEd = parseNumeroBrPagamento(trim($_POST['valor_inicial_editado'] ?? '')) ?? 0.0;
            $numerarioFinalEd = parseDataPagamento(trim($_POST['numerario_final_editado'] ?? ''));
            $valorFinalEd = parseNumeroBrPagamento(trim($_POST['valor_final_editado'] ?? '')) ?? 0.0;
            $rbEd = trim($_POST['rb_editado'] ?? '') ?: null;
            $oaEd = trim($_POST['oa_editado'] ?? '') ?: null;

            $balanceEd = $advanced1Ed + $advanced2Ed - $totalProc;
            $diferencaEd = $valorFinalEd - $valorInicialEd;

            $stmtEditar = mysqli_prepare($conn, "
                UPDATE pagamento
                SET advanced1 = ?, advanced2 = ?, balance = ?, despachante = ?, numerario_inicial = ?,
                    valor_inicial = ?, numerario_final = ?, valor_final = ?, diferenca = ?, rb = ?, oa = ?
                WHERE id = ?
            ");
            mysqli_stmt_bind_param(
                $stmtEditar, 'dddssdsddssi',
                $advanced1Ed, $advanced2Ed, $balanceEd, $despachanteEd, $numerarioInicialEd,
                $valorInicialEd, $numerarioFinalEd, $valorFinalEd, $diferencaEd, $rbEd, $oaEd, $idEditar
            );
            if (mysqli_stmt_execute($stmtEditar)) {
                $mensagens[] = '✅ Pagamento atualizado (Balance e Diferença recalculados).';
            } else {
                $mensagens[] = '❌ Erro ao atualizar: ' . mysqli_stmt_error($stmtEditar);
            }
            mysqli_stmt_close($stmtEditar);
        }
    }
}

// ---------- Exportação CSV ----------
if (isset($_GET['exportar'])) {
    $busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
    $where = '';
    $params = [];
    if ($busca !== '') {
        $where = "WHERE processo LIKE ? OR fornecedor LIKE ? OR po LIKE ?";
        $like = '%' . $busca . '%';
        $params = [$like, $like, $like];
    }
    $sqlExport = "SELECT * FROM pagamento $where ORDER BY criado_em DESC";
    if (!empty($params)) {
        $stmtExport = mysqli_prepare($conn, $sqlExport);
        mysqli_stmt_bind_param($stmtExport, 'sss', ...$params);
        mysqli_stmt_execute($stmtExport);
        $resultExport = mysqli_stmt_get_result($stmtExport);
    } else {
        $resultExport = mysqli_query($conn, $sqlExport);
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="pagamento.csv"');
    echo "\xEF\xBB\xBF";
    $saida = fopen('php://output', 'w');
    fputcsv($saida, ['processo', 'status', 'po', 'fornecedor', 'moeda', 'total', 'advanced1', 'advanced2', 'balance', 'liquidacao_or', 'despachante', 'numerario_inicial', 'valor_inicial', 'numerario_final', 'valor_final', 'diferenca', 'liquidacao_na', 'rb', 'oa'], ';', '"', '');
    while ($linha = mysqli_fetch_assoc($resultExport)) {
        fputcsv($saida, [
            $linha['processo'], $linha['status'], $linha['po'], $linha['fornecedor'], $linha['moeda'],
            $linha['total'] !== null ? number_format((float) $linha['total'], 2, ',', '') : '',
            $linha['advanced1'] !== null ? number_format((float) $linha['advanced1'], 2, ',', '') : '',
            $linha['advanced2'] !== null ? number_format((float) $linha['advanced2'], 2, ',', '') : '',
            $linha['balance'] !== null ? number_format((float) $linha['balance'], 2, ',', '') : '',
            $linha['liquidacao_or'], $linha['despachante'], $linha['numerario_inicial'],
            $linha['valor_inicial'] !== null ? number_format((float) $linha['valor_inicial'], 2, ',', '') : '',
            $linha['numerario_final'],
            $linha['valor_final'] !== null ? number_format((float) $linha['valor_final'], 2, ',', '') : '',
            $linha['diferenca'] !== null ? number_format((float) $linha['diferenca'], 2, ',', '') : '',
            $linha['liquidacao_na'], $linha['rb'], $linha['oa'],
        ], ';', '"', '');
    }
    fclose($saida);
    exit;
}

// ---------- Listagem ----------
// Lista de processos existentes, pra popular o <select> do cadastro manual —
// já traz os campos em comum (status, po, fornecedor, moeda, total) pro
// preview automático via JS (o servidor reconfirma tudo de novo ao salvar).
$processosDisponiveis = [];
$resProcessos = mysqli_query($conn, "SELECT processo, status, po, fornecedor, moeda, total FROM processos ORDER BY processo");
while ($linhaProc = mysqli_fetch_assoc($resProcessos)) {
    $processosDisponiveis[] = $linhaProc;
}

$porPagina = 50;
$pagina = isset($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$offset = ($pagina - 1) * $porPagina;
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$editandoId = (int) ($_GET['editar'] ?? 0);

$where = '';
$params = [];
$tipos = '';
if ($busca !== '') {
    $where = "WHERE processo LIKE ? OR fornecedor LIKE ? OR po LIKE ?";
    $like = '%' . $busca . '%';
    $params = [$like, $like, $like];
    $tipos = 'sss';
}

$stmtTotal = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM pagamento $where");
if (!empty($params)) { mysqli_stmt_bind_param($stmtTotal, $tipos, ...$params); }
mysqli_stmt_execute($stmtTotal);
$total = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmtTotal))['total'];
mysqli_stmt_close($stmtTotal);
$totalPaginas = max(1, (int) ceil($total / $porPagina));

$sql = "SELECT * FROM pagamento $where ORDER BY criado_em DESC LIMIT ? OFFSET ?";
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

// Soma geral (visão rápida de quanto está em aberto no total)
$totais = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(total) AS soma_total, SUM(balance) AS soma_balance FROM pagamento"));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pagamento | Controle de Importação</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/dashboard.css" rel="stylesheet">
</head>
<body>
    <header class="topbar">
        <div class="container-fluid dashboard-container d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="eyebrow">Controle de Importação • Financeiro</span>
                <h1>Pagamento</h1>
                <p class="mb-0"><?php echo numeroBr($total, 0); ?> registro(s) na base • Total geral: <?php echo numeroBr($totais['soma_total'] ?? 0); ?> • Balance geral: <?php echo numeroBr($totais['soma_balance'] ?? 0); ?></p>
            </div>
            <nav class="d-flex flex-wrap gap-2" aria-label="Ações do sistema">
                <a class="btn btn-outline-light btn-sm" href="follow.php">Follow</a>
                <a class="btn btn-outline-light btn-sm" href="processos.php">Processos</a>
                <a class="btn btn-light btn-sm" href="pagamento.php">Pagamento</a>
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
                    <code>processo, status, po, fornecedor, moeda, total, advanced1, advanced2, balance, liquidacao_or, despachante, numerario_inicial, valor_inicial, numerario_final, valor_final, diferenca, liquidacao_na, rb, oa</code><br>
                    Só <code>processo</code> é obrigatório. Datas em <code>dd/mm/aaaa</code>. Separador: vírgula ou ponto e vírgula.
                </p>
            </details>
        </section>

        <section class="filter-panel mb-4">
            <details>
                <summary class="fw-bold" style="cursor:pointer;">➕ Novo pagamento (cadastro manual)</summary>
                <form method="POST" class="row g-3 mt-3">
                    <input type="hidden" name="acao" value="cadastro_manual">
                    <div class="col-md-3">
                        <label class="form-label">Processo *</label>
                        <select name="processo_manual" id="processo_manual" class="form-select" required onchange="atualizarPreviewProcessoPagamento(); calcularBalancePagamento();">
                            <option value="">Escolha um processo já cadastrado...</option>
                            <?php foreach ($processosDisponiveis as $p): ?>
                                <option value="<?php echo h($p['processo']); ?>"
                                    data-status="<?php echo h($p['status'] ?? ''); ?>"
                                    data-po="<?php echo h($p['po'] ?? ''); ?>"
                                    data-fornecedor="<?php echo h($p['fornecedor'] ?? ''); ?>"
                                    data-moeda="<?php echo h($p['moeda'] ?? ''); ?>"
                                    data-total="<?php echo h($p['total'] !== null ? number_format((float) $p['total'], 2, ',', '.') : ''); ?>">
                                    <?php echo h($p['processo']); ?><?php echo $p['fornecedor'] ? ' — ' . h($p['fornecedor']) : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($processosDisponiveis)): ?>
                            <small class="text-danger">Nenhum processo cadastrado ainda — <a href="processos.php">cadastre um processo primeiro</a>.</small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status <small class="text-muted">(do processo)</small></label>
                        <input type="text" id="preview_status" class="form-control" disabled placeholder="—">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">PO <small class="text-muted">(do processo)</small></label>
                        <input type="text" id="preview_po" class="form-control" disabled placeholder="—">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Fornecedor <small class="text-muted">(do processo)</small></label>
                        <input type="text" id="preview_fornecedor" class="form-control" disabled placeholder="—">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Moeda <small class="text-muted">(do processo)</small></label>
                        <input type="text" id="preview_moeda" class="form-control" disabled placeholder="—">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Total <small class="text-muted">(do processo)</small></label>
                        <input type="text" id="preview_total" class="form-control" disabled placeholder="—">
                    </div>
                    <div class="col-12"><hr class="my-1"></div>
                    <div class="col-md-2">
                        <label class="form-label">Advanced 1</label>
                        <input type="text" name="advanced1_manual" id="advanced1_manual" class="form-control" oninput="calcularBalancePagamento()">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Advanced 2</label>
                        <input type="text" name="advanced2_manual" id="advanced2_manual" class="form-control" oninput="calcularBalancePagamento()">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Balance <small class="text-muted">(adv1+adv2−total)</small></label>
                        <input type="text" id="balance_manual_display" class="form-control" readonly placeholder="—">
                        <input type="hidden" name="balance_manual" id="balance_manual">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Despachante</label>
                        <input type="text" name="despachante_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Numerário inicial</label>
                        <input type="text" name="numerario_inicial_manual" class="form-control" placeholder="dd/mm/aaaa">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Valor inicial</label>
                        <input type="text" name="valor_inicial_manual" id="valor_inicial_manual" class="form-control" oninput="calcularDiferencaPagamento()">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Numerário final</label>
                        <input type="text" name="numerario_final_manual" class="form-control" placeholder="dd/mm/aaaa">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Valor final</label>
                        <input type="text" name="valor_final_manual" id="valor_final_manual" class="form-control" oninput="calcularDiferencaPagamento()">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Diferença <small class="text-muted">(final−inicial)</small></label>
                        <input type="text" id="diferenca_manual_display" class="form-control" readonly placeholder="—">
                        <input type="hidden" name="diferenca_manual" id="diferenca_manual">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">RB</label>
                        <input type="text" name="rb_manual" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">OA (link)</label>
                        <input type="text" name="oa_manual" class="form-control">
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
                    <label class="form-label">Buscar por processo, fornecedor ou PO</label>
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
                    <h2>Pagamentos</h2>
                    <p><?php echo numeroBr($total, 0); ?> encontrado(s)</p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table mrp-table mb-0" style="min-width: 2100px;">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Processo</th>
                            <th>Status Processo</th>
                            <th>PO</th>
                            <th>Fornecedor</th>
                            <th>Moeda</th>
                            <th>Total</th>
                            <th>Advanced 1</th>
                            <th>Advanced 2</th>
                            <th>Balance</th>
                            <th>Liquidação OR</th>
                            <th>Despachante</th>
                            <th>Numerário inicial</th>
                            <th>Valor inicial</th>
                            <th>Numerário final</th>
                            <th>Valor final</th>
                            <th>Diferença</th>
                            <th>Liquidação NA</th>
                            <th>RB</th>
                            <th>OA</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="21" class="empty-state">Nenhum pagamento encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <?php
                                    $orValor = strtolower(trim((string) ($r['liquidacao_or'] ?? ''))) === 'fechado' ? 'fechado' : 'aberto';
                                    $naValor = strtolower(trim((string) ($r['liquidacao_na'] ?? ''))) === 'fechado' ? 'fechado' : 'aberto';
                                    if ($orValor === 'aberto') {
                                        $statusCalc = 'aberto'; $statusClasse = 'status-atencao';
                                    } elseif ($orValor === 'fechado' && $naValor === 'aberto') {
                                        $statusCalc = 'parcial'; $statusClasse = 'status-planejar';
                                    } else {
                                        $statusCalc = 'finalizado'; $statusClasse = 'status-ok';
                                    }
                                    $emEdicao = $editandoId === (int) $r['id'];
                                    $linkVoltar = '?pagina=' . $pagina . '&busca=' . urlencode($busca);
                                ?>
                                <tr>
                                    <td><span class="status-badge <?php echo $statusClasse; ?>" title="OR: <?php echo h($orValor); ?> · NA: <?php echo h($naValor); ?>"><?php echo ucfirst($statusCalc); ?></span></td>
                                    <td><span class="component-code"><?php echo h($r['processo']); ?></span></td>
                                    <td><?php echo h($r['status'] ?: '—'); ?></td>
                                    <td><?php echo h($r['po'] ?: '—'); ?></td>
                                    <td><?php echo h($r['fornecedor'] ?: '—'); ?></td>
                                    <td><?php echo h($r['moeda'] ?: '—'); ?></td>
                                    <td><?php echo numeroBr($r['total']); ?></td>

                                    <?php if ($emEdicao): ?>
                                        <td colspan="13">
                                            <form method="POST" class="row g-2 align-items-end py-2">
                                                <input type="hidden" name="acao" value="editar_pagamento">
                                                <input type="hidden" name="id_editar" value="<?php echo (int) $r['id']; ?>">
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Advanced 1</label>
                                                    <input type="text" name="advanced1_editado" class="form-control form-control-sm" value="<?php echo $r['advanced1'] !== null ? numeroBr($r['advanced1']) : ''; ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Advanced 2</label>
                                                    <input type="text" name="advanced2_editado" class="form-control form-control-sm" value="<?php echo $r['advanced2'] !== null ? numeroBr($r['advanced2']) : ''; ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Despachante</label>
                                                    <input type="text" name="despachante_editado" class="form-control form-control-sm" value="<?php echo h($r['despachante'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Numerário inicial</label>
                                                    <input type="text" name="numerario_inicial_editado" class="form-control form-control-sm" value="<?php echo h($r['numerario_inicial'] ? dataBr($r['numerario_inicial']) : ''); ?>" placeholder="dd/mm/aaaa">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Valor inicial</label>
                                                    <input type="text" name="valor_inicial_editado" class="form-control form-control-sm" value="<?php echo $r['valor_inicial'] !== null ? numeroBr($r['valor_inicial']) : ''; ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Numerário final</label>
                                                    <input type="text" name="numerario_final_editado" class="form-control form-control-sm" value="<?php echo h($r['numerario_final'] ? dataBr($r['numerario_final']) : ''); ?>" placeholder="dd/mm/aaaa">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Valor final</label>
                                                    <input type="text" name="valor_final_editado" class="form-control form-control-sm" value="<?php echo $r['valor_final'] !== null ? numeroBr($r['valor_final']) : ''; ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">RB</label>
                                                    <input type="text" name="rb_editado" class="form-control form-control-sm" value="<?php echo h($r['rb'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label small mb-0">OA (link)</label>
                                                    <input type="text" name="oa_editado" class="form-control form-control-sm" value="<?php echo h($r['oa'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-9">
                                                    <small class="text-muted">Balance e Diferença são recalculados automaticamente ao salvar. Liquidação OR/NA se alteram pelo botão na listagem, não aqui.</small>
                                                </div>
                                                <div class="col-md-12 d-flex gap-2 mt-1">
                                                    <button type="submit" class="btn btn-success btn-sm">Salvar</button>
                                                    <a href="<?php echo $linkVoltar; ?>" class="btn btn-outline-secondary btn-sm">Cancelar</a>
                                                </div>
                                            </form>
                                        </td>
                                        <td></td>
                                    <?php else: ?>
                                        <td><?php echo numeroBr($r['advanced1']); ?></td>
                                        <td><?php echo numeroBr($r['advanced2']); ?></td>
                                        <td class="<?php echo ((float) ($r['balance'] ?? 0)) > 0 ? 'purchase-value' : ''; ?>"><?php echo numeroBr($r['balance']); ?></td>
                                        <td>
                                            <form method="POST" class="d-inline m-0">
                                                <input type="hidden" name="acao" value="toggle_liquidacao_or">
                                                <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                                <button type="submit" class="status-badge border-0 <?php echo $orValor === 'fechado' ? 'status-ok' : 'status-atencao'; ?>" style="cursor:pointer;" title="Clique pra alternar (reabrir a OR também reabre a NA junto)">
                                                    <?php echo ucfirst($orValor); ?>
                                                </button>
                                            </form>
                                        </td>
                                        <td><?php echo h($r['despachante'] ?: '—'); ?></td>
                                        <td><?php echo dataBr($r['numerario_inicial']); ?></td>
                                        <td><?php echo numeroBr($r['valor_inicial']); ?></td>
                                        <td><?php echo dataBr($r['numerario_final']); ?></td>
                                        <td><?php echo numeroBr($r['valor_final']); ?></td>
                                        <td><?php echo numeroBr($r['diferenca']); ?></td>
                                        <td>
                                            <form method="POST" class="d-inline m-0">
                                                <input type="hidden" name="acao" value="toggle_liquidacao_na">
                                                <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                                <button type="submit" class="status-badge border-0 <?php echo $naValor === 'fechado' ? 'status-ok' : 'status-atencao'; ?>" style="cursor:pointer;" title="<?php echo $orValor !== 'fechado' ? 'Só é possível fechar a NA depois que a OR estiver fechada' : 'Clique pra alternar'; ?>">
                                                    <?php echo ucfirst($naValor); ?>
                                                </button>
                                            </form>
                                        </td>
                                        <td><?php echo h($r['rb'] ?: '—'); ?></td>
                                        <td>
                                            <?php if (!empty($r['oa'])): ?>
                                                <a href="<?php echo h($r['oa']); ?>" target="_blank" rel="noopener">Abrir</a>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
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
    <script>
        // Ao escolher um processo, mostra os campos que já existem cadastrados
        // nele (status, PO, fornecedor, moeda, total) — só pra visualização.
        // O servidor sempre busca esses mesmos valores de novo, direto de
        // "processos", na hora de salvar — o que aparece aqui nunca é enviado.
        function atualizarPreviewProcessoPagamento() {
            const select = document.getElementById('processo_manual');
            const opcao = select.options[select.selectedIndex];
            document.getElementById('preview_status').value = opcao.dataset.status || '—';
            document.getElementById('preview_po').value = opcao.dataset.po || '—';
            document.getElementById('preview_fornecedor').value = opcao.dataset.fornecedor || '—';
            document.getElementById('preview_moeda').value = opcao.dataset.moeda || '—';
            document.getElementById('preview_total').value = opcao.dataset.total || '—';
        }

        // Converte texto em formato BR ("1.234,56" ou só "1234,56") pra número
        // JS de verdade. Aceita também número puro sem formatação nenhuma.
        function parseNumeroBrJs(texto) {
            if (!texto) return 0;
            texto = String(texto).trim();
            if (texto.includes(',')) {
                texto = texto.replace(/\./g, '').replace(',', '.');
            }
            const n = parseFloat(texto);
            return isNaN(n) ? 0 : n;
        }

        function formatarNumeroBrJs(n) {
            return n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        // Balance = Advanced 1 + Advanced 2 − Total (do processo escolhido).
        // Nunca digitado — sempre recalculado ao mudar qualquer um dos três.
        function calcularBalancePagamento() {
            const select = document.getElementById('processo_manual');
            const opcao = select.options[select.selectedIndex];
            const total = parseNumeroBrJs(opcao ? opcao.dataset.total : '');
            const adv1 = parseNumeroBrJs(document.getElementById('advanced1_manual').value);
            const adv2 = parseNumeroBrJs(document.getElementById('advanced2_manual').value);
            const balance = adv1 + adv2 - total;
            const textoBalance = formatarNumeroBrJs(balance);
            document.getElementById('balance_manual_display').value = textoBalance;
            document.getElementById('balance_manual').value = textoBalance;
        }

        // Diferença = Valor final − Valor inicial. Nunca digitada — sempre
        // recalculada ao mudar qualquer um dos dois.
        function calcularDiferencaPagamento() {
            const valorInicial = parseNumeroBrJs(document.getElementById('valor_inicial_manual').value);
            const valorFinal = parseNumeroBrJs(document.getElementById('valor_final_manual').value);
            const diferenca = valorFinal - valorInicial;
            const textoDiferenca = formatarNumeroBrJs(diferenca);
            document.getElementById('diferenca_manual_display').value = textoDiferenca;
            document.getElementById('diferenca_manual').value = textoDiferenca;
        }
    </script>
</body>
</html>
