<?php
// pedido_compra.php
// Tela pra montar um Pedido de Compra (Purchase Order) no layout padrão da
// empresa e exportar em PDF. Não depende de nenhuma biblioteca de PDF no
// servidor — o "Baixar PDF" chama a função de imprimir do navegador
// (window.print), com uma folha de estilo própria pra impressão que esconde
// os botões e ajusta a página pra ficar igual ao documento original.
//
// Os valores digitados NÃO são salvos no banco — essa tela é só uma
// "máquina de escrever" formatada pro PO, pensada pra gerar o PDF na hora.
// Se no futuro for necessário guardar histórico de pedidos emitidos, dá pra
// acrescentar uma tabela e um botão de "salvar" sem mexer no layout.
require_once 'conexao.php';

require_once 'auth.php';
exigirLogin();

// Filiais cadastradas (endereço de entrega). A tabela "filiais" precisa
// existir no banco — veja sql_filiais.sql. Cada opção do dropdown carrega o
// endereço/CEP já formatados, aplicados nos campos "Delivery at" via JS.
$filiais = [];
$resFiliais = mysqli_query($conn, "SELECT id, nome, razao_social, cnpj, endereco, cep, responsavel, email FROM filiais ORDER BY nome");
if ($resFiliais) {
    while ($linhaFilial = mysqli_fetch_assoc($resFiliais)) {
        $filiais[] = $linhaFilial;
    }
}

