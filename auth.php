<?php
// auth.php
//
// Inclua este arquivo no topo de TODA página que exige login, logo depois do
// require_once 'conexao.php'. Ele cuida da sessão e oferece os helpers:
//   exigirLogin()        -> redireciona pro login.php se não estiver logado
//   ehComprador()        -> true se o usuário logado pode editar dados (comprador, administrador OU produção)
//   exigirComprador()    -> bloqueia (403) qualquer ação de escrita se não puder editar
//   ehAdministrador()    -> true só pro papel administrador
//   exigirAdministrador()-> bloqueia (403) quem não for administrador (usado em usuarios.php)
//
// Papéis existentes:
//   'administrador' -> acessa e edita tudo (igual comprador) + gerencia usuários
//   'comprador'     -> acessa e edita tudo, EXCETO gerenciar usuários
//   'producao'      -> igual ao comprador, EXCETO: não altera a BOM (só enxerga) e
//                      não pode usar "Limpar tabela antes de importar" (nem "Limpar
//                      tudo" / "Excluir e substituir") em nenhuma importação
//   'visualizador'  -> só enxerga, não pode alterar nada nem ver a tela de usuários
//
// Também injeta o seletor de idioma (Português / English / 中文) em todas as telas.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------- Tradução das telas (Português / English / 中文) ----------
// Seletor de idioma fixo no canto de TODAS as telas (MRP e Importação), via Google
// Tradutor. Fica aqui porque toda página inclui o auth.php. Traduz títulos, menus,
// botões, rótulos e cabeçalhos de tabela; os DADOS (linhas das tabelas, campos
// digitados, listas de sugestão) ficam de fora (classe notranslate), pra códigos,
// descrições e nomes de fornecedor não serem "traduzidos" errado.
// O idioma escolhido fica guardado no navegador e vale pras outras telas.
// Só mexe em páginas HTML (CSV, JSON e PDF não são tocados).
if (PHP_SAPI !== 'cli') {
    ob_start(function (string $html): string {
        if (stripos($html, '</body>') === false || stripos($html, 'id="seletor-idioma-site"') !== false) {
            return $html;
        }
        $widget = <<<'HTML'
<div id="seletor-idioma-site" class="notranslate no-print" title="Idioma / Language / 语言">
    <span>🌐</span>
    <select id="seletor-idioma-select" aria-label="Idioma">
        <option value="pt">Português</option>
        <option value="en">English</option>
        <option value="zh-CN">中文</option>
    </select>
    <div id="google_translate_element" style="display:none"></div>
</div>
<style>
    #seletor-idioma-site { position: fixed; right: 14px; bottom: 14px; z-index: 9999; background: #fff;
        border: 1px solid #dce4ec; border-radius: 10px; box-shadow: 0 4px 14px rgba(18,48,74,.15);
        padding: 4px 8px; display: flex; align-items: center; gap: 6px; font-size: 13px; }
    #seletor-idioma-select { font-size: 13px; padding: 2px 4px; border: 1px solid #dce4ec; border-radius: 6px; background: #fff; cursor: pointer; }
    /* esconde a barra do Google no topo, que empurrava o site pra baixo */
    .goog-te-banner-frame, body > .skiptranslate { display: none !important; }
    body { top: 0 !important; }
    @media print { #seletor-idioma-site { display: none !important; } }
</style>
<script>
    (function () {
        // dentro de um quadro (ex.: CI / PL) o seletor fica escondido — a tradução
        // escolhida na tela principal continua valendo lá dentro
        if (window.self !== window.top) { document.getElementById('seletor-idioma-site').style.display = 'none'; }
        // dados ficam sem traduzir
        document.querySelectorAll('tbody, datalist, input, textarea, .component-code').forEach(function (el) { el.classList.add('notranslate'); });

        // Siglas nunca traduzidas (ex.: BOM virava "GOOD"). Só em MAIÚSCULAS, como palavra inteira.
        var siglas = /\b(BOM|MRP|EDI|MOQ|NCM|SAP|PO|FFW|ETA|ETD|TT|FT|CI|PL|CBM|RB|OA|UnM|U\.M\.)\b/g;
        var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
            acceptNode: function (n) {
                var pai = n.parentNode;
                if (!pai || /^(SCRIPT|STYLE|TEXTAREA|OPTION)$/.test(pai.nodeName) || pai.closest('.notranslate')) return NodeFilter.FILTER_REJECT;
                siglas.lastIndex = 0;
                return siglas.test(n.nodeValue) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
            }
        });
        var nos = [];
        while (walker.nextNode()) nos.push(walker.currentNode);
        nos.forEach(function (n) {
            var frag = document.createDocumentFragment();
            var texto = n.nodeValue, ultimo = 0, m;
            siglas.lastIndex = 0;
            while ((m = siglas.exec(texto)) !== null) {
                if (m.index > ultimo) frag.appendChild(document.createTextNode(texto.slice(ultimo, m.index)));
                var span = document.createElement('span');
                span.className = 'notranslate';
                span.textContent = m[0];
                frag.appendChild(span);
                ultimo = m.index + m[0].length;
            }
            if (ultimo < texto.length) frag.appendChild(document.createTextNode(texto.slice(ultimo)));
            n.parentNode.replaceChild(frag, n);
        });

        // Idioma escolhido = cookie "googtrans" (/pt/en, /pt/zh-CN). Trocar = grava o
        // cookie e recarrega; o Google Tradutor aplica sozinho ao abrir a página.
        var atual = (document.cookie.match(/(?:^|;\s*)googtrans=\/[^\/;]*\/([^;]+)/) || [])[1] || 'pt';
        var seletor = document.getElementById('seletor-idioma-select');
        seletor.value = ['en', 'zh-CN'].indexOf(atual) >= 0 ? atual : 'pt';
        seletor.addEventListener('change', function () {
            var host = location.hostname;
            var expira = this.value === 'pt' ? '; expires=Thu, 01 Jan 1970 00:00:00 GMT' : '';
            var valor = this.value === 'pt' ? '' : '/pt/' + this.value;
            document.cookie = 'googtrans=' + valor + '; path=/' + expira;
            document.cookie = 'googtrans=' + valor + '; path=/; domain=' + host + expira;
            document.cookie = 'googtrans=' + valor + '; path=/; domain=.' + host + expira;
            location.reload();
        });
    })();
    function googleTranslateElementInit() {
        new google.translate.TranslateElement({ pageLanguage: 'pt', includedLanguages: 'pt,en,zh-CN', autoDisplay: false }, 'google_translate_element');
    }
</script>
<script src="https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit" async></script>
HTML;
        return str_ireplace('</body>', $widget . '</body>', $html);
    });
}

