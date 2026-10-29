<?php
// importacao/parametros_importacao.php
// Parâmetros por COMPONENTE (independente de processo/projeto) usados na tela
// Release: unit pack, U PRICE, medidas da embalagem (LENGTH/WIDTH/HEIGHT em cm),
// peso unitário (weight of component, kg) e G WEIGHT.
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

// Campos numéricos editáveis => rótulo
const CAMPOS_PARAM_IMPORTACAO = [
    'unit_pack'     => 'Unit pack',
    'u_price'       => 'U PRICE',
    'length_cm'     => 'LENGTH',
    'width_cm'      => 'WIDTH',
    'height_cm'     => 'HEIGHT',
    'peso_unitario' => 'Weight of component (kg)',
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

// ---------- AJAX: edição inline (duplo clique) ----------
if ($tabelaExiste && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'ajax_editar') {
    header('Content-Type: application/json; charset=utf-8');
    $codigo = trim((string) ($_POST['codigo'] ?? ''));
    $campo = (string) ($_POST['campo'] ?? '');
    $valorTxt = trim((string) ($_POST['valor'] ?? ''));
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
            'peso_unitario' => ['weight', 'weight of component', 'weight of component (kg)', 'weight of component（kg）', 'peso_unitario', 'peso'],
            'g_weight' => ['g weight', 'g_weight', 'gross weight'],
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
    fputcsv($saida, ['componente', 'unit pack', 'u price', 'length', 'width', 'height', 'weight', 'g weight'], ';', '"', '');
    $res = mysqli_query($conn, 'SELECT * FROM parametros_importacao ORDER BY codigo_componente');
    while ($r = mysqli_fetch_assoc($res)) {
        $linha = [$r['codigo_componente']];
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
    $where = '';
    $params = [];
    $termos = array_values(array_filter(array_map('trim', explode(';', $busca)), fn($t) => $t !== ''));
    if ($termos) {
        $where = 'WHERE ' . implode(' OR ', array_fill(0, count($termos), 'LOWER(codigo_componente) LIKE ?'));
        $params = array_map(fn($t) => '%' . mb_strtolower($t, 'UTF-8') . '%', $termos);
    }
    $stmt = mysqli_prepare($conn, "SELECT * FROM parametros_importacao $where ORDER BY codigo_componente");
    if ($params) {
        mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
    }
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
        #tabela-param td.num { text-align: right; }
        .celula-editavel { cursor: cell; }
        .celula-editavel:hover { background: #eef4fb; box-shadow: inset 0 0 0 1px #b9d3ef; }
        .celula-editavel input { width: 100%; text-align: right; }
        .btn-remover-linha { border: none; background: none; color: #c53535; font-size: 1.05rem; cursor: pointer; }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="container-fluid dashboard-container d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="eyebrow">Controle de Importação • Parâmetros</span>
                <h1>Parâmetros da Importação</h1>
                <p class="mb-0">Unit pack, preço, medidas e pesos por componente — usados na Release</p>
            </div>
            <nav class="d-flex flex-wrap gap-2" aria-label="Ações do sistema">
                <a class="btn btn-outline-light btn-sm" href="follow.php">Follow</a>
                <a class="btn btn-outline-light btn-sm" href="processos.php">Processos</a>
                <a class="btn btn-outline-light btn-sm" href="pagamento.php">Pagamento</a>
                <a class="btn btn-outline-light btn-sm" href="confirmar_entrega.php">Confirmar entrega</a>
                <a class="btn btn-outline-light btn-sm" href="commercial_invoice.php">📄 Commercial Invoice</a>
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
                <small class="text-muted d-block mt-2">Cabeçalho (separado por <code>;</code>): <code>componente; unit pack; u price; length; width; height; weight; g weight</code>. Sem marcar "Limpar tudo": componente que já existe é atualizado e os outros continuam. Marcando: a tabela fica só com o que está no CSV. Dá pra mandar só algumas colunas (ex.: componente; length; width; height).</small>
            </details>
        </section>

        <section class="filter-panel mb-4">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small mb-1">Buscar componente (vários separados por ;)</label>
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
                    <p><?php echo count($registros); ?> componente(s) — dê <strong>duplo clique</strong> num valor pra editar</p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table mrp-table mb-0" id="tabela-param">
                    <thead>
                        <tr>
                            <th>SAP</th>
                            <th>Description</th>
                            <?php foreach (CAMPOS_PARAM_IMPORTACAO as $rotulo): ?><th><?php echo h($rotulo); ?></th><?php endforeach; ?>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$registros): ?>
                            <tr><td colspan="<?php echo count(CAMPOS_PARAM_IMPORTACAO) + 3; ?>" class="empty-state">Nenhum componente cadastrado.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($registros as $r): ?>
                            <tr>
                                <td><span class="component-code"><?php echo h($r['codigo_componente']); ?></span></td>
                                <td><?php echo h($descricoes[trim($r['codigo_componente'])] ?? '—'); ?></td>
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
