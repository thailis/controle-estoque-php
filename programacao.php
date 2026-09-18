<?php
require_once 'conexao.php';

require_once 'auth.php';
exigirLogin();
set_time_limit(300);

$mensagens = [];
$importados = 0;
$atualizados = 0;
$ignorados = 0;
$erros = 0;

function parseDataProgramacao(string $valor): ?string
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }

    $formatos = ['d/m/Y', 'Y-m-d', 'd-m-Y'];
    foreach ($formatos as $formato) {
        $data = DateTimeImmutable::createFromFormat('!' . $formato, $valor);
        if ($data instanceof DateTimeImmutable) {
            return $data->format('Y-m-d');
        }
    }
    return null;
}

// Formata sempre como dd/mm/aaaa — usada pra pré-preencher o campo de edição
// (que agora é texto simples, não mais <input type="date">, justamente pra
// não depender do formato que o navegador escolhe mostrar).
function formatarDataBrProgramacao(?string $data): string
{
    if ($data === null || $data === '') {
        return '';
    }
    $obj = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
    return $obj ? $obj->format('d/m/Y') : $data;
}

function normalizarTexto(string $texto): string
{
    $texto = mb_strtolower(trim($texto), 'UTF-8');
    $mapa = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c',
    ];
    return strtr($texto, $mapa);
}

