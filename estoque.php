<?php
require_once 'conexao.php';

set_time_limit(300);

// Interpreta números em formato BR: "1.400" = 1400 (milhar), "1.234,56" = 1234.56,
// "1234,5" = 1234.5. Se o ponto sozinho não parecer um agrupamento de milhar
// (grupos de exatamente 3 dígitos), é tratado como separador decimal mesmo.
function parseNumeroBr(string $valor): ?float
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

function numeroCsvBr($valor, int $decimais = 4): string
{
    if ($valor === null || $valor === '') {
        return '';
    }

    $numero = number_format((float) $valor, $decimais, ',', '');

    if ($decimais > 0) {
        $numero = rtrim($numero, '0');
        $numero = rtrim($numero, ',');
    }

    return $numero;
}
function normalizarTextoEstoque(string $texto): string
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

$mensagens = [];
$importados = 0;
$erros = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo_csv'])) {
    $arquivo = $_FILES['arquivo_csv']['tmp_name'];

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

                $cabecalho = array_map('normalizarTextoEstoque', $cabecalhoOriginal);

                $idxCodigo = null;
                $idxDescricao = null;
                $idxEstoqueTotal = null;
                $idxMrp = null;
                
                foreach ([
                           'codigo_componente',
                           'componente',
                           'codigo do componente',
                           'codigo componente',
                            'cod componente',
                            'codigo_do_componente'
                ] as $candidato) {
                    $pos = array_search($candidato, $cabecalho, true);
                    if ($pos !== false) { $idxCodigo = $pos; break; }
                }
                $idxDescricao = array_search('descricao', $cabecalho, true);
                $idxEstoqueTotal = array_search('estoque', $cabecalho, true);
                $idxMrp = array_search('mrp', $cabecalho, true);

                // Qualquer coluna que não seja codigo/descricao/estoque/mrp é tratada como
                // uma coluna de planta (ex.: "2401", "2403"), usando o texto original
                // do cabeçalho (sem normalizar) como identificador da planta. A coluna
                // "mrp" (se vier na planilha) é ignorada aqui — o status MRP já é mostrado
                // na tela com base na BOM, não é algo que essa importação de estoque grava.
                $colunasPlanta = [];
                foreach ($cabecalhoOriginal as $indice => $nomeOriginal) {
                    if ($indice === $idxCodigo || $indice === $idxDescricao || $indice === $idxEstoqueTotal || $indice === $idxMrp) {
                        continue;
                    }
                    $nomeLimpo = trim($nomeOriginal);
                    if ($nomeLimpo === '') {
                        continue;
                    }
                    $colunasPlanta[$indice] = $nomeLimpo;
                }

                if ($idxCodigo === null) {
                    $mensagens[] = "❌ Não encontrei a coluna do componente. Use 'codigo_componente' no cabeçalho.";
                } else {
                if (isset($_POST['limpar_tabela'])) {
                    mysqli_query($conn, "TRUNCATE TABLE estoque");
                    $mensagens[] = "🗑️ Tabela 'estoque' esvaziada antes da importação.";
                }

                $tamanhoLote = 200;
                $lote = [];

                $flushLote = function () use ($conn, &$lote, &$importados, &$erros, &$mensagens) {
                    if (empty($lote)) {
                        return;
                    }
                    $linhasSql = [];
                    foreach ($lote as $valores) {
                        [$cod, $desc, $est, $planta] = $valores;
                        $plantaSql = $planta === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, (string) $planta) . "'";
                        $linhasSql[] = "('" . mysqli_real_escape_string($conn, (string) $cod) . "', '"
                            . mysqli_real_escape_string($conn, (string) $desc) . "', " . (float) $est . ", " . $plantaSql . ")";
                    }
                    $sql = "INSERT INTO estoque (codigo_componente, descricao, estoque, planta) VALUES " . implode(', ', $linhasSql);

                    if (mysqli_query($conn, $sql)) {
                        $importados += count($lote);
                    } else {
                        $erros += count($lote);
                        $mensagens[] = "⚠️ Erro ao inserir um lote de " . count($lote) . " linha(s): " . mysqli_error($conn);
                    }
                    $lote = [];
                };

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

                    $codigo_componente = trim((string) ($linha[$idxCodigo] ?? ''));
                    if ($codigo_componente === '') {
                        $erros++;
                        $mensagens[] = "⚠️ Linha $linhaNum ignorada: componente vazio.";
                        continue;
                    }
                    $descricao = $idxDescricao !== null ? ($linha[$idxDescricao] ?? '') : '';

                    $gravouAlgumaPlanta = false;
                    foreach ($colunasPlanta as $indice => $nomePlanta) {
                        $valorBruto = trim((string) ($linha[$indice] ?? ''));
                        if ($valorBruto === '') {
                            continue; // planta sem valor nessa linha, pula sem erro
                        }
                        $valor = parseNumeroBr($valorBruto);
                        if ($valor === null) {
                            $erros++;
                            $mensagens[] = "⚠️ Linha $linhaNum, planta '$nomePlanta': valor '$valorBruto' inválido.";
                            continue;
                        }
                        $lote[] = [$codigo_componente, $descricao, $valor, $nomePlanta];
                        $gravouAlgumaPlanta = true;

                        if (count($lote) >= $tamanhoLote) {
                            $flushLote();
                        }
                    }

                    // Sem nenhuma coluna de planta preenchida: usa a coluna "estoque" total,
                    // como no formato simples de sempre (sem planta definida).
                    if (!$gravouAlgumaPlanta && $idxEstoqueTotal !== null) {
                        $estoqueBruto = (string) ($linha[$idxEstoqueTotal] ?? '0');
                        $estoque = parseNumeroBr($estoqueBruto) ?? 0;
                        $lote[] = [$codigo_componente, $descricao, $estoque, null];

                        if (count($lote) >= $tamanhoLote) {
                            $flushLote();
                        }
                    }
                }
                $flushLote();

                mysqli_commit($conn);
                mysqli_autocommit($conn, true);

                $mensagens[] = "✅ Importação concluída: $importados linha(s) importada(s), $erros erro(s).";
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
    $where = "WHERE e.codigo_componente LIKE ? OR e.descricao LIKE ?";
    $buscaLike = "%$busca%";
    $params = [$buscaLike, $buscaLike];
    $tipos = 'ss';
}

