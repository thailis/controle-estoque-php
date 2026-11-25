<?php
// edi_historico.php — Histórico EDI
// Compara duas "fotos" do EDI (versões guardadas a cada importação da
// planilha semanal) por Modelo × Mês, com gráfico, detalhe por dia, lista dos
// ajustes manuais e exportação (CSV e PDF pela impressão do navegador).
require_once 'conexao.php';
require_once 'auth.php';
require_once __DIR__ . '/edi_historico_lib.php';
exigirLogin();

function hh(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function numBr(?float $n, int $dec = 0): string
{
    return $n === null ? '—' : number_format($n, $dec, ',', '.');
}

function mesBr(string $mes): string
{
    $nomes = ['01' => 'jan', '02' => 'fev', '03' => 'mar', '04' => 'abr', '05' => 'mai', '06' => 'jun',
              '07' => 'jul', '08' => 'ago', '09' => 'set', '10' => 'out', '11' => 'nov', '12' => 'dez'];
    [$a, $m] = array_pad(explode('-', $mes), 2, '');
    return ($nomes[$m] ?? $m) . '/' . substr($a, 2);
}

function dataBrHist(?string $d): string
{
    if (!$d) { return '—'; }
    $o = DateTimeImmutable::createFromFormat('!Y-m-d', substr($d, 0, 10));
    return $o ? $o->format('d/m/Y') : $d;
}

$disponivel = historicoEdiDisponivel($conn);

$versoes = [];
if ($disponivel) {
    $res = mysqli_query($conn, "SELECT id, geracao, arquivo, usuario, criado_em FROM edi_versoes ORDER BY id DESC");
    while ($res && ($l = mysqli_fetch_assoc($res))) {
        $versoes[(int) $l['id']] = $l;
    }
}

function rotuloVersao(array $v, string $momento): string
{
    $ger = $v['geracao'] ? dataBrHist($v['geracao']) : dataBrHist(substr((string) $v['criado_em'], 0, 10));
    $quando = $v['criado_em'] ? (new DateTimeImmutable($v['criado_em']))->format('d/m H:i') : '';
    return $momento === 'antes'
        ? "Antes da planilha $ger (EDI com ajustes)"
        : "Planilha $ger (importada $quando)";
}

function rotuloCurto(array $v, string $momento): string
{
    $ger = $v['geracao'] ? dataBrHist($v['geracao']) : dataBrHist(substr((string) $v['criado_em'], 0, 10));
    return $momento === 'antes' ? "Antes $ger" : $ger;
}

// Seleção das duas versões comparadas (padrão: antes × depois da última importação)
$ultimo = array_key_first($versoes);
$parseSel = function (string $valor) use ($versoes): ?array {
    if (!preg_match('/^(\d+):(antes|depois)$/', $valor, $m) || !isset($versoes[(int) $m[1]])) {
        return null;
    }
    return [(int) $m[1], $m[2]];
};
$selA = $parseSel((string) ($_GET['a'] ?? '')) ?? ($ultimo ? [$ultimo, 'antes'] : null);
$selB = $parseSel((string) ($_GET['b'] ?? '')) ?? ($ultimo ? [$ultimo, 'depois'] : null);

$busca = trim((string) ($_GET['busca'] ?? ''));
$projetoFiltro = trim((string) ($_GET['projeto'] ?? ''));
$soAlteracoes = !isset($_GET['filtrar']) || isset($_GET['so_alteracoes']); // padrão: só o que mudou

$mapaMat = $disponivel ? mapaMaterialModeloBom($conn) : [];

function agregadoVersao(mysqli $conn, array $sel): array
{
    [$vid, $mom] = $sel;
    $stmt = mysqli_prepare($conn, "
        SELECT material, DATE_FORMAT(data, '%Y-%m') AS mes, SUM(quantidade) AS q, MAX(projeto) AS projeto, MAX(modelo) AS modelo
        FROM edi_snapshot WHERE versao_id = ? AND momento = ? AND data IS NOT NULL
        GROUP BY material, DATE_FORMAT(data, '%Y-%m')
    ");
    mysqli_stmt_bind_param($stmt, 'is', $vid, $mom);
    mysqli_stmt_execute($stmt);
    $dados = [];
    $info = [];
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC) as $l) {
        $dados[$l['material']][$l['mes']] = (float) $l['q'];
        $info[$l['material']] = ['projeto' => $l['projeto'], 'modelo' => $l['modelo']];
    }
    mysqli_stmt_close($stmt);
    return [$dados, $info];
}

