<?php
require_once 'conexao.php';

require_once 'auth.php';
exigirLogin();
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
    exigirComprador();
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

                // Qualquer coluna que não seja codigo/descricao/estoque é tratada como
                // uma coluna de planta (ex.: "2401", "2403"), usando o texto original
                // do cabeçalho (sem normalizar) como identificador da planta.
                $colunasPlanta = [];
                foreach ($cabecalhoOriginal as $indice => $nomeOriginal) {
                    if ($indice === $idxCodigo || $indice === $idxDescricao || $indice === $idxEstoqueTotal) {
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

function h(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

// ---------- Ajuste manual de estoque ----------
// A tabela "estoque" funciona como livro-razão (várias linhas por componente/
// planta, somadas na tela). Por isso, "editar" aqui NUNCA sobrescreve nem
// apaga linha nenhuma — em vez disso, insere uma linha de AJUSTE com a
// DIFERENÇA entre o valor atual (soma) e o valor novo desejado, marcada com
// origem = "ajuste_manual". Isso preserva o histórico completo (de onde veio
// cada entrada) e continua permitindo auditoria depois.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar_estoque_ajuste') {
    exigirComprador();
    $codigoAjuste = trim($_POST['codigo_componente_editado'] ?? '');
    if ($codigoAjuste === '') {
        $mensagens[] = '❌ Componente inválido pra ajuste.';
    } else {
        // Recalcula os valores ATUAIS (somados) por planta, direto no servidor —
        // nunca confia em nenhum valor "atual" que tenha vindo escondido do
        // formulário, pra não correr risco de calcular o ajuste errado se o
        // estoque tiver mudado entre a página carregar e o clique em Salvar.
        $atuais = [];
        $stmtAtual = mysqli_prepare($conn, "
            SELECT COALESCE(NULLIF(TRIM(planta), ''), 'SEM_PLANTA') AS planta_chave,
                   SUM(COALESCE(CAST(estoque AS DECIMAL(18,4)), 0)) AS valor
            FROM estoque
            WHERE codigo_componente = ?
            GROUP BY COALESCE(NULLIF(TRIM(planta), ''), 'SEM_PLANTA')
        ");
        mysqli_stmt_bind_param($stmtAtual, 's', $codigoAjuste);
        mysqli_stmt_execute($stmtAtual);
        $resAtual = mysqli_stmt_get_result($stmtAtual);
        while ($linhaAtual = mysqli_fetch_assoc($resAtual)) {
            $atuais[$linhaAtual['planta_chave']] = (float) $linhaAtual['valor'];
        }
        mysqli_stmt_close($stmtAtual);

        // Descrição não é editável aqui — usa a que já existe no componente,
        // só pra a linha de ajuste ficar legível se alguém consultar o banco.
        $stmtDescAtual = mysqli_prepare($conn, "SELECT MAX(descricao) AS descricao FROM estoque WHERE codigo_componente = ?");
        mysqli_stmt_bind_param($stmtDescAtual, 's', $codigoAjuste);
        mysqli_stmt_execute($stmtDescAtual);
        $descricaoParaAjuste = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtDescAtual))['descricao'] ?? null;
        mysqli_stmt_close($stmtDescAtual);

        $ajustesFeitos = 0;
        $valoresPostados = $_POST['planta_valor'] ?? [];

        foreach ($valoresPostados as $plantaChave => $valorTexto) {
            $novoValor = parseNumeroBr((string) $valorTexto) ?? 0.0;
            $valorAtual = $atuais[$plantaChave] ?? 0.0;
            $delta = $novoValor - $valorAtual;

            if (abs($delta) > 0.0001) {
                $plantaReal = $plantaChave === 'SEM_PLANTA' ? null : $plantaChave;
                $stmtAjuste = mysqli_prepare($conn, "
                    INSERT INTO estoque (codigo_componente, descricao, estoque, planta, origem)
                    VALUES (?, ?, ?, ?, 'ajuste_manual')
                ");
                mysqli_stmt_bind_param($stmtAjuste, 'ssds', $codigoAjuste, $descricaoParaAjuste, $delta, $plantaReal);
                mysqli_stmt_execute($stmtAjuste);
                mysqli_stmt_close($stmtAjuste);
                $ajustesFeitos++;
            }
        }

        $paginaVolta = (int) ($_POST['pagina_atual'] ?? 1);
        $buscaVolta = (string) ($_POST['busca_atual'] ?? '');
        $flag = $ajustesFeitos > 0 ? 'ajustado=1' : 'sem_mudanca=1';
        header('Location: estoque.php?pagina=' . $paginaVolta . '&busca=' . urlencode($buscaVolta) . '&' . $flag . '#linha-' . urlencode($codigoAjuste));
        exit;
    }
}

$porPagina = 50;
$pagina = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$offset = ($pagina - 1) * $porPagina;

$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$editandoCodigo = trim($_GET['editar'] ?? '');

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

    $cabecalhoCsv = ['Componente', 'Descrição'];
    foreach ($plantas as $p) { $cabecalhoCsv[] = $p; }
    if ($temSemPlanta) { $cabecalhoCsv[] = 'Sem planta'; }
    $cabecalhoCsv[] = 'Total';
    $cabecalhoCsv[] = 'MRP';
    fputcsv($saida, $cabecalhoCsv, ';', '"', '');

    foreach ($componentesExport as $codigo => $linha) {
        [, $mrpTexto] = statusMrp($linha['total_linhas'] !== null ? (int) $linha['total_linhas'] : null, $linha['tem_ativo'] !== null ? (int) $linha['tem_ativo'] : null);
        $linhaCsv = [$codigo, $linha['descricao']];
        // Estoque vem do banco com ponto decimal (ex.: 1234.5600). O Excel em português
        // espera vírgula decimal e lê o ponto como separador de milhar, concatenando os
        // dígitos (1234.5600 vira 12.345.600). Formatando aqui com vírgula e sem
        // separador de milhar, o Excel-BR lê certo.
        foreach ($plantas as $p) {
            $valorPlanta = $porPlantaExport[$codigo][$p] ?? null;
            $linhaCsv[] = $valorPlanta !== null ? number_format($valorPlanta, 2, ',', '') : '';
        }
        if ($temSemPlanta) {
            $valorSemPlanta = $porPlantaExport[$codigo][''] ?? null;
            $linhaCsv[] = $valorSemPlanta !== null ? number_format($valorSemPlanta, 2, ',', '') : '';
        }
        $linhaCsv[] = number_format((float) $linha['total'], 2, ',', '');
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
        <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Navegação do sistema">
            <a class="btn btn-outline-light btn-sm" href="usuarios.php">👤 Usuários</a>
                <a class="btn btn-outline-light btn-sm" href="logout.php">🚪 Sair</a>
                <a class="btn btn-outline-secondary btn-sm" href="index.php">🏠 Dashboard</a>
            <a class="btn btn-outline-secondary btn-sm" href="estoque.php">Estoque</a>
            <a class="btn btn-outline-secondary btn-sm" href="edi.php">EDI</a>
            <a class="btn btn-outline-secondary btn-sm" href="bomnova.php">BOM</a>
            <a class="btn btn-outline-secondary btn-sm" href="programacao.php">Programação</a>
            <a class="btn btn-outline-secondary btn-sm" href="parametros_compra.php">Parâmetros</a>
            <a class="btn btn-outline-secondary btn-sm" href="evolucao_geral.php">Evolução geral</a>
            <a class="btn btn-outline-secondary btn-sm" href="planejamento_compras.php">Planejamento de compras</a>
            <a class="btn btn-outline-secondary btn-sm" href="pedido_compra.php">📄 Pedido de Compra</a>
        </nav>
        <div class="card bg-primary text-white p-4 mb-4">
            <h1>🏷️ Estoque</h1>
            <p class="mb-0">
                <?php echo number_format($total, 0, ',', '.'); ?> componente(s) na base
                • soma geral do estoque (todas as plantas): <?php echo number_format($somaEstoque, 2, ',', '.'); ?>
            </p>
        </div>

        <?php if (isset($_GET['ajustado'])): ?>
            <div class="alert alert-success">✅ Estoque ajustado com sucesso.</div>
        <?php elseif (isset($_GET['sem_mudanca'])): ?>
            <div class="alert alert-secondary">Nada foi alterado (os valores digitados já eram os mesmos).</div>
        <?php endif; ?>

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
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($componentes)): ?>
                            <tr><td colspan="<?php echo 5 + count($plantas) + ($temSemPlanta ? 1 : 0); ?>" class="text-center text-muted">Nenhum registro encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($componentes as $codigo => $linha): ?>
                                <?php
                                    [$badgeClasse, $badgeTexto] = statusMrp(
                                        $linha['total_linhas'] !== null ? (int) $linha['total_linhas'] : null,
                                        $linha['tem_ativo'] !== null ? (int) $linha['tem_ativo'] : null
                                    );
                                    $emEdicao = $editandoCodigo !== '' && $editandoCodigo === (string) $codigo;
                                    $linkVoltar = 'estoque.php?pagina=' . $pagina . '&busca=' . urlencode($busca);
                                    // Componente e Descrição ficam FORA do formulário (não editáveis) —
                                    // o colspan cobre só as colunas de planta + Total + MRP.
                                    $colspanEdicao = 2 + count($plantas) + ($temSemPlanta ? 1 : 0);
                                ?>
                                <tr id="linha-<?php echo h($codigo); ?>">
                                    <td><strong><?php echo h($codigo); ?></strong></td>
                                    <td><?php echo h($linha['descricao'] ?? ''); ?></td>

                                    <?php if ($emEdicao): ?>
                                        <td colspan="<?php echo $colspanEdicao; ?>">
                                            <form method="POST" class="row g-2 align-items-end py-2">
                                                <input type="hidden" name="acao" value="editar_estoque_ajuste">
                                                <input type="hidden" name="codigo_componente_editado" value="<?php echo h($codigo); ?>">
                                                <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                                                <input type="hidden" name="busca_atual" value="<?php echo h($busca); ?>">
                                                <?php foreach ($plantas as $p): ?>
                                                    <div class="col-md-2">
                                                        <label class="form-label small mb-0">Estoque <?php echo h($p); ?></label>
                                                        <input type="text" name="planta_valor[<?php echo h($p); ?>]" class="form-control form-control-sm" value="<?php echo isset($porPlanta[$codigo][$p]) ? number_format($porPlanta[$codigo][$p], 2, ',', '.') : '0,00'; ?>">
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if ($temSemPlanta): ?>
                                                    <div class="col-md-2">
                                                        <label class="form-label small mb-0">Sem planta</label>
                                                        <input type="text" name="planta_valor[SEM_PLANTA]" class="form-control form-control-sm" value="<?php echo isset($porPlanta[$codigo]['']) ? number_format($porPlanta[$codigo][''], 2, ',', '.') : '0,00'; ?>">
                                                    </div>
                                                <?php endif; ?>
                                                <div class="col-12">
                                                    <small class="text-muted">Alterar um valor grava um ajuste com a diferença (não apaga histórico). Componente e descrição não são editáveis aqui.</small>
                                                </div>
                                                <div class="col-12 d-flex gap-2 mt-1">
                                                    <button type="submit" class="btn btn-success btn-sm">Salvar</button>
                                                    <a href="<?php echo $linkVoltar; ?>#linha-<?php echo h($codigo); ?>" class="btn btn-outline-secondary btn-sm">Cancelar</a>
                                                </div>
                                            </form>
                                        </td>
                                        <td></td>
                                    <?php else: ?>
                                        <?php foreach ($plantas as $p): ?>
                                            <td class="text-end"><?php echo isset($porPlanta[$codigo][$p]) ? number_format($porPlanta[$codigo][$p], 2, ',', '.') : '—'; ?></td>
                                        <?php endforeach; ?>
                                        <?php if ($temSemPlanta): ?>
                                            <td class="text-end"><?php echo isset($porPlanta[$codigo]['']) ? number_format($porPlanta[$codigo][''], 2, ',', '.') : '—'; ?></td>
                                        <?php endif; ?>
                                        <td class="text-end col-total"><?php echo number_format((float) $linha['total'], 2, ',', '.'); ?></td>
                                        <td><span class="badge <?php echo $badgeClasse; ?>"><?php echo h($badgeTexto); ?></span></td>
                                        <td>
                                            <a href="<?php echo $linkVoltar; ?>&editar=<?php echo urlencode($codigo); ?>#linha-<?php echo h($codigo); ?>" class="btn btn-outline-secondary btn-sm">Editar</a>
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
    <script>
        // Fallback pra garantir o scroll até a linha certa — a âncora (#linha-x)
        // já deveria fazer isso sozinha, mas algumas combinações de navegador/
        // cabeçalho fixo não respeitam isso direito. Isso força o scroll de
        // verdade, centralizando a linha na tela em vez de jogar ela pro topo.
        if (window.location.hash) {
            const alvo = document.querySelector(window.location.hash);
            if (alvo) {
                alvo.scrollIntoView({ block: 'center' });
            }
        }
    </script>
</body>
</html>
