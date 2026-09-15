<?php
// auth.php
//
// Inclua este arquivo no topo de TODA página que exige login, logo depois do
// require_once 'conexao.php'. Ele cuida da sessão e oferece os helpers:
//   exigirLogin()     -> redireciona pro login.php se não estiver logado
//   ehComprador()     -> true se o usuário logado pode editar dados
//   exigirComprador() -> bloqueia (403) qualquer ação de escrita se não for comprador
//
// Papéis existentes: 'comprador' (acessa e edita tudo) e 'visualizador'
// (só enxerga, não pode alterar nada).

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function exigirLogin(): void
{
    if (empty($_SESSION['usuario_id'])) {
        header('Location: login.php');
        exit;
    }
}

function ehComprador(): bool
{
    return ($_SESSION['papel'] ?? '') === 'comprador';
}

// Chame isso como a PRIMEIRA linha de qualquer bloco que trata uma ação de
// escrita (POST que insere/atualiza/apaga dado). Se o usuário for
// 'visualizador', a ação é barrada aqui, mesmo que ele tente forçar a URL
// ou reenviar o formulário manualmente.
function exigirComprador(): void
{
    if (!ehComprador()) {
        http_response_code(403);
        die('Você está como Visualizador e não tem permissão para alterar dados. Fale com um Comprador.');
    }
}

function nomeUsuarioLogado(): string
{
    return (string) ($_SESSION['usuario_nome'] ?? '');
}
