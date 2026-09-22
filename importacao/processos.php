<?php
// importacao/processos.php
require_once 'conexao.php';

function h(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function numeroBr($valor, int $decimais = 2): string
{
    return $valor === null || $valor === '' ? '—' : number_format((float) $valor, $decimais, ',', '.');
}

function dataBr(?string $data): string
{
    if ($data === null || $data === '') {
        return '—';
    }
    $obj = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
    return $obj ? $obj->format('d/m/Y') : $data;
}

// Aceita dd/mm/aaaa (formato mais comum vindo de Excel-BR) ou aaaa-mm-dd (ISO).
// Devolve sempre no formato aaaa-mm-dd (o que o banco espera), ou null se não reconhecer.
function parseDataProcessos(string $valor): ?string
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }
    $formatos = ['d/m/Y', 'Y-m-d'];
    foreach ($formatos as $formato) {
        $obj = DateTimeImmutable::createFromFormat('!' . $formato, $valor);
        if ($obj !== false) {
            return $obj->format('Y-m-d');
        }
    }
    return null;
}

// Mesma lógica de parse numérico BR usada no resto do sistema (aceita "1.234,56" e "1234.56").
function parseNumeroBrProcessos(string $valor): ?float
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }
    if (str_contains($valor, ',')) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    } elseif (substr_count($valor, '.') >= 1) {
        $partes = explode('.', $valor);
        $pareceMilhar = true;
        for ($i = 1; $i < count($partes); $i++) {
            if (strlen($partes[$i]) !== 3 || !ctype_digit($partes[$i])) {
                $pareceMilhar = false;
                break;
            }
        }
        if ($pareceMilhar) {
            $valor = str_replace('.', '', $valor);
        }
    }
    return is_numeric($valor) ? (float) $valor : null;
}

function normalizarTextoProcessos(string $texto): string
{
    $texto = mb_strtolower(trim($texto), 'UTF-8');
    $mapa = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', ' ' => '_', '-' => '_',
    ];
    return strtr($texto, $mapa);
}

// Gera o código do processo automaticamente (só usado no cadastro manual —
// a importação de CSV continua trazendo o processo pronto do arquivo).
// Formato: Y + ano(2) + modal(1) + planta(1) + categoria(1) + sequencial(4+)
// Ex.: Y26A1P0001 = Y, 2026, Aéreo, planta terminada em 1, Project, sequencial 0001.
// O sequencial é um contador ÚNICO GERAL (não reinicia por ano/modal/planta/categoria)
// — pega o maior sequencial já usado em qualquer processo gerado nesse formato
// (inclusive os que vieram prontos da planilha, contanto que sigam o mesmo
// formato) e soma 1, então nunca colide mesmo se a combinação se repetir.
// Sempre com no mínimo 4 dígitos (0001, 0002... 0046... 0099, 0100... 0999,
// 1000, 1001...) — cresce sozinho sem zero à esquerda quando passar de 4
// dígitos, e a busca abaixo aceita qualquer quantidade de dígitos no final
// (não só 4), então continua contando certo mesmo depois de passar de 9999.
function gerarCodigoProcesso(mysqli $conn, string $modal, string $planta, string $categoria): array
{
    $modalMapa = [
        'aereo' => 'A', 'aéreo' => 'A', 'air' => 'A',
        'sea' => 'S', 'maritimo' => 'S', 'marítimo' => 'S',
        'road' => 'R', 'rodoviario' => 'R', 'rodoviário' => 'R',
        'courrier' => 'C', 'courier' => 'C',
    ];
    $modalChave = mb_strtolower(trim($modal), 'UTF-8');
    if (!isset($modalMapa[$modalChave])) {
        return ['codigo' => null, 'erro' => "Modal \"$modal\" não reconhecido. Use: aereo, sea/maritimo, road ou courrier."];
    }
    $modalLetra = $modalMapa[$modalChave];

    $plantaTrim = trim($planta);
    if ($plantaTrim === '' || !ctype_digit(substr($plantaTrim, -1))) {
        return ['codigo' => null, 'erro' => "Planta \"$planta\" inválida — não consegui extrair o dígito final."];
    }
    $plantaDigito = substr($plantaTrim, -1);

    $categoriaMapa = [
        'tooling' => 'T', 'project' => 'P', 'other' => 'O',
    ];
    $categoriaChave = mb_strtolower(trim($categoria), 'UTF-8');
    if (!isset($categoriaMapa[$categoriaChave])) {
        return ['codigo' => null, 'erro' => "Categoria \"$categoria\" não reconhecida. Use: tooling, project ou other."];
    }
    $categoriaLetra = $categoriaMapa[$categoriaChave];

    $ano = date('y'); // 2 dígitos

    // Maior sequencial já usado em qualquer processo no formato
    // Y+AA+letra+dígito+letra+dígitos, sem filtrar por ano/modal/planta/
    // categoria — é um contador único pra todos. O prefixo (Y+ano+modal+
    // planta+categoria) sempre tem 6 caracteres fixos, então SUBSTRING a
    // partir da posição 7 pega o sequencial inteiro — 3, 4, 5 dígitos, tanto
    // faz — em vez de travar num tamanho fixo (era isso que fazia o contador
    // "esquecer" os códigos assim que passassem de 999/9999).
    $resultado = mysqli_query($conn, "
        SELECT MAX(CAST(SUBSTRING(processo, 7) AS UNSIGNED)) AS max_seq
        FROM processos
        WHERE processo REGEXP '^Y[0-9]{2}[A-Z][0-9][A-Z][0-9]+$'
    ");
    $maxSeq = (int) (mysqli_fetch_assoc($resultado)['max_seq'] ?? 0);
    $proximoSeq = $maxSeq + 1;

    $codigo = 'Y' . $ano . $modalLetra . $plantaDigito . $categoriaLetra . str_pad((string) $proximoSeq, 4, '0', STR_PAD_LEFT);
    return ['codigo' => $codigo, 'erro' => null];
}

// ---------- Preview do código do processo (AJAX, antes de salvar) ----------
// Chama a mesma gerarCodigoProcesso() usada no cadastro de verdade, só que
// sem gravar nada — é só pra mostrar na tela o que o código VAI ficar, à
// medida que Modal/Planta/Categoria vão sendo preenchidos. O sequencial é
// sempre "o maior já usado + 1" na hora da consulta, então esse preview pode
// mudar se outra pessoa cadastrar um processo entre o preview e o Salvar de
// verdade — por isso o código final de fato só é gerado (e gravado) no
// cadastro_manual abaixo, nunca reaproveitando o que veio desse preview.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'ajax_preview_codigo_processo') {
    header('Content-Type: application/json; charset=UTF-8');

    $modalPreview = trim($_POST['modal'] ?? '');
    $plantaPreview = trim($_POST['planta'] ?? '');
    $categoriaPreview = trim($_POST['categoria'] ?? '');

    if ($modalPreview === '' || $plantaPreview === '' || $categoriaPreview === '') {
        echo json_encode(['ok' => false, 'erro' => 'Preencha Categoria, Planta e Modal pra ver o código.']);
        exit;
    }

    $geracaoPreview = gerarCodigoProcesso($conn, $modalPreview, $plantaPreview, $categoriaPreview);
    if ($geracaoPreview['codigo'] === null) {
        echo json_encode(['ok' => false, 'erro' => $geracaoPreview['erro']]);
        exit;
    }

    echo json_encode(['ok' => true, 'codigo' => $geracaoPreview['codigo']]);
    exit;
}

$mensagens = [];
$importados = 0;
$erros = 0;

