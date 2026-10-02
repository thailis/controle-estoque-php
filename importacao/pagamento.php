<?php
// importacao/pagamento.php
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

function parseDataPagamento(string $valor): ?string
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

function parseNumeroBrPagamento(string $valor): ?float
{
    // Aceita valor com símbolo de moeda e espaços: "R$ 19.300,03", "US$ 1.234,56",
    // "$ 99,90", "€ 10,00" — tira tudo que não for dígito, vírgula, ponto ou sinal.
    $valor = preg_replace('/[^0-9,.\-]/u', '', $valor);
    $valor = trim((string) $valor);
    if ($valor === '' || $valor === '-') {
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

function normalizarTextoPagamento(string $texto): string
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

$mensagens = [];
$importados = 0;
$erros = 0;

// ---------- Toggle único de Status (Aberto <-> Finalizado) ----------
// Não existe mais etapa intermediária ("Parcial"): o botão fecha (ou reabre)
// liquidacao_or e liquidacao_na juntos, num único clique.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'toggle_status_pagamento') {
    $idPagamento = (int) ($_POST['id'] ?? 0);

    $stmtAtual = mysqli_prepare($conn, "SELECT liquidacao_na FROM pagamento WHERE id = ?");
    mysqli_stmt_bind_param($stmtAtual, 'i', $idPagamento);
    mysqli_stmt_execute($stmtAtual);
    $atual = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtAtual));
    mysqli_stmt_close($stmtAtual);

    if (!$atual) {
        $mensagens[] = '❌ Registro de pagamento não encontrado.';
    } else {
        $naAtual = strtolower(trim((string) ($atual['liquidacao_na'] ?? ''))) === 'fechado' ? 'fechado' : 'aberto';
        $novo = $naAtual === 'fechado' ? 'aberto' : 'fechado';

        $stmtUpdate = mysqli_prepare($conn, "UPDATE pagamento SET liquidacao_or = ?, liquidacao_na = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmtUpdate, 'ssi', $novo, $novo, $idPagamento);
        mysqli_stmt_execute($stmtUpdate);
        mysqli_stmt_close($stmtUpdate);
    }
}

