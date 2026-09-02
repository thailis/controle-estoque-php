<?php
require_once 'conexao.php';

set_time_limit(300);

$mensagens = [];
$importados = 0;
$atualizados = 0;
$ignorados = 0;
$erros = 0;

function parseDataProgramacao(string $valor): ?string
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }

    $formatos = ['d/m/Y', 'Y-m-d', 'd-m-Y'];
    foreach ($formatos as $formato) {
        $data = DateTimeImmutable::createFromFormat('!' . $formato, $valor);
        if ($data instanceof DateTimeImmutable) {
            return $data->format('Y-m-d');
        }
    }
    return null;
}

function normalizarTexto(string $texto): string
{
    $texto = mb_strtolower(trim($texto), 'UTF-8');
    $mapa = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c',
    ];
    return strtr($texto, $mapa);
}

function parseQuantidade(string $valor): ?float
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }
    if (str_contains($valor, ',')) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    } elseif (substr_count($valor, '.') >= 1) {
        // Só ponto, sem vírgula: só remove como milhar se todos os grupos após
        // o primeiro ponto tiverem exatamente 3 dígitos (ex.: "1.400", "45.000").
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

// Localiza a coluna do código do componente (aceita "componente" ou "codigo_componente")
function localizarColunaComponente(array $cabecalhoNormalizado): ?int
{
    $candidatos = [
    'codigo_componente',
    'componente',
    'codigo do componente',
    'codigo componente',
    'cod componente',
    'codigo_do_componente'
];
    foreach ($candidatos as $candidato) {
        $indice = array_search($candidato, $cabecalhoNormalizado, true);
        if ($indice !== false) {
            return $indice;
        }
    }
    return null;
}

// Detecta pares [indice_data, indice_quantidade]: qualquer coluna cujo cabeçalho contenha
// "quantidade" é pareada com a coluna imediatamente anterior (a data daquela programação).
// Isso cobre tanto o formato simples (codigo_componente, data, quantidade) quanto o formato
// largo da planilha (Programação 1, Quantidade, Programação 2, Quantidade 2, ...).
function detectarParesDataQuantidade(array $cabecalhoNormalizado): array
{
    $pares = [];
    foreach ($cabecalhoNormalizado as $indice => $nome) {
        if ($indice > 0 && str_contains($nome, 'quantidade')) {
            $pares[] = [$indice - 1, $indice];
        }
    }
    return $pares;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo_csv'])) {
    $arquivo = $_FILES['arquivo_csv']['tmp_name'];
    $modo = $_POST['modo'] ?? 'adicionar';

    if ($_FILES['arquivo_csv']['error'] !== UPLOAD_ERR_OK) {
        $mensagens[] = "❌ Erro no upload do arquivo.";
    } else {
        $handle = fopen($arquivo, 'r');

        if ($handle === false) {
            $mensagens[] = "❌ Não foi possível abrir o arquivo.";
        } else {
            $primeiraLinha = fgets($handle);
            rewind($handle);
            $separador = (substr_count($primeiraLinha, ';') > substr_count($primeiraLinha, ',')) ? ';' : ',';

            $cabecalhoOriginal = fgetcsv($handle, 0, $separador, '"', '\\');
            if ($cabecalhoOriginal === false) {
                $mensagens[] = "❌ Arquivo vazio ou inválido.";
            } else {
                // O Excel costuma gravar um BOM (marcador invisível) na primeira célula do CSV.
                if (isset($cabecalhoOriginal[0])) {
                    $cabecalhoOriginal[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cabecalhoOriginal[0]);
                }

                $cabecalho = array_map('normalizarTexto', $cabecalhoOriginal);

                $indiceComponente = localizarColunaComponente($cabecalho);
                $paresDataQuantidade = detectarParesDataQuantidade($cabecalho);

                if ($indiceComponente === null) {
                    $mensagens[] = "❌ Não encontrei a coluna do componente. Use 'componente' ou 'codigo_componente' no cabeçalho.";
                } elseif (empty($paresDataQuantidade)) {
                    $mensagens[] = "❌ Não encontrei nenhuma coluna de quantidade (ex.: 'quantidade', 'quantidade 2'...).";
                } else {
                if ($modo === 'substituir') {
                    mysqli_query($conn, "TRUNCATE TABLE programacao");
                    $mensagens[] = "🗑️ Tabela 'programacao' esvaziada antes da importação.";
                }

                $stmtInsert = mysqli_prepare($conn, "INSERT INTO programacao (codigo_componente, data, quantidade) VALUES (?, ?, ?)");
                $stmtVerifica = mysqli_prepare($conn, "SELECT id FROM programacao WHERE TRIM(codigo_componente) = ? AND data = ? LIMIT 1");
                $stmtUpdate = mysqli_prepare($conn, "UPDATE programacao SET quantidade = ? WHERE id = ?");

                mysqli_autocommit($conn, false);

                $linhaNum = 1;
                while (($linha = fgetcsv($handle, 0, $separador, '"', '\\')) !== false) {
                    $linhaNum++;

                    if (count(array_filter($linha, fn($v) => trim((string) $v) !== '')) === 0) {
                        continue;
                    }

                    if (count($linha) !== count($cabecalho)) {
                        $erros++;
                        $mensagens[] = "⚠️ Linha $linhaNum ignorada (número de colunas não confere).";
                        continue;
                    }

                    $codigoComponente = trim($linha[$indiceComponente] ?? '');
                    if ($codigoComponente === '') {
                        $erros++;
                        $mensagens[] = "⚠️ Linha $linhaNum ignorada: componente vazio.";
                        continue;
                    }

                    // Uma linha da planilha pode gerar várias entradas de programação
                    // (Programação 1, Programação 2, Programação 3...).
                    foreach ($paresDataQuantidade as [$indiceData, $indiceQtd]) {
                        $dataBruta = trim($linha[$indiceData] ?? '');
                        $quantidadeBruta = trim($linha[$indiceQtd] ?? '');

                        // Par vazio (ex.: componente não tem "Programação 3") — pula sem contar erro.
                        if ($dataBruta === '' && $quantidadeBruta === '') {
                            continue;
                        }

                        $data = parseDataProgramacao($dataBruta);
                        if ($data === null) {
                            $erros++;
                            $mensagens[] = "⚠️ Linha $linhaNum, coluna " . ($indiceData + 1) . ": data '$dataBruta' inválida (use DD/MM/AAAA ou AAAA-MM-DD).";
                            continue;
                        }

                        $quantidade = parseQuantidade($quantidadeBruta);
                        if ($quantidade === null) {
                            $erros++;
                            $mensagens[] = "⚠️ Linha $linhaNum, coluna " . ($indiceQtd + 1) . ": quantidade '$quantidadeBruta' inválida.";
                            continue;
                        }

                        $idExistente = null;
                        if ($modo === 'sem_duplicar' || $modo === 'atualizar') {
                            mysqli_stmt_bind_param($stmtVerifica, "ss", $codigoComponente, $data);
                            mysqli_stmt_execute($stmtVerifica);
                            $resVerifica = mysqli_stmt_get_result($stmtVerifica);
                            $linhaExistente = mysqli_fetch_assoc($resVerifica);
                            $idExistente = $linhaExistente['id'] ?? null;
                        }

                        if ($modo === 'sem_duplicar' && $idExistente !== null) {
                            $ignorados++;
                            continue;
                        }

                        if ($modo === 'atualizar' && $idExistente !== null) {
                            mysqli_stmt_bind_param($stmtUpdate, "di", $quantidade, $idExistente);
                            if (mysqli_stmt_execute($stmtUpdate)) {
                                $atualizados++;
                            } else {
                                $erros++;
                                $mensagens[] = "⚠️ Erro ao atualizar linha $linhaNum: " . mysqli_stmt_error($stmtUpdate);
                            }
                            continue;
                        }

                        mysqli_stmt_bind_param($stmtInsert, "ssd", $codigoComponente, $data, $quantidade);
                        if (mysqli_stmt_execute($stmtInsert)) {
                            $importados++;
                        } else {
                            $erros++;
                            $mensagens[] = "⚠️ Erro na linha $linhaNum: " . mysqli_stmt_error($stmtInsert);
                        }
                    }
                }

                mysqli_commit($conn);
                mysqli_autocommit($conn, true);

                mysqli_stmt_close($stmtInsert);
                mysqli_stmt_close($stmtVerifica);
                mysqli_stmt_close($stmtUpdate);

                $resumo = "✅ Importação concluída: $importados inserida(s)";
                if ($atualizados > 0) $resumo .= ", $atualizados atualizada(s)";
                if ($ignorados > 0) $resumo .= ", $ignorados ignorada(s) (já existiam)";
                $resumo .= ", $erros erro(s).";
                $mensagens[] = $resumo;
                }
            }
            fclose($handle);
        }
    }
}

// Alternar o status "atendido" de uma programação. Ao marcar como atendido, a
// quantidade é somada automaticamente ao estoque físico (nova linha em "estoque",
// vinculada via origem_programacao_id). Ao reabrir, a mesma linha de estoque é
// removida — desfaz a entrada sem deixar resíduo, mesmo que o estoque tenha
// mudado depois por outros motivos.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'alternar_atendido') {
    $idAlternar = (int) ($_POST['id'] ?? 0);

    if ($idAlternar > 0) {
        $stmtBuscaItem = mysqli_prepare($conn, "SELECT codigo_componente, quantidade, atendido FROM programacao WHERE id = ?");
        mysqli_stmt_bind_param($stmtBuscaItem, 'i', $idAlternar);
        mysqli_stmt_execute($stmtBuscaItem);
        $itemProg = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtBuscaItem));
        mysqli_stmt_close($stmtBuscaItem);

        if ($itemProg) {
            $jaAtendido = (int) $itemProg['atendido'] === 1;

            mysqli_begin_transaction($conn);
            try {
                if ($jaAtendido) {
                    // Reabrir: remove a linha de estoque que essa programação gerou
                    $stmtDelEstoque = mysqli_prepare($conn, "DELETE FROM estoque WHERE origem_programacao_id = ?");
                    mysqli_stmt_bind_param($stmtDelEstoque, 'i', $idAlternar);
                    mysqli_stmt_execute($stmtDelEstoque);
                    mysqli_stmt_close($stmtDelEstoque);

                    $stmtProgOff = mysqli_prepare($conn, "UPDATE programacao SET atendido = 0 WHERE id = ?");
                    mysqli_stmt_bind_param($stmtProgOff, 'i', $idAlternar);
                    mysqli_stmt_execute($stmtProgOff);
                    mysqli_stmt_close($stmtProgOff);
                } else {
                    // Marcar atendido: soma a quantidade recebida no estoque físico
                    $codigoComponente = trim((string) $itemProg['codigo_componente']);
                    $quantidadeRecebida = (float) $itemProg['quantidade'];

                    $stmtDesc = mysqli_prepare($conn, "
                        SELECT MAX(COALESCE(NULLIF(TRIM(descricao), ''), '')) AS descricao
                        FROM bomnova
                        WHERE TRIM(codigo_componente) = ?
                    ");
                    mysqli_stmt_bind_param($stmtDesc, 's', $codigoComponente);
                    mysqli_stmt_execute($stmtDesc);
                    $descricaoComponente = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtDesc))['descricao'] ?? '';
                    mysqli_stmt_close($stmtDesc);

                    $plantaRecebimento = 'Recebido (Programação)';
                    $stmtInsEstoque = mysqli_prepare($conn, "INSERT INTO estoque (codigo_componente, descricao, estoque, planta, origem_programacao_id) VALUES (?, ?, ?, ?, ?)");
                    mysqli_stmt_bind_param($stmtInsEstoque, 'ssdsi', $codigoComponente, $descricaoComponente, $quantidadeRecebida, $plantaRecebimento, $idAlternar);
                    mysqli_stmt_execute($stmtInsEstoque);
                    mysqli_stmt_close($stmtInsEstoque);

                    $stmtProgOn = mysqli_prepare($conn, "UPDATE programacao SET atendido = 1 WHERE id = ?");
                    mysqli_stmt_bind_param($stmtProgOn, 'i', $idAlternar);
                    mysqli_stmt_execute($stmtProgOn);
                    mysqli_stmt_close($stmtProgOn);
                }
                mysqli_commit($conn);
            } catch (Throwable $erroToggle) {
                mysqli_rollback($conn);
                error_log('Erro ao alternar atendido na programação: ' . $erroToggle->getMessage());
            }
        }
    }

    header('Location: programacao.php?' . http_build_query([
        'pagina' => $_POST['pagina_atual'] ?? 1,
        'busca'  => $_POST['busca_atual'] ?? '',
        'filtro' => $_POST['filtro_atual'] ?? '',
    ]));
    exit;
}