// ---------- Toggle Aberto / Cancelado (AJAX — sem recarregar a página) ----------
// Mesma regra da versão com reload abaixo, só que devolve JSON pra JS trocar
// a badge no lugar, sem sair da posição de scroll.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'ajax_toggle_status_processo') {
    header('Content-Type: application/json; charset=UTF-8');

    $processoToggle = trim($_POST['processo'] ?? '');
    if ($processoToggle === '') {
        echo json_encode(['ok' => false, 'erro' => 'Processo inválido.']);
        exit;
    }

    $stmtAtual = mysqli_prepare($conn, "SELECT status FROM processos WHERE processo = ? LIMIT 1");
    mysqli_stmt_bind_param($stmtAtual, 's', $processoToggle);
    mysqli_stmt_execute($stmtAtual);
    $statusAtual = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtAtual))['status'] ?? 'aberto';
    mysqli_stmt_close($stmtAtual);

    if (strtolower(trim($statusAtual)) === 'finalizado') {
        echo json_encode(['ok' => false, 'erro' => 'Esse processo já foi finalizado (entrega confirmada) — não é possível cancelar nem reabrir.']);
        exit;
    }

    $novoStatus = strtolower(trim($statusAtual)) === 'cancelado' ? 'aberto' : 'cancelado';

    $stmtUpdateProc = mysqli_prepare($conn, "UPDATE processos SET status = ? WHERE processo = ?");
    mysqli_stmt_bind_param($stmtUpdateProc, 'ss', $novoStatus, $processoToggle);
    mysqli_stmt_execute($stmtUpdateProc);
    mysqli_stmt_close($stmtUpdateProc);

    $stmtUpdatePag = mysqli_prepare($conn, "UPDATE pagamento SET status = ? WHERE processo = ?");
    mysqli_stmt_bind_param($stmtUpdatePag, 'ss', $novoStatus, $processoToggle);
    mysqli_stmt_execute($stmtUpdatePag);
    mysqli_stmt_close($stmtUpdatePag);

    echo json_encode([
        'ok' => true,
        'texto' => $novoStatus === 'cancelado' ? 'Cancelado' : 'Aberto',
        'classe' => $novoStatus === 'cancelado' ? 'status-critico' : 'status-atencao',
    ]);
    exit;
}

// ---------- Toggle Controla Estoque (AJAX — sem recarregar a página) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'ajax_toggle_controla_estoque') {
    header('Content-Type: application/json; charset=UTF-8');

    $idToggle = (int) ($_POST['id'] ?? 0);
    if ($idToggle <= 0) {
        echo json_encode(['ok' => false, 'erro' => 'Registro inválido.']);
        exit;
    }

    $stmtAtualCE = mysqli_prepare($conn, "SELECT controla_estoque, status FROM processos WHERE id = ?");
    mysqli_stmt_bind_param($stmtAtualCE, 'i', $idToggle);
    mysqli_stmt_execute($stmtAtualCE);
    $linhaAtualCE = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtAtualCE));
    mysqli_stmt_close($stmtAtualCE);

    if (!$linhaAtualCE) {
        echo json_encode(['ok' => false, 'erro' => 'Registro não encontrado.']);
        exit;
    }
    if (strtolower(trim((string) $linhaAtualCE['status'])) === 'finalizado') {
        echo json_encode(['ok' => false, 'erro' => 'Esse item já foi finalizado (entrega confirmada) — não é possível mudar o controle de estoque agora.']);
        exit;
    }

    $novoControla = strtolower(trim((string) $linhaAtualCE['controla_estoque'])) === 'sim' ? 'nao' : 'sim';
    $stmtToggleCE = mysqli_prepare($conn, "UPDATE processos SET controla_estoque = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmtToggleCE, 'si', $novoControla, $idToggle);
    mysqli_stmt_execute($stmtToggleCE);
    mysqli_stmt_close($stmtToggleCE);

    echo json_encode([
        'ok' => true,
        'texto' => $novoControla === 'sim' ? 'Sim' : 'Não',
        'classe' => $novoControla === 'sim' ? 'status-ok' : 'status-sem_demanda',
    ]);
    exit;
}

// ---------- Toggle Aberto / Cancelado ----------
// Age por "processo" (não por id de uma linha só), porque um mesmo processo
// pode ter várias linhas (vários componentes) — cancelar o processo cancela
// todas elas juntas, e também "alimenta" (cascata) o status em Pagamento.
// O Follow não guarda cópia do status — ele mostra ao vivo, direto de
// Processos, então não precisa de cascata lá.
// Uma vez "finalizado" (via confirmar_entrega.php), o botão trava — não dá
// pra cancelar nem reabrir um processo que já foi entregue de verdade.
// (Mantido como fallback caso o JS não carregue — recarrega a página normalmente.)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'toggle_status_processo') {
    $processoToggle = trim($_POST['processo'] ?? '');
    if ($processoToggle === '') {
        $mensagens[] = '❌ Processo inválido.';
    } else {
        $stmtAtual = mysqli_prepare($conn, "SELECT status FROM processos WHERE processo = ? LIMIT 1");
        mysqli_stmt_bind_param($stmtAtual, 's', $processoToggle);
        mysqli_stmt_execute($stmtAtual);
        $statusAtual = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtAtual))['status'] ?? 'aberto';
        mysqli_stmt_close($stmtAtual);

        if (strtolower(trim($statusAtual)) === 'finalizado') {
            $mensagens[] = '❌ Esse processo já foi finalizado (entrega confirmada) — não é possível cancelar nem reabrir.';
        } else {
            $novoStatus = strtolower(trim($statusAtual)) === 'cancelado' ? 'aberto' : 'cancelado';

            $stmtUpdateProc = mysqli_prepare($conn, "UPDATE processos SET status = ? WHERE processo = ?");
            mysqli_stmt_bind_param($stmtUpdateProc, 'ss', $novoStatus, $processoToggle);
            mysqli_stmt_execute($stmtUpdateProc);
            mysqli_stmt_close($stmtUpdateProc);

            // Cascata pro Pagamento (guarda uma cópia do status, não é ao vivo)
            $stmtUpdatePag = mysqli_prepare($conn, "UPDATE pagamento SET status = ? WHERE processo = ?");
            mysqli_stmt_bind_param($stmtUpdatePag, 'ss', $novoStatus, $processoToggle);
            mysqli_stmt_execute($stmtUpdatePag);
            mysqli_stmt_close($stmtUpdatePag);

            $paginaVolta = (int) ($_POST['pagina_atual'] ?? 1);
            $buscaVolta = (string) ($_POST['busca_atual'] ?? '');
            $plantaVolta = (string) ($_POST['planta_atual'] ?? '');
            $componenteVolta = (string) ($_POST['componente_atual'] ?? '');
            $categoriaVolta = (string) ($_POST['categoria_atual'] ?? '');
            $fornecedorVolta = (string) ($_POST['fornecedor_atual'] ?? '');
            header('Location: processos.php?pagina=' . $paginaVolta . '&busca=' . urlencode($buscaVolta) . '&planta=' . urlencode($plantaVolta) . '&componente=' . urlencode($componenteVolta) . '&categoria=' . urlencode($categoriaVolta) . '&fornecedor=' . urlencode($fornecedorVolta) . '&status_alterado=1');
            exit;
        }
    }
}

// ---------- Toggle Controla Estoque (Sim / Não) ----------
// Diferente do toggle de Status acima, esse age por LINHA específica (id),
// não pelo processo inteiro — porque um mesmo processo pode ter itens que
// controlam estoque (entram na fila do Confirmar Entrega) e itens que não
// controlam (tooling, amostra), misturados.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'toggle_controla_estoque') {
    $idToggle = (int) ($_POST['id'] ?? 0);
    if ($idToggle <= 0) {
        $mensagens[] = '❌ Registro inválido.';
    } else {
        $stmtAtualCE = mysqli_prepare($conn, "SELECT controla_estoque, status FROM processos WHERE id = ?");
        mysqli_stmt_bind_param($stmtAtualCE, 'i', $idToggle);
        mysqli_stmt_execute($stmtAtualCE);
        $linhaAtualCE = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtAtualCE));
        mysqli_stmt_close($stmtAtualCE);

        if (!$linhaAtualCE) {
            $mensagens[] = '❌ Registro não encontrado.';
        } elseif (strtolower(trim((string) $linhaAtualCE['status'])) === 'finalizado') {
            $mensagens[] = '❌ Esse item já foi finalizado (entrega confirmada) — não é possível mudar o controle de estoque agora.';
        } else {
            $novoControla = strtolower(trim((string) $linhaAtualCE['controla_estoque'])) === 'sim' ? 'nao' : 'sim';
            $stmtToggleCE = mysqli_prepare($conn, "UPDATE processos SET controla_estoque = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmtToggleCE, 'si', $novoControla, $idToggle);
            mysqli_stmt_execute($stmtToggleCE);
            mysqli_stmt_close($stmtToggleCE);

            $paginaVolta = (int) ($_POST['pagina_atual'] ?? 1);
            $buscaVolta = (string) ($_POST['busca_atual'] ?? '');
            $plantaVolta = (string) ($_POST['planta_atual'] ?? '');
            $componenteVolta = (string) ($_POST['componente_atual'] ?? '');
            $categoriaVolta = (string) ($_POST['categoria_atual'] ?? '');
            $fornecedorVolta = (string) ($_POST['fornecedor_atual'] ?? '');
            header('Location: processos.php?pagina=' . $paginaVolta . '&busca=' . urlencode($buscaVolta) . '&planta=' . urlencode($plantaVolta) . '&componente=' . urlencode($componenteVolta) . '&categoria=' . urlencode($categoriaVolta) . '&fornecedor=' . urlencode($fornecedorVolta) . '&controla_alterado=1');
            exit;
        }
    }
}