// Endpoint chamado via JS (fetch) quando o usuário digita/sai do campo "Part
// Number" — busca a descrição do componente na BOM, se existir. Se não
// encontrar, devolve null e o campo Description continua livre pra digitar
// na mão (não é um erro, é o caminho normal pra item que ainda não tem BOM).
if (isset($_GET['ajax']) && $_GET['ajax'] === 'buscar_descricao') {
    header('Content-Type: application/json; charset=utf-8');
    $codigo = trim($_GET['codigo'] ?? '');
    $descricao = null;
    if ($codigo !== '') {
        $stmt = mysqli_prepare($conn, "
            SELECT MAX(COALESCE(NULLIF(TRIM(descricao), ''), NULL)) AS descricao
            FROM bomnova
            WHERE TRIM(codigo_componente) = ? AND (mrp IS NULL OR UPPER(TRIM(mrp)) <> 'N')
        ");
        mysqli_stmt_bind_param($stmt, 's', $codigo);
        mysqli_stmt_execute($stmt);
        $descricao = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['descricao'] ?? null;
        mysqli_stmt_close($stmt);
    }
    echo json_encode(['descricao' => $descricao]);
    exit;
}

// Endpoint chamado via JS (fetch) ao escolher um Processo no dropdown do
// cabeçalho — busca os componentes lançados na Programação (tabela
// "programacao") pra esse processo, um item por linha (codigo_componente,
// quantidade, preco), já com a descrição puxada da BOM (mesma regra do
// buscar_descricao acima: ignora linhas com mrp='N'). Se o processo não
// tiver nenhum item, devolve lista vazia — o JS trata isso como "processo
// sem componentes" e mostra um aviso, sem travar a tela.
if (isset($_GET['ajax']) && $_GET['ajax'] === 'buscar_processo_programacao') {
    header('Content-Type: application/json; charset=utf-8');
    $processo = trim($_GET['processo'] ?? '');
    $itens = [];
    if ($processo !== '') {
        $stmt = mysqli_prepare($conn, "
            SELECT p.codigo_componente, p.quantidade, p.preco, p.data,
                (
                    SELECT MAX(COALESCE(NULLIF(TRIM(b.descricao), ''), NULL))
                    FROM bomnova b
                    WHERE TRIM(b.codigo_componente) = TRIM(p.codigo_componente)
                      AND (b.mrp IS NULL OR UPPER(TRIM(b.mrp)) <> 'N')
                ) AS descricao
            FROM programacao p
            WHERE p.processo = ?
            ORDER BY p.id
        ");
        mysqli_stmt_bind_param($stmt, 's', $processo);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($linha = mysqli_fetch_assoc($res)) {
            $itens[] = [
                'part' => (string) ($linha['codigo_componente'] ?? ''),
                'desc' => (string) ($linha['descricao'] ?? ''),
                'qtd' => $linha['quantidade'] !== null ? number_format((float) $linha['quantidade'], 0, ',', '') : '',
                'preco' => $linha['preco'] !== null ? number_format((float) $linha['preco'], 4, ',', '') : '',
                'eta' => $linha['data'] !== null && $linha['data'] !== '' ? date('d/m/Y', strtotime((string) $linha['data'])) : '',
            ];
        }
        mysqli_stmt_close($stmt);
    }
    echo json_encode(['itens' => $itens]);
    exit;
}

// Lista de processos lançados na Programação, pro dropdown do cabeçalho que
// puxa os componentes/preços — mesmo padrão do filtro de Processo já usado
// em programacao.php.
// Só entram processos que ainda têm pelo menos 1 item pendente (atendido=0
// ou NULL) — um processo com todos os itens já atendidos (já virou estoque
// físico) não faz mais sentido aparecer aqui pra montar um novo Pedido de Compra.
$processosProgramacao = [];
$resProcessosProgramacao = mysqli_query($conn, "SELECT DISTINCT TRIM(processo) AS processo FROM programacao WHERE processo IS NOT NULL AND TRIM(processo) <> '' AND (atendido = 0 OR atendido IS NULL) ORDER BY processo");
if ($resProcessosProgramacao) {
    while ($linhaProc = mysqli_fetch_assoc($resProcessosProgramacao)) {
        $processosProgramacao[] = $linhaProc['processo'];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pedido de Compra (PO)</title>
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

        .po-sheet {
            max-width: 900px;
            margin: 16px auto 40px;
            background: #fff;
            border: 1px solid var(--borda);
            box-shadow: 0 6px 20px rgba(18,48,74,.10);
        }

        .po-header {
            background: var(--navy);
            color: #fff;
            text-align: center;
            padding: 10px 16px;
            font-size: 1.05rem;
            font-weight: 700;
        }
        .po-header .numero {
            color: #ff9b7a;
            font-weight: 750;
        }
        .po-header input {
            background: transparent;
            border: none;
            color: #ff9b7a;
            font-weight: 750;
            text-align: center;
            width: 160px;
            font-size: 1.05rem;
        }
        .po-header input::placeholder { color: #ffd0bd; }
        .po-header select {
            background: var(--navy);
            border: 1px solid #4a6178;
            color: #ff9b7a;
            font-weight: 750;
            text-align: center;
            text-align-last: center;
            max-width: 260px;
            font-size: .95rem;
            padding: 2px 6px;
            border-radius: 3px;
        }
        .po-header select option { color: #17212b; background: #fff; }

        .po-secao-titulo {
            font-weight: 750;
            padding: 8px 16px 2px;
            font-size: .95rem;
        }

        table.po-info { width: 100%; border-collapse: collapse; padding: 0 16px; table-layout: fixed; }
        table.po-info td { padding: 1px 6px; vertical-align: top; font-size: .85rem; overflow: hidden; }
        table.po-info td.lbl { font-weight: 700; font-size: .78rem; color: #3c4c5c; width: 90px; white-space: nowrap; }
        table.po-info td.lbl-2 { font-weight: 700; font-size: .78rem; color: #3c4c5c; width: 70px; white-space: nowrap; }
        table.po-info input {
            border: none;
            border-bottom: 1px solid transparent;
            background: transparent;
            width: 100%;
            padding: 1px 2px;
            font-size: .85rem;
            box-sizing: border-box;
        }
        table.po-info input:focus {
            outline: none;
            border-bottom: 1px solid var(--navy);
            background: #f7faff;
        }
        .po-info-wrap { padding: 4px 16px 10px; }
        table.po-outer { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.po-outer td { vertical-align: top; padding: 0; overflow: hidden; }
        table.po-outer td.po-logo-cell { width: 130px; text-align: right; padding-right: 4px; }
        .po-logo-cell img { max-height: 55px; }

        .po-material-titulo {
            text-align: center;
            font-weight: 750;
            background: var(--cinza-label);
            border-top: 1px solid var(--borda);
            border-bottom: 1px solid var(--borda);
            padding: 6px;
            font-size: .9rem;
        }
        table.po-tabela { width: 100%; border-collapse: collapse; font-size: .8rem; table-layout: fixed; }
        table.po-tabela th {
            background: var(--cinza-label);
            padding: 6px 8px;
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .03em;
            border-bottom: 1px solid var(--borda);
            text-align: center;
        }
        table.po-tabela td { padding: 4px 8px; border-bottom: 1px solid #eef1f5; vertical-align: middle; }
        table.po-tabela tbody tr:nth-child(even) { background: #f9fbfd; }
        table.po-tabela input {
            border: none;
            background: transparent;
            width: 100%;
            padding: 2px 3px;
            font-size: .8rem;
        }
        table.po-tabela input:focus { outline: none; background: #f0f6ff; }
        table.po-tabela .num { text-align: right; }
        table.po-tabela .num input { text-align: right; }
        table.po-tabela .center { text-align: center; }
        table.po-tabela .center input { text-align: center; }
        table.po-tabela .col-idx { width: 28px; text-align: center; color: #66788a; }
        table.po-tabela .col-acao { width: 34px; text-align: center; }
        table.po-tabela .btn-remover-linha {
            border: none; background: none; color: #c53535; font-size: .95rem; cursor: pointer; line-height: 1;
        }
        /* Células puxadas da Programação (Part Number, Description, Quantity)
           — mostram texto simples e só entram em modo de edição com DUPLO
           clique, pra evitar alteração sem querer ao rolar a tela (mesmo
           padrão usado na Commercial Invoice). */
        table.po-tabela .celula-pull {
            cursor: cell;
            min-height: 20px;
        }
        table.po-tabela .celula-pull:hover {
            background: #eef4fb;
            box-shadow: inset 0 0 0 1px #b9d3ef;
        }
        table.po-tabela .celula-pull input {
            width: 100%;
        }

        .po-pagamento-titulo {
            text-align: center;
            font-weight: 750;
            background: var(--navy);
            color: #fff;
            padding: 6px;
            font-size: .9rem;
        }
        table.po-totais { width: 100%; border-collapse: collapse; padding: 10px 16px; }
        table.po-totais td { padding: 2px 8px; vertical-align: top; }
        .po-totais-linha { display: table; width: 100%; table-layout: fixed; margin-bottom: 2px; }
        .po-totais-linha .lado-a, .po-totais-linha .lado-b { display: table-cell; vertical-align: middle; }
        .po-totais-linha .lado-a { width: 55%; text-align: right; font-weight: 700; font-size: .78rem; color: #3c4c5c; padding-right: 6px; }
        .po-totais-linha input {
            border: none; border-bottom: 1px solid transparent; background: transparent;
            text-align: right; font-size: .85rem; padding: 1px 2px; width: 100%;
        }
        .po-totais-linha input:focus { outline: none; border-bottom: 1px solid var(--navy); background: #f7faff; }
        .po-total-final .lado-a { font-weight: 750; font-size: .95rem; }
        .po-total-final input { font-weight: 750; font-size: 1rem; }

        table.po-rodape { width: 100%; border-collapse: collapse; border-top: 1px solid var(--borda); }
        table.po-rodape td { padding: 10px 16px; vertical-align: top; width: 50%; }
        table.po-rodape td:first-child { border-right: 1px solid var(--borda); }
        .po-rodape-linha { display: table; width: 100%; table-layout: fixed; margin-bottom: 4px; }
        .po-rodape-linha .lado-a { display: table-cell; width: 90px; font-weight: 700; font-size: .78rem; color: #3c4c5c; vertical-align: middle; }
        .po-rodape-linha .lado-b { display: table-cell; vertical-align: middle; }
        .po-rodape-linha input {
            border: none; border-bottom: 1px solid transparent; background: transparent; width: 100%; font-size: .82rem; padding: 1px 2px;
        }
        .po-rodape-linha input:focus { outline: none; border-bottom: 1px solid var(--navy); background: #f7faff; }
        .rodape-textarea {
            border: none; border-bottom: 1px solid transparent; background: transparent; width: 100%;
            font-size: .82rem; padding: 1px 2px; font-family: inherit; resize: vertical; line-height: 1.3;
        }
        .rodape-textarea:focus { outline: none; border-bottom: 1px solid var(--navy); background: #f7faff; }
        .po-invoice-titulo { text-align: center; font-weight: 700; font-size: .82rem; margin-bottom: 6px; }
        .po-invoice-texto { text-align: center; }
        .po-invoice-texto textarea { text-align: center; color: var(--destaque); font-weight: 700; width: 100%; padding: 8px 10px; font-size: .85rem; resize: vertical; font-family: inherit; }

        .po-assinatura { text-align: center; padding: 40px 16px 24px; }
        .po-assinatura input {
            border: none; border-top: 1px solid #333; background: transparent; text-align: center;
            width: 260px; padding-top: 4px; font-size: .85rem;
        }
        .po-assinatura input:focus { outline: none; background: #f7faff; }

        .po-assinatura-img { display: block; max-height: 60px; margin: 0 auto 6px; }
        .po-assinatura-upload-wrap { text-align: center; margin-bottom: 6px; }
        .po-assinatura-upload-wrap label { font-size: .78rem; color: #3c4c5c; cursor: pointer; }

        .btn-add-linha { font-size: .78rem; }

        @media print {
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; color-adjust: exact; }
            body { background: #fff; }
            .no-print { display: none !important; }
            .linha-vazia-print { display: none !important; }
            .po-sheet { box-shadow: none; border: none; margin: 0; max-width: 100%; }
            table.po-tabela .col-acao { display: none; }
            table.po-tabela .col-acao-largura { display: none; }
            input { color: #000 !important; }
            .rodape-textarea { color: #000 !important; }
            .po-header input, .po-header select { color: var(--destaque) !important; }
            .po-invoice-texto textarea { color: var(--destaque) !important; }
        }
    </style>
</head>
<body>
    <header class="topbar no-print">
        <div class="container-fluid dashboard-container d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="eyebrow">Supply Chain • Compras</span>
                <h1>Pedido de Compra</h1>
                <p class="mb-0">Preencha os dados e gere o PDF direto pelo navegador</p>
            </div>
            <nav class="d-flex flex-wrap gap-2" aria-label="Ações do sistema">
                <a class="btn btn-outline-light btn-sm" href="index.php">🏠 Dashboard</a>
                <a class="btn btn-outline-light btn-sm" href="estoque.php">Estoque</a>
                <a class="btn btn-outline-light btn-sm" href="edi.php">EDI</a>
                <a class="btn btn-outline-light btn-sm" href="bomnova.php">BOM</a>
                <a class="btn btn-outline-light btn-sm" href="programacao.php">Programação</a>
                <a class="btn btn-outline-light btn-sm" href="parametros_compra.php">Parâmetros</a>
                <a class="btn btn-outline-light btn-sm" href="evolucao_geral.php">Evolução geral</a>
                <a class="btn btn-outline-light btn-sm" href="planejamento_compras.php">Planejamento de compras</a>
                <a class="btn btn-light btn-sm" href="pedido_compra.php">📄 Pedido de Compra</a>
                <button type="button" class="btn btn-warning btn-sm" onclick="window.print()">⬇️ Baixar PDF</button>
            </nav>
        </div>
    </header>

    <div class="po-sheet" id="po-sheet">
        <div class="po-header">
            Purchase Order:
            <select id="select_processo" onchange="carregarProcessoProgramacao()">
                <option value="">Selecione um processo...</option>
                <?php foreach ($processosProgramacao as $p): ?>
                    <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="po-secao-titulo">Customer</div>
        <div class="po-info-wrap">
            <table class="po-outer">
                <tr>
                    <td>
                        <table class="po-info">
                            <tr class="no-print">
                                <td class="lbl">Filial</td>
                                <td colspan="3">
                                    <select id="filial_selecionada" class="form-select form-select-sm" onchange="aplicarFilial()">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($filiais as $f): ?>
                                            <option value="<?php echo (int) $f['id']; ?>"><?php echo htmlspecialchars($f['nome']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr><td class="lbl">Company</td><td colspan="3"><input type="text" id="cli_company" placeholder="Selecione a filial acima"></td></tr>
                            <tr><td class="lbl">TAX ID</td><td colspan="3"><input type="text" id="cli_taxid" placeholder="00.000.000/0000-00"></td></tr>
                            <tr><td class="lbl">Adress</td><td colspan="3"><input type="text" id="cli_adress" placeholder="Endereço"></td></tr>
                            <tr><td class="lbl">Zip Code</td><td colspan="3"><input type="text" id="cli_zip" placeholder="00.000-000"></td></tr>
                        </table>
                    </td>
                    <td class="po-logo-cell">
                        <img src="assets/logo_yapp.jpeg" alt="Logo" onerror="this.style.display='none'">
                    </td>
                </tr>
            </table>
            <table class="po-info">
                <tr>
                    <td class="lbl">Buyer</td><td><input type="text" id="cli_buyer" placeholder="Nome do comprador" oninput="document.getElementById('assinatura_nome').value = this.value"></td>
                    <td class="lbl-2">Phone</td><td><input type="text" id="cli_phone" placeholder="+55 00 00000-0000"></td>
                </tr>
                <tr>
                    <td class="lbl">Email</td><td colspan="3"><input type="text" id="cli_email" placeholder="email@yapp.com"></td>
                </tr>
            </table>
        </div>

        <div class="po-secao-titulo">
            Supplier <input type="text" id="sup_ref" placeholder="Referência do fornecedor" style="color:var(--destaque); font-weight:700; width:220px; display:inline-block; border:none; background:transparent;">
        </div>
        <div class="po-info-wrap">
            <table class="po-info">
                <tr><td class="lbl">Company</td><td colspan="3"><input type="text" id="sup_company" placeholder="Razão social do fornecedor"></td></tr>
                <tr><td class="lbl">TAX ID</td><td colspan="3"><input type="text" id="sup_taxid" placeholder="00.000.000/0000-00"></td></tr>
                <tr><td class="lbl">Adress</td><td colspan="3"><input type="text" id="sup_adress" placeholder="Endereço"></td></tr>
                <tr><td class="lbl">Zip Code</td><td colspan="3"><input type="text" id="sup_zip" placeholder="00.000-000"></td></tr>
            </table>
            <table class="po-info">
                <tr>
                    <td class="lbl">Commercial</td><td><input type="text" id="sup_contato" placeholder="Nome do contato comercial"></td>
                    <td class="lbl-2">Phone</td><td><input type="text" id="sup_phone" placeholder="+55 00 00000-0000"></td>
                </tr>
                <tr>
                    <td class="lbl">Email</td><td colspan="3"><input type="text" id="sup_email" placeholder="email@fornecedor.com"></td>
                </tr>
            </table>
        </div>

        <div class="po-material-titulo">Material Detail</div>
        <table class="po-tabela" id="tabela-itens">
            <colgroup>
                <col style="width:4%"><col style="width:12%"><col style="width:26%">
                <col style="width:10%"><col style="width:6%"><col style="width:10%">
                <col style="width:12%"><col style="width:12%"><col class="col-acao-largura" style="width:8%">
            </colgroup>
            <thead>
                <tr>
                    <th class="col-idx">#</th>
                    <th>Part Number</th>
                    <th>Description</th>
                    <th>ETA</th>
                    <th>UnM</th>
                    <th>Quantity</th>
                    <th>Net Price</th>
                    <th>Total</th>
                    <th class="col-acao no-print"></th>
                </tr>
            </thead>
            <tbody id="corpo-itens"></tbody>
        </table>
        <div class="p-2 no-print">
            <button type="button" class="btn btn-outline-secondary btn-add-linha" onclick="adicionarLinha()">➕ Adicionar linha</button>
        </div>

        <div class="po-pagamento-titulo">Payment and Freight information</div>
        <table class="po-totais">
            <tr>
                <td style="width:50%">
                    <div class="po-totais-linha"><span class="lado-a">ICMS</span><span class="lado-b"><input type="text" id="tax_icms" value="18%" oninput="recalcularTotal()"></span></div>
                    <div class="po-totais-linha"><span class="lado-a">PIS</span><span class="lado-b"><input type="text" id="tax_pis" value="1,65%" oninput="recalcularTotal()"></span></div>
                    <div class="po-totais-linha"><span class="lado-a">COFINS</span><span class="lado-b"><input type="text" id="tax_cofins" value="7,60%" oninput="recalcularTotal()"></span></div>
                    <div class="po-totais-linha"><span class="lado-a">IPI</span><span class="lado-b"><input type="text" id="tax_ipi" value="0%" oninput="recalcularTotal()"></span></div>
                </td>
                <td style="width:50%">
                    <div class="po-totais-linha"><span class="lado-a">Currency:</span><span class="lado-b"><input type="text" id="moeda" value="R$"></span></div>
                    <div class="po-totais-linha"><span class="lado-a">Net:</span><span class="lado-b"><input type="text" id="valor_net" readonly></span></div>
                    <div class="po-totais-linha"><span class="lado-a">Advanced Cash:</span><span class="lado-b"><input type="text" id="valor_adiantamento" value="0,00" oninput="recalcularTotal()"></span></div>
                    <div class="po-totais-linha"><span class="lado-a">Taxes <small style="font-weight:400;">(ICMS+PIS+COFINS+IPI)</small>:</span><span class="lado-b"><input type="text" id="valor_taxes" readonly></span></div>
                    <div class="po-totais-linha"><span class="lado-a">Frete:</span><span class="lado-b"><input type="text" id="valor_frete" value="0,00" oninput="recalcularTotal()"></span></div>
                    <div class="po-totais-linha po-total-final"><span class="lado-a">Total:</span><span class="lado-b"><input type="text" id="valor_total" readonly></span></div>
                </td>
            </tr>
        </table>

        <table class="po-rodape">
            <tr>
                <td>
                    <div class="po-rodape-linha"><span class="lado-a">Payment:</span><span class="lado-b"><input type="text" id="rodape_payment" value="28 DDL"></span></div>
                    <div class="po-rodape-linha"><span class="lado-a">Incoterms:</span><span class="lado-b"><input type="text" id="rodape_incoterms" value="CIF"></span></div>
                    <div class="po-rodape-linha"><span class="lado-a">Delivery at:</span><span class="lado-b"><textarea id="rodape_delivery1" rows="2" class="rodape-textarea" placeholder="Selecione a filial acima"></textarea></span></div>
                    <div class="po-rodape-linha"><span class="lado-a"></span><span class="lado-b"><input type="text" id="rodape_delivery2" placeholder="00.000-000"></span></div>
                </td>
                <td>
                    <div class="po-invoice-titulo">Commercial Invoice must contain:</div>
                    <div class="po-invoice-texto">
                        <textarea id="rodape_invoice" rows="3" placeholder="PO 0000000000 PROJETO"></textarea>
                    </div>
                </td>
            </tr>
        </table>

        <div class="po-assinatura">
            <div class="po-assinatura-upload-wrap no-print">
                <label for="assinatura_upload">📎 Importar assinatura (JPG/PNG)</label>
                <input type="file" id="assinatura_upload" accept="image/jpeg,image/png" onchange="importarAssinatura(event)" style="display:block; margin:2px auto 0;">
            </div>
            <img id="assinatura_img" class="po-assinatura-img" alt="Assinatura" style="display:none;">
            <input type="text" id="assinatura_nome" placeholder="Nome do responsável">
        </div>
    </div>

    <script>
        // Antes de imprimir/exportar o PDF, esconde linhas de material que
        // ficaram em branco (sem Part Number nem Description preenchidos) —
        // linhas adicionadas mas não usadas não devem aparecer no documento
        // final. Depois de imprimir, volta tudo ao normal pra continuar editando.
        window.addEventListener('beforeprint', () => {
            let numeroVisivel = 0;
            document.querySelectorAll('#corpo-itens tr').forEach((linha) => {
                const part = linha.querySelector('.f-part')?.value.trim() ?? '';
                const desc = linha.querySelector('.f-desc')?.value.trim() ?? '';
                const vazia = part === '' && desc === '';
                linha.classList.toggle('linha-vazia-print', vazia);

                const celIdx = linha.querySelector('.col-idx');
                if (celIdx) {
                    celIdx.dataset.numeroOriginal = celIdx.textContent;
                    if (!vazia) {
                        numeroVisivel++;
                        celIdx.textContent = numeroVisivel;
                    }
                }
            });
        });
        window.addEventListener('afterprint', () => {
            document.querySelectorAll('#corpo-itens tr').forEach((linha) => {
                linha.classList.remove('linha-vazia-print');
                const celIdx = linha.querySelector('.col-idx');
                if (celIdx && celIdx.dataset.numeroOriginal) {
                    celIdx.textContent = celIdx.dataset.numeroOriginal;
                    delete celIdx.dataset.numeroOriginal;
                }
            });
        });

        let contadorLinhas = 0;

        const filiaisMap = <?php echo json_encode(array_column($filiais, null, 'id'), JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function aplicarFilial() {
            const id = document.getElementById('filial_selecionada').value;
            const filial = filiaisMap[id];
            if (!filial) return;
            document.getElementById('cli_company').value = filial.razao_social;
            document.getElementById('cli_taxid').value = filial.cnpj;
            document.getElementById('cli_adress').value = filial.endereco;
            document.getElementById('cli_zip').value = filial.cep;
            document.getElementById('cli_buyer').value = filial.responsavel;
            document.getElementById('assinatura_nome').value = filial.responsavel;
            document.getElementById('cli_email').value = filial.email;
            document.getElementById('rodape_delivery1').value = filial.nome + ' - ' + filial.endereco;
            document.getElementById('rodape_delivery2').value = filial.cep;
        }

        function importarAssinatura(evento) {
            const arquivo = evento.target.files[0];
            if (!arquivo) return;
            const leitor = new FileReader();
            leitor.onload = () => {
                const img = document.getElementById('assinatura_img');
                img.src = leitor.result;
                img.style.display = 'block';
            };
            leitor.readAsDataURL(arquivo);
        }

        function parseNumeroBrPo(texto) {
            if (!texto) return 0;
            texto = String(texto).trim();
            if (texto.includes(',')) {
                texto = texto.replace(/\./g, '').replace(',', '.');
            }
            const n = parseFloat(texto);
            return isNaN(n) ? 0 : n;
        }

        function formatarNumeroBrPo(n) {
            return n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        // Transforma uma célula em "editável por duplo clique" — mostra texto
        // simples, e só vira um <input> quando o usuário dá duplo clique. Isso
        // é só visual/local (não salva em lugar nenhum, essa tela não usa
        // banco) — serve pra corrigir um valor puxado da Programação sem
        // risco de editar sem querer (mesmo padrão da Commercial Invoice).
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

        function adicionarLinha(dados) {
            contadorLinhas++;
            const idx = contadorLinhas;
            const tr = document.createElement('tr');
            tr.id = 'linha-' + idx;
            tr.innerHTML = `
                <td class="col-idx">${idx}</td>
                <td class="f-part"></td>
                <td class="f-desc"></td>
                <td class="center"><input type="text" class="f-eta" placeholder="dd/mm/aaaa"></td>
                <td class="center"><input type="text" class="f-unm" placeholder="UN"></td>
                <td class="num f-qtd"></td>
                <td class="num"><input type="text" class="f-preco" placeholder="0,00" oninput="recalcularLinha(${idx})"></td>
                <td class="num"><input type="text" class="f-total" readonly></td>
                <td class="col-acao no-print"><button type="button" class="btn-remover-linha" onclick="removerLinha(${idx})" title="Remover linha">✕</button></td>
            `;
            document.getElementById('corpo-itens').appendChild(tr);

            const celPart = tr.querySelector('.f-part');
            const celDesc = tr.querySelector('.f-desc');
            const celQtd = tr.querySelector('.f-qtd');

            definirValorPull(celPart, dados && dados.part ? dados.part : '');
            definirValorPull(celDesc, dados && dados.desc ? dados.desc : '');
            definirValorPull(celQtd, dados && dados.qtd ? dados.qtd : '');

            tornarPull(celPart, () => buscarDescricaoBom(idx));
            tornarPull(celDesc);
            tornarPull(celQtd, () => recalcularLinha(idx));

            if (dados && dados.preco) {
                tr.querySelector('.f-preco').value = dados.preco;
            }
            if (dados && dados.eta) {
                tr.querySelector('.f-eta').value = dados.eta;
            }

            if (dados) { recalcularLinha(idx); }
        }

        // Disparado ao confirmar (duplo clique + Enter/blur) o Part Number de
        // uma linha — busca a descrição do componente na BOM. Se encontrar,
        // preenche a Description (sobrescreve, já que a BOM é a fonte
        // oficial). Se não encontrar, não faz nada — a Description continua
        // livre pra digitar na mão, não é tratado como erro.
        function buscarDescricaoBom(idx) {
            const linha = document.getElementById('linha-' + idx);
            if (!linha) return;
            const codigo = (linha.querySelector('.f-part').dataset.valor || '').trim();
            if (!codigo) return;

            fetch('pedido_compra.php?ajax=buscar_descricao&codigo=' + encodeURIComponent(codigo))
                .then(r => r.json())
                .then(dados => {
                    if (dados.descricao) {
                        definirValorPull(linha.querySelector('.f-desc'), dados.descricao);
                    }
                })
                .catch(() => { /* falha de rede — deixa o campo como está, sem travar o preenchimento manual */ });
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

        function recalcularLinha(idx) {
            const linha = document.getElementById('linha-' + idx);
            if (!linha) return;
            const qtd = parseNumeroBrPo(linha.querySelector('.f-qtd').dataset.valor);
            const preco = parseNumeroBrPo(linha.querySelector('.f-preco').value);
            linha.querySelector('.f-total').value = formatarNumeroBrPo(qtd * preco);
            recalcularNet();
        }

        // Ao escolher um Processo no dropdown do cabeçalho, busca os
        // componentes lançados nele na Programação e substitui as linhas
        // atuais por eles — Part Number, Description e Quantity vêm da
        // Programação (editáveis por duplo clique), e o Net Price já vem
        // preenchido também, mas continua sempre editável direto.
        function carregarProcessoProgramacao() {
            const processo = document.getElementById('select_processo').value;
            if (!processo) return;
            fetch('pedido_compra.php?ajax=buscar_processo_programacao&processo=' + encodeURIComponent(processo))
                .then(r => r.json())
                .then(dados => {
                    document.getElementById('corpo-itens').innerHTML = '';
                    contadorLinhas = 0;
                    if (dados.itens && dados.itens.length) {
                        dados.itens.forEach(item => adicionarLinha(item));
                    } else {
                        adicionarLinha();
                        alert('Esse processo não tem componentes lançados na Programação ainda.');
                    }
                })
                .catch(() => alert('Erro de conexão ao buscar os componentes desse processo.'));
        }

        function recalcularNet() {
            let net = 0;
            document.querySelectorAll('#corpo-itens .f-total').forEach(campo => {
                net += parseNumeroBrPo(campo.value);
            });
            document.getElementById('valor_net').value = formatarNumeroBrPo(net);
            recalcularTotal();
        }

        // ICMS e PIS/COFINS são calculados "por dentro": o percentual incide sobre o
        // preço BRUTO da nota, não sobre o Net. Por isso, pra achar o preço bruto que,
        // depois de descontados esses impostos, resulta de volta no Net informado, é
        // preciso "regrossar" (dividir), não multiplicar:
        //   preçoBruto = Net ÷ (1 − PIS% − COFINS%) ÷ (1 − ICMS%)
        // O IPI é "por fora": incide sobre esse preço bruto e é somado por cima
        // (não é descontado de dentro do preço, é um valor adicional na nota).
        //   valorIpi = preçoBruto × IPI%
        // Taxes (exibido) = (preçoBruto + valorIpi) − Net.
        // Total = Net + Taxes + Frete − Advanced Cash.
        function parsePercentualPo(texto) {
            return parseNumeroBrPo(String(texto || '').replace('%', ''));
        }

        function recalcularTotal() {
            const net = parseNumeroBrPo(document.getElementById('valor_net').value);
            const icms = parsePercentualPo(document.getElementById('tax_icms').value);
            const ipi = parsePercentualPo(document.getElementById('tax_ipi').value);
            const pis = parsePercentualPo(document.getElementById('tax_pis').value);
            const cofins = parsePercentualPo(document.getElementById('tax_cofins').value);

            const fatorPisCofins = 1 - (pis + cofins) / 100;
            const precoBrutoSemIcms = fatorPisCofins > 0 ? net / fatorPisCofins : net;

            const fatorIcms = 1 - icms / 100;
            const precoBruto = fatorIcms > 0 ? precoBrutoSemIcms / fatorIcms : precoBrutoSemIcms;

            const valorIpi = precoBruto * ipi / 100;
            const taxes = (precoBruto + valorIpi) - net;
            document.getElementById('valor_taxes').value = formatarNumeroBrPo(taxes);

            const frete = parseNumeroBrPo(document.getElementById('valor_frete').value);
            const adiantamento = parseNumeroBrPo(document.getElementById('valor_adiantamento').value);
            const total = net + taxes + frete - adiantamento;
            document.getElementById('valor_total').value = formatarNumeroBrPo(total);
        }

        // Começa com 3 linhas em branco, prontas pra preencher
        adicionarLinha();
        adicionarLinha();
        adicionarLinha();
        recalcularNet();
    </script>
</body>
</html>
