<?php
require_once 'conexao.php';

function h(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function numeroBr($valor, int $decimais = 2): string
{
    return number_format((float) $valor, $decimais, ',', '.');
}

// Classifica o componente dentro da janela de curto prazo (hoje até hoje+diasJanela),
// usando os limites Min/Max (em dias de cobertura) convertidos em quantidade através da
// demanda "local" (próximos 60 dias a partir de hoje — não o horizonte inteiro, pra não
// diluir a taxa com demanda distante e gerar alarme falso ou nunca alertar excesso).
//
// Estoque de segurança: piso calculado automaticamente (ver calcularEstoqueSegurancaQtd)
// que nunca deveria ser furado em condições normais. O gatilho de "crítico" dispara
// quando o saldo projetado cai abaixo desse piso, não só quando fica negativo — ou seja,
// soa ANTES do estoque realmente zerar. Se o cálculo resultar em 0 (sem setup cadastrado,
// por exemplo), o comportamento é idêntico ao de antes (piso = 0).
function calcularStatusJanela(
    float $estoqueAtual,
    array $programacaoPorData,
    array $demandaPorData,
    DateTimeImmutable $hoje,
    int $diasJanela,
    int $minDias,
    int $maxDias,
    float $segurancaQtd = 0.0
): array {
    $hojeChave = $hoje->format('Y-m-d');

    $entradaAtrasada = 0.0;
    foreach ($programacaoPorData as $d => $q) {
        if ($d < $hojeChave) { $entradaAtrasada += $q; }
    }
    $saidaAtrasada = 0.0;
    foreach ($demandaPorData as $d => $q) {
        if ($d < $hojeChave) { $saidaAtrasada += $q; }
    }

    $saldoAnterior = $estoqueAtual + $entradaAtrasada - $saidaAtrasada;
    $minSaldo = $saldoAnterior;
    $maxSaldo = $saldoAnterior;

    $cursor = $hoje;
    $fimJanela = $hoje->modify("+{$diasJanela} days");
    while ($cursor <= $fimJanela) {
        $chave = $cursor->format('Y-m-d');
        $entrada = $programacaoPorData[$chave] ?? 0.0;
        $saida = $demandaPorData[$chave] ?? 0.0;
        $saldoAnterior = $saldoAnterior + $entrada - $saida;
        $minSaldo = min($minSaldo, $saldoAnterior);
        $maxSaldo = max($maxSaldo, $saldoAnterior);
        $cursor = $cursor->modify('+1 day');
    }

    // Min/Max em quantidade = soma direta de toda demanda JÁ CONHECIDA dentro dos
    // respectivos períodos (sem inventar uma taxa diária média, que distorce muito
    // quando a demanda vem em picos, como é o caso do EDI).
    $fimMinChave = $hoje->modify("+{$minDias} days")->format('Y-m-d');
    $fimMaxChave = $hoje->modify("+{$maxDias} days")->format('Y-m-d');
    $minQtd = 0.0;
    $maxQtd = 0.0;
    foreach ($demandaPorData as $d => $q) {
        if ($d < $hojeChave) {
            continue; // atrasado já foi absorvido no saldo inicial, não conta de novo aqui
        }
        if ($d <= $fimMinChave) {
            $minQtd += $q;
        }
        if ($d <= $fimMaxChave) {
            $maxQtd += $q;
        }
    }

    if ($minSaldo < $segurancaQtd) {
        $status = 'critico';
    } elseif ($minSaldo < $minQtd) {
        $status = 'atencao';
    } elseif ($maxQtd > 0 && $maxSaldo > $maxQtd) {
        $status = 'excesso';
    } else {
        $status = 'ok';
    }

    return ['status' => $status, 'min_saldo' => $minSaldo, 'max_saldo' => $maxSaldo, 'min_qtd' => $minQtd, 'max_qtd' => $maxQtd, 'seguranca_qtd' => $segurancaQtd];
}

// Calcula o estoque de segurança automaticamente, sem exigir nenhum dia cadastrado
// manualmente. Fórmula: Z × desvio-padrão da demanda semanal × raiz(lead time em semanas).
//   - Demanda semanal média = soma da demanda EDI dos próximos 90 dias (já convertida por
//     BOM.consumo, igual a $demandaPorData) dividida por 90 (média diária) × 7.
//   - Desvio-padrão = demanda semanal média × (setup% / 100). Aqui o setup (percentual de
//     perda/scrap já cadastrado em Parâmetros de Compra) é usado como proxy da variabilidade
//     da demanda — quanto maior o setup, maior a oscilação considerada. Com setup = 0%,
//     desvio-padrão = 0 e o estoque de segurança calculado é 0 (sem margem extra).
//   - Lead time em semanas = (Lead Time + Transit Time, em dias) / 7.
// O nível de serviço (Z) é definido uma vez em conexao.php (MRP_Z_NIVEL_SERVICO).
function calcularEstoqueSegurancaQtd(
    array $demandaPorData,
    DateTimeImmutable $hoje,
    int $frozenDias,
    int $transitDias,
    float $setupPercentual
): float {
    if ($setupPercentual <= 0 || ($frozenDias + $transitDias) <= 0) {
        return 0.0;
    }

    $hojeChave = $hoje->format('Y-m-d');
    $fimJanela90 = $hoje->modify('+90 days')->format('Y-m-d');
    $demanda90Dias = 0.0;
    foreach ($demandaPorData as $d => $q) {
        if ($d >= $hojeChave && $d <= $fimJanela90) {
            $demanda90Dias += $q;
        }
    }

    $demandaSemanalMedia = ($demanda90Dias / 90) * 7;
    if ($demandaSemanalMedia <= 0) {
        return 0.0;
    }

    $desvioPadrao = $demandaSemanalMedia * ($setupPercentual / 100);
    $leadTimeSemanas = ($frozenDias + $transitDias) / 7;

    return MRP_Z_NIVEL_SERVICO * $desvioPadrao * sqrt($leadTimeSemanas);
}

function vincularParametros(mysqli_stmt $stmt, string $tipos, array &$parametros): void
{
    if ($tipos === '') {
        return;
    }

    $referencias = [$tipos];
    foreach ($parametros as $indice => $valor) {
        $referencias[] = &$parametros[$indice];
    }
    call_user_func_array([$stmt, 'bind_param'], $referencias);
}

// Simula o saldo dia a dia (estoque + programação - demanda, na ordem cronológica de cada
// evento) e procura o primeiro dia em que o saldo fica abaixo do piso de proteção
// (estoque de segurança, calculado automaticamente — ver calcularEstoqueSegurancaQtd —
// ou zero se não houver setup cadastrado). A data sugerida de compra é essa data de
// necessidade menos o tempo de reação (Lead Time + Transit Time). Se essa data já
// passou, a compra está atrasada (urgente).
//
// Importante: o gatilho NÃO usa uma média de demanda diluída em todo o horizonte — isso
// causaria alarme falso pra itens com demanda esporádica e distante (estoque atual baixo,
// mas sem nenhum evento próximo, acabava disparando "urgente" sem necessidade real).
// Min/Max (em dias) só entram depois, pra dimensionar QUANTO comprar como margem de segurança,
// usando a taxa de demanda observada perto do próprio dia da necessidade.
function calcularDataSugeridaCompra(
    float $estoqueAtual,
    array $programacaoPorData,
    array $demandaPorData,
    DateTimeImmutable $hoje,
    DateTimeImmutable $horizonteFim,
    float $moq,
    int $frozenDias,
    int $transitDias,
    int $minDias,
    int $maxDias,
    float $segurancaQtd = 0.0
): array {
    $dias = [];
    $cursor = $hoje;
    while ($cursor <= $horizonteFim) {
        $dias[] = $cursor;
        $cursor = $cursor->modify('+1 day');
    }
    $n = count($dias);
    if ($n === 0) {
        return ['status' => 'ok', 'data' => null, 'quantidade' => 0.0];
    }

    $hojeChave = $hoje->format('Y-m-d');
    $entradaAtrasada = 0.0;
    foreach ($programacaoPorData as $d => $q) {
        if ($d < $hojeChave) { $entradaAtrasada += $q; }
    }
    $saidaAtrasada = 0.0;
    foreach ($demandaPorData as $d => $q) {
        if ($d < $hojeChave) { $saidaAtrasada += $q; }
    }

    $saldoPorDia = [];
    $demandaPorDia = [];
    $saldoAnterior = $estoqueAtual + $entradaAtrasada - $saidaAtrasada;
    foreach ($dias as $i => $dia) {
        $chave = $dia->format('Y-m-d');
        $entrada = $programacaoPorData[$chave] ?? 0.0;
        $saida = $demandaPorData[$chave] ?? 0.0;
        $saldo = $saldoAnterior + $entrada - $saida;
        $saldoPorDia[$i] = $saldo;
        $demandaPorDia[$i] = $saida;
        $saldoAnterior = $saldo;
    }

    // Prefixo pra somar rapidamente a demanda numa janela local (usado tanto pra achar o
    // piso de segurança no dia i quanto pra dimensionar a quantidade a comprar).
    $prefixo = array_fill(0, $n + 1, 0.0);
    for ($i = 0; $i < $n; $i++) {
        $prefixo[$i + 1] = $prefixo[$i] + $demandaPorDia[$i];
    }

    $inicioBusca = $hoje->modify('+' . ($frozenDias + $transitDias) . ' days');

    for ($i = 0; $i < $n; $i++) {
        if ($dias[$i] < $inicioBusca) {
            continue;
        }

        // Piso de segurança = quantidade já calculada (constante, não varia por dia —
        // ver calcularEstoqueSegurancaQtd). Com segurancaQtd = 0 (sem setup cadastrado),
        // o piso é sempre 0 — comportamento idêntico ao de antes (só dispara quando o
        // saldo físico fica negativo).
        if ($saldoPorDia[$i] < $segurancaQtd) {
            $dataNecessidade = $dias[$i];
            $dataSugerida = $dataNecessidade->modify('-' . ($frozenDias + $transitDias) . ' days');

            // Quantidade alvo = soma direta da demanda JÁ CONHECIDA nos próximos Max dias
            // a partir da necessidade (sem taxa diária fabricada, que distorce muito com
            // demanda em picos). Se não houver mais demanda conhecida, cobre só o déficit
            // até o piso de segurança (ou até zero, se não houver estoque de segurança).
            $janelaLocal = min($n, $i + $maxDias);
            $demandaJanelaMax = $prefixo[$janelaLocal] - $prefixo[$i];

            $quantidadeAlvo = $demandaJanelaMax > 0
                ? $demandaJanelaMax - $saldoPorDia[$i]
                : $segurancaQtd - $saldoPorDia[$i];
            $quantidadeSugerida = $moq > 0 ? max($moq, ceil($quantidadeAlvo / $moq) * $moq) : max(0, $quantidadeAlvo);

            if ($dataSugerida <= $hoje) {
                return ['status' => 'urgente', 'data' => null, 'quantidade' => $quantidadeSugerida];
            }
            return ['status' => 'programada', 'data' => $dataSugerida, 'quantidade' => $quantidadeSugerida];
        }
    }

    return ['status' => 'ok', 'data' => null, 'quantidade' => 0.0];
}

function opcoesDistintas(mysqli $conn, string $coluna): array
{
    $permitidas = ['fornecedor', 'projeto'];
    if (!in_array($coluna, $permitidas, true)) {
        return [];
    }

    $sql = "SELECT DISTINCT TRIM($coluna) AS valor
            FROM bomnova
            WHERE $coluna IS NOT NULL AND TRIM($coluna) <> ''
            ORDER BY valor";
    $resultado = mysqli_query($conn, $sql);
    $opcoes = [];
    while ($linha = mysqli_fetch_assoc($resultado)) {
        $opcoes[] = $linha['valor'];
    }
    return $opcoes;
}

function urlCom(array $alteracoes = []): string
{
    $parametros = $_GET;
    foreach ($alteracoes as $chave => $valor) {
        if ($valor === null || $valor === '') {
            unset($parametros[$chave]);
        } else {
            $parametros[$chave] = $valor;
        }
    }
    return '?' . http_build_query($parametros);
}

$busca = trim($_GET['busca'] ?? '');
$fornecedor = trim($_GET['fornecedor'] ?? '');
$projeto = trim($_GET['projeto'] ?? '');
$statusFiltro = $_GET['status'] ?? 'critico';
$statusPermitidos = ['todos', 'critico', 'atencao', 'excesso', 'planejar', 'ok', 'sem_demanda'];
if (!in_array($statusFiltro, $statusPermitidos, true)) {
    $statusFiltro = 'critico';
}

// Janela fixa de análise: sempre de hoje até hoje + 20 dias. Não é mais um filtro
// manual de semana — o dashboard sempre olha só pro curto prazo relevante.
$hojeDashboard = new DateTimeImmutable('today');
$fimJanelaDashboard = $hojeDashboard->modify('+20 days');

$porPagina = (int) ($_GET['por_pagina'] ?? 50);
if (!in_array($porPagina, [25, 50, 100], true)) {
    $porPagina = 50;
}
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

$ordenacao = $_GET['ordenar'] ?? 'saldo';
$direcao = strtolower($_GET['direcao'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$colunasOrdenacao = ['codigo', 'descricao', 'fornecedores', 'materiais', 'estoque', 'demanda', 'necessidade', 'saldo', 'status'];
if (!in_array($ordenacao, $colunasOrdenacao, true)) {
    $ordenacao = 'saldo';
}

$erroDashboard = null;
$linhas = [];
$fornecedores = [];
$projetos = [];

try {
    mysqli_query($conn, 'SET SESSION group_concat_max_len = 8192');

    $fornecedores = opcoesDistintas($conn, 'fornecedor');
    $projetos = opcoesDistintas($conn, 'projeto');

    $condicoesBom = ["b.codigo_componente IS NOT NULL", "TRIM(b.codigo_componente) <> ''", "(b.mrp IS NULL OR UPPER(TRIM(b.mrp)) <> 'N')"];
    $condicoesEdi = ["CAST(e.quantidade AS DECIMAL(18,4)) > 0", "(e.atendido = 0 OR e.atendido IS NULL)"];
    $parametros = [];
    $tipos = '';

    // Janela fixa: só considera demanda com data de início entre hoje e hoje+20 dias.
    $condicoesEdi[] = 'e.data_inicio BETWEEN ? AND ?';
    $parametros[] = $hojeDashboard->format('Y-m-d');
    $parametros[] = $fimJanelaDashboard->format('Y-m-d');
    $tipos .= 'ss';

    if ($fornecedor !== '') {
        $condicoesBom[] = 'TRIM(b.fornecedor) = ?';
        $parametros[] = $fornecedor;
        $tipos .= 's';
    }
    if ($projeto !== '') {
        $condicoesBom[] = 'TRIM(b.projeto) = ?';
        $parametros[] = $projeto;
        $tipos .= 's';
    }
    if ($busca !== '') {
        $condicoesBom[] = '(TRIM(b.codigo_componente) LIKE ? OR b.descricao LIKE ? OR b.fornecedor LIKE ? OR TRIM(b.material) LIKE ?)';
        $termo = '%' . $busca . '%';
        array_push($parametros, $termo, $termo, $termo, $termo);
        $tipos .= 'ssss';
    }

    $sql = "SELECT
                dados.codigo_componente,
                dados.descricao,
                dados.fornecedores,
                dados.projetos,
                dados.materiais,
                dados.unidades,
                dados.materiais_atendidos,
                dados.semanas_demanda,
                dados.demanda_total,
                COALESCE(est.estoque_atual, 0) AS estoque_atual,
                CASE WHEN est.codigo_componente IS NULL THEN 0 ELSE 1 END AS estoque_cadastrado
            FROM (
                SELECT
                    TRIM(b.codigo_componente) AS codigo_componente,
                    MAX(COALESCE(NULLIF(TRIM(b.descricao), ''), 'Sem descrição')) AS descricao,
                    GROUP_CONCAT(DISTINCT NULLIF(TRIM(b.fornecedor), '') ORDER BY TRIM(b.fornecedor) SEPARATOR ', ') AS fornecedores,
                    GROUP_CONCAT(DISTINCT NULLIF(TRIM(b.projeto), '') ORDER BY TRIM(b.projeto) SEPARATOR ', ') AS projetos,
                    GROUP_CONCAT(DISTINCT NULLIF(TRIM(b.material), '') ORDER BY TRIM(b.material) SEPARATOR ', ') AS materiais,
                    GROUP_CONCAT(DISTINCT NULLIF(TRIM(b.um), '') ORDER BY TRIM(b.um) SEPARATOR ', ') AS unidades,
                    COUNT(DISTINCT TRIM(b.material)) AS materiais_atendidos,
                    COUNT(DISTINCT CASE
                        WHEN e.semana IS NOT NULL THEN CONCAT(e.ano, '-', LPAD(e.semana, 2, '0'))
                    END) AS semanas_demanda,
                    SUM(
                        COALESCE(CAST(e.quantidade AS DECIMAL(18,4)), 0)
                        * COALESCE(CAST(NULLIF(REPLACE(TRIM(b.consumo), ',', '.'), '') AS DECIMAL(18,6)), 0)
                    ) AS demanda_total
                FROM bomnova b
                LEFT JOIN edi e
                    ON TRIM(b.material) = TRIM(e.material)
                    AND " . implode(' AND ', $condicoesEdi) . "
                WHERE " . implode(' AND ', $condicoesBom) . "
                GROUP BY TRIM(b.codigo_componente)
            ) dados
            LEFT JOIN (
                SELECT
                    TRIM(codigo_componente) AS codigo_componente,
                    SUM(COALESCE(CAST(estoque AS DECIMAL(18,4)), 0)) AS estoque_atual
                FROM estoque
                WHERE codigo_componente IS NOT NULL AND TRIM(codigo_componente) <> ''
                GROUP BY TRIM(codigo_componente)
            ) est ON est.codigo_componente = dados.codigo_componente";

    $stmt = mysqli_prepare($conn, $sql);
    vincularParametros($stmt, $tipos, $parametros);
    mysqli_stmt_execute($stmt);
    $resultado = mysqli_stmt_get_result($stmt);

    while ($linha = mysqli_fetch_assoc($resultado)) {
        $linha['estoque_atual'] = (float) $linha['estoque_atual'];
        $linha['demanda_total'] = (float) $linha['demanda_total'];
        $linha['saldo_final'] = $linha['estoque_atual'] - $linha['demanda_total'];
        $linha['necessidade_compra'] = max(0, -$linha['saldo_final']);
        $linha['estoque_cadastrado'] = (bool) $linha['estoque_cadastrado'];

        if ($linha['demanda_total'] <= 0.000001) {
            $linha['status'] = 'sem_demanda';
        } elseif ($linha['saldo_final'] < 0) {
            $linha['status'] = 'critico';
        } elseif ($linha['estoque_atual'] <= ($linha['demanda_total'] * 1.20)) {
            $linha['status'] = 'atencao';
        } else {
            $linha['status'] = 'ok';
        }

        // Ainda não filtra pelo status aqui — isso só acontece depois de aplicar os
        // parâmetros de compra (quando existirem), que podem mudar o status final.
        $linhas[] = $linha;
    }
    mysqli_stmt_close($stmt);

    // Verifica quais componentes têm os parâmetros de compra completos (MOQ, Frozen Zone,
    // Transit Time, Min/Max). Para esses, o status final vem da simulação precisa (dentro
    // da janela de 20 dias), não mais do cálculo agregado simples feito acima.
    $codigosCriticos = array_values(array_unique(array_map(
        fn($l) => $l['codigo_componente'],
        $linhas
    )));

    if (!empty($codigosCriticos)) {
        $placeholders = implode(',', array_fill(0, count($codigosCriticos), '?'));
        $tiposCodigos = str_repeat('s', count($codigosCriticos));

        $parametrosCompra = [];
        $stmtParam = mysqli_prepare($conn, "
            SELECT codigo_componente, moq, frozen_zone_dias, transit_time_dias, estoque_min_dias, estoque_max_dias, setup
            FROM parametros_compra
            WHERE TRIM(codigo_componente) IN ($placeholders)
              AND moq IS NOT NULL AND frozen_zone_dias IS NOT NULL AND transit_time_dias IS NOT NULL
              AND estoque_min_dias IS NOT NULL AND estoque_max_dias IS NOT NULL
        ");
        mysqli_stmt_bind_param($stmtParam, $tiposCodigos, ...$codigosCriticos);
        mysqli_stmt_execute($stmtParam);
        $resParam = mysqli_stmt_get_result($stmtParam);
        while ($p = mysqli_fetch_assoc($resParam)) {
            $parametrosCompra[trim($p['codigo_componente'])] = $p;
        }
        mysqli_stmt_close($stmtParam);

        if (!empty($parametrosCompra)) {
            $codigosComParametros = array_keys($parametrosCompra);
            $placeholdersP = implode(',', array_fill(0, count($codigosComParametros), '?'));
            $tiposCodigosP = str_repeat('s', count($codigosComParametros));

            $programacaoPorComponenteMrp = [];
            $stmtProgMrp = mysqli_prepare($conn, "
                SELECT TRIM(codigo_componente) AS codigo_componente, data, SUM(quantidade) AS quantidade
                FROM programacao
                WHERE TRIM(codigo_componente) IN ($placeholdersP)
                  AND (atendido = 0 OR atendido IS NULL)
                GROUP BY TRIM(codigo_componente), data
            ");
            mysqli_stmt_bind_param($stmtProgMrp, $tiposCodigosP, ...$codigosComParametros);
            mysqli_stmt_execute($stmtProgMrp);
            $resProgMrp = mysqli_stmt_get_result($stmtProgMrp);
            while ($linhaProg = mysqli_fetch_assoc($resProgMrp)) {
                $programacaoPorComponenteMrp[$linhaProg['codigo_componente']][$linhaProg['data']] = (float) $linhaProg['quantidade'];
            }
            mysqli_stmt_close($stmtProgMrp);

            $demandaPorComponenteMrp = [];
            $stmtDemandaMrp = mysqli_prepare($conn, "
                SELECT TRIM(b.codigo_componente) AS codigo_componente, e.data_inicio AS data,
                       SUM(
                           COALESCE(CAST(e.quantidade AS DECIMAL(18,4)), 0)
                           * COALESCE(CAST(NULLIF(REPLACE(TRIM(b.consumo), ',', '.'), '') AS DECIMAL(18,6)), 0)
                       ) AS quantidade
                FROM bomnova b
                JOIN edi e ON TRIM(b.material) = TRIM(e.material)
                WHERE TRIM(b.codigo_componente) IN ($placeholdersP) AND (b.mrp IS NULL OR UPPER(TRIM(b.mrp)) <> 'N')
                  AND (e.atendido = 0 OR e.atendido IS NULL)
                GROUP BY TRIM(b.codigo_componente), e.data_inicio
            ");
            mysqli_stmt_bind_param($stmtDemandaMrp, $tiposCodigosP, ...$codigosComParametros);
            mysqli_stmt_execute($stmtDemandaMrp);
            $resDemandaMrp = mysqli_stmt_get_result($stmtDemandaMrp);
            while ($linhaDem = mysqli_fetch_assoc($resDemandaMrp)) {
                $demandaPorComponenteMrp[$linhaDem['codigo_componente']][$linhaDem['data']] = (float) $linhaDem['quantidade'];
            }
            mysqli_stmt_close($stmtDemandaMrp);

            $hojeMrp = new DateTimeImmutable('today');
            $horizonteMrp = new DateTimeImmutable('2027-12-31');

            foreach ($linhas as &$linhaRef) {
                $codigo = $linhaRef['codigo_componente'];
                if (!isset($parametrosCompra[$codigo])) {
                    continue;
                }
                $p = $parametrosCompra[$codigo];
                $progComp = $programacaoPorComponenteMrp[$codigo] ?? [];
                $demComp = $demandaPorComponenteMrp[$codigo] ?? [];

                // Estoque de segurança calculado automaticamente (sem dias cadastrados
                // manualmente) — ver calcularEstoqueSegurancaQtd().
                $segurancaQtd = calcularEstoqueSegurancaQtd(
                    $demComp,
                    $hojeMrp,
                    (int) $p['frozen_zone_dias'],
                    (int) $p['transit_time_dias'],
                    $p['setup'] !== null ? (float) $p['setup'] : 0.0
                );

                // (1) Status real dentro da janela de 20 dias: crítico (saldo já ficaria
                // abaixo do estoque de segurança calculado, ou negativo se o cálculo der
                // zero), atenção (abaixo do Min), excesso (acima do Max) ou ok.
                $janela = calcularStatusJanela(
                    $linhaRef['estoque_atual'],
                    $progComp,
                    $demComp,
                    $hojeMrp,
                    20,
                    (int) $p['estoque_min_dias'],
                    (int) $p['estoque_max_dias'],
                    $segurancaQtd
                );
                $linhaRef['status'] = $janela['status'];

                // (2) Data/quantidade sugerida (horizonte largo), só pra mostrar quando
                // fizer sentido (crítico ou atenção) — não muda o status, só informa.
                $resultadoMrp = calcularDataSugeridaCompra(
                    $linhaRef['estoque_atual'],
                    $progComp,
                    $demComp,
                    $hojeMrp,
                    $horizonteMrp,
                    (float) $p['moq'],
                    (int) $p['frozen_zone_dias'],
                    (int) $p['transit_time_dias'],
                    (int) $p['estoque_min_dias'],
                    (int) $p['estoque_max_dias'],
                    $segurancaQtd
                );
                $linhaRef['mrp_status'] = $resultadoMrp['status'];
                $linhaRef['mrp_data_sugerida'] = $resultadoMrp['data'];
                $linhaRef['mrp_quantidade_sugerida'] = $resultadoMrp['quantidade'];

                // A coluna "Comprar" passa a usar a quantidade do cálculo preciso (que
                // considera todo o horizonte, MOQ, programação etc.) em vez do cálculo
                // simples do período de 20 dias, que ficava zerado sempre que a necessidade
                // real estava fora dessa janela (ex.: itens em "Planejar").
                if ($resultadoMrp['quantidade'] > 0) {
                    $linhaRef['necessidade_compra'] = $resultadoMrp['quantidade'];
                }

                // Se dentro dos 20 dias está tudo ok, mas existe uma necessidade real mais à
                // frente (fora da janela imediata), vira "Planejar" em vez de sumir como "ok".
                // Uma necessidade real e imediata (crítico/atenção/excesso) sempre tem prioridade.
                if ($linhaRef['status'] === 'ok' && $resultadoMrp['status'] === 'programada') {
                    $linhaRef['status'] = 'planejar';
                }
            }
            unset($linhaRef);
        }
    }

    // Filtro final: aplica o status escolhido (agora já é o status definitivo — para quem
    // tem parâmetros, veio da janela de 20 dias; para quem não tem, do cálculo agregado).
    $linhas = array_values(array_filter($linhas, function (array $l) use ($statusFiltro): bool {
        return $statusFiltro === 'todos' || $l['status'] === $statusFiltro;
    }));
} catch (Throwable $erro) {
    error_log('Erro no dashboard MRP: ' . $erro->getMessage());
    $erroDashboard = 'Não foi possível carregar os dados do MRP. Verifique a conexão e a estrutura das tabelas.';
}

$mapaOrdenacao = [
    'codigo' => 'codigo_componente',
    'descricao' => 'descricao',
    'fornecedores' => 'fornecedores',
    'materiais' => 'materiais_atendidos',
    'estoque' => 'estoque_atual',
    'demanda' => 'demanda_total',
    'necessidade' => 'necessidade_compra',
    'saldo' => 'saldo_final',
    'status' => 'status',
];
$campoOrdenacao = $mapaOrdenacao[$ordenacao];
usort($linhas, function (array $a, array $b) use ($campoOrdenacao, $direcao): int {
    $valorA = $a[$campoOrdenacao] ?? '';
    $valorB = $b[$campoOrdenacao] ?? '';
    $comparacao = is_numeric($valorA) && is_numeric($valorB)
        ? ((float) $valorA <=> (float) $valorB)
        : strnatcasecmp((string) $valorA, (string) $valorB);
    return $direcao === 'desc' ? -$comparacao : $comparacao;
});

$stats = [
    'total' => count($linhas),
    'critico' => 0,
    'atencao' => 0,
    'excesso' => 0,
    'planejar' => 0,
    'ok' => 0,
    'sem_demanda' => 0,
    'sem_estoque' => 0,
];
foreach ($linhas as $linha) {
    $stats[$linha['status']]++;
    if (!$linha['estoque_cadastrado']) {
        $stats['sem_estoque']++;
    }
}

if (($_GET['exportar'] ?? '') === 'csv' && $erroDashboard === null) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="mrp-componentes-' . date('Y-m-d-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $saida = fopen('php://output', 'w');
    fputcsv($saida, ['Código do componente', 'Descrição', 'Fornecedor', 'Projetos', 'Itens atendidos', 'Quantidade de itens', 'U.M.', 'Estoque', 'Demanda', 'Necessidade de compra', 'Saldo projetado', 'Semanas', 'Status'], ';', '"', '');
    foreach ($linhas as $linha) {
        fputcsv($saida, [
            $linha['codigo_componente'],
            $linha['descricao'],
            $linha['fornecedores'],
            $linha['projetos'],
            $linha['materiais'],
            $linha['materiais_atendidos'],
            $linha['unidades'],
            numeroBr($linha['estoque_atual'], 4),
            numeroBr($linha['demanda_total'], 4),
            numeroBr($linha['necessidade_compra'], 4),
            numeroBr($linha['saldo_final'], 4),
            $linha['semanas_demanda'],
            $linha['status'],
        ], ';', '"', '');
    }
    fclose($saida);
    exit;
}

$totalPaginas = max(1, (int) ceil(max(1, count($linhas)) / $porPagina));
$pagina = min($pagina, $totalPaginas);
$linhasPagina = array_slice($linhas, ($pagina - 1) * $porPagina, $porPagina);

$rotulosStatus = [
    'critico' => 'Compra urgente',
    'atencao' => 'Atenção',
    'excesso' => 'Excesso',
    'planejar' => 'Planejar',
    'ok' => 'Estoque OK',
    'sem_demanda' => 'Sem demanda',
];

function cabecalhoOrdenavel(string $rotulo, string $coluna, string $ordenacaoAtual, string $direcaoAtual): string
{
    $novaDirecao = $ordenacaoAtual === $coluna && $direcaoAtual === 'asc' ? 'desc' : 'asc';
    $indicador = $ordenacaoAtual === $coluna ? ($direcaoAtual === 'asc' ? ' ↑' : ' ↓') : '';
    return '<a href="' . h(urlCom(['ordenar' => $coluna, 'direcao' => $novaDirecao, 'pagina' => 1])) . '">' . h($rotulo . $indicador) . '</a>';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard MRP | Controle de Estoque</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/dashboard.css" rel="stylesheet">
</head>
<body>
    <header class="topbar">
        <div class="container-fluid dashboard-container d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="eyebrow">Supply Chain • Planejamento de materiais</span>
                <h1>Dashboard MRP</h1>
                <p class="mb-0">SAP - Site Avançado em Planilhas</p>
            </div>
            <nav class="d-flex flex-wrap gap-2" aria-label="Ações do sistema">
                <a class="btn btn-light btn-sm" href="estoque.php">Estoque</a>
                <a class="btn btn-light btn-sm" href="edi.php">EDI</a>
                <a class="btn btn-light btn-sm" href="bomnova.php">BOM</a>
                <a class="btn btn-light btn-sm" href="programacao.php">Programação</a>
                <a class="btn btn-light btn-sm" href="parametros_compra.php">Parâmetros</a>
                <a class="btn btn-outline-light btn-sm" href="evolucao_geral.php">Evolução geral</a>
                <a class="btn btn-outline-light btn-sm" href="planejamento_compras.php">Planejamento de compras</a>
            </nav>
        </div>
    </header>

    <main class="container-fluid dashboard-container py-4">
        <?php if ($erroDashboard !== null): ?>
            <div class="alert alert-danger" role="alert"><?php echo h($erroDashboard); ?></div>
        <?php endif; ?>

        <section class="filter-panel" aria-labelledby="titulo-filtros">
            <div class="section-heading">
                <div>
                    <span class="eyebrow text-primary">Curto prazo</span>
                    <h2 id="titulo-filtros">Filtros da análise</h2>
                    <p class="mb-0 text-muted small">Sempre mostra a demanda de hoje (<?php echo h($hojeDashboard->format('d/m/Y')); ?>) até <?php echo h($fimJanelaDashboard->format('d/m/Y')); ?> (20 dias). Para períodos mais distantes, veja o <a href="planejamento_compras.php">Planejamento de Compras</a>.</p>
                </div>
                <a class="btn btn-outline-secondary btn-sm" href="index.php">Limpar filtros</a>
            </div>
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-12 col-lg-4">
                    <label class="form-label" for="busca">Componente, material ou descrição</label>
                    <input class="form-control" id="busca" name="busca" value="<?php echo h($busca); ?>" placeholder="Ex.: ABC123 ou tubo">
                </div>
                <div class="col-6 col-md-3 col-lg-3">
                    <label class="form-label" for="fornecedor">Fornecedor</label>
                    <select class="form-select" id="fornecedor" name="fornecedor">
                        <option value="">Todos</option>
                        <?php foreach ($fornecedores as $opcao): ?>
                            <option value="<?php echo h($opcao); ?>" <?php echo $fornecedor === $opcao ? 'selected' : ''; ?>><?php echo h($opcao); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-3">
                    <label class="form-label" for="projeto">Projeto</label>
                    <select class="form-select" id="projeto" name="projeto">
                        <option value="">Todos</option>
                        <?php foreach ($projetos as $opcao): ?>
                            <option value="<?php echo h($opcao); ?>" <?php echo $projeto === $opcao ? 'selected' : ''; ?>><?php echo h($opcao); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-1">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="todos" <?php echo $statusFiltro === 'todos' ? 'selected' : ''; ?>>Todos</option>
                        <option value="critico" <?php echo $statusFiltro === 'critico' ? 'selected' : ''; ?>>Urgente</option>
                        <option value="atencao" <?php echo $statusFiltro === 'atencao' ? 'selected' : ''; ?>>Atenção</option>
                        <option value="excesso" <?php echo $statusFiltro === 'excesso' ? 'selected' : ''; ?>>Excesso</option>
                        <option value="planejar" <?php echo $statusFiltro === 'planejar' ? 'selected' : ''; ?>>Planejar</option>
                        <option value="ok" <?php echo $statusFiltro === 'ok' ? 'selected' : ''; ?>>OK</option>
                        <option value="sem_demanda" <?php echo $statusFiltro === 'sem_demanda' ? 'selected' : ''; ?>>Sem demanda</option>
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-1 d-grid">
                    <button class="btn btn-primary" type="submit">Aplicar</button>
                </div>
            </form>
        </section>

        <section class="metrics-grid" aria-label="Indicadores do MRP">
            <article class="metric-card metric-neutral">
                <span class="metric-label">Componentes analisados</span>
                <strong><?php echo numeroBr($stats['total'], 0); ?></strong>
                <small>No período filtrado</small>
            </article>
            <article class="metric-card metric-danger">
                <span class="metric-label">Compra urgente</span>
                <strong><?php echo numeroBr($stats['critico'], 0); ?></strong>
                <small>Saldo projetado negativo</small>
            </article>
            <article class="metric-card metric-warning">
                <span class="metric-label">Em atenção</span>
                <strong><?php echo numeroBr($stats['atencao'], 0); ?></strong>
                <small>Abaixo do estoque mínimo</small>
            </article>
            <article class="metric-card metric-excesso">
                <span class="metric-label">Excesso</span>
                <strong><?php echo numeroBr($stats['excesso'], 0); ?></strong>
                <small>Acima do estoque máximo</small>
            </article>
            <article class="metric-card metric-info">
                <span class="metric-label">Sem estoque cadastrado</span>
                <strong><?php echo numeroBr($stats['sem_estoque'], 0); ?></strong>
                <small>Exige conferência da base</small>
            </article>
        </section>

        <section class="table-card" aria-labelledby="titulo-componentes">
            <div class="table-toolbar">
                <div>
                    <span class="eyebrow text-primary">Plano de abastecimento</span>
                    <h2 id="titulo-componentes">Necessidade por componente</h2>
                    <p><?php echo numeroBr(count($linhas), 0); ?> componente(s) encontrado(s) • <?php echo numeroBr($stats['critico'], 0); ?> com necessidade de compra</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-primary btn-sm" href="<?php echo h(urlCom(['exportar' => 'csv', 'pagina' => null])); ?>">Exportar CSV</a>
                    <form method="GET" class="d-flex align-items-center gap-2">
                        <?php foreach ($_GET as $chave => $valor): ?>
                            <?php if (!in_array($chave, ['por_pagina', 'pagina', 'exportar'], true)): ?>
                                <input type="hidden" name="<?php echo h($chave); ?>" value="<?php echo h($valor); ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <label class="small text-muted text-nowrap" for="por_pagina">Linhas</label>
                        <select class="form-select form-select-sm" id="por_pagina" name="por_pagina" onchange="this.form.submit()">
                            <?php foreach ([25, 50, 100] as $quantidade): ?>
                                <option value="<?php echo $quantidade; ?>" <?php echo $porPagina === $quantidade ? 'selected' : ''; ?>><?php echo $quantidade; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>

            <div class="status-legend" aria-label="Legenda de status">
                <span><i class="dot dot-danger"></i> Urgente: saldo ficaria negativo dentro de 20 dias</span>
                <span><i class="dot dot-warning"></i> Atenção: abaixo do estoque mínimo configurado (ou até 20% de margem, sem parâmetros)</span>
                <span><i class="dot dot-excesso"></i> Excesso: acima do estoque máximo configurado</span>
                <span><i class="dot dot-planejar"></i> Planejar: necessidade futura fora dos 20 dias</span>
                <span><i class="dot dot-success"></i> OK: dentro da faixa esperada</span>
                <span><i class="dot dot-muted"></i> Sem demanda no período</span>
            </div>

            <div class="table-responsive">
                <table class="table mrp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?php echo cabecalhoOrdenavel('Código do componente', 'codigo', $ordenacao, $direcao); ?></th>
                            <th><?php echo cabecalhoOrdenavel('Descrição', 'descricao', $ordenacao, $direcao); ?></th>
                            <th><?php echo cabecalhoOrdenavel('Fornecedor', 'fornecedores', $ordenacao, $direcao); ?></th>
                            <th>Itens atendidos</th>
                            <th class="text-end"><?php echo cabecalhoOrdenavel('Qtd. itens', 'materiais', $ordenacao, $direcao); ?></th>
                            <th>U.M.</th>
                            <th class="text-end"><?php echo cabecalhoOrdenavel('Estoque', 'estoque', $ordenacao, $direcao); ?></th>
                            <th class="text-end"><?php echo cabecalhoOrdenavel('Demanda', 'demanda', $ordenacao, $direcao); ?></th>
                            <th class="text-end"><?php echo cabecalhoOrdenavel('Comprar', 'necessidade', $ordenacao, $direcao); ?></th>
                            <th class="text-end"><?php echo cabecalhoOrdenavel('Saldo', 'saldo', $ordenacao, $direcao); ?></th>
                            <th><?php echo cabecalhoOrdenavel('Status', 'status', $ordenacao, $direcao); ?></th>
                            <th>Evolução</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($linhasPagina)): ?>
                            <tr>
                                <td colspan="12" class="empty-state">Nenhum componente encontrado para os filtros selecionados.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($linhasPagina as $linha): ?>
                                <tr class="row-<?php echo h($linha['status']); ?>">
                                    <td><strong class="component-code"><?php echo h($linha['codigo_componente']); ?></strong></td>
                                    <td>
                                        <span class="description-cell"><?php echo h($linha['descricao']); ?></span>
                                        <?php if (!empty($linha['projetos'])): ?><small class="d-block text-muted">Projeto: <?php echo h($linha['projetos']); ?></small><?php endif; ?>
                                    </td>
                                    <td><?php echo h($linha['fornecedores'] ?: 'Não informado'); ?></td>
                                    <td><span class="materials-cell" title="<?php echo h($linha['materiais']); ?>"><?php echo h($linha['materiais'] ?: 'Não informado'); ?></span></td>
                                    <td class="text-end"><?php echo numeroBr($linha['materiais_atendidos'], 0); ?></td>
                                    <td><?php echo h($linha['unidades'] ?: '-'); ?></td>
                                    <td class="text-end">
                                        <?php if ($linha['estoque_cadastrado']): ?>
                                            <?php echo numeroBr($linha['estoque_atual']); ?>
                                        <?php else: ?>
                                            <span class="missing-data">Não cadastrado</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?php echo numeroBr($linha['demanda_total']); ?></td>
                                    <td class="text-end purchase-value"><?php echo numeroBr($linha['necessidade_compra']); ?></td>
                                    <td class="text-end <?php echo $linha['saldo_final'] < 0 ? 'negative-balance' : ''; ?>"><?php echo numeroBr($linha['saldo_final']); ?></td>
                                    <td>
                                        <?php if ($linha['status'] === 'planejar' && ($linha['mrp_data_sugerida'] ?? null)): ?>
                                            <span class="status-badge status-planejar" title="Comprar em <?php echo h($linha['mrp_data_sugerida']->format('d/m/Y')); ?> • Quantidade sugerida: <?php echo numeroBr($linha['mrp_quantidade_sugerida']); ?>">Planejar</span>
                                        <?php else: ?>
                                            <span class="status-badge status-<?php echo h($linha['status']); ?>"><?php echo h($rotulosStatus[$linha['status']]); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><a class="btn btn-outline-primary btn-sm" href="detalhe_componente.php?codigo=<?php echo urlencode($linha['codigo_componente']); ?>">Ver evolução</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPaginas > 1): ?>
                <nav class="pagination-bar" aria-label="Paginação da tabela">
                    <span>Página <?php echo $pagina; ?> de <?php echo $totalPaginas; ?></span>
                    <div class="btn-group">
                        <a class="btn btn-outline-secondary btn-sm <?php echo $pagina <= 1 ? 'disabled' : ''; ?>" href="<?php echo h(urlCom(['pagina' => max(1, $pagina - 1)])); ?>">Anterior</a>
                        <a class="btn btn-outline-secondary btn-sm <?php echo $pagina >= $totalPaginas ? 'disabled' : ''; ?>" href="<?php echo h(urlCom(['pagina' => min($totalPaginas, $pagina + 1)])); ?>">Próxima</a>
                    </div>
                </nav>
            <?php endif; ?>
        </section>

        <footer class="dashboard-footer">
            Atualizado em <?php echo date('d/m/Y H:i'); ?> • Cálculo: demanda EDI × consumo da BOM
        </footer>
    </main>
</body>
</html>
