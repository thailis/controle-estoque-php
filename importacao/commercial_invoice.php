<?php
// importacao/commercial_invoice.php
// Tela pra montar a Commercial Invoice no layout padrão da empresa e exportar
// em PDF. Mesma lógica do Pedido de Compra do MRP: nada é salvo no banco —
// é só uma "máquina de escrever" formatada, e o "Baixar PDF" usa a função de
// imprimir do navegador (window.print).
//
// Diferente do Pedido de Compra, aqui os itens (Part Number, Description,
// NCM, Qty) são puxados ao vivo da tabela "processos" (deste próprio banco,
// controle_importacao) ao escolher um Processo no dropdown — só o Net Price
// é digitado na mão. O Supplier é puxado da tabela "filiais" do banco do MRP
// (mesma lista usada no Pedido de Compra: YAPP Americana / YAPP Gravataí).
require_once 'conexao.php';

function h(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

// ---------- AJAX: busca os componentes cadastrados em Processos pra um
// código de processo específico ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'buscar_processo') {
    header('Content-Type: application/json; charset=utf-8');
    $processo = trim($_GET['processo'] ?? '');
    $itens = [];
    if ($processo !== '') {
        $stmt = mysqli_prepare($conn, "SELECT codigo_componente, descricao, ncm, quantidade FROM processos WHERE processo = ? ORDER BY id");
        mysqli_stmt_bind_param($stmt, 's', $processo);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($linha = mysqli_fetch_assoc($res)) {
            $itens[] = [
                'part' => (string) ($linha['codigo_componente'] ?? ''),
                'desc' => (string) ($linha['descricao'] ?? ''),
                'hscode' => (string) ($linha['ncm'] ?? ''),
                'qtd' => $linha['quantidade'] !== null ? number_format((float) $linha['quantidade'], 0, ',', '') : '',
            ];
        }
        mysqli_stmt_close($stmt);
    }
    echo json_encode(['itens' => $itens]);
    exit;
}

// Lista de processos existentes, pro dropdown que puxa os componentes.
$processosDisponiveis = [];
$resProcessos = mysqli_query($conn, "SELECT DISTINCT processo FROM processos ORDER BY processo");
while ($linha = mysqli_fetch_assoc($resProcessos)) {
    $processosDisponiveis[] = $linha['processo'];
}

