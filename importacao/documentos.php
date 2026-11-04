<?php
// importacao/documentos.php
// Commercial Invoice + Packing List na MESMA janela (lado a lado ou um embaixo
// do outro). Cada documento continua sendo a sua própria tela
// (commercial_invoice.php / packing_list.php), carregada num quadro (iframe);
// esta página só junta as duas e SINCRONIZA o que é comum entre elas:
//   - Processo escolhido (carrega os itens nos dois)
//   - Filial (Supplier) escolhida
//   - Exporter, Importer, Currency, Freight/Insurance, Payment Terms, Freight
//     Forwarder, Pick Up, Incoterms, Additional Information, Remarks, Cargo
//     summary (Volume, CBM, Dimensions) e assinatura (nome/depto/data)
//   - Net/Gross Weight calculados no Packing List vão pro Cargo summary da Invoice
// Nada é salvo no banco (igual às telas originais).
require_once 'conexao.php';
require_once __DIR__ . '/auth_importacao.php'; // exige login (o mesmo do MRP)
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Commercial Invoice + Packing List</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/dashboard.css" rel="stylesheet">
    <style>
        html, body { height: 100%; }
        body { background: #eef1f5; display: flex; flex-direction: column; }
        .topbar { padding-top: 10px; padding-bottom: 10px; }
        .topbar h1 { font-size: 1.35rem; margin: 0; }
        .barra-docs {
            display: flex; flex-wrap: wrap; align-items: center; gap: 8px;
            padding: 8px 16px; background: #fff; border-bottom: 1px solid #dce4ec;
            font-size: .85rem;
        }
        .barra-docs .form-select, .barra-docs .form-control { width: auto; min-height: auto; }
        .barra-docs .sep { width: 1px; height: 24px; background: #dce4ec; margin: 0 4px; }
        .quadros { flex: 1; display: flex; gap: 8px; padding: 8px; min-height: 0; }
        .quadros.empilhado { flex-direction: column; }
        .quadro { flex: 1; display: flex; flex-direction: column; min-width: 0; min-height: 0;
                  background: #fff; border: 1px solid #dce4ec; border-radius: 6px; overflow: hidden; }
        .quadro-titulo { padding: 4px 10px; font-weight: 700; font-size: .8rem; background: #12304a; color: #fff;
                         display: flex; justify-content: space-between; align-items: center; }
        .quadro-titulo a { color: #cfe0f1; font-weight: 400; font-size: .75rem; }
        .quadro iframe { flex: 1; width: 100%; border: 0; min-height: 0; }
        .quadros.empilhado .quadro { min-height: 85vh; }
        .quadros.empilhado { overflow-y: auto; }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="container-fluid d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="eyebrow">Controle de Importação • Documentos</span>
                <h1>CI / PL — Commercial Invoice + Packing List</h1>
            </div>
            <nav class="d-flex flex-wrap gap-2" aria-label="Ações do sistema">
                <a class="btn btn-outline-light btn-sm" href="follow.php">Follow</a>
                <a class="btn btn-outline-light btn-sm" href="processos.php">Processos</a>
                <a class="btn btn-outline-light btn-sm" href="pagamento.php">Pagamento</a>
                <a class="btn btn-outline-light btn-sm" href="confirmar_entrega.php">Confirmar entrega</a>
                <a class="btn btn-outline-light btn-sm" href="comparativos.php">Comparativos</a>
                <a class="btn btn-light btn-sm" href="documentos.php">📄 CI / PL</a>
                <a class="btn btn-outline-light btn-sm" href="release.php">🚚 Release</a>
                <a class="btn btn-outline-light btn-sm" href="parametros_importacao.php">⚙️ Parâmetros</a>
            </nav>
        </div>
    </header>

    <div class="barra-docs">
        <label class="fw-bold">Layout:</label>
        <select id="layout" class="form-select form-select-sm" onchange="aplicarLayout()">
            <option value="lado">Lado a lado</option>
            <option value="empilhado">Um embaixo do outro</option>
        </select>
        <label class="fw-bold ms-2">Zoom:</label>
        <select id="zoom" class="form-select form-select-sm" onchange="aplicarZoom()">
            <option value="0.7">70%</option>
            <option value="0.8" selected>80%</option>
            <option value="0.9">90%</option>
            <option value="1">100%</option>
        </select>
        <span class="sep"></span>
        <label class="form-check-label d-flex align-items-center gap-1">
            <input type="checkbox" id="sincronizar" class="form-check-input mt-0" checked>
            Sincronizar campos comuns
        </label>
        <span class="text-muted" style="font-size:.78rem;">(processo, filial, exporter, importer, frete, rodapé, remarks, cargo e assinatura)</span>
        <span class="sep"></span>
        <button type="button" class="btn btn-warning btn-sm fw-bold" onclick="imprimirJuntos()" title="Um único PDF: Commercial Invoice na 1ª página e Packing List na 2ª">⬇️ PDF Invoice + Packing</button>
    </div>

    <div class="quadros" id="quadros">
        <div class="quadro">
            <div class="quadro-titulo">📄 Commercial Invoice</div>
            <iframe id="frame-ci" src="commercial_invoice.php" title="Commercial Invoice"></iframe>
        </div>
        <div class="quadro">
            <div class="quadro-titulo">📦 Packing List</div>
            <iframe id="frame-pl" src="packing_list.php" title="Packing List"></iframe>
        </div>
    </div>

    <script>
        // Campos com o MESMO id nas duas telas, copiados de uma pra outra ao digitar.
        const CAMPOS_COMUNS = [
            'exp_supplier', 'exp_cnpj', 'exp_address', 'exp_cep', 'exp_contato', 'exp_email',
            'imp_taxid', 'imp_address', 'imp_cep', 'imp_contato', 'imp_email', 'imp_phone',
            'moeda', 'valor_frete', 'valor_seguro',
            'rodape_payment', 'rodape_ffw', 'rodape_pickup', 'rodape_incoterms', 'rodape_adicional',
            'remarks',
            'assinatura_nome', 'assinatura_depto', 'assinatura_data',
        ];
        const frames = { ci: document.getElementById('frame-ci'), pl: document.getElementById('frame-pl') };
        let copiando = false; // evita "eco" (uma tela copiando de volta pra outra sem fim)

        function docDe(chave) {
            try { return frames[chave].contentDocument; } catch (e) { return null; }
        }
        function janelaDe(chave) {
            try { return frames[chave].contentWindow; } catch (e) { return null; }
        }
        function outra(chave) { return chave === 'ci' ? 'pl' : 'ci'; }
        function sincronizando() { return document.getElementById('sincronizar').checked; }

        // Deixa cada tela mais enxuta dentro do quadro: esconde título e links de
        // navegação do cabeçalho (ficam só os botões/campos, ex.: PTAX e Exportar Planilha).
        function enxugar(chave) {
            const doc = docDe(chave);
            if (!doc || doc.getElementById('estilo-embutido')) return;
            const estilo = doc.createElement('style');
            estilo.id = 'estilo-embutido';
            estilo.textContent = `
                .topbar { padding: 6px 0 !important; }
                .topbar .eyebrow, .topbar h1, .topbar p, .topbar nav a { display: none !important; }
                /* PDF só pelo botão único "PDF Invoice + Packing" desta tela */
                .topbar nav button.btn-warning { display: none !important; }
                .doc-sheet { margin-top: 8px !important; }
            `;
            doc.head.appendChild(estilo);
        }

        function aplicarZoom() {
            const z = document.getElementById('zoom').value;
            ['ci', 'pl'].forEach(chave => {
                const doc = docDe(chave);
                if (doc && doc.body) doc.body.style.zoom = z;
            });
        }

        function aplicarLayout() {
            document.getElementById('quadros').classList.toggle('empilhado', document.getElementById('layout').value === 'empilhado');
        }

        // Prefixa cada seletor do CSS de uma tela (ex.: "#pdf-ci .doc-tabela td"), pra
        // juntar o estilo das duas telas no mesmo PDF sem uma bagunçar a outra.
        function prefixarCss(css, prefixo) {
            let saida = '';
            let i = 0;
            css = css.replace(/\/\*[\s\S]*?\*\//g, '');
            while (i < css.length) {
                const abre = css.indexOf('{', i);
                if (abre === -1) break;
                const cabeca = css.slice(i, abre).trim();
                if (cabeca.startsWith('@media') || cabeca.startsWith('@supports')) {
                    // acha o fechamento do bloco @media (com regras dentro)
                    let nivel = 1, k = abre + 1;
                    while (k < css.length && nivel > 0) { if (css[k] === '{') nivel++; else if (css[k] === '}') nivel--; k++; }
                    saida += cabeca + '{' + prefixarCss(css.slice(abre + 1, k - 1), prefixo) + '}';
                    i = k;
                    continue;
                }
                const fecha = css.indexOf('}', abre);
                const corpo = css.slice(abre + 1, fecha);
                const seletores = cabeca.split(',').map(sel => {
                    sel = sel.trim();
                    if (sel === '' ) return '';
                    if (sel === ':root' || sel === 'html' || sel === 'body' || sel === '*') return prefixo;
                    if (sel.startsWith('body ')) return prefixo + ' ' + sel.slice(5);
                    return prefixo + ' ' + sel;
                }).filter(Boolean).join(', ');
                if (seletores) saida += seletores + '{' + corpo + '}';
                i = fecha + 1;
            }
            return saida;
        }

        // Copia a folha (.doc-sheet) de uma tela, trocando os campos pelo que está digitado.
        function copiarFolha(chave) {
            const doc = docDe(chave);
            const original = doc && doc.getElementById('doc-sheet');
            if (!original) return null;
            const copia = original.cloneNode(true);
            const camposOrig = original.querySelectorAll('input, textarea, select');
            const camposCopia = copia.querySelectorAll('input, textarea, select');
            camposOrig.forEach((campo, n) => {
                const alvo = camposCopia[n];
                if (campo.tagName === 'SELECT') {
                    const span = doc.createElement('span');
                    span.textContent = campo.selectedIndex >= 0 ? campo.options[campo.selectedIndex].text : '';
                    if (campo.value === '') span.textContent = '';
                    alvo.replaceWith(span);
                } else if (campo.tagName === 'TEXTAREA') {
                    alvo.textContent = campo.value;
                } else if (campo.type === 'file') {
                    alvo.remove();
                } else {
                    alvo.setAttribute('value', campo.value);
                    alvo.removeAttribute('placeholder');
                }
            });
            copia.querySelectorAll('.no-print').forEach(el => el.remove());
            copia.removeAttribute('id');
            return copia.outerHTML;
        }

        function cssDe(chave) {
            const doc = docDe(chave);
            return Array.from(doc.querySelectorAll('style:not(#estilo-embutido)')).map(st => st.textContent).join('\n');
        }

        // UM PDF: Commercial Invoice na 1ª página, Packing List na 2ª.
        function imprimirJuntos() {
            const folhaCi = copiarFolha('ci');
            const folhaPl = copiarFolha('pl');
            if (!folhaCi || !folhaPl) { alert('As duas telas ainda estão carregando. Tente de novo em instantes.'); return; }
            const docCi = docDe('ci');
            const selProc = docCi.getElementById('select_processo');
            const processo = selProc && selProc.value ? selProc.value : 'documentos';
            const base = location.href.replace(/[^\/]*$/, '');
            const html = `<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">
                <base href="${base}">
                <title>CI_PL_${processo}</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
                <link href="assets/dashboard.css" rel="stylesheet">
                <style>
                    body { background: #fff; margin: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
                    ${prefixarCss(cssDe('ci'), '#pdf-ci')}
                    ${prefixarCss(cssDe('pl'), '#pdf-pl')}
                    #pdf-ci .doc-sheet, #pdf-pl .doc-sheet { box-shadow: none !important; border: none !important; margin: 0 !important; max-width: 100% !important; }
                    #pdf-pl { break-before: page; page-break-before: always; }
                    input, textarea { color: #000 !important; }
                    .doc-header input { color: #fff !important; -webkit-text-fill-color: #fff !important; }
                    .col-acao { display: none !important; }
                </style></head><body>
                <div id="pdf-ci">${folhaCi}</div>
                <div id="pdf-pl">${folhaPl}</div>
                </body></html>`;

            let quadro = document.getElementById('quadro-pdf');
            if (quadro) quadro.remove();
            quadro = document.createElement('iframe');
            quadro.id = 'quadro-pdf';
            quadro.style.cssText = 'position:fixed; right:0; bottom:0; width:0; height:0; border:0;';
            document.body.appendChild(quadro);
            const docPdf = quadro.contentDocument;
            docPdf.open();
            docPdf.write(html);
            docPdf.close();
            const tituloAntes = document.title;
            quadro.onload = function () {
                // O nome sugerido do arquivo PDF vem do título da página
                document.title = 'CI_PL_' + processo;
                setTimeout(function () {
                    quadro.contentWindow.focus();
                    quadro.contentWindow.print();
                    document.title = tituloAntes;
                }, 300);
            };
        }

        // Copia o valor de um campo pra tela vizinha e dispara o "input" lá
        // (pra ela recalcular o que depende dele, ex.: Total da Invoice).
        function copiarCampo(origem, id) {
            const docOrigem = docDe(origem);
            const docDestino = docDe(outra(origem));
            if (!docOrigem || !docDestino) return;
            const campoOrigem = docOrigem.getElementById(id);
            const campoDestino = docDestino.getElementById(id);
            if (!campoOrigem || !campoDestino || campoDestino.value === campoOrigem.value) return;
            copiando = true;
            campoDestino.value = campoOrigem.value;
            campoDestino.dispatchEvent(new Event('input', { bubbles: true }));
            copiando = false;
        }

        // Totais calculados no Packing List (pesos, Volume, CBM, Dimensions) → Cargo summary da Invoice
        function copiarPesosPL() {
            const docPl = docDe('pl'), docCi = docDe('ci');
            if (!docPl || !docCi) return;
            ['cargo_net_weight', 'cargo_gross_weight', 'cargo_volume', 'cargo_cbm'].forEach(id => {
                const de = docPl.getElementById(id), para = docCi.getElementById(id);
                if (de && para && de.value !== '' && de.value !== '0,00') para.value = de.value;
            });
        }

        function ligarSincronia(chave) {
            const doc = docDe(chave);
            const jan = janelaDe(chave);
            if (!doc || !jan || doc.body.dataset.sincroniaLigada) return;
            doc.body.dataset.sincroniaLigada = '1';

            doc.addEventListener('input', function (ev) {
                if (copiando || !sincronizando()) return;
                const id = ev.target && ev.target.id;
                if (id && CAMPOS_COMUNS.includes(id)) copiarCampo(chave, id);
                if (chave === 'pl') copiarPesosPL();
            });

            // Processo e Filial: escolher numa tela escolhe na outra também
            const selProcesso = doc.getElementById('select_processo');
            if (selProcesso) {
                selProcesso.addEventListener('change', function () {
                    if (copiando || !sincronizando()) return;
                    const docOutra = docDe(outra(chave));
                    const janOutra = janelaDe(outra(chave));
                    const selOutra = docOutra && docOutra.getElementById('select_processo');
                    if (!selOutra || selOutra.value === selProcesso.value) return;
                    // Só repassa processo que existe (já convertido pro código cadastrado)
                    const existe = Array.from(doc.querySelectorAll('#lista-processos-doc option')).some(o => o.value === selProcesso.value);
                    if (!existe) return;
                    copiando = true;
                    selOutra.value = selProcesso.value;
                    if (janOutra && typeof janOutra.carregarProcesso === 'function') janOutra.carregarProcesso();
                    copiando = false;
                });
            }
            const selFilial = doc.getElementById('select_filial');
            if (selFilial) {
                selFilial.addEventListener('change', function () {
                    if (copiando || !sincronizando()) return;
                    const docOutra = docDe(outra(chave));
                    const janOutra = janelaDe(outra(chave));
                    const selOutra = docOutra && docOutra.getElementById('select_filial');
                    if (!selOutra) return;
                    // As duas telas listam as mesmas filiais, mas com "value" diferente
                    // (índice x nome) — casa pelo texto visível da opção.
                    const texto = selFilial.options[selFilial.selectedIndex].text.trim();
                    const opcao = Array.from(selOutra.options).find(o => o.text.trim() === texto);
                    if (!opcao) return;
                    copiando = true;
                    selOutra.value = opcao.value;
                    if (janOutra && typeof janOutra.aplicarFilial === 'function') janOutra.aplicarFilial();
                    copiando = false;
                });
            }
        }

        // Valores CALCULADOS (sem digitação, então sem evento): a cada 1s copia
        // Net Price/Total da Invoice pro Packing List e os pesos do Packing List
        // pro Cargo summary da Invoice.
        setInterval(function () {
            if (!sincronizando()) return;
            const docCi = docDe('ci'), docPl = docDe('pl');
            if (!docCi || !docPl) return;
            ['valor_net', 'valor_total'].forEach(id => {
                const de = docCi.getElementById(id), para = docPl.getElementById(id);
                if (de && para && para.value !== de.value && docPl.activeElement !== para) para.value = de.value;
            });
            copiarPesosPL();
        }, 1000);

        ['ci', 'pl'].forEach(chave => {
            frames[chave].addEventListener('load', function () {
                enxugar(chave);
                aplicarZoom();
                ligarSincronia(chave);
            });
        });
    </script>
</body>
</html>
