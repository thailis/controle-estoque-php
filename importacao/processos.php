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
// Formato: Y + ano(2) + modal(1) + planta(1) + categoria(1) + sequencial(3)
// Ex.: Y26A1P001 = Y, 2026, Aéreo, planta terminada em 1, Project, sequencial 001.
// O sequencial é um contador ÚNICO GERAL (não reinicia por ano/modal/planta/categoria)
// — pega o maior sequencial já usado em qualquer processo gerado nesse formato
// e soma 1, então nunca colide mesmo se a combinação se repetir.
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
    // Y+AA+letra+dígito+letra+NNN, sem filtrar por ano/modal/planta/categoria —
    // é um contador único pra todos.
    $resultado = mysqli_query($conn, "
        SELECT MAX(CAST(RIGHT(processo, 3) AS UNSIGNED)) AS max_seq
        FROM processos
        WHERE processo REGEXP '^Y[0-9]{2}[A-Z][0-9][A-Z][0-9]{3}$'
    ");
    $maxSeq = (int) (mysqli_fetch_assoc($resultado)['max_seq'] ?? 0);
    $proximoSeq = $maxSeq + 1;

    $codigo = 'Y' . $ano . $modalLetra . $plantaDigito . $categoriaLetra . str_pad((string) $proximoSeq, 3, '0', STR_PAD_LEFT);
    return ['codigo' => $codigo, 'erro' => null];
}

$mensagens = [];
$importados = 0;
$erros = 0;

// ---------- Toggle Aberto / Cancelado ----------
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

            $stmtUpdatePag = mysqli_prepare($conn, "UPDATE pagamento SET status = ? WHERE processo = ?");
            mysqli_stmt_bind_param($stmtUpdatePag, 'ss', $novoStatus, $processoToggle);
            mysqli_stmt_execute($stmtUpdatePag);
            mysqli_stmt_close($stmtUpdatePag);

            $paginaVolta = (int) ($_POST['pagina_atual'] ?? 1);
            $buscaVolta = (string) ($_POST['busca_atual'] ?? '');
            header('Location: processos.php?pagina=' . $paginaVolta . '&busca=' . urlencode($buscaVolta) . '&status_alterado=1');
            exit;
        }
    }
}