// ---------- Restrições do papel Produção (valem pra TODAS as telas, MRP e Importação) ----------
// Ficam aqui no auth.php pra não depender de cada tela: toda página já inclui este arquivo.
if (($_SESSION['papel'] ?? '') === 'producao') {
    $scriptAtualProducao = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $ehPostProducao = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $bloqueioProducao = null;

    if ($ehPostProducao && $scriptAtualProducao === 'bomnova.php') {
        $bloqueioProducao = 'Seu acesso é Produção: a BOM é só para consulta.';
    }
    if ($ehPostProducao && $bloqueioProducao === null) {
        foreach ($_POST as $chavePost => $valorPost) {
            $marcado = !in_array(strtolower(trim((string) (is_array($valorPost) ? '' : $valorPost))), ['', '0', 'false', 'off'], true);
            if (str_starts_with((string) $chavePost, 'limpar') && $marcado) {
                $bloqueioProducao = 'Seu acesso é Produção: não é permitido limpar a tabela antes de importar.';
                break;
            }
        }
        if (($_POST['modo_importacao'] ?? '') === 'substituir') {
            $bloqueioProducao = 'Seu acesso é Produção: não é permitido "Excluir e substituir" na importação.';
        }
    }
    if ($bloqueioProducao !== null) {
        http_response_code(403);
        if (str_starts_with((string) ($_POST['acao'] ?? ''), 'ajax')) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'erro' => $bloqueioProducao]);
        } else {
            echo $bloqueioProducao;
        }
        exit;
    }

    // Na tela: desabilita as opções de limpar (e deixa a BOM só leitura no clique),
    // pra quem é Produção nem conseguir marcar. Só mexe em páginas HTML.
    ob_start(function (string $html) use ($scriptAtualProducao): string {
        if (stripos($html, '</body>') === false) {
            return $html;
        }
        $script = <<<'JS'
<script>
(function () {
    var aviso = 'Indisponível para o acesso Produção';
    document.querySelectorAll('input[name^="limpar"], input[name="modo_importacao"][value="substituir"]').forEach(function (campo) {
        campo.checked = false;
        campo.disabled = true;
        campo.title = aviso;
        var rotulo = campo.id ? document.querySelector('label[for="' + campo.id + '"]') : campo.closest('label');
        if (rotulo) { rotulo.style.opacity = '.5'; rotulo.title = aviso; }
    });
})();
</script>
JS;
        if ($scriptAtualProducao === 'bomnova.php') {
            $script .= "<script>document.querySelectorAll('.celula-editavel').forEach(function (c) { c.classList.remove('celula-editavel'); c.removeAttribute('title'); });</script>";
        }
        return str_ireplace('</body>', $script . '</body>', $html);
    });
}

function exigirLogin(): void
{
    if (empty($_SESSION['usuario_id'])) {
        header('Location: login.php');
        exit;
    }
    // Libera a sessão logo depois de conferir o login. O PHP trava a sessão
    // enquanto a página roda — sem isso, um 2º clique (ou um AJAX, como o
    // duplo clique de edição) fica esperando a tela anterior terminar.
    // $_SESSION continua legível normalmente; só não grava mais nesta página
    // (nenhuma página que chama exigirLogin() grava na sessão).
    session_write_close();
}

function ehComprador(): bool
{
    return in_array($_SESSION['papel'] ?? '', ['comprador', 'administrador', 'producao'], true);
}

function ehProducao(): bool
{
    return ($_SESSION['papel'] ?? '') === 'producao';
}

function ehAdministrador(): bool
{
    return ($_SESSION['papel'] ?? '') === 'administrador';
}

// Chame isso como a PRIMEIRA linha de qualquer bloco que trata uma ação de
// escrita (POST que insere/atualiza/apaga dado). Se o usuário for
// 'visualizador', a ação é barrada aqui, mesmo que ele tente forçar a URL
// ou reenviar o formulário manualmente.
function exigirComprador(): void
{
    if (!ehComprador()) {
        http_response_code(403);
        die('Você está como Visualizador e não tem permissão para alterar dados. Fale com um Comprador ou Administrador.');
    }
}

// Chame no topo de usuarios.php (e em cada ação de escrita lá dentro). Só
// Administrador gerencia usuários — Comprador não tem mais esse acesso.
function exigirAdministrador(): void
{
    if (!ehAdministrador()) {
        http_response_code(403);
        die('Só Administradores podem gerenciar usuários.');
    }
}

function nomeUsuarioLogado(): string
{
    return (string) ($_SESSION['usuario_nome'] ?? '');
}

function rotuloPapel(string $papel): string
{
    return match ($papel) {
        'administrador' => 'Administrador',
        'comprador' => 'Comprador',
        'producao' => 'Produção',
        default => 'Visualizador',
    };
}

