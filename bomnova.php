<?php
require_once 'conexao.php';

require_once 'auth.php';
exigirLogin();
// Evita timeout do PHP em importações grandes; a lentidão real é de rede até o banco,
// não do processamento em si, então aumentamos a margem de segurança.
set_time_limit(300);

// Interpreta números em formato BR: "1.400" = 1400 (milhar), "1.234,56" = 1234.56.
// Sem isso, um consumo gravado como "1.400" seria lido depois como 1,4 na hora do
// cálculo (o banco interpreta "." como separador decimal, não de milhar).
function parseNumeroBr(string $valor): ?float
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

// Interpreta Net Price / IPI / PIS / COFINS / ICMS — diferente de parseNumeroBr(),
// aqui um ponto SEMPRE é separador decimal, nunca de milhar (esses campos não têm
// milhar: são % de 0 a 100, ou um preço unitário). Vírgula continua sendo decimal
// quando presente (e aí sim um ponto antes dela é milhar). Isso evita o valor
// digitado como "13.2000" virar 132000 por engano.
function parseNumeroBomnovaTax(string $valor): ?float
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }

    if (str_contains($valor, ',')) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    }

    return is_numeric($valor) ? (float) $valor : null;
}

// Calcula o Preço a partir do Net Price e dos impostos (IPI, PIS, COFINS, ICMS,
// digitados em %), seguindo o gross-up:
//   Preço = [ NetPrice ÷ (100% − (ICMS% + (PIS% − ICMS%×PIS%) + (COFINS% − ICMS%×COFINS%))) ] × (1 + IPI%)
// Sem Net Price não dá pra calcular nada (retorna null, exibido como "—").
// Impostos em branco contam como 0%. Se o denominador do gross-up zerar (ou ficar
// negativo/zero), a divisão não faz sentido, então também retorna null nesse caso
// extremo.
// Nome de coluna do CSV "normalizado": minúsculo, sem acento e só letras/números.
// Ex.: "Net Price" / "NET_PRICE" / "net-price" -> "netprice"; "IPI %" -> "ipi";
// "Descrição" -> "descricao"; "U.M." -> "um".
function normalizarCabecalhoBomnova(string $nome): string
{
    $nome = mb_strtolower(trim($nome), 'UTF-8');
    $nome = strtr($nome, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','í'=>'i','î'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','û'=>'u','ü'=>'u','ç'=>'c']);
    return preg_replace('/[^a-z0-9]/', '', $nome);
}

function calcularPrecoBomnova(?string $netPriceTexto, ?string $ipiTexto, ?string $pisTexto, ?string $cofinsTexto, ?string $icmsTexto): ?float
{
    $netPrice = $netPriceTexto !== null ? parseNumeroBomnovaTax($netPriceTexto) : null;
    if ($netPrice === null) {
        return null;
    }
    $ipi = ($ipiTexto !== null ? parseNumeroBomnovaTax($ipiTexto) : null) ?? 0.0;
    $pis = ($pisTexto !== null ? parseNumeroBomnovaTax($pisTexto) : null) ?? 0.0;
    $cofins = ($cofinsTexto !== null ? parseNumeroBomnovaTax($cofinsTexto) : null) ?? 0.0;
    $icms = ($icmsTexto !== null ? parseNumeroBomnovaTax($icmsTexto) : null) ?? 0.0;

    $icmsFr = $icms / 100;
    $pisFr = $pis / 100;
    $cofinsFr = $cofins / 100;
    $ipiFr = $ipi / 100;

    $divisor = 1 - ($icmsFr + ($pisFr - $icmsFr * $pisFr) + ($cofinsFr - $icmsFr * $cofinsFr));
    if (abs($divisor) < 0.0000001) {
        return null;
    }

    return ($netPrice / $divisor) * (1 + $ipiFr);
}

// Exibe Net Price/IPI/PIS/COFINS/ICMS sempre em formato BR (vírgula decimal) na
// tela — o banco pode ter o valor gravado com ponto (ex.: "13.2000"), mas o
// resto do site (e o próprio Preço calculado) usa vírgula, então a exibição
// fica inconsistente se mostrarmos o dado cru direto da tabela. $casas define
// quantas casas decimais aparecem (Net Price: 4, IPI/PIS/COFINS: 2, ICMS: 0).
function formatarNumeroBomnovaTaxExibicao(?string $valor, int $casas = 4): string
{
    if ($valor === null || trim($valor) === '') {
        return '';
    }
    $numero = parseNumeroBomnovaTax($valor);
    return $numero !== null ? number_format($numero, $casas, ',', '.') : $valor;
}

// Casas decimais de exibição por campo: Net Price fica com 4 (precisa de mais
// precisão pro cálculo do Preço), IPI/PIS/COFINS com 2, ICMS com 0 (inteiro).
const BOMNOVA_CASAS_DECIMAIS = [
    'net_price' => 4,
    'ipi' => 2,
    'pis' => 2,
    'cofins' => 2,
    'icms' => 0,
];

// Valor padrão usado quando o campo está em branco no banco — PIS e COFINS
// quase sempre são 1,65% e 7,60% (regime não-cumulativo), então em vez de
// ficar em branco (e contar como 0% no cálculo do Preço), já mostra o valor
// padrão pronto na tela. Continua editável por duplo clique normalmente —
// isso só preenche o vazio, nunca sobrescreve um valor já gravado.
const BOMNOVA_VALOR_PADRAO = [
    'pis' => 1.65,
    'cofins' => 7.6,
];

// Aplica o valor padrão (BOMNOVA_VALOR_PADRAO) quando o campo vem vazio/nulo
// do banco. Usado tanto na exibição quanto no cálculo do Preço, pra manter
// os dois consistentes: o que aparece na tela é o mesmo valor usado na conta.
function aplicarValorPadraoBomnova(?string $valor, string $campo): ?string
{
    if ($valor !== null && trim($valor) !== '') {
        return $valor;
    }
    if (isset(BOMNOVA_VALOR_PADRAO[$campo])) {
        return (string) BOMNOVA_VALOR_PADRAO[$campo];
    }
    return $valor;
}

$mensagens = [];
$importados = 0;
$erros = 0;