// Inserção manual de uma nova programação direto pelo site, sem CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'inserir_manual') {
    $componenteManual = trim($_POST['componente_manual'] ?? '');
    $dataManual = parseDataProgramacao(trim($_POST['data_manual'] ?? ''));
    $quantidadeManual = parseQuantidade(trim($_POST['quantidade_manual'] ?? ''));

    $flash = 'erro_dados';
    if ($componenteManual !== '' && $dataManual !== null && $quantidadeManual !== null) {
        $stmtInsManual = mysqli_prepare($conn, "INSERT INTO programacao (codigo_componente, data, quantidade) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmtInsManual, "ssd", $componenteManual, $dataManual, $quantidadeManual);
        mysqli_stmt_execute($stmtInsManual);
        mysqli_stmt_close($stmtInsManual);
        $flash = 'inserido';
    }

    header('Location: programacao.php?' . http_build_query([
        'pagina' => $_POST['pagina_atual'] ?? 1,
        'busca'  => $_POST['busca_atual'] ?? '',
        'filtro' => $_POST['filtro_atual'] ?? '',
        'flash'  => $flash,
    ]));
    exit;
}

// Edição direta de data/quantidade de uma programação já existente, sem CSV.
// Bloqueada se o item já estiver atendido (já virou estoque físico — editar
// aqui deixaria a quantidade da programação e a do estoque dessincronizadas;
// é preciso reabrir primeiro).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar_registro') {
    $idEditar = (int) ($_POST['id'] ?? 0);
    $dataEditada = parseDataProgramacao(trim($_POST['data_editada'] ?? ''));
    $quantidadeEditada = parseQuantidade(trim($_POST['quantidade_editada'] ?? ''));

    $flash = 'erro_dados';
    if ($idEditar > 0 && $dataEditada !== null && $quantidadeEditada !== null) {
        $stmtCheckAtendido = mysqli_prepare($conn, "SELECT atendido FROM programacao WHERE id = ?");
        mysqli_stmt_bind_param($stmtCheckAtendido, 'i', $idEditar);
        mysqli_stmt_execute($stmtCheckAtendido);
        $itemCheck = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtCheckAtendido));
        mysqli_stmt_close($stmtCheckAtendido);

        if ($itemCheck && (int) $itemCheck['atendido'] === 1) {
            $flash = 'erro_atendido';
        } elseif ($itemCheck) {
            $stmtEditar = mysqli_prepare($conn, "UPDATE programacao SET data = ?, quantidade = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmtEditar, "sdi", $dataEditada, $quantidadeEditada, $idEditar);
            mysqli_stmt_execute($stmtEditar);
            mysqli_stmt_close($stmtEditar);
            $flash = 'editado';
        }
    }

    header('Location: programacao.php?' . http_build_query([
        'pagina' => $_POST['pagina_atual'] ?? 1,
        'busca'  => $_POST['busca_atual'] ?? '',
        'filtro' => $_POST['filtro_atual'] ?? '',
        'flash'  => $flash,
    ]));
    exit;
}