// ---------- Excluir ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_processo') {
    $idExcluir = (int) ($_POST['id'] ?? 0);
    if ($idExcluir > 0) {
        $stmtExcluir = mysqli_prepare($conn, "DELETE FROM processos WHERE id = ?");
        mysqli_stmt_bind_param($stmtExcluir, 'i', $idExcluir);
        mysqli_stmt_execute($stmtExcluir);
        mysqli_stmt_close($stmtExcluir);
    }
    $paginaVolta = (int) ($_POST['pagina_atual'] ?? 1);
    $buscaVolta = (string) ($_POST['busca_atual'] ?? '');
    $plantaVolta = (string) ($_POST['planta_atual'] ?? '');
    $componenteVolta = (string) ($_POST['componente_atual'] ?? '');
    $categoriaVolta = (string) ($_POST['categoria_atual'] ?? '');
    $fornecedorVolta = (string) ($_POST['fornecedor_atual'] ?? '');
    header('Location: processos.php?pagina=' . $paginaVolta . '&busca=' . urlencode($buscaVolta) . '&planta=' . urlencode($plantaVolta) . '&componente=' . urlencode($componenteVolta) . '&categoria=' . urlencode($categoriaVolta) . '&fornecedor=' . urlencode($fornecedorVolta) . '&excluido=1');
    exit;
}

// ---------- Edição inline (duplo clique) ----------
// Identifica a linha por "id". Quantidade e Preço, ao serem editados,
// recalculam e gravam o Total também (nunca é digitado diretamente).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'ajax_editar_campo') {
    header('Content-Type: application/json; charset=UTF-8');

    $id = (int) ($_POST['id'] ?? 0);
    $campo = (string) ($_POST['campo'] ?? '');
    $valor = trim((string) ($_POST['valor'] ?? ''));

    $camposTexto = ['categoria', 'planta', 'po', 'modal', 'projeto', 'material', 'codigo_componente', 'descricao', 'hscode', 'ncm', 'fornecedor', 'moeda', 'tipo', 'ffw', 'obs'];
    $camposData = ['solicitacao'];
    $camposNumericos = ['quantidade', 'preco'];
    $todosCampos = array_merge($camposTexto, $camposData, $camposNumericos);

    if ($id <= 0 || !in_array($campo, $todosCampos, true)) {
        echo json_encode(['ok' => false, 'erro' => 'Requisição inválida.']);
        exit;
    }

    $stmtCheck = mysqli_prepare($conn, "SELECT status FROM processos WHERE id = ?");
    mysqli_stmt_bind_param($stmtCheck, 'i', $id);
    mysqli_stmt_execute($stmtCheck);
    $linhaAtual = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtCheck));
    mysqli_stmt_close($stmtCheck);

    if (!$linhaAtual) {
        echo json_encode(['ok' => false, 'erro' => 'Registro não encontrado.']);
        exit;
    }
    if (strtolower(trim((string) $linhaAtual['status'])) === 'finalizado') {
        echo json_encode(['ok' => false, 'erro' => 'Esse processo já foi finalizado — não é possível editar mais.']);
        exit;
    }

    if (in_array($campo, $camposData, true)) {
        $valorSalvo = $valor === '' ? null : parseDataProcessos($valor);
        if ($valor !== '' && $valorSalvo === null) {
            echo json_encode(['ok' => false, 'erro' => 'Data inválida. Use dd/mm/aaaa.']);
            exit;
        }
        $stmt = mysqli_prepare($conn, "UPDATE processos SET `$campo` = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $valorSalvo, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo json_encode(['ok' => true, 'exibido' => dataBr($valorSalvo)]);
        exit;
    }

    if (in_array($campo, $camposNumericos, true)) {
        $valorSalvo = $valor === '' ? null : parseNumeroBrProcessos($valor);
        if ($valor !== '' && $valorSalvo === null) {
            echo json_encode(['ok' => false, 'erro' => 'Valor numérico inválido.']);
            exit;
        }
        $stmt = mysqli_prepare($conn, "UPDATE processos SET `$campo` = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'di', $valorSalvo, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        // Total nunca é digitado — recalcula e grava de novo (quantidade × preço)
        // sempre que um dos dois muda.
        $stmtAtuais = mysqli_prepare($conn, "SELECT quantidade, preco FROM processos WHERE id = ?");
        mysqli_stmt_bind_param($stmtAtuais, 'i', $id);
        mysqli_stmt_execute($stmtAtuais);
        $atuais = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtAtuais));
        mysqli_stmt_close($stmtAtuais);

        $novoTotal = ($atuais['quantidade'] !== null && $atuais['preco'] !== null)
            ? (float) $atuais['quantidade'] * (float) $atuais['preco']
            : null;

        $stmtTotal = mysqli_prepare($conn, "UPDATE processos SET total = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmtTotal, 'di', $novoTotal, $id);
        mysqli_stmt_execute($stmtTotal);
        mysqli_stmt_close($stmtTotal);

        $exibido = $campo === 'quantidade' ? numeroBr($valorSalvo, 0) : numeroBr($valorSalvo);
        echo json_encode(['ok' => true, 'exibido' => $exibido]);
        exit;
    }

    // Campos de texto simples
    $valorSalvo = $valor === '' ? null : $valor;
    $stmt = mysqli_prepare($conn, "UPDATE processos SET `$campo` = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'si', $valorSalvo, $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    echo json_encode(['ok' => true, 'exibido' => $valorSalvo ?? '—']);
    exit;
}

// ---------- Importação de CSV ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo_csv'])) {
    if ($_FILES['arquivo_csv']['error'] !== UPLOAD_ERR_OK) {
        $mensagens[] = '❌ Falha no envio do arquivo.';
    } else {
        $caminho = $_FILES['arquivo_csv']['tmp_name'];
        $handle = fopen($caminho, 'r');
        if ($handle) {
            if (!empty($_POST['limpar_tabela'])) {
                mysqli_query($conn, 'TRUNCATE TABLE processos');
            }

            $primeiraLinha = fgets($handle);
            $separador = substr_count($primeiraLinha, ';') >= substr_count($primeiraLinha, ',') ? ';' : ',';
            rewind($handle);

            $cabecalhoOriginal = fgetcsv($handle, 0, $separador, '"', '\\');
            if ($cabecalhoOriginal === false) {
                $mensagens[] = '❌ Não consegui ler o cabeçalho do arquivo.';
            } else {
                $cabecalho = array_map('normalizarTextoProcessos', $cabecalhoOriginal);

                $mapaColunas = [
                    'processo' => ['processo'],
                    'solicitacao' => ['solicitacao'],
                    'categoria' => ['categoria'],
                    'planta' => ['planta'],
                    'po' => ['po'],
                    'modal' => ['modal'],
                    'projeto' => ['projeto'],
                    'material' => ['material'],
                    'codigo_componente' => ['codigo_componente', 'componente'],
                    'descricao' => ['descricao'],
                    'quantidade' => ['quantidade'],
                    'hscode' => ['hscode'],
                    'ncm' => ['ncm'],
                    'fornecedor' => ['fornecedor'],
                    'preco' => ['preco'],
                    'total' => ['total'],
                    'moeda' => ['moeda'],
                    'tipo' => ['tipo'],
                    'ffw' => ['ffw'],
                    'obs' => ['obs', 'observacao', 'observacoes'],
                    'controla_estoque' => ['controla_estoque', 'controla estoque', 'estoque'],
                ];

                $indices = [];
                foreach ($mapaColunas as $campo => $candidatos) {
                    $indices[$campo] = null;
                    foreach ($candidatos as $c) {
                        $pos = array_search($c, $cabecalho, true);
                        if ($pos !== false) { $indices[$campo] = $pos; break; }
                    }
                }

                if ($indices['processo'] === null) {
                    $mensagens[] = "❌ Não encontrei a coluna do processo. Use 'processo' no cabeçalho.";
                } else {
                    $lote = [];
                    $flushLote = function () use ($conn, &$lote, &$importados, &$erros, &$mensagens) {
                        if (empty($lote)) return;
                        $campos = ['processo', 'status', 'solicitacao', 'categoria', 'planta', 'po', 'modal', 'projeto', 'material', 'codigo_componente', 'descricao', 'quantidade', 'hscode', 'ncm', 'fornecedor', 'preco', 'total', 'moeda', 'tipo', 'ffw', 'obs', 'controla_estoque'];
                        $linhasSql = [];
                        $todosValores = [];
                        foreach ($lote as $linhaLote) {
                            $linhasSql[] = '(' . implode(',', array_fill(0, count($campos), '?')) . ')';
                            foreach ($linhaLote as $v) { $todosValores[] = $v; }
                        }
                        $sql = 'INSERT INTO processos (' . implode(',', $campos) . ') VALUES ' . implode(', ', $linhasSql);
                        $stmt = mysqli_prepare($conn, $sql);
                        $tipos = str_repeat('s', count($todosValores));
                        mysqli_stmt_bind_param($stmt, $tipos, ...$todosValores);
                        if (mysqli_stmt_execute($stmt)) {
                            $importados += count($lote);
                        } else {
                            $erros += count($lote);
                            $mensagens[] = '❌ Erro ao gravar lote: ' . mysqli_stmt_error($stmt);
                        }
                        mysqli_stmt_close($stmt);
                        $lote = [];
                    };

                    while (($linha = fgetcsv($handle, 0, $separador, '"', '\\')) !== false) {
                        if (count(array_filter($linha, fn($v) => trim((string) $v) !== '')) === 0) {
                            continue;
                        }
                        $get = fn($campo) => $indices[$campo] !== null ? trim((string) ($linha[$indices[$campo]] ?? '')) : '';

                        $processo = $get('processo');
                        if ($processo === '') { continue; }

                        $quantidade = parseNumeroBrProcessos($get('quantidade'));
                        $preco = parseNumeroBrProcessos($get('preco'));
                        // Se o CSV não trouxer "total" (coluna ausente ou vazia), calcula
                        // sozinho Quantidade × Preço — mesma regra do cadastro manual.
                        // Se o CSV trouxer um valor de "total", usa esse valor tal como
                        // está (não sobrescreve o que veio pronto da planilha).
                        $total = parseNumeroBrProcessos($get('total'));
                        if ($total === null && $quantidade !== null && $preco !== null) {
                            $total = $quantidade * $preco;
                        }
                        $solicitacao = parseDataProcessos($get('solicitacao'));

                        // Aceita variações comuns no CSV (sim/não, s/n, yes/no, 1/0).
                        // Sem a coluna no arquivo, ou com valor não reconhecido, o padrão
                        // passa a depender da categoria: "tooling" nasce "não controla
                        // estoque" (é o padrão esperado pra tooling/amostra); as demais
                        // categorias continuam nascendo "sim" (item normal de importação).
                        // Isso é só o valor INICIAL — continua 100% editável depois, tanto
                        // na tela (toggle Sim/Não) quanto reimportando o CSV.
                        $controlaEstoqueTexto = mb_strtolower($get('controla_estoque'), 'UTF-8');
                        $categoriaTextoCsv = mb_strtolower(trim($get('categoria')), 'UTF-8');
                        if (in_array($controlaEstoqueTexto, ['sim', 's', 'yes', '1'], true)) {
                            $controlaEstoque = 'sim';
                        } elseif (in_array($controlaEstoqueTexto, ['nao', 'não', 'n', 'no', '0'], true)) {
                            $controlaEstoque = 'nao';
                        } else {
                            $controlaEstoque = $categoriaTextoCsv === 'tooling' ? 'nao' : 'sim';
                        }

                        $lote[] = [
                            $processo,
                            'aberto', // status nunca vem do CSV — só o confirmar_entrega.php fecha ele
                            $solicitacao,
                            $get('categoria') ?: null,
                            $get('planta') ?: null,
                            $get('po') ?: null,
                            $get('modal') ?: null,
                            $get('projeto') ?: null,
                            $get('material') ?: null,
                            $get('codigo_componente') ?: null,
                            $get('descricao') ?: null,
                            $quantidade,
                            $get('hscode') ?: null,
                            $get('ncm') ?: null,
                            $get('fornecedor') ?: null,
                            $preco,
                            $total,
                            $get('moeda') ?: null,
                            $get('tipo') ?: null,
                            $get('ffw') ?: null,
                            $get('obs') ?: null,
                            $controlaEstoque,
                        ];

                        if (count($lote) >= 200) {
                            $flushLote();
                        }
                    }
                    $flushLote();

                    if ($importados > 0) {
                        $mensagens[] = "✅ $importados linha(s) importada(s) com sucesso.";
                    }
                    if ($erros > 0) {
                        $mensagens[] = "⚠️ $erros linha(s) com erro.";
                    }
                }
            }
            fclose($handle);
        }
    }
}

