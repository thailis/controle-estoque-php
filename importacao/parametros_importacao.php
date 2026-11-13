<?php
// importacao/parametros_importacao.php
// Parâmetros por COMPONENTE (independente de processo/projeto) usados na tela
// Release: unit pack, U PRICE, medidas da embalagem (LENGTH/WIDTH/HEIGHT em cm),
// peso unitário (weight of component, kg), G WEIGHT e NCM (preenche o NCM vazio em Processos).
// PACK, CBM, T PRICE e N WEIGHT NÃO ficam aqui — são calculados na Release.
// Tabela: parametros_importacao (ver SQL no README da tela / conversa).
require_once 'conexao.php';
require_once __DIR__ . '/auth_importacao.php'; // exige login (o mesmo do MRP)

function h(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function numeroBrParam($valor, int $decimais = 2): string
{
    if ($valor === null || $valor === '') {
        return '—';
    }
    // mostra só as casas que existem (até $decimais), sem zeros à toa
    $txt = number_format((float) $valor, $decimais, ',', '.');
    return str_contains($txt, ',') ? rtrim(rtrim($txt, '0'), ',') : $txt;
}

// Aceita "1.234,56", "1234,56" e "1234.56"
function parseNumeroParam(string $valor): ?float
{
    $valor = trim(str_replace(' ', '', $valor));
    if ($valor === '') {
        return null;
    }
    if (str_contains($valor, ',')) {
        $valor = str_replace(['.', ','], ['', '.'], $valor);
    } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $valor)) {
        $valor = str_replace('.', '', $valor);
    }
    return is_numeric($valor) ? (float) $valor : null;
}

// Conexão (só leitura) com o banco do MRP, pra puxar a descrição da BOM.
// Mesmas credenciais MRP_DB_* da Release / Confirmar entrega.
function conectarMrpParametros(): ?mysqli
{
    try {
        $host = getenv('MRP_DB_HOST') ?: '';
        $user = getenv('MRP_DB_USER') ?: '';
        $pass = getenv('MRP_DB_PASSWORD') ?: '';
        if ($host === '' || $user === '' || $pass === '') {
            return null;
        }
        $c = mysqli_init();
        mysqli_options($c, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
        mysqli_ssl_set($c, null, null, getenv('MRP_DB_SSL_CA') ?: '/etc/ssl/certs/ca-certificates.crt', null, null);
        if (!@mysqli_real_connect($c, $host, $user, $pass, getenv('MRP_DB_NAME') ?: 'controle_mrp', (int) (getenv('MRP_DB_PORT') ?: 4000), null, MYSQLI_CLIENT_SSL)) {
            return null;
        }
        mysqli_set_charset($c, 'utf8mb4');
        return $c;
    } catch (Throwable $e) {
        return null;
    }
}

// Campos numéricos editáveis => rótulo
const CAMPOS_PARAM_IMPORTACAO = [
    'unit_pack'     => 'Unit pack',
    'u_price'       => 'U PRICE',
    'length_cm'     => 'LENGTH',
    'width_cm'      => 'WIDTH',
    'height_cm'     => 'HEIGHT',
    'peso_unitario' => 'Weight un (kg)',
    'g_weight'      => 'G WEIGHT',
];

// Grava (insere ou atualiza) um componente. $valores: campo => float|null.
// Só os campos presentes em $valores são alterados num componente que já existe.
function salvarParametroImportacao(mysqli $conn, string $codigo, array $valores): void
{
    $colunas = array_keys($valores);
    $sql = 'INSERT INTO parametros_importacao (codigo_componente, ' . implode(', ', $colunas) . ') VALUES (?' . str_repeat(', ?', count($colunas)) . ')'
        . ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "$c = VALUES($c)", $colunas));
    $stmt = mysqli_prepare($conn, $sql);
    $params = array_merge([$codigo], array_values($valores));
    mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...array_map(fn($v) => $v === null ? null : (string) $v, $params));
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

$mensagens = [];
$tabelaExiste = true;
try {
    mysqli_query($conn, 'SELECT 1 FROM parametros_importacao LIMIT 1');
} catch (Throwable $e) {
    $tabelaExiste = false;
}

