<?php
require_once 'conexao.php';

set_time_limit(300);

$mensagens = [];
$importados = 0;
$atualizados = 0;
$ignorados = 0;
$erros = 0;
$linhasProcessadas = [];
$totalProcessadas = 0;
$limiteExibicao = 500;

function registrarLinhaProcessada(
    array &$linhasProcessadas,
    int &$totalProcessadas,
    int $limiteExibicao,
    array $dados,
    string $resultado
): void {
    $totalProcessadas++;

    if (count($linhasProcessadas) >= $limiteExibicao) {
        return;
    }

    $linhasProcessadas[] = [
        'resultado' => $resultado,
        'material' => $dados['material'] ?? '',
        'pn2' => $dados['pn2'] ?? '',
        'projeto' => $dados['projeto'] ?? '',
        'evento' => $dados['evento'] ?? '',
        'semana' => $dados['semana'] ?? '',
        'ano' => $dados['ano'] ?? '',
        'quantidade' => $dados['quantidade'] ?? '',
        'data_inicio' => $dados['data_inicio'] ?? '',
        'data_fim' => $dados['data_fim'] ?? '',
    ];
}

function calcularPeriodoSemana(int $semana): ?array
{
    if ($semana >= 30 && $semana <= 53) {
        $ano = 2026;
    } elseif ($semana >= 1 && $semana <= 29) {
        $ano = 2027;
    } else {
        return null;
    }

    $inicio = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->setISODate($ano, $semana, 1);

    return [
        'ano' => $ano,
        'data_inicio' => $inicio->format('Y-m-d'),
        'data_fim' => $inicio->modify('+6 days')->format('Y-m-d'),
    ];
}

// Interpreta a quantidade do CSV, que pode vir em formato BR (ponto de milhar,
// vírgula decimal — ex.: "1.400" = 1400, "1.234,56" = 1234.56) ou já em formato
// simples ("1400"). Sem essa conversão, "1.400" seria gravado como texto e o
// banco leria o ponto como separador decimal (virando 1,4 em vez de 1400).
function parseQuantidadeEdi(string $valor): ?string
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }

    if (str_contains($valor, ',')) {
        // Tem vírgula: ponto é milhar, vírgula é decimal (ex.: "1.234,56")
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    } elseif (substr_count($valor, '.') >= 1) {
        // Só tem ponto(s): só remove como milhar se TODOS os grupos após
        // o primeiro ponto tiverem exatamente 3 dígitos (ex.: "1.400", "45.000").
        // Caso contrário, mantém o ponto como decimal (ex.: "1.5").
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

    return is_numeric($valor) ? $valor : null;
}

