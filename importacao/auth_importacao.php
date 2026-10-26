<?php
// importacao/auth_importacao.php
//
// Login do site de Importação = o MESMO login do MRP (mesma sessão, mesmos
// usuários e papéis). Quem já entrou no MRP abre a Importação direto.
//   - sem login        -> vai pro login do MRP e, depois de entrar, volta pra tela que tentou abrir;
//   - Visualizador     -> só enxerga: qualquer gravação (POST) é barrada.
require_once __DIR__ . '/../auth.php';

if (empty($_SESSION['usuario_id'])) {
    $voltar = (string) ($_SERVER['REQUEST_URI'] ?? '/importacao/follow.php');
    header('Location: /login.php?voltar=' . urlencode($voltar));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !ehComprador()) {
    http_response_code(403);
    $mensagemBloqueio = 'Você está como Visualizador e não tem permissão para alterar dados. Fale com um Comprador ou Administrador.';
    if (str_starts_with((string) ($_POST['acao'] ?? ''), 'ajax')) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'erro' => $mensagemBloqueio]);
    } else {
        echo $mensagemBloqueio;
    }
    exit;
}

// Mesma otimização do MRP: libera a sessão pra outros cliques não ficarem na fila.
session_write_close();