$linhas = [];
$resumo = ['subiu' => 0, 'caiu' => 0, 'igual' => 0, 'novo' => 0, 'delta' => 0.0];
$projetos = [];
if ($disponivel && $selA && $selB) {
    [$aggA, $infoA] = agregadoVersao($conn, $selA);
    [$aggB, $infoB] = agregadoVersao($conn, $selB);
    $termos = array_values(array_filter(array_map('trim', preg_split('/[;,\n]+/', $busca)), fn($t) => $t !== ''));

    // Materiais da versão B (os que vieram naquela planilha). Os que só existem
    // em A não foram mexidos em B — ficam de fora pra não parecer que zeraram.
    foreach ($aggB as $material => $mesesB) {
        $modelo = $mapaMat[$material]['modelo'] ?? ($infoB[$material]['modelo'] ?? '');
        $projeto = $infoB[$material]['projeto'] ?? ($mapaMat[$material]['projeto'] ?? '');
        if ($projeto !== '') { $projetos[$projeto] = true; }
        if ($projetoFiltro !== '' && $projeto !== $projetoFiltro) { continue; }
        if (!empty($termos)) {
            $achou = false;
            foreach ($termos as $t) {
                if (stripos($material, $t) !== false || stripos($modelo, $t) !== false || stripos($projeto, $t) !== false
                    || ($modelo !== '' && normalizarModeloEdi($modelo) === normalizarModeloEdi($t))) {
                    $achou = true; break;
                }
            }
            if (!$achou) { continue; }
        }
        $novo = !isset($aggA[$material]);
        $meses = array_unique(array_merge(array_keys($mesesB), array_keys($aggA[$material] ?? [])));
        sort($meses);
        foreach ($meses as $mes) {
            $qa = $novo ? null : ($aggA[$material][$mes] ?? 0.0);
            $qb = $mesesB[$mes] ?? 0.0;
            $delta = $qb - ($qa ?? 0.0);
            $mudou = $novo || abs($delta) > 0.0001;
            if ($novo) { $resumo['novo']++; } elseif ($delta > 0.0001) { $resumo['subiu']++; } elseif ($delta < -0.0001) { $resumo['caiu']++; } else { $resumo['igual']++; }
            $resumo['delta'] += $delta;
            if ($soAlteracoes && !$mudou) { continue; }
            $linhas[] = [
                'material' => $material, 'modelo' => $modelo, 'projeto' => $projeto, 'mes' => $mes,
                'qa' => $qa, 'qb' => $qb, 'delta' => $delta,
                'pct' => ($qa !== null && abs($qa) > 0.0001) ? $delta / $qa * 100 : null,
            ];
        }
    }
    usort($linhas, fn($x, $y) => [$x['modelo'], $x['material'], $x['mes']] <=> [$y['modelo'], $y['material'], $y['mes']]);
    ksort($projetos);
}

$rotA = $selA ? rotuloVersao($versoes[$selA[0]], $selA[1]) : '';
$rotB = $selB ? rotuloVersao($versoes[$selB[0]], $selB[1]) : '';

// Data em que aquela situação do EDI foi incluída, por material:
//  - versão "depois" (planilha importada) = dia da importação;
//  - versão "antes" = dia da importação anterior desse material; se ainda não
//    havia histórico, a data do nome do evento (ex.: "EDI 16/11/2026").
function dataInclusaoEdi(mysqli $conn, array $versoes, array $sel, string $material): string
{
    static $cache = [];
    [$vid, $mom] = $sel;
    $chave = "$vid|$mom|$material";
    if (isset($cache[$chave])) {
        return $cache[$chave];
    }
    $paraData = fn($dt) => $dt ? (new DateTimeImmutable($dt))->format('d/m/Y') : '';
    if ($mom === 'depois') {
        return $cache[$chave] = $paraData($versoes[$vid]['criado_em'] ?? null);
    }
    $stmt = mysqli_prepare($conn, "SELECT MAX(versao_id) AS v FROM edi_snapshot WHERE material = ? AND momento = 'depois' AND versao_id < ?");
    mysqli_stmt_bind_param($stmt, 'si', $material, $vid);
    mysqli_stmt_execute($stmt);
    $anterior = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['v'] ?? 0);
    mysqli_stmt_close($stmt);
    if ($anterior > 0 && isset($versoes[$anterior])) {
        return $cache[$chave] = $paraData($versoes[$anterior]['criado_em']);
    }
    $stmt = mysqli_prepare($conn, "SELECT DISTINCT evento FROM edi_snapshot WHERE versao_id = ? AND momento = 'antes' AND material = ?");
    mysqli_stmt_bind_param($stmt, 'is', $vid, $material);
    mysqli_stmt_execute($stmt);
    $maior = null;
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC) as $l) {
        if (preg_match('#(\d{2})/(\d{2})/(\d{4})#', (string) $l['evento'], $m)) {
            $iso = "{$m[3]}-{$m[2]}-{$m[1]}";
            if ($maior === null || $iso > $maior) { $maior = $iso; }
        }
    }
    mysqli_stmt_close($stmt);
    return $cache[$chave] = $maior ? dataBrHist($maior) : '';
}

