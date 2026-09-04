<?php
// confirmar_entrega.php
//
// Ponto único de integração entre o site de Importação e o MRP.
// Roda quando o usuário marca um "follow" como entregue (preenche/confirma a
// data "efetiva"). Antes de tocar no estoque do MRP, valida se o
// codigo_componente daquele processo realmente existe lá — se não existir,
// BLOQUEIA e avisa, em vez de inserir estoque "no vácuo" silenciosamente.

require_once 'conexao.php'; // conexão com o PRÓPRIO banco (controle_importacao)

function h(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

// Segunda conexão, só pra falar com o MRP — credenciais PRÓPRIAS e mínimas
// (usuário dedicado, só SELECT em parametros_compra/bomnova e INSERT em
// estoque; nunca as mesmas credenciais do site interno do MRP).
function conectarMrp(): mysqli
{
    $host = getenv('MRP_DB_HOST') ?: '';
    $port = (int) (getenv('MRP_DB_PORT') ?: 4000);
    $dbname = getenv('MRP_DB_NAME') ?: 'controle_mrp';
    $user = getenv('MRP_DB_USER') ?: '';
    $password = getenv('MRP_DB_PASSWORD') ?: '';
    $sslCa = getenv('MRP_DB_SSL_CA') ?: '/etc/ssl/certs/ca-certificates.crt';

    if ($host === '' || $user === '' || $password === '') {
        throw new RuntimeException('Configuração de conexão com o MRP incompleta. Defina MRP_DB_HOST, MRP_DB_USER e MRP_DB_PASSWORD.');
    }

    $connMrp = mysqli_init();
    // Timeout curto (8s) — sem isso, uma instabilidade de rede deixa a
    // requisição inteira travada até o navegador estourar (504), sem
    // nenhuma mensagem de erro útil. Com o timeout, falha rápido e cai
    // no catch() do chamador, mostrando um erro claro em vez de travar.
    mysqli_options($connMrp, MYSQLI_OPT_CONNECT_TIMEOUT, 8);
    mysqli_ssl_set($connMrp, null, null, $sslCa, null, null);
    $conectou = @mysqli_real_connect($connMrp, $host, $user, $password, $dbname, $port, null, MYSQLI_CLIENT_SSL);

    if (!$conectou) {
        throw new RuntimeException('Não consegui conectar ao MRP em 8 segundos: ' . mysqli_connect_error());
    }

    mysqli_set_charset($connMrp, 'utf8mb4');

    return $connMrp;
}

// Confirma se o componente existe de verdade no MRP antes de deixar a
// integração seguir. Checa em parametros_compra OU bomnova — basta existir
// em um dos dois pra considerar válido (um componente pode ter parâmetros
// cadastrados sem ainda estar na BOM, ou vice-versa). Também busca a descrição
// canônica do componente direto da BOM (só de linhas ativas, mrp <> 'N') — é essa
// descrição que vai pro estoque, não o texto digitado no processo de importação
// (que pode divergir do nome oficial do componente no MRP).
function validarComponenteMrp(mysqli $connMrp, string $codigoComponente): array
{
    $codigo = trim($codigoComponente);
    if ($codigo === '') {
        return ['valido' => false, 'motivo' => 'Código do componente está vazio no cadastro do processo.', 'descricao' => null];
    }

    $stmt = mysqli_prepare($connMrp, "
        SELECT
            (SELECT COUNT(*) FROM parametros_compra WHERE TRIM(codigo_componente) = ?) AS em_parametros,
            (SELECT COUNT(*) FROM bomnova WHERE TRIM(codigo_componente) = ?) AS em_bom,
            (SELECT MAX(COALESCE(NULLIF(TRIM(descricao), ''), NULL))
               FROM bomnova
               WHERE TRIM(codigo_componente) = ?
                 AND (mrp IS NULL OR UPPER(TRIM(mrp)) <> 'N')) AS descricao_bom
    ");
    mysqli_stmt_bind_param($stmt, 'sss', $codigo, $codigo, $codigo);
    mysqli_stmt_execute($stmt);
    $resultado = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    $existe = ((int) $resultado['em_parametros'] > 0) || ((int) $resultado['em_bom'] > 0);

    if (!$existe) {
        return [
            'valido' => false,
            'motivo' => "Componente \"$codigo\" não foi encontrado no MRP (nem em Parâmetros de Compra, nem na BOM). Confira se o código foi digitado certo antes de confirmar a entrega.",
            'descricao' => null,
        ];
    }

    return ['valido' => true, 'motivo' => null, 'descricao' => $resultado['descricao_bom']];
}

$mensagem = null;
$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'confirmar_entrega') {
    $followId = (int) ($_POST['follow_id'] ?? 0);
    $dataEfetiva = trim($_POST['data_efetiva'] ?? '');

    if ($followId <= 0 || $dataEfetiva === '') {
        $erro = 'Dados incompletos — selecione o embarque e informe a data de entrega.';
    } else {
        // Busca o processo ligado a esse follow, e dele puxa componente + quantidade
        $stmt = mysqli_prepare($conn, "
            SELECT f.id, f.processo, f.integrado_mrp, p.codigo_componente, p.descricao, p.quantidade, p.planta
            FROM follow f
            JOIN processos p ON p.processo = f.processo
            WHERE f.id = ?
        ");
        mysqli_stmt_bind_param($stmt, 'i', $followId);
        mysqli_stmt_execute($stmt);
        $linha = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$linha) {
            $erro = 'Não encontrei esse embarque (ou o processo vinculado a ele) no banco.';
        } elseif ((int) $linha['integrado_mrp'] === 1) {
            $erro = 'Esse embarque já foi integrado ao MRP anteriormente — não é possível integrar de novo (evita duplicar estoque).';
        } else {
            try {
                $connMrp = conectarMrp();
                $validacao = validarComponenteMrp($connMrp, $linha['codigo_componente']);

                if (!$validacao['valido']) {
                    // BLOQUEIA — não insere nada no MRP, não marca como integrado.
                    // O follow.efetiva pode até já estar preenchido (é rastreio
                    // logístico, continua válido), mas a integração de estoque
                    // fica pendente até o componente ser corrigido/cadastrado.
                    $erro = "❌ Integração bloqueada — {$validacao['motivo']}";
                } else {
                    // Componente confirmado no MRP — segue com a integração.
                    // Usa a descrição CANÔNICA vinda da BOM (não o texto digitado no
                    // processo de importação, que pode divergir) — com o texto do
                    // processo como reserva só se a BOM não tiver descrição nenhuma.
                    //
                    // A planta é fixada como "Importação" — não a planta física de
                    // destino (ex.: 2401). Isso faz essa entrada aparecer numa coluna
                    // PRÓPRIA na tela de Estoque (o site já gera uma coluna por planta
                    // distinta encontrada nos dados, então basta essa string nova pra
                    // criar a coluna sozinha, sem precisar mexer no estoque.php). Assim:
                    // - a descrição do componente não é mais afetada por texto transacional
                    // - o estoque anterior não é perdido (o Total continua somando tudo)
                    // - fica visível separadamente o que entrou via importação
                    $stmtEstoque = mysqli_prepare($connMrp, "
                        INSERT INTO estoque (codigo_componente, descricao, estoque, planta, origem)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $origem = 'importacao:' . $linha['processo'];
                    $descricao = $validacao['descricao'] ?: $linha['descricao'];
                    $plantaImportacao = 'Importação';
                    mysqli_stmt_bind_param(
                        $stmtEstoque, 'ssdss',
                        $linha['codigo_componente'], $descricao, $linha['quantidade'], $plantaImportacao, $origem
                    );
                    mysqli_stmt_execute($stmtEstoque);
                    mysqli_stmt_close($stmtEstoque);
                    mysqli_close($connMrp);

                    // Marca o follow como entregue, integrado e FECHADO — o status do
                    // Follow só vira "fechado" neste momento exato, nunca é digitado
                    // manualmente. Também trava contra duplicar (integrado_mrp = 1).
                    $stmtUpdate = mysqli_prepare($conn, "
                        UPDATE follow
                        SET efetiva = ?, integrado_mrp = 1, integrado_em = NOW(), status = 'fechado'
                        WHERE id = ?
                    ");
                    mysqli_stmt_bind_param($stmtUpdate, 'si', $dataEfetiva, $followId);
                    mysqli_stmt_execute($stmtUpdate);
                    mysqli_stmt_close($stmtUpdate);

                    // O status do Processo segue o mesmo gatilho: nasce "aberto",
                    // e só vira "finalizado" neste exato momento — nunca digitado.
                    $stmtProcessoStatus = mysqli_prepare($conn, "UPDATE processos SET status = 'finalizado' WHERE processo = ?");
                    mysqli_stmt_bind_param($stmtProcessoStatus, 's', $linha['processo']);
                    mysqli_stmt_execute($stmtProcessoStatus);
                    mysqli_stmt_close($stmtProcessoStatus);

                    $mensagem = "✅ Entrega confirmada e estoque do MRP atualizado — componente {$linha['codigo_componente']}, quantidade " . number_format((float) $linha['quantidade'], 0, ',', '.') . ".";
                }
            } catch (Throwable $e) {
                $erro = '❌ Erro ao conectar/gravar no MRP: ' . $e->getMessage();
            }
        }
    }
}

// Lista embarques ainda não integrados, pra tela de confirmação
$pendentes = [];
$resultPendentes = mysqli_query($conn, "
    SELECT f.id, f.processo, f.efetiva, f.status, p.codigo_componente, p.descricao, p.quantidade
    FROM follow f
    LEFT JOIN processos p ON p.processo = f.processo
    WHERE f.integrado_mrp = 0
    ORDER BY f.efetiva IS NULL, f.efetiva ASC
");
while ($linha = mysqli_fetch_assoc($resultPendentes)) {
    $pendentes[] = $linha;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirmar entrega | Controle de Importação</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <main class="container py-4">
        <h1 class="h3 mb-1">Confirmar entrega</h1>
        <p class="text-muted mb-4">Ao confirmar, o componente é validado contra o MRP antes de alimentar o estoque — se não for encontrado, a integração é bloqueada e nada é gravado.</p>

        <?php if ($mensagem): ?>
            <div class="alert alert-success"><?php echo h($mensagem); ?></div>
        <?php endif; ?>
        <?php if ($erro): ?>
            <div class="alert alert-danger"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <h2 class="h5">Embarques pendentes de integração</h2>
                <?php if (empty($pendentes)): ?>
                    <p class="text-muted mb-0">Nenhum embarque pendente — tudo integrado.</p>
                <?php else: ?>
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Processo</th>
                                <th>Componente</th>
                                <th>Descrição</th>
                                <th class="text-end">Quantidade</th>
                                <th>Data efetiva</th>
                                <th>Confirmar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendentes as $p): ?>
                                <tr>
                                    <td><strong><?php echo h($p['processo']); ?></strong></td>
                                    <td><?php echo h($p['codigo_componente'] ?? '—'); ?></td>
                                    <td><?php echo h($p['descricao'] ?? '—'); ?></td>
                                    <td class="text-end"><?php echo $p['quantidade'] !== null ? number_format((float) $p['quantidade'], 0, ',', '.') : '—'; ?></td>
                                    <td>
                                        <form method="POST" class="d-flex gap-2 align-items-center">
                                            <input type="hidden" name="acao" value="confirmar_entrega">
                                            <input type="hidden" name="follow_id" value="<?php echo (int) $p['id']; ?>">
                                            <input type="date" name="data_efetiva" class="form-control form-control-sm" value="<?php echo h($p['efetiva'] ?? date('Y-m-d')); ?>" required>
                                            <button type="submit" class="btn btn-success btn-sm">Confirmar entrega</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>
