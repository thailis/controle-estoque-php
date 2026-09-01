<?php
require_once 'conexao.php';

set_time_limit(300);

// Interpreta números em formato BR: "1.400" = 1400 (milhar), "1.234,56" = 1234.56.
function parseNumeroBrParametros(string $valor): ?float
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

function normalizarTextoParametros(string $texto): string
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
$linhasProcessadas = [];
$totalProcessadas = 0;
$limiteExibicao = 500;

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
                if (isset($cabecalhoOriginal[0])) {
                    $cabecalhoOriginal[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cabecalhoOriginal[0]);
                }
                $cabecalho = array_map('normalizarTextoParametros', $cabecalhoOriginal);

                $mapaColunas = [
                    'codigo_componente' => [
                    'codigo_componente',
                    'componente',
                    'codigo do componente',
                    'codigo componente',
                    'cod componente',
                    'codigo_do_componente'
                ],
                    'moq' => ['moq'],
                    'frozen_zone_dias' => ['frozen_zone_dias', 'frozen_zone', 'frozenzone'],
                    'transit_time_dias' => ['transit_time_dias', 'transit_time', 'transittime'],
                    'estoque_min_dias' => ['estoque_min_dias', 'min', 'min_dias'],
                    'estoque_max_dias' => ['estoque_max_dias', 'max', 'max_dias'],
                    'setup' => ['setup', 'scrap', 'percentual_perda', 'perda'],
                ];
                $indices = [];
                foreach ($mapaColunas as $campo => $candidatos) {
                    $indices[$campo] = null;
                    foreach ($candidatos as $c) {
                        $pos = array_search($c, $cabecalho, true);
                        if ($pos !== false) { $indices[$campo] = $pos; break; }
                    }
                }

                if ($indices['codigo_componente'] === null) {
                    $mensagens[] = "❌ Não encontrei a coluna do componente. Use 'codigo_componente' no cabeçalho.";
                } else {
                    if (isset($_POST['limpar_tabela'])) {
                        mysqli_query($conn, "TRUNCATE TABLE parametros_compra");
                        $mensagens[] = "🗑️ Tabela 'parametros_compra' esvaziada antes da importação.";
                    }

                    $tamanhoLote = 200;
                    $lote = [];
                    $linhasProcessadas = [];
                    $totalProcessadas = 0;
                    $limiteExibicao = 500;

                    $flushLote = function () use ($conn, &$lote, &$importados, &$erros, &$mensagens, &$linhasProcessadas, &$totalProcessadas, $limiteExibicao) {
                        if (empty($lote)) return;
                        $linhasSql = [];
                        foreach ($lote as $v) {
                            $vals = array_map(function ($x) use ($conn) {
                                return $x === null ? 'NULL' : (is_numeric($x) ? $x : "'" . mysqli_real_escape_string($conn, (string) $x) . "'");
                            }, $v);
                            $linhasSql[] = '(' . implode(', ', $vals) . ')';
                        }
                        $sql = "INSERT INTO parametros_compra (codigo_componente, moq, frozen_zone_dias, transit_time_dias, estoque_min_dias, estoque_max_dias, setup) VALUES "
                            . implode(', ', $linhasSql)
                            . " ON DUPLICATE KEY UPDATE moq = VALUES(moq), frozen_zone_dias = VALUES(frozen_zone_dias), "
                            . "transit_time_dias = VALUES(transit_time_dias), estoque_min_dias = VALUES(estoque_min_dias), estoque_max_dias = VALUES(estoque_max_dias), "
                            . "setup = VALUES(setup)";
                        $sucesso = mysqli_query($conn, $sql);
                        if ($sucesso) {
                            $importados += count($lote);
                        } else {
                            $erros += count($lote);
                            $mensagens[] = "⚠️ Erro ao inserir um lote de " . count($lote) . " linha(s): " . mysqli_error($conn);
                        }
                        $resultado = $sucesso ? 'inserido' : 'erro';
                        foreach ($lote as $v) {
                            [$codigo, $moq, $frozen, $transit, $min, $max, $setup] = $v;
                            $totalProcessadas++;
                            if ($totalProcessadas <= $limiteExibicao) {
                                $linhasProcessadas[] = [
                                    'resultado' => $resultado,
                                    'codigo' => $codigo,
                                    'moq' => $moq,
                                    'frozen' => $frozen,
                                    'transit' => $transit,
                                    'min' => $min,
                                    'max' => $max,
                                    'setup' => $setup,
                                ];
                            }
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

                        $codigo = trim((string) ($linha[$indices['codigo_componente']] ?? ''));
                        if ($codigo === '') {
                            $erros++;
                            $mensagens[] = "⚠️ Linha $linhaNum ignorada: componente vazio.";
                            continue;
                        }

                        $moq = $indices['moq'] !== null ? parseNumeroBrParametros((string) ($linha[$indices['moq']] ?? '')) : null;
                        $frozen = $indices['frozen_zone_dias'] !== null ? parseNumeroBrParametros((string) ($linha[$indices['frozen_zone_dias']] ?? '')) : null;
                        $transit = $indices['transit_time_dias'] !== null ? parseNumeroBrParametros((string) ($linha[$indices['transit_time_dias']] ?? '')) : null;
                        $min = $indices['estoque_min_dias'] !== null ? parseNumeroBrParametros((string) ($linha[$indices['estoque_min_dias']] ?? '')) : null;
                        $max = $indices['estoque_max_dias'] !== null ? parseNumeroBrParametros((string) ($linha[$indices['estoque_max_dias']] ?? '')) : null;
                        // "setup" = percentual de perda (scrap). Digite só o número (ex.: 20),
                        // sem o símbolo "%" — mas se vier com "%" mesmo assim, removemos.
                        $setupTexto = $indices['setup'] !== null ? str_replace('%', '', (string) ($linha[$indices['setup']] ?? '')) : '';
                        $setup = $indices['setup'] !== null ? parseNumeroBrParametros($setupTexto) : null;

                        $lote[] = [
                            $codigo,
                            $moq,
                            $frozen !== null ? (int) $frozen : null,
                            $transit !== null ? (int) $transit : null,
                            $min !== null ? (int) $min : null,
                            $max !== null ? (int) $max : null,
                            $setup,
                        ];

                        if (count($lote) >= $tamanhoLote) {
                            $flushLote();
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
    $where = "WHERE codigo_componente LIKE ?";
    $buscaLike = "%$busca%";
    $params = [$buscaLike];
    $tipos = 's';
}

$sqlTotal = "SELECT COUNT(*) AS total FROM parametros_compra $where";
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

// Quantos têm os 5 parâmetros completos (é o que conta pro planejamento de compras)
$sqlCompletos = "SELECT COUNT(*) AS total FROM parametros_compra $where "
    . ($where === '' ? 'WHERE ' : ' AND ')
    . "moq IS NOT NULL AND frozen_zone_dias IS NOT NULL AND transit_time_dias IS NOT NULL AND estoque_min_dias IS NOT NULL AND estoque_max_dias IS NOT NULL";
if ($busca !== '') {
    $stmtCompletos = mysqli_prepare($conn, $sqlCompletos);
    mysqli_stmt_bind_param($stmtCompletos, $tipos, ...$params);
    mysqli_stmt_execute($stmtCompletos);
    $resultCompletos = mysqli_stmt_get_result($stmtCompletos);
} else {
    $resultCompletos = mysqli_query($conn, $sqlCompletos);
}
$totalCompletos = (int) (mysqli_fetch_assoc($resultCompletos)['total'] ?? 0);

// Exportação CSV: traz TODOS os registros filtrados
if (($_GET['exportar'] ?? '') === 'csv') {
    $sqlExport = "SELECT codigo_componente, moq, frozen_zone_dias, transit_time_dias, estoque_min_dias, estoque_max_dias, setup FROM parametros_compra $where ORDER BY codigo_componente";
    if ($busca !== '') {
        $stmtExport = mysqli_prepare($conn, $sqlExport);
        mysqli_stmt_bind_param($stmtExport, $tipos, ...$params);
        mysqli_stmt_execute($stmtExport);
        $resultExport = mysqli_stmt_get_result($stmtExport);
    } else {
        $resultExport = mysqli_query($conn, $sqlExport);
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="parametros-compra-' . date('Y-m-d-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $saida = fopen('php://output', 'w');
    fputcsv($saida, ['Componente', 'MOQ', 'Frozen Zone (dias)', 'Transit Time (dias)', 'Estoque Min (dias)', 'Estoque Max (dias)', 'Setup (%)'], ';', '"', '');
   
    while ($linha = mysqli_fetch_assoc($resultExport)) {

    $moqExportado = $linha['moq'] !== null
        ? number_format((float) $linha['moq'], 0, ',', '')
        : '';
    $setupExportado = $linha['setup'] !== null
        ? number_format((float) $linha['setup'], 2, ',', '')
        : '';

    fputcsv($saida, [
        $linha['codigo_componente'],
        $moqExportado,
        $linha['frozen_zone_dias'],
        $linha['transit_time_dias'],
        $linha['estoque_min_dias'],
        $linha['estoque_max_dias'],
        $setupExportado,
    ], ';', '"', '');
}

    fclose($saida);
    exit;
}

$sql = "SELECT codigo_componente, moq, frozen_zone_dias, transit_time_dias, estoque_min_dias, estoque_max_dias, setup
        FROM parametros_compra $where
        ORDER BY codigo_componente
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
    <title>⚙️ Parâmetros de Compra</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; padding: 20px; }
        .card { border-radius: 15px; box-shadow: 0 2px 20px rgba(0,0,0,0.08); }
        .bg-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important; }
        .table th { background: #f8f9fa; white-space: nowrap; }
        .table td { white-space: nowrap; }
        .badge-completo { background: #eaf8f0; color: #247a4d; }
        .badge-incompleto { background: #fff7df; color: #a96600; }
        summary { cursor: pointer; font-weight: 700; color: #405164; }
    </style>
</head>
<body>
    <div class="container" style="max-width: 1000px;">
        <div class="card bg-primary text-white p-4 mb-4">
            <h1>⚙️ Parâmetros de Compra</h1>
            <p class="mb-0">
                <?php echo number_format($total, 0, ',', '.'); ?> componente(s) na base
                • <?php echo number_format($totalCompletos, 0, ',', '.'); ?> com os 5 parâmetros completos (entram no planejamento)
            </p>
        </div>

        <div class="card p-3 mb-4">
            <details <?php echo (!empty($mensagens) || !empty($linhasProcessadas)) ? 'open' : ''; ?>>
                <summary>📥 Importar novo arquivo CSV</summary>
                <div class="mt-3">
                    <?php if (!empty($mensagens)): ?>
                    <div class="mb-3">
                        <?php foreach ($mensagens as $msg): ?>
                            <div><?php echo htmlspecialchars($msg); ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($linhasProcessadas)): ?>
                    <div class="mb-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <div>
                                <h2 class="h6 mb-1">Itens processados nesta importação</h2>
                                <p class="text-muted mb-0 small">
                                    <?php echo number_format($totalProcessadas, 0, ',', '.'); ?> linha(s) processada(s)
                                    (grava com "atualiza se já existir" — não dá pra separar quem era novo de quem foi atualizado)
                                </p>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <span class="badge text-bg-success"><?php echo $importados; ?> gravado(s)</span>
                                <span class="badge text-bg-danger"><?php echo $erros; ?> erro(s)</span>
                            </div>
                        </div>

                        <?php if ($totalProcessadas > $limiteExibicao): ?>
                            <div class="alert alert-info py-2">
                                A importação processou todas as linhas. Para manter a página rápida, a tabela abaixo mostra somente as primeiras <?php echo $limiteExibicao; ?>.
                            </div>
                        <?php endif; ?>

                        <div class="table-responsive">
                            <table class="table table-hover table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Resultado</th>
                                        <th>Componente</th>
                                        <th class="text-end">MOQ</th>
                                        <th class="text-end">Frozen Zone</th>
                                        <th class="text-end">Transit Time</th>
                                        <th class="text-end">Min</th>
                                        <th class="text-end">Max</th>
                                        <th class="text-end">Setup (%)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($linhasProcessadas as $linhaProcessada): ?>
                                        <?php $badgeCor = $linhaProcessada['resultado'] === 'inserido' ? 'success' : 'danger'; ?>
                                        <tr>
                                            <td><span class="badge text-bg-<?php echo $badgeCor; ?>"><?php echo $linhaProcessada['resultado'] === 'inserido' ? 'Gravado' : 'Erro'; ?></span></td>
                                            <td><strong><?php echo htmlspecialchars($linhaProcessada['codigo']); ?></strong></td>
                                            <td class="text-end"><?php echo htmlspecialchars((string) ($linhaProcessada['moq'] ?? '—')); ?></td>
                                            <td class="text-end"><?php echo htmlspecialchars((string) ($linhaProcessada['frozen'] ?? '—')); ?></td>
                                            <td class="text-end"><?php echo htmlspecialchars((string) ($linhaProcessada['transit'] ?? '—')); ?></td>
                                            <td class="text-end"><?php echo htmlspecialchars((string) ($linhaProcessada['min'] ?? '—')); ?></td>
                                            <td class="text-end"><?php echo htmlspecialchars((string) ($linhaProcessada['max'] ?? '—')); ?></td>
                                            <td class="text-end"><?php echo $linhaProcessada['setup'] !== null ? htmlspecialchars((string) $linhaProcessada['setup']) . '%' : '—'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
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
                        <strong>Colunas esperadas no CSV</strong> (primeira linha = cabeçalho, qualquer ordem):<br>
                        <code>codigo_componente, moq, frozen_zone_dias, transit_time_dias, estoque_min_dias, estoque_max_dias, setup</code><br>
                        Todas as colunas exceto <code>codigo_componente</code> são opcionais — mas a data sugerida de compra só é calculada para componentes com <strong>todos</strong> os 5 parâmetros principais preenchidos (MOQ, Frozen Zone, Transit Time, Min, Max).<br>
                        <code>estoque_min_dias</code>/<code>estoque_max_dias</code> = dias de cobertura de estoque desejados (não quantidade). <code>frozen_zone_dias</code> + <code>transit_time_dias</code> = tempo mínimo de reação (dias) entre decidir comprar e o material chegar.<br>
                        <code>setup</code> = percentual de perda (scrap) do componente. Digite só o número, sem o símbolo "%" (ex.: <code>20</code> significa 20%). É opcional; se não preencher, a sugestão de compra não sofre acréscimo. A quantidade sugerida no planejamento de compras é multiplicada por <code>(1 + setup/100)</code> — ex.: sugestão de 12.000 com <code>setup=20</code> vira 14.400.<br>
                        Separador: vírgula ou ponto e vírgula (detectado automaticamente).
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
                    <a href="parametros_compra.php" class="btn btn-outline-secondary">Limpar</a>
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
                            <th class="text-end">MOQ</th>
                            <th class="text-end">Frozen Zone (dias)</th>
                            <th class="text-end">Transit Time (dias)</th>
                            <th class="text-end">Estoque Min (dias)</th>
                            <th class="text-end">Estoque Max (dias)</th>
                            <th class="text-end">Setup (%)</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="8" class="text-center text-muted">Nenhum registro encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                    $completo = $row['moq'] !== null && $row['frozen_zone_dias'] !== null
                                        && $row['transit_time_dias'] !== null && $row['estoque_min_dias'] !== null
                                        && $row['estoque_max_dias'] !== null;
                                ?>
                                <tr>
                                    <td><strong><?php echo h($row['codigo_componente'] ?? ''); ?></strong></td>
                                    <td class="text-end"><?php echo $row['moq'] !== null ? number_format((float) $row['moq'], 0, ',', '.') : '—'; ?></td>
                                    <td class="text-end"><?php echo $row['frozen_zone_dias'] ?? '—'; ?></td>
                                    <td class="text-end"><?php echo $row['transit_time_dias'] ?? '—'; ?></td>
                                    <td class="text-end"><?php echo $row['estoque_min_dias'] ?? '—'; ?></td>
                                    <td class="text-end"><?php echo $row['estoque_max_dias'] ?? '—'; ?></td>
                                    <td class="text-end"><?php echo $row['setup'] !== null ? number_format((float) $row['setup'], 2, ',', '.') . '%' : '—'; ?></td>
                                    <td>
                                        <?php if ($completo): ?>
                                            <span class="badge badge-completo">Completo</span>
                                        <?php else: ?>
                                            <span class="badge badge-incompleto">Incompleto</span>
                                        <?php endif; ?>
                                    </td>
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