// ---------- Exportar evolução (uma coluna por inclusão) ----------
// Cada coluna é uma data de inclusão: a situação inicial de cada modelo (antes
// da 1ª planilha guardada, com a data do evento antigo) e depois cada planilha
// importada. Uma linha por Modelo × Mês. Filtros de modelo/projeto da tela valem.
if (($_GET['exportar'] ?? '') === 'evolucao' && $disponivel) {
    $termosEv = array_values(array_filter(array_map('trim', preg_split('/[;,\n]+/', $busca)), fn($t) => $t !== ''));
    $res = mysqli_query($conn, "
        SELECT versao_id, momento, material, DATE_FORMAT(data, '%Y-%m') AS mes, SUM(quantidade) AS q, MAX(projeto) AS projeto, MAX(modelo) AS modelo
        FROM edi_snapshot WHERE data IS NOT NULL
        GROUP BY versao_id, momento, material, DATE_FORMAT(data, '%Y-%m')
        ORDER BY versao_id
    ");
    $snap = []; // material => versao => momento => mes => q
    $infoEv = [];
    while ($res && ($l = mysqli_fetch_assoc($res))) {
        $snap[$l['material']][(int) $l['versao_id']][$l['momento']][$l['mes']] = (float) $l['q'];
        $infoEv[$l['material']] = ['projeto' => $l['projeto'], 'modelo' => $l['modelo']];
    }

    $serie = [];   // material => dataIso => mes => q
    $datas = [];
    foreach ($snap as $material => $porVersao) {
        $modelo = $mapaMat[$material]['modelo'] ?? ($infoEv[$material]['modelo'] ?? '');
        $projeto = $infoEv[$material]['projeto'] ?? ($mapaMat[$material]['projeto'] ?? '');
        if ($projetoFiltro !== '' && $projeto !== $projetoFiltro) { continue; }
        if (!empty($termosEv)) {
            $achou = false;
            foreach ($termosEv as $t) {
                if (stripos($material, $t) !== false || stripos($modelo, $t) !== false || stripos($projeto, $t) !== false
                    || ($modelo !== '' && normalizarModeloEdi($modelo) === normalizarModeloEdi($t))) { $achou = true; break; }
            }
            if (!$achou) { continue; }
        }
        ksort($porVersao);
        $primeira = array_key_first($porVersao);
        // Situação inicial (antes da 1ª planilha guardada desse modelo)
        if (!empty($porVersao[$primeira]['antes'])) {
            $dataIni = dataInclusaoEdi($conn, $versoes, [$primeira, 'antes'], $material);
            $isoIni = $dataIni !== '' ? DateTimeImmutable::createFromFormat('!d/m/Y', $dataIni)->format('Y-m-d') : '0000-00-00';
            $serie[$material][$isoIni] = $porVersao[$primeira]['antes'];
            $datas[$isoIni] = true;
        }
        foreach ($porVersao as $vid => $momentos) {
            if (!isset($momentos['depois']) || !isset($versoes[$vid])) { continue; }
            $iso = substr((string) $versoes[$vid]['criado_em'], 0, 10);
            $serie[$material][$iso] = $momentos['depois']; // mais de uma no mesmo dia: vale a última
            $datas[$iso] = true;
        }
    }
    $datas = array_keys($datas);
    sort($datas);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="edi-evolucao-' . date('Y-m-d-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    $cab = ['Modelo', 'Material', 'Projeto', 'Mês'];
    foreach ($datas as $d) { $cab[] = $d === '0000-00-00' ? 'Inicial' : dataBrHist($d); }
    $cab[] = 'Δ última';
    fputcsv($out, $cab, ';', '"', '');

    $linhasEv = [];
    foreach ($serie as $material => $porData) {
        $meses = [];
        foreach ($porData as $m) { $meses += array_flip(array_keys($m)); }
        $meses = array_keys($meses);
        sort($meses);
        foreach ($meses as $mes) {
            $linha = [$mapaMat[$material]['modelo'] ?? ($infoEv[$material]['modelo'] ?? ''), $material,
                      $infoEv[$material]['projeto'] ?? ($mapaMat[$material]['projeto'] ?? ''), $mes];
            $valores = [];
            foreach ($datas as $d) {
                if (!isset($porData[$d])) { $linha[] = ''; continue; }   // modelo não veio nessa planilha
                $q = $porData[$d][$mes] ?? 0.0;
                $valores[] = $q;
                $linha[] = number_format($q, 0, ',', '');
            }
            $n = count($valores);
            $linha[] = $n >= 2 ? number_format($valores[$n - 1] - $valores[$n - 2], 0, ',', '') : '';
            $linhasEv[] = $linha;
        }
    }
    usort($linhasEv, fn($x, $y) => strcmp($x[0] . '|' . $x[1] . '|' . $x[3], $y[0] . '|' . $y[1] . '|' . $y[3]));
    foreach ($linhasEv as $linha) {
        $linha[3] = mesBr($linha[3]);
        fputcsv($out, $linha, ';', '"', '');
    }
    fclose($out);
    exit;
}

// ---------- Gráfico: um modelo, todas as planilhas importadas ----------
$materiaisTabela = array_values(array_unique(array_column($linhas, 'material')));
$graficoMaterial = trim((string) ($_GET['grafico'] ?? ''));
if ($graficoMaterial === '' && !empty($linhas)) {
    $maior = null;
    foreach ($linhas as $l) {
        if ($maior === null || abs($l['delta']) > abs($maior['delta'])) { $maior = $l; }
    }
    $graficoMaterial = $maior['material'];
}
$grafico = null;
if ($disponivel && $graficoMaterial !== '') {
    $stmt = mysqli_prepare($conn, "
        SELECT versao_id, DATE_FORMAT(data, '%Y-%m') AS mes, SUM(quantidade) AS q
        FROM edi_snapshot WHERE material = ? AND momento = 'depois' AND data IS NOT NULL
        GROUP BY versao_id, DATE_FORMAT(data, '%Y-%m')
    ");
    mysqli_stmt_bind_param($stmt, 's', $graficoMaterial);
    mysqli_stmt_execute($stmt);
    $porVersao = [];
    $mesesG = [];
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC) as $l) {
        $porVersao[(int) $l['versao_id']][$l['mes']] = (float) $l['q'];
        $mesesG[$l['mes']] = true;
    }
    mysqli_stmt_close($stmt);
    krsort($porVersao);
    $porVersao = array_slice($porVersao, 0, 8, true); // últimas 8 planilhas
    ksort($porVersao);
    $mesesG = array_keys($mesesG);
    sort($mesesG);
    $series = [];
    $ids = array_keys($porVersao);
    foreach ($porVersao as $vid => $meses) {
        $series[] = [
            'label' => rotuloCurto($versoes[$vid] ?? ['geracao' => null, 'criado_em' => null], 'depois'),
            'data' => array_map(fn($m) => $meses[$m] ?? 0, $mesesG),
            'ultima' => $vid === end($ids),
        ];
    }
    // Barras: Δ entre as duas versões comparadas, por mês
    $deltaMes = [];
    foreach ($linhas as $l) {
        if ($l['material'] === $graficoMaterial) { $deltaMes[$l['mes']] = $l['delta']; }
    }
    $grafico = [
        'meses' => array_map('mesBr', $mesesG),
        'series' => $series,
        'deltaMeses' => array_map('mesBr', array_keys($deltaMes)),
        'delta' => array_values($deltaMes),
        'titulo' => ($mapaMat[$graficoMaterial]['modelo'] ?? '') . ' · ' . $graficoMaterial,
    ];
}