// ---------- Cadastro manual ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cadastro_manual') {
    // O processo nunca é digitado no cadastro manual — é sempre gerado, e só
    // depois que os 4 campos que compõem o código estiverem preenchidos
    // (Categoria, Planta, Solicitação, Modal). Fornecedor NÃO entra no código
    // e não é mais obrigatório.
    $categoria = trim($_POST['categoria_manual'] ?? '') ?: null;
    $planta = trim($_POST['planta_manual'] ?? '') ?: null;
    $solicitacaoTexto = trim($_POST['solicitacao_manual'] ?? '');
    $modal = trim($_POST['modal_manual'] ?? '') ?: null;
    $fornecedor = trim($_POST['fornecedor_manual'] ?? '') ?: null;

    $faltando = [];
    if ($categoria === null) { $faltando[] = 'Categoria'; }
    if ($planta === null) { $faltando[] = 'Planta'; }
    if ($solicitacaoTexto === '') { $faltando[] = 'Solicitação'; }
    if ($modal === null) { $faltando[] = 'Modal'; }

    if (!empty($faltando)) {
        $mensagens[] = '❌ Preencha os campos obrigatórios antes de salvar: ' . implode(', ', $faltando) . '.';
    } else {
        $geracao = gerarCodigoProcesso($conn, $modal, $planta, $categoria);
        if ($geracao['codigo'] === null) {
            $mensagens[] = '❌ ' . $geracao['erro'];
        } else {
        $processo = $geracao['codigo'];
        $stmt = mysqli_prepare($conn, "
            INSERT INTO processos (processo, status, solicitacao, categoria, planta, po, modal, projeto, material, codigo_componente, descricao, quantidade, hscode, ncm, fornecedor, preco, total, moeda, tipo, ffw, obs, controla_estoque)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $status = 'aberto'; // nunca digitado — só o confirmar_entrega.php fecha (vira "finalizado")
        $solicitacao = parseDataProcessos($solicitacaoTexto);
        $po = trim($_POST['po_manual'] ?? '') ?: null;
        $projeto = trim($_POST['projeto_manual'] ?? '') ?: null;
        $material = trim($_POST['material_manual'] ?? '') ?: null;
        $codigoComponente = trim($_POST['codigo_componente_manual'] ?? '') ?: null;
        $descricao = trim($_POST['descricao_manual'] ?? '') ?: null;
        $quantidade = parseNumeroBrProcessos(trim($_POST['quantidade_manual'] ?? ''));
        $hscode = trim($_POST['hscode_manual'] ?? '') ?: null;
        $ncm = trim($_POST['ncm_manual'] ?? '') ?: null;
        $preco = parseNumeroBrProcessos(trim($_POST['preco_manual'] ?? ''));
        // Total nunca é digitado — sempre calculado: quantidade × preço.
        $total = ($quantidade !== null && $preco !== null) ? $quantidade * $preco : null;
        $moeda = trim($_POST['moeda_manual'] ?? '') ?: null;
        $tipo = trim($_POST['tipo_manual'] ?? '') ?: null;
        $ffw = trim($_POST['ffw_manual'] ?? '') ?: null;
        $obs = trim($_POST['obs_manual'] ?? '') ?: null;
        // Checkbox desmarcado não vem no POST — ausência = "nao". Padrão do
        // formulário é vir marcado (checked), então o normal é chegar "sim".
        $controlaEstoqueManual = isset($_POST['controla_estoque_manual']) ? 'sim' : 'nao';

        // Tipos: processo(s) status(s) solicitacao(s) categoria(s) planta(s) po(s) modal(s)
        // projeto(s) material(s) codigo_componente(s) descricao(s) quantidade(d) hscode(s)
        // ncm(s) fornecedor(s) preco(d) total(d) moeda(s) tipo(s) ffw(s) obs(s) controla_estoque(s)
        mysqli_stmt_bind_param(
            $stmt, 'sssssssssssdsssddsssss',
            $processo, $status, $solicitacao, $categoria, $planta, $po, $modal, $projeto, $material, $codigoComponente,
            $descricao, $quantidade, $hscode, $ncm, $fornecedor, $preco, $total, $moeda, $tipo, $ffw, $obs,
            $controlaEstoqueManual
        );
        if (mysqli_stmt_execute($stmt)) {
            $mensagens[] = "✅ Processo \"$processo\" cadastrado (código gerado automaticamente).";
        } else {
            $mensagens[] = '❌ Erro ao cadastrar: ' . mysqli_stmt_error($stmt);
        }
        mysqli_stmt_close($stmt);
        }
    }
}

