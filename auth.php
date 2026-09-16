<?php
// auth.php
//
// Inclua este arquivo no topo de TODA página que exige login, logo depois do
// require_once 'conexao.php'. Ele cuida da sessão e oferece os helpers:
//   exigirLogin()        -> redireciona pro login.php se não estiver logado
//   ehComprador()        -> true se o usuário logado pode editar dados (comprador OU administrador)
//   exigirComprador()    -> bloqueia (403) qualquer ação de escrita se não puder editar
//   ehAdministrador()    -> true só pro papel administrador
//   exigirAdministrador()-> bloqueia (403) quem não for administrador (usado em usuarios.php)
//
// Papéis existentes:
//   'administrador' -> acessa e edita tudo (igual comprador) + gerencia usuários
//   'comprador'     -> acessa e edita tudo, EXCETO gerenciar usuários
//   'visualizador'  -> só enxerga, não pode alterar nada nem ver a tela de usuários

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
    return in_array($_SESSION['papel'] ?? '', ['comprador', 'administrador'], true);
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
        default => 'Visualizador',
    };
}