function h($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}
$porPagina = 50;
$pagina = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$offset = ($pagina - 1) * $porPagina;

$busca  = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$filtro = isset($_GET['filtro']) ? trim($_GET['filtro']) : ''; // '', 'pendente', 'atendido'
$editando = isset($_GET['editar']) ? (int) $_GET['editar'] : 0;
$flash = isset($_GET['flash']) ? trim($_GET['flash']) : '';

$flashMap = [
    'inserido'      => ['success', '✅ Programação adicionada com sucesso.'],
    'editado'       => ['success', '✅ Registro atualizado.'],
    'erro_dados'    => ['danger', '❌ Componente, data ou quantidade inválidos.'],
    'erro_atendido' => ['warning', '⚠️ Esse item já está atendido — reabra antes de editar.'],
];

// Lista de componentes existentes na BOM, pra sugerir no campo de entrada manual
$componentesDisponiveis = [];
$resComp = mysqli_query($conn, "SELECT DISTINCT TRIM(codigo_componente) AS codigo FROM bomnova WHERE codigo_componente IS NOT NULL AND TRIM(codigo_componente) <> '' ORDER BY codigo");
if ($resComp) {
    while ($linhaComp = mysqli_fetch_assoc($resComp)) {
        $componentesDisponiveis[] = $linhaComp['codigo'];
    }
}