// ---------- Exportação CSV ----------
if (isset($_GET['exportar'])) {
    $busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
    $filtroPlantaExp = trim($_GET['planta'] ?? '');
    $filtroComponenteExp = trim($_GET['componente'] ?? '');
    $filtroCategoriaExp = trim($_GET['categoria'] ?? '');
    $filtroFornecedorExp = trim($_GET['fornecedor'] ?? '');

    $condicoesExp = [];
    $paramsExp = [];
    $tiposExp = '';
    if ($busca !== '') {
        $condicoesExp[] = "(processo LIKE ? OR codigo_componente LIKE ? OR fornecedor LIKE ? OR descricao LIKE ?)";
        $like = '%' . $busca . '%';
        $paramsExp[] = $like; $paramsExp[] = $like; $paramsExp[] = $like; $paramsExp[] = $like;
        $tiposExp .= 'ssss';
    }
    if ($filtroPlantaExp !== '') {
        $condicoesExp[] = "planta = ?";
        $paramsExp[] = $filtroPlantaExp;
        $tiposExp .= 's';
    }
    if ($filtroComponenteExp !== '') {
        $condicoesExp[] = "codigo_componente LIKE ?";
        $paramsExp[] = '%' . $filtroComponenteExp . '%';
        $tiposExp .= 's';
    }
    if ($filtroCategoriaExp !== '') {
        $condicoesExp[] = "categoria = ?";
        $paramsExp[] = $filtroCategoriaExp;
        $tiposExp .= 's';
    }
    if ($filtroFornecedorExp !== '') {
        $condicoesExp[] = "fornecedor = ?";
        $paramsExp[] = $filtroFornecedorExp;
        $tiposExp .= 's';
    }
    $where = !empty($condicoesExp) ? ('WHERE ' . implode(' AND ', $condicoesExp)) : '';

    $sqlExport = "SELECT * FROM processos $where ORDER BY criado_em DESC";
    if (!empty($paramsExp)) {
        $stmtExport = mysqli_prepare($conn, $sqlExport);
        mysqli_stmt_bind_param($stmtExport, $tiposExp, ...$paramsExp);
        mysqli_stmt_execute($stmtExport);
        $resultExport = mysqli_stmt_get_result($stmtExport);
    } else {
        $resultExport = mysqli_query($conn, $sqlExport);
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="processos.csv"');
    echo "\xEF\xBB\xBF";
    $saida = fopen('php://output', 'w');
    fputcsv($saida, ['processo', 'status', 'solicitacao', 'categoria', 'planta', 'po', 'modal', 'projeto', 'material', 'codigo_componente', 'descricao', 'quantidade', 'hscode', 'ncm', 'fornecedor', 'preco', 'total', 'moeda', 'tipo', 'ffw', 'obs'], ';', '"', '');
    while ($linha = mysqli_fetch_assoc($resultExport)) {
        fputcsv($saida, [
            $linha['processo'], $linha['status'], $linha['solicitacao'], $linha['categoria'], $linha['planta'],
            $linha['po'], $linha['modal'], $linha['projeto'], $linha['material'], $linha['codigo_componente'], $linha['descricao'],
            $linha['quantidade'] !== null ? number_format((float) $linha['quantidade'], 2, ',', '') : '',
            $linha['hscode'], $linha['ncm'], $linha['fornecedor'],
            $linha['preco'] !== null ? number_format((float) $linha['preco'], 2, ',', '') : '',
            $linha['total'] !== null ? number_format((float) $linha['total'], 2, ',', '') : '',
            $linha['moeda'], $linha['tipo'], $linha['ffw'], $linha['obs'],
        ], ';', '"', '');
    }
    fclose($saida);
    exit;
}

// ---------- Listagem ----------
$porPagina = 50;
$pagina = isset($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$offset = ($pagina - 1) * $porPagina;
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$filtroPlanta = trim($_GET['planta'] ?? '');
$filtroComponente = trim($_GET['componente'] ?? '');
$filtroCategoria = trim($_GET['categoria'] ?? '');
$filtroFornecedor = trim($_GET['fornecedor'] ?? '');

// Listas pros filtros em dropdown (só valores que já existem de verdade na base)
$plantasDisponiveis = [];
$res = mysqli_query($conn, "SELECT DISTINCT planta FROM processos WHERE planta IS NOT NULL AND TRIM(planta) <> '' ORDER BY planta");
while ($linha = mysqli_fetch_assoc($res)) { $plantasDisponiveis[] = $linha['planta']; }

$categoriasDisponiveis = [];
$res = mysqli_query($conn, "SELECT DISTINCT categoria FROM processos WHERE categoria IS NOT NULL AND TRIM(categoria) <> '' ORDER BY categoria");
while ($linha = mysqli_fetch_assoc($res)) { $categoriasDisponiveis[] = $linha['categoria']; }

$fornecedoresDisponiveis = [];
$res = mysqli_query($conn, "SELECT DISTINCT fornecedor FROM processos WHERE fornecedor IS NOT NULL AND TRIM(fornecedor) <> '' ORDER BY fornecedor");
while ($linha = mysqli_fetch_assoc($res)) { $fornecedoresDisponiveis[] = $linha['fornecedor']; }

$condicoes = [];
$params = [];
$tipos = '';
if ($busca !== '') {
    $condicoes[] = "(processo LIKE ? OR codigo_componente LIKE ? OR fornecedor LIKE ? OR descricao LIKE ?)";
    $like = '%' . $busca . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $tipos .= 'ssss';
}
// Os 4 filtros abaixo são independentes entre si e da busca livre — combinam
// com AND (cada um restringe mais o resultado), diferente da busca livre
// (que usa OR entre as colunas pra achar qualquer correspondência).
if ($filtroPlanta !== '') {
    $condicoes[] = "planta = ?";
    $params[] = $filtroPlanta;
    $tipos .= 's';
}
if ($filtroComponente !== '') {
    $condicoes[] = "codigo_componente LIKE ?";
    $params[] = '%' . $filtroComponente . '%';
    $tipos .= 's';
}
if ($filtroCategoria !== '') {
    $condicoes[] = "categoria = ?";
    $params[] = $filtroCategoria;
    $tipos .= 's';
}
if ($filtroFornecedor !== '') {
    $condicoes[] = "fornecedor = ?";
    $params[] = $filtroFornecedor;
    $tipos .= 's';
}

$where = !empty($condicoes) ? ('WHERE ' . implode(' AND ', $condicoes)) : '';

$stmtTotal = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM processos $where");
if (!empty($params)) { mysqli_stmt_bind_param($stmtTotal, $tipos, ...$params); }
mysqli_stmt_execute($stmtTotal);
$total = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmtTotal))['total'];
mysqli_stmt_close($stmtTotal);
$totalPaginas = max(1, (int) ceil($total / $porPagina));