// ---------- Importação CSV ----------
// Uma linha do CSV = um pagamento. Só "processo" é obrigatório e precisa já
// existir em Processos — Status, PO, Fornecedor, Moeda e Total vêm sempre do
// processo (igual ao cadastro manual), nunca do CSV. Colunas aceitas (qualquer
// ordem, com ou sem acento/maiúscula; o próprio "Exportar CSV" desta tela serve
// de modelo): processo, cod_twin (Cod. Twin.), data_twin (Data),
// numerario_inicial (Vencimento), valor_inicial (Valor), rb, oa e, opcional,
// liquidacao_na / situacao (aberto | fechado/finalizado).
// Linha idêntica já existente (mesmo processo + Cod. Twin + Vencimento + Valor)
// é pulada, pra reimportar o mesmo arquivo não duplicar.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo_csv_pagamento'])) {
    $arquivoCsv = $_FILES['arquivo_csv_pagamento'];
    if ($arquivoCsv['error'] !== UPLOAD_ERR_OK) {
        $mensagens[] = '❌ Erro no upload do arquivo.';
    } elseif (($handle = fopen($arquivoCsv['tmp_name'], 'r')) === false) {
        $mensagens[] = '❌ Não foi possível abrir o arquivo.';
    } else {
        $primeiraLinha = (string) fgets($handle);
        rewind($handle);
        $separador = substr_count($primeiraLinha, ';') > substr_count($primeiraLinha, ',') ? ';' : ',';
        $cabecalho = fgetcsv($handle, 0, $separador, '"', '\\');
        if ($cabecalho === false) {
            $mensagens[] = '❌ Arquivo vazio ou inválido.';
        } else {
            $cabecalho[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cabecalho[0]);
            // normaliza: minúsculo, sem acento, espaço/hífen -> "_", tira o resto
            $cabecalhoNorm = array_map(function ($c) {
                $n = normalizarTextoPagamento((string) $c);
                $n = preg_replace('/[^a-z0-9_]/', '', $n);
                return trim(preg_replace('/_+/', '_', $n), '_');
            }, $cabecalho);
            $apelidos = [
                'processo' => ['processo'],
                'cod_twin' => ['cod_twin', 'codtwin', 'cod_twin_', 'codigo_twin'],
                'data_twin' => ['data_twin', 'data'],
                'numerario_inicial' => ['numerario_inicial', 'vencimento'],
                'valor_inicial' => ['valor_inicial', 'valor'],
                'rb' => ['rb'],
                'oa' => ['oa', 'oa_link'],
                'liquidacao_na' => ['liquidacao_na', 'situacao', 'status_pagamento'],
            ];
            $idx = [];
            foreach ($apelidos as $campo => $nomes) {
                $idx[$campo] = null;
                foreach ($nomes as $nome) {
                    $pos = array_search($nome, $cabecalhoNorm, true);
                    if ($pos !== false) { $idx[$campo] = $pos; break; }
                }
            }

            if ($idx['processo'] === null) {
                $mensagens[] = "❌ Não encontrei a coluna 'processo' no cabeçalho.";
            } else {
                // "Limpar antes de importar": apaga TODO o histórico de pagamento e
                // substitui pelo conteúdo do CSV. Roda numa transação — se nenhuma
                // linha do arquivo for importada, desfaz a limpeza (não deixa a
                // tabela vazia por causa de um arquivo errado).
                $limparAntes = isset($_POST['limpar_pagamento']);
                if ($limparAntes) {
                    mysqli_begin_transaction($conn);
                    mysqli_query($conn, "DELETE FROM pagamento");
                }
                $stmtProcCsv = mysqli_prepare($conn, "SELECT status, po, fornecedor, moeda, total FROM processos WHERE processo = ? LIMIT 1");
                $stmtDupCsv = mysqli_prepare($conn, "
                    SELECT id FROM pagamento
                    WHERE processo = ? AND COALESCE(cod_twin, '') = ? AND COALESCE(numerario_inicial, '') = ?
                      AND COALESCE(valor_inicial, -999999999) = COALESCE(?, -999999999)
                    LIMIT 1
                ");
                $stmtInsCsv = mysqli_prepare($conn, "
                    INSERT INTO pagamento (processo, status, po, fornecedor, cod_twin, data_twin, moeda, total,
                                           liquidacao_or, numerario_inicial, valor_inicial, liquidacao_na, rb, oa)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $pulados = 0;
                $linhaNum = 1;
                while (($linha = fgetcsv($handle, 0, $separador, '"', '\\')) !== false) {
                    $linhaNum++;
                    if (count(array_filter($linha, fn($v) => trim((string) $v) !== '')) === 0) {
                        continue;
                    }
                    $pegar = fn($campo) => $idx[$campo] !== null ? trim((string) ($linha[$idx[$campo]] ?? '')) : '';

                    $processoCsv = $pegar('processo');
                    if ($processoCsv === '') {
                        $erros++;
                        $mensagens[] = "⚠️ Linha $linhaNum ignorada: processo vazio.";
                        continue;
                    }
                    mysqli_stmt_bind_param($stmtProcCsv, 's', $processoCsv);
                    mysqli_stmt_execute($stmtProcCsv);
                    $proc = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtProcCsv));
                    if (!$proc) {
                        $erros++;
                        $mensagens[] = "⚠️ Linha $linhaNum ignorada: processo \"$processoCsv\" não existe em Processos.";
                        continue;
                    }

                    $dataTwinBruta = $pegar('data_twin');
                    $vencBruto = $pegar('numerario_inicial');
                    $valorBruto = $pegar('valor_inicial');
                    $dataTwinCsv = $dataTwinBruta !== '' ? parseDataPagamento($dataTwinBruta) : null;
                    $vencCsv = $vencBruto !== '' ? parseDataPagamento($vencBruto) : null;
                    $valorCsv = $valorBruto !== '' ? parseNumeroBrPagamento($valorBruto) : null;
                    if (($dataTwinBruta !== '' && $dataTwinCsv === null) || ($vencBruto !== '' && $vencCsv === null)) {
                        $erros++;
                        $mensagens[] = "⚠️ Linha $linhaNum ignorada: data inválida (use dd/mm/aaaa).";
                        continue;
                    }
                    if ($valorBruto !== '' && $valorCsv === null) {
                        $erros++;
                        $mensagens[] = "⚠️ Linha $linhaNum ignorada: valor '$valorBruto' inválido.";
                        continue;
                    }
                    $codTwinCsv = $pegar('cod_twin');

                    // Já existe igual? pula (não duplica ao reimportar)
                    $vencChave = (string) ($vencCsv ?? '');
                    mysqli_stmt_bind_param($stmtDupCsv, 'sssd', $processoCsv, $codTwinCsv, $vencChave, $valorCsv);
                    mysqli_stmt_execute($stmtDupCsv);
                    if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmtDupCsv))) {
                        $pulados++;
                        continue;
                    }

                    $situacao = normalizarTextoPagamento($pegar('liquidacao_na'));
                    $liquidacao = in_array($situacao, ['fechado', 'finalizado', 'pago'], true) ? 'fechado' : 'aberto';
                    $codTwinGravar = $codTwinCsv !== '' ? $codTwinCsv : null;
                    $rbCsv = $pegar('rb') !== '' ? $pegar('rb') : null;
                    $oaCsv = $pegar('oa') !== '' ? $pegar('oa') : null;
                    $totalProc = $proc['total'] !== null ? (float) $proc['total'] : null;

                    mysqli_stmt_bind_param(
                        $stmtInsCsv, 'sssssssdssdsss',
                        $processoCsv, $proc['status'], $proc['po'], $proc['fornecedor'], $codTwinGravar, $dataTwinCsv,
                        $proc['moeda'], $totalProc, $liquidacao, $vencCsv, $valorCsv, $liquidacao, $rbCsv, $oaCsv
                    );
                    if (mysqli_stmt_execute($stmtInsCsv)) {
                        $importados++;
                    } else {
                        $erros++;
                        $mensagens[] = "⚠️ Linha $linhaNum: " . mysqli_stmt_error($stmtInsCsv);
                    }
                }
                mysqli_stmt_close($stmtProcCsv);
                mysqli_stmt_close($stmtDupCsv);
                mysqli_stmt_close($stmtInsCsv);
                if ($limparAntes) {
                    if ($importados > 0) {
                        mysqli_commit($conn);
                        $mensagens[] = '🗑️ Histórico de pagamento apagado e substituído pelo conteúdo do arquivo.';
                    } else {
                        mysqli_rollback($conn);
                        $mensagens[] = '↩️ Nenhuma linha do arquivo pôde ser importada — a limpeza foi DESFEITA e o histórico de pagamento continua como estava.';
                    }
                }
                $mensagens[] = "✅ Importação concluída: $importados pagamento(s) incluído(s)"
                    . ($pulados > 0 ? ", $pulados já existente(s) pulado(s)" : '')
                    . ", $erros erro(s).";
            }
        }
        fclose($handle);
    }
}

