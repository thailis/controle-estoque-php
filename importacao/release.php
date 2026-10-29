<?php
// importacao/release.php
// Release de embarque: escolhe Projeto + Processo e monta a tabela no layout
// da planilha "PO between YAPP HQ and YAPP Subsidiaries", exportando .xlsx.
//   SAP / Description / Qtd / PO / Planta → Processos
//   ETD / ETA / ETA YAPP (Prevista)       → Follow (registro mais recente do processo)
//   Pick up                               → ETD − 20 dias
//   unit pack, U PRICE, L/W/H, weight, G WEIGHT → Parâmetros da Importação
//   PACK, T PRICE, CBM, N WEIGHT          → fórmulas (iguais às da planilha)
// Nada é salvo no banco por aqui — os valores podem ser ajustados na tela antes de exportar.
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

// ---------- AJAX: itens do processo ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'buscar') {
    header('Content-Type: application/json; charset=utf-8');
    $projeto = trim((string) ($_GET['projeto'] ?? ''));
    $processo = trim((string) ($_GET['processo'] ?? ''));

    $sqlBase = "
        SELECT p.codigo_componente, p.descricao, p.quantidade, p.po, p.planta %s
        FROM processos p %s
        WHERE TRIM(p.processo) = ?
          AND COALESCE(TRIM(p.projeto), '') = ?
          AND LOWER(TRIM(COALESCE(p.status, ''))) <> 'cancelado'
        ORDER BY p.id
    ";
    $comParametros = true;
    try {
        $stmt = mysqli_prepare($conn, sprintf($sqlBase,
            ', pi.unit_pack, pi.u_price, pi.length_cm, pi.width_cm, pi.height_cm, pi.peso_unitario, pi.g_weight',
            'LEFT JOIN parametros_importacao pi ON TRIM(pi.codigo_componente) = TRIM(p.codigo_componente)'));
    } catch (Throwable $e) {
        // tabela de parâmetros ainda não criada — segue sem ela
        $comParametros = false;
        $stmt = mysqli_prepare($conn, sprintf($sqlBase, '', ''));
    }
    mysqli_stmt_bind_param($stmt, 'ss', $processo, $projeto);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $itens = [];
    $pos = [];
    $plantas = [];
    while ($l = mysqli_fetch_assoc($res)) {
        if (trim((string) $l['po']) !== '') { $pos[trim($l['po'])] = true; }
        if (trim((string) $l['planta']) !== '') { $plantas[trim($l['planta'])] = true; }
        $itens[] = [
            'sap' => trim((string) $l['codigo_componente']),
            'desc' => (string) ($l['descricao'] ?? ''),
            'qtd' => numJs($l['quantidade']),
            'unit_pack' => numJs($l['unit_pack'] ?? null),
            'u_price' => numJs($l['u_price'] ?? null),
            'length' => numJs($l['length_cm'] ?? null),
            'width' => numJs($l['width_cm'] ?? null),
            'height' => numJs($l['height_cm'] ?? null),
            'peso' => numJs($l['peso_unitario'] ?? null),
            'g_weight' => numJs($l['g_weight'] ?? null),
        ];
    }
    mysqli_stmt_close($stmt);

    $follow = null;
    try {
        $stmtF = mysqli_prepare($conn, "SELECT etd, eta, prevista FROM follow WHERE TRIM(processo) = ? ORDER BY id DESC LIMIT 1");
        mysqli_stmt_bind_param($stmtF, 's', $processo);
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

    echo json_encode([
        'itens' => $itens,
        'po' => implode(' / ', array_keys($pos)),
        'planta' => implode(' / ', array_keys($plantas)),
        'etd' => dataBrRelease($follow['etd'] ?? null),
        'eta' => dataBrRelease($follow['eta'] ?? null),
        'eta_yapp' => dataBrRelease($follow['prevista'] ?? null),
        'pickup' => $pickup,
        'tem_follow' => $follow !== null,
        'tem_parametros' => $comParametros,
    ]);
    exit;
}

