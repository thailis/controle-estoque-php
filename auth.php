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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
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