function formatarDataBr(?string $data): string
{
    if ($data === null || $data === '') {
        return '-';
    }

    $objetoData = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
    return $objetoData ? $objetoData->format('d/m/Y') : $data;
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

                if ($modo === 'substituir') {
                    mysqli_query($conn, "TRUNCATE TABLE edi");
                    $mensagens[] = "🗑️ Tabela 'edi' esvaziada antes da importação.";
                }

                $stmtInsert = mysqli_prepare($conn, "INSERT INTO edi (pn2, material, marca, projeto, modelo, evento, semana, quantidade, ano, data_fim, data_inicio) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtVerifica = mysqli_prepare($conn, "SELECT COUNT(*) AS existe FROM edi WHERE material = ? AND semana = ? AND evento = ?");
                $stmtUpdate = mysqli_prepare($conn, "UPDATE edi SET pn2 = ?, marca = ?, projeto = ?, modelo = ?, quantidade = ?, ano = ?, data_fim = ?, data_inicio = ? WHERE material = ? AND semana = ? AND evento = ?");

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

                    $dados = array_combine($cabecalho, $linha);

                    $pn2 = $dados['pn2'] ?? null;
                    $material = $dados['material'] ?? null;
                    $marca = $dados['marca'] ?? null;
                    $projeto = $dados['projeto'] ?? null;
                    $modelo = $dados['modelo'] ?? null;
                    $evento = $dados['evento'] ?? null;
                    $semana = $dados['semana'] ?? null;
                    $quantidadeBruta = $dados['quantidade'] ?? '';
                    $quantidade = parseQuantidadeEdi((string) $quantidadeBruta);
                    if ($quantidade === null) {
                        $erros++;
                        $mensagens[] = "⚠️ Linha $linhaNum ignorada: quantidade '$quantidadeBruta' inválida.";
                        registrarLinhaProcessada(
                            $linhasProcessadas,
                            $totalProcessadas,
                            $limiteExibicao,
                            $dados,
                            'erro'
                        );
                        continue;
                    }
                    $dados['quantidade'] = $quantidade;

                    $periodo = is_numeric($semana) ? calcularPeriodoSemana((int) $semana) : null;
                    if ($periodo === null) {
                        $erros++;
                        $mensagens[] = "⚠️ Linha $linhaNum ignorada: semana '$semana' fora dos intervalos 30–53/2026 e 1–29/2027.";
                        registrarLinhaProcessada(
                            $linhasProcessadas,
                            $totalProcessadas,
                            $limiteExibicao,
                            $dados,
                            'erro'
                        );
                        continue;
                    }

                    $ano = $periodo['ano'];
                    $data_inicio = $periodo['data_inicio'];
                    $data_fim = $periodo['data_fim'];
                    $dados['ano'] = $ano;
                    $dados['data_inicio'] = $data_inicio;
                    $dados['data_fim'] = $data_fim;

                    $existe = false;
                    if ($modo === 'sem_duplicar' || $modo === 'atualizar') {
                        mysqli_stmt_bind_param($stmtVerifica, "sss", $material, $semana, $evento);
                        mysqli_stmt_execute($stmtVerifica);
                        $resVerifica = mysqli_stmt_get_result($stmtVerifica);
                        $existe = mysqli_fetch_assoc($resVerifica)['existe'] > 0;
                    }

                    if ($modo === 'sem_duplicar' && $existe) {
                        $ignorados++;
                        registrarLinhaProcessada(
                            $linhasProcessadas,
                            $totalProcessadas,
                            $limiteExibicao,
                            $dados,
                            'ignorado'
                        );
                        continue;
                    }

                    if ($modo === 'atualizar' && $existe) {
                        mysqli_stmt_bind_param(
                            $stmtUpdate, "ssssdisssss",
                            $pn2, $marca, $projeto, $modelo, $quantidade, $ano, $data_fim, $data_inicio,
                            $material, $semana, $evento
                        );
                        if (mysqli_stmt_execute($stmtUpdate)) {
                            $atualizados++;
                            registrarLinhaProcessada(
                                $linhasProcessadas,
                                $totalProcessadas,
                                $limiteExibicao,
                                $dados,
                                'atualizado'
                            );
                        } else {
                            $erros++;
                            $mensagens[] = "⚠️ Erro ao atualizar linha $linhaNum: " . mysqli_stmt_error($stmtUpdate);
                            registrarLinhaProcessada(
                                $linhasProcessadas,
                                $totalProcessadas,
                                $limiteExibicao,
                                $dados,
                                'erro'
                            );
                        }
                        continue;
                    }

                    // Inserção normal (modos: adicionar, substituir, ou "atualizar"/"sem_duplicar" quando não existe ainda)
                    mysqli_stmt_bind_param(
                        $stmtInsert, "ssssssssiss",
                        $pn2, $material, $marca, $projeto, $modelo, $evento,
                        $semana, $quantidade, $ano, $data_fim, $data_inicio
                    );

                    if (mysqli_stmt_execute($stmtInsert)) {
                        $importados++;
                        registrarLinhaProcessada(
                            $linhasProcessadas,
                            $totalProcessadas,
                            $limiteExibicao,
                            $dados,
                            'inserido'
                        );
                    } else {
                        $erros++;
                        $mensagens[] = "⚠️ Erro na linha $linhaNum: " . mysqli_stmt_error($stmtInsert);
                        registrarLinhaProcessada(
                            $linhasProcessadas,
                            $totalProcessadas,
                            $limiteExibicao,
                            $dados,
                            'erro'
                        );
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
            fclose($handle);
        }
    }
}