$sql = "SELECT * FROM processos $where ORDER BY criado_em DESC LIMIT ? OFFSET ?";
$stmt = mysqli_prepare($conn, $sql);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $tipos . 'ii', ...array_merge($params, [$porPagina, $offset]));
} else {
    mysqli_stmt_bind_param($stmt, 'ii', $porPagina, $offset);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$rows = [];
while ($row = mysqli_fetch_assoc($result)) { $rows[] = $row; }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Processos | Controle de Importação</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/dashboard.css" rel="stylesheet">
    <style>
        /* Espaçamento extra — a tabela tem muitas colunas, então o padding
           padrão do dashboard.css (12px) fica meio apertado nessa tela. */
        #tabela-processos td, #tabela-processos th { padding: 14px 16px; }
        #tabela-processos td { font-size: .85rem; }
        /* Descrição e Obs podem vir com texto longo sem espaço nenhum (ex.: uma
           palavra gigante) — sem isso, o texto "vaza" por cima da célula vizinha
           em vez de truncar com reticências. */
        #tabela-processos .description-cell {
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            /* dashboard.css define .description-cell com "display: block", o que
               tira a célula do alinhamento vertical normal da tabela (o texto sobe
               pro topo em vez de ficar centralizado como as outras colunas). Aqui
               a gente sobrescreve isso só nesta tabela, voltando pro comportamento
               padrão de célula. */
            display: table-cell;
            vertical-align: middle;
        }
        #tabela-processos .btn-remover-linha {
            border: none;
            background: none;
            color: #c53535;
            font-size: 1.05rem;
            cursor: pointer;
            line-height: 1;
        }
        #tabela-processos .btn-remover-linha:hover {
            color: #a12727;
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="container-fluid dashboard-container d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="eyebrow">Controle de Importação • Processos</span>
                <h1>Processos</h1>
                <p class="mb-0"><?php echo numeroBr($total, 0); ?> processo(s) na base</p>
            </div>
            <nav class="d-flex flex-wrap gap-2" aria-label="Ações do sistema">
                <a class="btn btn-outline-light btn-sm" href="follow.php">Follow</a>
                <a class="btn btn-light btn-sm" href="processos.php">Processos</a>
                <a class="btn btn-outline-light btn-sm" href="pagamento.php">Pagamento</a>
                <a class="btn btn-outline-light btn-sm" href="confirmar_entrega.php">Confirmar entrega</a>
                <a class="btn btn-outline-light btn-sm" href="commercial_invoice.php">📄 Commercial Invoice</a>
                <a class="btn btn-outline-light btn-sm" href="packing_list.php">📦 Packing List</a>
            </nav>
        </div>
    </header>

    <main class="container-fluid dashboard-container py-4">

        <?php if (isset($_GET['status_alterado'])): ?>
            <div class="alert alert-success">✅ Status do processo atualizado.</div>
        <?php endif; ?>

        <?php if (isset($_GET['excluido'])): ?>
            <div class="alert alert-success">✅ Processo excluído.</div>
        <?php endif; ?>

        <?php if (!empty($mensagens)): ?>
            <div class="alert alert-info">
                <?php foreach ($mensagens as $msg): ?>
                    <div><?php echo h($msg); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="filter-panel mb-4">
            <details>
                <summary class="fw-bold" style="cursor:pointer;">📤 Importar novo arquivo CSV</summary>
                <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end mt-3">
                    <div class="col-md-6">
                        <label class="form-label">Arquivo CSV</label>
                        <input type="file" name="arquivo_csv" class="form-control" accept=".csv" required>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check mt-4">
                            <input type="checkbox" name="limpar_tabela" id="limpar_tabela" class="form-check-input">
                            <label class="form-check-label" for="limpar_tabela">Limpar tabela antes de importar</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100">Importar</button>
                    </div>
                </form>
                <p class="mt-3 mb-0" style="font-size:.8rem; color:var(--muted);">
                    Colunas esperadas (primeira linha = cabeçalho, qualquer ordem):<br>
                    <code>processo, solicitacao, categoria, planta, po, modal, projeto, material, codigo_componente, descricao, quantidade, hscode, ncm, fornecedor, preco, total, moeda, tipo, ffw, obs, estoque</code><br>
                    Só <code>processo</code> é obrigatório — as demais colunas podem faltar. <strong>Não existe coluna <code>status</code></strong>: todo processo nasce "aberto" automaticamente, e só vira "finalizado" quando o embarque correspondente é confirmado na tela Confirmar Entrega. Separador: vírgula ou ponto e vírgula (detectado automaticamente).<br>
                    <strong>Coluna <code>estoque</code></strong> (aceita também <code>controla_estoque</code>): use <code>sim</code>/<code>não</code> (ou <code>s</code>/<code>n</code>, <code>yes</code>/<code>no</code>, <code>1</code>/<code>0</code>) pra já subir cada linha com o Estoque certo, sem precisar alternar depois — se a coluna faltar ou vier com um valor não reconhecido, categoria "tooling" nasce "Não" e as demais nascem "Sim". É só o valor inicial: o botão Sim/Não na tabela continua liberado pra alternar a qualquer momento (menos em processo já finalizado).
                </p>
            </details>
        </section>

        <section class="filter-panel mb-4">
            <details>
                <summary class="fw-bold" style="cursor:pointer;">➕ Novo processo (cadastro manual)</summary>
                <form method="POST" class="row g-3 mt-3">
                    <input type="hidden" name="acao" value="cadastro_manual">
                    <div class="col-md-2">
                        <label class="form-label">Processo <small class="text-muted">(prévia — só confirma ao salvar)</small></label>
                        <input type="text" id="preview_codigo_processo" class="form-control" value="Preencha Categoria, Planta e Modal" disabled>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Solicitação *</label>
                        <input type="text" name="solicitacao_manual" class="form-control" placeholder="dd/mm/aaaa" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Categoria *</label>
                        <input type="text" name="categoria_manual" id="categoria_manual" class="form-control" placeholder="tooling / project / other" required oninput="aplicarPadraoControlaEstoqueTooling(); atualizarPreviewCodigoProcesso();">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Planta *</label>
                        <input type="text" name="planta_manual" id="planta_manual" class="form-control" required oninput="atualizarPreviewCodigoProcesso()">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">PO</label>
                        <input type="text" name="po_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Modal *</label>
                        <input type="text" name="modal_manual" id="modal_manual" class="form-control" placeholder="aereo / sea / road / courrier" required oninput="atualizarPreviewCodigoProcesso()">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Projeto</label>
                        <input type="text" name="projeto_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Material</label>
                        <input type="text" name="material_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Componente</label>
                        <input type="text" name="codigo_componente_manual" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Descrição</label>
                        <input type="text" name="descricao_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Quantidade</label>
                        <input type="text" name="quantidade_manual" id="quantidade_manual" class="form-control" oninput="calcularTotalProcesso()">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">HS Code</label>
                        <input type="text" name="hscode_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">NCM</label>
                        <input type="text" name="ncm_manual" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Fornecedor</label>
                        <input type="text" name="fornecedor_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Preço</label>
                        <input type="text" name="preco_manual" id="preco_manual" class="form-control" oninput="calcularTotalProcesso()">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Total <small class="text-muted">(qtd × preço)</small></label>
                        <input type="text" id="total_manual_display" class="form-control" readonly placeholder="—">
                        <input type="hidden" name="total_manual" id="total_manual">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Moeda</label>
                        <input type="text" name="moeda_manual" class="form-control" placeholder="usd">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Tipo</label>
                        <input type="text" name="tipo_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">FFW</label>
                        <input type="text" name="ffw_manual" class="form-control">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Observações</label>
                        <input type="text" name="obs_manual" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <div class="form-check mt-4">
                            <input type="checkbox" name="controla_estoque_manual" id="controla_estoque_manual" class="form-check-input" checked onchange="controlaEstoqueManualTocadoPeloUsuario = true;">
                            <label class="form-check-label" for="controla_estoque_manual">Controla estoque (entra no Confirmar Entrega)</label>
                        </div>
                        <small class="text-muted">Desmarque pra tooling, amostra e itens que não devem alimentar o estoque do MRP.</small>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Salvar</button>
                    </div>
                </form>
            </details>
        </section>

        <section class="filter-panel mb-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Buscar por processo, componente, fornecedor ou descrição</label>
                    <input type="text" name="busca" class="form-control" value="<?php echo h($busca); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Planta</label>
                    <select name="planta" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($plantasDisponiveis as $pl): ?>
                            <option value="<?php echo h($pl); ?>" <?php echo $filtroPlanta === $pl ? 'selected' : ''; ?>><?php echo h($pl); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Componente</label>
                    <input type="text" name="componente" class="form-control" value="<?php echo h($filtroComponente); ?>" placeholder="Ex.: 11001199">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Categoria</label>
                    <select name="categoria" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($categoriasDisponiveis as $cat): ?>
                            <option value="<?php echo h($cat); ?>" <?php echo $filtroCategoria === $cat ? 'selected' : ''; ?>><?php echo h($cat); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Fornecedor</label>
                    <select name="fornecedor" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($fornecedoresDisponiveis as $forn): ?>
                            <option value="<?php echo h($forn); ?>" <?php echo $filtroFornecedor === $forn ? 'selected' : ''; ?>><?php echo h($forn); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Buscar</button>
                </div>
                <div class="col-md-2">
                    <a href="processos.php" class="btn btn-outline-secondary w-100">Limpar filtros</a>
                </div>
                <div class="col-md-2">
                    <a href="?exportar=1&busca=<?php echo urlencode($busca); ?>&planta=<?php echo urlencode($filtroPlanta); ?>&componente=<?php echo urlencode($filtroComponente); ?>&categoria=<?php echo urlencode($filtroCategoria); ?>&fornecedor=<?php echo urlencode($filtroFornecedor); ?>" class="btn btn-outline-secondary w-100">Exportar CSV</a>
                </div>
            </form>
        </section>

        <section class="table-card">
            <div class="table-toolbar">
                <div>
                    <span class="eyebrow text-primary">Resultado</span>
                    <h2>Processos</h2>
                    <p><?php echo numeroBr($total, 0); ?> encontrado(s)</p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table mrp-table mb-0" id="tabela-processos" style="min-width: 2050px;">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Estoque</th>
                            <th>Processo</th>
                            <th>Solicitação</th>
                            <th>Categoria</th>
                            <th>Planta</th>
                            <th>PO</th>
                            <th>Modal</th>
                            <th>Projeto</th>
                            <th>Material</th>
                            <th>Componente</th>
                            <th>Descrição</th>
                            <th>Quantidade</th>
                            <th>HS Code</th>
                            <th>NCM</th>
                            <th>Fornecedor</th>
                            <th>Preço</th>
                            <th>Total</th>
                            <th>Moeda</th>
                            <th>Tipo</th>
                            <th>FFW</th>
                            <th>Obs</th>
                            <th class="no-print">Excluir</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="23" class="empty-state">Nenhum processo encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <?php
                                    $id = (int) $r['id'];
                                    $statusAtualRow = strtolower(trim((string) ($r['status'] ?? '')));
                                    $statusFinalizado = $statusAtualRow === 'finalizado';
                                    $statusCancelado = $statusAtualRow === 'cancelado';
                                    $controlaEstoqueRow = strtolower(trim((string) ($r['controla_estoque'] ?? 'sim'))) === 'sim';
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($statusFinalizado): ?>
                                            <span class="status-badge status-ok" title="Finalizado (entrega confirmada) — não pode mais mudar">Finalizado</span>
                                        <?php else: ?>
                                            <form method="POST" class="d-inline m-0">
                                                <input type="hidden" name="acao" value="toggle_status_processo">
                                                <input type="hidden" name="processo" value="<?php echo h($r['processo']); ?>">
                                                <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                                                <input type="hidden" name="busca_atual" value="<?php echo h($busca); ?>">
                                                <input type="hidden" name="planta_atual" value="<?php echo h($filtroPlanta); ?>">
                                                <input type="hidden" name="componente_atual" value="<?php echo h($filtroComponente); ?>">
                                                <input type="hidden" name="categoria_atual" value="<?php echo h($filtroCategoria); ?>">
                                                <input type="hidden" name="fornecedor_atual" value="<?php echo h($filtroFornecedor); ?>">
                                                <button type="submit" class="status-badge border-0 <?php echo $statusCancelado ? 'status-critico' : 'status-atencao'; ?>" style="cursor:pointer;" title="Clique pra alternar entre Aberto e Cancelado">
                                                    <?php echo $statusCancelado ? 'Cancelado' : 'Aberto'; ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($statusFinalizado): ?>
                                            <span class="status-badge <?php echo $controlaEstoqueRow ? 'status-ok' : 'status-sem_demanda'; ?>" title="Finalizado — não é possível mudar">
                                                <?php echo $controlaEstoqueRow ? 'Sim' : 'Não'; ?>
                                            </span>
                                        <?php else: ?>
                                            <form method="POST" class="d-inline m-0">
                                                <input type="hidden" name="acao" value="toggle_controla_estoque">
                                                <input type="hidden" name="id" value="<?php echo $id; ?>">
                                                <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                                                <input type="hidden" name="busca_atual" value="<?php echo h($busca); ?>">
                                                <input type="hidden" name="planta_atual" value="<?php echo h($filtroPlanta); ?>">
                                                <input type="hidden" name="componente_atual" value="<?php echo h($filtroComponente); ?>">
                                                <input type="hidden" name="categoria_atual" value="<?php echo h($filtroCategoria); ?>">
                                                <input type="hidden" name="fornecedor_atual" value="<?php echo h($filtroFornecedor); ?>">
                                                <button type="submit" class="status-badge border-0 <?php echo $controlaEstoqueRow ? 'status-ok' : 'status-sem_demanda'; ?>" style="cursor:pointer;" title="Clique pra alternar — 'Não' significa que esse item não entra no Confirmar Entrega (ex.: tooling, amostra)">
                                                    <?php echo $controlaEstoqueRow ? 'Sim' : 'Não'; ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="component-code"><?php echo h($r['processo']); ?></span></td>
                                    <td class="celula-editavel" data-id="<?php echo $id; ?>" data-campo="solicitacao" data-valor-bruto="<?php echo $r['solicitacao'] ? h(dataBr($r['solicitacao'])) : ''; ?>"><?php echo dataBr($r['solicitacao']); ?></td>
                                    <td class="celula-editavel" data-id="<?php echo $id; ?>" data-campo="categoria" data-valor-bruto="<?php echo h($r['categoria'] ?? ''); ?>"><?php echo h($r['categoria'] ?: '—'); ?></td>
                                    <td class="celula-editavel" data-id="<?php echo $id; ?>" data-campo="planta" data-valor-bruto="<?php echo h($r['planta'] ?? ''); ?>"><?php echo h($r['planta'] ?: '—'); ?></td>
                                    <td class="celula-editavel" data-id="<?php echo $id; ?>" data-campo="po" data-valor-bruto="<?php echo h($r['po'] ?? ''); ?>"><?php echo h($r['po'] ?: '—'); ?></td>
                                    <td class="celula-editavel" data-id="<?php echo $id; ?>" data-campo="modal" data-valor-bruto="<?php echo h($r['modal'] ?? ''); ?>"><?php echo h($r['modal'] ?: '—'); ?></td>
                                    <td class="celula-editavel" data-id="<?php echo $id; ?>" data-campo="projeto" data-valor-bruto="<?php echo h($r['projeto'] ?? ''); ?>"><?php echo h($r['projeto'] ?: '—'); ?></td>
                                    <td class="celula-editavel" data-id="<?php echo $id; ?>" data-campo="material" data-valor-bruto="<?php echo h($r['material'] ?? ''); ?>"><?php echo h($r['material'] ?: '—'); ?></td>
                                    <td class="celula-editavel" data-id="<?php echo $id; ?>" data-campo="codigo_componente" data-valor-bruto="<?php echo h($r['codigo_componente'] ?? ''); ?>"><?php echo h($r['codigo_componente'] ?: '—'); ?></td>
                                    <td class="celula-editavel description-cell" data-id="<?php echo $id; ?>" data-campo="descricao" data-valor-bruto="<?php echo h($r['descricao'] ?? ''); ?>" title="<?php echo h($r['descricao'] ?? ''); ?>"><?php echo h($r['descricao'] ?: '—'); ?></td>
                                    <td class="celula-editavel col-quantidade" data-id="<?php echo $id; ?>" data-campo="quantidade" data-valor-bruto="<?php echo $r['quantidade'] !== null ? numeroBr($r['quantidade'], 0) : ''; ?>"><?php echo numeroBr($r['quantidade'], 0); ?></td>
                                    <td class="celula-editavel" data-id="<?php echo $id; ?>" data-campo="hscode" data-valor-bruto="<?php echo h($r['hscode'] ?? ''); ?>"><?php echo h($r['hscode'] ?: '—'); ?></td>
                                    <td class="celula-editavel" data-id="<?php echo $id; ?>" data-campo="ncm" data-valor-bruto="<?php echo h($r['ncm'] ?? ''); ?>"><?php echo h($r['ncm'] ?: '—'); ?></td>
                                    <td class="celula-editavel" data-id="<?php echo $id; ?>" data-campo="fornecedor" data-valor-bruto="<?php echo h($r['fornecedor'] ?? ''); ?>"><?php echo h($r['fornecedor'] ?: '—'); ?></td>
                                    <td class="celula-editavel col-preco" data-id="<?php echo $id; ?>" data-campo="preco" data-valor-bruto="<?php echo $r['preco'] !== null ? numeroBr($r['preco']) : ''; ?>"><?php echo numeroBr($r['preco']); ?></td>
                                    <td class="col-total"><?php echo numeroBr($r['total']); ?></td>
                                    <td class="celula-editavel" data-id="<?php echo $id; ?>" data-campo="moeda" data-valor-bruto="<?php echo h($r['moeda'] ?? ''); ?>"><?php echo h($r['moeda'] ?: '—'); ?></td>
                                    <td class="celula-editavel" data-id="<?php echo $id; ?>" data-campo="tipo" data-valor-bruto="<?php echo h($r['tipo'] ?? ''); ?>"><?php echo h($r['tipo'] ?: '—'); ?></td>
                                    <td class="celula-editavel" data-id="<?php echo $id; ?>" data-campo="ffw" data-valor-bruto="<?php echo h($r['ffw'] ?? ''); ?>"><?php echo h($r['ffw'] ?: '—'); ?></td>
                                    <td class="celula-editavel description-cell" data-id="<?php echo $id; ?>" data-campo="obs" data-valor-bruto="<?php echo h($r['obs'] ?? ''); ?>" title="<?php echo h($r['obs'] ?? ''); ?>"><?php echo h($r['obs'] ?: '—'); ?></td>
                                    <td class="no-print">
                                        <form method="POST" class="d-inline m-0" onsubmit="return confirm('Excluir este processo? Essa ação não pode ser desfeita.');">
                                            <input type="hidden" name="acao" value="excluir_processo">
                                            <input type="hidden" name="id" value="<?php echo $id; ?>">
                                            <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                                            <input type="hidden" name="busca_atual" value="<?php echo h($busca); ?>">
                                            <input type="hidden" name="planta_atual" value="<?php echo h($filtroPlanta); ?>">
                                            <input type="hidden" name="componente_atual" value="<?php echo h($filtroComponente); ?>">
                                            <input type="hidden" name="categoria_atual" value="<?php echo h($filtroCategoria); ?>">
                                            <input type="hidden" name="fornecedor_atual" value="<?php echo h($filtroFornecedor); ?>">
                                            <button type="submit" class="btn-remover-linha" title="Excluir">✕</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPaginas > 1): ?>
                <div class="pagination-bar">
                    <span>Página <?php echo $pagina; ?> de <?php echo $totalPaginas; ?></span>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
                                <li class="page-item <?php echo $p === $pagina ? 'active' : ''; ?>">
                                    <a class="page-link" href="?pagina=<?php echo $p; ?>&busca=<?php echo urlencode($busca); ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </section>

        <footer class="dashboard-footer">Controle de Importação — site independente do MRP, integração via processo controlado.</footer>
    </main>
    <script>
        // Converte texto em formato BR ("1.234,56") ou número puro em float JS.
        function parseNumeroBrProcJs(texto) {
            if (!texto) return 0;
            texto = String(texto).trim();
            if (texto.includes(',')) {
                texto = texto.replace(/\./g, '').replace(',', '.');
            }
            const n = parseFloat(texto);
            return isNaN(n) ? 0 : n;
        }

        // Categoria "tooling" pré-marca Controla estoque como "Não" ao digitar
        // no cadastro manual — só um valor inicial sugerido: o checkbox continua
        // 100% editável, e paramos de mexer nele assim que o usuário mesmo o tocar.
        let controlaEstoqueManualTocadoPeloUsuario = false;
        function aplicarPadraoControlaEstoqueTooling() {
            if (controlaEstoqueManualTocadoPeloUsuario) return;
            const campoCategoria = document.getElementById('categoria_manual');
            const checkboxControlaEstoque = document.getElementById('controla_estoque_manual');
            if (!campoCategoria || !checkboxControlaEstoque) return;
            const categoria = campoCategoria.value.trim().toLowerCase();
            checkboxControlaEstoque.checked = categoria !== 'tooling';
        }

        // Prévia do código do processo — consulta o servidor (o sequencial
        // depende do maior já usado no banco, não dá pra calcular só no
        // navegador) sempre que Categoria/Planta/Modal mudam. É só uma
        // prévia: o código de verdade é gerado (e gravado) só ao clicar em
        // Salvar, então pode mudar se outra pessoa cadastrar um processo
        // entre a prévia e o salvamento. Debounce de 400ms pra não disparar
        // uma requisição a cada letra digitada.
        let debouncePreviewCodigoProcesso = null;
        function atualizarPreviewCodigoProcesso() {
            clearTimeout(debouncePreviewCodigoProcesso);
            debouncePreviewCodigoProcesso = setTimeout(buscarPreviewCodigoProcesso, 400);
        }

        function buscarPreviewCodigoProcesso() {
            const campoPreview = document.getElementById('preview_codigo_processo');
            const categoria = (document.getElementById('categoria_manual').value || '').trim();
            const planta = (document.getElementById('planta_manual').value || '').trim();
            const modal = (document.getElementById('modal_manual').value || '').trim();

            if (!campoPreview) return;
            if (!categoria || !planta || !modal) {
                campoPreview.value = 'Preencha Categoria, Planta e Modal';
                return;
            }

            campoPreview.value = 'Calculando...';
            const dados = new URLSearchParams({
                acao: 'ajax_preview_codigo_processo',
                categoria: categoria,
                planta: planta,
                modal: modal,
            });

            fetch('processos.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: dados.toString(),
            })
                .then((resposta) => resposta.json())
                .then((json) => {
                    campoPreview.value = json.ok ? json.codigo : (json.erro || 'Não foi possível calcular.');
                })
                .catch(() => {
                    campoPreview.value = 'Erro ao calcular a prévia.';
                });
        }

        // Total = Quantidade × Preço. Nunca digitado — sempre recalculado.
        function calcularTotalProcesso() {
            const quantidade = parseNumeroBrProcJs(document.getElementById('quantidade_manual').value);
            const preco = parseNumeroBrProcJs(document.getElementById('preco_manual').value);
            const total = quantidade * preco;
            const textoTotal = total.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('total_manual_display').value = textoTotal;
            document.getElementById('total_manual').value = textoTotal;
        }

        // A tabela recalcula o Total da linha ao vivo (sem reload) sempre que
        // Quantidade ou Preço são editados por duplo clique — o servidor também
        // já grava o total recalculado, isso só evita esperar a página recarregar.
        document.addEventListener('inline-edit:salvo', function (evento) {
            const campo = evento.detail && evento.detail.campo;
            if (campo !== 'quantidade' && campo !== 'preco') return;
            const linha = evento.target.closest('tr');
            if (!linha) return;
            const celQtd = linha.querySelector('.col-quantidade');
            const celPreco = linha.querySelector('.col-preco');
            const celTotal = linha.querySelector('.col-total');
            if (!celQtd || !celPreco || !celTotal) return;
            const quantidade = parseNumeroBrProcJs(celQtd.dataset.valorBruto);
            const preco = parseNumeroBrProcJs(celPreco.dataset.valorBruto);
            const total = quantidade * preco;
            celTotal.textContent = total.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        });
    </script>
    <script>
        // Status e Controla estoque agora trocam a badge por AJAX (sem
        // recarregar a página, então o scroll nem se mexe). Excluir continua
        // recarregando (a linha some da lista), então só ele guarda/restaura
        // a posição do scroll.
        document.addEventListener('submit', function (evento) {
            const form = evento.target;
            const acaoInput = form.querySelector('input[name="acao"]');
            if (!acaoInput) return;
            const acao = acaoInput.value;

            if (acao === 'toggle_status_processo' || acao === 'toggle_controla_estoque') {
                evento.preventDefault();
                const dados = new URLSearchParams(new FormData(form));
                dados.set('acao', 'ajax_' + acao);

                fetch('processos.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: dados.toString(),
                })
                    .then((resposta) => resposta.json())
                    .then((json) => {
                        if (!json.ok) {
                            alert(json.erro || 'Não foi possível atualizar.');
                            return;
                        }
                        const botao = form.querySelector('button[type="submit"]');
                        if (!botao) return;
                        botao.textContent = json.texto;
                        botao.classList.remove('status-ok', 'status-atencao', 'status-critico', 'status-sem_demanda');
                        botao.classList.add(json.classe);
                    })
                    .catch(() => {
                        alert('Erro de conexão ao atualizar. Tente de novo.');
                    });
                return;
            }

            if (acao === 'excluir_processo') {
                sessionStorage.setItem('processos_scroll', String(window.scrollY));
            }
        });
        window.addEventListener('DOMContentLoaded', function () {
            const scrollSalvo = sessionStorage.getItem('processos_scroll');
            if (scrollSalvo !== null) {
                window.scrollTo(0, parseInt(scrollSalvo, 10) || 0);
                sessionStorage.removeItem('processos_scroll');
            }
        });
    </script>
    <script>
        window.INLINE_EDIT_ENDPOINT = 'processos.php';
        window.INLINE_EDIT_ACAO = 'ajax_editar_campo';
    </script>
    <script src="assets/inline-edit.js"></script>
</body>
</html>