// ---------- Detalhe (dia a dia) de um modelo num mês ----------
$detalhe = null;
$detMaterial = trim((string) ($_GET['det_material'] ?? ''));
$detMes = trim((string) ($_GET['det_mes'] ?? ''));
if ($disponivel && $selA && $selB && $detMaterial !== '' && preg_match('/^\d{4}-\d{2}$/', $detMes)) {
    $porDia = function (array $sel) use ($conn, $detMaterial, $detMes): array {
        [$vid, $mom] = $sel;
        $stmt = mysqli_prepare($conn, "
            SELECT data, SUM(quantidade) AS q, MAX(atendido) AS atendido FROM edi_snapshot
            WHERE versao_id = ? AND momento = ? AND material = ? AND DATE_FORMAT(data, '%Y-%m') = ?
            GROUP BY data
        ");
        mysqli_stmt_bind_param($stmt, 'isss', $vid, $mom, $detMaterial, $detMes);
        mysqli_stmt_execute($stmt);
        $r = [];
        foreach (mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC) as $l) {
            $r[$l['data']] = ['q' => (float) $l['q'], 'atendido' => (int) $l['atendido']];
        }
        mysqli_stmt_close($stmt);
        return $r;
    };
    $diaA = $porDia($selA);
    $diaB = $porDia($selB);
    $dias = array_unique(array_merge(array_keys($diaA), array_keys($diaB)));
    sort($dias);
    $stmt = mysqli_prepare($conn, "
        SELECT criado_em, usuario, acao, campo, data, evento, valor_anterior, valor_novo
        FROM edi_ajustes WHERE material = ? AND DATE_FORMAT(data, '%Y-%m') = ? ORDER BY criado_em
    ");
    mysqli_stmt_bind_param($stmt, 'ss', $detMaterial, $detMes);
    mysqli_stmt_execute($stmt);
    $ajustesDet = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    $detalhe = ['dias' => $dias, 'a' => $diaA, 'b' => $diaB, 'ajustes' => $ajustesDet];
}

// ---------- Ajustes manuais recentes ----------
$ajustes = [];
if ($disponivel) {
    $res = mysqli_query($conn, "SELECT criado_em, usuario, acao, campo, material, modelo, data, evento, valor_anterior, valor_novo FROM edi_ajustes ORDER BY id DESC LIMIT 300");
    while ($res && ($l = mysqli_fetch_assoc($res))) {
        $modeloAj = $mapaMat[$l['material']]['modelo'] ?? ($l['modelo'] ?? '');
        if ($busca !== '') {
            $achou = false;
            foreach (array_filter(array_map('trim', preg_split('/[;,\n]+/', $busca))) as $t) {
                if (stripos((string) $l['material'], $t) !== false || stripos($modeloAj, $t) !== false) { $achou = true; break; }
            }
            if (!$achou) { continue; }
        }
        $l['modelo_exib'] = $modeloAj;
        $ajustes[] = $l;
        if (count($ajustes) >= 100) { break; }
    }
}

$qsBase = ['a' => $selA ? $selA[0] . ':' . $selA[1] : '', 'b' => $selB ? $selB[0] . ':' . $selB[1] : '',
           'busca' => $busca, 'projeto' => $projetoFiltro, 'filtrar' => 1] + ($soAlteracoes ? ['so_alteracoes' => 1] : []);
$link = fn(array $extra) => 'edi_historico.php?' . http_build_query(array_merge($qsBase, $extra));

$valorQtd = function ($v) {
    if (is_numeric($v)) { return numBr((float) $v); }
    if ($v !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v)) { return dataBrHist((string) $v); }
    return $v === null || $v === '' ? '—' : (string) $v;
};
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>📈 Histórico EDI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; padding: 20px; }
        .card { border-radius: 15px; box-shadow: 0 2px 20px rgba(0,0,0,0.08); }
        .bg-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important; }
        .table th { background: #f8f9fa; white-space: nowrap; }
        .table th, .table td { text-align: center; vertical-align: middle; padding: 8px 12px; }
        .table td { white-space: nowrap; }
        .delta-mais { color: #1e7d4b !important; font-weight: 700; }
        .delta-menos { color: #c53535 !important; font-weight: 700; }
        .delta-zero { color: #8a96a3 !important; }
        tr.linha-clicavel { cursor: pointer; }
        tr.linha-clicavel:hover td { background: #f3f6ff; }
        tr.linha-ativa td { background: #eef2ff !important; }
        .resumo-box { border-radius: 12px; padding: 12px 16px; background: #fff; border: 1px solid #e5e9f0; }
        .resumo-box .n { font-size: 1.5rem; font-weight: 750; }
        .so-print { display: none; }
        @media print {
            /* No PDF os dois gráficos ficam um embaixo do outro, cada um na largura da folha */
            .col-grafico { flex: 0 0 100% !important; max-width: 100% !important; width: 100% !important; }
            .caixa-grafico { height: 240px !important; width: 100% !important; }
            .caixa-grafico canvas { max-width: 100% !important; }
            .card { break-inside: avoid; }
            body { background: #fff; padding: 0; }
            .no-print { display: none !important; }
            .so-print { display: block; }
            .card { box-shadow: none; border: 1px solid #ddd; }
            .table td, .table th { padding: 4px 6px; font-size: 11px; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
<div class="container-fluid" style="max-width: 98vw;">
    <nav class="d-flex flex-wrap gap-2 mb-3 no-print" aria-label="Navegação do sistema">
        <a class="btn btn-outline-secondary btn-sm" href="index.php">🏠 Dashboard</a>
        <a class="btn btn-outline-secondary btn-sm" href="estoque.php">Estoque</a>
        <a class="btn btn-outline-secondary btn-sm" href="edi.php">EDI</a>
        <a class="btn btn-secondary btn-sm" href="edi_historico.php">📈 Histórico EDI</a>
        <a class="btn btn-outline-secondary btn-sm" href="bomnova.php">BOM</a>
        <a class="btn btn-outline-secondary btn-sm" href="programacao.php">Programação</a>
        <a class="btn btn-outline-secondary btn-sm" href="planejamento_compras.php">Planejamento de compras</a>
    </nav>

    <div class="card bg-primary text-white p-4 mb-4">
        <h1 class="h3 mb-1">📈 Histórico EDI</h1>
        <p class="mb-0">Compara as planilhas semanais por Modelo × Mês (total do mês, independente do dia) e mostra os ajustes manuais feitos no EDI.</p>
        <p class="mb-0 so-print"><strong>Comparação:</strong> <?php echo hh($rotA); ?> × <?php echo hh($rotB); ?> — gerado em <?php echo date('d/m/Y H:i'); ?></p>
    </div>

    <?php if (!$disponivel): ?>
        <div class="alert alert-warning">O Histórico EDI ainda não foi ativado: rode no banco o SQL das tabelas <code>edi_versoes</code>, <code>edi_snapshot</code> e <code>edi_ajustes</code>.</div>
    <?php elseif (empty($versoes)): ?>
        <div class="alert alert-info">Ainda não há versões guardadas. A primeira aparece aqui na próxima vez que você importar a planilha na janela <a href="edi.php">EDI</a>.</div>
    <?php else: ?>

    <div class="card p-3 mb-4 no-print">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="filtrar" value="1">
            <div class="col-12 col-md-3">
                <label class="form-label small mb-1">Comparar (anterior)</label>
                <select name="a" class="form-select form-select-sm">
                    <?php foreach ($versoes as $vid => $v): foreach (['depois', 'antes'] as $mom): $val = "$vid:$mom"; ?>
                        <option value="<?php echo $val; ?>" <?php echo $selA && $selA === [$vid, $mom] ? 'selected' : ''; ?>><?php echo hh(rotuloVersao($v, $mom)); ?></option>
                    <?php endforeach; endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small mb-1">com (nova)</label>
                <select name="b" class="form-select form-select-sm">
                    <?php foreach ($versoes as $vid => $v): foreach (['depois', 'antes'] as $mom): $val = "$vid:$mom"; ?>
                        <option value="<?php echo $val; ?>" <?php echo $selB && $selB === [$vid, $mom] ? 'selected' : ''; ?>><?php echo hh(rotuloVersao($v, $mom)); ?></option>
                    <?php endforeach; endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label small mb-1">Modelo / material (vários: ;)</label>
                <input type="text" name="busca" class="form-control form-control-sm" value="<?php echo hh($busca); ?>">
            </div>
            <div class="col-6 col-md-1">
                <label class="form-label small mb-1">Projeto</label>
                <select name="projeto" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <?php foreach (array_keys($projetos) as $p): ?>
                        <option value="<?php echo hh($p); ?>" <?php echo $p === $projetoFiltro ? 'selected' : ''; ?>><?php echo hh($p); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-1">
                <div class="form-check mb-1">
                    <input class="form-check-input" type="checkbox" name="so_alteracoes" id="so_alteracoes" <?php echo $soAlteracoes ? 'checked' : ''; ?>>
                    <label class="form-check-label small" for="so_alteracoes">Só o que mudou</label>
                </div>
            </div>
            <div class="col-12 col-md-2 d-flex gap-1 flex-wrap">
                <button class="btn btn-primary btn-sm">Comparar</button>
                <a class="btn btn-outline-primary btn-sm" href="<?php echo hh($link(['exportar' => 'evolucao'])); ?>" title="Uma coluna por inclusão (planilha importada), por Modelo × Mês">⬇️ Exportar evolução</a>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">🖨️ PDF</button>
            </div>
        </form>
        <small class="text-muted mt-2">"Antes da planilha" = como o EDI estava logo antes daquela importação, já com os ajustes manuais. O padrão compara isso com a planilha nova (o que eu tinha × o que chegou).</small>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-2"><div class="resumo-box"><div class="small text-muted">Subiram</div><div class="n delta-mais"><?php echo $resumo['subiu']; ?></div></div></div>
        <div class="col-6 col-md-2"><div class="resumo-box"><div class="small text-muted">Caíram</div><div class="n delta-menos"><?php echo $resumo['caiu']; ?></div></div></div>
        <div class="col-6 col-md-2"><div class="resumo-box"><div class="small text-muted">Iguais</div><div class="n delta-zero"><?php echo $resumo['igual']; ?></div></div></div>
        <div class="col-6 col-md-2"><div class="resumo-box"><div class="small text-muted">Novos (não estavam antes)</div><div class="n"><?php echo $resumo['novo']; ?></div></div></div>
        <div class="col-12 col-md-4"><div class="resumo-box"><div class="small text-muted">Δ total (modelo × mês)</div>
            <div class="n <?php echo $resumo['delta'] > 0 ? 'delta-mais' : ($resumo['delta'] < 0 ? 'delta-menos' : 'delta-zero'); ?>"><?php echo ($resumo['delta'] > 0 ? '+' : '') . numBr($resumo['delta']); ?></div></div></div>
    </div>

    <?php if ($grafico): ?>
    <div class="card p-3 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <h2 class="h6 m-0">Gráfico — <?php echo hh($grafico['titulo']); ?></h2>
            <form method="GET" class="d-flex gap-2 no-print">
                <?php foreach ($qsBase as $k => $v): ?><input type="hidden" name="<?php echo hh($k); ?>" value="<?php echo hh($v); ?>"><?php endforeach; ?>
                <select name="grafico" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ($materiaisTabela as $m): ?>
                        <option value="<?php echo hh($m); ?>" <?php echo $m === $graficoMaterial ? 'selected' : ''; ?>><?php echo hh(($mapaMat[$m]['modelo'] ?? '') . ' · ' . $m); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <div class="row g-3">
            <div class="col-12 col-lg-8 col-grafico"><div class="caixa-grafico" style="height:300px"><canvas id="grafico-linhas"></canvas></div>
                <small class="text-muted">Uma linha por planilha importada (últimas 8) — a mais recente em destaque.</small></div>
            <div class="col-12 col-lg-4 col-grafico"><div class="caixa-grafico" style="height:300px"><canvas id="grafico-delta"></canvas></div>
                <small class="text-muted">Δ por mês entre as duas versões comparadas.</small></div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card p-3 mb-4">
        <h2 class="h6 mb-2">Alterações por Modelo × Mês <small class="text-muted no-print">— clique numa linha para ver o dia a dia</small></h2>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr>
                    <th>Modelo</th><th>Material</th><th>Projeto</th><th>Mês</th>
                    <th title="<?php echo hh($rotA); ?>">Anterior</th><th title="<?php echo hh($rotB); ?>">Nova</th><th>Δ</th><th>Δ %</th>
                </tr></thead>
                <tbody>
                <?php if (empty($linhas)): ?>
                    <tr><td colspan="8" class="text-muted">Nenhuma alteração entre as duas versões escolhidas.</td></tr>
                <?php else: foreach ($linhas as $l):
                    $classe = $l['qa'] === null ? '' : ($l['delta'] > 0.0001 ? 'delta-mais' : ($l['delta'] < -0.0001 ? 'delta-menos' : 'delta-zero'));
                    $ativa = $detalhe && $detMaterial === $l['material'] && $detMes === $l['mes'];
                ?>
                    <tr class="linha-clicavel <?php echo $ativa ? 'linha-ativa' : ''; ?>" onclick="location.href='<?php echo hh($link(['det_material' => $l['material'], 'det_mes' => $l['mes'], 'grafico' => $l['material']])); ?>#detalhe'">
                        <td><?php echo hh($l['modelo'] ?: '—'); ?></td>
                        <td><strong><?php echo hh($l['material']); ?></strong></td>
                        <td><?php echo hh($l['projeto'] ?: '—'); ?></td>
                        <td><?php echo hh(mesBr($l['mes'])); ?></td>
                        <td><?php echo $l['qa'] === null ? '<span class="text-muted">novo</span>' : numBr($l['qa']); ?></td>
                        <td><?php echo numBr($l['qb']); ?></td>
                        <td class="<?php echo $classe; ?>"><?php echo $l['qa'] === null ? '—' : (($l['delta'] > 0 ? '+' : '') . numBr($l['delta'])); ?></td>
                        <td class="<?php echo $classe; ?>"><?php echo $l['pct'] === null ? '—' : (($l['pct'] > 0 ? '+' : '') . numBr($l['pct'], 1) . '%'); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($detalhe): ?>
    <div class="card p-3 mb-4" id="detalhe">
        <h2 class="h6 mb-2">Dia a dia — <?php echo hh(($mapaMat[$detMaterial]['modelo'] ?? '') . ' · ' . $detMaterial . ' · ' . mesBr($detMes)); ?></h2>
        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Data</th><th title="<?php echo hh($rotA); ?>">Anterior</th><th title="<?php echo hh($rotB); ?>">Nova</th><th>Δ</th></tr></thead>
                    <tbody>
                    <?php foreach ($detalhe['dias'] as $d):
                        $qa = $detalhe['a'][$d]['q'] ?? 0.0; $qb = $detalhe['b'][$d]['q'] ?? 0.0; $dl = $qb - $qa;
                        $at = !empty($detalhe['b'][$d]['atendido']) || !empty($detalhe['a'][$d]['atendido']); ?>
                        <tr>
                            <td><?php echo dataBrHist($d); ?><?php echo $at ? ' <span class="badge bg-success-subtle text-success">Atendido</span>' : ''; ?></td>
                            <td><?php echo isset($detalhe['a'][$d]) ? numBr($qa) : '—'; ?></td>
                            <td><?php echo isset($detalhe['b'][$d]) ? numBr($qb) : '—'; ?></td>
                            <td class="<?php echo $dl > 0.0001 ? 'delta-mais' : ($dl < -0.0001 ? 'delta-menos' : 'delta-zero'); ?>"><?php echo ($dl > 0 ? '+' : '') . numBr($dl); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="col-12 col-lg-6">
                <h3 class="h6">Ajustes manuais neste mês</h3>
                <?php if (empty($detalhe['ajustes'])): ?>
                    <p class="text-muted small mb-0">Nenhum ajuste manual registrado.</p>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead><tr><th>Quando</th><th>Quem</th><th>O quê</th><th>Data EDI</th><th>De → Para</th></tr></thead>
                    <tbody>
                    <?php foreach ($detalhe['ajustes'] as $aj): ?>
                        <tr>
                            <td><?php echo hh((new DateTimeImmutable($aj['criado_em']))->format('d/m H:i')); ?></td>
                            <td><?php echo hh($aj['usuario'] ?: '—'); ?></td>
                            <td><?php echo hh(ucfirst($aj['acao']) . ' · ' . $aj['campo']); ?></td>
                            <td><?php echo dataBrHist($aj['data']); ?></td>
                            <td><?php echo hh($valorQtd($aj['valor_anterior'])); ?> → <?php echo hh($valorQtd($aj['valor_novo'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card p-3 mb-4">
        <h2 class="h6 mb-2">Ajustes manuais recentes no EDI <small class="text-muted">(duplo clique, edição, inclusão, exclusão e ações em massa — últimos 100)</small></h2>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Quando</th><th>Quem</th><th>Modelo</th><th>Material</th><th>Evento</th><th>Data EDI</th><th>O quê</th><th>De → Para</th></tr></thead>
                <tbody>
                <?php if (empty($ajustes)): ?>
                    <tr><td colspan="8" class="text-muted">Nenhum ajuste manual registrado ainda.</td></tr>
                <?php else: foreach ($ajustes as $aj): ?>
                    <tr>
                        <td><?php echo hh((new DateTimeImmutable($aj['criado_em']))->format('d/m/Y H:i')); ?></td>
                        <td><?php echo hh($aj['usuario'] ?: '—'); ?></td>
                        <td><?php echo hh($aj['modelo_exib'] ?: '—'); ?></td>
                        <td><?php echo hh($aj['material']); ?></td>
                        <td><?php echo hh($aj['evento'] ?: '—'); ?></td>
                        <td><?php echo dataBrHist($aj['data']); ?></td>
                        <td><?php echo hh(ucfirst($aj['acao']) . ' · ' . $aj['campo']); ?></td>
                        <td><?php echo hh($valorQtd($aj['valor_anterior'])); ?> → <?php echo hh($valorQtd($aj['valor_novo'])); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card p-3 mb-4 no-print">
        <h2 class="h6 mb-2">Planilhas importadas</h2>
        <table class="table table-sm mb-0">
            <thead><tr><th>Geração</th><th>Importada em</th><th>Arquivo</th><th>Quem</th></tr></thead>
            <tbody>
            <?php foreach ($versoes as $v): ?>
                <tr>
                    <td><?php echo dataBrHist($v['geracao']); ?></td>
                    <td><?php echo hh($v['criado_em'] ? (new DateTimeImmutable($v['criado_em']))->format('d/m/Y H:i') : '—'); ?></td>
                    <td><?php echo hh($v['arquivo'] ?: '—'); ?></td>
                    <td><?php echo hh($v['usuario'] ?: '—'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($grafico): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;
    const g = <?php echo json_encode($grafico, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const fmt = (v) => Number(v).toLocaleString('pt-BR');
    const total = g.series.length;
    const graficos = [];
    graficos.push(new Chart(document.getElementById('grafico-linhas'), {
        type: 'line',
        data: {
            labels: g.meses,
            datasets: g.series.map((s, i) => ({
                label: s.label,
                data: s.data,
                borderColor: s.ultima ? '#5b4bd6' : 'rgba(120,130,150,' + (0.25 + 0.5 * (i + 1) / total) + ')',
                backgroundColor: s.ultima ? '#5b4bd6' : 'rgba(120,130,150,0.4)',
                borderWidth: s.ultima ? 3 : 1.5,
                pointRadius: s.ultima ? 3 : 2,
                tension: 0.2,
            })),
        },
        options: {
            maintainAspectRatio: false, animation: false,
            plugins: { tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + fmt(c.parsed.y) } } },
            scales: { y: { beginAtZero: true, ticks: { callback: fmt } } },
        },
    }));
    graficos.push(new Chart(document.getElementById('grafico-delta'), {
        type: 'bar',
        data: {
            labels: g.deltaMeses,
            datasets: [{ label: 'Δ', data: g.delta, backgroundColor: g.delta.map((v) => v >= 0 ? '#2e9e63' : '#d64545') }],
        },
        options: {
            maintainAspectRatio: false, animation: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => (c.parsed.y > 0 ? '+' : '') + fmt(c.parsed.y) } } },
            scales: { y: { ticks: { callback: fmt } } },
        },
    }));

    // PDF: redimensiona os gráficos pro tamanho da folha antes de imprimir
    // (senão eles ficam com a largura da tela e um passa por cima do outro)
    const paraImpressao = () => graficos.forEach((c) => c.resize(680, 240));
    const paraTela = () => graficos.forEach((c) => c.resize());
    window.addEventListener('beforeprint', paraImpressao);
    window.addEventListener('afterprint', paraTela);
    if (window.matchMedia) {
        window.matchMedia('print').addEventListener('change', (e) => (e.matches ? paraImpressao() : paraTela()));
    }
})();
</script>
<?php endif; ?>
</body>
</html>
