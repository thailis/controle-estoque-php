<?php
// importacao/packing_list.php
// Mesma lógica do commercial_invoice.php (e do Pedido de Compra do MRP):
// nada é salvo no banco, é uma folha formatada pra preencher na tela e
// exportar em PDF via impressão do navegador. Part Number / Material
// Description / Qty são puxados do Processo selecionado (tabela `processos`
// do banco da Importação) e o Supplier usa a mesma lista de filiais do
// Pedido de Compra do MRP (banco do MRP).

require_once 'conexao.php';

// Ajax: busca os componentes cadastrados em Processos pra um processo.
if (isset($_GET['ajax']) && $_GET['ajax'] === 'buscar_processo') {
    header('Content-Type: application/json; charset=utf-8');
    $processo = trim($_GET['processo'] ?? '');
    $itens = [];
    if ($processo !== '') {
        $stmt = mysqli_prepare($conn, "SELECT codigo_componente, descricao, quantidade FROM processos WHERE processo = ? ORDER BY id");
        mysqli_stmt_bind_param($stmt, 's', $processo);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($linha = mysqli_fetch_assoc($res)) {
            $itens[] = [
                'part' => (string) ($linha['codigo_componente'] ?? ''),
                'desc' => (string) ($linha['descricao'] ?? ''),
                'qtd'  => $linha['quantidade'] !== null ? number_format((float) $linha['quantidade'], 0, ',', '') : '',
            ];
        }
        mysqli_stmt_close($stmt);
    }
    echo json_encode(['itens' => $itens]);
    exit;
}

// Lista de processos existentes, pro dropdown.
$processosDisponiveis = [];
$resProc = mysqli_query($conn, "SELECT DISTINCT processo FROM processos WHERE processo IS NOT NULL AND processo <> '' ORDER BY processo");
if ($resProc) {
    while ($linha = mysqli_fetch_assoc($resProc)) {
        $processosDisponiveis[] = $linha['processo'];
    }
}