// ---------- Siglas de fornecedor (código dos processos de categoria Release) ----------
// Ex.: KURARAY → KR, então o processo fica Y26S1KR01. Tabela: siglas_fornecedor.
$siglasExiste = true;
try {
    mysqli_query($conn, 'SELECT 1 FROM siglas_fornecedor LIMIT 1');
} catch (Throwable $e) {
    $siglasExiste = false;
}
if ($siglasExiste && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_sigla') {
    $fornSigla = trim((string) ($_POST['fornecedor_sigla'] ?? ''));
    $sigla = strtoupper(trim((string) ($_POST['sigla'] ?? '')));
    $siglaAntiga = trim((string) ($_POST['fornecedor_original'] ?? ''));
    if ($fornSigla === '' || !preg_match('/^[A-Z]{2,3}$/', $sigla)) {
        $mensagens[] = '❌ Informe o fornecedor e uma sigla de 2 (ou 3) letras.';
    } else {
        try {
            // sigla não pode repetir em outro fornecedor (o código ficaria ambíguo)
            $stmtDup = mysqli_prepare($conn, 'SELECT fornecedor FROM siglas_fornecedor WHERE UPPER(sigla) = ? AND UPPER(fornecedor) <> UPPER(?) AND UPPER(fornecedor) <> UPPER(?)');
            mysqli_stmt_bind_param($stmtDup, 'sss', $sigla, $fornSigla, $siglaAntiga);
            mysqli_stmt_execute($stmtDup);
            $dup = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtDup));
            mysqli_stmt_close($stmtDup);
            if ($dup) {
                $mensagens[] = "❌ A sigla $sigla já é do fornecedor {$dup['fornecedor']}.";
            } else {
                if ($siglaAntiga !== '' && strcasecmp($siglaAntiga, $fornSigla) !== 0) {
                    $stmtDel = mysqli_prepare($conn, 'DELETE FROM siglas_fornecedor WHERE fornecedor = ?');
                    mysqli_stmt_bind_param($stmtDel, 's', $siglaAntiga);
                    mysqli_stmt_execute($stmtDel);
                    mysqli_stmt_close($stmtDel);
                }
                $stmtS = mysqli_prepare($conn, 'INSERT INTO siglas_fornecedor (fornecedor, sigla) VALUES (?, ?) ON DUPLICATE KEY UPDATE sigla = VALUES(sigla)');
                mysqli_stmt_bind_param($stmtS, 'ss', $fornSigla, $sigla);
                mysqli_stmt_execute($stmtS);
                mysqli_stmt_close($stmtS);
                $mensagens[] = "✅ $fornSigla = $sigla salvo.";
            }
        } catch (Throwable $e) {
            $mensagens[] = '❌ Erro ao salvar a sigla: ' . $e->getMessage();
        }
    }
}
if ($siglasExiste && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_sigla') {
    $fornSigla = trim((string) ($_POST['fornecedor_sigla'] ?? ''));
    $stmtDel = mysqli_prepare($conn, 'DELETE FROM siglas_fornecedor WHERE fornecedor = ?');
    mysqli_stmt_bind_param($stmtDel, 's', $fornSigla);
    mysqli_stmt_execute($stmtDel);
    mysqli_stmt_close($stmtDel);
    $mensagens[] = "✅ Sigla de $fornSigla excluída.";
}
$siglas = [];
if ($siglasExiste) {
    $resSig = mysqli_query($conn, 'SELECT fornecedor, sigla FROM siglas_fornecedor ORDER BY fornecedor');
    while ($rs = mysqli_fetch_assoc($resSig)) {
        $siglas[] = $rs;
    }
}

// ---------- AJAX: edição inline (duplo clique) ----------
if ($tabelaExiste && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'ajax_editar') {
    header('Content-Type: application/json; charset=utf-8');
    $codigo = trim((string) ($_POST['codigo'] ?? ''));
    $campo = (string) ($_POST['campo'] ?? '');
    $valorTxt = trim((string) ($_POST['valor'] ?? ''));
    // NCM é texto (ex.: 3926.90.90), não número
    if ($codigo !== '' && $campo === 'ncm') {
        try {
            $ncmGravar = $valorTxt !== '' ? mb_substr($valorTxt, 0, 20) : null;
            $stmtNcm = mysqli_prepare($conn, 'INSERT INTO parametros_importacao (codigo_componente, ncm) VALUES (?, ?) ON DUPLICATE KEY UPDATE ncm = VALUES(ncm)');
            mysqli_stmt_bind_param($stmtNcm, 'ss', $codigo, $ncmGravar);
            mysqli_stmt_execute($stmtNcm);
            mysqli_stmt_close($stmtNcm);
            echo json_encode(['ok' => true, 'exibido' => $ncmGravar ?? '—', 'bruto' => $ncmGravar ?? '']);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'erro' => 'Erro ao salvar: ' . $e->getMessage()]);
        }
        exit;
    }
    if ($codigo === '' || !array_key_exists($campo, CAMPOS_PARAM_IMPORTACAO)) {
        echo json_encode(['ok' => false, 'erro' => 'Campo inválido.']);
        exit;
    }
    $valor = parseNumeroParam($valorTxt);
    if ($valorTxt !== '' && $valor === null) {
        echo json_encode(['ok' => false, 'erro' => 'Número inválido.']);
        exit;
    }
    try {
        salvarParametroImportacao($conn, $codigo, [$campo => $valor]);
        echo json_encode(['ok' => true, 'exibido' => numeroBrParam($valor, 6), 'bruto' => $valor === null ? '' : numeroBrParam($valor, 6)]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'erro' => 'Erro ao salvar: ' . $e->getMessage()]);
    }
    exit;
}

