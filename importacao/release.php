<?php
// importacao/release.php
// Release de embarque no MESMO layout da planilha "PO between YAPP HQ and YAPP
// Subsidiaries" (aba de projeto, ex.: VW427): um bloco "Spot" por embarque
// (FORECAST / PACK / CBM / PRICE) lado a lado e, à direita, o quadro
// U PRICE / LENGTH / WIDTH / HEIGHT / volume / weight / N WEIGHT / G WEIGHT.
//
// Escolhe o Projeto e marca os Processos que entram — cada Processo vira um
// Spot (ordenados pela ETA YAPP). Assim, embarques com datas de recebimento
// diferentes saem como Spot 1, Spot 2, Spot 3...
//   SAP / Description / FORECAST (qtd) / Planta / Modal → Processos
//   ETD / ETA / ETA YAPP (Prevista)                    → Follow
//   PICK UP                                            → ETD − 20 dias
//   unit pack, U PRICE, L/W/H, weight, G WEIGHT        → Parâmetros da Importação
//   PACK, CBM, PRICE, volume, N WEIGHT                 → fórmulas da planilha
// Nada é salvo no banco por aqui — tudo pode ser ajustado na tela antes de exportar.
require_once 'conexao.php';
require_once __DIR__ . '/auth_importacao.php'; // exige login (o mesmo do MRP)

const PICKUP_DIAS_ANTES_ETD = 20;

