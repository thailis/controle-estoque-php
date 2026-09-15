<?php
require_once 'conexao.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Já logado? Manda direto pro dashboard.
if (!empty($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

function h(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $senha = (string) ($_POST['senha'] ?? '');

    if ($usuario === '' || $senha === '') {
        $erro = 'Preencha usuário e senha.';
    } else {
        $stmt = mysqli_prepare($conn, "SELECT id, nome, senha_hash, papel FROM usuarios WHERE usuario = ? AND ativo = 1");
        mysqli_stmt_bind_param($stmt, 's', $usuario);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $linha = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if ($linha && password_verify($senha, $linha['senha_hash'])) {
            session_regenerate_id(true);
            $_SESSION['usuario_id'] = $linha['id'];
            $_SESSION['usuario_nome'] = $linha['nome'];
            $_SESSION['papel'] = $linha['papel'];
            header('Location: index.php');
            exit;
        }

        $erro = 'Usuário ou senha incorretos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar | Controle MRP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f8fb; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-card { max-width: 380px; width: 100%; background: #fff; border-radius: 16px; padding: 32px; box-shadow: 0 8px 30px rgba(20,30,50,.08); }
        .login-card h1 { font-size: 1.3rem; margin-bottom: 4px; }
        .login-card p.eyebrow { color: #6c7a89; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px; }
    </style>
</head>
<body>
    <div class="login-card">
        <p class="eyebrow">Supply Chain • Planejamento de materiais</p>
        <h1>Entrar no Controle MRP</h1>
        <p class="text-muted mb-4">Use seu usuário e senha cadastrados.</p>

        <?php if ($erro !== null): ?>
            <div class="alert alert-danger py-2"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label" for="usuario">Usuário</label>
                <input type="text" class="form-control" id="usuario" name="usuario" autofocus required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="senha">Senha</label>
                <input type="password" class="form-control" id="senha" name="senha" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Entrar</button>
        </form>
    </div>
</body>
</html>