// ---------- Exclusão ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_pagamento') {
    $idExcluir = (int) ($_POST['id'] ?? 0);
    if ($idExcluir > 0) {
        $stmtExcluir = mysqli_prepare($conn, "DELETE FROM pagamento WHERE id = ?");
        mysqli_stmt_bind_param($stmtExcluir, 'i', $idExcluir);
        if (mysqli_stmt_execute($stmtExcluir)) {
            $mensagens[] = '🗑️ Pagamento excluído.';
        } else {
            $mensagens[] = '❌ Erro ao excluir: ' . mysqli_stmt_error($stmtExcluir);
        }
        mysqli_stmt_close($stmtExcluir);
    }
}

// ---------- Cadastro manual ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cadastro_manual') {
    $processo = trim($_POST['processo_manual'] ?? '');
    if ($processo === '') {
        $mensagens[] = '❌ Escolha um processo já cadastrado em Processos.';
    } else {
        $stmtProc = mysqli_prepare($conn, "SELECT status, po, fornecedor, moeda, total FROM processos WHERE processo = ? LIMIT 1");
        mysqli_stmt_bind_param($stmtProc, 's', $processo);
        mysqli_stmt_execute($stmtProc);
        $dadosProcesso = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtProc));
        mysqli_stmt_close($stmtProc);

        if (!$dadosProcesso) {
            $mensagens[] = "❌ O processo \"$processo\" não existe em Processos. Cadastre ele lá primeiro.";
        } else {
        $stmt = mysqli_prepare($conn, "
            INSERT INTO pagamento (processo, status, po, fornecedor, cod_twin, data_twin, moeda, total, advanced1, advanced2, balance, liquidacao_or, despachante, numerario_inicial, valor_inicial, numerario_final, valor_final, diferenca, liquidacao_na, rb, oa)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $status = $dadosProcesso['status'];
        $po = $dadosProcesso['po'];
        $fornecedor = $dadosProcesso['fornecedor'];
        $moeda = $dadosProcesso['moeda'];
        $total = $dadosProcesso['total'];
        $codTwin = trim($_POST['cod_twin_manual'] ?? '') ?: null;
        $dataTwin = parseDataPagamento(trim($_POST['data_twin_manual'] ?? ''));
        // Advanced1/Advanced2/Balance/Despachante/Numerário final/Valor
        // final/Diferença saíram do cadastro manual — ficam null aqui (a
        // coluna continua existindo no banco, só não é mais preenchida por
        // esse formulário; pode continuar vindo de outro fluxo/import).
        $advanced1 = null;
        $advanced2 = null;
        $balance = null;
        $liquidacaoOr = 'aberto';
        $despachante = null;
        $numerarioInicial = parseDataPagamento(trim($_POST['numerario_inicial_manual'] ?? ''));
        $valorInicial = parseNumeroBrPagamento(trim($_POST['valor_inicial_manual'] ?? ''));
        $numerarioFinal = null;
        $valorFinal = null;
        $diferenca = null;
        $liquidacaoNa = 'aberto';
        $rb = trim($_POST['rb_manual'] ?? '') ?: null;
        $oa = trim($_POST['oa_manual'] ?? '') ?: null;

        mysqli_stmt_bind_param(
            $stmt, 'sssssssddddsssdsddsss',
            $processo, $status, $po, $fornecedor, $codTwin, $dataTwin, $moeda, $total, $advanced1, $advanced2, $balance,
            $liquidacaoOr, $despachante, $numerarioInicial, $valorInicial, $numerarioFinal, $valorFinal,
            $diferenca, $liquidacaoNa, $rb, $oa
        );
        if (mysqli_stmt_execute($stmt)) {
            $mensagens[] = "✅ Pagamento do processo \"$processo\" cadastrado.";
        } else {
            $mensagens[] = '❌ Erro ao cadastrar: ' . mysqli_stmt_error($stmt);
        }
        mysqli_stmt_close($stmt);
        }
    }
}

