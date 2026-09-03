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
                    'frozen_zone_dias' => ['frozen_zone_dias', 'lead_time_dias', 'frozen_zone', 'frozenzone', 'lead_time_(dias)', 'lead_time'],
                    'transit_time_dias' => ['transit_time_dias', 'transit_time', 'transittime', 'transit_time_(dias)'],
                    'estoque_min_dias' => ['estoque_min_dias', 'min', 'min_dias', 'estoque_min_(dias)'],
                    'estoque_max_dias' => ['estoque_max_dias', 'max', 'max_dias', 'estoque_max_(dias)'],
                    'setup' => ['setup', 'scrap', 'percentual_perda', 'perda', 'setup_(%)'],
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
                    $mensagens[] = "❌ Não encontrei a coluna do componente. Use 'codigo_componente' ou 'componente' no cabeçalho.";
                } else {
                    if (isset($_POST['limpar_tabela'])) {
                        mysqli_query($conn, "TRUNCATE TABLE parametros_compra");
                        $mensagens[] = "🗑️ Tabela 'parametros_compra' esvaziada antes da importação.";
                    }

                    $tamanhoLote = 200;
                    $lote = [];

                    $flushLote = function () use ($conn, &$lote, &$importados, &$erros, &$mensagens) {
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

// Inclusão/edição manual direto pelo site, sem CSV. Como codigo_componente já é
// chave única na tabela (mesma UNIQUE KEY usada pelo upsert do import), a inclusão
// usa o mesmo INSERT ... ON DUPLICATE KEY UPDATE do CSV — cadastrar um componente
// que já existe simplesmente atualiza os parâmetros dele, sem duplicar linha.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'inserir_manual') {
    $codigoManual = trim($_POST['componente_manual'] ?? '');
    $moqManual = parseNumeroBrParametros(trim($_POST['moq_manual'] ?? ''));
    $frozenManual = parseNumeroBrParametros(trim($_POST['frozen_manual'] ?? ''));
    $transitManual = parseNumeroBrParametros(trim($_POST['transit_manual'] ?? ''));
    $minManual = parseNumeroBrParametros(trim($_POST['min_manual'] ?? ''));
    $maxManual = parseNumeroBrParametros(trim($_POST['max_manual'] ?? ''));
    $setupManual = parseNumeroBrParametros(str_replace('%', '', trim($_POST['setup_manual'] ?? '')));

    $flash = 'erro_dados';
    if ($codigoManual !== '') {
        $frozenIntManual = $frozenManual !== null ? (int) $frozenManual : null;
        $transitIntManual = $transitManual !== null ? (int) $transitManual : null;
        $minIntManual = $minManual !== null ? (int) $minManual : null;
        $maxIntManual = $maxManual !== null ? (int) $maxManual : null;

        $stmtManual = mysqli_prepare($conn, "
            INSERT INTO parametros_compra (codigo_componente, moq, frozen_zone_dias, transit_time_dias, estoque_min_dias, estoque_max_dias, setup)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE moq = VALUES(moq), frozen_zone_dias = VALUES(frozen_zone_dias),
                transit_time_dias = VALUES(transit_time_dias), estoque_min_dias = VALUES(estoque_min_dias),
                estoque_max_dias = VALUES(estoque_max_dias), setup = VALUES(setup)
        ");
        mysqli_stmt_bind_param(
            $stmtManual, "sdiiiid",
            $codigoManual, $moqManual, $frozenIntManual, $transitIntManual, $minIntManual, $maxIntManual, $setupManual
        );
        mysqli_stmt_execute($stmtManual);
        mysqli_stmt_close($stmtManual);
        $flash = 'inserido';
    }

    header('Location: parametros_compra.php?' . http_build_query([
        'pagina'     => $_POST['pagina_atual'] ?? 1,
        'busca'      => $_POST['busca_atual'] ?? '',
        'fornecedor' => $_POST['fornecedor_atual'] ?? '',
        'flash'      => $flash,
    ]));
    exit;
}

// Edição direta dos parâmetros de um componente já cadastrado, sem CSV.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar_registro') {
    $codigoEditar = trim($_POST['codigo_editar'] ?? '');
    $moqEditado = parseNumeroBrParametros(trim($_POST['moq_editado'] ?? ''));
    $frozenEditado = parseNumeroBrParametros(trim($_POST['frozen_editado'] ?? ''));
    $transitEditado = parseNumeroBrParametros(trim($_POST['transit_editado'] ?? ''));
    $minEditado = parseNumeroBrParametros(trim($_POST['min_editado'] ?? ''));
    $maxEditado = parseNumeroBrParametros(trim($_POST['max_editado'] ?? ''));
    $setupEditado = parseNumeroBrParametros(str_replace('%', '', trim($_POST['setup_editado'] ?? '')));

    $flash = 'erro_dados';
    if ($codigoEditar !== '') {
        $frozenIntEditado = $frozenEditado !== null ? (int) $frozenEditado : null;
        $transitIntEditado = $transitEditado !== null ? (int) $transitEditado : null;
        $minIntEditado = $minEditado !== null ? (int) $minEditado : null;
        $maxIntEditado = $maxEditado !== null ? (int) $maxEditado : null;

        $stmtEditar = mysqli_prepare($conn, "
            UPDATE parametros_compra
            SET moq = ?, frozen_zone_dias = ?, transit_time_dias = ?, estoque_min_dias = ?, estoque_max_dias = ?, setup = ?
            WHERE codigo_componente = ?
        ");
        mysqli_stmt_bind_param(
            $stmtEditar, "diiiids",
            $moqEditado, $frozenIntEditado, $transitIntEditado, $minIntEditado, $maxIntEditado, $setupEditado, $codigoEditar
        );
        mysqli_stmt_execute($stmtEditar);
        mysqli_stmt_close($stmtEditar);
        $flash = 'editado';
    }

    header('Location: parametros_compra.php?' . http_build_query([
        'pagina'     => $_POST['pagina_atual'] ?? 1,
        'busca'      => $_POST['busca_atual'] ?? '',
        'fornecedor' => $_POST['fornecedor_atual'] ?? '',
        'flash'      => $flash,
    ]));
    exit;
}

function h(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

// Texto e tooltip da célula "Estoque Segurança (calculado)": diferencia "sem_demanda"
// (falta dado — setup e lead time OK, mas não há EDI nos próximos 90 dias pra calcular
// em cima) dos casos onde o resultado 0 é esperado (setup = 0% ou lead time = 0, ambos
// configurações válidas, não "dado faltando").
function celulaEstoqueSeguranca(array $row): array
{
    $motivo = $row['estoque_seguranca_motivo'] ?? 'zero';
    if ($motivo === 'calculado' && $row['estoque_seguranca_calculado'] > 0) {
        return [number_format($row['estoque_seguranca_calculado'], 0, ',', '.'), 'Calculado a partir da demanda dos próximos 90 dias, setup e lead time'];
    }
    if ($motivo === 'sem_demanda') {
        return ['sem demanda', 'Setup e Lead Time preenchidos, mas não há demanda EDI cadastrada nos próximos 90 dias para calcular a variabilidade'];
    }
    return ['0', ''];
}

$porPagina = 50;
$pagina = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$offset = ($pagina - 1) * $porPagina;

$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$fornecedorFiltro = isset($_GET['fornecedor']) ? trim($_GET['fornecedor']) : '';
$editando = isset($_GET['editar']) ? trim($_GET['editar']) : '';
$flash = isset($_GET['flash']) ? trim($_GET['flash']) : '';

$flashMap = [
    'inserido'   => ['success', '✅ Componente cadastrado/atualizado com sucesso.'],
    'editado'    => ['success', '✅ Parâmetros atualizados.'],
    'erro_dados' => ['danger', '❌ Informe ao menos o código do componente.'],
];

$condicoes = [];
$params = [];
$tipos = '';

if ($busca !== '') {
    $condicoes[] = "p.codigo_componente LIKE ?";
    $buscaLike = "%$busca%";
    $params[] = $buscaLike;
    $tipos .= 's';
}

if ($fornecedorFiltro !== '') {
    $condicoes[] = "EXISTS (SELECT 1 FROM bomnova b2 WHERE TRIM(b2.codigo_componente) = TRIM(p.codigo_componente) AND TRIM(b2.fornecedor) = ?)";
    $params[] = $fornecedorFiltro;
    $tipos .= 's';
}

$where = $condicoes ? ('WHERE ' . implode(' AND ', $condicoes)) : '';

// Listas pros campos de sugestão/filtro
$componentesDisponiveis = [];
$resComp = mysqli_query($conn, "SELECT DISTINCT TRIM(codigo_componente) AS codigo FROM bomnova WHERE codigo_componente IS NOT NULL AND TRIM(codigo_componente) <> '' ORDER BY codigo");
if ($resComp) {
    while ($linhaComp = mysqli_fetch_assoc($resComp)) {
        $componentesDisponiveis[] = $linhaComp['codigo'];
    }
}

$fornecedoresDisponiveis = [];
$resForn = mysqli_query($conn, "SELECT DISTINCT TRIM(fornecedor) AS fornecedor FROM bomnova WHERE fornecedor IS NOT NULL AND TRIM(fornecedor) <> '' ORDER BY fornecedor");
if ($resForn) {
    while ($linhaForn = mysqli_fetch_assoc($resForn)) {
        $fornecedoresDisponiveis[] = $linhaForn['fornecedor'];
    }
}

$sqlTotal = "SELECT COUNT(*) AS total FROM parametros_compra p $where";
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

// Quantos têm os 5 parâmetros completos (é o que conta pro planejamento de compras)
$sqlCompletos = "SELECT COUNT(*) AS total FROM parametros_compra p $where "
    . ($where === '' ? 'WHERE ' : ' AND ')
    . "p.moq IS NOT NULL AND p.frozen_zone_dias IS NOT NULL AND p.transit_time_dias IS NOT NULL AND p.estoque_min_dias IS NOT NULL AND p.estoque_max_dias IS NOT NULL";
if (!empty($params)) {
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
    $sqlExport = "SELECT p.codigo_componente, p.moq, p.frozen_zone_dias, p.transit_time_dias, p.estoque_min_dias, p.estoque_max_dias, p.setup FROM parametros_compra p $where ORDER BY p.codigo_componente";
    if (!empty($params)) {
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
    fputcsv($saida, ['Componente', 'MOQ', 'Lead Time (dias)', 'Transit Time (dias)', 'Estoque Min (dias)', 'Estoque Max (dias)', 'Setup (%)'], ';', '"', '');
    while ($linha = mysqli_fetch_assoc($resultExport)) {
        // MOQ e setup vêm do banco com ponto decimal (ex.: 500.0000, 20.00). O Excel em
        // português espera vírgula decimal e lê o ponto como separador de milhar,
        // concatenando os dígitos (500.0000 vira 5.000.000). Formatando aqui com vírgula
        // e sem separador de milhar, o Excel-BR lê certo. frozen/transit/min/max já são
        // inteiros puros no banco (sem parte decimal), então não têm esse problema.
        $moqFormatado = $linha['moq'] !== null ? number_format((float) $linha['moq'], 0, ',', '') : '';
        $setupFormatado = $linha['setup'] !== null ? number_format((float) $linha['setup'], 2, ',', '') : '';

        fputcsv($saida, [
            $linha['codigo_componente'], $moqFormatado, $linha['frozen_zone_dias'],
            $linha['transit_time_dias'], $linha['estoque_min_dias'], $linha['estoque_max_dias'], $setupFormatado,
        ], ';', '"', '');
    }
    fclose($saida);
    exit;
}

$sql = "SELECT p.codigo_componente, p.moq, p.frozen_zone_dias, p.transit_time_dias, p.estoque_min_dias, p.estoque_max_dias, p.setup,
               bg.fornecedores
        FROM parametros_compra p
        LEFT JOIN (
            SELECT TRIM(codigo_componente) AS codigo_componente,
                   GROUP_CONCAT(DISTINCT NULLIF(TRIM(fornecedor), '') ORDER BY TRIM(fornecedor) SEPARATOR ', ') AS fornecedores
            FROM bomnova
            GROUP BY TRIM(codigo_componente)
        ) bg ON bg.codigo_componente = TRIM(p.codigo_componente)
        $where
        ORDER BY p.codigo_componente
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

// Estoque de segurança calculado (só pra exibir na tela, não é armazenado): busca a
// demanda dos próximos 90 dias (via BOM.consumo × EDI.quantidade) dos componentes desta
// página e aplica a mesma fórmula usada no dashboard e no planejamento de compras.
if (!empty($rows)) {
    $codigosPagina = array_values(array_unique(array_map(fn($r) => trim((string) $r['codigo_componente']), $rows)));
    $placeholdersSeg = implode(',', array_fill(0, count($codigosPagina), '?'));
    $tiposSeg = str_repeat('s', count($codigosPagina));
    $hojeParametros = new DateTimeImmutable('today');
    $fimJanela90Parametros = $hojeParametros->modify('+90 days');

    $demanda90PorComponente = [];
    $stmtDemanda90 = mysqli_prepare($conn, "
        SELECT TRIM(b.codigo_componente) AS codigo_componente,
               SUM(
                   COALESCE(CAST(e.quantidade AS DECIMAL(18,4)), 0)
                   * COALESCE(CAST(NULLIF(REPLACE(TRIM(b.consumo), ',', '.'), '') AS DECIMAL(18,6)), 0)
               ) AS demanda_90d
        FROM bomnova b
        JOIN edi e ON TRIM(b.material) = TRIM(e.material)
        WHERE TRIM(b.codigo_componente) IN ($placeholdersSeg)
          AND (b.mrp IS NULL OR UPPER(TRIM(b.mrp)) <> 'N')
          AND (e.atendido = 0 OR e.atendido IS NULL)
          AND e.data_inicio BETWEEN ? AND ?
        GROUP BY TRIM(b.codigo_componente)
    ");
    $tiposComDatas = $tiposSeg . 'ss';
    $paramsComDatas = array_merge($codigosPagina, [$hojeParametros->format('Y-m-d'), $fimJanela90Parametros->format('Y-m-d')]);
    mysqli_stmt_bind_param($stmtDemanda90, $tiposComDatas, ...$paramsComDatas);
    mysqli_stmt_execute($stmtDemanda90);
    $resDemanda90 = mysqli_stmt_get_result($stmtDemanda90);
    while ($linhaDem90 = mysqli_fetch_assoc($resDemanda90)) {
        $demanda90PorComponente[$linhaDem90['codigo_componente']] = (float) $linhaDem90['demanda_90d'];
    }
    mysqli_stmt_close($stmtDemanda90);

    foreach ($rows as &$rowCalc) {
        $codigoCalc = trim((string) $rowCalc['codigo_componente']);
        $setupCalc = $rowCalc['setup'] !== null ? (float) $rowCalc['setup'] : 0.0;
        $frozenCalc = (int) ($rowCalc['frozen_zone_dias'] ?? 0);
        $transitCalc = (int) ($rowCalc['transit_time_dias'] ?? 0);
        $demanda90Calc = $demanda90PorComponente[$codigoCalc] ?? 0.0;

        // "sem_demanda" só quando setup e lead time estão OK mas falta demanda EDI nos
        // próximos 90 dias pra calcular em cima — é o único motivo que vale destacar
        // separado, porque indica dado faltando (não uma escolha deliberada do usuário).
        // Setup = 0% ou Lead Time + Transit Time = 0 são configurações válidas (resultado
        // esperado = 0), não tratadas como "faltando dado".
        $rowCalc['estoque_seguranca_calculado'] = 0.0;
        $rowCalc['estoque_seguranca_motivo'] = 'zero';
        if ($setupCalc > 0 && ($frozenCalc + $transitCalc) > 0) {
            if ($demanda90Calc > 0) {
                $demandaSemanalCalc = ($demanda90Calc / 90) * 7;
                $desvioCalc = $demandaSemanalCalc * ($setupCalc / 100);
                $leadTimeSemanasCalc = ($frozenCalc + $transitCalc) / 7;
                $rowCalc['estoque_seguranca_calculado'] = MRP_Z_NIVEL_SERVICO * $desvioCalc * sqrt($leadTimeSemanasCalc);
                $rowCalc['estoque_seguranca_motivo'] = 'calculado';
            } else {
                $rowCalc['estoque_seguranca_motivo'] = 'sem_demanda';
            }
        }
    }
    unset($rowCalc);
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
        .table th {
            background: #f8f9fa;
            white-space: normal;
            text-align: center;
            vertical-align: middle;
            line-height: 1.25;
        }
        .table th.text-end { text-align: center !important; }
        /* Cabeçalhos de MOQ até "Estoque Segurança (calculado)": largura limitada pra
           forçar a quebra em ~2 linhas em vez de esticar a tabela toda. */
        .table th:nth-child(3),
        .table th:nth-child(4),
        .table th:nth-child(5),
        .table th:nth-child(6),
        .table th:nth-child(7),
        .table th:nth-child(8) { max-width: 95px; }
        .table th:nth-child(9) { max-width: 115px; }
        .table td { white-space: nowrap; text-align: center; }
        .table td.text-end { text-align: center !important; }
        .badge-completo { background: #eaf8f0; color: #247a4d; }
        .badge-incompleto { background: #fff7df; color: #a96600; }
        summary { cursor: pointer; font-weight: 700; color: #405164; }
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
        <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Navegação do sistema">
            <a class="btn btn-outline-secondary btn-sm" href="index.php">🏠 Dashboard</a>
            <a class="btn btn-outline-secondary btn-sm" href="estoque.php">Estoque</a>
            <a class="btn btn-outline-secondary btn-sm" href="edi.php">EDI</a>
            <a class="btn btn-outline-secondary btn-sm" href="bomnova.php">BOM</a>
            <a class="btn btn-outline-secondary btn-sm" href="programacao.php">Programação</a>
            <a class="btn btn-outline-secondary btn-sm" href="parametros_compra.php">Parâmetros</a>
            <a class="btn btn-outline-secondary btn-sm" href="evolucao_geral.php">Evolução geral</a>
            <a class="btn btn-outline-secondary btn-sm" href="planejamento_compras.php">Planejamento de compras</a>
        </nav>
        <div class="card bg-primary text-white p-4 mb-4">
            <h1>⚙️ Parâmetros de Compra</h1>
            <p class="mb-0">
                <?php echo number_format($total, 0, ',', '.'); ?> componente(s) na base
                • <?php echo number_format($totalCompletos, 0, ',', '.'); ?> com os 5 parâmetros completos (entram no planejamento)
            </p>
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
                        <code>codigo_componente, moq, lead_time_dias, transit_time_dias, estoque_min_dias, estoque_max_dias, setup</code><br>
                        (A coluna do componente também aceita o cabeçalho <code>componente</code>, igual nas outras telas de importação do sistema.)<br>
                        (O CSV exportado pela tela usa títulos amigáveis — "MOQ", "Lead Time (dias)" etc. — e pode ser reimportado normalmente; os dois formatos de cabeçalho são aceitos.)<br>
                        Todas as colunas exceto <code>codigo_componente</code> são opcionais — mas a data sugerida de compra só é calculada para componentes com <strong>todos</strong> os 5 parâmetros principais preenchidos (MOQ, Lead Time, Transit Time, Min, Max).<br>
                        <code>estoque_min_dias</code>/<code>estoque_max_dias</code> = dias de cobertura de estoque desejados (não quantidade). <code>lead_time_dias</code> + <code>transit_time_dias</code> = tempo mínimo de reação (dias) entre decidir comprar e o material chegar.<br>
                        <code>setup</code> = percentual de perda (scrap) do componente. Digite só o número, sem o símbolo "%" (ex.: <code>20</code> significa 20%). É opcional; se não preencher, a sugestão de compra não sofre acréscimo. No planejamento de compras, a quantidade sugerida é multiplicada por <code>(1 + setup/100)</code> — ex.: sugestão de 12.000 com <code>setup=20</code> vira 14.400.<br>
                        <strong>Estoque de segurança:</strong> não é mais um campo pra preencher — o sistema calcula sozinho, componente por componente, usando a fórmula Z × desvio-padrão da demanda semanal × raiz(lead time em semanas). A demanda semanal vem da média dos próximos 90 dias de EDI; o desvio-padrão usa o <code>setup</code> como proxy de variabilidade; o lead time é <code>lead_time_dias + transit_time_dias</code>. O resultado aparece na coluna "Estoque Segurança (un)" abaixo, com três estados possíveis:<br>
                        &nbsp;&nbsp;• <strong>Um número</strong> = calculado normalmente.<br>
                        &nbsp;&nbsp;• <strong>"0"</strong> = setup em 0% (sem variabilidade configurada) ou Lead Time + Transit Time em 0 (sem tempo de reação pra projetar) — configuração válida, não é dado faltando.<br>
                        &nbsp;&nbsp;• <strong>"sem demanda"</strong> = setup e Lead Time preenchidos, mas não há demanda EDI cadastrada nos próximos 90 dias pra calcular a variabilidade em cima — aqui sim é dado faltando, vale checar o EDI do componente.<br>
                        Esse valor calculado também é usado como piso na simulação do Dashboard e do Planejamento de Compras: "Compra urgente" passa a disparar quando o saldo projetado cai abaixo desse piso, não mais só quando fica negativo — ou seja, a compra é sinalizada mais cedo.<br>
                        Separador: vírgula ou ponto e vírgula (detectado automaticamente).
                    </small>
                </div>
            </details>
        </div>

        <div class="card p-3 mb-4">
            <details>
                <summary>➕ Novo componente (cadastro manual)</summary>
                <form method="POST" class="row g-2 align-items-end mt-3">
                    <input type="hidden" name="acao" value="inserir_manual">
                    <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                    <input type="hidden" name="busca_atual" value="<?php echo h($busca); ?>">
                    <input type="hidden" name="fornecedor_atual" value="<?php echo h($fornecedorFiltro); ?>">
                    <div class="col-auto">
                        <label class="form-label small mb-1">Componente *</label>
                        <input type="text" name="componente_manual" list="lista_componentes" class="form-control form-control-sm" style="width:150px" required>
                    </div>
                    <div class="col-auto">
                        <label class="form-label small mb-1">MOQ</label>
                        <input type="text" name="moq_manual" class="form-control form-control-sm text-end" style="width:100px">
                    </div>
                    <div class="col-auto">
                        <label class="form-label small mb-1">Lead Time (dias)</label>
                        <input type="text" name="frozen_manual" class="form-control form-control-sm text-end" style="width:110px">
                    </div>
                    <div class="col-auto">
                        <label class="form-label small mb-1">Transit Time (dias)</label>
                        <input type="text" name="transit_manual" class="form-control form-control-sm text-end" style="width:110px">
                    </div>
                    <div class="col-auto">
                        <label class="form-label small mb-1">Estoque Min (dias)</label>
                        <input type="text" name="min_manual" class="form-control form-control-sm text-end" style="width:110px">
                    </div>
                    <div class="col-auto">
                        <label class="form-label small mb-1">Estoque Max (dias)</label>
                        <input type="text" name="max_manual" class="form-control form-control-sm text-end" style="width:110px">
                    </div>
                    <div class="col-auto">
                        <label class="form-label small mb-1">Setup (%)</label>
                        <input type="text" name="setup_manual" class="form-control form-control-sm text-end" style="width:100px" placeholder="Ex.: 20">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary btn-sm">Salvar</button>
                    </div>
                    <div class="col-12">
                        <small class="text-muted">Se o componente já existir na tabela, os parâmetros dele são atualizados (mesmo comportamento do CSV). O estoque de segurança não se cadastra — o sistema calcula sozinho a partir da demanda, do setup e do lead time.</small>
                    </div>
                </form>
            </details>
        </div>

        <div class="card p-3 mb-4">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-auto flex-grow-1">
                    <input type="text" name="busca" class="form-control" placeholder="Buscar por componente..." value="<?php echo h($busca); ?>">
                </div>
                <div class="col-auto">
                    <select name="fornecedor" class="form-select" onchange="this.form.submit()">
                        <option value="">Todos os fornecedores</option>
                        <?php foreach ($fornecedoresDisponiveis as $f): ?>
                            <option value="<?php echo h($f); ?>" <?php echo $fornecedorFiltro === $f ? 'selected' : ''; ?>><?php echo h($f); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">Buscar</button>
                    <a href="parametros_compra.php" class="btn btn-outline-secondary">Limpar</a>
                    <a href="?busca=<?php echo urlencode($busca); ?>&fornecedor=<?php echo urlencode($fornecedorFiltro); ?>&exportar=csv" class="btn btn-outline-primary">Exportar CSV</a>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-body table-responsive">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Componente</th>
                            <th>Fornecedor</th>
                            <th class="text-end">MOQ</th>
                            <th class="text-end">Lead Time (dias)</th>
                            <th class="text-end">Transit Time (dias)</th>
                            <th class="text-end">Estoque Min (dias)</th>
                            <th class="text-end">Estoque Max (dias)</th>
                            <th class="text-end">Setup (%)</th>
                            <th class="text-end">Estoque Segurança (un)</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="10" class="text-center text-muted">Nenhum registro encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                    $codigoLinha = $row['codigo_componente'] ?? '';
                                    $emEdicao = ($editando !== '' && $editando === $codigoLinha);
                                    $linkVoltar = '?pagina=' . $pagina . '&busca=' . urlencode($busca) . '&fornecedor=' . urlencode($fornecedorFiltro);
                                ?>
                                <tr>
                                    <td><strong><?php echo h($codigoLinha); ?></strong></td>
                                    <td title="<?php echo h($row['fornecedores'] ?? ''); ?>"><span class="text-truncate-cell"><?php echo h($row['fornecedores'] ?? '—'); ?></span></td>

                                    <?php if ($emEdicao): ?>
                                        <td colspan="6">
                                            <form method="POST" class="d-flex gap-2 align-items-center flex-wrap m-0">
                                                <input type="hidden" name="acao" value="editar_registro">
                                                <input type="hidden" name="codigo_editar" value="<?php echo h($codigoLinha); ?>">
                                                <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                                                <input type="hidden" name="busca_atual" value="<?php echo h($busca); ?>">
                                                <input type="hidden" name="fornecedor_atual" value="<?php echo h($fornecedorFiltro); ?>">
                                                <input type="text" name="moq_editado" class="form-control form-control-sm text-end" style="width:90px" value="<?php echo h($row['moq'] ?? ''); ?>" placeholder="MOQ">
                                                <input type="text" name="frozen_editado" class="form-control form-control-sm text-end" style="width:90px" value="<?php echo h($row['frozen_zone_dias'] ?? ''); ?>" placeholder="Lead Time">
                                                <input type="text" name="transit_editado" class="form-control form-control-sm text-end" style="width:90px" value="<?php echo h($row['transit_time_dias'] ?? ''); ?>" placeholder="Transit">
                                                <input type="text" name="min_editado" class="form-control form-control-sm text-end" style="width:90px" value="<?php echo h($row['estoque_min_dias'] ?? ''); ?>" placeholder="Min">
                                                <input type="text" name="max_editado" class="form-control form-control-sm text-end" style="width:90px" value="<?php echo h($row['estoque_max_dias'] ?? ''); ?>" placeholder="Max">
                                                <input type="text" name="setup_editado" class="form-control form-control-sm text-end" style="width:90px" value="<?php echo h($row['setup'] ?? ''); ?>" placeholder="Setup %">
                                                <button type="submit" class="btn btn-success btn-sm">Salvar</button>
                                                <a href="<?php echo $linkVoltar; ?>" class="btn btn-outline-secondary btn-sm">Cancelar</a>
                                            </form>
                                        </td>
                                        <?php $celSeg = celulaEstoqueSeguranca($row); ?>
                                        <td class="text-end text-muted" <?php if ($celSeg[1] !== ''): ?>title="<?php echo h($celSeg[1]); ?> (recalcula ao salvar)"<?php endif; ?>><?php echo h($celSeg[0]); ?></td>
                                        <td>
                                            <a href="<?php echo $linkVoltar; ?>&editar=<?php echo urlencode($codigoLinha); ?>" class="btn btn-outline-secondary btn-sm">Editar</a>
                                        </td>
                                    <?php else: ?>
                                        <td class="text-end"><?php echo $row['moq'] !== null ? number_format((float) $row['moq'], 0, ',', '.') : '—'; ?></td>
                                        <td class="text-end"><?php echo $row['frozen_zone_dias'] ?? '—'; ?></td>
                                        <td class="text-end"><?php echo $row['transit_time_dias'] ?? '—'; ?></td>
                                        <td class="text-end"><?php echo $row['estoque_min_dias'] ?? '—'; ?></td>
                                        <td class="text-end"><?php echo $row['estoque_max_dias'] ?? '—'; ?></td>
                                        <td class="text-end"><?php echo $row['setup'] !== null ? number_format((float) $row['setup'], 2, ',', '.') . '%' : '—'; ?></td>
                                        <?php $celSeg = celulaEstoqueSeguranca($row); ?>
                                        <td class="text-end" <?php if ($celSeg[1] !== ''): ?>title="<?php echo h($celSeg[1]); ?>"<?php endif; ?>>
                                            <?php echo h($celSeg[0]); ?>
                                        </td>
                                        <td>
                                            <a href="<?php echo $linkVoltar; ?>&editar=<?php echo urlencode($codigoLinha); ?>" class="btn btn-outline-secondary btn-sm">Editar</a>
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
                    <a href="?pagina=<?php echo $pagina - 1; ?>&busca=<?php echo urlencode($busca); ?>&fornecedor=<?php echo urlencode($fornecedorFiltro); ?>" class="btn btn-outline-primary btn-sm">← Anterior</a>
                <?php endif; ?>
            </div>
            <div class="text-muted">Página <?php echo $pagina; ?> de <?php echo $totalPaginas; ?></div>
            <div>
                <?php if ($pagina < $totalPaginas): ?>
                    <a href="?pagina=<?php echo $pagina + 1; ?>&busca=<?php echo urlencode($busca); ?>&fornecedor=<?php echo urlencode($fornecedorFiltro); ?>" class="btn btn-outline-primary btn-sm">Próxima →</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="text-center mt-4">
            <a href="index.php" class="btn btn-outline-secondary">Voltar ao Dashboard</a>
        </div>
    </div>
</body>
</html>