// Alterna o MRP (S/N) de uma linha específica da BOM. Como a tabela "bomnova" não tem
// coluna id (confirmado via SHOW COLUMNS), a linha é identificada pela combinação de
// TODOS os outros campos ao mesmo tempo — não existe outro jeito de apontar "essa linha
// exata" sem um identificador único. COALESCE(...,'') trata NULL e string vazia como
// equivalentes na comparação, pra não depender de acertar exatamente NULL vs '' na
// hora de casar o valor. LIMIT 1 garante que, mesmo em um cenário raro de duas linhas
// idênticas em tudo, só uma é afetada por clique (não trava a página, só limita o
// alcance do clique único).
// Edição inline (duplo clique) de um campo por vez. Mesma limitação de sempre:
// sem coluna id na tabela, a linha só pode ser identificada pela combinação de
// TODOS os campos originais ao mesmo tempo (enviados pelo front como orig_*).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'ajax_editar_campo') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!ehComprador()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'erro' => 'Você está como Visualizador e não pode editar.']);
        exit;
    }

    $camposEditaveis = ['planta', 'projeto', 'material', 'tipo', 'fornecedor', 'codigo_componente', 'pn', 'descricao', 'consumo', 'um', 'net_price', 'ipi', 'pis', 'cofins', 'icms', 'moeda'];
    $campo = (string) ($_POST['campo'] ?? '');
    $novoValor = trim((string) ($_POST['valor'] ?? ''));

    if (!in_array($campo, $camposEditaveis, true)) {
        echo json_encode(['ok' => false, 'erro' => 'Requisição inválida.']);
        exit;
    }

    // Net Price e os impostos (%) são numéricos — normaliza pra sempre gravar/
    // exibir com 4 casas decimais, aceitando tanto "10,5" quanto "10.5" na
    // digitação. Vazio grava NULL (campo em branco, some do cálculo do Preço).
    $camposNumericosPercentuais = ['net_price', 'ipi', 'pis', 'cofins', 'icms'];
    $valorParaGravar = $novoValor;
    $valorParaExibir = $novoValor;
    if (in_array($campo, $camposNumericosPercentuais, true)) {
        if ($novoValor === '') {
            $valorParaGravar = null;
            $valorParaExibir = '';
        } else {
            $numeroEditado = parseNumeroBomnovaTax($novoValor);
            if ($numeroEditado === null) {
                echo json_encode(['ok' => false, 'erro' => 'Valor inválido.']);
                exit;
            }
            $casasDecimais = BOMNOVA_CASAS_DECIMAIS[$campo] ?? 4;
            $valorParaGravar = number_format($numeroEditado, $casasDecimais, '.', '');
            $valorParaExibir = number_format($numeroEditado, $casasDecimais, ',', '.');
        }
    }

    // Moeda: texto curto em maiúsculas (USD, EUR...). Apagar volta pro padrão BRL.
    if ($campo === 'moeda') {
        $moedaEditada = strtoupper($novoValor);
        if (mb_strlen($moedaEditada) > 10) {
            echo json_encode(['ok' => false, 'erro' => 'Moeda com no máximo 10 caracteres (ex.: USD).']);
            exit;
        }
        $valorParaGravar = $moedaEditada !== '' ? $moedaEditada : 'BRL';
        $valorParaExibir = $valorParaGravar;
    }

    // Consumo é número: aceita "1,5" ou "1.5" (ponto sozinho = decimal, nunca
    // milhar) e grava sempre com ponto, pra não dar erro/valor torto no banco.
    if ($campo === 'consumo') {
        if ($novoValor === '') {
            $valorParaGravar = null;
            $valorParaExibir = '';
        } else {
            $consumoEditado = parseNumeroBomnovaTax($novoValor);
            if ($consumoEditado === null) {
                echo json_encode(['ok' => false, 'erro' => 'Consumo inválido.']);
                exit;
            }
            $valorParaGravar = rtrim(rtrim(number_format($consumoEditado, 6, '.', ''), '0'), '.');
            $valorParaExibir = $valorParaGravar;
        }
    }

    $camposCompostos = ['planta', 'projeto', 'material', 'tipo', 'fornecedor', 'codigo_componente', 'pn', 'descricao', 'consumo', 'um'];
    $valoresOriginais = [];
    foreach ($camposCompostos as $c) {
        $valoresOriginais[] = (string) ($_POST['orig_' . $c] ?? '');
    }

    $condicoes = array_map(fn($c) => "COALESCE($c, '') = ?", $camposCompostos);
    $sqlEditar = "UPDATE bomnova SET $campo = ? WHERE " . implode(' AND ', $condicoes) . " LIMIT 1";

    $stmtEditar = mysqli_prepare($conn, $sqlEditar);
    $tiposEditar = str_repeat('s', 1 + count($camposCompostos));
    $parametrosEditar = array_merge([$valorParaGravar], $valoresOriginais);
    mysqli_stmt_bind_param($stmtEditar, $tiposEditar, ...$parametrosEditar);
    try {
        mysqli_stmt_execute($stmtEditar);
    } catch (Throwable $erroEditar) {
        mysqli_stmt_close($stmtEditar);
        echo json_encode(['ok' => false, 'erro' => 'Não foi possível salvar: ' . $erroEditar->getMessage()]);
        exit;
    }
    $linhasAfetadas = mysqli_stmt_affected_rows($stmtEditar);
    mysqli_stmt_close($stmtEditar);

    if ($linhasAfetadas === 0) {
        echo json_encode(['ok' => false, 'erro' => 'Não achei essa linha exata (os dados podem ter mudado). Recarregue a página e tente de novo.']);
        exit;
    }

    // Consumo faz parte da "chave" da linha: devolve o valor EXATAMENTE como o
    // banco guardou (ex.: 1.5 pode virar "1.5000" numa coluna decimal), pra tela
    // usar esse mesmo texto nas próximas edições/cliques da linha.
    if ($campo === 'consumo' && $valorParaGravar !== null) {
        $condRelida = [];
        $valoresRelidos = [];
        foreach ($camposCompostos as $i => $c) {
            if ($c === 'consumo') {
                $condRelida[] = 'consumo = ?';
                $valoresRelidos[] = $valorParaGravar;
            } else {
                $condRelida[] = "COALESCE($c, '') = ?";
                $valoresRelidos[] = $valoresOriginais[$i];
            }
        }
        $stmtRelido = mysqli_prepare($conn, "SELECT consumo FROM bomnova WHERE " . implode(' AND ', $condRelida) . " LIMIT 1");
        mysqli_stmt_bind_param($stmtRelido, str_repeat('s', count($valoresRelidos)), ...$valoresRelidos);
        mysqli_stmt_execute($stmtRelido);
        $consumoRelido = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtRelido))['consumo'] ?? null;
        mysqli_stmt_close($stmtRelido);
        if ($consumoRelido !== null) {
            $valorParaExibir = (string) $consumoRelido;
        }
    }

    echo json_encode(['ok' => true, 'exibido' => $valorParaExibir]);
    exit;
}

// ---------- Toggle MRP / Planejamento via AJAX (sem recarregar a página) ----------
// Mesma regra dos handlers com reload logo abaixo, só que devolve JSON pra JS
// trocar as duas badges no lugar (MRP força Planejamento junto quando muda
// pra N, então o retorno já traz o estado das duas).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'ajax_toggle_mrp') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!ehComprador()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'erro' => 'Você está como Visualizador e não pode editar.']);
        exit;
    }

    $campos = ['planta', 'projeto', 'material', 'tipo', 'fornecedor', 'codigo_componente', 'pn', 'descricao', 'consumo', 'um'];
    $valoresOriginais = [];
    foreach ($campos as $campo) {
        $valoresOriginais[] = (string) ($_POST['orig_' . $campo] ?? '');
    }

    $mrpAtual = strtoupper(trim((string) ($_POST['mrp_atual'] ?? '')));
    $novoMrp = ($mrpAtual === 'N') ? 'S' : 'N';
    $novoPlanejamento = ($novoMrp === 'N') ? 'N' : 'S';

    $condicoes = array_map(fn($campo) => "COALESCE($campo, '') = ?", $campos);
    $sqlToggle = "UPDATE bomnova SET mrp = ?, planejamento = ? WHERE " . implode(' AND ', $condicoes) . " LIMIT 1";

    $stmtToggle = mysqli_prepare($conn, $sqlToggle);
    $tiposToggle = str_repeat('s', 2 + count($campos));
    $parametrosToggle = array_merge([$novoMrp, $novoPlanejamento], $valoresOriginais);
    mysqli_stmt_bind_param($stmtToggle, $tiposToggle, ...$parametrosToggle);
    mysqli_stmt_execute($stmtToggle);
    $linhasAfetadas = mysqli_stmt_affected_rows($stmtToggle);
    mysqli_stmt_close($stmtToggle);

    if ($linhasAfetadas === 0) {
        echo json_encode(['ok' => false, 'erro' => 'Não achei essa linha exata (os dados podem ter mudado). Recarregue a página e tente de novo.']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'mrpTexto' => $novoMrp,
        'mrpClasse' => $novoMrp === 'N' ? 'badge-mrp-n' : 'badge-mrp-s',
        'planTexto' => $novoPlanejamento,
        'planClasse' => $novoPlanejamento === 'N' ? 'badge-mrp-n' : 'badge-mrp-s',
        'planBloqueado' => $novoMrp === 'N',
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'ajax_toggle_planejamento') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!ehComprador()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'erro' => 'Você está como Visualizador e não pode editar.']);
        exit;
    }

    $campos = ['planta', 'projeto', 'material', 'tipo', 'fornecedor', 'codigo_componente', 'pn', 'descricao', 'consumo', 'um'];
    $valoresOriginais = [];
    foreach ($campos as $campo) {
        $valoresOriginais[] = (string) ($_POST['orig_' . $campo] ?? '');
    }

    $mrpAtualPlan = strtoupper(trim((string) ($_POST['mrp_atual'] ?? '')));
    $planejamentoAtual = strtoupper(trim((string) ($_POST['planejamento_atual'] ?? '')));

    if ($mrpAtualPlan === 'N') {
        echo json_encode(['ok' => false, 'erro' => 'MRP=N já força Planejamento=N — reabra o MRP primeiro pra poder mudar isso.']);
        exit;
    }

    $novoPlanejamentoToggle = ($planejamentoAtual === 'N') ? 'S' : 'N';
    $condicoes = array_map(fn($campo) => "COALESCE($campo, '') = ?", $campos);
    $sqlToggle = "UPDATE bomnova SET planejamento = ? WHERE mrp = 'S' AND " . implode(' AND ', $condicoes) . " LIMIT 1";

    $stmtToggle = mysqli_prepare($conn, $sqlToggle);
    $tiposToggle = str_repeat('s', 1 + count($campos));
    $parametrosToggle = array_merge([$novoPlanejamentoToggle], $valoresOriginais);
    mysqli_stmt_bind_param($stmtToggle, $tiposToggle, ...$parametrosToggle);
    mysqli_stmt_execute($stmtToggle);
    $linhasAfetadas = mysqli_stmt_affected_rows($stmtToggle);
    mysqli_stmt_close($stmtToggle);

    if ($linhasAfetadas === 0) {
        echo json_encode(['ok' => false, 'erro' => 'Não achei essa linha exata (os dados podem ter mudado). Recarregue a página e tente de novo.']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'mrpTexto' => $mrpAtualPlan,
        'mrpClasse' => $mrpAtualPlan === 'N' ? 'badge-mrp-n' : 'badge-mrp-s',
        'planTexto' => $novoPlanejamentoToggle,
        'planClasse' => $novoPlanejamentoToggle === 'N' ? 'badge-mrp-n' : 'badge-mrp-s',
        'planBloqueado' => false,
    ]);
    exit;
}