// Segunda conexão, só de leitura, pra puxar a lista de filiais do banco do
// MRP (mesma tabela "filiais" usada no Pedido de Compra) — credenciais
// próprias (MRP_DB_*), igual ao confirmar_entrega.php. Se não conseguir
// conectar, o dropdown fica vazio e a tela continua funcionando normalmente
// (Supplier/CNPJ/Address/CEP podem sempre ser digitados na mão).
$filiaisDisponiveis = [];
try {
    $hostMrp = getenv('MRP_DB_HOST') ?: '';
    $portMrp = (int) (getenv('MRP_DB_PORT') ?: 4000);
    $dbnameMrp = getenv('MRP_DB_NAME') ?: 'controle_mrp';
    $userMrp = getenv('MRP_DB_USER') ?: '';
    $passwordMrp = getenv('MRP_DB_PASSWORD') ?: '';
    $sslCaMrp = getenv('MRP_DB_SSL_CA') ?: '/etc/ssl/certs/ca-certificates.crt';

    if ($hostMrp !== '' && $userMrp !== '' && $passwordMrp !== '') {
        $connMrp = mysqli_init();
        mysqli_options($connMrp, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
        mysqli_ssl_set($connMrp, null, null, $sslCaMrp, null, null);
        $conectouMrp = @mysqli_real_connect($connMrp, $hostMrp, $userMrp, $passwordMrp, $dbnameMrp, $portMrp, null, MYSQLI_CLIENT_SSL);
        if ($conectouMrp) {
            mysqli_set_charset($connMrp, 'utf8mb4');
            $resFiliais = mysqli_query($connMrp, "SELECT nome, razao_social, cnpj, endereco, cep, email FROM filiais ORDER BY nome");
            if ($resFiliais) {
                while ($linha = mysqli_fetch_assoc($resFiliais)) {
                    $filiaisDisponiveis[] = $linha;
                }
            }
            mysqli_close($connMrp);
        }
    }
} catch (Throwable $e) {
    // Falha silenciosa — dropdown fica vazio, os campos continuam editáveis na mão.
    $filiaisDisponiveis = [];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Commercial Invoice</title>
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
            max-width: 950px;
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
        .doc-header input {
            background: transparent;
            border: none;
            color: #fff;
            font-weight: 750;
            text-align: center;
            width: 160px;
            font-size: 1.05rem;
        }
        .doc-header input::placeholder { color: #aab5c0; }
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

        .doc-toolbar-select {
            padding: 10px 16px 2px;
        }
        .doc-toolbar-select label {
            font-size: .78rem;
            font-weight: 700;
            color: #3c4c5c;
            margin-bottom: 2px;
        }
        .doc-toolbar-select select {
            max-width: 320px;
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
        table.doc-tabela { width: 100%; border-collapse: collapse; font-size: .78rem; table-layout: fixed; }
        table.doc-tabela th {
            background: var(--cinza-label);
            padding: 6px 6px;
            font-size: .68rem;
            text-transform: uppercase;
            letter-spacing: .02em;
            border-bottom: 1px solid var(--borda);
            text-align: center;
        }
        table.doc-tabela td { padding: 4px 6px; border-bottom: 1px solid #eef1f5; vertical-align: middle; }
        table.doc-tabela tbody tr:nth-child(even) { background: #f9fbfd; }
        table.doc-tabela input {
            border: none;
            background: transparent;
            width: 100%;
            padding: 2px 3px;
            font-size: .78rem;
        }
        table.doc-tabela input:focus { outline: none; background: #f0f6ff; }
        table.doc-tabela .num { text-align: right; }
        table.doc-tabela .num input { text-align: right; }
        table.doc-tabela .center { text-align: center; }
        table.doc-tabela .center input { text-align: center; }
        table.doc-tabela .col-idx { width: 24px; text-align: center; color: #66788a; }
        table.doc-tabela .col-acao { width: 30px; text-align: center; }
        table.doc-tabela .btn-remover-linha {
            border: none; background: none; color: #c53535; font-size: .95rem; cursor: pointer; line-height: 1;
        }
        /* Células puxadas de Processos (Part Number, Description, HS Code/NCM,
           Qty) — mostram texto simples e só entram em modo de edição com
           DUPLO clique, pra evitar alteração sem querer ao rolar a tela. */
        table.doc-tabela .celula-pull {
            cursor: cell;
            min-height: 20px;
        }
        table.doc-tabela .celula-pull:hover {
            background: #eef4fb;
            box-shadow: inset 0 0 0 1px #b9d3ef;
        }
        table.doc-tabela .celula-pull input {
            width: 100%;
        }

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
        .doc-assinatura .depto {
            display: block; margin: 2px auto 0; padding-top: 4px; font-size: .8rem;
        }
        .doc-assinatura .data { display: block; margin: 2px auto 0; font-size: .8rem; }
        .doc-assinatura-import { font-size: .78rem; color: #3c4c5c; margin-bottom: 6px; }
        .doc-assinatura-imagem { display: block; max-height: 60px; margin: 0 auto 4px; }

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
                <h1>Commercial Invoice</h1>
                <p class="mb-0">Preencha os dados e gere o PDF direto pelo navegador</p>
            </div>
            <nav class="d-flex flex-wrap gap-2" aria-label="Ações do sistema">
                <a class="btn btn-outline-light btn-sm" href="follow.php">Follow</a>
                <a class="btn btn-outline-light btn-sm" href="processos.php">Processos</a>
                <a class="btn btn-outline-light btn-sm" href="pagamento.php">Pagamento</a>
                <a class="btn btn-outline-light btn-sm" href="confirmar_entrega.php">Confirmar entrega</a>
                <a class="btn btn-light btn-sm" href="commercial_invoice.php">📄 Commercial Invoice</a>
                <a class="btn btn-outline-light btn-sm" href="packing_list.php">📦 Packing List</a>
                <button type="button" class="btn btn-warning btn-sm" onclick="window.print()">⬇️ Baixar PDF</button>
            </nav>
        </div>
    </header>

    <div class="doc-sheet" id="doc-sheet">
        <div class="doc-header">Commercial Invoice:
            <select id="select_processo" onchange="carregarProcesso()">
                <option value="">Selecione um processo...</option>
                <?php foreach ($processosDisponiveis as $p): ?>
                    <option value="<?php echo h($p); ?>"><?php echo h($p); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="doc-toolbar-select no-print">
            <label class="d-block">Supplier (filial YAPP)</label>
            <select id="select_filial" class="form-select form-select-sm" onchange="aplicarFilial()">
                <option value="">Selecione uma filial para preencher o Exporter...</option>
                <?php foreach ($filiaisDisponiveis as $i => $f): ?>
                    <option value="<?php echo $i; ?>"
                        data-nome="<?php echo h($f['razao_social'] ?: $f['nome']); ?>"
                        data-cnpj="<?php echo h($f['cnpj'] ?? ''); ?>"
                        data-endereco="<?php echo h($f['endereco'] ?? ''); ?>"
                        data-cep="<?php echo h($f['cep'] ?? ''); ?>"
                        data-email="<?php echo h($f['email'] ?? ''); ?>">
                        <?php echo h($f['nome']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (empty($filiaisDisponiveis)): ?>
                <small class="text-muted d-block mt-1">Não foi possível carregar a lista de filiais agora — preencha o Exporter manualmente.</small>
            <?php endif; ?>
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
                <col style="width:4%"><col style="width:14%"><col style="width:28%">
                <col style="width:10%"><col style="width:6%"><col style="width:8%">
                <col style="width:12%"><col style="width:13%"><col style="width:5%">
            </colgroup>
            <thead>
                <tr>
                    <th class="col-idx">#</th>
                    <th>Part Number</th>
                    <th>Material Description</th>
                    <th>HS Code</th>
                    <th>UnM</th>
                    <th>Qty</th>
                    <th>Net Price</th>
                    <th>Total Price</th>
                    <th class="col-acao no-print"></th>
                </tr>
            </thead>
            <tbody id="corpo-itens"></tbody>
        </table>
        <div class="p-2 no-print">
            <button type="button" class="btn btn-outline-secondary btn-add-linha" onclick="adicionarLinha()">➕ Adicionar linha</button>
            <small class="text-muted d-block mt-1">Part Number, Material Description, HS Code e Qty vêm de Processos — dê <strong>duplo clique</strong> pra editar se precisar corrigir algo. Net Price é sempre digitado na mão.</small>
        </div>

        <div class="doc-pagamento-titulo">Freight and Payment Terms</div>
        <table class="doc-totais">
            <tr>
                <td style="width:50%"></td>
                <td style="width:50%">
                    <div class="doc-totais-linha"><span class="lado-a">Currency:</span><span class="lado-b"><input type="text" id="moeda" value="USD"></span></div>
                    <div class="doc-totais-linha"><span class="lado-a">Net Price:</span><span class="lado-b"><input type="text" id="valor_net" readonly></span></div>
                    <div class="doc-totais-linha"><span class="lado-a">Freight Price:</span><span class="lado-b"><input type="text" id="valor_frete" value="0,00" oninput="recalcularTotal()"></span></div>
                    <div class="doc-totais-linha"><span class="lado-a">Insurance Price:</span><span class="lado-b"><input type="text" id="valor_seguro" value="0,00" oninput="recalcularTotal()"></span></div>
                    <div class="doc-totais-linha doc-total-final"><span class="lado-a">Total:</span><span class="lado-b"><input type="text" id="valor_total" readonly></span></div>
                </td>
            </tr>
        </table>

        <table class="doc-rodape">
            <tr>
                <td class="col-payment">
                    <div class="doc-rodape-label">Payment Terms</div>
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
                <tr><td class="lbl">Net Weight (kg):</td><td><input type="text" id="cargo_net_weight"></td></tr>
                <tr><td class="lbl">Gross Weight (kg):</td><td><input type="text" id="cargo_gross_weight"></td></tr>
                <tr><td class="lbl">Dimensions (cm):</td><td><input type="text" id="cargo_dimensoes"></td></tr>
            </table>
        </div>

        <div class="doc-assinatura">
            <div class="doc-assinatura-import no-print">
                📎 Importar assinatura (JPG/PNG)<br>
                <input type="file" id="assinatura_arquivo" accept="image/jpeg,image/png" onchange="carregarAssinatura(event)">
            </div>
            <img id="assinatura_imagem" class="doc-assinatura-imagem" src="" alt="Assinatura" style="display:none;">
            <input type="text" id="assinatura_nome" class="nome" value="Maria Lima">
            <input type="text" id="assinatura_depto" class="depto" value="Supply Chain Department">
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

        // Transforma uma célula em "editável por duplo clique" — mostra texto
        // simples, e só vira um <input> quando o usuário dá duplo clique. Isso
        // é só visual/local (não salva em lugar nenhum, essa tela não usa
        // banco) — serve pra corrigir um valor puxado de Processos sem risco
        // de editar sem querer.
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
                    if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
                    else if (e.key === 'Escape') { e.preventDefault(); resolvido = true; definirValorPull(celula, valorAtual); }
                });
            });
        }

        function definirValorPull(celula, valor) {
            celula.dataset.valor = valor;
            celula.textContent = valor === '' ? '—' : valor;
        }

        function adicionarLinha(item) {
            contadorLinhas++;
            const idx = contadorLinhas;
            const tr = document.createElement('tr');
            tr.id = 'linha-' + idx;
            tr.innerHTML = `
                <td class="col-idx">${idx}</td>
                <td class="f-part"></td>
                <td class="f-desc"></td>
                <td class="center f-hscode"></td>
                <td class="center"><input type="text" class="f-unm" placeholder="UN"></td>
                <td class="num f-qtd"></td>
                <td class="num"><input type="text" class="f-preco" placeholder="0,00" oninput="recalcularLinha(${idx})"></td>
                <td class="num"><input type="text" class="f-total" readonly></td>
                <td class="col-acao no-print"><button type="button" class="btn-remover-linha" onclick="removerLinha(${idx})" title="Remover linha">✕</button></td>
            `;
            document.getElementById('corpo-itens').appendChild(tr);

            const celPart = tr.querySelector('.f-part');
            const celDesc = tr.querySelector('.f-desc');
            const celHscode = tr.querySelector('.f-hscode');
            const celQtd = tr.querySelector('.f-qtd');

            definirValorPull(celPart, item && item.part ? item.part : '');
            definirValorPull(celDesc, item && item.desc ? item.desc : '');
            definirValorPull(celHscode, item && item.hscode ? item.hscode : '');
            definirValorPull(celQtd, item && item.qtd ? item.qtd : '');

            tornarPull(celPart);
            tornarPull(celDesc);
            tornarPull(celHscode);
            tornarPull(celQtd, () => recalcularLinha(idx));

            if (item) { recalcularLinha(idx); }
        }

        function removerLinha(idx) {
            const linha = document.getElementById('linha-' + idx);
            if (linha) linha.remove();
            renumerarLinhas();
            recalcularNet();
        }

        function renumerarLinhas() {
            const linhas = document.querySelectorAll('#corpo-itens tr');
            linhas.forEach((linha, i) => {
                linha.querySelector('.col-idx').textContent = i + 1;
            });
        }

        // Total Price (por linha) = Qty (puxado de Processos) × Net Price (digitado).
        function recalcularLinha(idx) {
            const linha = document.getElementById('linha-' + idx);
            if (!linha) return;
            const qtd = parseNumeroBrDoc(linha.querySelector('.f-qtd').dataset.valor);
            const preco = parseNumeroBrDoc(linha.querySelector('.f-preco').value);
            linha.querySelector('.f-total').value = formatarNumeroBrDoc(qtd * preco);
            recalcularNet();
        }

        // Net Price (Currency) = soma da coluna Total Price. Total = Net + Freight + Insurance.
        function recalcularNet() {
            let net = 0;
            document.querySelectorAll('#corpo-itens .f-total').forEach(campo => {
                net += parseNumeroBrDoc(campo.value);
            });
            document.getElementById('valor_net').value = formatarNumeroBrDoc(net);
            recalcularTotal();
        }

        function recalcularTotal() {
            const net = parseNumeroBrDoc(document.getElementById('valor_net').value);
            const frete = parseNumeroBrDoc(document.getElementById('valor_frete').value);
            const seguro = parseNumeroBrDoc(document.getElementById('valor_seguro').value);
            const total = net + frete + seguro;
            document.getElementById('valor_total').value = formatarNumeroBrDoc(total);
        }

        // Ao escolher a filial, preenche Supplier/CNPJ/Address/CEP/E-mail —
        // Contact Person SEMPRE volta pra "Maria Lima", independente da filial
        // escolhida (mas continua editável se precisar trocar).
        function aplicarFilial() {
            const select = document.getElementById('select_filial');
            const opcao = select.options[select.selectedIndex];
            if (!opcao || opcao.value === '') return;
            document.getElementById('exp_supplier').value = opcao.dataset.nome || '';
            document.getElementById('exp_cnpj').value = opcao.dataset.cnpj || '';
            document.getElementById('exp_address').value = opcao.dataset.endereco || '';
            document.getElementById('exp_cep').value = opcao.dataset.cep || '';
            document.getElementById('exp_email').value = opcao.dataset.email || '';
            document.getElementById('exp_contato').value = 'Maria Lima';
        }

        // Ao escolher o Processo, busca os componentes cadastrados nele em
        // Processos e substitui as linhas atuais por eles.
        function carregarProcesso() {
            const processo = document.getElementById('select_processo').value;
            if (!processo) return;
            fetch('commercial_invoice.php?ajax=buscar_processo&processo=' + encodeURIComponent(processo))
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

        // Importa uma imagem de assinatura (JPG/PNG) e mostra ela acima do
        // nome — a imagem entra no PDF exportado normalmente (window.print
        // imprime o que está na tela).
        function carregarAssinatura(event) {
            const arquivo = event.target.files[0];
            if (!arquivo) return;
            const leitor = new FileReader();
            leitor.onload = function (e) {
                const img = document.getElementById('assinatura_imagem');
                img.src = e.target.result;
                img.style.display = 'block';
            };
            leitor.readAsDataURL(arquivo);
        }

        // Começa com 1 linha em branco — o normal é escolher um Processo pra
        // puxar os itens automaticamente.
        adicionarLinha();
    </script>
</body>
</html>
