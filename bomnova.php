<?php
require_once 'conexao.php';

// Evita timeout do PHP em importações grandes; a lentidão real é de rede até o banco,
// não do processamento em si, então aumentamos a margem de segurança.
set_time_limit(300);

// Interpreta números em formato BR: "1.400" = 1400 (milhar), "1.234,56" = 1234.56.
// Sem isso, um consumo gravado como "1.400" seria lido depois como 1,4 na hora do
// cálculo (o banco interpreta "." como separador decimal, não de milhar).
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

            $cabecalho = fgetcsv($handle, 0, $separador, '"', '\\');
            if ($cabecalho === false) {
                $mensagens[] = "❌ Arquivo vazio ou inválido.";
            } else {
                // O Excel costuma gravar um BOM (marcador invisível) na primeira célula do CSV.
                if (isset($cabecalho[0])) {
                    $cabecalho[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cabecalho[0]);
                }

                $cabecalho = array_map(function($c) {
                    return strtolower(trim($c));
                }, $cabecalho);

                if (isset($_POST['limpar_tabela'])) {
                    mysqli_query($conn, "TRUNCATE TABLE bomnova");
                    $mensagens[] = "🗑️ Tabela 'bomnova' esvaziada antes da importação.";
                }

                // Insere em lotes (várias linhas por comando INSERT) para reduzir o número
                // de viagens de rede até o banco — essencial em conexões de alta latência
                // como TiDB Cloud, onde 1 INSERT por linha pode causar timeout do gateway.
                $tamanhoLote = 200;
                $lote = [];

                $flushLote = function () use ($conn, &$lote, &$importados, &$erros, &$mensagens) {
                    if (empty($lote)) {
                        return;
                    }
                    $linhasSql = [];
                    foreach ($lote as $valores) {
                        $escapados = array_map(function ($v) use ($conn) {
                            return $v === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, (string) $v) . "'";
                        }, $valores);
                        $linhasSql[] = '(' . implode(', ', $escapados) . ')';
                    }
                    $sql = "INSERT INTO bomnova (planta, projeto, material, tipo, fornecedor, codigo_componente, pn, descricao, consumo, um, mrp) VALUES "
                        . implode(', ', $linhasSql);

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
                        continue; // linha totalmente vazia (comum no fim do CSV exportado do Excel)
                    }

                    if (count($linha) !== count($cabecalho)) {
                        $erros++;
                        $mensagens[] = "⚠️ Linha $linhaNum ignorada (número de colunas não confere).";
                        continue;
                    }

                    $dados = array_combine($cabecalho, $linha);

                    $planta = $dados['planta'] ?? null;
                    $projeto = $dados['projeto'] ?? null;
                    $material = $dados['material'] ?? null;
                    $tipo = $dados['tipo'] ?? null;
                    $fornecedor = $dados['fornecedor'] ?? null;
                    $codigo_componente = $dados['codigo_componente'] ?? $dados['componente'] ?? $dados['codigo do componente'] ?? $dados['código do componente'] ?? $dados['codigo componente'] ?? null;
                    $pn = $dados['pn'] ?? null;
                    $descricao = $dados['descricao'] ?? null;
                    $consumoBruto = $dados['consumo'] ?? '';
                    $consumo = $consumoBruto !== '' ? parseNumeroBr((string) $consumoBruto) : null;
                    $um = $dados['um'] ?? null;
                    $mrp = $dados['mrp'] ?? null;
                    if ($mrp !== null) {
                        $mrp = strtoupper(trim($mrp));
                    }

                    $lote[] = [$planta, $projeto, $material, $tipo, $fornecedor, $codigo_componente, $pn, $descricao, $consumo, $um, $mrp];

                    if (count($lote) >= $tamanhoLote) {
                        $flushLote();
                    }
                }
                $flushLote();

                mysqli_commit($conn);
                mysqli_autocommit($conn, true);

                $mensagens[] = "✅ Importação concluída: $importados linha(s) importada(s), $erros erro(s).";
            }
            fclose($handle);
        }
    }
}

$porPagina = 50;
$pagina = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$offset = ($pagina - 1) * $porPagina;

$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';

$where = '';
$params = [];
$tipos = '';

if ($busca !== '') {
    $where = "WHERE material LIKE ? OR codigo_componente LIKE ? OR descricao LIKE ? OR fornecedor LIKE ? OR projeto LIKE ?";
    $buscaLike = "%$busca%";
    $params = [$buscaLike, $buscaLike, $buscaLike, $buscaLike, $buscaLike];
    $tipos = 'sssss';
}

$sqlTotal = "SELECT COUNT(*) AS total FROM bomnova $where";
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