function parseQuantidade(string $valor): ?float
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }
    if (str_contains($valor, ',')) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    } elseif (substr_count($valor, '.') >= 1) {
        // Só ponto, sem vírgula: só remove como milhar se todos os grupos após
        // o primeiro ponto tiverem exatamente 3 dígitos (ex.: "1.400", "45.000").
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

// Localiza a coluna do código do componente (aceita "componente" ou "codigo_componente")
function localizarColunaComponente(array $cabecalhoNormalizado): ?int
{
    $candidatos = [
    'codigo_componente',
    'componente',
    'codigo do componente',
    'codigo componente',
    'cod componente',
    'codigo_do_componente'
];
    foreach ($candidatos as $candidato) {
        $indice = array_search($candidato, $cabecalhoNormalizado, true);
        if ($indice !== false) {
            return $indice;
        }
    }
    return null;
}

// Detecta pares [indice_data, indice_quantidade]: qualquer coluna cujo cabeçalho contenha
// "quantidade" é pareada com a coluna imediatamente anterior (a data daquela programação).
// Isso cobre tanto o formato simples (codigo_componente, data, quantidade) quanto o formato
// largo da planilha (Programação 1, Quantidade, Programação 2, Quantidade 2, ...).
function detectarParesDataQuantidade(array $cabecalhoNormalizado): array
{
    $pares = [];
    foreach ($cabecalhoNormalizado as $indice => $nome) {
        if ($indice > 0 && str_contains($nome, 'quantidade')) {
            $pares[] = [$indice - 1, $indice];
        }
    }
    return $pares;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo_csv'])) {
    exigirComprador();
    $arquivo = $_FILES['arquivo_csv']['tmp_name'];
    $modo = $_POST['modo'] ?? 'adicionar';

    if ($_FILES['arquivo_csv']['error'] !== UPLOAD_ERR_OK) {
        $mensagens[] = "❌ Erro no upload do arquivo.";
    } else {
        $handle = fopen($arquivo, 'r');

        if ($handle === false) {
            $mensagens[] = "❌ Não foi possível abrir o arquivo.";
        } else {
            $primeiraLinha = fgets($handle);
            rewind($handle);
            $separador = (substr_count($primeiraLinha, ';') > substr_count($primeiraLinha, ',')) ? ';' : ',';

            $cabecalhoOriginal = fgetcsv($handle, 0, $separador, '"', '\\');
            if ($cabecalhoOriginal === false) {
                $mensagens[] = "❌ Arquivo vazio ou inválido.";
            } else {
                // O Excel costuma gravar um BOM (marcador invisível) na primeira célula do CSV.
                if (isset($cabecalhoOriginal[0])) {
                    $cabecalhoOriginal[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cabecalhoOriginal[0]);
                }

                $cabecalho = array_map('normalizarTexto', $cabecalhoOriginal);

                $indiceComponente = localizarColunaComponente($cabecalho);
                $paresDataQuantidade = detectarParesDataQuantidade($cabecalho);
                $indiceProcesso = array_search('processo', $cabecalho, true);
                if ($indiceProcesso === false) {
                    $indiceProcesso = null;
                }

                if ($indiceComponente === null) {
                    $mensagens[] = "❌ Não encontrei a coluna do componente. Use 'componente' ou 'codigo_componente' no cabeçalho.";
                } elseif (empty($paresDataQuantidade)) {
                    $mensagens[] = "❌ Não encontrei nenhuma coluna de quantidade (ex.: 'quantidade', 'quantidade 2'...).";
                } else {
                if ($modo === 'substituir') {
                    mysqli_query($conn, "TRUNCATE TABLE programacao");
                    $mensagens[] = "🗑️ Tabela 'programacao' esvaziada antes da importação.";
                }

                $stmtInsert = mysqli_prepare($conn, "INSERT INTO programacao (codigo_componente, processo, data, quantidade) VALUES (?, ?, ?, ?)");
                $stmtVerifica = mysqli_prepare($conn, "SELECT id FROM programacao WHERE TRIM(codigo_componente) = ? AND data = ? LIMIT 1");
                $stmtUpdate = mysqli_prepare($conn, "UPDATE programacao SET quantidade = ? WHERE id = ?");

                mysqli_autocommit($conn, false);

                $linhaNum = 1;
                while (($linha = fgetcsv($handle, 0, $separador, '"', '\\')) !== false) {
                    $linhaNum++;

                    if (count(array_filter($linha, fn($v) => trim((string) $v) !== '')) === 0) {
                        continue;
                    }

                    if (count($linha) !== count($cabecalho)) {
                        $erros++;
                        $mensagens[] = "⚠️ Linha $linhaNum ignorada (número de colunas não confere).";
                        continue;
                    }

                    $codigoComponente = trim($linha[$indiceComponente] ?? '');
                    if ($codigoComponente === '') {
                        $erros++;
                        $mensagens[] = "⚠️ Linha $linhaNum ignorada: componente vazio.";
                        continue;
                    }
                    $processoLinha = $indiceProcesso !== null ? trim($linha[$indiceProcesso] ?? '') : '';
                    $processoLinha = $processoLinha !== '' ? $processoLinha : null;

                    // Uma linha da planilha pode gerar várias entradas de programação
                    // (Programação 1, Programação 2, Programação 3...).
                    foreach ($paresDataQuantidade as [$indiceData, $indiceQtd]) {
                        $dataBruta = trim($linha[$indiceData] ?? '');
                        $quantidadeBruta = trim($linha[$indiceQtd] ?? '');

                        // Par vazio (ex.: componente não tem "Programação 3") — pula sem contar erro.
                        if ($dataBruta === '' && $quantidadeBruta === '') {
                            continue;
                        }

                        $data = parseDataProgramacao($dataBruta);
                        if ($data === null) {
                            $erros++;
                            $mensagens[] = "⚠️ Linha $linhaNum, coluna " . ($indiceData + 1) . ": data '$dataBruta' inválida (use DD/MM/AAAA ou AAAA-MM-DD).";
                            continue;
                        }

                        $quantidade = parseQuantidade($quantidadeBruta);
                        if ($quantidade === null) {
                            $erros++;
                            $mensagens[] = "⚠️ Linha $linhaNum, coluna " . ($indiceQtd + 1) . ": quantidade '$quantidadeBruta' inválida.";
                            continue;
                        }

                        $idExistente = null;
                        if ($modo === 'sem_duplicar' || $modo === 'atualizar') {
                            mysqli_stmt_bind_param($stmtVerifica, "ss", $codigoComponente, $data);
                            mysqli_stmt_execute($stmtVerifica);
                            $resVerifica = mysqli_stmt_get_result($stmtVerifica);
                            $linhaExistente = mysqli_fetch_assoc($resVerifica);
                            $idExistente = $linhaExistente['id'] ?? null;
                        }

                        if ($modo === 'sem_duplicar' && $idExistente !== null) {
                            $ignorados++;
                            continue;
                        }

                        if ($modo === 'atualizar' && $idExistente !== null) {
                            mysqli_stmt_bind_param($stmtUpdate, "di", $quantidade, $idExistente);
                            if (mysqli_stmt_execute($stmtUpdate)) {
                                $atualizados++;
                            } else {
                                $erros++;
                                $mensagens[] = "⚠️ Erro ao atualizar linha $linhaNum: " . mysqli_stmt_error($stmtUpdate);
                            }
                            continue;
                        }

                        mysqli_stmt_bind_param($stmtInsert, "sssd", $codigoComponente, $processoLinha, $data, $quantidade);
                        if (mysqli_stmt_execute($stmtInsert)) {
                            $importados++;
                        } else {
                            $erros++;
                            $mensagens[] = "⚠️ Erro na linha $linhaNum: " . mysqli_stmt_error($stmtInsert);
                        }
                    }
                }

                mysqli_commit($conn);
                mysqli_autocommit($conn, true);

                mysqli_stmt_close($stmtInsert);
                mysqli_stmt_close($stmtVerifica);
                mysqli_stmt_close($stmtUpdate);

                $resumo = "✅ Importação concluída: $importados inserida(s)";
                if ($atualizados > 0) $resumo .= ", $atualizados atualizada(s)";
                if ($ignorados > 0) $resumo .= ", $ignorados ignorada(s) (já existiam)";
                $resumo .= ", $erros erro(s).";
                $mensagens[] = $resumo;
                }
            }
            fclose($handle);
        }
    }
}

// Alternar o status "atendido" de uma programação. Ao marcar como atendido, a
// quantidade é somada automaticamente ao estoque físico (nova linha em "estoque",
// vinculada via origem_programacao_id). Ao reabrir, a mesma linha de estoque é
// removida — desfaz a entrada sem deixar resíduo, mesmo que o estoque tenha
// mudado depois por outros motivos.
//
// Versão AJAX: mesma lógica de negócio acima, mas responde em JSON pra
// atualizar o botão e as células editáveis (data/quantidade) sem recarregar
// a página.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'ajax_alternar_atendido') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!ehComprador()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'erro' => 'Você está como Visualizador e não pode editar.']);
        exit;
    }

    $idAlternar = (int) ($_POST['id'] ?? 0);
    if ($idAlternar <= 0) {
        echo json_encode(['ok' => false, 'erro' => 'Requisição inválida.']);
        exit;
    }

    $stmtBuscaItem = mysqli_prepare($conn, "SELECT codigo_componente, quantidade, atendido, data FROM programacao WHERE id = ?");
    mysqli_stmt_bind_param($stmtBuscaItem, 'i', $idAlternar);
    mysqli_stmt_execute($stmtBuscaItem);
    $itemProg = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtBuscaItem));
    mysqli_stmt_close($stmtBuscaItem);

    if (!$itemProg) {
        echo json_encode(['ok' => false, 'erro' => 'Registro não encontrado.']);
        exit;
    }

    $jaAtendido = (int) $itemProg['atendido'] === 1;

    mysqli_begin_transaction($conn);
    try {
        if ($jaAtendido) {
            // Reabrir: remove a linha de estoque que essa programação gerou
            $stmtDelEstoque = mysqli_prepare($conn, "DELETE FROM estoque WHERE origem_programacao_id = ?");
            mysqli_stmt_bind_param($stmtDelEstoque, 'i', $idAlternar);
            mysqli_stmt_execute($stmtDelEstoque);
            mysqli_stmt_close($stmtDelEstoque);

            $stmtProgOff = mysqli_prepare($conn, "UPDATE programacao SET atendido = 0 WHERE id = ?");
            mysqli_stmt_bind_param($stmtProgOff, 'i', $idAlternar);
            mysqli_stmt_execute($stmtProgOff);
            mysqli_stmt_close($stmtProgOff);
        } else {
            // Marcar atendido: soma a quantidade recebida no estoque físico
            $codigoComponente = trim((string) $itemProg['codigo_componente']);
            $quantidadeRecebida = (float) $itemProg['quantidade'];

            $stmtDesc = mysqli_prepare($conn, "
                SELECT MAX(COALESCE(NULLIF(TRIM(descricao), ''), '')) AS descricao
                FROM bomnova
                WHERE TRIM(codigo_componente) = ?
            ");
            mysqli_stmt_bind_param($stmtDesc, 's', $codigoComponente);
            mysqli_stmt_execute($stmtDesc);
            $descricaoComponente = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtDesc))['descricao'] ?? '';
            mysqli_stmt_close($stmtDesc);

            $plantaRecebimento = 'Recebido';
            $stmtInsEstoque = mysqli_prepare($conn, "INSERT INTO estoque (codigo_componente, descricao, estoque, planta, origem_programacao_id) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmtInsEstoque, 'ssdsi', $codigoComponente, $descricaoComponente, $quantidadeRecebida, $plantaRecebimento, $idAlternar);
            mysqli_stmt_execute($stmtInsEstoque);
            mysqli_stmt_close($stmtInsEstoque);

            $stmtProgOn = mysqli_prepare($conn, "UPDATE programacao SET atendido = 1 WHERE id = ?");
            mysqli_stmt_bind_param($stmtProgOn, 'i', $idAlternar);
            mysqli_stmt_execute($stmtProgOn);
            mysqli_stmt_close($stmtProgOn);
        }
        mysqli_commit($conn);
    } catch (Throwable $erroToggle) {
        mysqli_rollback($conn);
        error_log('Erro ao alternar atendido na programação (ajax): ' . $erroToggle->getMessage());
        echo json_encode(['ok' => false, 'erro' => 'Erro ao atualizar. Tente novamente.']);
        exit;
    }

    $novoAtendido = !$jaAtendido;
    echo json_encode([
        'ok' => true,
        'atendido' => $novoAtendido,
        'texto' => $novoAtendido ? 'Atendido' : 'Pendente',
        'classe' => $novoAtendido ? 'is-atendido' : 'is-pendente',
        'title' => $novoAtendido
            ? 'Clique para reabrir (remove a entrada do estoque)'
            : 'Clique para marcar como atendido (soma no estoque)',
        'dataFormatada' => $itemProg['data'] ? (new DateTimeImmutable($itemProg['data']))->format('d/m/Y') : '',
        'quantidadeFormatada' => number_format((float) $itemProg['quantidade'], 2, ',', ''),
    ]);
    exit;
}

