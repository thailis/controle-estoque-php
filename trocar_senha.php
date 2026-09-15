<?php
require_once 'conexao.php';
require_once 'auth.php';

exigirLogin();

function h(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

$mensagem = null;
$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $senhaAtual = (string) ($_POST['senha_atual'] ?? '');
    $novaSenha = (string) ($_POST['nova_senha'] ?? '');
    $confirmarSenha = (string) ($_POST['confirmar_senha'] ?? '');

    if ($senhaAtual === '' || $novaSenha === '' || $confirmarSenha === '') {
        $erro = 'Preencha todos os campos.';
    } elseif (strlen($novaSenha) < 6) {
        $erro = 'A nova senha precisa ter pelo menos 6 caracteres.';
    } elseif ($novaSenha !== $confirmarSenha) {
        $erro = 'A confirmação não bate com a nova senha.';
    } else {
        $stmt = mysqli_prepare($conn, "SELECT senha_hash FROM usuarios WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $_SESSION['usuario_id']);
        mysqli_stmt_execute($stmt);
        $linha = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$linha || !password_verify($senhaAtual, $linha['senha_hash'])) {
            $erro = 'Senha atual incorreta.';
        } else {
            $hash = password_hash($novaSenha, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, "UPDATE usuarios SET senha_hash = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'si', $hash, $_SESSION['usuario_id']);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $mensagem = 'Senha alterada com sucesso.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Alterar senha | Controle MRP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/dashboard.css" rel="stylesheet">
</head>
<body>
    <header class="topbar">
        <div class="container-fluid dashboard-container d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="eyebrow">Supply Chain • Planejamento de materiais</span>
                <h1>Alterar senha</h1>
                <p class="mb-0">Logado como <?php echo h(nomeUsuarioLogado()); ?></p>
            </div>
            <nav class="d-flex flex-wrap gap-2" aria-label="Ações do sistema">
                <a class="btn btn-light btn-sm" href="index.php">🏠 Dashboard</a>
                <a class="btn btn-outline-light btn-sm" href="logout.php">🚪 Sair</a>
            </nav>
        </div>
    </header>

    <main class="container-fluid dashboard-container py-4">
        <section class="table-card" style="max-width:420px;">
            <div class="p-3">
                <?php if ($mensagem !== null): ?>
                    <div class="alert alert-success"><?php echo h($mensagem); ?></div>
                <?php endif; ?>
                <?php if ($erro !== null): ?>
                    <div class="alert alert-danger"><?php echo h($erro); ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Senha atual</label>
                        <input type="password" name="senha_atual" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nova senha</label>
                        <input type="password" name="nova_senha" class="form-control" minlength="6" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirmar nova senha</label>
                        <input type="password" name="confirmar_senha" class="form-control" minlength="6" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Salvar nova senha</button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