// ---------- Cadastro manual ----------
if ($tabelaExiste && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'manual') {
    $codigo = trim((string) ($_POST['codigo_componente'] ?? ''));
    if ($codigo === '') {
        $mensagens[] = '❌ Informe o componente (SAP).';
    } else {
        $valores = [];
        $erro = null;
        foreach (CAMPOS_PARAM_IMPORTACAO as $campo => $rotulo) {
            $txt = trim((string) ($_POST[$campo] ?? ''));
            $valores[$campo] = parseNumeroParam($txt);
            if ($txt !== '' && $valores[$campo] === null) {
                $erro = "$rotulo inválido.";
            }
        }
        $ncmManual = trim((string) ($_POST['ncm'] ?? ''));
        $valores['ncm'] = $ncmManual !== '' ? mb_substr($ncmManual, 0, 20) : null;
        if ($erro) {
            $mensagens[] = '❌ ' . $erro;
        } else {
            try {
                salvarParametroImportacao($conn, $codigo, $valores);
                $mensagens[] = "✅ Componente $codigo salvo.";
            } catch (Throwable $e) {
                $mensagens[] = '❌ Erro ao salvar: ' . $e->getMessage();
            }
        }
    }
}

// ---------- Excluir ----------
if ($tabelaExiste && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir') {
    $codigo = trim((string) ($_POST['codigo'] ?? ''));
    $stmt = mysqli_prepare($conn, 'DELETE FROM parametros_importacao WHERE codigo_componente = ?');
    mysqli_stmt_bind_param($stmt, 's', $codigo);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $mensagens[] = "✅ Componente $codigo excluído.";
}