// ---------- Toggle Controla Estoque (Sim / Não) ----------
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
            header('Location: processos.php?pagina=' . $paginaVolta . '&busca=' . urlencode($buscaVolta) . '&controla_alterado=1');
            exit;
        }
    }
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
                        $campos = ['processo', 'status', 'solicitacao', 'categoria', 'planta', 'po', 'modal', 'codigo_componente', 'descricao', 'quantidade', 'hscode', 'ncm', 'fornecedor', 'preco', 'total', 'moeda', 'tipo', 'ffw', 'obs', 'controla_estoque'];
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
                        $total = parseNumeroBrProcessos($get('total'));
                        $solicitacao = parseDataProcessos($get('solicitacao'));

                        $controlaEstoqueTexto = mb_strtolower($get('controla_estoque'), 'UTF-8');
                        $controlaEstoque = in_array($controlaEstoqueTexto, ['nao', 'não', 'n', 'no', '0'], true) ? 'nao' : 'sim';

                        $lote[] = [
                            $processo,
                            'aberto',
                            $solicitacao,
                            $get('categoria') ?: null,
                            $get('planta') ?: null,
                            $get('po') ?: null,
                            $get('modal') ?: null,
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
            INSERT INTO processos (processo, status, solicitacao, categoria, planta, po, modal, codigo_componente, descricao, quantidade, hscode, ncm, fornecedor, preco, total, moeda, tipo, ffw, obs, controla_estoque)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $status = 'aberto';
        $solicitacao = parseDataProcessos($solicitacaoTexto);
        $po = trim($_POST['po_manual'] ?? '') ?: null;
        $codigoComponente = trim($_POST['codigo_componente_manual'] ?? '') ?: null;
        $descricao = trim($_POST['descricao_manual'] ?? '') ?: null;
        $quantidade = parseNumeroBrProcessos(trim($_POST['quantidade_manual'] ?? ''));
        $hscode = trim($_POST['hscode_manual'] ?? '') ?: null;
        $ncm = trim($_POST['ncm_manual'] ?? '') ?: null;
        $preco = parseNumeroBrProcessos(trim($_POST['preco_manual'] ?? ''));
        $total = ($quantidade !== null && $preco !== null) ? $quantidade * $preco : null;
        $moeda = trim($_POST['moeda_manual'] ?? '') ?: null;
        $tipo = trim($_POST['tipo_manual'] ?? '') ?: null;
        $ffw = trim($_POST['ffw_manual'] ?? '') ?: null;
        $obs = trim($_POST['obs_manual'] ?? '') ?: null;
        $controlaEstoqueManual = isset($_POST['controla_estoque_manual']) ? 'sim' : 'nao';

        mysqli_stmt_bind_param(
            $stmt, 'sssssssssdsssddsssss',
            $processo, $status, $solicitacao, $categoria, $planta, $po, $modal, $codigoComponente,
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

// ---------- Edição ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar_registro') {
    $idEditar = (int) ($_POST['id_editar'] ?? 0);
    if ($idEditar <= 0) {
        $mensagens[] = '❌ Registro inválido pra edição.';
    } else {
        $stmtEditar = mysqli_prepare($conn, "
            UPDATE processos
            SET solicitacao = ?, categoria = ?, planta = ?, po = ?, modal = ?, codigo_componente = ?,
                descricao = ?, quantidade = ?, hscode = ?, ncm = ?, fornecedor = ?, preco = ?, total = ?,
                moeda = ?, tipo = ?, ffw = ?, obs = ?
            WHERE id = ?
        ");
        $solicitacaoEd = parseDataProcessos(trim($_POST['solicitacao_editado'] ?? ''));
        $categoriaEd = trim($_POST['categoria_editado'] ?? '') ?: null;
        $plantaEd = trim($_POST['planta_editado'] ?? '') ?: null;
        $poEd = trim($_POST['po_editado'] ?? '') ?: null;
        $modalEd = trim($_POST['modal_editado'] ?? '') ?: null;
        $codigoComponenteEd = trim($_POST['codigo_componente_editado'] ?? '') ?: null;
        $descricaoEd = trim($_POST['descricao_editado'] ?? '') ?: null;
        $quantidadeEd = parseNumeroBrProcessos(trim($_POST['quantidade_editado'] ?? ''));
        $hscodeEd = trim($_POST['hscode_editado'] ?? '') ?: null;
        $ncmEd = trim($_POST['ncm_editado'] ?? '') ?: null;
        $fornecedorEd = trim($_POST['fornecedor_editado'] ?? '') ?: null;
        $precoEd = parseNumeroBrProcessos(trim($_POST['preco_editado'] ?? ''));
        $totalEd = ($quantidadeEd !== null && $precoEd !== null) ? $quantidadeEd * $precoEd : null;
        $moedaEd = trim($_POST['moeda_editado'] ?? '') ?: null;
        $tipoEd = trim($_POST['tipo_editado'] ?? '') ?: null;
        $ffwEd = trim($_POST['ffw_editado'] ?? '') ?: null;
        $obsEd = trim($_POST['obs_editado'] ?? '') ?: null;

        mysqli_stmt_bind_param(
            $stmtEditar, 'sssssssdsssddssssi',
            $solicitacaoEd, $categoriaEd, $plantaEd, $poEd, $modalEd, $codigoComponenteEd,
            $descricaoEd, $quantidadeEd, $hscodeEd, $ncmEd, $fornecedorEd, $precoEd, $totalEd,
            $moedaEd, $tipoEd, $ffwEd, $obsEd, $idEditar
        );
        if (mysqli_stmt_execute($stmtEditar)) {
            mysqli_stmt_close($stmtEditar);
            $paginaVolta = (int) ($_POST['pagina_atual'] ?? 1);
            $buscaVolta = (string) ($_POST['busca_atual'] ?? '');
            header('Location: processos.php?pagina=' . $paginaVolta . '&busca=' . urlencode($buscaVolta) . '&editado=1');
            exit;
        } else {
            $mensagens[] = '❌ Erro ao atualizar: ' . mysqli_stmt_error($stmtEditar);
            mysqli_stmt_close($stmtEditar);
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
    $filtroStatusExp = trim($_GET['status'] ?? '');

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
    if ($filtroStatusExp !== '') {
        $condicoesExp[] = "status = ?";
        $paramsExp[] = $filtroStatusExp;
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
    fputcsv($saida, ['processo', 'status', 'solicitacao', 'categoria', 'planta', 'po', 'modal', 'codigo_componente', 'descricao', 'quantidade', 'hscode', 'ncm', 'fornecedor', 'preco', 'total', 'moeda', 'tipo', 'ffw', 'obs'], ';', '"', '');
    while ($linha = mysqli_fetch_assoc($resultExport)) {
        fputcsv($saida, [
            $linha['processo'], $linha['status'], $linha['solicitacao'], $linha['categoria'], $linha['planta'],
            $linha['po'], $linha['modal'], $linha['codigo_componente'], $linha['descricao'],
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
$filtroStatus = trim($_GET['status'] ?? '');
$editandoId = (int) ($_GET['editar'] ?? 0);

$plantasDisponiveis = [];
$res = mysqli_query($conn, "SELECT DISTINCT planta FROM processos WHERE planta IS NOT NULL AND TRIM(planta) <> '' ORDER BY planta");
while ($linha = mysqli_fetch_assoc($res)) { $plantasDisponiveis[] = $linha['planta']; }

$categoriasDisponiveis = [];
$res = mysqli_query($conn, "SELECT DISTINCT categoria FROM processos WHERE categoria IS NOT NULL AND TRIM(categoria) <> '' ORDER BY categoria");
while ($linha = mysqli_fetch_assoc($res)) { $categoriasDisponiveis[] = $linha['categoria']; }

$fornecedoresDisponiveis = [];
$res = mysqli_query($conn, "SELECT DISTINCT fornecedor FROM processos WHERE fornecedor IS NOT NULL AND TRIM(fornecedor) <> '' ORDER BY fornecedor");
while ($linha = mysqli_fetch_assoc($res)) { $fornecedoresDisponiveis[] = $linha['fornecedor']; }

// Lista de status pro filtro — sempre os 3 valores possíveis (aberto,
// cancelado, finalizado), independente de já existirem na base ou não.
$statusDisponiveis = ['aberto', 'cancelado', 'finalizado'];

$condicoes = [];
$params = [];
$tipos = '';
if ($busca !== '') {
    $condicoes[] = "(processo LIKE ? OR codigo_componente LIKE ? OR fornecedor LIKE ? OR descricao LIKE ?)";
    $like = '%' . $busca . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $tipos .= 'ssss';
}
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
if ($filtroStatus !== '') {
    $condicoes[] = "status = ?";
    $params[] = $filtroStatus;
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
        #tabela-processos td, #tabela-processos th { padding: 14px 16px; }
        #tabela-processos td { font-size: .85rem; }
        #tabela-processos .description-cell {
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
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
            </nav>
        </div>
    </header>

    <main class="container-fluid dashboard-container py-4">

        <?php if (isset($_GET['editado'])): ?>
            <div class="alert alert-success">✅ Registro atualizado com sucesso.</div>
        <?php endif; ?>

        <?php if (isset($_GET['status_alterado'])): ?>
            <div class="alert alert-success">✅ Status do processo atualizado.</div>
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
                    <code>processo, solicitacao, categoria, planta, po, modal, codigo_componente, descricao, quantidade, hscode, ncm, fornecedor, preco, total, moeda, tipo, ffw, obs</code><br>
                    Só <code>processo</code> é obrigatório — as demais colunas podem faltar. <strong>Não existe coluna <code>status</code></strong>: todo processo nasce "aberto" automaticamente, e só vira "finalizado" quando o embarque correspondente é confirmado na tela Confirmar Entrega. Separador: vírgula ou ponto e vírgula (detectado automaticamente).
                </p>
            </details>
        </section>

        <section class="filter-panel mb-4">
            <details>
                <summary class="fw-bold" style="cursor:pointer;">➕ Novo processo (cadastro manual)</summary>
                <form method="POST" class="row g-3 mt-3">
                    <input type="hidden" name="acao" value="cadastro_manual">
                    <div class="col-md-2">
                        <label class="form-label">Processo <small class="text-muted">(gerado ao salvar)</small></label>
                        <input type="text" class="form-control" value="Será gerado automaticamente" disabled>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Solicitação *</label>
                        <input type="text" name="solicitacao_manual" class="form-control" placeholder="dd/mm/aaaa" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Categoria *</label>
                        <input type="text" name="categoria_manual" class="form-control" placeholder="tooling / project / other" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Planta *</label>
                        <input type="text" name="planta_manual" class="form-control" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">PO</label>
                        <input type="text" name="po_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Modal *</label>
                        <input type="text" name="modal_manual" class="form-control" placeholder="aereo / sea / road / courrier" required>
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
                            <input type="checkbox" name="controla_estoque_manual" id="controla_estoque_manual" class="form-check-input" checked>
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
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($statusDisponiveis as $st): ?>
                            <option value="<?php echo h($st); ?>" <?php echo $filtroStatus === $st ? 'selected' : ''; ?>><?php echo h(ucfirst($st)); ?></option>
                        <?php endforeach; ?>
                    </select>
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
                    <a href="?exportar=1&busca=<?php echo urlencode($busca); ?>&planta=<?php echo urlencode($filtroPlanta); ?>&componente=<?php echo urlencode($filtroComponente); ?>&categoria=<?php echo urlencode($filtroCategoria); ?>&fornecedor=<?php echo urlencode($filtroFornecedor); ?>&status=<?php echo urlencode($filtroStatus); ?>" class="btn btn-outline-secondary w-100">Exportar CSV</a>
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
                <table class="table mrp-table mb-0" id="tabela-processos" style="min-width: 1900px;">
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
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="21" class="empty-state">Nenhum processo encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <?php
                                    $statusAtualRow = strtolower(trim((string) ($r['status'] ?? '')));
                                    $statusFinalizado = $statusAtualRow === 'finalizado';
                                    $statusCancelado = $statusAtualRow === 'cancelado';
                                    $controlaEstoqueRow = strtolower(trim((string) ($r['controla_estoque'] ?? 'sim'))) === 'sim';
                                    $emEdicao = $editandoId === (int) $r['id'];
                                    $linkVoltar = '?pagina=' . $pagina . '&busca=' . urlencode($busca);
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
                                                <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                                <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                                                <input type="hidden" name="busca_atual" value="<?php echo h($busca); ?>">
                                                <button type="submit" class="status-badge border-0 <?php echo $controlaEstoqueRow ? 'status-ok' : 'status-sem_demanda'; ?>" style="cursor:pointer;" title="Clique pra alternar — 'Não' significa que esse item não entra no Confirmar Entrega (ex.: tooling, amostra)">
                                                    <?php echo $controlaEstoqueRow ? 'Sim' : 'Não'; ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="component-code"><?php echo h($r['processo']); ?></span></td>

                                    <?php if ($emEdicao): ?>
                                        <td colspan="17">
                                            <form method="POST" class="row g-2 align-items-end py-2">
                                                <input type="hidden" name="acao" value="editar_registro">
                                                <input type="hidden" name="id_editar" value="<?php echo (int) $r['id']; ?>">
                                                <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                                                <input type="hidden" name="busca_atual" value="<?php echo h($busca); ?>">
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Solicitação</label>
                                                    <input type="text" name="solicitacao_editado" class="form-control form-control-sm" placeholder="dd/mm/aaaa" value="<?php echo $r['solicitacao'] ? h(dataBr($r['solicitacao'])) : ''; ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Categoria</label>
                                                    <input type="text" name="categoria_editado" class="form-control form-control-sm" value="<?php echo h($r['categoria'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">Planta</label>
                                                    <input type="text" name="planta_editado" class="form-control form-control-sm" value="<?php echo h($r['planta'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">PO</label>
                                                    <input type="text" name="po_editado" class="form-control form-control-sm" value="<?php echo h($r['po'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">Modal</label>
                                                    <input type="text" name="modal_editado" class="form-control form-control-sm" value="<?php echo h($r['modal'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Componente</label>
                                                    <input type="text" name="codigo_componente_editado" class="form-control form-control-sm" value="<?php echo h($r['codigo_componente'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label small mb-0">Descrição</label>
                                                    <input type="text" name="descricao_editado" class="form-control form-control-sm" value="<?php echo h($r['descricao'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">Quantidade</label>
                                                    <input type="text" name="quantidade_editado" id="quantidade_editado" class="form-control form-control-sm" value="<?php echo $r['quantidade'] !== null ? numeroBr($r['quantidade'], 0) : ''; ?>" oninput="calcularTotalProcessoEdicao()">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">HS Code</label>
                                                    <input type="text" name="hscode_editado" class="form-control form-control-sm" value="<?php echo h($r['hscode'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">NCM</label>
                                                    <input type="text" name="ncm_editado" class="form-control form-control-sm" value="<?php echo h($r['ncm'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Fornecedor</label>
                                                    <input type="text" name="fornecedor_editado" class="form-control form-control-sm" value="<?php echo h($r['fornecedor'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">Preço</label>
                                                    <input type="text" name="preco_editado" id="preco_editado" class="form-control form-control-sm" value="<?php echo $r['preco'] !== null ? numeroBr($r['preco']) : ''; ?>" oninput="calcularTotalProcessoEdicao()">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">Total <small class="text-muted">(qtd×preço)</small></label>
                                                    <input type="text" id="total_editado_display" class="form-control form-control-sm" readonly value="<?php echo $r['total'] !== null ? numeroBr($r['total']) : ''; ?>">
                                                    <input type="hidden" name="total_editado" id="total_editado" value="<?php echo $r['total'] !== null ? numeroBr($r['total']) : ''; ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">Moeda</label>
                                                    <input type="text" name="moeda_editado" class="form-control form-control-sm" value="<?php echo h($r['moeda'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">Tipo</label>
                                                    <input type="text" name="tipo_editado" class="form-control form-control-sm" value="<?php echo h($r['tipo'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">FFW</label>
                                                    <input type="text" name="ffw_editado" class="form-control form-control-sm" value="<?php echo h($r['ffw'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Obs</label>
                                                    <input type="text" name="obs_editado" class="form-control form-control-sm" value="<?php echo h($r['obs'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-12 d-flex gap-2 mt-1">
                                                    <button type="submit" class="btn btn-success btn-sm">Salvar</button>
                                                    <a href="<?php echo $linkVoltar; ?>" class="btn btn-outline-secondary btn-sm">Cancelar</a>
                                                </div>
                                            </form>
                                        </td>
                                        <td></td>
                                    <?php else: ?>
                                        <td><?php echo dataBr($r['solicitacao']); ?></td>
                                        <td><?php echo h($r['categoria'] ?: '—'); ?></td>
                                        <td><?php echo h($r['planta'] ?: '—'); ?></td>
                                        <td><?php echo h($r['po'] ?: '—'); ?></td>
                                        <td><?php echo h($r['modal'] ?: '—'); ?></td>
                                        <td><?php echo h($r['codigo_componente'] ?: '—'); ?></td>
                                        <td class="description-cell" title="<?php echo h($r['descricao'] ?? ''); ?>"><?php echo h($r['descricao'] ?: '—'); ?></td>
                                        <td><?php echo numeroBr($r['quantidade'], 0); ?></td>
                                        <td><?php echo h($r['hscode'] ?: '—'); ?></td>
                                        <td><?php echo h($r['ncm'] ?: '—'); ?></td>
                                        <td><?php echo h($r['fornecedor'] ?: '—'); ?></td>
                                        <td><?php echo numeroBr($r['preco']); ?></td>
                                        <td><?php echo numeroBr($r['total']); ?></td>
                                        <td><?php echo h($r['moeda'] ?: '—'); ?></td>
                                        <td><?php echo h($r['tipo'] ?: '—'); ?></td>
                                        <td><?php echo h($r['ffw'] ?: '—'); ?></td>
                                        <td class="description-cell" title="<?php echo h($r['obs'] ?? ''); ?>"><?php echo h($r['obs'] ?: '—'); ?></td>
                                        <td>
                                            <a href="<?php echo $linkVoltar; ?>&editar=<?php echo (int) $r['id']; ?>" class="btn btn-outline-secondary btn-sm">Editar</a>
                                        </td>
                                    <?php endif; ?>
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
        function parseNumeroBrProcJs(texto) {
            if (!texto) return 0;
            texto = String(texto).trim();
            if (texto.includes(',')) {
                texto = texto.replace(/\./g, '').replace(',', '.');
            }
            const n = parseFloat(texto);
            return isNaN(n) ? 0 : n;
        }

        function calcularTotalProcesso() {
            const quantidade = parseNumeroBrProcJs(document.getElementById('quantidade_manual').value);
            const preco = parseNumeroBrProcJs(document.getElementById('preco_manual').value);
            const total = quantidade * preco;
            const textoTotal = total.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('total_manual_display').value = textoTotal;
            document.getElementById('total_manual').value = textoTotal;
        }

        function calcularTotalProcessoEdicao() {
            const qtdEl = document.getElementById('quantidade_editado');
            const precoEl = document.getElementById('preco_editado');
            if (!qtdEl || !precoEl) return;
            const quantidade = parseNumeroBrProcJs(qtdEl.value);
            const preco = parseNumeroBrProcJs(precoEl.value);
            const total = quantidade * preco;
            const textoTotal = total.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.getElementById('total_editado_display').value = textoTotal;
            document.getElementById('total_editado').value = textoTotal;
        }
    </script>
</body>
</html>