// Exportação CSV: traz TODOS os registros filtrados (ignora a paginação da tela)
if (($_GET['exportar'] ?? '') === 'csv') {
    $sqlExport = "SELECT planta, projeto, material, tipo, fornecedor, codigo_componente, pn, descricao, consumo, um, mrp
                  FROM bomnova $where
                  ORDER BY projeto, material, codigo_componente";
    if ($busca !== '') {
        $stmtExport = mysqli_prepare($conn, $sqlExport);
        mysqli_stmt_bind_param($stmtExport, $tipos, ...$params);
        mysqli_stmt_execute($stmtExport);
        $resultExport = mysqli_stmt_get_result($stmtExport);
    } else {
        $resultExport = mysqli_query($conn, $sqlExport);
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="bomnova-' . date('Y-m-d-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $saida = fopen('php://output', 'w');
    fputcsv($saida, ['Planta', 'Projeto', 'Material', 'Tipo', 'Fornecedor', 'Componente', 'PN', 'Descrição', 'Consumo', 'U.M.', 'MRP'], ';', '"', '');
    while ($linhaExport = mysqli_fetch_assoc($resultExport)) {
        fputcsv($saida, [
            $linhaExport['planta'], $linhaExport['projeto'], $linhaExport['material'], $linhaExport['tipo'],
            $linhaExport['fornecedor'], $linhaExport['codigo_componente'], $linhaExport['pn'], $linhaExport['descricao'],
            $linhaExport['consumo'], $linhaExport['um'], $linhaExport['mrp'],
        ], ';', '"', '');
    }
    fclose($saida);
    exit;
}

$sql = "SELECT planta, projeto, material, tipo, fornecedor, codigo_componente, pn, descricao, consumo, um, mrp
        FROM bomnova $where
        ORDER BY projeto, material, codigo_componente
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
    <title>📦 BOM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; padding: 20px; }
        .card { border-radius: 15px; box-shadow: 0 2px 20px rgba(0,0,0,0.08); }
        .bg-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important; }
        .table th { background: #f8f9fa; white-space: nowrap; }
        .table td { white-space: nowrap; }
        .badge-mrp-s { background: #eaf8f0; color: #247a4d; }
        .badge-mrp-n { background: #fff0f0; color: #c53535; }
        summary { cursor: pointer; font-weight: 700; color: #405164; }
    </style>
</head>
<body>
    <div class="container-fluid" style="max-width: 1600px;">
        <div class="card bg-primary text-white p-4 mb-4">
            <h1>📦 BOM</h1>
            <p class="mb-0"><?php echo number_format($total, 0, ',', '.'); ?> registro(s) na base</p>
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
                        <code>planta, projeto, material, tipo, fornecedor, codigo_componente, pn, descricao, consumo, um, mrp</code><br>
                        A coluna <code>mrp</code> é opcional: use <code>S</code> para componente ativo (conta no cálculo de demanda) ou <code>N</code> para substituído (fica só como histórico, não conta no cálculo). Se não vier no CSV, é tratado como ativo.<br>
                        Separador: vírgula ou ponto e vírgula (detectado automaticamente).
                    </small>
                </div>
            </details>
        </div>

        <div class="card p-3 mb-4">
            <form method="GET" class="row g-2">
                <div class="col-auto flex-grow-1">
                    <input type="text" name="busca" class="form-control" placeholder="Buscar por material, componente, descrição, fornecedor ou projeto..." value="<?php echo htmlspecialchars($busca); ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">Buscar</button>
                    <a href="bomnova.php" class="btn btn-outline-secondary">Limpar</a>
                    <a href="?busca=<?php echo urlencode($busca); ?>&exportar=csv" class="btn btn-outline-primary">Exportar CSV</a>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-body table-responsive">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Planta</th>
                            <th>Projeto</th>
                            <th>Material</th>
                            <th>Tipo</th>
                            <th>Fornecedor</th>
                            <th>Componente</th>
                            <th>PN</th>
                            <th>Descrição</th>
                            <th class="text-end">Consumo</th>
                            <th>U.M.</th>
                            <th>MRP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="11" class="text-center text-muted">Nenhum registro encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                    $mrp = strtoupper(trim((string) ($row['mrp'] ?? '')));
                                    $badgeClasse = $mrp === 'N' ? 'badge-mrp-n' : ($mrp === 'S' ? 'badge-mrp-s' : 'text-bg-secondary');
                                    $badgeTexto = $mrp !== '' ? $mrp : '—';
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['planta'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['projeto'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['material'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['tipo'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['fornecedor'] ?? ''); ?></td>
                                    <td><strong><?php echo htmlspecialchars($row['codigo_componente'] ?? ''); ?></strong></td>
                                    <td><?php echo htmlspecialchars($row['pn'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['descricao'] ?? ''); ?></td>
                                    <td class="text-end"><?php echo htmlspecialchars($row['consumo'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['um'] ?? ''); ?></td>
                                    <td><span class="badge <?php echo $badgeClasse; ?>"><?php echo htmlspecialchars($badgeTexto); ?></span></td>
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