// ---------- Dropdowns: Projeto → Processos ----------
$processosPorProjeto = [];
$res = mysqli_query($conn, "
    SELECT COALESCE(TRIM(projeto), '') AS projeto, TRIM(processo) AS processo
    FROM processos
    WHERE processo IS NOT NULL AND TRIM(processo) <> ''
      AND LOWER(TRIM(COALESCE(status, ''))) <> 'cancelado'
    GROUP BY COALESCE(TRIM(projeto), ''), TRIM(processo)
    ORDER BY projeto, processo DESC
");
while ($l = mysqli_fetch_assoc($res)) {
    $processosPorProjeto[$l['projeto']][] = $l['processo'];
}

$importers = [
    'americana' => [
        'nome' => 'YAPP Americana',
        'texto' => "Importer:  YAPP BRASIL FABRICACAO DE TANQUES E RESERVATORIOS PARA VEICULOS AUTOMOTORES LTDA.\nID TAX: 27.690.132/0003-00\nR. Doosan, 777 - Parque Sao Jeronimo, Americana - SP, Brasil\nZIP 13469-765",
    ],
    'gravatai' => [
        'nome' => 'YAPP Gravataí',
        'texto' => "Importer:  YAPP BRASIL FABRICACAO DE TANQUES E RESERVATORIOS PARA VEICULOS AUTOMOTORES LTDA.\nID TAX: 27.690.132/0001-49\nRS-118, 12701 - Galpão 3, Neópolis, Gravataí - Rio Grande do Sul - Brasil\nZIP 94100-420",
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
        .rel-cab td { padding: 3px 8px; font-size: .85rem; vertical-align: middle; }
        .rel-cab td.lbl { font-weight: 700; color: #3c4c5c; white-space: nowrap; }
        .rel-cab input { border: none; border-bottom: 1px solid #dce4ec; background: transparent; width: 130px; font-size: .85rem; }
        .rel-cab input:focus, #tabela-release input:focus { outline: none; background: #f0f6ff; }
        #tabela-release { font-size: .8rem; min-width: 1400px; }
        #tabela-release th { background: #f3f6f9; font-size: .7rem; text-transform: uppercase; text-align: center; padding: 6px; white-space: nowrap; }
        #tabela-release td { padding: 3px 6px; vertical-align: middle; }
        #tabela-release td.num, #tabela-release td.num input { text-align: right; }
        #tabela-release input { border: none; background: transparent; width: 100%; font-size: .8rem; padding: 2px; }
        #tabela-release .calc { background: #f7f9fb; font-weight: 600; }
        #tabela-release tfoot td { font-weight: 750; background: #eef1f5; }
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
                <p class="mb-0">Escolha Projeto e Processo, confira e exporte a planilha</p>
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
                    <label class="form-label small fw-bold mb-1">Projeto</label>
                    <select id="sel_projeto" class="form-select form-select-sm" onchange="preencherProcessos()">
                        <option value="">Selecione...</option>
                        <?php foreach (array_keys($processosPorProjeto) as $proj): ?>
                            <option value="<?php echo h($proj); ?>"><?php echo h($proj === '' ? '(sem projeto)' : $proj); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold mb-1">Processo</label>
                    <select id="sel_processo" class="form-select form-select-sm" onchange="carregarRelease()">
                        <option value="">Escolha o projeto primeiro</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-bold mb-1">Importer</label>
                    <select id="sel_importer" class="form-select form-select-sm mb-1" onchange="aplicarImporter()">
                        <?php foreach ($importers as $chave => $imp): ?>
                            <option value="<?php echo $chave; ?>"><?php echo h($imp['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <textarea id="importer_texto" class="form-control form-control-sm" rows="4"></textarea>
                </div>
            </div>

            <table class="rel-cab mb-3">
                <tr>
                    <td class="lbl">Planta</td><td><input type="text" id="cab_planta"></td>
                    <td class="lbl">PO</td><td><input type="text" id="cab_po" style="width:200px"></td>
                </tr>
                <tr>
                    <td class="lbl">PICK UP</td><td><input type="text" id="cab_pickup" placeholder="dd/mm/aaaa" title="ETD − <?php echo PICKUP_DIAS_ANTES_ETD; ?> dias"></td>
                    <td class="lbl">ETD</td><td><input type="text" id="cab_etd" placeholder="dd/mm/aaaa" oninput="recalcularPickup()"></td>
                </tr>
                <tr>
                    <td class="lbl">ETA</td><td><input type="text" id="cab_eta" placeholder="dd/mm/aaaa"></td>
                    <td class="lbl">ETA YAPP</td><td><input type="text" id="cab_eta_yapp" placeholder="dd/mm/aaaa"></td>
                </tr>
            </table>

            <div class="table-responsive">
                <table class="table table-sm mb-0" id="tabela-release">
                    <thead>
                        <tr>
                            <th>#</th><th>Description</th><th>SAP</th><th>Unit pack</th><th>Qty</th><th>Pack</th>
                            <th>U Price</th><th>T Price</th><th>Length</th><th>Width</th><th>Height</th>
                            <th>CBM</th><th>Weight of component (kg)</th><th>N Weight</th><th>G Weight</th>
                        </tr>
                    </thead>
                    <tbody id="corpo-release">
                        <tr><td colspan="15" class="text-center text-muted py-4">Escolha Projeto e Processo.</td></tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="text-end">Total</td>
                            <td class="num" id="tot_qtd"></td><td class="num" id="tot_pack"></td><td></td>
                            <td class="num" id="tot_tprice"></td><td colspan="3"></td>
                            <td class="num" id="tot_cbm"></td><td></td>
                            <td class="num" id="tot_nweight"></td><td class="num" id="tot_gweight"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <small class="text-muted d-block mt-2">Pack = Qty ÷ Unit pack · T Price = Qty × U Price · CBM = Pack × L × W × H ÷ 1.000.000 · N Weight = Qty × Weight. Valores em <span class="falta-param">vermelho</span> = sem cadastro em Parâmetros. Tudo é editável aqui antes de exportar (não altera o banco).</small>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
    <script>
        const PROCESSOS_POR_PROJETO = <?php echo json_encode($processosPorProjeto, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const IMPORTERS = <?php echo json_encode($importers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const PICKUP_DIAS = <?php echo PICKUP_DIAS_ANTES_ETD; ?>;
        const CAMPOS_EDIT = ['unit_pack', 'qtd', 'u_price', 'length', 'width', 'height', 'peso', 'g_weight'];

        function parseBr(t) {
            t = String(t ?? '').trim().replace(/\s/g, '');
            if (!t) return null;
            if (t.includes(',')) t = t.replace(/\./g, '').replace(',', '.');
            else if (/^\d{1,3}(\.\d{3})+$/.test(t)) t = t.replace(/\./g, '');
            const n = parseFloat(t);
            return isNaN(n) ? null : n;
        }
        function fmt(n, dec) {
            if (n === null || n === undefined || isNaN(n)) return '';
            return n.toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: dec });
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

        function preencherProcessos() {
            const proj = document.getElementById('sel_projeto').value;
            const sel = document.getElementById('sel_processo');
            sel.innerHTML = '<option value="">Selecione...</option>';
            (PROCESSOS_POR_PROJETO[proj] || []).forEach(p => {
                const o = document.createElement('option');
                o.value = p; o.textContent = p;
                sel.appendChild(o);
            });
        }

        function recalcularPickup() {
            const etd = lerData(document.getElementById('cab_etd').value);
            if (!etd) return;
            etd.setDate(etd.getDate() - PICKUP_DIAS);
            document.getElementById('cab_pickup').value = escreverData(etd);
        }

        function carregarRelease() {
            const projeto = document.getElementById('sel_projeto').value;
            const processo = document.getElementById('sel_processo').value;
            if (!processo) return;
            document.title = 'Release_' + processo;
            fetch('release.php?ajax=buscar&projeto=' + encodeURIComponent(projeto) + '&processo=' + encodeURIComponent(processo))
                .then(r => r.json())
                .then(d => {
                    document.getElementById('cab_planta').value = d.planta || '';
                    document.getElementById('cab_po').value = d.po || '';
                    document.getElementById('cab_etd').value = d.etd || '';
                    document.getElementById('cab_eta').value = d.eta || '';
                    document.getElementById('cab_eta_yapp').value = d.eta_yapp || '';
                    document.getElementById('cab_pickup').value = d.pickup || '';
                    // Planta → Importer (final 1 = Gravataí; senão Americana)
                    if (/1$/.test((d.planta || '').trim())) document.getElementById('sel_importer').value = 'gravatai';
                    else if (d.planta) document.getElementById('sel_importer').value = 'americana';
                    aplicarImporter();

                    const avisos = [];
                    if (!d.tem_follow) avisos.push('Esse processo ainda não está no Follow — ETD/ETA/ETA YAPP e Pick up ficam em branco (dá pra digitar).');
                    if (!d.tem_parametros) avisos.push('A tabela de Parâmetros da Importação ainda não existe no banco.');
                    document.getElementById('avisos').innerHTML = avisos.map(a => '<div class="alert alert-warning py-2">' + esc(a) + '</div>').join('');

                    const corpo = document.getElementById('corpo-release');
                    corpo.innerHTML = '';
                    (d.itens || []).forEach((it, i) => {
                        const tr = document.createElement('tr');
                        const inp = (campo, valor, falta) => `<td class="num"><input class="f-${campo}${falta ? ' falta-param' : ''}" value="${esc(valor)}" oninput="recalcular()"></td>`;
                        tr.innerHTML = `
                            <td>${i + 1}</td>
                            <td><input class="f-desc" value="${esc(it.desc)}"></td>
                            <td><input class="f-sap" value="${esc(it.sap)}"></td>
                            ${inp('unit_pack', it.unit_pack, it.unit_pack === '')}
                            ${inp('qtd', it.qtd, false)}
                            <td class="num calc c-pack"></td>
                            ${inp('u_price', it.u_price, it.u_price === '')}
                            <td class="num calc c-tprice"></td>
                            ${inp('length', it.length, it.length === '')}
                            ${inp('width', it.width, it.width === '')}
                            ${inp('height', it.height, it.height === '')}
                            <td class="num calc c-cbm"></td>
                            ${inp('peso', it.peso, it.peso === '')}
                            <td class="num calc c-nweight"></td>
                            ${inp('g_weight', it.g_weight, false)}
                        `;
                        corpo.appendChild(tr);
                    });
                    if (!(d.itens || []).length) {
                        corpo.innerHTML = '<tr><td colspan="15" class="text-center text-muted py-4">Nenhum item nesse processo.</td></tr>';
                    }
                    recalcular();
                })
                .catch(() => alert('Erro de conexão ao buscar o processo.'));
        }

        // Lê uma linha da tabela e devolve valores + cálculos (mesmas fórmulas da planilha)
        function lerLinha(tr) {
            const v = {};
            CAMPOS_EDIT.forEach(c => { v[c] = parseBr(tr.querySelector('.f-' + c).value); });
            v.desc = tr.querySelector('.f-desc').value;
            v.sap = tr.querySelector('.f-sap').value;
            v.pack = (v.qtd !== null && v.unit_pack) ? v.qtd / v.unit_pack : null;
            v.tprice = (v.qtd !== null && v.u_price !== null) ? v.qtd * v.u_price : null;
            v.cbm = (v.pack !== null && v.length !== null && v.width !== null && v.height !== null) ? v.pack * v.length * v.width * v.height / 1000000 : null;
            v.nweight = (v.qtd !== null && v.peso !== null) ? v.qtd * v.peso : null;
            return v;
        }

        function linhasRelease() {
            return Array.from(document.querySelectorAll('#corpo-release tr')).filter(tr => tr.querySelector('.f-qtd'));
        }

        function recalcular() {
            const tot = { qtd: 0, pack: 0, tprice: 0, cbm: 0, nweight: 0, g_weight: 0 };
            linhasRelease().forEach(tr => {
                const v = lerLinha(tr);
                tr.querySelector('.c-pack').textContent = fmt(v.pack, 2);
                tr.querySelector('.c-tprice').textContent = fmt(v.tprice, 2);
                tr.querySelector('.c-cbm').textContent = fmt(v.cbm, 3);
                tr.querySelector('.c-nweight').textContent = fmt(v.nweight, 2);
                Object.keys(tot).forEach(k => { if (v[k] !== null) tot[k] += v[k]; });
            });
            document.getElementById('tot_qtd').textContent = fmt(tot.qtd, 2);
            document.getElementById('tot_pack').textContent = fmt(tot.pack, 2);
            document.getElementById('tot_tprice').textContent = fmt(tot.tprice, 2);
            document.getElementById('tot_cbm').textContent = fmt(tot.cbm, 3);
            document.getElementById('tot_nweight').textContent = fmt(tot.nweight, 2);
            document.getElementById('tot_gweight').textContent = fmt(tot.g_weight, 2);
        }

        // ---------- Exportar .xlsx (com fórmulas, igual à planilha) ----------
        function exportarRelease() {
            if (typeof ExcelJS === 'undefined') { alert('Biblioteca de planilha não carregou. Recarregue a página.'); return; }
            const linhas = linhasRelease();
            if (!linhas.length) { alert('Escolha um processo com itens antes de exportar.'); return; }
            const processo = document.getElementById('sel_processo').value;
            const projeto = document.getElementById('sel_projeto').value;

            const wb = new ExcelJS.Workbook();
            const ws = wb.addWorksheet((projeto || 'Release').substring(0, 31));
            ws.columns = [5, 30, 13, 10, 11, 9, 10, 12, 9, 9, 9, 11, 12, 12, 12].map(w => ({ width: w }));
            const azul = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF12304A' } };
            const cinza = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFF3F6F9' } };
            const borda = { top: { style: 'thin' }, left: { style: 'thin' }, bottom: { style: 'thin' }, right: { style: 'thin' } };
            const comoData = t => { const d = lerData(t); return d ? new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate())) : (t || ''); };

            ws.mergeCells('A1:O1');
            ws.getCell('A1').value = 'RELEASE' + (projeto ? ' - ' + projeto : '') + ' - ' + processo;
            ws.getCell('A1').font = { bold: true, size: 13, color: { argb: 'FFFFFFFF' } };
            ws.getCell('A1').fill = azul;
            ws.getCell('A1').alignment = { horizontal: 'center' };

            const cab = [
                ['PLANTA', document.getElementById('cab_planta').value],
                ['PROCESSO', processo],
                ['PO', document.getElementById('cab_po').value],
                ['PICK UP', comoData(document.getElementById('cab_pickup').value)],
                ['ETD', comoData(document.getElementById('cab_etd').value)],
                ['ETA', comoData(document.getElementById('cab_eta').value)],
                ['ETA YAPP', comoData(document.getElementById('cab_eta_yapp').value)],
            ];
            cab.forEach((c, i) => {
                const r = ws.getRow(2 + i);
                r.getCell(2).value = c[0];
                r.getCell(2).font = { bold: true };
                r.getCell(3).value = c[1];
                if (c[1] instanceof Date) r.getCell(3).numFmt = 'dd/mm/yyyy';
                r.getCell(3).alignment = { horizontal: 'left' };
            });

            const linhaCab = 10;
            const titulos = ['#', 'DESCRIPTION', 'SAP', 'unit pack', 'QTY', 'PACK', 'U PRICE', 'T PRICE', 'LENGTH', 'WIDTH', 'HEIGHT', 'CBM', 'weight of component (kg)', 'N WEIGHT', 'G WEIGHT'];
            const rCab = ws.getRow(linhaCab);
            titulos.forEach((t, i) => {
                const c = rCab.getCell(i + 1);
                c.value = t; c.font = { bold: true }; c.fill = cinza; c.border = borda;
                c.alignment = { horizontal: 'center', vertical: 'middle', wrapText: true };
            });
            rCab.height = 30;

            const primeira = linhaCab + 1;
            linhas.forEach((tr, i) => {
                const v = lerLinha(tr);
                const n = primeira + i;
                const r = ws.getRow(n);
                const sapNum = /^\d+$/.test(v.sap.trim()) ? Number(v.sap.trim()) : v.sap;
                r.values = [i + 1, v.desc, sapNum, v.unit_pack, v.qtd, null, v.u_price, null, v.length, v.width, v.height, null, v.peso, null, v.g_weight];
                r.getCell(6).value = { formula: `IF(AND(ISNUMBER(D${n}),D${n}<>0),E${n}/D${n},"")`, result: v.pack ?? '' };
                r.getCell(8).value = { formula: `IF(ISNUMBER(G${n}),E${n}*G${n},"")`, result: v.tprice ?? '' };
                r.getCell(12).value = { formula: `IF(AND(ISNUMBER(F${n}),ISNUMBER(I${n}),ISNUMBER(J${n}),ISNUMBER(K${n})),F${n}*I${n}*J${n}*K${n}/1000000,"")`, result: v.cbm ?? '' };
                r.getCell(14).value = { formula: `IF(ISNUMBER(M${n}),E${n}*M${n},"")`, result: v.nweight ?? '' };
                for (let c = 1; c <= 15; c++) r.getCell(c).border = borda;
                r.getCell(6).numFmt = '#,##0.00';
                r.getCell(7).numFmt = '#,##0.00##';
                r.getCell(8).numFmt = '#,##0.00';
                r.getCell(12).numFmt = '#,##0.000';
                r.getCell(14).numFmt = '#,##0.00';
                r.getCell(15).numFmt = '#,##0.00';
            });

            const ultima = primeira + linhas.length - 1;
            const nt = ultima + 1;
            const rt = ws.getRow(nt);
            rt.getCell(2).value = 'Total';
            [5, 6, 8, 12, 14, 15].forEach(c => {
                const col = String.fromCharCode(64 + c);
                rt.getCell(c).value = { formula: `SUM(${col}${primeira}:${col}${ultima})` };
                rt.getCell(c).numFmt = c === 12 ? '#,##0.000' : '#,##0.00';
            });
            for (let c = 1; c <= 15; c++) { rt.getCell(c).font = { bold: true }; rt.getCell(c).fill = cinza; rt.getCell(c).border = borda; }

            const ni = nt + 2;
            ws.mergeCells(`B${ni}:H${ni + 4}`);
            const ci = ws.getCell(`B${ni}`);
            ci.value = document.getElementById('importer_texto').value;
            ci.alignment = { wrapText: true, vertical: 'top' };

            wb.xlsx.writeBuffer().then(buf => {
                const blob = new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = ('Release_' + (projeto ? projeto + '_' : '') + processo + '.xlsx').replace(/\s+/g, '_');
                document.body.appendChild(a); a.click(); a.remove();
                URL.revokeObjectURL(a.href);
            }).catch(() => alert('Erro ao gerar a planilha.'));
        }

        aplicarImporter();
    </script>
</body>
</html>