// Exclui uma entrada de programação específica. Se ela já estava "Atendido"
// (já tinha gerado uma linha em estoque), remove primeiro essa linha de
// estoque vinculada — mesma limpeza que "reabrir" já faz — pra não sobrar
// estoque órfão sem origem depois que a programação some.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_programacao') {
    exigirComprador();
    $idExcluir = (int) ($_POST['id'] ?? 0);
    if ($idExcluir > 0) {
        $stmtDelEstoqueExcluir = mysqli_prepare($conn, "DELETE FROM estoque WHERE origem_programacao_id = ?");
        mysqli_stmt_bind_param($stmtDelEstoqueExcluir, 'i', $idExcluir);
        mysqli_stmt_execute($stmtDelEstoqueExcluir);
        mysqli_stmt_close($stmtDelEstoqueExcluir);

        $stmtExcluirProg = mysqli_prepare($conn, "DELETE FROM programacao WHERE id = ?");
        mysqli_stmt_bind_param($stmtExcluirProg, 'i', $idExcluir);
        mysqli_stmt_execute($stmtExcluirProg);
        mysqli_stmt_close($stmtExcluirProg);
    }

    header('Location: programacao.php?' . http_build_query([
        'pagina'   => $_POST['pagina_atual'] ?? 1,
        'busca'    => $_POST['busca_atual'] ?? '',
        'filtro'   => $_POST['filtro_atual'] ?? '',
        'excluido' => 1,
    ]));
    exit;
}

// Mantido como fallback caso o JS não carregue (reload completo da página).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'alternar_atendido') {
    exigirComprador();
    $idAlternar = (int) ($_POST['id'] ?? 0);

    if ($idAlternar > 0) {
        $stmtBuscaItem = mysqli_prepare($conn, "SELECT codigo_componente, quantidade, atendido FROM programacao WHERE id = ?");
        mysqli_stmt_bind_param($stmtBuscaItem, 'i', $idAlternar);
        mysqli_stmt_execute($stmtBuscaItem);
        $itemProg = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtBuscaItem));
        mysqli_stmt_close($stmtBuscaItem);

        if ($itemProg) {
            $jaAtendido = (int) $itemProg['atendido'] === 1;

            mysqli_begin_transaction($conn);
            try {
                if ($jaAtendido) {
                    // Reabrir: remove a linha de estoque que essa programação gerou
                    $stmtDelEstoque = mysqli_prepare($conn, "DELETE FROM estoque WHERE origem_programacao_id = ?");
                    mysqli_stmt_bind_param($stmtDelEstoque, 'i', $idAlternar);
                    mysqli_stmt_execute($stmtDelEstoque);
                    mysqli_stmt_close($stmtDelEstoque);

                    $stmtProgOff = mysqli_prepare($conn, "UPDATE programacao SET atendido = 0 WHERE id = ?");
                    mysqli_stmt_bind_param($stmtProgOff, 'i', $idAlternar);
                    mysqli_stmt_execute($stmtProgOff);
                    mysqli_stmt_close($stmtProgOff);
                } else {
                    // Marcar atendido: soma a quantidade recebida no estoque físico
                    $codigoComponente = trim((string) $itemProg['codigo_componente']);
                    $quantidadeRecebida = (float) $itemProg['quantidade'];

                    $stmtDesc = mysqli_prepare($conn, "
                        SELECT MAX(COALESCE(NULLIF(TRIM(descricao), ''), '')) AS descricao
                        FROM bomnova
                        WHERE TRIM(codigo_componente) = ?
                    ");
                    mysqli_stmt_bind_param($stmtDesc, 's', $codigoComponente);
                    mysqli_stmt_execute($stmtDesc);
                    $descricaoComponente = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtDesc))['descricao'] ?? '';
                    mysqli_stmt_close($stmtDesc);

                    $plantaRecebimento = 'Recebido';
                    $stmtInsEstoque = mysqli_prepare($conn, "INSERT INTO estoque (codigo_componente, descricao, estoque, planta, origem_programacao_id) VALUES (?, ?, ?, ?, ?)");
                    mysqli_stmt_bind_param($stmtInsEstoque, 'ssdsi', $codigoComponente, $descricaoComponente, $quantidadeRecebida, $plantaRecebimento, $idAlternar);
                    mysqli_stmt_execute($stmtInsEstoque);
                    mysqli_stmt_close($stmtInsEstoque);

                    $stmtProgOn = mysqli_prepare($conn, "UPDATE programacao SET atendido = 1 WHERE id = ?");
                    mysqli_stmt_bind_param($stmtProgOn, 'i', $idAlternar);
                    mysqli_stmt_execute($stmtProgOn);
                    mysqli_stmt_close($stmtProgOn);
                }
                mysqli_commit($conn);
            } catch (Throwable $erroToggle) {
                mysqli_rollback($conn);
                error_log('Erro ao alternar atendido na programação: ' . $erroToggle->getMessage());
            }
        }
    }

    header('Location: programacao.php?' . http_build_query([
        'pagina' => $_POST['pagina_atual'] ?? 1,
        'busca'  => $_POST['busca_atual'] ?? '',
        'filtro' => $_POST['filtro_atual'] ?? '',
    ]) . '#linha-' . $idAlternar);
    exit;
}