// Lista de filiais do MRP (mesma lista do Pedido de Compra: Americana e Gravataí).
$filiaisDisponiveis = [];
try {
    $connMrp = mysqli_init();
    mysqli_ssl_set($connMrp, null, null, getenv('MRP_DB_SSL_CA') ?: null, null, null);
    mysqli_real_connect(
        $connMrp,
        getenv('MRP_DB_HOST'),
        getenv('MRP_DB_USER'),
        getenv('MRP_DB_PASSWORD'),
        getenv('MRP_DB_NAME'),
        (int) (getenv('MRP_DB_PORT') ?: 4000),
        null,
        MYSQLI_CLIENT_SSL
    );
    $resFil = mysqli_query($connMrp, "SELECT nome, razao_social, cnpj, endereco, cep, email FROM filiais ORDER BY nome");
    if ($resFil) {
        while ($linha = mysqli_fetch_assoc($resFil)) {
            $filiaisDisponiveis[] = $linha;
        }
    }
    mysqli_close($connMrp);
} catch (Throwable $e) {
    $filiaisDisponiveis = [];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Packing List</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/dashboard.css" rel="stylesheet">
    <style>
        :root {
            --navy: #12304a;
            --destaque: #c0504d;
            --borda: #dce4ec;
            --cinza-label: #f3f6f9;
        }
        body {
            background: #eef1f5;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            font-size: 13px;
            color: #17212b;
        }

        .doc-sheet {
            max-width: 1050px;
            margin: 16px auto 40px;
            background: #fff;
            border: 1px solid var(--borda);
            box-shadow: 0 6px 20px rgba(18,48,74,.10);
        }

        .doc-header {
            background: var(--navy);
            color: #fff;
            text-align: center;
            padding: 10px 16px;
            font-size: 1.05rem;
            font-weight: 700;
        }
        .doc-header select {
            background: var(--navy);
            border: 1px solid #4a6178;
            color: #fff;
            font-weight: 750;
            text-align: center;
            text-align-last: center;
            max-width: 260px;
            font-size: .95rem;
            padding: 2px 6px;
            border-radius: 3px;
        }
        .doc-header select option { color: #17212b; background: #fff; }

        .doc-secao-titulo {
            font-weight: 750;
            padding: 8px 16px 2px;
            font-size: .95rem;
        }

        .doc-seletor-wrap {
            padding: 10px 16px 0;
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
        }
        .doc-seletor-wrap label {
            font-weight: 700;
            font-size: .78rem;
            color: #3c4c5c;
            display: block;
            margin-bottom: 2px;
        }
        .doc-seletor-wrap select {
            min-width: 220px;
        }

        table.doc-info { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.doc-info td { padding: 1px 6px; vertical-align: top; font-size: .85rem; overflow: hidden; }
        table.doc-info td.lbl { font-weight: 700; font-size: .78rem; color: #3c4c5c; width: 110px; white-space: nowrap; }
        table.doc-info input {
            border: none;
            border-bottom: 1px solid transparent;
            background: transparent;
            width: 100%;
            padding: 1px 2px;
            font-size: .85rem;
            box-sizing: border-box;
        }
        table.doc-info input:focus {
            outline: none;
            border-bottom: 1px solid var(--navy);
            background: #f7faff;
        }
        .doc-info-wrap { padding: 4px 16px 10px; }
        .doc-hr { border: none; border-top: 1px solid var(--borda); margin: 6px 16px; }

        table.doc-outer { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.doc-outer td { vertical-align: top; padding: 0; overflow: hidden; }
        table.doc-outer td.doc-logo-cell { width: 130px; text-align: right; padding-right: 4px; }
        .doc-logo-cell img { max-height: 55px; }

        .doc-material-titulo {
            text-align: center;
            font-weight: 750;
            background: var(--cinza-label);
            border-top: 1px solid var(--borda);
            border-bottom: 1px solid var(--borda);
            padding: 6px;
            font-size: .9rem;
        }
        table.doc-tabela { width: 100%; border-collapse: collapse; font-size: .74rem; table-layout: fixed; }
        table.doc-tabela th {
            background: var(--cinza-label);
            padding: 6px 4px;
            font-size: .64rem;
            text-transform: uppercase;
            letter-spacing: .01em;
            border-bottom: 1px solid var(--borda);
            text-align: center;
            line-height: 1.15;
        }
        table.doc-tabela td { padding: 4px 5px; border-bottom: 1px solid #eef1f5; vertical-align: middle; }
        table.doc-tabela tbody tr:nth-child(even) { background: #f9fbfd; }
        table.doc-tabela input {
            border: none;
            background: transparent;
            width: 100%;
            padding: 2px 2px;
            font-size: .74rem;
        }
        table.doc-tabela input:focus { outline: none; background: #f0f6ff; }
        table.doc-tabela .num { text-align: right; }
        table.doc-tabela .num input { text-align: right; }
        table.doc-tabela .center { text-align: center; }
        table.doc-tabela .center input { text-align: center; }
        table.doc-tabela .col-idx { width: 22px; text-align: center; color: #66788a; }
        table.doc-tabela .col-acao { width: 26px; text-align: center; }
        table.doc-tabela .btn-remover-linha {
            border: none; background: none; color: #c53535; font-size: .95rem; cursor: pointer; line-height: 1;
        }
        table.doc-tabela .sub-dim { display: flex; gap: 2px; }
        table.doc-tabela .sub-dim input { width: 32px; text-align: center; padding: 2px 1px; }

        /* Células puxadas do Processo — só editáveis com duplo clique. */
        table.doc-tabela .celula-pull {
            cursor: pointer;
            padding: 4px 5px;
        }
        table.doc-tabela .celula-pull:hover { background: #f0f6ff; }
        table.doc-tabela .celula-pull input {
            border: none; background: transparent; width: 100%; padding: 0; font-size: .74rem;
        }
        table.doc-tabela .celula-pull input:focus { outline: none; }

        .doc-pagamento-titulo {
            text-align: center;
            font-weight: 750;
            background: var(--navy);
            color: #fff;
            padding: 6px;
            font-size: .9rem;
        }
        table.doc-totais { width: 100%; border-collapse: collapse; }
        table.doc-totais td { padding: 8px 16px; vertical-align: top; }
        .doc-totais-linha { display: table; width: 100%; table-layout: fixed; margin-bottom: 2px; }
        .doc-totais-linha .lado-a, .doc-totais-linha .lado-b { display: table-cell; vertical-align: middle; }
        .doc-totais-linha .lado-a { width: 55%; text-align: right; font-weight: 700; font-size: .78rem; color: #3c4c5c; padding-right: 6px; }
        .doc-totais-linha input {
            border: none; border-bottom: 1px solid transparent; background: transparent;
            text-align: right; font-size: .85rem; padding: 1px 2px; width: 100%;
        }
        .doc-totais-linha input:focus { outline: none; border-bottom: 1px solid var(--navy); background: #f7faff; }
        .doc-total-final .lado-a { font-weight: 750; font-size: .95rem; }
        .doc-total-final input { font-weight: 750; font-size: 1rem; }

        table.doc-rodape { width: 100%; border-collapse: collapse; border-top: 1px solid var(--navy); }
        table.doc-rodape td { padding: 10px 16px; vertical-align: top; }
        table.doc-rodape td.col-payment { width: 33%; border-right: 1px solid var(--borda); }
        table.doc-rodape td.col-incoterms { width: 17%; border-right: 1px solid var(--borda); }
        table.doc-rodape td.col-adicional { width: 50%; }
        .doc-rodape-linha { display: table; width: 100%; table-layout: fixed; margin-bottom: 4px; }
        .doc-rodape-linha .lado-a { display: table-cell; width: 110px; font-weight: 700; font-size: .78rem; color: #3c4c5c; vertical-align: middle; }
        .doc-rodape-linha .lado-b { display: table-cell; vertical-align: middle; }
        .doc-rodape-linha input {
            border: none; border-bottom: 1px solid transparent; background: transparent; width: 100%; font-size: .82rem; padding: 1px 2px;
        }
        .doc-rodape-linha input:focus { outline: none; border-bottom: 1px solid var(--navy); background: #f7faff; }
        .doc-rodape textarea {
            border: none; background: transparent; width: 100%; font-size: .82rem; padding: 1px 2px; resize: vertical; min-height: 60px;
        }
        .doc-rodape textarea:focus { outline: none; background: #f7faff; }
        .doc-rodape-label { font-weight: 700; font-size: .78rem; color: #3c4c5c; margin-bottom: 4px; }

        .doc-remarks { padding: 14px 16px 0; }
        .doc-remarks-titulo { font-weight: 700; font-size: .85rem; color: #3c4c5c; margin-bottom: 4px; }
        .doc-remarks textarea {
            border: 1px solid var(--borda); background: transparent; width: 100%; min-height: 50px; font-size: .82rem; padding: 6px; resize: vertical;
        }
        .doc-remarks textarea:focus { outline: none; border-color: var(--navy); background: #f7faff; }

        .doc-cargo { padding: 14px 16px 0; }
        .doc-cargo-titulo { font-weight: 700; font-size: .85rem; color: #3c4c5c; margin-bottom: 4px; }
        table.doc-cargo-tabela { width: 100%; border-collapse: collapse; max-width: 420px; }
        table.doc-cargo-tabela td { padding: 1px 4px; vertical-align: middle; }
        table.doc-cargo-tabela td.lbl { font-weight: 700; font-size: .78rem; color: #3c4c5c; width: 150px; white-space: nowrap; }
        table.doc-cargo-tabela input {
            border: none; border-bottom: 1px solid transparent; background: transparent; width: 100%; font-size: .82rem; padding: 1px 2px;
        }
        table.doc-cargo-tabela input:focus { outline: none; border-bottom: 1px solid var(--navy); background: #f7faff; }

        .doc-assinatura { text-align: center; padding: 40px 16px 24px; }
        .doc-assinatura input {
            border: none; background: transparent; text-align: center;
            width: 260px; padding-top: 4px; font-size: .85rem;
        }
        .doc-assinatura input:focus { outline: none; background: #f7faff; }
        .doc-assinatura .nome { font-weight: 700; border-top: 1px solid #333; }
        .doc-assinatura .depto, .doc-assinatura .data { display: block; margin: 2px auto 0; padding-top: 4px; font-size: .8rem; }

        .btn-add-linha { font-size: .78rem; }

        @media print {
            body { background: #fff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            .no-print { display: none !important; }
            .doc-sheet { box-shadow: none; border: none; margin: 0; max-width: 100%; }
            table.doc-tabela .col-acao { display: none; }
            input, textarea { color: #000 !important; }
        }
    </style>
</head>
<body>
    <header class="topbar no-print">
        <div class="container-fluid dashboard-container d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="eyebrow">Controle de Importação • Documentos</span>
                <h1>Packing List</h1>
                <p class="mb-0">Preencha os dados e gere o PDF direto pelo navegador</p>
            </div>
            <nav class="d-flex flex-wrap gap-2" aria-label="Ações do sistema">
                <a class="btn btn-outline-light btn-sm" href="follow.php">Follow</a>
                <a class="btn btn-outline-light btn-sm" href="processos.php">Processos</a>
                <a class="btn btn-outline-light btn-sm" href="pagamento.php">Pagamento</a>
                <a class="btn btn-outline-light btn-sm" href="confirmar_entrega.php">Confirmar entrega</a>
                <a class="btn btn-outline-light btn-sm" href="commercial_invoice.php">📄 Commercial Invoice</a>
                <a class="btn btn-light btn-sm" href="packing_list.php">📦 Packing List</a>
                <button type="button" class="btn btn-warning btn-sm" onclick="window.print()">⬇️ Baixar PDF</button>
            </nav>
        </div>
    </header>

    <div class="doc-sheet" id="doc-sheet">
        <div class="doc-header">Packing List:
            <select id="select_processo" onchange="carregarProcesso()">
                <option value="">Selecione um processo...</option>
                <?php foreach ($processosDisponiveis as $p): ?>
                    <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="doc-seletor-wrap no-print">
            <div>
                <label for="select_filial">Supplier (filial YAPP)</label>
                <select id="select_filial" class="form-select form-select-sm" onchange="aplicarFilial()">
                    <option value="">Selecione a filial…</option>
                    <?php foreach ($filiaisDisponiveis as $f): ?>
                        <option value="<?php echo htmlspecialchars($f['nome']); ?>"
                            data-nome="<?php echo htmlspecialchars($f['razao_social'] ?: $f['nome']); ?>"
                            data-cnpj="<?php echo htmlspecialchars($f['cnpj'] ?? ''); ?>"
                            data-endereco="<?php echo htmlspecialchars($f['endereco'] ?? ''); ?>"
                            data-cep="<?php echo htmlspecialchars($f['cep'] ?? ''); ?>"
                            data-email="<?php echo htmlspecialchars($f['email'] ?? ''); ?>">
                            <?php echo htmlspecialchars($f['nome']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="doc-info-wrap">
            <table class="doc-outer">
                <tr>
                    <td>
                        <div class="doc-secao-titulo" style="padding-left:0;">EXPORTER</div>
                        <table class="doc-info">
                            <tr><td class="lbl">Supplier:</td><td><input type="text" id="exp_supplier" placeholder="Razão social do fornecedor"></td></tr>
                            <tr><td class="lbl">CNPJ:</td><td><input type="text" id="exp_cnpj" placeholder="00.000.000/0000-00"></td></tr>
                            <tr><td class="lbl">Address:</td><td><input type="text" id="exp_address" placeholder="Endereço"></td></tr>
                            <tr><td class="lbl">CEP:</td><td><input type="text" id="exp_cep" placeholder="00.000-000"></td></tr>
                            <tr><td class="lbl">Contact Person:</td><td><input type="text" id="exp_contato" value="Maria Lima"></td></tr>
                            <tr><td class="lbl">E-mail:</td><td><input type="text" id="exp_email" placeholder="email@fornecedor.com"></td></tr>
                        </table>
                    </td>
                    <td class="doc-logo-cell">
                        <img src="assets/logo_yapp.jpeg" alt="Logo" onerror="this.style.display='none'">
                    </td>
                </tr>
            </table>
        </div>

        <hr class="doc-hr">

        <div class="doc-info-wrap">
            <div class="doc-secao-titulo" style="padding-left:0;">IMPORTER</div>
            <table class="doc-info">
                <tr><td class="lbl">TAX ID:</td><td><input type="text" id="imp_taxid" placeholder="00.000.000/0000-00"></td></tr>
                <tr><td class="lbl">Address:</td><td><input type="text" id="imp_address" placeholder="Endereço"></td></tr>
                <tr><td class="lbl">CEP:</td><td><input type="text" id="imp_cep" placeholder="00.000-000"></td></tr>
                <tr><td class="lbl">Contact Person:</td><td><input type="text" id="imp_contato" placeholder="Nome do contato comercial"></td></tr>
                <tr><td class="lbl">E-mail:</td><td><input type="text" id="imp_email" placeholder="email@empresa.com"></td></tr>
                <tr><td class="lbl">Phone:</td><td><input type="text" id="imp_phone" placeholder="+55 00 00000-0000"></td></tr>
            </table>
        </div>

        <div class="doc-material-titulo">Material Details</div>
        <table class="doc-tabela" id="tabela-itens">
            <colgroup>
                <col style="width:3%"><col style="width:11%"><col style="width:20%">
                <col style="width:6%"><col style="width:10%"><col style="width:10%">
                <col style="width:10%"><col style="width:8%"><col style="width:16%"><col style="width:6%">
            </colgroup>
            <thead>
                <tr>
                    <th class="col-idx">#</th>
                    <th>Part Number</th>
                    <th>Material Description</th>
                    <th>Qty</th>
                    <th>Unit Net<br>Weight (kg)</th>
                    <th>Total Net<br>Weight (kg)</th>
                    <th>Total Gross<br>Weight (kg)</th>
                    <th>Wood Box</th>
                    <th>Dimensions (cm)<br>L / W / H</th>
                    <th class="col-acao no-print"></th>
                </tr>
            </thead>
            <tbody id="corpo-itens"></tbody>
        </table>
        <div class="p-2 no-print">
            <button type="button" class="btn btn-outline-secondary btn-add-linha" onclick="adicionarLinha()">➕ Adicionar linha</button>
        </div>

        <div class="doc-pagamento-titulo">Freight and Payment Terms</div>
        <table class="doc-totais">
            <tr>
                <td style="width:50%"></td>
                <td style="width:50%">
                    <div class="doc-totais-linha"><span class="lado-a">Currency:</span><span class="lado-b"><input type="text" id="moeda" value="USD"></span></div>
                    <div class="doc-totais-linha"><span class="lado-a">Net Price:</span><span class="lado-b"><input type="text" id="valor_net" value="0,00"></span></div>
                    <div class="doc-totais-linha"><span class="lado-a">Freight Price:</span><span class="lado-b"><input type="text" id="valor_frete"></span></div>
                    <div class="doc-totais-linha"><span class="lado-a">Insurance Price:</span><span class="lado-b"><input type="text" id="valor_seguro"></span></div>
                    <div class="doc-totais-linha doc-total-final"><span class="lado-a">Total:</span><span class="lado-b"><input type="text" id="valor_total" value="0,00"></span></div>
                </td>
            </tr>
        </table>

        <table class="doc-rodape">
            <tr>
                <td class="col-payment">
                    <div class="doc-rodape-label">Payment Terms:</div>
                    <div class="doc-rodape-linha"><span class="lado-a"></span><span class="lado-b"><input type="text" id="rodape_payment"></span></div>
                    <div class="doc-rodape-linha"><span class="lado-a">Freight Forwarder:</span><span class="lado-b"><input type="text" id="rodape_ffw"></span></div>
                    <div class="doc-rodape-linha"><span class="lado-a">Pick Up Adress:</span><span class="lado-b"><input type="text" id="rodape_pickup"></span></div>
                </td>
                <td class="col-incoterms">
                    <div class="doc-rodape-label">Incoterms:</div>
                    <input type="text" id="rodape_incoterms">
                </td>
                <td class="col-adicional">
                    <div class="doc-rodape-label">Aditional Information:</div>
                    <textarea id="rodape_adicional"></textarea>
                </td>
            </tr>
        </table>

        <div class="doc-remarks">
            <div class="doc-remarks-titulo">Remarks</div>
            <textarea id="remarks"></textarea>
        </div>

        <div class="doc-cargo">
            <div class="doc-cargo-titulo">Cargo summary:</div>
            <table class="doc-cargo-tabela">
                <tr><td class="lbl">Volume:</td><td><input type="text" id="cargo_volume"></td></tr>
                <tr><td class="lbl">CBM:</td><td><input type="text" id="cargo_cbm"></td></tr>
                <tr><td class="lbl">Net Weight (kg):</td><td><input type="text" id="cargo_net_weight" readonly></td></tr>
                <tr><td class="lbl">Gross Weight (kg):</td><td><input type="text" id="cargo_gross_weight" readonly></td></tr>
                <tr><td class="lbl">Dimensions (cm):</td><td><input type="text" id="cargo_dimensoes"></td></tr>
            </table>
            <p class="mt-1 mb-0 no-print" style="font-size:.72rem; color:var(--muted);">Net Weight e Gross Weight são somados automaticamente a partir das linhas de material.</p>
        </div>

        <div class="doc-assinatura">
            <input type="text" id="assinatura_nome" class="nome" value="Maria Lima">
            <input type="text" id="assinatura_depto" class="depto" placeholder="Supply Chain Department" value="Supply Chain Department">
            <input type="text" id="assinatura_data" class="data" value="<?php echo date('d/m/Y'); ?>">
        </div>
    </div>

    <script>
        let contadorLinhas = 0;

        function parseNumeroBrDoc(texto) {
            if (!texto) return 0;
            texto = String(texto).trim();
            if (texto.includes(',')) {
                texto = texto.replace(/\./g, '').replace(',', '.');
            }
            const n = parseFloat(texto);
            return isNaN(n) ? 0 : n;
        }

        function formatarNumeroBrDoc(n) {
            return n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        // Torna uma célula editável só com duplo clique (usada nos campos
        // puxados automaticamente do Processo: Part Number, Descrição, Qty).
        function tornarPull(celula, aoSalvar) {
            celula.classList.add('celula-pull');
            celula.addEventListener('dblclick', function () {
                if (celula.querySelector('input')) return;
                const valorAtual = celula.dataset.valor || '';
                celula.textContent = '';
                const input = document.createElement('input');
                input.type = 'text';
                input.value = valorAtual;
                celula.appendChild(input);
                input.focus();
                input.select();
                let resolvido = false;
                function commit() {
                    if (resolvido) return;
                    resolvido = true;
                    const novo = input.value.trim();
                    definirValorPull(celula, novo);
                    if (aoSalvar) aoSalvar(novo);
                }
                input.addEventListener('blur', commit);
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        input.blur();
                    } else if (e.key === 'Escape') {
                        e.preventDefault();
                        resolvido = true;
                        definirValorPull(celula, valorAtual);
                    }
                });
            });
        }

        function definirValorPull(celula, valor) {
            celula.dataset.valor = valor;
            celula.textContent = valor === '' ? '—' : valor;
        }

        function aplicarFilial() {
            const select = document.getElementById('select_filial');
            const opcao = select.options[select.selectedIndex];
            if (!opcao || opcao.value === '') return;
            document.getElementById('exp_supplier').value = opcao.dataset.nome || '';
            document.getElementById('exp_cnpj').value = opcao.dataset.cnpj || '';
            document.getElementById('exp_address').value = opcao.dataset.endereco || '';
            document.getElementById('exp_cep').value = opcao.dataset.cep || '';
            document.getElementById('exp_email').value = opcao.dataset.email || '';
            // Independente da filial/CNPJ escolhido, o responsável padrão é a Maria Lima
            // (mas pode ser editado depois, se precisar).
            document.getElementById('exp_contato').value = 'Maria Lima';
        }

        function carregarProcesso() {
            const processo = document.getElementById('select_processo').value;
            if (!processo) return;
            fetch('packing_list.php?ajax=buscar_processo&processo=' + encodeURIComponent(processo))
                .then(r => r.json())
                .then(dados => {
                    document.getElementById('corpo-itens').innerHTML = '';
                    contadorLinhas = 0;
                    if (dados.itens && dados.itens.length) {
                        dados.itens.forEach(item => adicionarLinha(item));
                    } else {
                        adicionarLinha();
                        alert('Esse processo não tem componentes cadastrados em Processos ainda.');
                    }
                })
                .catch(() => alert('Erro de conexão ao buscar os componentes desse processo.'));
        }

        function adicionarLinha(item) {
            contadorLinhas++;
            const idx = contadorLinhas;
            const tr = document.createElement('tr');
            tr.id = 'linha-' + idx;
            tr.innerHTML = `
                <td class="col-idx">${idx}</td>
                <td class="td-part"></td>
                <td class="td-desc"></td>
                <td class="num td-qtd"></td>
                <td class="num"><input type="text" class="f-peso-unit" placeholder="0,00" oninput="recalcularLinha(${idx})"></td>
                <td class="num"><input type="text" class="f-peso-net-total" readonly></td>
                <td class="num"><input type="text" class="f-peso-bruto-total" oninput="recalcularCargo()"></td>
                <td class="center"><input type="text" class="f-wood-box" placeholder="Sim/Não"></td>
                <td class="center">
                    <div class="sub-dim">
                        <input type="text" class="f-dim-l" placeholder="L">
                        <input type="text" class="f-dim-w" placeholder="W">
                        <input type="text" class="f-dim-h" placeholder="H">
                    </div>
                </td>
                <td class="col-acao no-print"><button type="button" class="btn-remover-linha" onclick="removerLinha(${idx})" title="Remover linha">✕</button></td>
            `;
            document.getElementById('corpo-itens').appendChild(tr);

            const celPart = tr.querySelector('.td-part');
            const celDesc = tr.querySelector('.td-desc');
            const celQtd = tr.querySelector('.td-qtd');
            definirValorPull(celPart, item && item.part ? item.part : '');
            definirValorPull(celDesc, item && item.desc ? item.desc : '');
            definirValorPull(celQtd, item && item.qtd ? item.qtd : '');
            tornarPull(celPart);
            tornarPull(celDesc);
            tornarPull(celQtd, () => recalcularLinha(idx));

            if (item) recalcularLinha(idx);
        }

        function removerLinha(idx) {
            const linha = document.getElementById('linha-' + idx);
            if (linha) linha.remove();
            renumerarLinhas();
            recalcularCargo();
        }

        function renumerarLinhas() {
            const linhas = document.querySelectorAll('#corpo-itens tr');
            linhas.forEach((linha, i) => {
                linha.querySelector('.col-idx').textContent = i + 1;
            });
        }

        // Total Net Weight (por linha) = Qty × Unit Net Weight.
        function recalcularLinha(idx) {
            const linha = document.getElementById('linha-' + idx);
            if (!linha) return;
            const qtd = parseNumeroBrDoc(linha.querySelector('.td-qtd').dataset.valor || '');
            const pesoUnit = parseNumeroBrDoc(linha.querySelector('.f-peso-unit').value);
            linha.querySelector('.f-peso-net-total').value = formatarNumeroBrDoc(qtd * pesoUnit);
            recalcularCargo();
        }

        // Cargo summary → Net Weight e Gross Weight somam as colunas
        // "Total Net Weight" e "Total Gross Weight" de todas as linhas.
        function recalcularCargo() {
            let netTotal = 0;
            let brutoTotal = 0;
            document.querySelectorAll('#corpo-itens tr').forEach(linha => {
                netTotal += parseNumeroBrDoc(linha.querySelector('.f-peso-net-total').value);
                brutoTotal += parseNumeroBrDoc(linha.querySelector('.f-peso-bruto-total').value);
            });
            document.getElementById('cargo_net_weight').value = formatarNumeroBrDoc(netTotal);
            document.getElementById('cargo_gross_weight').value = formatarNumeroBrDoc(brutoTotal);
        }

        // Começa com 1 linha em branco — as demais vêm ao escolher um Processo.
        adicionarLinha();
        recalcularCargo();
    </script>
</body>
</html>