// Lista de plantas existentes na base (vira uma coluna por planta na tabela)
$plantas = [];
$resPlantas = mysqli_query($conn, "SELECT DISTINCT planta FROM estoque WHERE planta IS NOT NULL AND TRIM(planta) <> '' ORDER BY planta");
while ($linhaPlanta = mysqli_fetch_assoc($resPlantas)) {
    $plantas[] = $linhaPlanta['planta'];
}

// Existe alguma linha sem planta definida? (formato simples antigo, sem quebra por planta)
$resSemPlanta = mysqli_query($conn, "SELECT COUNT(*) AS total FROM estoque WHERE planta IS NULL OR TRIM(planta) = ''");
$temSemPlanta = (int) (mysqli_fetch_assoc($resSemPlanta)['total'] ?? 0) > 0;

// Total de componentes distintos (para paginação)
$sqlTotal = "SELECT COUNT(DISTINCT e.codigo_componente) AS total FROM estoque e $where";
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

// Soma geral de estoque (todas as plantas), útil pra ter noção geral da base
$sqlSoma = "SELECT SUM(COALESCE(CAST(e.estoque AS DECIMAL(18,4)), 0)) AS soma FROM estoque e $where";
if ($busca !== '') {
    $stmtSoma = mysqli_prepare($conn, $sqlSoma);
    mysqli_stmt_bind_param($stmtSoma, $tipos, ...$params);
    mysqli_stmt_execute($stmtSoma);
    $resultSoma = mysqli_stmt_get_result($stmtSoma);
} else {
    $resultSoma = mysqli_query($conn, $sqlSoma);
}
$somaEstoque = (float) (mysqli_fetch_assoc($resultSoma)['soma'] ?? 0);