// ---------- Importar CSV ----------
// Cabeçalho (ordem livre, ; ou ,): componente; unit pack; u price; length; width; height; weight; g weight
// Componente que já existe é ATUALIZADO (só as colunas presentes no CSV).
// Célula vazia no CSV deixa o valor vazio.
if ($tabelaExiste && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'importar_csv') {
    $arquivo = $_FILES['arquivo_csv']['tmp_name'] ?? '';
    if ($arquivo === '' || !is_uploaded_file($arquivo)) {
        $mensagens[] = '❌ Escolha um arquivo CSV.';
    } else {
        $conteudo = file_get_contents($arquivo);
        $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', (string) $conteudo);
        if (!mb_check_encoding($conteudo, 'UTF-8')) {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252');
        }
        $linhas = preg_split('/\r\n|\r|\n/', $conteudo);
        $primeira = $linhas[0] ?? '';
        $sep = substr_count($primeira, ';') >= substr_count($primeira, ',') ? ';' : ',';
        $cab = array_map(fn($c) => mb_strtolower(trim((string) $c), 'UTF-8'), str_getcsv($primeira, $sep, '"', ''));

        $apelidos = [
            'codigo_componente' => ['componente', 'codigo_componente', 'sap', 'part number', 'pn', 'codigo'],
            'unit_pack' => ['unit pack', 'unit_pack', 'unitpack', 'pack unit'],
            'u_price' => ['u price', 'u  price', 'u_price', 'unit price', 'price', 'preco'],
            'length_cm' => ['length', 'length_cm', 'comprimento'],
            'width_cm' => ['width', 'width_cm', 'largura'],
            'height_cm' => ['height', 'height_cm', 'altura'],
            'peso_unitario' => ['weight', 'weight of component', 'weight of component (kg)', 'weight of component（kg）', 'weight un', 'weight un (kg)', 'peso_unitario', 'peso'],
            'g_weight' => ['g weight', 'g_weight', 'gross weight'],
            'ncm' => ['ncm', 'hs code', 'hscode', 'hs_code'],
        ];
        $indices = [];
        foreach ($apelidos as $campo => $nomes) {
            foreach ($cab as $i => $nomeCab) {
                if (in_array(preg_replace('/\s+/', ' ', $nomeCab), $nomes, true)) {
                    $indices[$campo] = $i;
                    break;
                }
            }
        }

        if (!isset($indices['codigo_componente'])) {
            $mensagens[] = '❌ O CSV precisa de uma coluna "componente" (ou SAP).';
        } else {
            $gravados = 0;
            $erros = [];
            $limparTudo = !empty($_POST['limpar_tudo']);
            mysqli_begin_transaction($conn);
            try {
                // "Limpar tudo": apaga todos os parâmetros e deixa só o que vier no CSV.
                // Tudo na mesma transação — se a importação der erro, nada é apagado.
                if ($limparTudo) {
                    mysqli_query($conn, 'DELETE FROM parametros_importacao');
                }
                foreach (array_slice($linhas, 1) as $n => $linha) {
                    if (trim($linha) === '') {
                        continue;
                    }
                    $cols = str_getcsv($linha, $sep, '"', '');
                    $codigo = trim((string) ($cols[$indices['codigo_componente']] ?? ''));
                    if ($codigo === '') {
                        continue;
                    }
                    $valores = [];
                    foreach (array_keys(CAMPOS_PARAM_IMPORTACAO) as $campo) {
                        if (!isset($indices[$campo])) {
                            continue;
                        }
                        $txt = trim((string) ($cols[$indices[$campo]] ?? ''));
                        $valores[$campo] = parseNumeroParam($txt);
                        if ($txt !== '' && $valores[$campo] === null) {
                            $erros[] = 'linha ' . ($n + 2) . ": valor inválido em $campo";
                        }
                    }
                    if (isset($indices['ncm'])) {
                        $ncmCsv = trim((string) ($cols[$indices['ncm']] ?? ''));
                        $valores['ncm'] = $ncmCsv !== '' ? mb_substr($ncmCsv, 0, 20) : null;
                    }
                    if ($valores) {
                        salvarParametroImportacao($conn, $codigo, $valores);
                        $gravados++;
                    }
                }
                mysqli_commit($conn);
                $mensagens[] = "✅ CSV importado: $gravados componente(s) gravado(s)." . ($limparTudo ? ' (parâmetros anteriores apagados)' : '');
                foreach (array_slice($erros, 0, 10) as $e) {
                    $mensagens[] = '⚠️ ' . $e;
                }
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                $mensagens[] = '❌ Erro ao importar: ' . $e->getMessage();
            }
        }
    }
}

