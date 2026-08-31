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

function h($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

$porPagina = 50;
$pagina = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$offset = ($pagina - 1) * $porPagina;

$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';

$where = '';
$params = [];
$tipos = '';

if ($busca !== '') {
    $where = "WHERE codigo_componente LIKE ?";
    $buscaLike = "%$busca%";
    $params = [$buscaLike];
    $tipos = 's';
}

$sqlTotal = "SELECT COUNT(*) AS total FROM programacao $where";
if ($busca !== '') {
    $stmtTotal = mysqli_prepare($conn, $sqlTotal);
    mysqli_stmt_bind_param($stmtTotal, $tipos, ...$params);
    mysqli_stmt_execute($stmtTotal);
    $resultTotal = mysqli_stmt_get_result($stmtTotal);
} else {
    $resultTotal = mysqli_query($conn, $sqlTotal);
}
$total = mysqli_fetch_assoc($resultTotal)['total'];
$totalPaginas = max(1, ceil($total / $porPagina));

$sqlSoma = "SELECT SUM(COALESCE(CAST(quantidade AS DECIMAL(18,4)), 0)) AS soma FROM programacao $where";
if ($busca !== '') {
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
    $sqlExport = "SELECT codigo_componente, data, quantidade FROM programacao $where ORDER BY data, codigo_componente";
    if ($busca !== '') {
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
    fputcsv($saida, ['Componente', 'Data', 'Quantidade'], ';', '"', '');
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
    [$linha['codigo_componente'], $data, $quantidadeExportada],
    ';',
    '"',
    ''
);
    }
    fclose($saida);
    exit;
}

$sql = "SELECT codigo_componente, data, quantidade
        FROM programacao $where
        ORDER BY data, codigo_componente
        LIMIT ? OFFSET ?";

$stmt = mysqli_prepare($conn, $sql);
if ($busca !== '') {
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
        .table td { white-space: nowrap; }
        .form-check { padding: 10px; border: 1px solid #e0e0e0; border-radius: 8px; margin-bottom: 8px; }
        .form-check:hover { background: #f8f9fa; }
        summary { cursor: pointer; font-weight: 700; color: #405164; }
    </style>
</head>
<body>
    <div class="container" style="max-width: 900px;">
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

        <div class="card p-3 mb-4">
            <form method="GET" class="row g-2">
                <div class="col-auto flex-grow-1">
                    <input type="text" name="busca" class="form-control" placeholder="Buscar por componente..." value="<?php echo h($busca); ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">Buscar</button>
                    <a href="programacao.php" class="btn btn-outline-secondary">Limpar</a>
                    <a href="?busca=<?php echo urlencode($busca); ?>&exportar=csv" class="btn btn-outline-primary">Exportar CSV</a>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-body table-responsive">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Componente</th>
                            <th>Data</th>
                            <th class="text-end">Quantidade</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="3" class="text-center text-muted">Nenhum registro encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><strong><?php echo h($row['codigo_componente'] ?? ''); ?></strong></td>
                                    <td><?php echo $row['data'] ? h((new DateTimeImmutable($row['data']))->format('d/m/Y')) : ''; ?></td>
                                    <td class="text-end"><?php echo number_format((float) $row['quantidade'], 2, ',', '.'); ?></td>
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
                    <a href="?pagina=<?php echo $pagina - 1; ?>&busca=<?php echo urlencode($busca); ?>" class="btn btn-outline-primary btn-sm">← Anterior</a>
                <?php endif; ?>
            </div>
            <div class="text-muted">Página <?php echo $pagina; ?> de <?php echo $totalPaginas; ?></div>
            <div>
                <?php if ($pagina < $totalPaginas): ?>
                    <a href="?pagina=<?php echo $pagina + 1; ?>&busca=<?php echo urlencode($busca); ?>" class="btn btn-outline-primary btn-sm">Próxima →</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="text-center mt-4">
            <a href="index.php" class="btn btn-outline-secondary">Voltar ao Dashboard</a>
        </div>
    </div>
</body>
</html>