// ---------- Edição inline (duplo clique) ----------
// Identifica a linha por "id". Mesmo mecanismo usado em Processos e Follow:
// duplo clique numa célula, edita direto ali, salva sozinho (sem botão).
// Campos editáveis: numerário/valor inicial e final, diferença, RB e OA.
// Advanced1, Advanced2, Despachante e Balance não são editados por aqui.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'ajax_editar_campo') {
    header('Content-Type: application/json; charset=UTF-8');

    $id = (int) ($_POST['id'] ?? 0);
    $campo = (string) ($_POST['campo'] ?? '');
    $valor = trim((string) ($_POST['valor'] ?? ''));

    $camposTexto = ['rb', 'oa', 'cod_twin'];
    $camposData = ['numerario_inicial', 'data_twin'];
    $camposNumericos = ['valor_inicial'];
    $todosCampos = array_merge($camposTexto, $camposData, $camposNumericos);

    if ($id <= 0 || !in_array($campo, $todosCampos, true)) {
        echo json_encode(['ok' => false, 'erro' => 'Requisição inválida.']);
        exit;
    }

    $stmtCheck = mysqli_prepare($conn, "SELECT liquidacao_na FROM pagamento WHERE id = ?");
    mysqli_stmt_bind_param($stmtCheck, 'i', $id);
    mysqli_stmt_execute($stmtCheck);
    $linhaAtual = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtCheck));
    mysqli_stmt_close($stmtCheck);

    if (!$linhaAtual) {
        echo json_encode(['ok' => false, 'erro' => 'Registro não encontrado.']);
        exit;
    }
    if (strtolower(trim((string) $linhaAtual['liquidacao_na'])) === 'fechado') {
        echo json_encode(['ok' => false, 'erro' => 'Esse pagamento já foi finalizado — não é possível editar mais.']);
        exit;
    }

    if (in_array($campo, $camposData, true)) {
        $valorSalvo = $valor === '' ? null : parseDataPagamento($valor);
        if ($valor !== '' && $valorSalvo === null) {
            echo json_encode(['ok' => false, 'erro' => 'Data inválida. Use dd/mm/aaaa.']);
            exit;
        }
        $stmt = mysqli_prepare($conn, "UPDATE pagamento SET `$campo` = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $valorSalvo, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo json_encode(['ok' => true, 'exibido' => dataBr($valorSalvo)]);
        exit;
    }

    if (in_array($campo, $camposNumericos, true)) {
        $valorSalvo = $valor === '' ? null : parseNumeroBrPagamento($valor);
        if ($valor !== '' && $valorSalvo === null) {
            echo json_encode(['ok' => false, 'erro' => 'Valor numérico inválido.']);
            exit;
        }
        $stmt = mysqli_prepare($conn, "UPDATE pagamento SET `$campo` = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'di', $valorSalvo, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        echo json_encode(['ok' => true, 'exibido' => numeroBr($valorSalvo)]);
        exit;
    }

    // Campos de texto simples (RB, OA)
    $valorSalvo = $valor === '' ? null : $valor;
    $stmt = mysqli_prepare($conn, "UPDATE pagamento SET `$campo` = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'si', $valorSalvo, $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    echo json_encode(['ok' => true, 'exibido' => $valorSalvo ?? '—']);
    exit;
}

// ---------- Exportação CSV ----------
if (isset($_GET['exportar'])) {
    $busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
    $where = '';
    $params = [];
    if ($busca !== '') {
        $where = "WHERE processo LIKE ? OR fornecedor LIKE ? OR po LIKE ?";
        $like = '%' . $busca . '%';
        $params = [$like, $like, $like];
    }
    $sqlExport = "SELECT * FROM pagamento $where ORDER BY criado_em DESC";
    if (!empty($params)) {
        $stmtExport = mysqli_prepare($conn, $sqlExport);
        mysqli_stmt_bind_param($stmtExport, 'sss', ...$params);
        mysqli_stmt_execute($stmtExport);
        $resultExport = mysqli_stmt_get_result($stmtExport);
    } else {
        $resultExport = mysqli_query($conn, $sqlExport);
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="pagamento.csv"');
    echo "\xEF\xBB\xBF";
    $saida = fopen('php://output', 'w');
    fputcsv($saida, ['processo', 'status', 'po', 'fornecedor', 'cod_twin', 'data_twin', 'moeda', 'total', 'advanced1', 'advanced2', 'balance', 'liquidacao_or', 'despachante', 'numerario_inicial', 'valor_inicial', 'numerario_final', 'valor_final', 'diferenca', 'liquidacao_na', 'rb', 'oa'], ';', '"', '');
    while ($linha = mysqli_fetch_assoc($resultExport)) {
        fputcsv($saida, [
            $linha['processo'], $linha['status'], $linha['po'], $linha['fornecedor'], $linha['cod_twin'], $linha['data_twin'], $linha['moeda'],
            $linha['total'] !== null ? number_format((float) $linha['total'], 2, ',', '') : '',
            $linha['advanced1'] !== null ? number_format((float) $linha['advanced1'], 2, ',', '') : '',
            $linha['advanced2'] !== null ? number_format((float) $linha['advanced2'], 2, ',', '') : '',
            $linha['balance'] !== null ? number_format((float) $linha['balance'], 2, ',', '') : '',
            $linha['liquidacao_or'], $linha['despachante'], $linha['numerario_inicial'],
            $linha['valor_inicial'] !== null ? number_format((float) $linha['valor_inicial'], 2, ',', '') : '',
            $linha['numerario_final'],
            $linha['valor_final'] !== null ? number_format((float) $linha['valor_final'], 2, ',', '') : '',
            $linha['diferenca'] !== null ? number_format((float) $linha['diferenca'], 2, ',', '') : '',
            $linha['liquidacao_na'], $linha['rb'], $linha['oa'],
        ], ';', '"', '');
    }
    fclose($saida);
    exit;
}

// ---------- Listagem ----------
$processosDisponiveis = [];
$resProcessos = mysqli_query($conn, "SELECT processo, status, po, fornecedor, moeda, total FROM processos ORDER BY processo");
while ($linhaProc = mysqli_fetch_assoc($resProcessos)) {
    $processosDisponiveis[] = $linhaProc;
}

$porPagina = 50;
$pagina = isset($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$offset = ($pagina - 1) * $porPagina;
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';

$where = '';
$params = [];
$tipos = '';
if ($busca !== '') {
    $where = "WHERE pg.processo LIKE ? OR pg.fornecedor LIKE ? OR pg.po LIKE ?";
    $like = '%' . $busca . '%';
    $params = [$like, $like, $like];
    $tipos = 'sss';
}

$stmtTotal = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM pagamento pg $where");
if (!empty($params)) { mysqli_stmt_bind_param($stmtTotal, $tipos, ...$params); }
mysqli_stmt_execute($stmtTotal);
$total = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmtTotal))['total'];
mysqli_stmt_close($stmtTotal);
$totalPaginas = max(1, (int) ceil($total / $porPagina));

$sql = "
    SELECT pg.*,
           p.status AS status_processo_vivo,
           p.po AS po_vivo,
           p.fornecedor AS fornecedor_vivo,
           p.moeda AS moeda_vivo,
           p.total AS total_vivo
    FROM pagamento pg
    LEFT JOIN processos p ON p.processo = pg.processo
    $where
    ORDER BY pg.criado_em DESC
    LIMIT ? OFFSET ?
";
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
    $row['status'] = $row['status_processo_vivo'] ?? $row['status'];
    $row['po'] = $row['po_vivo'] ?? $row['po'];
    $row['fornecedor'] = $row['fornecedor_vivo'] ?? $row['fornecedor'];
    $row['moeda'] = $row['moeda_vivo'] ?? $row['moeda'];
    $row['total'] = $row['total_vivo'] ?? $row['total'];
    $rows[] = $row;
}

$totais = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(total) AS soma_total, SUM(balance) AS soma_balance FROM pagamento"));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pagamento | Controle de Importação</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/dashboard.css" rel="stylesheet">
    <style>
        .mrp-table .btn-remover-linha {
            border: none; background: none; color: #c53535; font-size: 1.05rem; cursor: pointer; line-height: 1;
        }
        .mrp-table .btn-remover-linha:hover { color: #a12727; }
        .celula-editavel { cursor: text; }
        .celula-editavel:hover { background: #f7faff; }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="container-fluid dashboard-container d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="eyebrow">Controle de Importação • Financeiro</span>
                <h1>Pagamento</h1>
                <p class="mb-0"><?php echo numeroBr($total, 0); ?> registro(s) na base • Total geral: <?php echo numeroBr($totais['soma_total'] ?? 0); ?> • Balance geral: <?php echo numeroBr($totais['soma_balance'] ?? 0); ?></p>
            </div>
            <nav class="d-flex flex-wrap gap-2" aria-label="Ações do sistema">
                <a class="btn btn-outline-light btn-sm" href="follow.php">Follow</a>
                <a class="btn btn-outline-light btn-sm" href="processos.php">Processos</a>
                <a class="btn btn-light btn-sm" href="pagamento.php">Pagamento</a>
                <a class="btn btn-outline-light btn-sm" href="confirmar_entrega.php">Confirmar entrega</a>
            </nav>
        </div>
    </header>

    <main class="container-fluid dashboard-container py-4">

        <?php if (isset($_GET['editado'])): ?>
            <div class="alert alert-success">✅ Pagamento atualizado com sucesso.</div>
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
                <summary class="fw-bold" style="cursor:pointer;">➕ Novo pagamento (cadastro manual)</summary>
                <form method="POST" class="row g-3 mt-3">
                    <input type="hidden" name="acao" value="cadastro_manual">
                    <div class="col-md-3">
                        <label class="form-label">Processo *</label>
                        <select name="processo_manual" id="processo_manual" class="form-select" required onchange="atualizarPreviewProcessoPagamento();">
                            <option value="">Escolha um processo já cadastrado...</option>
                            <?php foreach ($processosDisponiveis as $p): ?>
                                <option value="<?php echo h($p['processo']); ?>"
                                    data-status="<?php echo h($p['status'] ?? ''); ?>"
                                    data-po="<?php echo h($p['po'] ?? ''); ?>"
                                    data-fornecedor="<?php echo h($p['fornecedor'] ?? ''); ?>"
                                    data-moeda="<?php echo h($p['moeda'] ?? ''); ?>"
                                    data-total="<?php echo h($p['total'] !== null ? number_format((float) $p['total'], 2, ',', '.') : ''); ?>">
                                    <?php echo h($p['processo']); ?><?php echo $p['fornecedor'] ? ' — ' . h($p['fornecedor']) : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($processosDisponiveis)): ?>
                            <small class="text-danger">Nenhum processo cadastrado ainda — <a href="processos.php">cadastre um processo primeiro</a>.</small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Status <small class="text-muted">(do processo)</small></label>
                        <input type="text" id="preview_status" class="form-control" disabled placeholder="—">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">PO <small class="text-muted">(do processo)</small></label>
                        <input type="text" id="preview_po" class="form-control" disabled placeholder="—">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Fornecedor <small class="text-muted">(do processo)</small></label>
                        <input type="text" id="preview_fornecedor" class="form-control" disabled placeholder="—">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Moeda <small class="text-muted">(do processo)</small></label>
                        <input type="text" id="preview_moeda" class="form-control" disabled placeholder="—">
                    </div>
                    <div class="col-12"><hr class="my-1"></div>
                    <div class="col-md-2">
                        <label class="form-label">Cod. Twin.</label>
                        <input type="text" name="cod_twin_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Data</label>
                        <input type="text" name="data_twin_manual" class="form-control" placeholder="dd/mm/aaaa">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Vencimento</label>
                        <input type="text" name="numerario_inicial_manual" class="form-control" placeholder="dd/mm/aaaa">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Valor</label>
                        <input type="text" name="valor_inicial_manual" id="valor_inicial_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">RB</label>
                        <input type="text" name="rb_manual" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">OA (link)</label>
                        <input type="text" name="oa_manual" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Salvar</button>
                    </div>
                </form>
            </details>
        </section>

        <section class="filter-panel mb-4">
            <details <?php echo isset($_FILES['arquivo_csv_pagamento']) ? 'open' : ''; ?>>
                <summary class="fw-bold" style="cursor:pointer;">📥 Importar pagamentos (.csv)</summary>
                <form method="POST" enctype="multipart/form-data" class="row g-3 mt-2 align-items-end"
                      onsubmit="return !this.limpar_pagamento.checked || confirm('ATENÇÃO: isso vai APAGAR todo o histórico de pagamento e substituir pelo conteúdo do arquivo. Continuar?');">
                    <div class="col-md-6">
                        <label class="form-label">Arquivo CSV</label>
                        <input type="file" name="arquivo_csv_pagamento" accept=".csv" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="limpar_pagamento" id="limpar_pagamento">
                            <label class="form-check-label" for="limpar_pagamento">Limpar tabela antes de importar</label>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">Importar</button>
                    </div>
                </form>
                <small class="text-muted d-block mt-2">
                    Colunas (qualquer ordem): <code>processo, cod_twin, data_twin, numerario_inicial, valor_inicial, rb, oa</code> —
                    também aceita os nomes da tela: <code>Cod. Twin., Data, Vencimento, Valor, RB, OA</code>. Só <code>processo</code> é obrigatório
                    e ele precisa existir em Processos (Status, PO, Fornecedor, Moeda e Total vêm de lá). Opcional: <code>situacao</code> (aberto ou finalizado).
                    Datas em dd/mm/aaaa; valores como 1.234,56. Linha igual a uma já cadastrada (mesmo processo, Cod. Twin, Vencimento e Valor) é pulada.
                    <strong>Limpar tabela</strong>: apaga todos os pagamentos e substitui pelo arquivo (se nenhuma linha for importada, a limpeza é desfeita).
                    Separador vírgula ou ponto e vírgula. O "Exportar CSV" desta tela serve de modelo.
                </small>
            </details>
        </section>

        <section class="filter-panel mb-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label class="form-label">Buscar por processo, fornecedor ou PO</label>
                    <input type="text" name="busca" class="form-control" value="<?php echo h($busca); ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Buscar</button>
                </div>
                <div class="col-md-2">
                    <a href="?exportar=1&busca=<?php echo urlencode($busca); ?>" class="btn btn-outline-secondary w-100">Exportar CSV</a>
                </div>
            </form>
        </section>

        <section class="table-card">
            <div class="table-toolbar">
                <div>
                    <span class="eyebrow text-primary">Resultado</span>
                    <h2>Pagamentos</h2>
                    <p><?php echo numeroBr($total, 0); ?> encontrado(s) <small class="text-muted">— dê duplo clique numa célula pra editar</small></p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table mrp-table mb-0" style="min-width: 1350px;">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Processo</th>
                            <th>PO</th>
                            <th>Fornecedor</th>
                            <th>Cod. Twin.</th>
                            <th>Data</th>
                            <th>Vencimento</th>
                            <th>Valor</th>
                            <th>RB</th>
                            <th>OA</th>
                            <th class="no-print">Excluir</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="11" class="empty-state">Nenhum pagamento encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <?php
                                    $id = (int) $r['id'];
                                    $naValor = strtolower(trim((string) ($r['liquidacao_na'] ?? ''))) === 'fechado' ? 'fechado' : 'aberto';
                                    $processoCancelado = strtolower(trim((string) ($r['status'] ?? ''))) === 'cancelado';
                                    if ($processoCancelado) {
                                        $statusTexto = 'Cancelado'; $statusClasse = 'status-critico';
                                    } elseif ($naValor === 'fechado') {
                                        $statusTexto = 'Finalizado'; $statusClasse = 'status-ok';
                                    } else {
                                        $statusTexto = 'Aberto'; $statusClasse = 'status-atencao';
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($processoCancelado): ?>
                                            <span class="status-badge <?php echo $statusClasse; ?>"><?php echo h($statusTexto); ?></span>
                                        <?php else: ?>
                                            <form method="POST" class="d-inline m-0">
                                                <input type="hidden" name="acao" value="toggle_status_pagamento">
                                                <input type="hidden" name="id" value="<?php echo $id; ?>">
                                                <button type="submit" class="status-badge border-0 <?php echo $statusClasse; ?>" style="cursor:pointer;" title="Clique pra alternar entre Aberto e Finalizado">
                                                    <?php echo h($statusTexto); ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="component-code"><?php echo h($r['processo']); ?></span></td>
                                    <td><?php echo h($r['po'] ?: '—'); ?></td>
                                    <td><?php echo h($r['fornecedor'] ?: '—'); ?></td>
                                    <td class="celula-editavel" data-id="<?php echo $id; ?>" data-campo="cod_twin" data-valor-bruto="<?php echo h($r['cod_twin'] ?? ''); ?>"><?php echo h($r['cod_twin'] ?: '—'); ?></td>
                                    <td class="celula-editavel" data-id="<?php echo $id; ?>" data-campo="data_twin" data-valor-bruto="<?php echo $r['data_twin'] ? h(dataBr($r['data_twin'])) : ''; ?>"><?php echo dataBr($r['data_twin']); ?></td>
                                    <td class="celula-editavel" data-id="<?php echo $id; ?>" data-campo="numerario_inicial" data-valor-bruto="<?php echo $r['numerario_inicial'] ? h(dataBr($r['numerario_inicial'])) : ''; ?>"><?php echo dataBr($r['numerario_inicial']); ?></td>
                                    <td class="celula-editavel" data-id="<?php echo $id; ?>" data-campo="valor_inicial" data-valor-bruto="<?php echo $r['valor_inicial'] !== null ? numeroBr($r['valor_inicial']) : ''; ?>"><?php echo numeroBr($r['valor_inicial']); ?></td>
                                    <td class="celula-editavel" data-id="<?php echo $id; ?>" data-campo="rb" data-valor-bruto="<?php echo h($r['rb'] ?? ''); ?>"><?php echo h($r['rb'] ?: '—'); ?></td>
                                    <td class="celula-editavel" data-id="<?php echo $id; ?>" data-campo="oa" data-valor-bruto="<?php echo h($r['oa'] ?? ''); ?>">
                                        <?php if (!empty($r['oa'])): ?>
                                            <a href="<?php echo h($r['oa']); ?>" target="_blank" rel="noopener" onclick="event.stopPropagation();">Abrir</a>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td class="no-print">
                                        <form method="POST" class="d-inline m-0" onsubmit="return confirm('Excluir este pagamento? Essa ação não pode ser desfeita.');">
                                            <input type="hidden" name="acao" value="excluir_pagamento">
                                            <input type="hidden" name="id" value="<?php echo $id; ?>">
                                            <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                                            <input type="hidden" name="busca_atual" value="<?php echo h($busca); ?>">
                                            <button type="submit" class="btn-remover-linha" title="Excluir pagamento">✕</button>
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
        function atualizarPreviewProcessoPagamento() {
            const select = document.getElementById('processo_manual');
            const opcao = select.options[select.selectedIndex];
            document.getElementById('preview_status').value = opcao.dataset.status || '—';
            document.getElementById('preview_po').value = opcao.dataset.po || '—';
            document.getElementById('preview_fornecedor').value = opcao.dataset.fornecedor || '—';
            document.getElementById('preview_moeda').value = opcao.dataset.moeda || '—';
        }
    </script>
    <script>
        window.INLINE_EDIT_ENDPOINT = 'pagamento.php';
        window.INLINE_EDIT_ACAO = 'ajax_editar_campo';
    </script>
    <script src="assets/inline-edit.js"></script>
</body>
</html>