$condicoes = [];
$params = [];
$tipos = '';

if ($busca !== '') {
    $condicoes[] = "p.codigo_componente LIKE ?";
    $buscaLike = "%$busca%";
    $params[] = $buscaLike;
    $tipos .= 's';
}

if ($filtro === 'pendente') {
    $condicoes[] = "(p.atendido = 0 OR p.atendido IS NULL)";
} elseif ($filtro === 'atendido') {
    $condicoes[] = "p.atendido = 1";
}

$where = $condicoes ? ('WHERE ' . implode(' AND ', $condicoes)) : '';

$sqlTotal = "SELECT COUNT(*) AS total FROM programacao p $where";
if (!empty($params)) {
    $stmtTotal = mysqli_prepare($conn, $sqlTotal);
    mysqli_stmt_bind_param($stmtTotal, $tipos, ...$params);
    mysqli_stmt_execute($stmtTotal);
    $resultTotal = mysqli_stmt_get_result($stmtTotal);
} else {
    $resultTotal = mysqli_query($conn, $sqlTotal);
}
$total = mysqli_fetch_assoc($resultTotal)['total'];
$totalPaginas = max(1, ceil($total / $porPagina));

$sqlSoma = "SELECT SUM(COALESCE(CAST(p.quantidade AS DECIMAL(18,4)), 0)) AS soma FROM programacao p $where";
if (!empty($params)) {
    $stmtSoma = mysqli_prepare($conn, $sqlSoma);
    mysqli_stmt_bind_param($stmtSoma, $tipos, ...$params);
    mysqli_stmt_execute($stmtSoma);
    $resultSoma = mysqli_stmt_get_result($stmtSoma);
} else {
    $resultSoma = mysqli_query($conn, $sqlSoma);
}
$somaProgramacao = (float) (mysqli_fetch_assoc($resultSoma)['soma'] ?? 0);