// Alternar o status "atendido" de um evento EDI, sem apagar a linha
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'alternar_atendido') {
    $idAlternar = (int) ($_POST['id'] ?? 0);
    if ($idAlternar > 0) {
        $stmtToggle = mysqli_prepare($conn, "UPDATE edi SET atendido = IF(atendido = 1, 0, 1) WHERE _tidb_rowid = ?");
        mysqli_stmt_bind_param($stmtToggle, 'i', $idAlternar);
        mysqli_stmt_execute($stmtToggle);
        mysqli_stmt_close($stmtToggle);
    }

    header('Location: edi.php?' . http_build_query([
        'pagina' => $_POST['pagina_atual'] ?? 1,
        'busca'  => $_POST['busca_atual'] ?? '',
        'ano'    => $_POST['ano_atual'] ?? '',
        'filtro' => $_POST['filtro_atual'] ?? '',
    ]));
    exit;
}

$porPagina = 50;
$pagina = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$offset = ($pagina - 1) * $porPagina;

$busca  = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$filtro = isset($_GET['filtro']) ? trim($_GET['filtro']) : ''; // '', 'pendente', 'atendido'
$anoFiltro = isset($_GET['ano']) ? trim($_GET['ano']) : ''; // '', ou um ano específico

$condicoes = [];
$params = [];
$tipos = '';

if ($busca !== '') {
    $condicoes[] = "(material LIKE ? OR pn2 LIKE ? OR projeto LIKE ?)";
    $buscaLike = "%$busca%";
    array_push($params, $buscaLike, $buscaLike, $buscaLike);
    $tipos .= 'sss';
}

if ($filtro === 'pendente') {
    $condicoes[] = "(atendido = 0 OR atendido IS NULL)";
} elseif ($filtro === 'atendido') {
    $condicoes[] = "atendido = 1";
}

if ($anoFiltro !== '' && ctype_digit($anoFiltro)) {
    $condicoes[] = "ano = ?";
    $params[] = (int) $anoFiltro;
    $tipos .= 'i';
}

$where = $condicoes ? ('WHERE ' . implode(' AND ', $condicoes)) : '';

// Anos disponíveis na base, pra montar o combo de filtro dinamicamente
$anosDisponiveis = [];
$resAnos = mysqli_query($conn, "SELECT DISTINCT ano FROM edi WHERE ano IS NOT NULL ORDER BY ano DESC");
if ($resAnos) {
    while ($linhaAno = mysqli_fetch_assoc($resAnos)) {
        $anosDisponiveis[] = $linhaAno['ano'];
    }
}

// Total de registros (para calcular número de páginas)
$sqlTotal = "SELECT COUNT(*) AS total FROM edi $where";
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