// Inserção manual de uma nova programação direto pelo site, sem CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'inserir_manual') {
    exigirComprador();
    $componenteManual = trim($_POST['componente_manual'] ?? '');
    $processoManual = trim($_POST['processo_manual'] ?? '');
    $processoManual = $processoManual !== '' ? $processoManual : null;
    $dataManual = parseDataProgramacao(trim($_POST['data_manual'] ?? ''));
    $quantidadeManual = parseQuantidade(trim($_POST['quantidade_manual'] ?? ''));

    $flash = 'erro_dados';
    if ($componenteManual !== '' && $dataManual !== null && $quantidadeManual !== null) {
        $stmtInsManual = mysqli_prepare($conn, "INSERT INTO programacao (codigo_componente, processo, data, quantidade) VALUES (?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmtInsManual, "sssd", $componenteManual, $processoManual, $dataManual, $quantidadeManual);
        mysqli_stmt_execute($stmtInsManual);
        mysqli_stmt_close($stmtInsManual);
        $flash = 'inserido';
    }

    header('Location: programacao.php?' . http_build_query([
        'pagina' => $_POST['pagina_atual'] ?? 1,
        'busca'  => $_POST['busca_atual'] ?? '',
        'filtro' => $_POST['filtro_atual'] ?? '',
        'flash'  => $flash,
    ]));
    exit;
}

