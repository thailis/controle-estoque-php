<?php
// confirmar_entrega.php
//
// Ponto único de integração entre o site de Importação e o MRP.
// Roda quando o usuário marca um "follow" como entregue (preenche/confirma a
// data "efetiva"). Antes de tocar no MRP, valida se o codigo_componente
// daquele processo realmente existe lá — se não existir, BLOQUEIA e avisa,
// em vez de gravar "no vácuo" silenciosamente.

require_once 'conexao.php'; // conexão com o PRÓPRIO banco (controle_importacao)

function h(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

// Formata sempre como dd/mm/aaaa — não depende do <input type="date"> do
// navegador (que mostra mm/dd/aaaa em alguns idiomas/locales), porque aqui
// o campo é só leitura e a formatação é toda nossa.
function dataBrConfirmar(?string $data): string
{
    if ($data === null || $data === '') {
        return '—';
    }
    $obj = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
    return $obj ? $obj->format('d/m/Y') : $data;
}

// Segunda conexão, só pra falar com o MRP — credenciais PRÓPRIAS e mínimas
// (usuário dedicado, só SELECT em parametros_compra/bomnova e leitura/escrita
// em programacao; nunca as mesmas credenciais do site interno do MRP).
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
// cadastrados sem ainda estar na BOM, ou vice-versa).
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

// Lista os componentes de um processo que entram no MRP. "processos" tem
// UMA LINHA POR COMPONENTE dentro do mesmo processo, enquanto o Follow tem
// uma linha só por processo — por isso a integração precisa percorrer TODOS
// os componentes do processo (antes pegava só o primeiro, e os demais nunca
// chegavam na Programação do MRP, mesmo com o embarque aparecendo como
// "Confirmado"). Itens "não controla estoque" ficam de fora. Se o mesmo
// componente aparecer em mais de uma linha do processo, as quantidades somam.
function buscarComponentesProcesso(mysqli $conn, string $processo): array
{
    $stmt = mysqli_prepare($conn, "
        SELECT TRIM(codigo_componente) AS codigo_componente, SUM(quantidade) AS quantidade
        FROM processos
        WHERE processo = ?
          AND LOWER(TRIM(COALESCE(controla_estoque, 'sim'))) <> 'nao'
        GROUP BY TRIM(codigo_componente)
        ORDER BY TRIM(codigo_componente)
    ");
    mysqli_stmt_bind_param($stmt, 's', $processo);
    mysqli_stmt_execute($stmt);
    $resultado = mysqli_stmt_get_result($stmt);
    $componentes = [];
    while ($linhaComp = mysqli_fetch_assoc($resultado)) {
        $componentes[] = [
            'codigo_componente' => (string) $linhaComp['codigo_componente'],
            'quantidade' => (float) ($linhaComp['quantidade'] ?? 0),
        ];
    }
    mysqli_stmt_close($stmt);
    return $componentes;
}

// Grava UM componente na Programação do MRP, casando por componente + processo:
// - se já existe a linha (manual, CSV ou confirmação anterior), SUBSTITUI
//   "importado" (quantidade do site de Importação) e "data_recebida" (data
//   efetiva do Follow) — nunca soma. Assim, se a confirmação for refeita,
//   o MRP fica sempre igual ao site de Importação;
// - se não existe, cria a linha (Quantidade = 0, já que não houve
//   planejamento manual; Data = efetiva; Importado e Data Recebida preenchidos).
// Nunca marca "Atendido" — isso continua sendo decisão manual no MRP
// (é o clique em "Atendido" que soma no estoque físico).
// Devolve 'criada' ou 'atualizada'.
function gravarComponenteNaProgramacao(mysqli $connMrp, string $codigoComponente, string $processo, float $quantidade, string $dataEfetiva): string
{
    $stmtBusca = mysqli_prepare($connMrp, "
        SELECT id FROM programacao
        WHERE TRIM(codigo_componente) = ? AND TRIM(COALESCE(processo, '')) = ?
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmtBusca, 'ss', $codigoComponente, $processo);
    mysqli_stmt_execute($stmtBusca);
    $existente = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtBusca));
    mysqli_stmt_close($stmtBusca);

    if ($existente) {
        $stmtAtualiza = mysqli_prepare($connMrp, "UPDATE programacao SET importado = ?, data_recebida = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmtAtualiza, 'dsi', $quantidade, $dataEfetiva, $existente['id']);
        mysqli_stmt_execute($stmtAtualiza);
        mysqli_stmt_close($stmtAtualiza);
        return 'atualizada';
    }

    $stmtInsere = mysqli_prepare($connMrp, "
        INSERT INTO programacao (codigo_componente, processo, data, quantidade, importado, data_recebida)
        VALUES (?, ?, ?, 0, ?, ?)
    ");
    mysqli_stmt_bind_param($stmtInsere, 'sssds', $codigoComponente, $processo, $dataEfetiva, $quantidade, $dataEfetiva);
    mysqli_stmt_execute($stmtInsere);
    mysqli_stmt_close($stmtInsere);
    return 'criada';
}

$mensagem = null;
$erro = null;

// ---------- Confirmar entrega (1 embarque = TODOS os componentes do processo) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'confirmar_entrega') {
    $followId = (int) ($_POST['follow_id'] ?? 0);
    $dataEfetiva = trim($_POST['data_efetiva'] ?? '');

    if ($followId <= 0 || $dataEfetiva === '') {
        $erro = 'Dados incompletos — selecione o embarque e informe a data de entrega.';
    } else {
        $stmt = mysqli_prepare($conn, "
            SELECT f.id, f.processo, f.integrado_mrp,
                   (SELECT COUNT(*) FROM processos WHERE processo = f.processo) AS total_linhas_processo,
                   (SELECT COUNT(*) FROM processos WHERE processo = f.processo AND LOWER(TRIM(status)) = 'cancelado') AS linhas_canceladas
            FROM follow f
            WHERE f.id = ?
        ");
        mysqli_stmt_bind_param($stmt, 'i', $followId);
        mysqli_stmt_execute($stmt);
        $linha = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$linha || (int) $linha['total_linhas_processo'] === 0) {
            $erro = 'Não encontrei esse embarque (ou o processo vinculado a ele) no banco.';
        } elseif ((int) $linha['integrado_mrp'] === 1) {
            $erro = 'Esse embarque já foi confirmado anteriormente — não é possível confirmar de novo (evita duplicar). Use "Sincronizar com o MRP" se precisar reenviar.';
        } elseif ((int) $linha['linhas_canceladas'] > 0) {
            // Trava no servidor — mesmo que alguém envie o follow_id direto.
            $erro = "❌ O processo \"{$linha['processo']}\" está CANCELADO — não é possível confirmar entrega.";
        } else {
            $processoTrim = trim((string) $linha['processo']);
            $componentes = buscarComponentesProcesso($conn, $processoTrim);
            $prosseguirComFechamento = true;
            $complementoMensagem = '';

            if (empty($componentes)) {
                // Todos os itens do processo são "não controla estoque" (tooling,
                // amostra): fecha só no site de Importação, sem tocar no MRP.
                $complementoMensagem = ' — itens não controlam estoque, apenas o status foi atualizado no site de Importação (sem lançamento no MRP).';
            } else {
                try {
                    $connMrp = conectarMrp();

                    // Valida TODOS os componentes antes de gravar qualquer um —
                    // se um falhar, nada é gravado (nem fecha o Follow/Processo),
                    // pra não deixar o processo pela metade no MRP.
                    $invalidos = [];
                    foreach ($componentes as $comp) {
                        $validacao = validarComponenteMrp($connMrp, $comp['codigo_componente']);
                        if (!$validacao['valido']) {
                            $invalidos[] = $validacao['motivo'];
                        }
                    }

                    if (!empty($invalidos)) {
                        $erro = '❌ Integração bloqueada — nada foi gravado. ' . implode(' | ', $invalidos);
                        $prosseguirComFechamento = false;
                    } else {
                        mysqli_begin_transaction($connMrp);
                        $criadas = 0;
                        $atualizadas = 0;
                        foreach ($componentes as $comp) {
                            $resultadoGravacao = gravarComponenteNaProgramacao($connMrp, $comp['codigo_componente'], $processoTrim, $comp['quantidade'], $dataEfetiva);
                            $resultadoGravacao === 'criada' ? $criadas++ : $atualizadas++;
                        }
                        mysqli_commit($connMrp);
                        $complementoMensagem = ' — ' . count($componentes) . " componente(s) lançado(s) na Programação do MRP ($atualizadas atualizada(s), $criadas criada(s)), com Importado e Data Recebida.";
                    }
                    mysqli_close($connMrp);
                } catch (Throwable $e) {
                    if (isset($connMrp) && $connMrp instanceof mysqli) {
                        @mysqli_rollback($connMrp);
                    }
                    $erro = '❌ Erro ao conectar/gravar no MRP (nada foi gravado): ' . $e->getMessage();
                    $prosseguirComFechamento = false;
                }
            }

            if ($prosseguirComFechamento) {
                // Follow vira "fechado" e Processo vira "finalizado" só aqui —
                // nunca digitados. integrado_mrp = trava contra duplicar.
                $stmtUpdate = mysqli_prepare($conn, "
                    UPDATE follow
                    SET efetiva = ?, integrado_mrp = 1, integrado_em = NOW(), status = 'fechado'
                    WHERE id = ?
                ");
                mysqli_stmt_bind_param($stmtUpdate, 'si', $dataEfetiva, $followId);
                mysqli_stmt_execute($stmtUpdate);
                mysqli_stmt_close($stmtUpdate);

                $stmtProcessoStatus = mysqli_prepare($conn, "UPDATE processos SET status = 'finalizado' WHERE processo = ?");
                mysqli_stmt_bind_param($stmtProcessoStatus, 's', $linha['processo']);
                mysqli_stmt_execute($stmtProcessoStatus);
                mysqli_stmt_close($stmtProcessoStatus);

                $mensagem = "✅ Entrega confirmada — processo {$processoTrim}" . $complementoMensagem;
            }
        }
    }
}

// ---------- Sincronizar com o MRP ----------
// Reenvia TODOS os embarques já confirmados (Follow fechado, processo não
// cancelado) pra Programação do MRP, usando exatamente a mesma gravação da
// confirmação (substitui Importado e Data Recebida; cria a linha se faltar).
// Serve pra corrigir o que ficou pra trás (ex.: processos confirmados antes
// da correção, quando só o 1º componente ia pro MRP). Pode rodar quantas
// vezes quiser — como sempre substitui, nunca duplica nem soma.
// Se o mesmo processo tiver mais de um embarque confirmado, vale a data
// efetiva mais recente. Componente que não existe no MRP é pulado e listado.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'sincronizar_mrp') {
    $resultadoFollows = mysqli_query($conn, "
        SELECT f.processo, f.efetiva
        FROM follow f
        WHERE f.integrado_mrp = 1
          AND f.efetiva IS NOT NULL
          AND NOT EXISTS (
              SELECT 1 FROM processos pc
              WHERE pc.processo = f.processo AND LOWER(TRIM(pc.status)) = 'cancelado'
          )
        ORDER BY f.efetiva ASC, f.integrado_em ASC
    ");
    $efetivaPorProcesso = [];
    while ($linhaFollow = mysqli_fetch_assoc($resultadoFollows)) {
        // Ordenado por efetiva ASC: o último que sobrescreve é o mais recente
        $efetivaPorProcesso[trim((string) $linhaFollow['processo'])] = $linhaFollow['efetiva'];
    }

    if (empty($efetivaPorProcesso)) {
        $mensagem = 'Nenhum embarque confirmado pra sincronizar.';
    } else {
        try {
            $connMrp = conectarMrp();
            $criadas = 0;
            $atualizadas = 0;
            $pulados = [];
            foreach ($efetivaPorProcesso as $processoSync => $efetivaSync) {
                foreach (buscarComponentesProcesso($conn, $processoSync) as $comp) {
                    $validacao = validarComponenteMrp($connMrp, $comp['codigo_componente']);
                    if (!$validacao['valido']) {
                        $pulados[] = "{$processoSync} / " . ($comp['codigo_componente'] !== '' ? $comp['codigo_componente'] : '(sem código)');
                        continue;
                    }
                    $resultadoGravacao = gravarComponenteNaProgramacao($connMrp, $comp['codigo_componente'], $processoSync, $comp['quantidade'], $efetivaSync);
                    $resultadoGravacao === 'criada' ? $criadas++ : $atualizadas++;
                }
            }
            mysqli_close($connMrp);

            $mensagem = '🔄 Sincronização concluída — ' . count($efetivaPorProcesso) . " processo(s) conferido(s): $atualizadas linha(s) atualizada(s), $criadas linha(s) criada(s) na Programação do MRP.";
            if (!empty($pulados)) {
                $erro = '⚠️ ' . count($pulados) . ' componente(s) não encontrado(s) no MRP e pulado(s): ' . implode(', ', $pulados);
            }
        } catch (Throwable $e) {
            $erro = '❌ Erro durante a sincronização com o MRP (o que já tinha sido gravado até aqui permanece): ' . $e->getMessage();
        }
    }
}

// Filtros da tela (busca livre por componente/fornecedor/processo + status).
// A lista mostra TUDO por padrão (pendente, confirmado e cancelado) — o item
// não some mais da tela depois de confirmado, só muda de badge/situação.
// Itens "não controla estoque" (tooling, amostra) também aparecem aqui e
// podem ser confirmados normalmente — só que a confirmação deles finaliza
// apenas Follow/Processo no site de Importação, sem tocar no MRP.
$buscaComponente = trim($_GET['componente'] ?? '');
$buscaFornecedor = trim($_GET['fornecedor'] ?? '');
$buscaProcesso   = trim($_GET['processo'] ?? '');
$filtroStatus    = trim($_GET['status'] ?? ''); // '', 'pendente', 'confirmado', 'cancelado'

$condicoesLista = [];
$paramsLista = [];
$tiposLista = '';

if ($buscaComponente !== '') {
    $condicoesLista[] = "p.codigo_componente LIKE ?";
    $paramsLista[] = "%$buscaComponente%";
    $tiposLista .= 's';
}
if ($buscaFornecedor !== '') {
    $condicoesLista[] = "p.fornecedor LIKE ?";
    $paramsLista[] = "%$buscaFornecedor%";
    $tiposLista .= 's';
}
if ($buscaProcesso !== '') {
    $condicoesLista[] = "f.processo LIKE ?";
    $paramsLista[] = "%$buscaProcesso%";
    $tiposLista .= 's';
}
if ($filtroStatus === 'pendente') {
    $condicoesLista[] = "f.integrado_mrp = 0 AND (p.status IS NULL OR LOWER(TRIM(p.status)) <> 'cancelado')";
} elseif ($filtroStatus === 'confirmado') {
    $condicoesLista[] = "f.integrado_mrp = 1";
} elseif ($filtroStatus === 'cancelado') {
    $condicoesLista[] = "LOWER(TRIM(p.status)) = 'cancelado'";
}

$whereLista = $condicoesLista ? ('WHERE ' . implode(' AND ', $condicoesLista)) : '';

$sqlLista = "
    SELECT f.id, f.processo, f.efetiva, f.prevista, f.status, f.integrado_mrp,
           p.codigo_componente, p.descricao, p.quantidade, p.fornecedor, p.status AS status_processo, p.controla_estoque
    FROM follow f
    LEFT JOIN processos p ON p.processo = f.processo
    $whereLista
    ORDER BY f.integrado_mrp ASC, f.efetiva IS NULL, f.efetiva ASC
";

$registros = [];
if (!empty($paramsLista)) {
    $stmtLista = mysqli_prepare($conn, $sqlLista);
    mysqli_stmt_bind_param($stmtLista, $tiposLista, ...$paramsLista);
    mysqli_stmt_execute($stmtLista);
    $resultLista = mysqli_stmt_get_result($stmtLista);
} else {
    $resultLista = mysqli_query($conn, $sqlLista);
}
while ($linha = mysqli_fetch_assoc($resultLista)) {
    $registros[] = $linha;
}

// Contagem de pendentes de verdade (ignora os filtros da tela) — só pra dar
// o número no cabeçalho, sem depender do que está sendo exibido no momento.
$totalPendentesReal = 0;
$resultContagem = mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM follow f
    LEFT JOIN processos p ON p.processo = f.processo
    WHERE f.integrado_mrp = 0
      AND (p.status IS NULL OR LOWER(TRIM(p.status)) <> 'cancelado')
");
if ($resultContagem) {
    $totalPendentesReal = (int) (mysqli_fetch_assoc($resultContagem)['total'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirmar Entrega | Controle de Importação</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/dashboard.css" rel="stylesheet">
    <style>
        /* Larguras fixas só nesta tabela — a .mrp-table compartilhada (dashboard.css)
           não trava largura de coluna nenhuma, então o navegador distribui o espaço
           sobrante (ela força min-width: 1460px) de forma inconsistente conforme o
           texto de cada linha muda, criando um "buraco" em branco entre Descrição e
           Quantidade. Travando aqui (sem mexer no dashboard.css, que é usado também
           por Follow/Processos/Pagamento) cada coluna fica com largura previsível e
           só a Descrição absorve o espaço que sobra. */
        .table-confirmar { table-layout: fixed; }
        /* Sem isso, o navegador usa "vertical-align: baseline" por padrão em
           <td>, então células com texto em uma linha só (Fornecedor) e
           células com texto quebrando em duas linhas (Descrição) alinham
           pelo TEXTO, não pelo topo/meio da célula — isso faz a linha
           divisória entre registros parecer "desencaixada" de uma coluna
           pra outra, mesmo com a altura da linha sendo a mesma. Forçando
           "middle" em todas as células, elas ficam centralizadas juntas. */
        .table-confirmar td, .table-confirmar th { vertical-align: middle; }
        .table-confirmar th:nth-child(1), .table-confirmar td:nth-child(1) { width: 110px; }
        .table-confirmar th:nth-child(2), .table-confirmar td:nth-child(2) { width: 150px; }
        .table-confirmar th:nth-child(3), .table-confirmar td:nth-child(3) { width: 130px; }
        .table-confirmar th:nth-child(4), .table-confirmar td:nth-child(4) { width: 150px; }
        .table-confirmar th:nth-child(5), .table-confirmar td:nth-child(5) { width: auto; }
        .table-confirmar th:nth-child(6), .table-confirmar td:nth-child(6) { width: 110px; }
        .table-confirmar th:nth-child(7), .table-confirmar td:nth-child(7) { width: 150px; }
        .table-confirmar th:nth-child(8), .table-confirmar td:nth-child(8) { width: 170px; }
        /* A classe .description-cell do dashboard.css (compartilhada com Follow/
           Processos/Pagamento) já vem com "display: block" — funciona lá, mas
           aqui, aplicada direto no <td>, tira a célula do modelo de tabela e
           desalinha a linha inteira (foi a causa real do desencaixe). Por isso
           é preciso FORÇAR "display: table-cell" de volta aqui — só remover o
           "block" da regra não bastava, porque cada propriedade CSS é resolvida
           separadamente pela cascata: sem essa linha, a regra do dashboard.css
           continua sendo a única que declara "display", e prevalece. */
        .table-confirmar td.description-cell {
            display: table-cell;
            max-width: none;
            white-space: normal;
            overflow-wrap: break-word;
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="container-fluid dashboard-container d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="eyebrow">Controle de Importação • Integração com o MRP</span>
                <h1>Confirmar Entrega</h1>
                <p class="mb-0"><?php echo $totalPendentesReal; ?> embarque(s) pendente(s) de integração</p>
            </div>
            <nav class="d-flex flex-wrap gap-2" aria-label="Ações do sistema">
                <a class="btn btn-outline-light btn-sm" href="follow.php">Follow</a>
                <a class="btn btn-outline-light btn-sm" href="processos.php">Processos</a>
                <a class="btn btn-outline-light btn-sm" href="pagamento.php">Pagamento</a>
                <a class="btn btn-light btn-sm" href="confirmar_entrega.php">Confirmar entrega</a>
            </nav>
        </div>
    </header>

    <main class="container-fluid dashboard-container py-4">

        <?php if ($mensagem): ?>
            <div class="alert alert-success"><?php echo h($mensagem); ?></div>
        <?php endif; ?>
        <?php if ($erro): ?>
            <div class="alert alert-danger"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <section class="filter-panel mb-4">
            <div class="section-heading">
                <div>
                    <span class="eyebrow text-primary">Como funciona</span>
                    <h2>Validação antes de alimentar o MRP</h2>
                </div>
            </div>
            <p class="mb-0" style="color: var(--muted);">Ao confirmar, o componente é validado contra o MRP (Parâmetros de Compra e BOM) antes de seguir — se não for encontrado, a integração é <strong>bloqueada</strong> e nada é gravado. Um embarque confirma <strong>todos os componentes do processo</strong> de uma vez. A quantidade confirmada é gravada na tela de Programação do MRP (coluna "Importado", com a data efetiva do Follow em "Data Recebida"), casando por componente + processo — não duplica em cima do que já estava planejado. O status do Follow e do Processo correspondente viram "Fechado"/"Finalizado" automaticamente neste momento. Depois de confirmado, o embarque continua aparecendo nesta lista — só muda para a situação "Confirmado". Itens marcados como <strong>"não controla estoque"</strong> (tooling, amostra) também podem ser confirmados aqui, mas a confirmação só fecha o Follow/Processo no site de Importação — não valida nem grava nada no MRP.</p>
            <form method="POST" class="mt-3 mb-0" onsubmit="return confirm('Reenviar TODOS os embarques já confirmados para a Programação do MRP? Importado e Data Recebida serão substituídos pelos valores do site de Importação, e linhas que faltam serão criadas.');">
                <input type="hidden" name="acao" value="sincronizar_mrp">
                <button type="submit" class="btn btn-outline-primary btn-sm">🔄 Sincronizar com o MRP</button>
                <small class="text-muted ms-2">Reenvia todos os confirmados (todos os componentes de cada processo). Pode rodar mais de uma vez — substitui, não duplica.</small>
            </form>
        </section>

        <section class="filter-panel mb-4">
            <div class="section-heading">
                <div>
                    <span class="eyebrow text-primary">Filtros</span>
                    <h2>Buscar embarques</h2>
                </div>
            </div>
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-12 col-md-3">
                    <label class="form-label small mb-1">Componente</label>
                    <input type="text" name="componente" class="form-control form-control-sm" placeholder="Ex.: 12000630" value="<?php echo h($buscaComponente); ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label small mb-1">Fornecedor</label>
                    <input type="text" name="fornecedor" class="form-control form-control-sm" placeholder="Ex.: YAPP CN" value="<?php echo h($buscaFornecedor); ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label small mb-1">Processo</label>
                    <input type="text" name="processo" class="form-control form-control-sm" placeholder="Ex.: YAMCS276-26YP" value="<?php echo h($buscaProcesso); ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label small mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="" <?php echo $filtroStatus === '' ? 'selected' : ''; ?>>Todos</option>
                        <option value="pendente" <?php echo $filtroStatus === 'pendente' ? 'selected' : ''; ?>>Pendente</option>
                        <option value="confirmado" <?php echo $filtroStatus === 'confirmado' ? 'selected' : ''; ?>>Confirmado</option>
                        <option value="cancelado" <?php echo $filtroStatus === 'cancelado' ? 'selected' : ''; ?>>Cancelado</option>
                    </select>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">Buscar</button>
                    <a href="confirmar_entrega.php" class="btn btn-outline-secondary btn-sm">Limpar filtros</a>
                </div>
            </form>
        </section>

        <section class="table-card">
            <div class="table-toolbar">
                <div>
                    <span class="eyebrow text-primary">Resultado</span>
                    <h2>Embarques</h2>
                    <p><?php echo count($registros); ?> encontrado(s)</p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table mrp-table mb-0 table-confirmar">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Processo</th>
                            <th>Componente</th>
                            <th>Fornecedor</th>
                            <th>Descrição</th>
                            <th>Quantidade</th>
                            <th>Efetiva</th>
                            <th>Confirmar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($registros)): ?>
                            <tr><td colspan="8" class="empty-state">Nenhum embarque encontrado com esses filtros.</td></tr>
                        <?php else: ?>
                            <?php foreach ($registros as $p): ?>
                                <?php
                                    $formId = 'form-confirmar-' . (int) $p['id'];
                                    // A data que vai pra Programação é sempre a EFETIVA, puxada do
                                    // Follow — nunca digitada aqui, e nunca editável (por isso o
                                    // campo é só leitura, não um <input type="date"> normal — isso
                                    // também evita o formato mm/dd/aaaa que o navegador às vezes
                                    // mostra num campo de data editável). Sem efetiva cadastrada,
                                    // não tem o que confirmar: botão fica travado em "Aguardando".
                                    $temEfetiva = !empty($p['efetiva']);
                                    $statusProcesso = strtolower(trim((string) ($p['status_processo'] ?? '')));
                                    $integrado = (int) ($p['integrado_mrp'] ?? 0) === 1;
                                    $cancelado = $statusProcesso === 'cancelado';
                                    $controlaEstoqueLinha = strtolower(trim((string) ($p['controla_estoque'] ?? 'sim'))) !== 'nao';

                                    if ($cancelado) {
                                        $badgeClasseSituacao = 'bg-danger';
                                        $badgeTextoSituacao = 'Cancelado';
                                    } elseif ($integrado && $controlaEstoqueLinha) {
                                        $badgeClasseSituacao = 'bg-success';
                                        $badgeTextoSituacao = 'Confirmado';
                                    } elseif ($integrado && !$controlaEstoqueLinha) {
                                        $badgeClasseSituacao = 'bg-info text-dark';
                                        $badgeTextoSituacao = 'Finalizado';
                                    } else {
                                        $badgeClasseSituacao = 'bg-warning text-dark';
                                        $badgeTextoSituacao = ucfirst($p['status'] ?: 'Aberto');
                                    }
                                ?>
                                <tr>
                                    <td><span class="badge <?php echo $badgeClasseSituacao; ?>"><?php echo h($badgeTextoSituacao); ?></span></td>
                                    <td><span class="component-code"><?php echo h($p['processo']); ?></span></td>
                                    <td><?php echo h($p['codigo_componente'] ?? '—'); ?></td>
                                    <td><?php echo h($p['fornecedor'] ?? '—'); ?></td>
                                    <td class="description-cell" title="<?php echo h($p['descricao'] ?? ''); ?>"><?php echo h($p['descricao'] ?? '—'); ?></td>
                                    <td><?php echo $p['quantidade'] !== null ? number_format((float) $p['quantidade'], 0, ',', '.') : '—'; ?></td>
                                    <td>
                                        <?php if ($temEfetiva && !$integrado && !$cancelado): ?>
                                            <form id="<?php echo h($formId); ?>" method="POST" class="m-0">
                                                <input type="hidden" name="acao" value="confirmar_entrega">
                                                <input type="hidden" name="follow_id" value="<?php echo (int) $p['id']; ?>">
                                                <input type="hidden" name="data_efetiva" value="<?php echo h($p['efetiva']); ?>">
                                                <input type="text" class="form-control form-control-sm" value="<?php echo h(dataBrConfirmar($p['efetiva'])); ?>" disabled>
                                            </form>
                                        <?php elseif ($temEfetiva): ?>
                                            <?php echo h(dataBrConfirmar($p['efetiva'])); ?>
                                        <?php else: ?>
                                            <span class="text-muted" title="Preencha a data efetiva no Follow pra liberar a confirmação">— sem efetiva —</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($integrado && $controlaEstoqueLinha): ?>
                                            <span class="text-success" title="Já confirmado — Programação do MRP atualizada">✅ Confirmado</span>
                                        <?php elseif ($integrado && !$controlaEstoqueLinha): ?>
                                            <span class="text-info" title="Não controla estoque — status finalizado só no site de Importação, sem lançamento no MRP">☑️ Finalizado (sem estoque)</span>
                                        <?php elseif ($cancelado): ?>
                                            <span class="text-muted" title="Processo cancelado — não é possível confirmar">— cancelado —</span>
                                        <?php elseif ($temEfetiva): ?>
                                            <button type="submit" form="<?php echo h($formId); ?>" class="btn btn-success btn-sm">Confirmar</button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-secondary btn-sm" disabled title="Preencha a data efetiva no Follow primeiro">Aguardando</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <footer class="dashboard-footer">Controle de Importação — site independente do MRP, integração via processo controlado.</footer>
    </main>
</body>
</html>