// ---------- Exportar CSV ----------
if ($tabelaExiste && ($_GET['exportar'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="parametros_importacao.csv"');
    $saida = fopen('php://output', 'w');
    fwrite($saida, "\xEF\xBB\xBF");
    fputcsv($saida, ['componente', 'ncm', 'unit pack', 'u price', 'length', 'width', 'height', 'weight', 'g weight'], ';', '"', '');
    $res = mysqli_query($conn, 'SELECT * FROM parametros_importacao ORDER BY codigo_componente');
    while ($r = mysqli_fetch_assoc($res)) {
        $linha = [$r['codigo_componente'], (string) ($r['ncm'] ?? '')];
        foreach (array_keys(CAMPOS_PARAM_IMPORTACAO) as $campo) {
            $linha[] = $r[$campo] === null ? '' : str_replace('.', ',', rtrim(rtrim((string) $r[$campo], '0'), '.'));
        }
        fputcsv($saida, $linha, ';', '"', '');
    }
    fclose($saida);
    exit;
}

// ---------- Lista ----------
$busca = trim((string) ($_GET['busca'] ?? ''));
$registros = [];
if ($tabelaExiste) {
    // Busca livre (sem diferenciar maiúscula/minúscula): procura o texto no SAP,
    // na descrição, no fornecedor e no projeto. Vários termos separados por ";"
    // = mostra o que bater com qualquer um. O filtro é aplicado mais abaixo,
    // depois de juntar descrição/fornecedor/projeto (que vêm de outros lugares).
    $termos = array_values(array_filter(array_map(fn($t) => mb_strtolower(trim($t), 'UTF-8'), explode(';', $busca)), fn($t) => $t !== ''));
    $stmt = mysqli_prepare($conn, "SELECT * FROM parametros_importacao ORDER BY codigo_componente");
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($r = mysqli_fetch_assoc($res)) {
        $registros[] = $r;
    }
    mysqli_stmt_close($stmt);

    // Descrição (de Processos) só pra ajudar a identificar o componente na tela
    $descricoes = [];
    $resDesc = mysqli_query($conn, "SELECT TRIM(codigo_componente) AS c, MAX(NULLIF(TRIM(descricao), '')) AS d FROM processos GROUP BY TRIM(codigo_componente)");
    while ($rd = mysqli_fetch_assoc($resDesc)) {
        $descricoes[(string) $rd['c']] = (string) $rd['d'];
    }

    // BOM do MRP (só leitura): Fornecedor e Projeto de cada componente e a descrição
    // dos que não têm descrição em Processos (ex.: ainda não tiveram processo).
    // Componente em mais de um projeto/fornecedor mostra todos, separados por vírgula.
    $bomInfo = [];
    $bomConectada = false;
    $codigosBom = array_values(array_unique(array_filter(array_map(fn($r) => trim((string) $r['codigo_componente']), $registros), fn($c) => $c !== '')));
    if ($codigosBom) {
        $connMrp = conectarMrpParametros();
        if ($connMrp) {
            try {
                $stmtBom = mysqli_prepare($connMrp, "
                    SELECT TRIM(codigo_componente) AS c,
                           MAX(NULLIF(TRIM(descricao), '')) AS d,
                           GROUP_CONCAT(DISTINCT NULLIF(TRIM(fornecedor), '') ORDER BY TRIM(fornecedor) SEPARATOR ', ') AS fornecedores,
                           GROUP_CONCAT(DISTINCT NULLIF(TRIM(projeto), '') ORDER BY TRIM(projeto) SEPARATOR ', ') AS projetos
                    FROM bomnova
                    WHERE TRIM(codigo_componente) IN (" . implode(',', array_fill(0, count($codigosBom), '?')) . ")
                    GROUP BY TRIM(codigo_componente)");
                mysqli_stmt_bind_param($stmtBom, str_repeat('s', count($codigosBom)), ...$codigosBom);
                mysqli_stmt_execute($stmtBom);
                $resBom = mysqli_stmt_get_result($stmtBom);
                while ($rb = mysqli_fetch_assoc($resBom)) {
                    $cod = (string) $rb['c'];
                    $bomInfo[$cod] = ['fornecedores' => (string) ($rb['fornecedores'] ?? ''), 'projetos' => (string) ($rb['projetos'] ?? '')];
                    if (trim((string) ($descricoes[$cod] ?? '')) === '' && trim((string) $rb['d']) !== '') {
                        $descricoes[$cod] = (string) $rb['d'];
                    }
                }
                mysqli_stmt_close($stmtBom);
                $bomConectada = true;
            } catch (Throwable $e) {
                // sem BOM: Fornecedor/Projeto ficam com "—"
            }
            mysqli_close($connMrp);
        }
    }

    if ($termos) {
        $registros = array_values(array_filter($registros, function ($r) use ($termos, $descricoes, $bomInfo) {
            $cod = trim((string) $r['codigo_componente']);
            $texto = mb_strtolower(implode(' | ', [
                $cod,
                (string) ($r['ncm'] ?? ''),
                $descricoes[$cod] ?? '',
                $bomInfo[$cod]['fornecedores'] ?? '',
                $bomInfo[$cod]['projetos'] ?? '',
            ]), 'UTF-8');
            foreach ($termos as $t) {
                if (str_contains($texto, $t)) {
                    return true;
                }
            }
            return false;
        }));
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Parâmetros | Controle de Importação</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/dashboard.css" rel="stylesheet">
    <style>
        #tabela-param td, #tabela-param th { padding: 10px 12px; font-size: .85rem; }
        #tabela-param td.num, #tabela-param th.num { text-align: right; }
        /* colunas com largura fixa: SAP e Description à esquerda, números todos do mesmo tamanho */
        #tabela-param { table-layout: fixed; width: 100%; min-width: 1350px; }
        #tabela-param td.txt-bom { white-space: normal; }
        #tabela-param th { text-align: left; }
        #tabela-param td, #tabela-param th { overflow: hidden; text-overflow: ellipsis; }
        #tabela-param th { white-space: normal; text-overflow: clip; }
        .celula-editavel { cursor: cell; }
        .celula-editavel:hover { background: #eef4fb; box-shadow: inset 0 0 0 1px #b9d3ef; }
        .celula-editavel input { width: 100%; text-align: right; }
        .celula-editavel.cel-texto input { text-align: left; }
        .btn-remover-linha { border: none; background: none; color: #c53535; font-size: 1.05rem; cursor: pointer; }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="container-fluid dashboard-container d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="eyebrow">Controle de Importação • Parâmetros</span>
                <h1>Parâmetros da Importação</h1>
                <p class="mb-0">Unit pack, preço, medidas e pesos por componente (Release) e siglas de fornecedor</p>
            </div>
            <nav class="d-flex flex-wrap gap-2" aria-label="Ações do sistema">
                <a class="btn btn-outline-light btn-sm" href="follow.php">Follow</a>
                <a class="btn btn-outline-light btn-sm" href="processos.php">Processos</a>
                <a class="btn btn-outline-light btn-sm" href="pagamento.php">Pagamento</a>
                <a class="btn btn-outline-light btn-sm" href="confirmar_entrega.php">Confirmar entrega</a>
                <a class="btn btn-outline-light btn-sm" href="documentos.php" title="Commercial Invoice + Packing List">📄 CI / PL</a>
                <a class="btn btn-outline-light btn-sm" href="release.php">🚚 Release</a>
                <a class="btn btn-light btn-sm" href="parametros_importacao.php">⚙️ Parâmetros</a>
            </nav>
        </div>
    </header>

    <main class="container-fluid dashboard-container py-4">
        <?php if (!$tabelaExiste): ?>
            <div class="alert alert-warning">A tabela <code>parametros_importacao</code> ainda não existe no banco <code>controle_importacao</code>. Rode o SQL de criação no DBeaver e recarregue a página.</div>
        <?php endif; ?>

        <?php if ($mensagens): ?>
            <div class="alert alert-info"><?php foreach ($mensagens as $m): ?><div><?php echo h($m); ?></div><?php endforeach; ?></div>
        <?php endif; ?>

        <section class="filter-panel mb-4">
            <details <?php echo in_array($_POST['acao'] ?? '', ['salvar_sigla', 'excluir_sigla'], true) ? 'open' : ''; ?>>
                <summary class="fw-bold" style="cursor:pointer;">🏷️ Siglas de fornecedor (código dos processos Release)</summary>
                <?php if (!$siglasExiste): ?>
                    <div class="alert alert-warning mt-2 mb-0">A tabela <code>siglas_fornecedor</code> ainda não existe. Rode o SQL de criação no DBeaver e recarregue.</div>
                <?php else: ?>
                    <p class="small text-muted mt-2 mb-2">Categoria <strong>Release</strong> gera o processo como <strong>Y + ano + modal + planta + SIGLA + sequencial</strong> (ex.: Y26S1KR01). O sequencial conta por fornecedor no ano. O nome aqui precisa bater com o campo Fornecedor de Processos (ou estar contido nele — "KURARAY CO., LTD." acha KURARAY).</p>
                    <form method="POST" class="row g-2 align-items-end mb-3">
                        <input type="hidden" name="acao" value="salvar_sigla">
                        <div class="col-md-4"><label class="form-label small mb-1">Fornecedor</label><input type="text" name="fornecedor_sigla" class="form-control form-control-sm" required></div>
                        <div class="col-md-1" style="min-width:90px"><label class="form-label small mb-1">Sigla</label><input type="text" name="sigla" maxlength="3" class="form-control form-control-sm text-uppercase" required></div>
                        <div class="col-auto"><button class="btn btn-primary btn-sm">Salvar</button></div>
                    </form>
                    <table class="table table-sm mb-0" style="max-width:520px">
                        <thead><tr><th>Fornecedor</th><th>Sigla</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($siglas as $sg): ?>
                                <tr>
                                    <td><?php echo h($sg['fornecedor']); ?></td>
                                    <td><strong><?php echo h($sg['sigla']); ?></strong></td>
                                    <td class="text-end">
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Excluir a sigla de <?php echo h($sg['fornecedor']); ?>?');">
                                            <input type="hidden" name="acao" value="excluir_sigla">
                                            <input type="hidden" name="fornecedor_sigla" value="<?php echo h($sg['fornecedor']); ?>">
                                            <button class="btn-remover-linha" title="Excluir">✕</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$siglas): ?><tr><td colspan="3" class="text-muted">Nenhuma sigla cadastrada.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                    <small class="text-muted d-block mt-1">Pra trocar a sigla de um fornecedor, salve de novo com o mesmo nome.</small>
                <?php endif; ?>
            </details>
        </section>

        <?php if ($tabelaExiste): ?>
        <section class="filter-panel mb-4">
            <details>
                <summary class="fw-bold" style="cursor:pointer;">➕ Cadastro manual</summary>
                <form method="POST" class="row g-2 align-items-end mt-2">
                    <input type="hidden" name="acao" value="manual">
                    <div class="col-md-2">
                        <label class="form-label small mb-1">Componente (SAP)</label>
                        <input type="text" name="codigo_componente" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-1" style="min-width:120px">
                        <label class="form-label small mb-1">NCM</label>
                        <input type="text" name="ncm" class="form-control form-control-sm" placeholder="3926.90.90">
                    </div>
                    <?php foreach (CAMPOS_PARAM_IMPORTACAO as $campo => $rotulo): ?>
                        <div class="col-md-1" style="min-width:120px">
                            <label class="form-label small mb-1"><?php echo h($rotulo); ?></label>
                            <input type="text" name="<?php echo $campo; ?>" class="form-control form-control-sm">
                        </div>
                    <?php endforeach; ?>
                    <div class="col-auto"><button type="submit" class="btn btn-primary btn-sm">Salvar</button></div>
                </form>
                <small class="text-muted d-block mt-2">Se o componente já existir, os valores são substituídos.</small>
            </details>
        </section>

        <section class="filter-panel mb-4">
            <details>
                <summary class="fw-bold" style="cursor:pointer;">📥 Importar CSV</summary>
                <form method="POST" enctype="multipart/form-data" class="row g-2 align-items-end mt-2"
                      onsubmit="return !this.limpar_tudo.checked || confirm('Limpar tudo: TODOS os parâmetros cadastrados serão apagados e substituídos pelo CSV. Continuar?');">
                    <input type="hidden" name="acao" value="importar_csv">
                    <div class="col-md-4"><input type="file" name="arquivo_csv" accept=".csv,text/csv" class="form-control form-control-sm" required></div>
                    <div class="col-auto">
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" name="limpar_tudo" value="1" id="limpar_tudo">
                            <label class="form-check-label small" for="limpar_tudo">🗑️ Limpar tudo e substituir pelo CSV</label>
                        </div>
                    </div>
                    <div class="col-auto"><button type="submit" class="btn btn-primary btn-sm">Importar</button></div>
                </form>
                <small class="text-muted d-block mt-2">Cabeçalho (separado por <code>;</code>): <code>componente; ncm; unit pack; u price; length; width; height; weight; g weight</code>. O NCM preenche sozinho o NCM em branco das linhas abertas de Processos. Sem marcar "Limpar tudo": componente que já existe é atualizado e os outros continuam. Marcando: a tabela fica só com o que está no CSV. Dá pra mandar só algumas colunas (ex.: componente; length; width; height).</small>
            </details>
        </section>

        <section class="filter-panel mb-4">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small mb-1">Buscar (SAP, descrição, fornecedor ou projeto — vários separados por ;)</label>
                    <input type="text" name="busca" value="<?php echo h($busca); ?>" class="form-control form-control-sm">
                </div>
                <div class="col-auto"><button class="btn btn-primary btn-sm">Filtrar</button></div>
                <div class="col-auto"><a class="btn btn-outline-secondary btn-sm" href="parametros_importacao.php">Limpar</a></div>
                <div class="col-auto"><a class="btn btn-success btn-sm" href="?exportar=csv">📊 Exportar CSV</a></div>
            </form>
        </section>

        <section class="table-card">
            <div class="table-toolbar">
                <div>
                    <span class="eyebrow text-primary">Resultado</span>
                    <h2>Componentes</h2>
                    <p><?php echo count($registros); ?> componente(s) — dê <strong>duplo clique</strong> num valor pra editar · Fornecedor e Projeto vêm da BOM do MRP</p>
                    <?php if ($registros && !$bomConectada): ?><p class="text-warning mb-0">Não consegui acessar a BOM do MRP agora — Fornecedor e Projeto ficaram em branco.</p><?php endif; ?>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table mrp-table mb-0" id="tabela-param">
                    <colgroup>
                        <col style="width:8%">
                        <col style="width:18%">
                        <col style="width:8%">
                        <col style="width:8%">
                        <col style="width:7%">
                        <?php foreach (CAMPOS_PARAM_IMPORTACAO as $rotulo): ?><col style="width:7%"><?php endforeach; ?>
                        <col style="width:3%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>SAP</th>
                            <th>Description</th>
                            <th title="Da BOM do MRP">Fornecedor</th>
                            <th title="Da BOM do MRP">Projeto</th>
                            <th title="Preenche sozinho o NCM em branco nas linhas abertas de Processos">NCM</th>
                            <?php foreach (CAMPOS_PARAM_IMPORTACAO as $rotulo): ?><th class="num"><?php echo h($rotulo); ?></th><?php endforeach; ?>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$registros): ?>
                            <tr><td colspan="<?php echo count(CAMPOS_PARAM_IMPORTACAO) + 6; ?>" class="empty-state">Nenhum componente cadastrado.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($registros as $r): ?>
                            <tr>
                                <td><span class="component-code"><?php echo h($r['codigo_componente']); ?></span></td>
                                <td><?php echo h($descricoes[trim($r['codigo_componente'])] ?? '—'); ?></td>
                                <?php $infoBom = $bomInfo[trim($r['codigo_componente'])] ?? ['fornecedores' => '', 'projetos' => '']; ?>
                                <td class="txt-bom" title="<?php echo h($infoBom['fornecedores']); ?>"><?php echo h($infoBom['fornecedores'] !== '' ? $infoBom['fornecedores'] : '—'); ?></td>
                                <td class="txt-bom" title="<?php echo h($infoBom['projetos']); ?>"><?php echo h($infoBom['projetos'] !== '' ? $infoBom['projetos'] : '—'); ?></td>
                                <td class="celula-editavel cel-texto" data-codigo="<?php echo h($r['codigo_componente']); ?>" data-campo="ncm" data-valor-bruto="<?php echo h($r['ncm'] ?? ''); ?>"><?php echo h(($r['ncm'] ?? '') !== '' ? $r['ncm'] : '—'); ?></td>
                                <?php foreach (array_keys(CAMPOS_PARAM_IMPORTACAO) as $campo): ?>
                                    <td class="num celula-editavel" data-codigo="<?php echo h($r['codigo_componente']); ?>" data-campo="<?php echo $campo; ?>" data-valor-bruto="<?php echo $r[$campo] === null ? '' : h(numeroBrParam($r[$campo], 6)); ?>"><?php echo numeroBrParam($r[$campo], 6); ?></td>
                                <?php endforeach; ?>
                                <td>
                                    <form method="POST" onsubmit="return confirm('Excluir os parâmetros de <?php echo h($r['codigo_componente']); ?>?');">
                                        <input type="hidden" name="acao" value="excluir">
                                        <input type="hidden" name="codigo" value="<?php echo h($r['codigo_componente']); ?>">
                                        <button class="btn-remover-linha" title="Excluir">✕</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>
    </main>

    <script>
        document.addEventListener('dblclick', function (e) {
            const celula = e.target.closest('#tabela-param td.celula-editavel');
            if (!celula || celula.querySelector('input')) return;
            const original = celula.textContent;
            const input = document.createElement('input');
            input.type = 'text';
            input.value = celula.dataset.valorBruto || '';
            celula.textContent = '';
            celula.appendChild(input);
            input.focus();
            input.select();
            let feito = false;
            function salvar() {
                if (feito) return;
                feito = true;
                const corpo = new URLSearchParams({ acao: 'ajax_editar', codigo: celula.dataset.codigo, campo: celula.dataset.campo, valor: input.value });
                fetch('parametros_importacao.php', { method: 'POST', body: corpo })
                    .then(r => r.json())
                    .then(d => {
                        if (!d.ok) { alert(d.erro || 'Erro ao salvar.'); celula.textContent = original; return; }
                        celula.textContent = d.exibido;
                        celula.dataset.valorBruto = d.bruto;
                    })
                    .catch(() => { alert('Erro de conexão ao salvar.'); celula.textContent = original; });
            }
            input.addEventListener('blur', salvar);
            input.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') { ev.preventDefault(); input.blur(); }
                else if (ev.key === 'Escape') { feito = true; celula.textContent = original; }
            });
        });
    </script>
</body>
</html>
