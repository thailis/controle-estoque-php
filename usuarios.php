<?php
require_once 'conexao.php';
require_once 'auth.php';

exigirLogin();
// Só Comprador gerencia usuários. Visualizador nem carrega esta tela.
exigirComprador();

function h(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

$mensagens = [];
$erros = [];

// Criar novo usuário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar') {
    exigirComprador();

    $nome = trim($_POST['nome'] ?? '');
    $usuario = trim($_POST['usuario'] ?? '');
    $senha = (string) ($_POST['senha'] ?? '');
    $papel = ($_POST['papel'] ?? '') === 'comprador' ? 'comprador' : 'visualizador';

    if ($nome === '' || $usuario === '' || $senha === '') {
        $erros[] = 'Preencha nome, usuário e senha.';
    } elseif (strlen($senha) < 6) {
        $erros[] = 'A senha precisa ter pelo menos 6 caracteres.';
    } else {
        $hash = password_hash($senha, PASSWORD_DEFAULT);
        try {
            $stmt = mysqli_prepare($conn, "INSERT INTO usuarios (nome, usuario, senha_hash, papel) VALUES (?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'ssss', $nome, $usuario, $hash, $papel);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $mensagens[] = "Usuário \"$usuario\" criado com sucesso.";
        } catch (mysqli_sql_exception $e) {
            $erros[] = str_contains($e->getMessage(), 'Duplicate')
                ? "Já existe um usuário com o login \"$usuario\"."
                : 'Não foi possível criar o usuário.';
        }
    }
}

// Trocar o papel (comprador <-> visualizador)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'trocar_papel') {
    exigirComprador();
    $id = (int) ($_POST['id'] ?? 0);
    $novoPapel = ($_POST['novo_papel'] ?? '') === 'comprador' ? 'comprador' : 'visualizador';
    $stmt = mysqli_prepare($conn, "UPDATE usuarios SET papel = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'si', $novoPapel, $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $mensagens[] = 'Papel atualizado.';
}

// Ativar/desativar (em vez de excluir de vez, é mais seguro)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'alternar_ativo') {
    exigirComprador();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id === (int) ($_SESSION['usuario_id'] ?? 0)) {
        $erros[] = 'Você não pode desativar seu próprio usuário.';
    } else {
        $stmt = mysqli_prepare($conn, "UPDATE usuarios SET ativo = NOT ativo WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $mensagens[] = 'Status atualizado.';
    }
}

// Redefinir senha
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'redefinir_senha') {
    exigirComprador();
    $id = (int) ($_POST['id'] ?? 0);
    $novaSenha = (string) ($_POST['nova_senha'] ?? '');
    if (strlen($novaSenha) < 6) {
        $erros[] = 'A nova senha precisa ter pelo menos 6 caracteres.';
    } else {
        $hash = password_hash($novaSenha, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conn, "UPDATE usuarios SET senha_hash = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $hash, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $mensagens[] = 'Senha redefinida.';
    }
}

$usuarios = [];
$res = mysqli_query($conn, "SELECT id, nome, usuario, papel, ativo, criado_em FROM usuarios ORDER BY nome");
while ($linha = mysqli_fetch_assoc($res)) {
    $usuarios[] = $linha;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Usuários | Controle MRP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/dashboard.css" rel="stylesheet">
</head>
<body>
    <header class="topbar">
        <div class="container-fluid dashboard-container d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="eyebrow">Supply Chain • Planejamento de materiais</span>
                <h1>Usuários</h1>
                <p class="mb-0">Logado como <?php echo h(nomeUsuarioLogado()); ?> (Comprador)</p>
            </div>
            <nav class="d-flex flex-wrap gap-2" aria-label="Ações do sistema">
                <a class="btn btn-light btn-sm" href="index.php">🏠 Dashboard</a>
                <a class="btn btn-outline-light btn-sm" href="trocar_senha.php">🔑 Alterar senha</a>
                <a class="btn btn-outline-light btn-sm" href="logout.php">Sair</a>
            </nav>
        </div>
    </header>

    <main class="container-fluid dashboard-container py-4">
        <?php foreach ($mensagens as $msg): ?>
            <div class="alert alert-success"><?php echo h($msg); ?></div>
        <?php endforeach; ?>
        <?php foreach ($erros as $err): ?>
            <div class="alert alert-danger"><?php echo h($err); ?></div>
        <?php endforeach; ?>

        <section class="table-card mb-4">
            <div class="table-toolbar">
                <div>
                    <span class="eyebrow text-primary">Novo</span>
                    <h2>Cadastrar usuário</h2>
                </div>
            </div>
            <form method="POST" class="row g-2 p-3">
                <input type="hidden" name="acao" value="criar">
                <div class="col-md-3">
                    <label class="form-label">Nome</label>
                    <input type="text" name="nome" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Usuário (login)</label>
                    <input type="text" name="usuario" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Senha</label>
                    <input type="password" name="senha" class="form-control" minlength="6" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Papel</label>
                    <select name="papel" class="form-select">
                        <option value="visualizador">Visualizador</option>
                        <option value="comprador">Comprador</option>
                    </select>
                </div>
                <div class="col-md-1 d-grid align-items-end">
                    <button type="submit" class="btn btn-primary">Criar</button>
                </div>
            </form>
        </section>

        <section class="table-card">
            <div class="table-toolbar">
                <div>
                    <span class="eyebrow text-primary">Cadastrados</span>
                    <h2><?php echo count($usuarios); ?> usuário(s)</h2>
                </div>
            </div>
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Usuário</th>
                        <th>Papel</th>
                        <th>Status</th>
                        <th>Redefinir senha</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $u): ?>
                        <tr>
                            <td><?php echo h($u['nome']); ?></td>
                            <td><?php echo h($u['usuario']); ?></td>
                            <td>
                                <form method="POST" class="d-flex gap-2 align-items-center">
                                    <input type="hidden" name="acao" value="trocar_papel">
                                    <input type="hidden" name="id" value="<?php echo (int) $u['id']; ?>">
                                    <select name="novo_papel" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto;">
                                        <option value="visualizador" <?php echo $u['papel'] === 'visualizador' ? 'selected' : ''; ?>>Visualizador</option>
                                        <option value="comprador" <?php echo $u['papel'] === 'comprador' ? 'selected' : ''; ?>>Comprador</option>
                                    </select>
                                </form>
                            </td>
                            <td>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="acao" value="alternar_ativo">
                                    <input type="hidden" name="id" value="<?php echo (int) $u['id']; ?>">
                                    <button type="submit" class="btn btn-sm <?php echo $u['ativo'] ? 'btn-outline-danger' : 'btn-outline-success'; ?>">
                                        <?php echo $u['ativo'] ? 'Desativar' : 'Ativar'; ?>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <form method="POST" class="d-flex gap-2">
                                    <input type="hidden" name="acao" value="redefinir_senha">
                                    <input type="hidden" name="id" value="<?php echo (int) $u['id']; ?>">
                                    <input type="password" name="nova_senha" class="form-control form-control-sm" placeholder="Nova senha" minlength="6" style="width:160px;">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Redefinir</button>
                                </form>
                            </td>
                            <td><?php echo $u['ativo'] ? '' : '<span class="text-muted">Inativo</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </main>
</body>
</html>