function h(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function dataBrRelease(?string $data): string
{
    if ($data === null || $data === '') {
        return '';
    }
    $obj = DateTimeImmutable::createFromFormat('!Y-m-d', substr($data, 0, 10));
    return $obj ? $obj->format('d/m/Y') : '';
}

function numJs($valor): string
{
    // número do banco → texto BR sem zeros à toa (ex.: 0.7000 → "0,7")
    if ($valor === null || $valor === '') {
        return '';
    }
    $txt = number_format((float) $valor, 6, ',', '');
    return rtrim(rtrim($txt, '0'), ',');
}

function modalPlanilha(string $modal): string
{
    $m = mb_strtolower(trim($modal), 'UTF-8');
    if (in_array($m, ['sea', 'maritimo', 'marítimo'], true)) { return 'SEA SHIP'; }
    if (in_array($m, ['air', 'aereo', 'aéreo'], true)) { return 'AIR SHIP'; }
    if (in_array($m, ['road', 'rodoviario', 'rodoviário'], true)) { return 'ROAD'; }
    if (in_array($m, ['courrier', 'courier'], true)) { return 'COURIER'; }
    return mb_strtoupper(trim($modal), 'UTF-8');
}

function cidadePlanta(string $planta): string
{
    // Planta terminando em 1 = Gravataí; as demais = Americana (mesma regra do Importer)
    $planta = trim($planta);
    if ($planta === '') { return ''; }
    return str_ends_with($planta, '1') ? 'GRAVATAÍ' : 'AMERICANA';
}

// Conexão (só leitura) com o banco do MRP, pra puxar os itens da BOM do projeto.
// Usa as mesmas credenciais MRP_DB_* da Confirmar entrega / Commercial Invoice.
function conectarMrpRelease(): ?mysqli
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

// ---------- AJAX: monta os Spots e os itens ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'buscar') {
    header('Content-Type: application/json; charset=utf-8');
    // Vários projetos (ex.: VW427 Diesel + VW427 PHEV) — chegam como lista JSON
    $projetos = json_decode((string) ($_GET['projetos'] ?? '[]'), true);
    $projetos = is_array($projetos) ? array_values(array_unique(array_map(fn($p) => trim((string) $p), $projetos))) : [];
    $processos = array_values(array_filter(array_map('trim', explode(',', (string) ($_GET['processos'] ?? ''))), fn($p) => $p !== ''));
    if (!$processos || !$projetos) {
        echo json_encode(['spots' => [], 'itens' => []]);
        exit;
    }
    $ph = implode(',', array_fill(0, count($processos), '?'));

    $sqlBase = "
        SELECT TRIM(p.processo) AS processo, p.codigo_componente, p.descricao, p.quantidade, p.po, p.planta, p.modal, p.status %s
        FROM processos p %s
        WHERE TRIM(p.processo) IN ($ph)
          AND COALESCE(TRIM(p.projeto), '') IN (" . implode(',', array_fill(0, count($projetos), '?')) . ")
          AND LOWER(TRIM(COALESCE(p.status, ''))) <> 'cancelado'
        ORDER BY p.id
    ";
    $comParametros = true;
    try {
        $stmt = mysqli_prepare($conn, sprintf($sqlBase,
            ', pi.unit_pack, pi.u_price, pi.length_cm, pi.width_cm, pi.height_cm, pi.peso_unitario, pi.g_weight',
            'LEFT JOIN parametros_importacao pi ON TRIM(pi.codigo_componente) = TRIM(p.codigo_componente)'));
    } catch (Throwable $e) {
        $comParametros = false; // tabela de parâmetros ainda não criada — segue sem ela
        $stmt = mysqli_prepare($conn, sprintf($sqlBase, '', ''));
    }
    $params = array_merge($processos, $projetos);
    mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $spots = [];
    $itens = [];
    while ($l = mysqli_fetch_assoc($res)) {
        $proc = (string) $l['processo'];
        if (!isset($spots[$proc])) {
            $spots[$proc] = ['processo' => $proc, 'pos' => [], 'planta' => '', 'modal' => '', 'finalizado' => false];
        }
        if (trim((string) $l['po']) !== '') { $spots[$proc]['pos'][trim($l['po'])] = true; }
        if ($spots[$proc]['planta'] === '' && trim((string) $l['planta']) !== '') { $spots[$proc]['planta'] = trim($l['planta']); }
        if ($spots[$proc]['modal'] === '' && trim((string) $l['modal']) !== '') { $spots[$proc]['modal'] = trim($l['modal']); }
        if (strtolower(trim((string) $l['status'])) === 'finalizado') { $spots[$proc]['finalizado'] = true; }

        $sap = trim((string) $l['codigo_componente']);
        if (!isset($itens[$sap])) {
            $itens[$sap] = [
                'sap' => $sap,
                'desc' => (string) ($l['descricao'] ?? ''),
                'unit_pack' => numJs($l['unit_pack'] ?? null),
                'u_price' => numJs($l['u_price'] ?? null),
                'length' => numJs($l['length_cm'] ?? null),
                'width' => numJs($l['width_cm'] ?? null),
                'height' => numJs($l['height_cm'] ?? null),
                'peso' => numJs($l['peso_unitario'] ?? null),
                'g_weight' => numJs($l['g_weight'] ?? null),
                'qtd' => [],
            ];
        } elseif ($itens[$sap]['desc'] === '' && trim((string) $l['descricao']) !== '') {
            $itens[$sap]['desc'] = (string) $l['descricao'];
        }
        $itens[$sap]['qtd'][$proc] = ($itens[$sap]['qtd'][$proc] ?? 0) + (float) $l['quantidade'];
    }
    mysqli_stmt_close($stmt);

    // Todos os itens da BOM do projeto (MRP ≠ N): quem não está em nenhum dos
    // processos escolhidos entra com FORECAST 0. Ordem = ordem da BOM; itens que
    // estão no processo mas não na BOM do projeto vão no final.
    $bomStatus = 'desligado';
    $projetosBom = array_values(array_filter($projetos, fn($p) => $p !== ''));
    if (($_GET['bom'] ?? '1') === '1' && $projetosBom) {
        $bomStatus = 'sem_conexao';
        $connMrp = conectarMrpRelease();
        if ($connMrp) {
            $sqlBom = "
                SELECT TRIM(codigo_componente) AS sap,
                       COALESCE(MAX(CASE WHEN mrp IS NULL OR UPPER(TRIM(mrp)) <> 'N' THEN NULLIF(TRIM(descricao), '') END), MAX(NULLIF(TRIM(descricao), ''))) AS descricao
                FROM bomnova
                WHERE LOWER(TRIM(projeto)) IN (" . implode(',', array_fill(0, count($projetosBom), 'LOWER(?)')) . ")
                  AND codigo_componente IS NOT NULL AND TRIM(codigo_componente) <> ''
                  AND (mrp IS NULL OR UPPER(TRIM(mrp)) <> 'N')
                GROUP BY TRIM(codigo_componente)
                ORDER BY %s
            ";
            try {
                $stmtBom = mysqli_prepare($connMrp, sprintf($sqlBom, 'MIN(id)'));
            } catch (Throwable $e) {
                $stmtBom = mysqli_prepare($connMrp, sprintf($sqlBom, 'sap'));
            }
            mysqli_stmt_bind_param($stmtBom, str_repeat('s', count($projetosBom)), ...$projetosBom);
            mysqli_stmt_execute($stmtBom);
            $resBom = mysqli_stmt_get_result($stmtBom);
            $itensBom = [];
            while ($b = mysqli_fetch_assoc($resBom)) {
                $sapBom = (string) $b['sap'];
                $itensBom[$sapBom] = $itens[$sapBom] ?? [
                    'sap' => $sapBom, 'desc' => '', 'unit_pack' => '', 'u_price' => '', 'length' => '', 'width' => '',
                    'height' => '', 'peso' => '', 'g_weight' => '', 'qtd' => [], 'so_bom' => true,
                ];
                if ($itensBom[$sapBom]['desc'] === '') {
                    $itensBom[$sapBom]['desc'] = (string) ($b['descricao'] ?? '');
                }
            }
            mysqli_stmt_close($stmtBom);
            mysqli_close($connMrp);
            $bomStatus = $itensBom ? 'ok' : 'projeto_nao_achado';
            // BOM primeiro, depois o que só existe nos processos
            $itens = $itensBom + $itens;

            // Parâmetros dos itens que vieram só da BOM
            $semParam = array_keys(array_filter($itens, fn($it) => !empty($it['so_bom'])));
            if ($comParametros && $semParam) {
                $phP = implode(',', array_fill(0, count($semParam), '?'));
                $stmtP = mysqli_prepare($conn, "SELECT TRIM(codigo_componente) AS sap, unit_pack, u_price, length_cm, width_cm, height_cm, peso_unitario, g_weight FROM parametros_importacao WHERE TRIM(codigo_componente) IN ($phP)");
                mysqli_stmt_bind_param($stmtP, str_repeat('s', count($semParam)), ...$semParam);
                mysqli_stmt_execute($stmtP);
                $resP = mysqli_stmt_get_result($stmtP);
                while ($pr = mysqli_fetch_assoc($resP)) {
                    $sp = (string) $pr['sap'];
                    if (!isset($itens[$sp])) { continue; }
                    $itens[$sp]['unit_pack'] = numJs($pr['unit_pack']);
                    $itens[$sp]['u_price'] = numJs($pr['u_price']);
                    $itens[$sp]['length'] = numJs($pr['length_cm']);
                    $itens[$sp]['width'] = numJs($pr['width_cm']);
                    $itens[$sp]['height'] = numJs($pr['height_cm']);
                    $itens[$sp]['peso'] = numJs($pr['peso_unitario']);
                    $itens[$sp]['g_weight'] = numJs($pr['g_weight']);
                }
                mysqli_stmt_close($stmtP);
            }
        }
    }

    // Datas do Follow (registro mais recente de cada processo)
    foreach ($spots as $proc => &$spot) {
        $follow = null;
        try {
            $stmtF = mysqli_prepare($conn, "SELECT etd, eta, prevista FROM follow WHERE TRIM(processo) = ? ORDER BY id DESC LIMIT 1");
            mysqli_stmt_bind_param($stmtF, 's', $proc);
            mysqli_stmt_execute($stmtF);
            $follow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtF)) ?: null;
            mysqli_stmt_close($stmtF);
        } catch (Throwable $e) {
            $follow = null;
        }
        $pickup = '';
        if ($follow && !empty($follow['etd'])) {
            $etdObj = DateTimeImmutable::createFromFormat('!Y-m-d', substr($follow['etd'], 0, 10));
            if ($etdObj) {
                $pickup = $etdObj->modify('-' . PICKUP_DIAS_ANTES_ETD . ' days')->format('d/m/Y');
            }
        }
        $spot['po'] = implode(' / ', array_keys($spot['pos']));
        unset($spot['pos']);
        $spot['cidade'] = cidadePlanta($spot['planta']);
        $spot['modal_txt'] = modalPlanilha($spot['modal']);
        $spot['pickup'] = $pickup;
        $spot['etd'] = dataBrRelease($follow['etd'] ?? null);
        $spot['eta'] = dataBrRelease($follow['eta'] ?? null);
        $spot['eta_yapp'] = dataBrRelease($follow['prevista'] ?? null);
        $spot['ordem'] = substr((string) ($follow['prevista'] ?? $follow['eta'] ?? '9999-12-31'), 0, 10) . $proc;
        $spot['tem_follow'] = $follow !== null;
    }
    unset($spot);
    $spots = array_values($spots);
    usort($spots, fn($a, $b) => strcmp($a['ordem'], $b['ordem']));

    echo json_encode([
        'spots' => $spots,
        'itens' => array_values($itens),
        'tem_parametros' => $comParametros,
        'bom_status' => $bomStatus,
    ]);
    exit;
}