// Exportação CSV: traz TODOS os registros filtrados
if (($_GET['exportar'] ?? '') === 'csv') {
    $sqlExport = "SELECT p.codigo_componente, p.data, p.quantidade, p.atendido FROM programacao p $where ORDER BY p.data, p.codigo_componente";
    if (!empty($params)) {
        $stmtExport = mysqli_prepare($conn, $sqlExport);
        mysqli_stmt_bind_param($stmtExport, $tipos, ...$params);
        mysqli_stmt_execute($stmtExport);
        $resultExport = mysqli_stmt_get_result($stmtExport);
    } else {
        $resultExport = mysqli_query($conn, $sqlExport);
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="programacao-' . date('Y-m-d-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $saida = fopen('php://output', 'w');
    fputcsv($saida, ['Componente', 'Data', 'Quantidade', 'Atendido'], ';', '"', '');
    while ($linha = mysqli_fetch_assoc($resultExport)) {
        $data = $linha['data'] ? (new DateTimeImmutable($linha['data']))->format('d/m/Y') : '';
       
        $quantidadeExportada = number_format(
    (float) $linha['quantidade'],
    0,
    ',',
    ''
);

fputcsv(
    $saida,
    [$linha['codigo_componente'], $data, $quantidadeExportada, ((int) ($linha['atendido'] ?? 0) === 1) ? 'Sim' : 'Não'],
    ';',
    '"',
    ''
);
    }
    fclose($saida);
    exit;
}

$sql = "SELECT p.id, p.codigo_componente, p.data, p.quantidade, p.atendido,
               bg.descricao, bg.fornecedores
        FROM programacao p
        LEFT JOIN (
            SELECT TRIM(codigo_componente) AS codigo_componente,
                   MAX(COALESCE(NULLIF(TRIM(descricao), ''), '')) AS descricao,
                   GROUP_CONCAT(DISTINCT NULLIF(TRIM(fornecedor), '') ORDER BY TRIM(fornecedor) SEPARATOR ', ') AS fornecedores
            FROM bomnova
            GROUP BY TRIM(codigo_componente)
        ) bg ON bg.codigo_componente = TRIM(p.codigo_componente)
        $where
        ORDER BY p.data, p.codigo_componente
        LIMIT ? OFFSET ?";

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
    <title>📅 Programação</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; padding: 20px; }
        .card { border-radius: 15px; box-shadow: 0 2px 20px rgba(0,0,0,0.08); }
        .bg-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important; }
        .table th { background: #f8f9fa; white-space: nowrap; }
        .table td { white-space: nowrap; vertical-align: middle; }
        .form-check { padding: 10px; border: 1px solid #e0e0e0; border-radius: 8px; margin-bottom: 8px; }
        .form-check:hover { background: #f8f9fa; }
        summary { cursor: pointer; font-weight: 700; color: #405164; }

        .situacao-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border: none;
            border-radius: 999px;
            font-size: .74rem;
            font-weight: 700;
            line-height: 1.2;
            cursor: pointer;
            white-space: nowrap;
            transition: filter .15s ease, transform .05s ease;
        }
        .situacao-toggle:hover { filter: brightness(0.94); }
        .situacao-toggle:active { transform: scale(0.97); }
        .situacao-toggle .dot { width: 6px; height: 6px; border-radius: 50%; flex: 0 0 auto; }
        .situacao-toggle.is-pendente { background: #fff3cd; color: #a96600; }
        .situacao-toggle.is-pendente .dot { background: #d88b0b; }
        .situacao-toggle.is-atendido { background: #eaf8f0; color: #247a4d; }
        .situacao-toggle.is-atendido .dot { background: #247a4d; }

        .text-truncate-cell {
            display: inline-block;
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            vertical-align: middle;
        }
    </style>
</head>
<body>
    <div class="container" style="max-width: 1180px;">
        <div class="card bg-primary text-white p-4 mb-4">
            <h1>📅 Programação de Entradas</h1>
            <p class="mb-0">
                <?php echo number_format($total, 0, ',', '.'); ?> registro(s) na base
                • soma das quantidades: <?php echo number_format($somaProgramacao, 2, ',', '.'); ?>
            </p>
        </div>

        <div class="card p-3 mb-4">
            <details <?php echo !empty($mensagens) ? 'open' : ''; ?>>
                <summary>📥 Importar novo arquivo CSV</summary>
                <div class="mt-3">
                    <?php if (!empty($mensagens)): ?>
                    <div class="mb-3">
                        <?php foreach ($mensagens as $msg): ?>
                            <div><?php echo htmlspecialchars($msg); ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label">Arquivo CSV</label>
                            <input type="file" name="arquivo_csv" accept=".csv" class="form-control" required>
                        </div>

                        <label class="form-label"><strong>O que fazer com os dados?</strong></label>

                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="modo" id="modo_adicionar" value="adicionar" checked>
                            <label class="form-check-label" for="modo_adicionar">
                                <strong>Adicionar</strong> — insere as linhas do arquivo, mesmo se já existirem (pode duplicar)
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="modo" id="modo_sem_duplicar" value="sem_duplicar">
                            <label class="form-check-label" for="modo_sem_duplicar">
                                <strong>Adicionar sem duplicar</strong> — ignora linhas cujo Componente+Data já existe
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="modo" id="modo_atualizar" value="atualizar">
                            <label class="form-check-label" for="modo_atualizar">
                                <strong>Adicionar e atualizar</strong> — se já existir (mesmo Componente+Data), atualiza a quantidade; senão insere novo
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="modo" id="modo_substituir" value="substituir">
                            <label class="form-check-label" for="modo_substituir">
                                <strong>Substituir tudo</strong> — apaga toda a programação atual e importa somente o que está no arquivo
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary mt-2">Importar</button>
                    </form>

                    <hr>
                    <small class="text-muted">
                        <strong>Formato simples</strong> (uma programação por linha):<br>
                        <code>codigo_componente, data, quantidade</code><br><br>
                        <strong>Formato da planilha</strong> (várias programações na mesma linha, como no Excel):<br>
                        <code>Componente, ..., Programação 1, Quantidade, Programação 2, Quantidade 2, Programação 3, Quantidade...</code><br>
                        Qualquer coluna com "quantidade" no nome é pareada automaticamente com a coluna de data logo antes dela. Deixe em branco as programações que não existirem.<br><br>
                        Data em DD/MM/AAAA ou AAAA-MM-DD. Separador: vírgula ou ponto e vírgula (detectado automaticamente).
                    </small>
                </div>
            </details>
        </div>

        <?php if ($flash !== '' && isset($flashMap[$flash])): ?>
            <div class="alert alert-<?php echo $flashMap[$flash][0]; ?> py-2"><?php echo $flashMap[$flash][1]; ?></div>
        <?php endif; ?>

        <datalist id="lista_componentes">
            <?php foreach ($componentesDisponiveis as $c): ?>
                <option value="<?php echo h($c); ?>">
            <?php endforeach; ?>
        </datalist>

        <div class="card p-3 mb-4">
            <h2 class="h6 mb-3">➕ Nova programação (entrada manual)</h2>
            <form method="POST" class="row g-2 align-items-end">
                <input type="hidden" name="acao" value="inserir_manual">
                <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                <input type="hidden" name="busca_atual" value="<?php echo h($busca); ?>">
                <input type="hidden" name="filtro_atual" value="<?php echo h($filtro); ?>">
                <div class="col-auto">
                    <label class="form-label small mb-1">Componente</label>
                    <input type="text" name="componente_manual" list="lista_componentes" class="form-control form-control-sm" placeholder="Ex.: 12000586" required>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1">Data</label>
                    <input type="date" name="data_manual" class="form-control form-control-sm" required>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1">Quantidade</label>
                    <input type="text" name="quantidade_manual" class="form-control form-control-sm text-end" placeholder="Ex.: 1500" required>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">Adicionar</button>
                </div>
            </form>
        </div>

        <div class="card p-3 mb-4">
            <form method="GET" class="row g-2 align-items-center">
                <input type="hidden" name="filtro" value="<?php echo h($filtro); ?>">
                <div class="col-auto flex-grow-1">
                    <input type="text" name="busca" class="form-control" placeholder="Buscar por componente..." value="<?php echo h($busca); ?>">
                </div>
                <div class="col-auto">
                    <div class="btn-group" role="group">
                        <a href="?busca=<?php echo urlencode($busca); ?>&filtro=" class="btn btn-outline-secondary btn-sm <?php echo $filtro === '' ? 'active' : ''; ?>">Todos</a>
                        <a href="?busca=<?php echo urlencode($busca); ?>&filtro=pendente" class="btn btn-outline-secondary btn-sm <?php echo $filtro === 'pendente' ? 'active' : ''; ?>">Pendentes</a>
                        <a href="?busca=<?php echo urlencode($busca); ?>&filtro=atendido" class="btn btn-outline-secondary btn-sm <?php echo $filtro === 'atendido' ? 'active' : ''; ?>">Atendidos</a>
                    </div>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">Buscar</button>
                    <a href="programacao.php" class="btn btn-outline-secondary">Limpar</a>
                    <a href="?busca=<?php echo urlencode($busca); ?>&filtro=<?php echo urlencode($filtro); ?>&exportar=csv" class="btn btn-outline-primary">Exportar CSV</a>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-body table-responsive">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Situação</th>
                            <th>Componente</th>
                            <th>Descrição</th>
                            <th>Fornecedor</th>
                            <th>Data</th>
                            <th class="text-end">Quantidade</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="7" class="text-center text-muted">Nenhum registro encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $estaAtendido = (int) ($row['atendido'] ?? 0) === 1;
                                $idLinha = (int) $row['id'];
                                $emEdicao = ($editando === $idLinha);
                                $linkVoltar = '?pagina=' . $pagina . '&busca=' . urlencode($busca) . '&filtro=' . urlencode($filtro);
                                ?>
                                <tr>
                                    <td>
                                        <form method="POST" class="m-0">
                                            <input type="hidden" name="acao" value="alternar_atendido">
                                            <input type="hidden" name="id" value="<?php echo $idLinha; ?>">
                                            <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                                            <input type="hidden" name="busca_atual" value="<?php echo h($busca); ?>">
                                            <input type="hidden" name="filtro_atual" value="<?php echo h($filtro); ?>">
                                            <button type="submit"
                                                    class="situacao-toggle <?php echo $estaAtendido ? 'is-atendido' : 'is-pendente'; ?>"
                                                    title="<?php echo $estaAtendido ? 'Clique para reabrir (remove a entrada do estoque)' : 'Clique para marcar como atendido (soma no estoque)'; ?>">
                                                <span class="dot"></span>
                                                <?php echo $estaAtendido ? 'Atendido' : 'Pendente'; ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td><strong><?php echo h($row['codigo_componente'] ?? ''); ?></strong></td>
                                    <td title="<?php echo h($row['descricao'] ?? ''); ?>"><span class="text-truncate-cell"><?php echo h($row['descricao'] ?? ''); ?></span></td>
                                    <td title="<?php echo h($row['fornecedores'] ?? ''); ?>"><span class="text-truncate-cell"><?php echo h($row['fornecedores'] ?? ''); ?></span></td>

                                    <?php if ($emEdicao): ?>
                                        <td colspan="3">
                                            <form method="POST" class="d-flex gap-2 align-items-center flex-wrap m-0">
                                                <input type="hidden" name="acao" value="editar_registro">
                                                <input type="hidden" name="id" value="<?php echo $idLinha; ?>">
                                                <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                                                <input type="hidden" name="busca_atual" value="<?php echo h($busca); ?>">
                                                <input type="hidden" name="filtro_atual" value="<?php echo h($filtro); ?>">
                                                <input type="date" name="data_editada" class="form-control form-control-sm" style="width:150px" value="<?php echo h($row['data'] ?? ''); ?>" required>
                                                <input type="text" name="quantidade_editada" class="form-control form-control-sm text-end" style="width:120px" value="<?php echo h(number_format((float) $row['quantidade'], 2, ',', '')); ?>" required>
                                                <button type="submit" class="btn btn-success btn-sm">Salvar</button>
                                                <a href="<?php echo $linkVoltar; ?>" class="btn btn-outline-secondary btn-sm">Cancelar</a>
                                            </form>
                                        </td>
                                    <?php else: ?>
                                        <td><?php echo $row['data'] ? h((new DateTimeImmutable($row['data']))->format('d/m/Y')) : ''; ?></td>
                                        <td class="text-end"><?php echo number_format((float) $row['quantidade'], 2, ',', '.'); ?></td>
                                        <td>
                                            <?php if ($estaAtendido): ?>
                                                <span class="text-muted small" title="Reabra o item antes de editar">—</span>
                                            <?php else: ?>
                                                <a href="<?php echo $linkVoltar; ?>&editar=<?php echo $idLinha; ?>" class="btn btn-outline-secondary btn-sm">Editar</a>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-3">
            <div>
                <?php if ($pagina > 1): ?>
                    <a href="?pagina=<?php echo $pagina - 1; ?>&busca=<?php echo urlencode($busca); ?>&filtro=<?php echo urlencode($filtro); ?>" class="btn btn-outline-primary btn-sm">← Anterior</a>
                <?php endif; ?>
            </div>
            <div class="text-muted">Página <?php echo $pagina; ?> de <?php echo $totalPaginas; ?></div>
            <div>
                <?php if ($pagina < $totalPaginas): ?>
                    <a href="?pagina=<?php echo $pagina + 1; ?>&busca=<?php echo urlencode($busca); ?>&filtro=<?php echo urlencode($filtro); ?>" class="btn btn-outline-primary btn-sm">Próxima →</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="text-center mt-4">
            <a href="index.php" class="btn btn-outline-secondary">Voltar ao Dashboard</a>
        </div>
    </div>
</body>
</html>