// Monta uma linha por componente, com descrição, total (soma de todas as plantas) e status MRP
function montarSqlComponentes(string $where): string
{
    return "SELECT e.codigo_componente,
                   MAX(e.descricao) AS descricao,
                   SUM(COALESCE(CAST(e.estoque AS DECIMAL(18,4)), 0)) AS total,
                   MAX(bom.tem_ativo) AS tem_ativo,
                   MAX(bom.total_linhas) AS total_linhas
            FROM estoque e
            LEFT JOIN (
                SELECT TRIM(codigo_componente) AS codigo_componente,
                       MAX(CASE WHEN mrp IS NULL OR TRIM(mrp) = '' OR UPPER(TRIM(mrp)) <> 'N' THEN 1 ELSE 0 END) AS tem_ativo,
                       COUNT(*) AS total_linhas
                FROM bomnova
                GROUP BY TRIM(codigo_componente)
            ) bom ON bom.codigo_componente = TRIM(e.codigo_componente)
            $where
            GROUP BY e.codigo_componente";
}

function statusMrp(?int $totalLinhas, ?int $temAtivo): array
{
    if ($totalLinhas === null) {
        return ['badge-mrp-none', 'Não cadastrado'];
    }
    if ((int) $temAtivo === 1) {
        return ['badge-mrp-s', 'S'];
    }
    return ['badge-mrp-n', 'N'];
}