// Edição direta de data/quantidade de uma programação já existente, sem CSV.
// Bloqueada se o item já estiver atendido (já virou estoque físico — editar
// aqui deixaria a quantidade da programação e a do estoque dessincronizadas;
// é preciso reabrir primeiro).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'ajax_editar_campo') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!ehComprador()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'erro' => 'Você está como Visualizador e não pode editar.']);
        exit;
    }

    $id = (int) ($_POST['id'] ?? 0);
    $campo = (string) ($_POST['campo'] ?? '');
    $valor = trim((string) ($_POST['valor'] ?? ''));

    if ($id <= 0 || !in_array($campo, ['data', 'quantidade', 'processo'], true)) {
        echo json_encode(['ok' => false, 'erro' => 'Requisição inválida.']);
        exit;
    }

    $stmtCheck = mysqli_prepare($conn, "SELECT atendido FROM programacao WHERE id = ?");
    mysqli_stmt_bind_param($stmtCheck, 'i', $id);
    mysqli_stmt_execute($stmtCheck);
    $item = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtCheck));
    mysqli_stmt_close($stmtCheck);

    if (!$item) {
        echo json_encode(['ok' => false, 'erro' => 'Registro não encontrado.']);
        exit;
    }
    if ((int) $item['atendido'] === 1) {
        echo json_encode(['ok' => false, 'erro' => 'Reabra o item antes de editar.']);
        exit;
    }

    if ($campo === 'processo') {
        $processoEditado = $valor !== '' ? $valor : null;
        $stmt = mysqli_prepare($conn, "UPDATE programacao SET processo = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $processoEditado, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo json_encode(['ok' => true, 'exibido' => $processoEditado ?? '']);
        exit;
    }

    if ($campo === 'data') {
        $dataEditada = parseDataProgramacao($valor);
        if ($dataEditada === null) {
            echo json_encode(['ok' => false, 'erro' => 'Data inválida. Use dd/mm/aaaa.']);
            exit;
        }
        $stmt = mysqli_prepare($conn, "UPDATE programacao SET data = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $dataEditada, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo json_encode(['ok' => true, 'exibido' => (new DateTimeImmutable($dataEditada))->format('d/m/Y')]);
        exit;
    }

    // campo === 'quantidade'
    $quantidadeEditada = parseQuantidade($valor);
    if ($quantidadeEditada === null) {
        echo json_encode(['ok' => false, 'erro' => 'Quantidade inválida.']);
        exit;
    }
    $stmt = mysqli_prepare($conn, "UPDATE programacao SET quantidade = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'di', $quantidadeEditada, $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    echo json_encode(['ok' => true, 'exibido' => number_format($quantidadeEditada, 2, ',', '.')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar_registro') {
    exigirComprador();
    $idEditar = (int) ($_POST['id'] ?? 0);
    $dataEditada = parseDataProgramacao(trim($_POST['data_editada'] ?? ''));
    $quantidadeEditada = parseQuantidade(trim($_POST['quantidade_editada'] ?? ''));

    $flash = 'erro_dados';
    if ($idEditar > 0 && $dataEditada !== null && $quantidadeEditada !== null) {
        $stmtCheckAtendido = mysqli_prepare($conn, "SELECT atendido FROM programacao WHERE id = ?");
        mysqli_stmt_bind_param($stmtCheckAtendido, 'i', $idEditar);
        mysqli_stmt_execute($stmtCheckAtendido);
        $itemCheck = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtCheckAtendido));
        mysqli_stmt_close($stmtCheckAtendido);

        if ($itemCheck && (int) $itemCheck['atendido'] === 1) {
            $flash = 'erro_atendido';
        } elseif ($itemCheck) {
            $stmtEditar = mysqli_prepare($conn, "UPDATE programacao SET data = ?, quantidade = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmtEditar, "sdi", $dataEditada, $quantidadeEditada, $idEditar);
            mysqli_stmt_execute($stmtEditar);
            mysqli_stmt_close($stmtEditar);
            $flash = 'editado';
        }
    }

    header('Location: programacao.php?' . http_build_query([
        'pagina' => $_POST['pagina_atual'] ?? 1,
        'busca'  => $_POST['busca_atual'] ?? '',
        'filtro' => $_POST['filtro_atual'] ?? '',
        'flash'  => $flash,
    ]) . '#linha-' . $idEditar);
    exit;
}

function h(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

$porPagina = 50;
$pagina = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$offset = ($pagina - 1) * $porPagina;

$busca  = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$filtro = isset($_GET['filtro']) ? trim($_GET['filtro']) : ''; // '', 'pendente', 'atendido'
$editando = isset($_GET['editar']) ? (int) $_GET['editar'] : 0;
$flash = isset($_GET['flash']) ? trim($_GET['flash']) : '';

$flashMap = [
    'inserido'      => ['success', '✅ Programação adicionada com sucesso.'],
    'editado'       => ['success', '✅ Registro atualizado.'],
    'erro_dados'    => ['danger', '❌ Componente, data ou quantidade inválidos.'],
    'erro_atendido' => ['warning', '⚠️ Esse item já está atendido — reabra antes de editar.'],
];

// Lista de componentes existentes na BOM, pra sugerir no campo de entrada manual
$componentesDisponiveis = [];
$resComp = mysqli_query($conn, "SELECT DISTINCT TRIM(codigo_componente) AS codigo FROM bomnova WHERE codigo_componente IS NOT NULL AND TRIM(codigo_componente) <> '' ORDER BY codigo");
if ($resComp) {
    while ($linhaComp = mysqli_fetch_assoc($resComp)) {
        $componentesDisponiveis[] = $linhaComp['codigo'];
    }
}

$condicoes = [];
$params = [];
$tipos = '';

if ($busca !== '') {
    $condicoes[] = "p.codigo_componente LIKE ?";
    $buscaLike = "%$busca%";
    $params[] = $buscaLike;
    $tipos .= 's';
}

if ($filtro === 'pendente') {
    $condicoes[] = "(p.atendido = 0 OR p.atendido IS NULL)";
} elseif ($filtro === 'atendido') {
    $condicoes[] = "p.atendido = 1";
}

$where = $condicoes ? ('WHERE ' . implode(' AND ', $condicoes)) : '';

$sqlTotal = "SELECT COUNT(*) AS total FROM programacao p $where";
if (!empty($params)) {
    $stmtTotal = mysqli_prepare($conn, $sqlTotal);
    mysqli_stmt_bind_param($stmtTotal, $tipos, ...$params);
    mysqli_stmt_execute($stmtTotal);
    $resultTotal = mysqli_stmt_get_result($stmtTotal);
} else {
    $resultTotal = mysqli_query($conn, $sqlTotal);
}
$total = mysqli_fetch_assoc($resultTotal)['total'];
$totalPaginas = max(1, ceil($total / $porPagina));

$sqlSoma = "SELECT SUM(COALESCE(CAST(p.quantidade AS DECIMAL(18,4)), 0)) AS soma FROM programacao p $where";
if (!empty($params)) {
    $stmtSoma = mysqli_prepare($conn, $sqlSoma);
    mysqli_stmt_bind_param($stmtSoma, $tipos, ...$params);
    mysqli_stmt_execute($stmtSoma);
    $resultSoma = mysqli_stmt_get_result($stmtSoma);
} else {
    $resultSoma = mysqli_query($conn, $sqlSoma);
}
$somaProgramacao = (float) (mysqli_fetch_assoc($resultSoma)['soma'] ?? 0);

// Exportação CSV: traz TODOS os registros filtrados
if (($_GET['exportar'] ?? '') === 'csv') {
    $sqlExport = "SELECT p.codigo_componente, p.processo, p.data, p.quantidade, p.atendido FROM programacao p $where ORDER BY p.data, p.codigo_componente";
    if (!empty($params)) {
        $stmtExport = mysqli_prepare($conn, $sqlExport);
        mysqli_stmt_bind_param($stmtExport, $tipos, ...$params);
        mysqli_stmt_execute($stmtExport);
        $resultExport = mysqli_stmt_get_result($stmtExport);
    } else {
        $resultExport = mysqli_query($conn, $sqlExport);
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="programacao-' . date('Y-m-d-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $saida = fopen('php://output', 'w');
    fputcsv($saida, ['Componente', 'Processo', 'Data', 'Quantidade', 'Atendido'], ';', '"', '');
    while ($linha = mysqli_fetch_assoc($resultExport)) {
        $data = $linha['data'] ? (new DateTimeImmutable($linha['data']))->format('d/m/Y') : '';

        $quantidadeExportada = number_format(
    (float) $linha['quantidade'],
    0,
    ',',
    ''
);

fputcsv(
    $saida,
    [$linha['codigo_componente'], $linha['processo'] ?? '', $data, $quantidadeExportada, ((int) ($linha['atendido'] ?? 0) === 1) ? 'Sim' : 'Não'],
    ';',
    '"',
    ''
);
    }
    fclose($saida);
    exit;
}

$sql = "SELECT p.id, p.codigo_componente, p.processo, p.data, p.quantidade, p.atendido,
               bg.descricao, bg.fornecedores
        FROM programacao p
        LEFT JOIN (
            SELECT TRIM(codigo_componente) AS codigo_componente,
                   MAX(COALESCE(NULLIF(TRIM(descricao), ''), '')) AS descricao,
                   GROUP_CONCAT(DISTINCT NULLIF(TRIM(fornecedor), '') ORDER BY TRIM(fornecedor) SEPARATOR ', ') AS fornecedores
            FROM bomnova
            GROUP BY TRIM(codigo_componente)
        ) bg ON bg.codigo_componente = TRIM(p.codigo_componente)
        $where
        ORDER BY p.data, p.codigo_componente
        LIMIT ? OFFSET ?";

$stmt = mysqli_prepare($conn, $sql);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $tipos . 'ii', ...array_merge($params, [$porPagina, $offset]));
} else {
    mysqli_stmt_bind_param($stmt, 'ii', $porPagina, $offset);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$rows = [];
while ($row = mysqli_fetch_assoc($result)) {
    $rows[] = $row;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>📅 Programação</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; padding: 20px; }
        .card { border-radius: 15px; box-shadow: 0 2px 20px rgba(0,0,0,0.08); }
        .bg-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important; }
        .table th { background: #f8f9fa; white-space: nowrap; }
        .table td { white-space: nowrap; vertical-align: middle; }
        .form-check { padding: 10px; border: 1px solid #e0e0e0; border-radius: 8px; margin-bottom: 8px; }
        .form-check:hover { background: #f8f9fa; }
        summary { cursor: pointer; font-weight: 700; color: #405164; }

        .situacao-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border: none;
            border-radius: 999px;
            font-size: .74rem;
            font-weight: 700;
            line-height: 1.2;
            cursor: pointer;
            white-space: nowrap;
            transition: filter .15s ease, transform .05s ease;
        }
        .situacao-toggle:hover { filter: brightness(0.94); }
        .situacao-toggle:active { transform: scale(0.97); }
        .situacao-toggle .dot { width: 6px; height: 6px; border-radius: 50%; flex: 0 0 auto; }
        .situacao-toggle.is-pendente { background: #fff3cd; color: #a96600; }
        .situacao-toggle.is-pendente .dot { background: #d88b0b; }
        .situacao-toggle.is-atendido { background: #eaf8f0; color: #247a4d; }
        .situacao-toggle.is-atendido .dot { background: #247a4d; }

        .text-truncate-cell {
            display: inline-block;
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            vertical-align: middle;
        }

        .btn-remover-linha {
            border: none;
            background: none;
            color: #c53535;
            font-size: 1.05rem;
            cursor: pointer;
            line-height: 1;
        }
        .btn-remover-linha:hover { color: #a12727; }
    </style>
</head>
<body>
    <div class="container" style="max-width: 1180px;">
        <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Navegação do sistema">
                <a class="btn btn-outline-secondary btn-sm" href="index.php">🏠 Dashboard</a>
            <a class="btn btn-outline-secondary btn-sm" href="estoque.php">Estoque</a>
            <a class="btn btn-outline-secondary btn-sm" href="edi.php">EDI</a>
            <a class="btn btn-outline-secondary btn-sm" href="bomnova.php">BOM</a>
            <a class="btn btn-outline-secondary btn-sm" href="programacao.php">Programação</a>
            <a class="btn btn-outline-secondary btn-sm" href="parametros_compra.php">Parâmetros</a>
            <a class="btn btn-outline-secondary btn-sm" href="evolucao_geral.php">Evolução geral</a>
            <a class="btn btn-outline-secondary btn-sm" href="planejamento_compras.php">Planejamento de compras</a>
            <a class="btn btn-outline-secondary btn-sm" href="pedido_compra.php">📄 Pedido de Compra</a>
        </nav>
        <div class="card bg-primary text-white p-4 mb-4">
            <h1>📅 Programação de Entradas</h1>
            <p class="mb-0">
                <?php echo number_format($total, 0, ',', '.'); ?> registro(s) na base
                • soma das quantidades: <?php echo number_format($somaProgramacao, 2, ',', '.'); ?>
            </p>
        </div>

        <div class="card p-3 mb-4">
            <details <?php echo !empty($mensagens) ? 'open' : ''; ?>>
                <summary>📥 Importar novo arquivo CSV</summary>
                <div class="mt-3">
                    <?php if (!empty($mensagens)): ?>
                    <div class="mb-3">
                        <?php foreach ($mensagens as $msg): ?>
                            <div><?php echo htmlspecialchars($msg); ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label">Arquivo CSV</label>
                            <input type="file" name="arquivo_csv" accept=".csv" class="form-control" required>
                        </div>

                        <label class="form-label"><strong>O que fazer com os dados?</strong></label>

                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="modo" id="modo_adicionar" value="adicionar" checked>
                            <label class="form-check-label" for="modo_adicionar">
                                <strong>Adicionar</strong> — insere as linhas do arquivo, mesmo se já existirem (pode duplicar)
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="modo" id="modo_sem_duplicar" value="sem_duplicar">
                            <label class="form-check-label" for="modo_sem_duplicar">
                                <strong>Adicionar sem duplicar</strong> — ignora linhas cujo Componente+Data já existe
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="modo" id="modo_atualizar" value="atualizar">
                            <label class="form-check-label" for="modo_atualizar">
                                <strong>Adicionar e atualizar</strong> — se já existir (mesmo Componente+Data), atualiza a quantidade; senão insere novo
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="modo" id="modo_substituir" value="substituir">
                            <label class="form-check-label" for="modo_substituir">
                                <strong>Substituir tudo</strong> — apaga toda a programação atual e importa somente o que está no arquivo
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary mt-2">Importar</button>
                    </form>

                    <hr>
                    <small class="text-muted">
                        <strong>Formato simples</strong> (uma programação por linha):<br>
                        <code>codigo_componente, processo, data, quantidade</code><br>
                        A coluna <code>processo</code> é opcional.<br><br>
                        <strong>Formato da planilha</strong> (várias programações na mesma linha, como no Excel):<br>
                        <code>Componente, ..., Programação 1, Quantidade, Programação 2, Quantidade 2, Programação 3, Quantidade...</code><br>
                        Qualquer coluna com "quantidade" no nome é pareada automaticamente com a coluna de data logo antes dela. Deixe em branco as programações que não existirem.<br><br>
                        Data em DD/MM/AAAA ou AAAA-MM-DD. Separador: vírgula ou ponto e vírgula (detectado automaticamente).
                    </small>
                </div>
            </details>
        </div>

        <?php if ($flash !== '' && isset($flashMap[$flash])): ?>
            <div class="alert alert-<?php echo $flashMap[$flash][0]; ?> py-2"><?php echo $flashMap[$flash][1]; ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['excluido'])): ?>
            <div class="alert alert-success py-2">✅ Programação excluída.</div>
        <?php endif; ?>

        <datalist id="lista_componentes">
            <?php foreach ($componentesDisponiveis as $c): ?>
                <option value="<?php echo h($c); ?>">
            <?php endforeach; ?>
        </datalist>

        <div class="card p-3 mb-4">
            <h2 class="h6 mb-3">➕ Nova programação (entrada manual)</h2>
            <form method="POST" class="row g-2 align-items-end">
                <input type="hidden" name="acao" value="inserir_manual">
                <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                <input type="hidden" name="busca_atual" value="<?php echo h($busca); ?>">
                <input type="hidden" name="filtro_atual" value="<?php echo h($filtro); ?>">
                <div class="col-auto">
                    <label class="form-label small mb-1">Componente</label>
                    <input type="text" name="componente_manual" list="lista_componentes" class="form-control form-control-sm" placeholder="Ex.: 12000586" required>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1">Processo</label>
                    <input type="text" name="processo_manual" class="form-control form-control-sm" placeholder="Opcional">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1">Data</label>
                    <input type="text" name="data_manual" class="form-control form-control-sm" placeholder="dd/mm/aaaa" required>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1">Quantidade</label>
                    <input type="text" name="quantidade_manual" class="form-control form-control-sm text-end" placeholder="Ex.: 1500" required>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">Adicionar</button>
                </div>
            </form>
        </div>

        <div class="card p-3 mb-4">
            <form method="GET" class="row g-2 align-items-center">
                <input type="hidden" name="filtro" value="<?php echo h($filtro); ?>">
                <div class="col-auto flex-grow-1">
                    <input type="text" name="busca" class="form-control" placeholder="Buscar por componente..." value="<?php echo h($busca); ?>">
                </div>
                <div class="col-auto">
                    <div class="btn-group" role="group">
                        <a href="?busca=<?php echo urlencode($busca); ?>&filtro=" class="btn btn-outline-secondary btn-sm <?php echo $filtro === '' ? 'active' : ''; ?>">Todos</a>
                        <a href="?busca=<?php echo urlencode($busca); ?>&filtro=pendente" class="btn btn-outline-secondary btn-sm <?php echo $filtro === 'pendente' ? 'active' : ''; ?>">Pendentes</a>
                        <a href="?busca=<?php echo urlencode($busca); ?>&filtro=atendido" class="btn btn-outline-secondary btn-sm <?php echo $filtro === 'atendido' ? 'active' : ''; ?>">Atendidos</a>
                    </div>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">Buscar</button>
                    <a href="programacao.php" class="btn btn-outline-secondary">Limpar</a>
                    <a href="?busca=<?php echo urlencode($busca); ?>&filtro=<?php echo urlencode($filtro); ?>&exportar=csv" class="btn btn-outline-primary">Exportar CSV</a>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-body table-responsive">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Situação</th>
                            <th>Processo</th>
                            <th>Componente</th>
                            <th>Descrição</th>
                            <th>Fornecedor</th>
                            <th>Data</th>
                            <th class="text-end">Quantidade</th>
                            <th title="Excluir">Excluir</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="8" class="text-center text-muted">Nenhum registro encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $estaAtendido = (int) ($row['atendido'] ?? 0) === 1;
                                $idLinha = (int) $row['id'];
                                $emEdicao = ($editando === $idLinha);
                                $linkVoltar = '?pagina=' . $pagina . '&busca=' . urlencode($busca) . '&filtro=' . urlencode($filtro);
                                ?>
                                <tr id="linha-<?php echo $idLinha; ?>">
                                    <td>
                                        <form method="POST" class="m-0">
                                            <input type="hidden" name="acao" value="alternar_atendido">
                                            <input type="hidden" name="id" value="<?php echo $idLinha; ?>">
                                            <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                                            <input type="hidden" name="busca_atual" value="<?php echo h($busca); ?>">
                                            <input type="hidden" name="filtro_atual" value="<?php echo h($filtro); ?>">
                                            <button type="submit"
                                                    class="situacao-toggle <?php echo $estaAtendido ? 'is-atendido' : 'is-pendente'; ?>"
                                                    title="<?php echo $estaAtendido ? 'Clique para reabrir (remove a entrada do estoque)' : 'Clique para marcar como atendido (soma no estoque)'; ?>">
                                                <span class="dot"></span>
                                                <?php echo $estaAtendido ? 'Atendido' : 'Pendente'; ?>
                                            </button>
                                        </form>
                                    </td>
                                    <?php $podeEditarLinha = !$estaAtendido; ?>
                                    <td class="<?php echo $podeEditarLinha ? 'celula-editavel' : ''; ?>"
                                        <?php if ($podeEditarLinha): ?>
                                        data-id="<?php echo $idLinha; ?>" data-campo="processo"
                                        data-valor-bruto="<?php echo h($row['processo'] ?? ''); ?>"
                                        title="Duplo clique para editar"
                                        <?php endif; ?>
                                    ><?php echo h($row['processo'] ?? ''); ?></td>
                                    <td><strong><?php echo h($row['codigo_componente'] ?? ''); ?></strong></td>
                                    <td title="<?php echo h($row['descricao'] ?? ''); ?>"><span class="text-truncate-cell"><?php echo h($row['descricao'] ?? ''); ?></span></td>
                                    <td title="<?php echo h($row['fornecedores'] ?? ''); ?>"><span class="text-truncate-cell"><?php echo h($row['fornecedores'] ?? ''); ?></span></td>

                                    <td class="<?php echo $podeEditarLinha ? 'celula-editavel' : ''; ?>"
                                        <?php if ($podeEditarLinha): ?>
                                        data-id="<?php echo $idLinha; ?>" data-campo="data"
                                        data-valor-bruto="<?php echo h(formatarDataBrProgramacao($row['data'] ?? null)); ?>"
                                        title="Duplo clique para editar"
                                        <?php endif; ?>
                                    ><?php echo $row['data'] ? h((new DateTimeImmutable($row['data']))->format('d/m/Y')) : ''; ?></td>
                                    <td class="text-end <?php echo $podeEditarLinha ? 'celula-editavel' : ''; ?>"
                                        <?php if ($podeEditarLinha): ?>
                                        data-id="<?php echo $idLinha; ?>" data-campo="quantidade"
                                        data-valor-bruto="<?php echo h(number_format((float) $row['quantidade'], 2, ',', '')); ?>"
                                        title="Duplo clique para editar"
                                        <?php endif; ?>
                                    ><?php echo number_format((float) $row['quantidade'], 2, ',', '.'); ?></td>
                                    <td>
                                        <form method="POST" class="m-0" onsubmit="return confirm('Excluir esta programação? Essa ação não pode ser desfeita.');">
                                            <input type="hidden" name="acao" value="excluir_programacao">
                                            <input type="hidden" name="id" value="<?php echo $idLinha; ?>">
                                            <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                                            <input type="hidden" name="busca_atual" value="<?php echo h($busca); ?>">
                                            <input type="hidden" name="filtro_atual" value="<?php echo h($filtro); ?>">
                                            <button type="submit" class="btn-remover-linha" title="Excluir">✕</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-3">
            <div>
                <?php if ($pagina > 1): ?>
                    <a href="?pagina=<?php echo $pagina - 1; ?>&busca=<?php echo urlencode($busca); ?>&filtro=<?php echo urlencode($filtro); ?>" class="btn btn-outline-primary btn-sm">← Anterior</a>
                <?php endif; ?>
            </div>
            <div class="text-muted">Página <?php echo $pagina; ?> de <?php echo $totalPaginas; ?></div>
            <div>
                <?php if ($pagina < $totalPaginas): ?>
                    <a href="?pagina=<?php echo $pagina + 1; ?>&busca=<?php echo urlencode($busca); ?>&filtro=<?php echo urlencode($filtro); ?>" class="btn btn-outline-primary btn-sm">Próxima →</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="text-center mt-4">
            <a href="index.php" class="btn btn-outline-secondary">Voltar ao Dashboard</a>
        </div>
    </div>
    <script>
        window.INLINE_EDIT_ENDPOINT = 'programacao.php';
        window.INLINE_EDIT_ACAO = 'ajax_editar_campo';
        window.FILTRO_ATUAL = <?php echo json_encode($filtro); ?>;
    </script>
    <script src="assets/inline-edit.js"></script>
    <script>
        // Alterna "Pendente"/"Atendido" via AJAX, sem recarregar a página:
        // atualiza o botão e a editabilidade das células de data/quantidade.
        // Se a linha deixar de bater com o filtro atual (ex.: filtro=pendente
        // e a linha virou atendida), ela é removida da tabela na hora.
        document.addEventListener('submit', function (evento) {
            const form = evento.target;
            const acaoInput = form.querySelector('input[name="acao"]');
            if (!acaoInput || acaoInput.value !== 'alternar_atendido') return;

            evento.preventDefault();
            const dados = new URLSearchParams(new FormData(form));
            dados.set('acao', 'ajax_alternar_atendido');

            fetch('programacao.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: dados.toString()
            })
                .then(r => r.json())
                .then(json => {
                    if (!json.ok) { alert(json.erro || 'Não foi possível atualizar.'); return; }

                    const linha = form.closest('tr');
                    const filtroAtual = window.FILTRO_ATUAL || '';
                    if ((filtroAtual === 'pendente' && json.atendido) || (filtroAtual === 'atendido' && !json.atendido)) {
                        linha.remove();
                        return;
                    }

                    const botao = form.querySelector('button[type="submit"]');
                    if (botao) {
                        botao.classList.remove('is-atendido', 'is-pendente');
                        botao.classList.add(json.classe);
                        botao.title = json.title;
                        botao.innerHTML = '<span class="dot"></span> ' + json.texto;
                    }

                    const idLinha = form.querySelector('input[name="id"]').value;
                    const celulaProcesso = linha.children[1];
                    const celulaData = linha.children[5];
                    const celulaQtd = linha.children[6];
                    if (celulaProcesso && celulaData && celulaQtd) {
                        if (json.atendido) {
                            celulaProcesso.className = '';
                            celulaProcesso.removeAttribute('data-id');
                            celulaProcesso.removeAttribute('data-campo');
                            celulaProcesso.removeAttribute('data-valor-bruto');
                            celulaProcesso.removeAttribute('title');
                            celulaData.className = '';
                            celulaData.removeAttribute('data-id');
                            celulaData.removeAttribute('data-campo');
                            celulaData.removeAttribute('data-valor-bruto');
                            celulaData.removeAttribute('title');
                            celulaQtd.className = 'text-end';
                            celulaQtd.removeAttribute('data-id');
                            celulaQtd.removeAttribute('data-campo');
                            celulaQtd.removeAttribute('data-valor-bruto');
                            celulaQtd.removeAttribute('title');
                        } else {
                            celulaProcesso.className = 'celula-editavel';
                            celulaProcesso.setAttribute('data-id', idLinha);
                            celulaProcesso.setAttribute('data-campo', 'processo');
                            celulaProcesso.setAttribute('data-valor-bruto', celulaProcesso.textContent.trim());
                            celulaProcesso.setAttribute('title', 'Duplo clique para editar');
                            celulaData.className = 'celula-editavel';
                            celulaData.setAttribute('data-id', idLinha);
                            celulaData.setAttribute('data-campo', 'data');
                            celulaData.setAttribute('data-valor-bruto', json.dataFormatada);
                            celulaData.setAttribute('title', 'Duplo clique para editar');
                            celulaQtd.className = 'text-end celula-editavel';
                            celulaQtd.setAttribute('data-id', idLinha);
                            celulaQtd.setAttribute('data-campo', 'quantidade');
                            celulaQtd.setAttribute('data-valor-bruto', json.quantidadeFormatada);
                            celulaQtd.setAttribute('title', 'Duplo clique para editar');
                        }
                    }
                })
                .catch(() => alert('Erro de conexão ao atualizar. Tente de novo.'));
        });
    </script>
    <script>
        // Exclusão recarrega a página (o registro some da tabela, então não há
        // linha pra manter na tela), mas guarda a posição do scroll antes de
        // enviar e restaura depois do reload, pra não voltar pro topo.
        document.addEventListener('submit', function (evento) {
            const form = evento.target;
            const acaoInput = form.querySelector('input[name="acao"]');
            if (acaoInput && acaoInput.value === 'excluir_programacao') {
                sessionStorage.setItem('programacao_scroll', String(window.scrollY));
            }
        });
        window.addEventListener('DOMContentLoaded', function () {
            const scrollSalvo = sessionStorage.getItem('programacao_scroll');
            if (scrollSalvo !== null) {
                window.scrollTo(0, parseInt(scrollSalvo, 10) || 0);
                sessionStorage.removeItem('programacao_scroll');
            }
        });
    </script>
    <script>
        // Fallback pra garantir o scroll até a linha certa — a âncora (#linha-x)
        // já deveria fazer isso sozinha, mas algumas combinações de navegador/
        // cabeçalho fixo não respeitam isso direito. Isso força o scroll de
        // verdade, centralizando a linha na tela em vez de jogar ela pro topo.
        if (window.location.hash) {
            const alvo = document.querySelector(window.location.hash);
            if (alvo) {
                alvo.scrollIntoView({ block: 'center' });
            }
        }
    </script>
</body>
</html>