// Exclui SÓ a linha exata do BOM (aquela combinação planta+projeto+material+
// ...+componente), identificada pela mesma combinação de todos os campos
// usada em editar/toggle (a tabela não tem coluna id). Não mexe em nenhuma
// outra tabela: pedido_compra e o histórico de compras guardam o código do
// componente como texto solto, sem vínculo com a linha do BOM, então
// continuam intactos. Programação e estoque também não são tocados. Se o
// componente tiver outras linhas (outros projetos/plantas), elas continuam
// normalmente — só o consumo dessa linha específica sai da soma usada em
// Planejamento de Compras e Evolução Geral, porque essa linha deixa de
// existir na tabela.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_linha_bomnova') {
    exigirComprador();
    $camposExcluir = ['planta', 'projeto', 'material', 'tipo', 'fornecedor', 'codigo_componente', 'pn', 'descricao', 'consumo', 'um'];
    $valoresOriginaisExcluir = [];
    foreach ($camposExcluir as $campo) {
        $valoresOriginaisExcluir[] = (string) ($_POST['orig_' . $campo] ?? '');
    }

    $condicoesExcluir = array_map(fn($campo) => "COALESCE($campo, '') = ?", $camposExcluir);
    $sqlExcluirLinha = "DELETE FROM bomnova WHERE " . implode(' AND ', $condicoesExcluir) . " LIMIT 1";

    $stmtExcluirLinha = mysqli_prepare($conn, $sqlExcluirLinha);
    $tiposExcluirLinha = str_repeat('s', count($camposExcluir));
    mysqli_stmt_bind_param($stmtExcluirLinha, $tiposExcluirLinha, ...$valoresOriginaisExcluir);
    mysqli_stmt_execute($stmtExcluirLinha);
    $linhasAfetadasExcluir = mysqli_stmt_affected_rows($stmtExcluirLinha);
    mysqli_stmt_close($stmtExcluirLinha);

    $paginaVoltaExcluir = (int) ($_POST['pagina_atual'] ?? 1);
    $buscaVoltaExcluir = (string) ($_POST['busca_atual'] ?? '');
    $projetoVoltaExcluir = (string) ($_POST['projeto_atual'] ?? '');
    $fornecedorVoltaExcluir = (string) ($_POST['fornecedor_atual'] ?? '');
    $flagExcluir = $linhasAfetadasExcluir > 0 ? 'excluido=1' : 'excluir_erro=1';
    header('Location: bomnova.php?pagina=' . $paginaVoltaExcluir . '&busca=' . urlencode($buscaVoltaExcluir) . '&projeto=' . urlencode($projetoVoltaExcluir) . '&fornecedor=' . urlencode($fornecedorVoltaExcluir) . '&' . $flagExcluir);
    exit;
}

// (Mantidos como fallback caso o JS não carregue — recarregam a página normalmente.)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'toggle_mrp') {
    exigirComprador();
    $campos = ['planta', 'projeto', 'material', 'tipo', 'fornecedor', 'codigo_componente', 'pn', 'descricao', 'consumo', 'um'];
    $valoresOriginais = [];
    foreach ($campos as $campo) {
        $valoresOriginais[] = (string) ($_POST['orig_' . $campo] ?? '');
    }

    $mrpAtual = strtoupper(trim((string) ($_POST['mrp_atual'] ?? '')));
    $novoMrp = ($mrpAtual === 'N') ? 'S' : 'N';
    // Regra obrigatória: MRP=N sempre força Planejamento=N junto. Ao reabrir
    // (voltar pra S), reseta Planejamento pra S também (estado "tudo normal"
    // por padrão — quem quiser desligar só o planejamento, faz isso depois,
    // com o botão de Planejamento).
    $novoPlanejamento = ($novoMrp === 'N') ? 'N' : 'S';

    $condicoes = array_map(fn($campo) => "COALESCE($campo, '') = ?", $campos);
    $sqlToggle = "UPDATE bomnova SET mrp = ?, planejamento = ? WHERE " . implode(' AND ', $condicoes) . " LIMIT 1";

    $stmtToggle = mysqli_prepare($conn, $sqlToggle);
    $tiposToggle = str_repeat('s', 2 + count($campos));
    $parametrosToggle = array_merge([$novoMrp, $novoPlanejamento], $valoresOriginais);
    mysqli_stmt_bind_param($stmtToggle, $tiposToggle, ...$parametrosToggle);
    mysqli_stmt_execute($stmtToggle);
    $linhasAfetadas = mysqli_stmt_affected_rows($stmtToggle);
    mysqli_stmt_close($stmtToggle);

    $paginaVolta = (int) ($_POST['pagina_atual'] ?? 1);
    $buscaVolta = (string) ($_POST['busca_atual'] ?? '');
    $projetoVolta = (string) ($_POST['projeto_atual'] ?? '');
    $fornecedorVolta = (string) ($_POST['fornecedor_atual'] ?? '');
    $flag = $linhasAfetadas > 0 ? 'mrp_ok' : 'mrp_erro';
    header('Location: bomnova.php?pagina=' . $paginaVolta . '&busca=' . urlencode($buscaVolta) . '&projeto=' . urlencode($projetoVolta) . '&fornecedor=' . urlencode($fornecedorVolta) . '&' . $flag . '=1');
    exit;
}