// Exportação CSV: traz TODOS os componentes filtrados (ignora a paginação da tela)
if (($_GET['exportar'] ?? '') === 'csv') {
    $sqlExport = montarSqlComponentes($where) . " ORDER BY e.codigo_componente";
    if ($busca !== '') {
        $stmtExport = mysqli_prepare($conn, $sqlExport);
        mysqli_stmt_bind_param($stmtExport, $tipos, ...$params);
        mysqli_stmt_execute($stmtExport);
        $resultExport = mysqli_stmt_get_result($stmtExport);
    } else {
        $resultExport = mysqli_query($conn, $sqlExport);
    }

    $componentesExport = [];
    while ($linha = mysqli_fetch_assoc($resultExport)) {
        $componentesExport[$linha['codigo_componente']] = $linha;
    }

    $porPlantaExport = [];
    if (!empty($componentesExport)) {
        $codigos = array_keys($componentesExport);
        $placeholders = implode(',', array_fill(0, count($codigos), '?'));
        $tiposCodigos = str_repeat('s', count($codigos));
        $stmtPlanta = mysqli_prepare($conn, "
            SELECT codigo_componente, COALESCE(NULLIF(TRIM(planta), ''), '') AS planta,
                   SUM(COALESCE(CAST(estoque AS DECIMAL(18,4)), 0)) AS valor
            FROM estoque
            WHERE codigo_componente IN ($placeholders)
            GROUP BY codigo_componente, COALESCE(NULLIF(TRIM(planta), ''), '')
        ");
        mysqli_stmt_bind_param($stmtPlanta, $tiposCodigos, ...$codigos);
        mysqli_stmt_execute($stmtPlanta);
        $resPlantaExport = mysqli_stmt_get_result($stmtPlanta);
        while ($linha = mysqli_fetch_assoc($resPlantaExport)) {
            $porPlantaExport[$linha['codigo_componente']][$linha['planta']] = (float) $linha['valor'];
        }
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="estoque-' . date('Y-m-d-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $saida = fopen('php://output', 'w');

    $cabecalhoCsv = ['Componente', 'Descricao'];
    foreach ($plantas as $p) { $cabecalhoCsv[] = $p; }
    if ($temSemPlanta) { $cabecalhoCsv[] = 'Sem planta'; }
    $cabecalhoCsv[] = 'Total';
    $cabecalhoCsv[] = 'MRP';
    fputcsv($saida, $cabecalhoCsv, ';', '"', '');

    foreach ($componentesExport as $codigo => $linha) {
        [, $mrpTexto] = statusMrp($linha['total_linhas'] !== null ? (int) $linha['total_linhas'] : null, $linha['tem_ativo'] !== null ? (int) $linha['tem_ativo'] : null);
        $linhaCsv = [$codigo, $linha['descricao']];
        foreach ($plantas as $p) {
            $linhaCsv[] = isset($porPlantaExport[$codigo][$p])
          ? numeroCsvBr($porPlantaExport[$codigo][$p])
          : '';
        }
       if ($temSemPlanta) {
    $linhaCsv[] = isset($porPlantaExport[$codigo][''])
        ? numeroCsvBr($porPlantaExport[$codigo][''])
        : '';
        }
        $linhaCsv[] = numeroCsvBr($linha['total']);
        $linhaCsv[] = $mrpTexto;
        fputcsv($saida, $linhaCsv, ';', '"', '');
    }
    fclose($saida);
    exit;
}

$sql = montarSqlComponentes($where) . " ORDER BY e.codigo_componente LIMIT ? OFFSET ?";
$stmt = mysqli_prepare($conn, $sql);
if ($busca !== '') {
    mysqli_stmt_bind_param($stmt, $tipos . 'ii', ...array_merge($params, [$porPagina, $offset]));
} else {
    mysqli_stmt_bind_param($stmt, 'ii', $porPagina, $offset);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$componentes = [];
while ($row = mysqli_fetch_assoc($result)) {
    $componentes[$row['codigo_componente']] = $row;
}

// Busca o detalhamento por planta só dos componentes desta página
$porPlanta = [];
if (!empty($componentes)) {
    $codigos = array_keys($componentes);
    $placeholders = implode(',', array_fill(0, count($codigos), '?'));
    $tiposCodigos = str_repeat('s', count($codigos));
    $stmtPlanta = mysqli_prepare($conn, "
        SELECT codigo_componente, COALESCE(NULLIF(TRIM(planta), ''), '') AS planta,
               SUM(COALESCE(CAST(estoque AS DECIMAL(18,4)), 0)) AS valor
        FROM estoque
        WHERE codigo_componente IN ($placeholders)
        GROUP BY codigo_componente, COALESCE(NULLIF(TRIM(planta), ''), '')
    ");
    mysqli_stmt_bind_param($stmtPlanta, $tiposCodigos, ...$codigos);
    mysqli_stmt_execute($stmtPlanta);
    $resPlantaPagina = mysqli_stmt_get_result($stmtPlanta);
    while ($linha = mysqli_fetch_assoc($resPlantaPagina)) {
        $porPlanta[$linha['codigo_componente']][$linha['planta']] = (float) $linha['valor'];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>🏷️ Estoque</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; padding: 20px; }
        .card { border-radius: 15px; box-shadow: 0 2px 20px rgba(0,0,0,0.08); }
        .bg-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important; }
        .table th { background: #f8f9fa; white-space: nowrap; }
        .table td { white-space: nowrap; }
        .badge-mrp-s { background: #eaf8f0; color: #247a4d; }
        .badge-mrp-n { background: #fff0f0; color: #c53535; }
        .badge-mrp-none { background: #eef2f5; color: #637485; }
        .col-total { font-weight: 750; background: #f8f9fa; }
        summary { cursor: pointer; font-weight: 700; color: #405164; }
    </style>
</head>
<body>
    <div class="container-fluid" style="max-width: 1400px;">
        <div class="card bg-primary text-white p-4 mb-4">
            <h1>🏷️ Estoque</h1>
            <p class="mb-0">
                <?php echo number_format($total, 0, ',', '.'); ?> componente(s) na base
                • soma geral do estoque (todas as plantas): <?php echo number_format($somaEstoque, 2, ',', '.'); ?>
            </p>
        </div>

        <div class="card p-3 mb-4">
            <details <?php echo !empty($mensagens) ? 'open' : ''; ?>>
                <summary>📥 Importar novo arquivo CSV</summary>
                <div class="mt-3">
                    <?php if (!empty($mensagens)): ?>
                        <div class="mb-3">
                            <?php foreach ($mensagens as $msg): ?>
                                <div><?php echo h($msg); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data" class="row g-2 align-items-end">
                        <div class="col-12 col-md-6">
                            <label class="form-label">Arquivo CSV</label>
                            <input type="file" name="arquivo_csv" accept=".csv" class="form-control" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="limpar_tabela" id="limpar_tabela">
                                <label class="form-check-label" for="limpar_tabela">
                                    Limpar tabela antes de importar
                                </label>
                            </div>
                        </div>
                        <div class="col-12 col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Importar</button>
                        </div>
                    </form>

                    <hr>
                    <small class="text-muted">
                        <strong>Formato simples</strong> (uma linha por componente):<br>
                        <code>codigo_componente, descricao, estoque</code><br><br>
                        <strong>Formato por planta</strong> (uma coluna de estoque por planta, como no Excel):<br>
                        <code>codigo_componente, descricao, estoque, 2401, 2403, ...</code><br>
                        Qualquer coluna que não seja <code>codigo_componente</code>, <code>descricao</code> ou <code>estoque</code> é tratada como uma planta. A coluna <code>estoque</code> (total) não é gravada nesse formato — o sistema soma sozinho o valor de todas as plantas.<br><br>
                        Números aceitam formato "1234.56" ou "1.234,56". Separador: vírgula ou ponto e vírgula (detectado automaticamente).
                    </small>
                </div>
            </details>
        </div>

        <div class="card p-3 mb-4">
            <form method="GET" class="row g-2">
                <div class="col-auto flex-grow-1">
                    <input type="text" name="busca" class="form-control" placeholder="Buscar por componente ou descrição..." value="<?php echo h($busca); ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">Buscar</button>
                    <a href="estoque.php" class="btn btn-outline-secondary">Limpar</a>
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
                            <th>Descrição</th>
                            <?php foreach ($plantas as $p): ?>
                                <th class="text-end">Estoque <?php echo h($p); ?></th>
                            <?php endforeach; ?>
                            <?php if ($temSemPlanta): ?>
                                <th class="text-end">Sem planta</th>
                            <?php endif; ?>
                            <th class="text-end col-total">Total</th>
                            <th>MRP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($componentes)): ?>
                            <tr><td colspan="<?php echo 4 + count($plantas) + ($temSemPlanta ? 1 : 0); ?>" class="text-center text-muted">Nenhum registro encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($componentes as $codigo => $linha): ?>
                                <?php
                                    [$badgeClasse, $badgeTexto] = statusMrp(
                                        $linha['total_linhas'] !== null ? (int) $linha['total_linhas'] : null,
                                        $linha['tem_ativo'] !== null ? (int) $linha['tem_ativo'] : null
                                    );
                                ?>
                                <tr>
                                    <td><strong><?php echo h($codigo); ?></strong></td>
                                    <td><?php echo h($linha['descricao'] ?? ''); ?></td>
                                    <?php foreach ($plantas as $p): ?>
                                        <td class="text-end"><?php echo isset($porPlanta[$codigo][$p]) ? number_format($porPlanta[$codigo][$p], 2, ',', '.') : '—'; ?></td>
                                    <?php endforeach; ?>
                                    <?php if ($temSemPlanta): ?>
                                        <td class="text-end"><?php echo isset($porPlanta[$codigo]['']) ? number_format($porPlanta[$codigo][''], 2, ',', '.') : '—'; ?></td>
                                    <?php endif; ?>
                                    <td class="text-end col-total"><?php echo number_format((float) $linha['total'], 2, ',', '.'); ?></td>
                                    <td><span class="badge <?php echo $badgeClasse; ?>"><?php echo h($badgeTexto); ?></span></td>
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