// ---------- Projetos → Processos (abertos primeiro) ----------
$processosPorProjeto = [];
$res = mysqli_query($conn, "
    SELECT COALESCE(TRIM(projeto), '') AS projeto, TRIM(processo) AS processo,
           MAX(CASE WHEN LOWER(TRIM(COALESCE(status, ''))) = 'finalizado' THEN 1 ELSE 0 END) AS finalizado
    FROM processos
    WHERE processo IS NOT NULL AND TRIM(processo) <> ''
      AND LOWER(TRIM(COALESCE(status, ''))) <> 'cancelado'
    GROUP BY COALESCE(TRIM(projeto), ''), TRIM(processo)
    ORDER BY projeto, finalizado, processo
");
while ($l = mysqli_fetch_assoc($res)) {
    $processosPorProjeto[$l['projeto']][] = ['processo' => $l['processo'], 'finalizado' => (int) $l['finalizado'] === 1];
}

$importers = [
    'americana' => [
        'nome' => 'YAPP Americana',
        'texto' => "IMPORTER:  YAPP BRASIL FABRICACAO DE TANQUES E RESERVATORIOS PARA VEICULOS AUTOMOTORES LTDA.\nID TAX: 27.690.132/0003-00\nR. Doosan, 777 - Parque Sao Jeronimo, Americana - SP, Brasil\nZIP 13469-765",
    ],
    'gravatai' => [
        'nome' => 'YAPP Gravataí',
        'texto' => "IMPORTER:  YAPP BRASIL FABRICACAO DE TANQUES E RESERVATORIOS PARA VEICULOS AUTOMOTORES LTDA.\nID TAX: 27.690.132/0001-49\nRS-118, 12701 - Galpão 3, Neópolis, Gravataí - Rio Grande do Sul - Brasil\nZIP 94100-420",
    ],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Release | Controle de Importação</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/dashboard.css" rel="stylesheet">
    <style>
        .dashboard-container { max-width: 98vw; }
        .rel-sheet { background: #fff; border: 1px solid #dce4ec; box-shadow: 0 6px 20px rgba(18,48,74,.10); padding: 16px; }
        .lista-rel { max-height: 160px; overflow: auto; border: 1px solid #dce4ec; border-radius: 6px; padding: 6px 10px; }
        .lista-rel label { display: block; font-size: .82rem; }
        #lista-processos-rel { max-height: 160px; overflow: auto; border: 1px solid #dce4ec; border-radius: 6px; padding: 6px 10px; }
        #lista-processos-rel label { display: inline-block; min-width: 150px; font-size: .82rem; }
        .tab-rel { font-size: .8rem; }
        .tab-rel th { background: #f3f6f9; font-size: .7rem; text-transform: uppercase; text-align: center; padding: 5px; white-space: nowrap; }
        .tab-rel td { padding: 2px 5px; vertical-align: middle; }
        .tab-rel input { border: none; background: transparent; width: 100%; font-size: .8rem; padding: 2px; }
        .tab-rel input:focus { outline: none; background: #f0f6ff; }
        .tab-rel td.num input { text-align: right; }
        .tab-rel th.spot { background: #44546a; color: #fff; }
        .tab-rel td.forecast { background: #f0f0f0; }
        .falta-param { color: #c0504d; }
        #importer_texto { font-size: .82rem; white-space: pre; }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="container-fluid dashboard-container d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="eyebrow">Controle de Importação • Documentos</span>
                <h1>Release</h1>
                <p class="mb-0">Escolha o Projeto e os Processos (cada um vira um Spot), confira e exporte</p>
            </div>
            <nav class="d-flex flex-wrap gap-2" aria-label="Ações do sistema">
                <a class="btn btn-outline-light btn-sm" href="follow.php">Follow</a>
                <a class="btn btn-outline-light btn-sm" href="processos.php">Processos</a>
                <a class="btn btn-outline-light btn-sm" href="pagamento.php">Pagamento</a>
                <a class="btn btn-outline-light btn-sm" href="confirmar_entrega.php">Confirmar entrega</a>
                <a class="btn btn-outline-light btn-sm" href="commercial_invoice.php">📄 Commercial Invoice</a>
                <a class="btn btn-light btn-sm" href="release.php">🚚 Release</a>
                <a class="btn btn-outline-light btn-sm" href="parametros_importacao.php">⚙️ Parâmetros</a>
                <button type="button" class="btn btn-success btn-sm" onclick="exportarRelease()">📊 Exportar planilha</button>
            </nav>
        </div>
    </header>

    <main class="container-fluid dashboard-container py-4">
        <div id="avisos"></div>
        <div class="rel-sheet">
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold mb-1">Projeto(s) — pode marcar mais de um</label>
                    <div id="lista-projetos-rel" class="lista-rel">
                        <?php foreach (array_keys($processosPorProjeto) as $proj): ?>
                            <label><input type="checkbox" class="chk-proj" value="<?php echo h($proj); ?>" onchange="preencherProcessos()"> <?php echo h($proj === '' ? '(sem projeto)' : $proj); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold mb-1">Processos (cada um = 1 Spot)</label>
                    <div id="lista-processos-rel"><span class="text-muted small">Escolha o projeto primeiro.</span></div>
                    <div class="d-flex align-items-center gap-3 mt-1">
                        <button type="button" class="btn btn-primary btn-sm" onclick="carregarRelease()">Carregar</button>
                        <label class="small mb-0"><input type="checkbox" id="chk_bom" checked> Todos os itens da BOM do projeto (sem processo = 0)</label>
                    </div>
                </div>
                <div class="col-md-5">
                    <label class="form-label small fw-bold mb-1">Importer</label>
                    <select id="sel_importer" class="form-select form-select-sm mb-1" onchange="aplicarImporter()">
                        <?php foreach ($importers as $chave => $imp): ?>
                            <option value="<?php echo $chave; ?>"><?php echo h($imp['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <textarea id="importer_texto" class="form-control form-control-sm" rows="4"></textarea>
                </div>
            </div>

            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered tab-rel mb-0" id="tabela-spots"></table>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered tab-rel mb-0" id="tabela-itens-rel"></table>
            </div>
            <small class="text-muted d-block mt-2">Na planilha exportada: PACK = FORECAST ÷ unit pack · CBM = volume × PACK · PRICE = FORECAST × U PRICE · volume = L × W × H ÷ 1.000.000 · N WEIGHT = weight × unit pack. Valores em <span class="falta-param">vermelho</span> = sem cadastro em Parâmetros. Tudo é editável aqui antes de exportar (não altera o banco).</small>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
    <script>
        const PROCESSOS_POR_PROJETO = <?php echo json_encode($processosPorProjeto, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const IMPORTERS = <?php echo json_encode($importers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const PICKUP_DIAS = <?php echo PICKUP_DIAS_ANTES_ETD; ?>;
        const CAMPOS_PARAM = ['unit_pack', 'u_price', 'length', 'width', 'height', 'peso', 'g_weight'];
        let SPOTS = [];

        function parseBr(t) {
            t = String(t ?? '').trim().replace(/\s/g, '');
            if (!t) return null;
            if (t.includes(',')) t = t.replace(/\./g, '').replace(',', '.');
            else if (/^\d{1,3}(\.\d{3})+$/.test(t)) t = t.replace(/\./g, '');
            const n = parseFloat(t);
            return isNaN(n) ? null : n;
        }
        function fmtBr(n) {
            return (n === null || n === undefined) ? '' : String(n).replace('.', ',');
        }
        function lerData(t) {
            const m = String(t ?? '').trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
            if (!m) return null;
            const d = new Date(+m[3], +m[2] - 1, +m[1]);
            return d.getDate() === +m[1] ? d : null;
        }
        function escreverData(d) {
            return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0') + '/' + d.getFullYear();
        }
        function esc(t) {
            return String(t ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }

        function aplicarImporter() {
            document.getElementById('importer_texto').value = IMPORTERS[document.getElementById('sel_importer').value].texto;
        }

        function projetosMarcados() {
            return Array.from(document.querySelectorAll('.chk-proj:checked')).map(c => c.value);
        }
        function nomeProjetos() {
            return projetosMarcados().filter(p => p !== '').join(' + ') || 'projeto';
        }

        // Lista os processos dos projetos marcados — abertos já vêm marcados.
        // Mantém a marcação de quem já estava na lista.
        function preencherProcessos() {
            const caixa = document.getElementById('lista-processos-rel');
            const antes = {};
            document.querySelectorAll('.chk-proc').forEach(c => { antes[c.value] = c.checked; });
            const vistos = new Set();
            let html = '';
            projetosMarcados().forEach(proj => {
                (PROCESSOS_POR_PROJETO[proj] || []).forEach(p => {
                    if (vistos.has(p.processo)) return;
                    vistos.add(p.processo);
                    const marcado = p.processo in antes ? antes[p.processo] : !p.finalizado;
                    html += `<label><input type="checkbox" class="chk-proc" value="${esc(p.processo)}" ${marcado ? 'checked' : ''}> ${esc(p.processo)} <small class="text-muted">${esc(proj)}${p.finalizado ? ' · finalizado' : ''}</small></label>`;
                });
            });
            caixa.innerHTML = html || '<span class="text-muted small">Marque um ou mais projetos.</span>';
        }

        function carregarRelease() {
            const projeto = nomeProjetos();
            const processos = Array.from(document.querySelectorAll('.chk-proc:checked')).map(c => c.value);
            if (!projetosMarcados().length) { alert('Marque pelo menos um projeto.'); return; }
            if (!processos.length) { alert('Marque pelo menos um processo.'); return; }
            document.title = 'Release_' + projeto;
            fetch('release.php?ajax=buscar&projetos=' + encodeURIComponent(JSON.stringify(projetosMarcados())) + '&processos=' + encodeURIComponent(processos.join(',')) + '&bom=' + (document.getElementById('chk_bom').checked ? '1' : '0'))
                .then(r => r.json())
                .then(d => {
                    SPOTS = d.spots || [];
                    const avisos = [];
                    SPOTS.filter(s => !s.tem_follow).forEach(s => avisos.push(s.processo + ' ainda não está no Follow — datas em branco (dá pra digitar).'));
                    if (d.tem_parametros === false) avisos.push('A tabela de Parâmetros da Importação ainda não existe no banco.');
                    if (d.bom_status === 'sem_conexao') avisos.push('Não consegui acessar a BOM do MRP agora — saíram só os itens dos processos.');
                    if (d.bom_status === 'projeto_nao_achado') avisos.push('Não achei "' + projeto + '" na BOM (o nome precisa ser igual ao da BOM) — saíram só os itens dos processos.');
                    document.getElementById('avisos').innerHTML = avisos.map(a => '<div class="alert alert-warning py-2">' + esc(a) + '</div>').join('');
                    // Importer pela planta do 1º spot
                    if (SPOTS[0] && SPOTS[0].cidade === 'GRAVATAÍ') document.getElementById('sel_importer').value = 'gravatai';
                    else if (SPOTS[0]) document.getElementById('sel_importer').value = 'americana';
                    aplicarImporter();
                    desenharSpots();
                    desenharItens(d.itens || []);
                })
                .catch(() => alert('Erro de conexão ao buscar os processos.'));
        }

        // Quadro dos Spots (uma coluna por Spot): cidade, modal e as 4 datas
        function desenharSpots() {
            const tab = document.getElementById('tabela-spots');
            if (!SPOTS.length) { tab.innerHTML = ''; return; }
            const linha = (rotulo, campo, extra = '') => '<tr><th class="text-start">' + rotulo + '</th>' + SPOTS.map((s, i) =>
                `<td><input class="sp-${campo}" data-i="${i}" value="${esc(s[campo])}" ${extra}></td>`).join('') + '</tr>';
            tab.innerHTML =
                '<tr><th></th>' + SPOTS.map((s, i) => `<th class="spot">Spot ${i + 1}<br><small>${esc(s.processo)}${s.po ? ' · PO ' + esc(s.po) : ''}</small></th>`).join('') + '</tr>' +
                linha('Cidade', 'cidade') + linha('Modal', 'modal_txt') +
                linha('PICK UP', 'pickup') + linha('ETD', 'etd', 'oninput="recalcularPickup(this)"') + linha('ETA', 'eta') + linha('ETA YAPP', 'eta_yapp');
        }

        function recalcularPickup(campo) {
            const etd = lerData(campo.value);
            if (!etd) return;
            etd.setDate(etd.getDate() - PICKUP_DIAS);
            document.querySelector('.sp-pickup[data-i="' + campo.dataset.i + '"]').value = escreverData(etd);
        }

        // Itens: descrição, SAP, unit pack, FORECAST de cada Spot e o quadro de parâmetros
        function desenharItens(itens) {
            const tab = document.getElementById('tabela-itens-rel');
            if (!itens.length) { tab.innerHTML = '<tr><td class="text-muted p-3">Nenhum item.</td></tr>'; return; }
            const inp = (cls, valor, falta) => `<td class="num"><input class="${cls}${falta ? ' falta-param' : ''}" value="${esc(valor)}"></td>`;
            let html = '<thead><tr><th>Description</th><th>SAP</th><th>unit pack</th>' +
                SPOTS.map((s, i) => `<th class="spot">Spot ${i + 1}<br>FORECAST</th>`).join('') +
                '<th>U PRICE</th><th>LENGTH</th><th>WIDTH</th><th>HEIGHT</th><th>weight of component (kg)</th><th>G WEIGHT</th></tr></thead><tbody>';
            itens.forEach(it => {
                html += '<tr class="linha-item">' +
                    `<td><input class="f-desc" value="${esc(it.desc)}" style="min-width:220px"></td>` +
                    `<td><input class="f-sap" value="${esc(it.sap)}" style="min-width:90px"></td>` +
                    inp('f-unit_pack', it.unit_pack, it.unit_pack === '') +
                    SPOTS.map((s, i) => `<td class="num forecast"><input class="f-qtd" data-i="${i}" value="${esc(fmtBr(it.qtd[s.processo] ?? 0))}"></td>`).join('') +
                    inp('f-u_price', it.u_price, it.u_price === '') +
                    inp('f-length', it.length, it.length === '') +
                    inp('f-width', it.width, it.width === '') +
                    inp('f-height', it.height, it.height === '') +
                    inp('f-peso', it.peso, it.peso === '') +
                    inp('f-g_weight', it.g_weight, it.g_weight === '') +
                    '</tr>';
            });
            tab.innerHTML = html + '</tbody>';
        }

        function lerItens() {
            return Array.from(document.querySelectorAll('#tabela-itens-rel tr.linha-item')).map(tr => {
                const v = { desc: tr.querySelector('.f-desc').value, sap: tr.querySelector('.f-sap').value.trim() };
                CAMPOS_PARAM.forEach(c => { v[c] = parseBr(tr.querySelector('.f-' + c).value); });
                v.qtd = SPOTS.map((s, i) => parseBr(tr.querySelector('.f-qtd[data-i="' + i + '"]').value) ?? 0);
                return v;
            });
        }
        function lerSpots() {
            return SPOTS.map((s, i) => {
                const val = campo => document.querySelector('.sp-' + campo + '[data-i="' + i + '"]').value;
                return { cidade: val('cidade'), modal: val('modal_txt'), pickup: val('pickup'), etd: val('etd'), eta: val('eta'), eta_yapp: val('eta_yapp') };
            });
        }

        // ---------- Exportar .xlsx idêntico à aba de projeto da planilha ----------
        // Colunas: A # (oculta) | B DESCRIPTION | C SAP | D unit pack |
        // por Spot: FORECAST | PACK | CBM | PRICE | 2 colunas de respiro |
        // U PRICE | T PRICE (oculta) | LENGTH | WIDTH | HEIGHT | volume | weight of component | N WEIGHT | G WEIGHT
        function exportarRelease() {
            if (typeof ExcelJS === 'undefined') { alert('Biblioteca de planilha não carregou. Recarregue a página.'); return; }
            const itens = lerItens();
            if (!itens.length || !SPOTS.length) { alert('Carregue o projeto e os processos antes de exportar.'); return; }
            const spots = lerSpots();
            const projeto = nomeProjetos();

            const wb = new ExcelJS.Workbook();
            wb.calcProperties.fullCalcOnLoad = true;
            const ws = wb.addWorksheet((projeto || 'Release').replace(/[\\\/\?\*\[\]:]/g, ' ').substring(0, 31), {
                views: [{ showGridLines: false, zoomScale: 70 }],
            });
            ws.pageSetup = { orientation: 'landscape', fitToPage: true, fitToWidth: 1, fitToHeight: 0 };

            const L = n => { let s = ''; while (n > 0) { const r = (n - 1) % 26; s = String.fromCharCode(65 + r) + s; n = Math.floor((n - 1) / 26); } return s; };
            const fino = { style: 'thin' };
            const bordaTodas = { top: fino, left: fino, bottom: fino, right: fino };
            const CINZA = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFD9D9D9' } };
            const BRANCO = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFFFFFFF' } };
            const ARDOSIA = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF44546A' } };
            const YAHEI = '微软雅黑';
            const arial = (size, bold = false, extra = {}) => ({ name: 'Arial', size, bold, ...extra });
            const VERMELHO = { argb: 'FFFF0000' };
            const comoData = t => { const d = lerData(t); return d ? new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate())) : (t || null); };

            // Posição das colunas
            const colSpot = i => 5 + i * 4;                 // FORECAST do Spot i (E, I, M...)
            const fimSpots = 5 + spots.length * 4 - 1;
            const cU = fimSpots + 3;                        // U PRICE (2 colunas de respiro antes)
            const cT = cU + 1, cLen = cU + 2, cWid = cU + 3, cHei = cU + 4, cVol = cU + 5, cPeso = cU + 6, cNw = cU + 7, cGw = cU + 8;

            // Larguras (iguais às da planilha)
            ws.getColumn(1).width = 2.91; ws.getColumn(1).hidden = true;
            ws.getColumn(2).width = 22.27; ws.getColumn(3).width = 13.54; ws.getColumn(4).width = 10.09;
            spots.forEach((s, i) => {
                const c = colSpot(i);
                ws.getColumn(c).width = 16; ws.getColumn(c + 1).width = 5.45; ws.getColumn(c + 2).width = 6.5; ws.getColumn(c + 3).width = 9.5;
            });
            ws.getColumn(fimSpots + 1).width = 3; ws.getColumn(fimSpots + 2).width = 3;
            ws.getColumn(cU).width = 5.73; ws.getColumn(cT).width = 6.73; ws.getColumn(cT).hidden = true;
            [cLen, cWid, cHei].forEach(c => { ws.getColumn(c).width = 4.91; });
            ws.getColumn(cVol).width = 5.5; ws.getColumn(cPeso).width = 7.91; ws.getColumn(cNw).width = 8; ws.getColumn(cGw).width = 8.54;

            // Linhas 1-7: cabeçalho de cada Spot
            [16.2, 22.2, 16.2, 19.7, 19.7, 19.7, 19.7].forEach((ht, k) => { ws.getRow(k + 1).height = ht; });
            const rotulos = [[4, 'PICK UP', 'pickup'], [5, 'ETD', 'etd'], [6, 'ETA', 'eta'], [7, 'ETA YAPP', 'eta_yapp']];
            rotulos.forEach(([lin, rot]) => {
                const c = ws.getCell(lin, 2);
                c.value = rot; c.font = arial(16, true, { color: VERMELHO }); c.alignment = { vertical: 'middle', wrapText: true };
            });
            spots.forEach((s, i) => {
                const c = colSpot(i);
                const t1 = ws.getCell(1, c);
                t1.value = 'Spot ' + (i + 1); t1.fill = ARDOSIA; t1.font = { name: YAHEI, size: 11, bold: true, color: { argb: 'FFFFFFFF' } };
                t1.alignment = { horizontal: 'center', vertical: 'middle' };
                [[2, s.cidade], [3, s.modal]].forEach(([lin, valor]) => {
                    for (let k = 0; k < 4; k++) { ws.getCell(lin, c + k).fill = BRANCO; }
                    const cel = ws.getCell(lin, c);
                    cel.value = valor || null; cel.font = { name: YAHEI, size: 11, bold: true };
                    cel.alignment = { horizontal: 'center', vertical: 'middle' }; cel.border = { left: fino, right: fino };
                });
                rotulos.forEach(([lin, , campo]) => {
                    for (let k = 1; k < 4; k++) { ws.getCell(lin, c + k).fill = BRANCO; }
                    const cel = ws.getCell(lin, c);
                    cel.value = comoData(s[campo]); cel.numFmt = 'dd/mm/yyyy';
                    cel.font = arial(16, false, { color: VERMELHO }); cel.fill = BRANCO;
                    cel.alignment = { horizontal: 'center', vertical: 'middle', wrapText: true };
                    cel.border = lin === 4 ? bordaTodas : { left: fino, right: fino, bottom: fino };
                });
            });

            // Linha 8: títulos
            ws.getRow(8).height = 33;
            const titulo = (col, texto, bold, centro = false, borda = true) => {
                const c = ws.getCell(8, col);
                c.value = texto; c.font = arial(8, bold);
                c.alignment = { horizontal: centro ? 'center' : (col === 3 ? 'left' : undefined), vertical: 'middle', wrapText: true };
                if (borda) c.border = bordaTodas;
            };
            ws.getCell(8, 1).border = { left: fino, right: fino, top: fino };
            titulo(2, 'DESCRIPTION', true); titulo(3, 'SAP', true); titulo(4, 'unit pack', true);
            spots.forEach((s, i) => {
                const c = colSpot(i);
                titulo(c, 'FORECAST', false, false, false);
                titulo(c + 1, 'PACK', true, true); titulo(c + 2, 'CBM', true, true); titulo(c + 3, 'PRICE', true, true);
            });
            ws.getCell(8, fimSpots + 1).border = { left: fino };
            titulo(cU, 'U  PRICE', true); titulo(cT, 'T PRICE', false); titulo(cLen, 'LENGTH', true); titulo(cWid, 'WIDTH', true);
            titulo(cHei, 'HEIGHT', true); titulo(cVol, 'volume', false); titulo(cPeso, 'weight of component（kg）', false);
            titulo(cNw, 'N WEIGHT', false); titulo(cGw, 'G WEIGHT', false);

            // Itens (a partir da linha 9)
            const primeira = 9;
            itens.forEach((v, k) => {
                const n = primeira + k;
                ws.getRow(n).height = 13.8;
                const cel = (col, valor, fonte, extra = {}) => {
                    const c = ws.getCell(n, col);
                    c.value = valor; c.font = fonte; c.border = bordaTodas;
                    c.alignment = { vertical: 'middle', ...(extra.al || {}) };
                    if (extra.numFmt) c.numFmt = extra.numFmt;
                    if (extra.fill) c.fill = extra.fill;
                    return c;
                };
                const D = `D${n}`, U = `${L(cU)}${n}`, VOL = `${L(cVol)}${n}`;
                cel(1, k + 1, arial(11), { al: { horizontal: 'left' } });
                cel(2, v.desc, arial(10));
                cel(3, v.sap, arial(11), { al: { horizontal: 'left' }, numFmt: '@' });
                cel(4, v.unit_pack, arial(11));
                spots.forEach((s, i) => {
                    const c = colSpot(i), Q = `${L(c)}${n}`, P = `${L(c + 1)}${n}`;
                    cel(c, v.qtd[i], arial(11, true), { fill: CINZA, numFmt: '#,##0' });
                    cel(c + 1, { formula: `IFERROR(${Q}/${D},0)` }, arial(10), { al: { horizontal: 'center' }, numFmt: 'General' });
                    cel(c + 2, { formula: `IFERROR(${VOL}*${P},0)` }, arial(10), { al: { horizontal: 'center' }, numFmt: '0.000' });
                    cel(c + 3, { formula: `IFERROR(${U}*${Q},0)` }, arial(10), { al: { horizontal: 'center' }, numFmt: '0.00' });
                });
                ws.getCell(n, fimSpots + 1).border = { left: fino };
                cel(cU, v.u_price, arial(11, true), { numFmt: '0.00' });
                cel(cT, null, arial(11));
                cel(cLen, v.length, arial(11)); cel(cWid, v.width, arial(11)); cel(cHei, v.height, arial(11));
                cel(cVol, { formula: `IFERROR(${L(cLen)}${n}*${L(cWid)}${n}*${L(cHei)}${n}/1000000,0)` }, arial(11), { numFmt: '0.00' });
                cel(cPeso, v.peso, arial(11), { numFmt: '0.00' });
                cel(cNw, { formula: `IFERROR(${L(cPeso)}${n}*${D},0)` }, arial(11), { numFmt: '0.##' });
                cel(cGw, v.g_weight, arial(11));
            });

            // Total
            const ultima = primeira + itens.length - 1;
            const nt = ultima + 1;
            ws.getRow(nt).height = 17.4;
            const ct = (col, valor, fonte, centro = false) => {
                const c = ws.getCell(nt, col);
                c.value = valor; c.font = fonte; c.border = bordaTodas;
                c.alignment = { horizontal: centro ? 'center' : undefined, vertical: 'middle', wrapText: true };
            };
            ct(1, ' ', arial(11)); ct(2, 'Total', arial(11, true)); ct(3, null, arial(11)); ct(4, null, arial(11));
            spots.forEach((s, i) => {
                const c = colSpot(i);
                ct(c, null, arial(11, true), true);
                [1, 2, 3].forEach(k => ct(c + k, { formula: `SUM(${L(c + k)}${primeira}:${L(c + k)}${ultima})` }, arial(10, true), true));
                ws.getCell(nt, c + 2).numFmt = '0.000';
                ws.getCell(nt, c + 3).numFmt = '#,##0.00';
            });

            // Importer
            const ni = nt + 3;
            ws.getRow(ni).height = 96;
            ws.mergeCells(ni, 2, ni, Math.max(fimSpots, 8));
            const ci = ws.getCell(ni, 2);
            ci.value = document.getElementById('importer_texto').value;
            ci.font = { name: YAHEI, size: 11, bold: true };
            ci.alignment = { wrapText: true, vertical: 'top' };

            wb.xlsx.writeBuffer().then(buf => {
                const blob = new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = ('Release_' + (projeto || 'projeto') + '.xlsx').replace(/\s+/g, '_');
                document.body.appendChild(a); a.click(); a.remove();
                URL.revokeObjectURL(a.href);
            }).catch(() => alert('Erro ao gerar a planilha.'));
        }

        aplicarImporter();
    </script>
</body>
</html>