// Toggle do Planejamento (S/N) — só tem efeito quando MRP=S (se MRP=N, o
// planejamento já está travado em N pela regra acima, e essa ação nem deveria
// ser possível de clicar, mas a trava é reforçada aqui no servidor também).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'toggle_planejamento') {
    exigirComprador();
    $campos = ['planta', 'projeto', 'material', 'tipo', 'fornecedor', 'codigo_componente', 'pn', 'descricao', 'consumo', 'um'];
    $valoresOriginais = [];
    foreach ($campos as $campo) {
        $valoresOriginais[] = (string) ($_POST['orig_' . $campo] ?? '');
    }

    $mrpAtualPlan = strtoupper(trim((string) ($_POST['mrp_atual'] ?? '')));
    $planejamentoAtual = strtoupper(trim((string) ($_POST['planejamento_atual'] ?? '')));

    if ($mrpAtualPlan === 'N') {
        $flag = 'planejamento_bloqueado';
    } else {
        $novoPlanejamentoToggle = ($planejamentoAtual === 'N') ? 'S' : 'N';
        $condicoes = array_map(fn($campo) => "COALESCE($campo, '') = ?", $campos);
        $sqlToggle = "UPDATE bomnova SET planejamento = ? WHERE mrp = 'S' AND " . implode(' AND ', $condicoes) . " LIMIT 1";

        $stmtToggle = mysqli_prepare($conn, $sqlToggle);
        $tiposToggle = str_repeat('s', 1 + count($campos));
        $parametrosToggle = array_merge([$novoPlanejamentoToggle], $valoresOriginais);
        mysqli_stmt_bind_param($stmtToggle, $tiposToggle, ...$parametrosToggle);
        mysqli_stmt_execute($stmtToggle);
        $linhasAfetadas = mysqli_stmt_affected_rows($stmtToggle);
        mysqli_stmt_close($stmtToggle);
        $flag = $linhasAfetadas > 0 ? 'planejamento_ok' : 'planejamento_erro';
    }

    $paginaVolta = (int) ($_POST['pagina_atual'] ?? 1);
    $buscaVolta = (string) ($_POST['busca_atual'] ?? '');
    $projetoVolta = (string) ($_POST['projeto_atual'] ?? '');
    $fornecedorVolta = (string) ($_POST['fornecedor_atual'] ?? '');
    header('Location: bomnova.php?pagina=' . $paginaVolta . '&busca=' . urlencode($buscaVolta) . '&projeto=' . urlencode($projetoVolta) . '&fornecedor=' . urlencode($fornecedorVolta) . '&' . $flag . '=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo_csv'])) {
    exigirComprador();
    $arquivo = $_FILES['arquivo_csv']['tmp_name'];

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

            $cabecalho = fgetcsv($handle, 0, $separador, '"', '\\');
            if ($cabecalho === false) {
                $mensagens[] = "❌ Arquivo vazio ou inválido.";
            } else {
                // O Excel costuma gravar um BOM (marcador invisível) na primeira célula do CSV.
                if (isset($cabecalho[0])) {
                    $cabecalho[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cabecalho[0]);
                }

                $cabecalho = array_map(function($c) {
                    return strtolower(trim($c));
                }, $cabecalho);

                if (isset($_POST['limpar_tabela'])) {
                    mysqli_query($conn, "TRUNCATE TABLE bomnova");
                    $mensagens[] = "🗑️ Tabela 'bomnova' esvaziada antes da importação.";
                }

                // Insere em lotes (várias linhas por comando INSERT) para reduzir o número
                // de viagens de rede até o banco — essencial em conexões de alta latência
                // como TiDB Cloud, onde 1 INSERT por linha pode causar timeout do gateway.
                $tamanhoLote = 200;
                $lote = [];

                $flushLote = function () use ($conn, &$lote, &$importados, &$erros, &$mensagens) {
                    if (empty($lote)) {
                        return;
                    }
                    $linhasSql = [];
                    foreach ($lote as $valores) {
                        $escapados = array_map(function ($v) use ($conn) {
                            return $v === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, (string) $v) . "'";
                        }, $valores);
                        $linhasSql[] = '(' . implode(', ', $escapados) . ')';
                    }
                    $sql = "INSERT INTO bomnova (planta, projeto, material, tipo, fornecedor, codigo_componente, pn, descricao, consumo, um, net_price, ipi, pis, cofins, icms, moeda, mrp, planejamento) VALUES "
                        . implode(', ', $linhasSql);

                    if (mysqli_query($conn, $sql)) {
                        $importados += count($lote);
                    } else {
                        $erros += count($lote);
                        $mensagens[] = "⚠️ Erro ao inserir um lote de " . count($lote) . " linha(s): " . mysqli_error($conn);
                    }
                    $lote = [];
                };

                mysqli_autocommit($conn, false);

                $linhaNum = 1;
                while (($linha = fgetcsv($handle, 0, $separador, '"', '\\')) !== false) {
                    $linhaNum++;

                    if (count(array_filter($linha, fn($v) => trim((string) $v) !== '')) === 0) {
                        continue; // linha totalmente vazia (comum no fim do CSV exportado do Excel)
                    }

                    if (count($linha) !== count($cabecalho)) {
                        $erros++;
                        $mensagens[] = "⚠️ Linha $linhaNum ignorada (número de colunas não confere).";
                        continue;
                    }

                    $dados = array_combine($cabecalho, $linha);
                    // Também indexa por nome "normalizado" (sem acento, espaço, %, ponto,
                    // sublinhado...) pra aceitar cabeçalhos como "Net Price", "NET_PRICE",
                    // "IPI %", "Descrição", "U.M." — inclusive o próprio CSV exportado
                    // por esta tela, que antes não era reconhecido na reimportação.
                    $dadosNorm = [];
                    foreach ($dados as $chaveCab => $valorCab) {
                        $dadosNorm[normalizarCabecalhoBomnova((string) $chaveCab)] = $valorCab;
                    }
                    $pegar = function (string ...$nomes) use ($dados, $dadosNorm) {
                        foreach ($nomes as $nome) {
                            if (array_key_exists($nome, $dados)) { return $dados[$nome]; }
                            $n = normalizarCabecalhoBomnova($nome);
                            if (array_key_exists($n, $dadosNorm)) { return $dadosNorm[$n]; }
                        }
                        return null;
                    };

                    $planta = $dados['planta'] ?? null;
                    $projeto = $dados['projeto'] ?? null;
                    $material = $dados['material'] ?? null;
                    $tipo = $dados['tipo'] ?? null;
                    $fornecedor = $dados['fornecedor'] ?? null;
                    $codigo_componente = $pegar('codigo_componente', 'componente', 'codigo do componente', 'codigo componente', 'part number');
                    $pn = $pegar('pn');
                    $descricao = $pegar('descricao', 'description');
                    $consumoBruto = $pegar('consumo') ?? '';
                    $consumo = $consumoBruto !== '' ? parseNumeroBr((string) $consumoBruto) : null;
                    $um = $pegar('um', 'unidade');
                    // Net Price e os impostos (%) são opcionais no CSV — se não vierem,
                    // ficam em branco e podem ser digitados depois direto na tela.
                    $netPriceBruto = trim((string) ($pegar('net_price', 'net price', 'preco liquido', 'preco net') ?? ''));
                    $netPrice = $netPriceBruto !== '' ? parseNumeroBomnovaTax((string) $netPriceBruto) : null;
                    $ipiBruto = trim((string) ($pegar('ipi') ?? ''));
                    $ipi = $ipiBruto !== '' ? parseNumeroBomnovaTax((string) $ipiBruto) : null;
                    $pisBruto = trim((string) ($pegar('pis') ?? ''));
                    $pis = $pisBruto !== '' ? parseNumeroBomnovaTax((string) $pisBruto) : null;
                    $cofinsBruto = trim((string) ($pegar('cofins') ?? ''));
                    $cofins = $cofinsBruto !== '' ? parseNumeroBomnovaTax((string) $cofinsBruto) : null;
                    $icmsBruto = trim((string) ($pegar('icms') ?? ''));
                    $icms = $icmsBruto !== '' ? parseNumeroBomnovaTax((string) $icmsBruto) : null;
                    // Moeda opcional no CSV — sem ela (ou vazia), entra BRL.
                    $moeda = strtoupper(trim((string) ($pegar('moeda', 'currency') ?? '')));
                    $moeda = $moeda !== '' ? $moeda : 'BRL';
                    $mrp = $pegar('mrp');
                    if ($mrp !== null) {
                        $mrp = strtoupper(trim($mrp));
                    }
                    // Planejamento é opcional no CSV. Se mrp='N', é sempre forçado
                    // 'N' junto (não dá pra ter mrp=N com planejamento=S). Se mrp='S'
                    // e a coluna não vier no arquivo (ou vier com valor inválido),
                    // assume 'S' — comportamento igual ao que já existia antes dessa
                    // coluna existir.
                    $planejamentoBruto = $pegar('planejamento');
                    $planejamento = $planejamentoBruto !== null ? strtoupper(trim($planejamentoBruto)) : null;
                    if ($mrp === 'N') {
                        $planejamento = 'N';
                    } elseif ($planejamento !== 'S' && $planejamento !== 'N') {
                        $planejamento = 'S';
                    }

                    $lote[] = [$planta, $projeto, $material, $tipo, $fornecedor, $codigo_componente, $pn, $descricao, $consumo, $um, $netPrice, $ipi, $pis, $cofins, $icms, $moeda, $mrp, $planejamento];

                    if (count($lote) >= $tamanhoLote) {
                        $flushLote();
                    }
                }
                $flushLote();

                mysqli_commit($conn);
                mysqli_autocommit($conn, true);

                $mensagens[] = "✅ Importação concluída: $importados linha(s) importada(s), $erros erro(s).";
            }
            fclose($handle);
        }
    }
}

$porPagina = 50;
$pagina = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$offset = ($pagina - 1) * $porPagina;

$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$projetoFiltro = isset($_GET['projeto']) ? trim($_GET['projeto']) : '';
$fornecedorFiltro = isset($_GET['fornecedor']) ? trim($_GET['fornecedor']) : '';

$condicoesWhere = [];
$params = [];
$tipos = '';

if ($busca !== '') {
    $condicoesWhere[] = "(material LIKE ? OR codigo_componente LIKE ? OR descricao LIKE ? OR fornecedor LIKE ? OR projeto LIKE ?)";
    $buscaLike = "%$busca%";
    $params = array_merge($params, [$buscaLike, $buscaLike, $buscaLike, $buscaLike, $buscaLike]);
    $tipos .= 'sssss';
}
if ($projetoFiltro !== '') {
    $condicoesWhere[] = "projeto = ?";
    $params[] = $projetoFiltro;
    $tipos .= 's';
}
if ($fornecedorFiltro !== '') {
    $condicoesWhere[] = "fornecedor = ?";
    $params[] = $fornecedorFiltro;
    $tipos .= 's';
}

$where = !empty($condicoesWhere) ? ('WHERE ' . implode(' AND ', $condicoesWhere)) : '';
$temFiltro = $where !== '';

// Listas pra popular os selects de Projeto e Fornecedor — sempre traz TODOS os
// valores distintos existentes na base (não filtra pelos filtros já aplicados),
// pra não esconder opção nenhuma do dropdown enquanto o usuário troca de filtro.
$projetosDisponiveis = [];
$resProjetosDisp = mysqli_query($conn, "SELECT DISTINCT projeto FROM bomnova WHERE projeto IS NOT NULL AND projeto <> '' ORDER BY projeto");
if ($resProjetosDisp) {
    while ($linhaProjeto = mysqli_fetch_assoc($resProjetosDisp)) {
        $projetosDisponiveis[] = $linhaProjeto['projeto'];
    }
}
$fornecedoresDisponiveis = [];
$resFornecedoresDisp = mysqli_query($conn, "SELECT DISTINCT fornecedor FROM bomnova WHERE fornecedor IS NOT NULL AND fornecedor <> '' ORDER BY fornecedor");
if ($resFornecedoresDisp) {
    while ($linhaFornecedor = mysqli_fetch_assoc($resFornecedoresDisp)) {
        $fornecedoresDisponiveis[] = $linhaFornecedor['fornecedor'];
    }
}

$sqlTotal = "SELECT COUNT(*) AS total FROM bomnova $where";
if ($temFiltro) {
    $stmtTotal = mysqli_prepare($conn, $sqlTotal);
    mysqli_stmt_bind_param($stmtTotal, $tipos, ...$params);
    mysqli_stmt_execute($stmtTotal);
    $resultTotal = mysqli_stmt_get_result($stmtTotal);
} else {
    $resultTotal = mysqli_query($conn, $sqlTotal);
}
$total = mysqli_fetch_assoc($resultTotal)['total'];
$totalPaginas = max(1, ceil($total / $porPagina));