// Exportação CSV: traz TODOS os registros filtrados (ignora a paginação da tela)
if (($_GET['exportar'] ?? '') === 'csv') {
    $sqlExport = "SELECT pn2, material, marca, projeto, modelo, evento, semana, quantidade, ano, data_inicio, data_fim, atendido
                  FROM edi $where
                  ORDER BY ano DESC, semana DESC";
    if (!empty($params)) {
        $stmtExport = mysqli_prepare($conn, $sqlExport);
        mysqli_stmt_bind_param($stmtExport, $tipos, ...$params);
        mysqli_stmt_execute($stmtExport);
        $resultExport = mysqli_stmt_get_result($stmtExport);
    } else {
        $resultExport = mysqli_query($conn, $sqlExport);
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="edi-' . date('Y-m-d-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $saida = fopen('php://output', 'w');
    fputcsv($saida, ['PN2', 'Material', 'Marca', 'Projeto', 'Modelo', 'Evento', 'Semana', 'Quantidade', 'Ano', 'Data início', 'Data fim', 'Atendido'], ';', '"', '');
    while ($linhaExport = mysqli_fetch_assoc($resultExport)) {
        fputcsv($saida, [
            $linhaExport['pn2'], $linhaExport['material'], $linhaExport['marca'], $linhaExport['projeto'],
            $linhaExport['modelo'], $linhaExport['evento'], $linhaExport['semana'], $linhaExport['quantidade'],
            $linhaExport['ano'], $linhaExport['data_inicio'], $linhaExport['data_fim'],
            ((int) ($linhaExport['atendido'] ?? 0) === 1) ? 'Sim' : 'Não',
        ], ';', '"', '');
    }
    fclose($saida);
    exit;
}

// Busca os dados da página atual
$sql = "SELECT _tidb_rowid AS id, pn2, material, marca, projeto, modelo, evento, semana, quantidade, ano, data_inicio, data_fim, atendido 
        FROM edi $where 
        ORDER BY ano DESC, semana DESC 
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
    <title>📋 EDI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; padding: 20px; }
        .card { border-radius: 15px; box-shadow: 0 2px 20px rgba(0,0,0,0.08); }
        .bg-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important; }
        .table th { background: #f8f9fa; white-space: nowrap; }
        .table td { white-space: nowrap; vertical-align: middle; }
        .form-check { padding: 10px; border: 1px solid #e0e0e0; border-radius: 8px; margin-bottom: 8px; }
        .form-check:hover { background: #f8f9fa; }
        .resultado-table th { white-space: nowrap; background: #f8f9fa; }
        .resultado-table td { vertical-align: middle; }
        .codigo-material { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-weight: 700; }
        summary { cursor: pointer; font-weight: 700; color: #405164; }

        /* Badge-botão de situação: um único elemento clicável, sem quebrar linha */
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

        .table-hover tbody tr:hover > * { background: #f7faff; }

        /* Tabela principal: fonte e espaçamento mais compactos pra caber mais colunas sem sobrepor */
        .edi-table { font-size: .8rem; }
        .edi-table th,
        .edi-table td { padding: 8px 10px; }
        .edi-table th { font-size: .72rem; text-transform: uppercase; letter-spacing: .03em; color: #536578; }
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
            <h1>📋 EDI</h1>
            <p class="mb-0"><?php echo number_format($total, 0, ',', '.'); ?> registro(s) na base</p>
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
                                </p>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <span class="badge text-bg-success"><?php echo $importados; ?> inserido(s)</span>
                                <span class="badge text-bg-primary"><?php echo $atualizados; ?> atualizado(s)</span>
                                <span class="badge text-bg-secondary"><?php echo $ignorados; ?> ignorado(s)</span>
                                <span class="badge text-bg-danger"><?php echo $erros; ?> erro(s)</span>
                            </div>
                        </div>

                        <?php if ($totalProcessadas > $limiteExibicao): ?>
                            <div class="alert alert-info py-2">
                                A importação processou todas as linhas. Para manter a página rápida, a tabela abaixo mostra somente as primeiras <?php echo $limiteExibicao; ?>.
                            </div>
                        <?php endif; ?>

                        <div class="table-responsive">
                            <table class="table table-hover table-sm resultado-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Resultado</th>
                                        <th>Material</th>
                                        <th>PN2</th>
                                        <th>Projeto</th>
                                        <th>Evento</th>
                                        <th>Semana</th>
                                        <th>Ano</th>
                                        <th>Data inicial</th>
                                        <th>Data final</th>
                                        <th class="text-end">Quantidade</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $badgesResultado = [
                                        'inserido' => ['success', 'Inserido'],
                                        'atualizado' => ['primary', 'Atualizado'],
                                        'ignorado' => ['secondary', 'Ignorado'],
                                        'erro' => ['danger', 'Erro'],
                                    ];
                                    ?>
                                    <?php foreach ($linhasProcessadas as $linhaProcessada): ?>
                                        <?php $badge = $badgesResultado[$linhaProcessada['resultado']]; ?>
                                        <tr>
                                            <td><span class="badge text-bg-<?php echo $badge[0]; ?>"><?php echo $badge[1]; ?></span></td>
                                            <td><span class="codigo-material"><?php echo htmlspecialchars($linhaProcessada['material']); ?></span></td>
                                            <td><?php echo htmlspecialchars($linhaProcessada['pn2']); ?></td>
                                            <td><?php echo htmlspecialchars($linhaProcessada['projeto']); ?></td>
                                            <td><?php echo htmlspecialchars($linhaProcessada['evento']); ?></td>
                                            <td><?php echo htmlspecialchars($linhaProcessada['semana']); ?></td>
                                            <td><?php echo htmlspecialchars($linhaProcessada['ano']); ?></td>
                                            <td><?php echo htmlspecialchars(formatarDataBr($linhaProcessada['data_inicio'])); ?></td>
                                            <td><?php echo htmlspecialchars(formatarDataBr($linhaProcessada['data_fim'])); ?></td>
                                            <td class="text-end"><?php echo htmlspecialchars($linhaProcessada['quantidade']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
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
                                <strong>Adicionar sem duplicar</strong> — ignora linhas cujo Material+Semana+Evento já existe
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="modo" id="modo_atualizar" value="atualizar">
                            <label class="form-check-label" for="modo_atualizar">
                                <strong>Adicionar e atualizar</strong> — se já existir (mesmo Material+Semana+Evento), atualiza os dados; senão insere novo
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="modo" id="modo_substituir" value="substituir">
                            <label class="form-check-label" for="modo_substituir">
                                <strong>Substituir tudo</strong> — apaga todos os dados atuais e importa somente o que está no arquivo
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary mt-2">Importar</button>
                    </form>

                    <hr>
                    <small class="text-muted">
                        <strong>Colunas esperadas no CSV</strong> (primeira linha = cabeçalho, qualquer ordem):<br>
                        <code>pn2, material, marca, projeto, modelo, evento, semana, quantidade</code><br>
                        Ano e datas são calculados automaticamente: semanas 30–53 = 2026; semanas 1–29 = 2027. A data inicial é a segunda-feira e a data final é o domingo da semana. Separador: vírgula ou ponto e vírgula.
                    </small>
                </div>
            </details>
        </div>

        <div class="card p-3 mb-4">
            <form method="GET" class="row g-2 align-items-center">
                <input type="hidden" name="filtro" value="<?php echo htmlspecialchars($filtro); ?>">
                <div class="col-auto flex-grow-1">
                    <input type="text" name="busca" class="form-control" placeholder="Buscar por material, PN ou projeto..." value="<?php echo htmlspecialchars($busca); ?>">
                </div>
                <div class="col-auto">
                    <select name="ano" class="form-select" onchange="this.form.submit()">
                        <option value="">Todos os anos</option>
                        <?php foreach ($anosDisponiveis as $anoOpcao): ?>
                            <option value="<?php echo (int) $anoOpcao; ?>" <?php echo ((string) $anoFiltro === (string) $anoOpcao) ? 'selected' : ''; ?>><?php echo (int) $anoOpcao; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <div class="btn-group" role="group">
                        <a href="?busca=<?php echo urlencode($busca); ?>&ano=<?php echo urlencode($anoFiltro); ?>&filtro=" class="btn btn-outline-secondary btn-sm <?php echo $filtro === '' ? 'active' : ''; ?>">Todos</a>
                        <a href="?busca=<?php echo urlencode($busca); ?>&ano=<?php echo urlencode($anoFiltro); ?>&filtro=pendente" class="btn btn-outline-secondary btn-sm <?php echo $filtro === 'pendente' ? 'active' : ''; ?>">Pendentes</a>
                        <a href="?busca=<?php echo urlencode($busca); ?>&ano=<?php echo urlencode($anoFiltro); ?>&filtro=atendido" class="btn btn-outline-secondary btn-sm <?php echo $filtro === 'atendido' ? 'active' : ''; ?>">Atendidos</a>
                    </div>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">Buscar</button>
                    <a href="edi.php" class="btn btn-outline-secondary">Limpar</a>
                    <a href="?busca=<?php echo urlencode($busca); ?>&ano=<?php echo urlencode($anoFiltro); ?>&filtro=<?php echo urlencode($filtro); ?>&exportar=csv" class="btn btn-outline-primary">Exportar CSV</a>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-body table-responsive">
                <table class="table table-hover table-sm edi-table">
                    <thead>
                        <tr>
                            <th>Situação</th>
                            <th>PN2</th>
                            <th>Material</th>
                            <th>Marca</th>
                            <th>Projeto</th>
                            <th>Modelo</th>
                            <th>Evento</th>
                            <th class="text-center">Semana</th>
                            <th class="text-center">Quantidade</th>
                            <th>Data</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="10" class="text-center text-muted">Nenhum registro encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php $estaAtendido = (int) ($row['atendido'] ?? 0) === 1; ?>
                                <tr>
                                    <td>
                                        <form method="POST" class="m-0">
                                            <input type="hidden" name="acao" value="alternar_atendido">
                                            <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                            <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                                            <input type="hidden" name="busca_atual" value="<?php echo htmlspecialchars($busca); ?>">
                                            <input type="hidden" name="ano_atual" value="<?php echo htmlspecialchars($anoFiltro); ?>">
                                            <input type="hidden" name="filtro_atual" value="<?php echo htmlspecialchars($filtro); ?>">
                                            <button type="submit"
                                                    class="situacao-toggle <?php echo $estaAtendido ? 'is-atendido' : 'is-pendente'; ?>"
                                                    title="<?php echo $estaAtendido ? 'Clique para reabrir' : 'Clique para marcar como atendido'; ?>">
                                                <span class="dot"></span>
                                                <?php echo $estaAtendido ? 'Atendido' : 'Pendente'; ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['pn2'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['material'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['marca'] ?? ''); ?></td>
                                    <td title="<?php echo htmlspecialchars($row['projeto'] ?? ''); ?>"><span class="text-truncate-cell"><?php echo htmlspecialchars($row['projeto'] ?? ''); ?></span></td>
                                    <td title="<?php echo htmlspecialchars($row['modelo'] ?? ''); ?>"><span class="text-truncate-cell"><?php echo htmlspecialchars($row['modelo'] ?? ''); ?></span></td>
                                    <td><?php echo htmlspecialchars($row['evento'] ?? ''); ?></td>
                                    <td class="text-center"><?php echo htmlspecialchars($row['semana'] ?? ''); ?></td>
                                    <td class="text-center"><?php echo htmlspecialchars($row['quantidade'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['data_inicio'] ?? ''); ?></td>
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
                    <a href="?pagina=<?php echo $pagina - 1; ?>&busca=<?php echo urlencode($busca); ?>&ano=<?php echo urlencode($anoFiltro); ?>&filtro=<?php echo urlencode($filtro); ?>" class="btn btn-outline-primary btn-sm">← Anterior</a>
                <?php endif; ?>
            </div>
            <div class="text-muted">Página <?php echo $pagina; ?> de <?php echo $totalPaginas; ?></div>
            <div>
                <?php if ($pagina < $totalPaginas): ?>
                    <a href="?pagina=<?php echo $pagina + 1; ?>&busca=<?php echo urlencode($busca); ?>&ano=<?php echo urlencode($anoFiltro); ?>&filtro=<?php echo urlencode($filtro); ?>" class="btn btn-outline-primary btn-sm">Próxima →</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="text-center mt-4">
            <a href="index.php" class="btn btn-outline-secondary">Voltar ao Dashboard</a>
        </div>
    </div>
</body>
</html>
