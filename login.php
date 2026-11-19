<?php
require_once 'conexao.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Pra onde ir depois de entrar: a tela que a pessoa tentou abrir (ex.: uma
// tela da Importação), se veio em ?voltar=. Só aceita endereço interno do
// próprio site (começa com "/" e não com "//"), nunca um site de fora.
$voltar = (string) ($_GET['voltar'] ?? '');
if ($voltar === '' || $voltar[0] !== '/' || str_starts_with($voltar, '//') || str_contains($voltar, '\\')) {
    $voltar = 'index.php';
}

// Já logado? Manda direto pra tela de destino.
if (!empty($_SESSION['usuario_id'])) {
    header('Location: ' . $voltar);
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
            header('Location: ' . $voltar);
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
        .idioma-login { display: flex; align-items: center; justify-content: flex-end; gap: 6px; margin-bottom: 12px; font-size: .85rem; color: #6c7a89; }
        .idioma-login select { width: auto; }
        .goog-te-banner-frame, body > .skiptranslate { display: none !important; }
        body { top: 0 !important; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="idioma-login notranslate" title="Idioma / Language / 语言">
            <span>🌐</span>
            <select id="idioma-login" class="form-select form-select-sm" aria-label="Idioma">
                <option value="pt">Português</option>
                <option value="en">English</option>
                <option value="zh-CN">中文</option>
            </select>
        </div>
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
    <div id="google_translate_element" style="display:none"></div>
    <script>
        // Idioma do site: escolhido aqui no login e guardado no navegador (cookie
        // "googtrans"). Vale pra todas as telas do MRP e da Importação.
        (function () {
            document.querySelectorAll('input').forEach(function (el) { el.classList.add('notranslate'); });
            // sigla MRP não é traduzida
            document.querySelectorAll('h1').forEach(function (h) { h.innerHTML = h.innerHTML.replace(/\bMRP\b/, '<span class="notranslate">MRP</span>'); });
            var atual = (document.cookie.match(/(?:^|;\s*)googtrans=\/[^\/;]*\/([^;]+)/) || [])[1] || 'pt';
            var seletor = document.getElementById('idioma-login');
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
</body>
</html>