// Exportação CSV: traz TODOS os registros filtrados (ignora a paginação da tela)
if (($_GET['exportar'] ?? '') === 'csv') {
    $sqlExport = "SELECT planta, projeto, material, tipo, fornecedor, codigo_componente, pn, descricao, consumo, um, net_price, ipi, pis, cofins, icms, moeda, mrp, planejamento
                  FROM bomnova $where
                  ORDER BY projeto, material, codigo_componente";
    if ($temFiltro) {
        $stmtExport = mysqli_prepare($conn, $sqlExport);
        mysqli_stmt_bind_param($stmtExport, $tipos, ...$params);
        mysqli_stmt_execute($stmtExport);
        $resultExport = mysqli_stmt_get_result($stmtExport);
    } else {
        $resultExport = mysqli_query($conn, $sqlExport);
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="bomnova-' . date('Y-m-d-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $saida = fopen('php://output', 'w');
    fputcsv($saida, ['Planta', 'Projeto', 'Material', 'Tipo', 'Fornecedor', 'Componente', 'PN', 'Descrição', 'Consumo', 'U.M.', 'Net Price', 'IPI %', 'PIS %', 'COFINS %', 'ICMS %', 'Preço', 'Moeda', 'MRP', 'Planejamento'], ';', '"', '');
    while ($linhaExport = mysqli_fetch_assoc($resultExport)) {
        $pisExport = aplicarValorPadraoBomnova($linhaExport['pis'] ?? null, 'pis');
        $cofinsExport = aplicarValorPadraoBomnova($linhaExport['cofins'] ?? null, 'cofins');
        $precoExport = calcularPrecoBomnova(
            $linhaExport['net_price'], $linhaExport['ipi'], $pisExport, $cofinsExport, $linhaExport['icms']
        );
        fputcsv($saida, [
            $linhaExport['planta'], $linhaExport['projeto'], $linhaExport['material'], $linhaExport['tipo'],
            $linhaExport['fornecedor'], $linhaExport['codigo_componente'], $linhaExport['pn'], $linhaExport['descricao'],
            $linhaExport['consumo'], $linhaExport['um'],
            formatarNumeroBomnovaTaxExibicao($linhaExport['net_price'] ?? null, BOMNOVA_CASAS_DECIMAIS['net_price']),
            formatarNumeroBomnovaTaxExibicao($linhaExport['ipi'] ?? null, BOMNOVA_CASAS_DECIMAIS['ipi']),
            formatarNumeroBomnovaTaxExibicao($pisExport, BOMNOVA_CASAS_DECIMAIS['pis']),
            formatarNumeroBomnovaTaxExibicao($cofinsExport, BOMNOVA_CASAS_DECIMAIS['cofins']),
            formatarNumeroBomnovaTaxExibicao($linhaExport['icms'] ?? null, BOMNOVA_CASAS_DECIMAIS['icms']),
            $precoExport !== null ? number_format($precoExport, 2, ',', '') : '',
            ($linhaExport['moeda'] ?? '') !== '' ? $linhaExport['moeda'] : 'BRL',
            $linhaExport['mrp'], $linhaExport['planejamento'],
        ], ';', '"', '');
    }
    fclose($saida);
    exit;
}

$sql = "SELECT planta, projeto, material, tipo, fornecedor, codigo_componente, pn, descricao, consumo, um, net_price, ipi, pis, cofins, icms, moeda, mrp, planejamento
        FROM bomnova $where
        ORDER BY projeto, material, codigo_componente
        LIMIT ? OFFSET ?";

$stmt = mysqli_prepare($conn, $sql);
if ($temFiltro) {
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
    <title>📦 BOM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; padding: 20px; }
        .card { border-radius: 15px; box-shadow: 0 2px 20px rgba(0,0,0,0.08); }
        .bg-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important; }
        .table th { background: #f8f9fa; white-space: nowrap; }
        .table td { white-space: nowrap; }
        .badge-mrp-s { background: #eaf8f0; color: #247a4d; }
        .badge-mrp-n { background: #fff0f0; color: #c53535; }
        .mrp-toggle-btn {
            cursor: pointer;
            font: inherit;
            transition: opacity 0.15s, transform 0.1s;
        }
        .mrp-toggle-btn:hover { opacity: 0.75; }
        .mrp-toggle-btn:active { transform: scale(0.95); }
        summary { cursor: pointer; font-weight: 700; color: #405164; }

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
    <div class="container-fluid" style="max-width: 98vw;">
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
            <h1>📦 BOM</h1>
            <p class="mb-0"><?php echo number_format($total, 0, ',', '.'); ?> registro(s) na base</p>
        </div>

        <?php if (isset($_GET['mrp_ok'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                ✅ MRP atualizado.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
            </div>
        <?php elseif (isset($_GET['mrp_erro'])): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                ⚠️ Não encontrei essa linha exata pra atualizar (os dados podem ter mudado desde que a página carregou — recarregue e tente de novo).
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
            </div>
        <?php elseif (isset($_GET['planejamento_ok'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                ✅ Planejamento atualizado.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
            </div>
        <?php elseif (isset($_GET['planejamento_erro'])): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                ⚠️ Não encontrei essa linha exata pra atualizar (os dados podem ter mudado desde que a página carregou — recarregue e tente de novo).
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
            </div>
        <?php elseif (isset($_GET['planejamento_bloqueado'])): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                ⚠️ Esse componente está com MRP=N — Planejamento já está travado em N junto. Reabra o MRP primeiro se quiser mudar isso.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
            </div>
        <?php elseif (isset($_GET['excluido'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                ✅ Linha excluída do BOM.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
            </div>
        <?php elseif (isset($_GET['excluir_erro'])): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                ⚠️ Não encontrei essa linha exata pra excluir (os dados podem ter mudado desde que a página carregou — recarregue e tente de novo).
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
            </div>
        <?php endif; ?>

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

                    <form method="POST" enctype="multipart/form-data" class="row g-2 align-items-end">
                        <div class="col-12 col-md-6">
                            <label class="form-label">Arquivo CSV</label>
                            <input type="file" name="arquivo_csv" accept=".csv" class="form-control" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="limpar_tabela" id="limpar_tabela">
                                <label class="form-check-label" for="limpar_tabela">
                                    Limpar tabela antes de importar
                                </label>
                            </div>
                        </div>
                        <div class="col-12 col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Importar</button>
                        </div>
                    </form>

                    <hr>
                    <small class="text-muted">
                        <strong>Colunas esperadas no CSV</strong> (primeira linha = cabeçalho, qualquer ordem):<br>
                        <code>planta, projeto, material, tipo, fornecedor, codigo_componente, pn, descricao, consumo, um, mrp</code><br>
                        A coluna <code>mrp</code> é opcional: use <code>S</code> para componente ativo (conta no cálculo de demanda) ou <code>N</code> para substituído (fica só como histórico, não conta no cálculo). Se não vier no CSV, é tratado como ativo. Você também pode clicar direto no badge S/N na tabela abaixo pra alternar, sem precisar reimportar o CSV.<br>
                        A coluna <code>moeda</code> é opcional — sem ela, entra <strong>BRL</strong> (dá pra trocar depois com duplo clique).<br>
                        As colunas <code>net_price</code> (ou <code>Net Price</code>), <code>ipi, pis, cofins, icms</code> (aceita também <code>IPI %</code> etc.) também são opcionais no CSV — se não vierem, ficam em branco e dá pra digitar direto na tela (duplo clique). O <strong>Preço</strong> é sempre calculado automaticamente a partir delas, nunca é importado nem digitado diretamente.<br>
                        Separador: vírgula ou ponto e vírgula (detectado automaticamente).
                    </small>
                </div>
            </details>
        </div>

        <div class="card p-3 mb-4">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-12 col-md flex-grow-1">
                    <label class="form-label small text-muted mb-1">Busca livre</label>
                    <input type="text" name="busca" class="form-control" placeholder="Buscar por material, componente, descrição, fornecedor ou projeto..." value="<?php echo htmlspecialchars($busca); ?>">
                </div>
                <div class="col-12 col-md-auto" style="min-width: 200px;">
                    <label class="form-label small text-muted mb-1">Projeto</label>
                    <select name="projeto" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($projetosDisponiveis as $opcaoProjeto): ?>
                            <option value="<?php echo htmlspecialchars($opcaoProjeto); ?>" <?php echo $projetoFiltro === $opcaoProjeto ? 'selected' : ''; ?>><?php echo htmlspecialchars($opcaoProjeto); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-auto" style="min-width: 200px;">
                    <label class="form-label small text-muted mb-1">Fornecedor</label>
                    <select name="fornecedor" class="form-select">
                        <option value="">Todos</option>
                        <?php foreach ($fornecedoresDisponiveis as $opcaoFornecedor): ?>
                            <option value="<?php echo htmlspecialchars($opcaoFornecedor); ?>" <?php echo $fornecedorFiltro === $opcaoFornecedor ? 'selected' : ''; ?>><?php echo htmlspecialchars($opcaoFornecedor); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-auto">
                    <button type="submit" class="btn btn-primary">Buscar</button>
                    <a href="bomnova.php" class="btn btn-outline-secondary">Limpar</a>
                    <a href="?busca=<?php echo urlencode($busca); ?>&projeto=<?php echo urlencode($projetoFiltro); ?>&fornecedor=<?php echo urlencode($fornecedorFiltro); ?>&exportar=csv" class="btn btn-outline-primary">Exportar CSV</a>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-body table-responsive">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Planta</th>
                            <th>Projeto</th>
                            <th>Material</th>
                            <th>Tipo</th>
                            <th>Fornecedor</th>
                            <th>Componente</th>
                            <th>PN</th>
                            <th>Descrição</th>
                            <th class="text-end">Consumo</th>
                            <th>U.M.</th>
                            <th class="text-end">Net Price</th>
                            <th class="text-end">IPI %</th>
                            <th class="text-end">PIS %</th>
                            <th class="text-end">COFINS %</th>
                            <th class="text-end">ICMS %</th>
                            <th class="text-end" title="Calculado automaticamente: [Net Price ÷ (100% − (ICMS% + (PIS% − ICMS%×PIS%) + (COFINS% − ICMS%×COFINS%)))] × (1 + IPI%)">Preço</th>
                            <th>Moeda</th>
                            <th>MRP</th>
                            <th>Planejamento</th>
                            <th title="Excluir">Excluir</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="20" class="text-center text-muted">Nenhum registro encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                    $mrp = strtoupper(trim((string) ($row['mrp'] ?? '')));
                                    $badgeClasse = $mrp === 'N' ? 'badge-mrp-n' : ($mrp === 'S' ? 'badge-mrp-s' : 'text-bg-secondary');
                                    $badgeTexto = $mrp !== '' ? $mrp : '—';
                                    $planejamento = strtoupper(trim((string) ($row['planejamento'] ?? '')));
                                    // Sem valor gravado ainda (linha antiga, pré-migração) — trata como
                                    // "S" quando mrp=S (comportamento igual ao que já era antes dessa
                                    // coluna existir), ou "N" quando mrp=N (força a regra).
                                    if ($planejamento !== 'S' && $planejamento !== 'N') {
                                        $planejamento = $mrp === 'N' ? 'N' : 'S';
                                    }
                                    $planBadgeClasse = $planejamento === 'N' ? 'badge-mrp-n' : 'badge-mrp-s';
                                ?>
                                <?php
                                    $contextoLinha = json_encode([
                                        'planta' => (string) ($row['planta'] ?? ''),
                                        'projeto' => (string) ($row['projeto'] ?? ''),
                                        'material' => (string) ($row['material'] ?? ''),
                                        'tipo' => (string) ($row['tipo'] ?? ''),
                                        'fornecedor' => (string) ($row['fornecedor'] ?? ''),
                                        'codigo_componente' => (string) ($row['codigo_componente'] ?? ''),
                                        'pn' => (string) ($row['pn'] ?? ''),
                                        'descricao' => (string) ($row['descricao'] ?? ''),
                                        'consumo' => (string) ($row['consumo'] ?? ''),
                                        'um' => (string) ($row['um'] ?? ''),
                                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                                ?>
                                <tr>
                                    <td class="celula-editavel" data-campo="planta" data-valor-bruto="<?php echo htmlspecialchars($row['planta'] ?? ''); ?>" data-extra='<?php echo $contextoLinha; ?>'><?php echo htmlspecialchars($row['planta'] ?? ''); ?></td>
                                    <td class="celula-editavel" data-campo="projeto" data-valor-bruto="<?php echo htmlspecialchars($row['projeto'] ?? ''); ?>" data-extra='<?php echo $contextoLinha; ?>'><?php echo htmlspecialchars($row['projeto'] ?? ''); ?></td>
                                    <td class="celula-editavel" data-campo="material" data-valor-bruto="<?php echo htmlspecialchars($row['material'] ?? ''); ?>" data-extra='<?php echo $contextoLinha; ?>'><?php echo htmlspecialchars($row['material'] ?? ''); ?></td>
                                    <td class="celula-editavel" data-campo="tipo" data-valor-bruto="<?php echo htmlspecialchars($row['tipo'] ?? ''); ?>" data-extra='<?php echo $contextoLinha; ?>'><?php echo htmlspecialchars($row['tipo'] ?? ''); ?></td>
                                    <td class="celula-editavel" data-campo="fornecedor" data-valor-bruto="<?php echo htmlspecialchars($row['fornecedor'] ?? ''); ?>" data-extra='<?php echo $contextoLinha; ?>'><?php echo htmlspecialchars($row['fornecedor'] ?? ''); ?></td>
                                    <td class="celula-editavel" data-campo="codigo_componente" data-valor-bruto="<?php echo htmlspecialchars($row['codigo_componente'] ?? ''); ?>" data-extra='<?php echo $contextoLinha; ?>'><strong><?php echo htmlspecialchars($row['codigo_componente'] ?? ''); ?></strong></td>
                                    <td class="celula-editavel" data-campo="pn" data-valor-bruto="<?php echo htmlspecialchars($row['pn'] ?? ''); ?>" data-extra='<?php echo $contextoLinha; ?>'><?php echo htmlspecialchars($row['pn'] ?? ''); ?></td>
                                    <td class="celula-editavel" data-campo="descricao" data-valor-bruto="<?php echo htmlspecialchars($row['descricao'] ?? ''); ?>" data-extra='<?php echo $contextoLinha; ?>'><?php echo htmlspecialchars($row['descricao'] ?? ''); ?></td>
                                    <td class="text-end celula-editavel" data-campo="consumo" data-valor-bruto="<?php echo htmlspecialchars($row['consumo'] ?? ''); ?>" data-extra='<?php echo $contextoLinha; ?>'><?php echo htmlspecialchars($row['consumo'] ?? ''); ?></td>
                                    <td class="celula-editavel" data-campo="um" data-valor-bruto="<?php echo htmlspecialchars($row['um'] ?? ''); ?>" data-extra='<?php echo $contextoLinha; ?>'><?php echo htmlspecialchars($row['um'] ?? ''); ?></td>
                                    <?php
                                        // PIS e COFINS: se vierem em branco do banco, usa o valor padrão
                                        // (1,65% e 7,60%) tanto na exibição quanto no cálculo do Preço.
                                        // Continua editável por duplo clique — o padrão só preenche o
                                        // vazio, nunca sobrescreve um valor já gravado na linha.
                                        $pisComPadrao = aplicarValorPadraoBomnova($row['pis'] ?? null, 'pis');
                                        $cofinsComPadrao = aplicarValorPadraoBomnova($row['cofins'] ?? null, 'cofins');
                                        $netPriceExibir = formatarNumeroBomnovaTaxExibicao($row['net_price'] ?? null, BOMNOVA_CASAS_DECIMAIS['net_price']);
                                        $ipiExibir = formatarNumeroBomnovaTaxExibicao($row['ipi'] ?? null, BOMNOVA_CASAS_DECIMAIS['ipi']);
                                        $pisExibir = formatarNumeroBomnovaTaxExibicao($pisComPadrao, BOMNOVA_CASAS_DECIMAIS['pis']);
                                        $cofinsExibir = formatarNumeroBomnovaTaxExibicao($cofinsComPadrao, BOMNOVA_CASAS_DECIMAIS['cofins']);
                                        $icmsExibir = formatarNumeroBomnovaTaxExibicao($row['icms'] ?? null, BOMNOVA_CASAS_DECIMAIS['icms']);
                                    ?>
                                    <td class="text-end celula-editavel" data-campo="net_price" data-valor-bruto="<?php echo htmlspecialchars($netPriceExibir); ?>" data-extra='<?php echo $contextoLinha; ?>' title="Duplo clique para editar"><?php echo htmlspecialchars($netPriceExibir); ?></td>
                                    <td class="text-end celula-editavel" data-campo="ipi" data-valor-bruto="<?php echo htmlspecialchars($ipiExibir); ?>" data-extra='<?php echo $contextoLinha; ?>' title="Duplo clique para editar"><?php echo htmlspecialchars($ipiExibir); ?></td>
                                    <td class="text-end celula-editavel" data-campo="pis" data-valor-bruto="<?php echo htmlspecialchars($pisExibir); ?>" data-extra='<?php echo $contextoLinha; ?>' title="Duplo clique para editar"><?php echo htmlspecialchars($pisExibir); ?></td>
                                    <td class="text-end celula-editavel" data-campo="cofins" data-valor-bruto="<?php echo htmlspecialchars($cofinsExibir); ?>" data-extra='<?php echo $contextoLinha; ?>' title="Duplo clique para editar"><?php echo htmlspecialchars($cofinsExibir); ?></td>
                                    <td class="text-end celula-editavel" data-campo="icms" data-valor-bruto="<?php echo htmlspecialchars($icmsExibir); ?>" data-extra='<?php echo $contextoLinha; ?>' title="Duplo clique para editar"><?php echo htmlspecialchars($icmsExibir); ?></td>
                                    <?php
                                        $precoLinha = calcularPrecoBomnova($row['net_price'] ?? null, $row['ipi'] ?? null, $pisComPadrao, $cofinsComPadrao, $row['icms'] ?? null);
                                    ?>
                                    <td class="text-end text-muted js-preco-bomnova" title="Calculado automaticamente: [Net Price ÷ (100% − (ICMS% + (PIS% − ICMS%×PIS%) + (COFINS% − ICMS%×COFINS%)))] × (1 + IPI%)"><?php echo $precoLinha !== null ? number_format($precoLinha, 2, ',', '.') : '—'; ?></td>
                                    <?php $moedaLinha = ($row['moeda'] ?? '') !== '' ? $row['moeda'] : 'BRL'; ?>
                                    <td class="celula-editavel" data-campo="moeda" data-valor-bruto="<?php echo htmlspecialchars($moedaLinha); ?>" data-extra='<?php echo $contextoLinha; ?>' title="Duplo clique para editar"><?php echo htmlspecialchars($moedaLinha); ?></td>
                                    <td>
                                        <form method="POST" class="d-inline m-0">
                                            <input type="hidden" name="acao" value="toggle_mrp">
                                            <input type="hidden" name="mrp_atual" value="<?php echo htmlspecialchars($mrp); ?>">
                                            <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                                            <input type="hidden" name="busca_atual" value="<?php echo htmlspecialchars($busca); ?>">
                                            <input type="hidden" name="projeto_atual" value="<?php echo htmlspecialchars($projetoFiltro); ?>">
                                            <input type="hidden" name="fornecedor_atual" value="<?php echo htmlspecialchars($fornecedorFiltro); ?>">
                                            <input type="hidden" name="orig_planta" value="<?php echo htmlspecialchars($row['planta'] ?? ''); ?>">
                                            <input type="hidden" name="orig_projeto" value="<?php echo htmlspecialchars($row['projeto'] ?? ''); ?>">
                                            <input type="hidden" name="orig_material" value="<?php echo htmlspecialchars($row['material'] ?? ''); ?>">
                                            <input type="hidden" name="orig_tipo" value="<?php echo htmlspecialchars($row['tipo'] ?? ''); ?>">
                                            <input type="hidden" name="orig_fornecedor" value="<?php echo htmlspecialchars($row['fornecedor'] ?? ''); ?>">
                                            <input type="hidden" name="orig_codigo_componente" value="<?php echo htmlspecialchars($row['codigo_componente'] ?? ''); ?>">
                                            <input type="hidden" name="orig_pn" value="<?php echo htmlspecialchars($row['pn'] ?? ''); ?>">
                                            <input type="hidden" name="orig_descricao" value="<?php echo htmlspecialchars($row['descricao'] ?? ''); ?>">
                                            <input type="hidden" name="orig_consumo" value="<?php echo htmlspecialchars($row['consumo'] ?? ''); ?>">
                                            <input type="hidden" name="orig_um" value="<?php echo htmlspecialchars($row['um'] ?? ''); ?>">
                                            <button type="submit" class="badge border-0 mrp-toggle-btn <?php echo $badgeClasse; ?>" title="Clique pra alternar entre S (conta no cálculo) e N (só histórico, fica de fora do estoque/planejamento/evolução)">
                                                <?php echo htmlspecialchars($badgeTexto); ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        <?php
                                            // Sempre o mesmo botão (nunca troca pra <span>) — com MRP=N ele só
                                            // fica desabilitado. Isso permite o JS trocar texto/classe/estado
                                            // via AJAX sem precisar reconstruir a célula inteira.
                                            $planBloqueado = $mrp === 'N';
                                            $planTitle = $planBloqueado
                                                ? 'MRP=N já força Planejamento=N — reabra o MRP primeiro pra poder mudar isso'
                                                : "Clique pra alternar — 'N' tira o componente do Dashboard e do Planejamento de Compras, mas ele continua aparecendo na Evolução Geral";
                                        ?>
                                        <form method="POST" class="d-inline m-0">
                                            <input type="hidden" name="acao" value="toggle_planejamento">
                                            <input type="hidden" name="mrp_atual" value="<?php echo htmlspecialchars($mrp); ?>">
                                            <input type="hidden" name="planejamento_atual" value="<?php echo htmlspecialchars($planejamento); ?>">
                                            <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                                            <input type="hidden" name="busca_atual" value="<?php echo htmlspecialchars($busca); ?>">
                                            <input type="hidden" name="projeto_atual" value="<?php echo htmlspecialchars($projetoFiltro); ?>">
                                            <input type="hidden" name="fornecedor_atual" value="<?php echo htmlspecialchars($fornecedorFiltro); ?>">
                                            <input type="hidden" name="orig_planta" value="<?php echo htmlspecialchars($row['planta'] ?? ''); ?>">
                                            <input type="hidden" name="orig_projeto" value="<?php echo htmlspecialchars($row['projeto'] ?? ''); ?>">
                                            <input type="hidden" name="orig_material" value="<?php echo htmlspecialchars($row['material'] ?? ''); ?>">
                                            <input type="hidden" name="orig_tipo" value="<?php echo htmlspecialchars($row['tipo'] ?? ''); ?>">
                                            <input type="hidden" name="orig_fornecedor" value="<?php echo htmlspecialchars($row['fornecedor'] ?? ''); ?>">
                                            <input type="hidden" name="orig_codigo_componente" value="<?php echo htmlspecialchars($row['codigo_componente'] ?? ''); ?>">
                                            <input type="hidden" name="orig_pn" value="<?php echo htmlspecialchars($row['pn'] ?? ''); ?>">
                                            <input type="hidden" name="orig_descricao" value="<?php echo htmlspecialchars($row['descricao'] ?? ''); ?>">
                                            <input type="hidden" name="orig_consumo" value="<?php echo htmlspecialchars($row['consumo'] ?? ''); ?>">
                                            <input type="hidden" name="orig_um" value="<?php echo htmlspecialchars($row['um'] ?? ''); ?>">
                                            <button type="submit" class="badge border-0 mrp-toggle-btn <?php echo $planBadgeClasse; ?>" <?php echo $planBloqueado ? 'disabled' : ''; ?> title="<?php echo htmlspecialchars($planTitle); ?>">
                                                <?php echo htmlspecialchars($planejamento); ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="POST" class="d-inline m-0" onsubmit="return confirm('Excluir esta linha do BOM? Apaga só esta combinação (planta/projeto/material). O histórico de compras não é afetado. Essa ação não pode ser desfeita.');">
                                            <input type="hidden" name="acao" value="excluir_linha_bomnova">
                                            <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                                            <input type="hidden" name="busca_atual" value="<?php echo htmlspecialchars($busca); ?>">
                                            <input type="hidden" name="projeto_atual" value="<?php echo htmlspecialchars($projetoFiltro); ?>">
                                            <input type="hidden" name="fornecedor_atual" value="<?php echo htmlspecialchars($fornecedorFiltro); ?>">
                                            <input type="hidden" name="orig_planta" value="<?php echo htmlspecialchars($row['planta'] ?? ''); ?>">
                                            <input type="hidden" name="orig_projeto" value="<?php echo htmlspecialchars($row['projeto'] ?? ''); ?>">
                                            <input type="hidden" name="orig_material" value="<?php echo htmlspecialchars($row['material'] ?? ''); ?>">
                                            <input type="hidden" name="orig_tipo" value="<?php echo htmlspecialchars($row['tipo'] ?? ''); ?>">
                                            <input type="hidden" name="orig_fornecedor" value="<?php echo htmlspecialchars($row['fornecedor'] ?? ''); ?>">
                                            <input type="hidden" name="orig_codigo_componente" value="<?php echo htmlspecialchars($row['codigo_componente'] ?? ''); ?>">
                                            <input type="hidden" name="orig_pn" value="<?php echo htmlspecialchars($row['pn'] ?? ''); ?>">
                                            <input type="hidden" name="orig_descricao" value="<?php echo htmlspecialchars($row['descricao'] ?? ''); ?>">
                                            <input type="hidden" name="orig_consumo" value="<?php echo htmlspecialchars($row['consumo'] ?? ''); ?>">
                                            <input type="hidden" name="orig_um" value="<?php echo htmlspecialchars($row['um'] ?? ''); ?>">
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
                    <a href="?pagina=<?php echo $pagina - 1; ?>&busca=<?php echo urlencode($busca); ?>&projeto=<?php echo urlencode($projetoFiltro); ?>&fornecedor=<?php echo urlencode($fornecedorFiltro); ?>" class="btn btn-outline-primary btn-sm">← Anterior</a>
                <?php endif; ?>
            </div>
            <div class="text-muted">Página <?php echo $pagina; ?> de <?php echo $totalPaginas; ?></div>
            <div>
                <?php if ($pagina < $totalPaginas): ?>
                    <a href="?pagina=<?php echo $pagina + 1; ?>&busca=<?php echo urlencode($busca); ?>&projeto=<?php echo urlencode($projetoFiltro); ?>&fornecedor=<?php echo urlencode($fornecedorFiltro); ?>" class="btn btn-outline-primary btn-sm">Próxima →</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="text-center mt-4">
            <a href="index.php" class="btn btn-outline-secondary">Voltar ao Dashboard</a>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // MRP e Planejamento agora trocam a badge por AJAX (sem recarregar a
        // página). Alternar o MRP também pode mudar o Planejamento junto (a
        // regra "MRP=N força Planejamento=N"), então o retorno do servidor
        // já traz o estado das duas badges pra atualizar as duas de uma vez.
        document.addEventListener('submit', function (evento) {
            const form = evento.target;
            const acaoInput = form.querySelector('input[name="acao"]');
            if (!acaoInput) return;
            const acao = acaoInput.value;

            if (acao !== 'toggle_mrp' && acao !== 'toggle_planejamento') {
                return;
            }

            evento.preventDefault();
            const dados = new URLSearchParams(new FormData(form));
            dados.set('acao', 'ajax_' + acao);

            fetch('bomnova.php', {
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
                    const linha = form.closest('tr');
                    if (!linha) return;

                    const formMrp = linha.querySelector('input[value="toggle_mrp"]')?.closest('form');
                    const formPlan = linha.querySelector('input[value="toggle_planejamento"]')?.closest('form');

                    if (formMrp) {
                        const botaoMrp = formMrp.querySelector('button');
                        botaoMrp.textContent = json.mrpTexto;
                        botaoMrp.classList.remove('badge-mrp-s', 'badge-mrp-n');
                        botaoMrp.classList.add(json.mrpClasse);
                        formMrp.querySelector('input[name="mrp_atual"]').value = json.mrpTexto;
                    }
                    if (formPlan) {
                        const botaoPlan = formPlan.querySelector('button');
                        botaoPlan.textContent = json.planTexto;
                        botaoPlan.classList.remove('badge-mrp-s', 'badge-mrp-n');
                        botaoPlan.classList.add(json.planClasse);
                        botaoPlan.disabled = json.planBloqueado;
                        botaoPlan.title = json.planBloqueado
                            ? 'MRP=N já força Planejamento=N — reabra o MRP primeiro pra poder mudar isso'
                            : "Clique pra alternar — 'N' tira o componente do Dashboard e do Planejamento de Compras, mas ele continua aparecendo na Evolução Geral";
                        formPlan.querySelector('input[name="mrp_atual"]').value = json.mrpTexto;
                        formPlan.querySelector('input[name="planejamento_atual"]').value = json.planTexto;
                    }
                })
                .catch(() => {
                    alert('Erro de conexão ao atualizar. Tente de novo.');
                });
        });
    </script>
    <script>
        window.INLINE_EDIT_ENDPOINT = 'bomnova.php';
        window.INLINE_EDIT_ACAO = 'ajax_editar_campo';
    </script>
    <script src="assets/inline-edit.js"></script>
    <script>
        // A BOM não tem coluna id: cada linha é achada no banco pela combinação
        // de Planta, Projeto, Material, Tipo, Fornecedor, Componente, PN,
        // Descrição, Consumo e U.M. Quando um DESSES campos é editado, a "chave"
        // da linha muda — então aqui atualiza, na mesma linha da tela, o contexto
        // (data-extra) das outras células e os campos orig_* dos botões (MRP,
        // Planejamento, Excluir). Sem isso, a 2ª edição/clique na mesma linha
        // dava "Não achei essa linha exata" até recarregar a página.
        document.addEventListener('inline-edit:salvo', function (evento) {
            const campo = evento.detail && evento.detail.campo;
            const camposChave = ['planta', 'projeto', 'material', 'tipo', 'fornecedor', 'codigo_componente', 'pn', 'descricao', 'consumo', 'um'];
            if (!camposChave.includes(campo)) return;
            const linha = evento.target.closest('tr');
            if (!linha) return;
            const celulaEditada = linha.querySelector('td[data-campo="' + campo + '"]');
            if (!celulaEditada) return;
            // "exibido" = valor como ficou gravado no banco (no Consumo pode ser
            // diferente do digitado, ex.: "1,5" -> "1.5000"); é esse que identifica a linha.
            const novoValor = String(evento.detail.exibido ?? celulaEditada.textContent).trim();
            celulaEditada.dataset.valorBruto = novoValor;

            linha.querySelectorAll('td[data-extra]').forEach(function (celula) {
                try {
                    const contexto = JSON.parse(celula.dataset.extra || '{}');
                    contexto[campo] = novoValor;
                    celula.dataset.extra = JSON.stringify(contexto);
                } catch (e) { /* contexto ilegível — deixa como está */ }
            });
            linha.querySelectorAll('input[name="orig_' + campo + '"]').forEach(function (input) {
                input.value = novoValor;
            });
        });
    </script>
    <script>
        // A edição por duplo clique (assets/inline-edit.js) só atualiza a célula
        // que foi editada — não sabe que o Preço de uma linha depende de outras
        // 5 células dela (Net Price, IPI, PIS, COFINS, ICMS). Por isso, esse
        // observer recalcula o Preço na hora, no navegador, sempre que qualquer
        // uma dessas 5 células muda de valor — usando a mesma fórmula do PHP.
        window.addEventListener('DOMContentLoaded', function () {
            function parseNumeroBr(texto) {
                if (texto === null || texto === undefined) return null;
                texto = texto.trim();
                if (texto === '' || texto === '—') return null;
                // Net Price/IPI/PIS/COFINS/ICMS nunca têm milhar aqui — um ponto sozinho
                // (sem vírgula) É o separador decimal, nunca deve ser removido. Só quando
                // há vírgula é que um ponto antes dela vira separador de milhar.
                const limpo = texto.includes(',')
                    ? texto.replace(/\./g, '').replace(',', '.')
                    : texto;
                const numero = parseFloat(limpo);
                return isNaN(numero) ? null : numero;
            }
            function formatarNumeroBr(numero) {
                return numero.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
            document.querySelectorAll('tbody tr').forEach(function (linha) {
                const celulaNetPrice = linha.querySelector('td[data-campo="net_price"]');
                const celulaPreco = linha.querySelector('td.js-preco-bomnova');
                if (!celulaNetPrice || !celulaPreco) return;

                const celulaIpi = linha.querySelector('td[data-campo="ipi"]');
                const celulaPis = linha.querySelector('td[data-campo="pis"]');
                const celulaCofins = linha.querySelector('td[data-campo="cofins"]');
                const celulaIcms = linha.querySelector('td[data-campo="icms"]');

                function recalcular() {
                    const netPrice = parseNumeroBr(celulaNetPrice.textContent);
                    if (netPrice === null) {
                        celulaPreco.textContent = '—';
                        return;
                    }
                    const ipi = parseNumeroBr(celulaIpi ? celulaIpi.textContent : null) || 0;
                    const pis = parseNumeroBr(celulaPis ? celulaPis.textContent : null) || 0;
                    const cofins = parseNumeroBr(celulaCofins ? celulaCofins.textContent : null) || 0;
                    const icms = parseNumeroBr(celulaIcms ? celulaIcms.textContent : null) || 0;
                    const icmsFr = icms / 100;
                    const pisFr = pis / 100;
                    const cofinsFr = cofins / 100;
                    const ipiFr = ipi / 100;
                    // Gross-up: Preço = [NetPrice ÷ (100% − (ICMS% + (PIS% − ICMS%×PIS%) + (COFINS% − ICMS%×COFINS%)))] × (1 + IPI%)
                    const divisor = 1 - (icmsFr + (pisFr - icmsFr * pisFr) + (cofinsFr - icmsFr * cofinsFr));
                    if (Math.abs(divisor) < 0.0000001) {
                        celulaPreco.textContent = '—';
                        return;
                    }
                    const preco = (netPrice / divisor) * (1 + ipiFr);
                    celulaPreco.textContent = formatarNumeroBr(preco);
                }

                [celulaNetPrice, celulaIpi, celulaPis, celulaCofins, celulaIcms].forEach(function (celula) {
                    if (!celula) return;
                    new MutationObserver(recalcular).observe(celula, { childList: true, characterData: true, subtree: true });
                });
            });
        });
    </script>
    <script>
        // Exclusão recarrega a página (a linha some da tabela, então não há
        // linha pra manter na tela), mas guarda a posição do scroll antes de
        // enviar e restaura depois do reload, pra não voltar pro topo.
        document.addEventListener('submit', function (evento) {
            const form = evento.target;
            const acaoInput = form.querySelector('input[name="acao"]');
            if (acaoInput && acaoInput.value === 'excluir_linha_bomnova') {
                sessionStorage.setItem('bomnova_scroll', String(window.scrollY));
            }
        });
        window.addEventListener('DOMContentLoaded', function () {
            const scrollSalvo = sessionStorage.getItem('bomnova_scroll');
            if (scrollSalvo !== null) {
                window.scrollTo(0, parseInt(scrollSalvo, 10) || 0);
                sessionStorage.removeItem('bomnova_scroll');
            }
        });
    </script>
</body>
</html>