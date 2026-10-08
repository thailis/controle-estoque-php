<?php
require_once 'conexao.php';

require_once 'auth.php';
exigirLogin();
function h(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function numeroBr($valor, int $decimais = 0): string
{
    return number_format((float) $valor, $decimais, ',', '.');
}

function opcoesDistintasGeral(mysqli $conn, string $coluna): array
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

// ---------- Exportação .xlsx colorida (sem bibliotecas externas) ----------
// Monta o .xlsx "na mão": um .xlsx é só um ZIP com alguns XMLs dentro. O ZIP é
// gerado aqui mesmo (zipSimplesEvolucao), sem depender da extensão ZipArchive do
// servidor — só usa gzdeflate/crc32, que já vêm no PHP padrão.
function colunaExcelEvolucao(int $indice): string
{
    // 1 => A, 27 => AA ...
    $letras = '';
    while ($indice > 0) {
        $resto = ($indice - 1) % 26;
        $letras = chr(65 + $resto) . $letras;
        $indice = intdiv($indice - 1, 26);
    }
    return $letras;
}

function xmlEscEvolucao(string $texto): string
{
    // Remove caracteres de controle inválidos em XML e escapa o resto.
    $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $texto) ?? '';
    return htmlspecialchars($texto, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function zipSimplesEvolucao(array $arquivos): string
{
    $dados = '';
    $central = '';
    $offset = 0;
    $hora = 0; $dia = (1 << 5) | 1 | ((2020 - 1980) << 9); // data fixa qualquer (01/01/2020)
    foreach ($arquivos as $nome => $conteudo) {
        $crc = crc32($conteudo);
        $comprimido = function_exists('gzdeflate') ? gzdeflate($conteudo, 6) : $conteudo;
        $metodo = function_exists('gzdeflate') ? 8 : 0;
        $tamOrig = strlen($conteudo);
        $tamComp = strlen($comprimido);
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $metodo, $hora, $dia, $crc, $tamComp, $tamOrig, strlen($nome), 0) . $nome;
        $dados .= $local . $comprimido;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $metodo, $hora, $dia, $crc, $tamComp, $tamOrig, strlen($nome), 0, 0, 0, 0, 0, $offset) . $nome;
        $offset += strlen($local) + $tamComp;
    }
    $fim = pack('VvvvvVVv', 0x06054b50, 0, 0, count($arquivos), count($arquivos), strlen($central), $offset, 0);
    return $dados . $central . $fim;
}

function urlComGeral(array $alteracoes = []): string
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

$porPagina = (int) ($_GET['por_pagina'] ?? 20);
if (!in_array($porPagina, [10, 20, 50], true)) {
    $porPagina = 20;
}
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

$dataLimite = new DateTimeImmutable('2027-03-31');
$hoje = new DateTimeImmutable('today');

$erroGeral = null;
$componentes = [];
$totalComponentes = 0;
$fornecedores = [];
$projetos = [];
$dias = [];
$temEdiPorDia = [];
$demandaEdiBrutaPorDia = [];
$projetosPorDia = [];
$maxEntregas = 0;

try {
    $fornecedores = opcoesDistintasGeral($conn, 'fornecedor');
    $projetos = opcoesDistintasGeral($conn, 'projeto');

    $condicoes = ["b.codigo_componente IS NOT NULL", "TRIM(b.codigo_componente) <> ''", "(b.mrp IS NULL OR UPPER(TRIM(b.mrp)) <> 'N')"];
    $parametros = [];
    $tipos = '';

    if ($busca !== '') {
        $condicoes[] = '(TRIM(b.codigo_componente) LIKE ? OR b.descricao LIKE ? OR b.fornecedor LIKE ? OR TRIM(b.material) LIKE ?)';
        $termo = '%' . $busca . '%';
        array_push($parametros, $termo, $termo, $termo, $termo);
        $tipos .= 'ssss';
    }
    if ($fornecedor !== '') {
        $condicoes[] = 'TRIM(b.fornecedor) = ?';
        $parametros[] = $fornecedor;
        $tipos .= 's';
    }
    if ($projeto !== '') {
        $condicoes[] = 'TRIM(b.projeto) = ?';
        $parametros[] = $projeto;
        $tipos .= 's';
    }

    $sqlBase = "SELECT
                    TRIM(b.codigo_componente) AS codigo_componente,
                    MAX(COALESCE(NULLIF(TRIM(b.descricao), ''), 'Sem descrição')) AS descricao,
                    GROUP_CONCAT(DISTINCT NULLIF(TRIM(b.fornecedor), '') ORDER BY TRIM(b.fornecedor) SEPARATOR ', ') AS fornecedores,
                    GROUP_CONCAT(DISTINCT NULLIF(TRIM(b.projeto), '') ORDER BY TRIM(b.projeto) SEPARATOR ', ') AS projetos,
                    GROUP_CONCAT(DISTINCT NULLIF(TRIM(b.consumo), '') ORDER BY TRIM(b.consumo) SEPARATOR ', ') AS consumos,
                    COALESCE(MAX(est.estoque_atual), 0) AS estoque_atual
                FROM bomnova b
                LEFT JOIN (
                    SELECT TRIM(codigo_componente) AS codigo_componente,
                           SUM(COALESCE(CAST(estoque AS DECIMAL(18,4)), 0)) AS estoque_atual
                    FROM estoque
                    WHERE codigo_componente IS NOT NULL AND TRIM(codigo_componente) <> ''
                    GROUP BY TRIM(codigo_componente)
                ) est ON est.codigo_componente = TRIM(b.codigo_componente)
                WHERE " . implode(' AND ', $condicoes) . "
                GROUP BY TRIM(b.codigo_componente)
                ORDER BY TRIM(b.codigo_componente)";

    $stmtBase = mysqli_prepare($conn, $sqlBase);
    if ($tipos !== '') {
        mysqli_stmt_bind_param($stmtBase, $tipos, ...$parametros);
    }
    mysqli_stmt_execute($stmtBase);
    $resBase = mysqli_stmt_get_result($stmtBase);
    while ($linha = mysqli_fetch_assoc($resBase)) {
        $componentes[] = $linha;
    }
    mysqli_stmt_close($stmtBase);

    $totalComponentes = count($componentes);
    $totalPaginas = max(1, (int) ceil($totalComponentes / $porPagina));
    $pagina = min($pagina, $totalPaginas);
    $componentesPagina = array_slice($componentes, ($pagina - 1) * $porPagina, $porPagina);

    $tipoExportacao = $_GET['exportar'] ?? '';
    $exportando = in_array($tipoExportacao, ['csv', 'xlsx'], true);
    // Na exportação, considera TODOS os componentes filtrados (ignora a paginação da tela).
    $componentesParaCalcular = $exportando ? $componentes : $componentesPagina;

    $codigosCalculo = array_column($componentesParaCalcular, 'codigo_componente');

    $programacaoPorComponente = [];
    $demandaPorComponente = [];
    $demandaEdiBrutaPorDia = [];

    if (!empty($codigosCalculo)) {
        $placeholders = implode(',', array_fill(0, count($codigosCalculo), '?'));
        $tiposCodigos = str_repeat('s', count($codigosCalculo));

        // Programação de entradas futuras dos componentes considerados
        $stmtProg = mysqli_prepare($conn, "
            SELECT TRIM(codigo_componente) AS codigo_componente, data, SUM(quantidade) AS quantidade
            FROM programacao
            WHERE TRIM(codigo_componente) IN ($placeholders)
              AND (atendido = 0 OR atendido IS NULL)
            GROUP BY TRIM(codigo_componente), data
        ");
        mysqli_stmt_bind_param($stmtProg, $tiposCodigos, ...$codigosCalculo);
        mysqli_stmt_execute($stmtProg);
        $resProg = mysqli_stmt_get_result($stmtProg);
        while ($linha = mysqli_fetch_assoc($resProg)) {
            $programacaoPorComponente[$linha['codigo_componente']][$linha['data']] = (float) $linha['quantidade'];
        }
        mysqli_stmt_close($stmtProg);

        // Demanda EDI (quantidade × consumo) dos componentes considerados, por data de início da semana
        $stmtDemanda = mysqli_prepare($conn, "
            SELECT TRIM(b.codigo_componente) AS codigo_componente, e.data_inicio AS data,
                   SUM(
                       COALESCE(CAST(e.quantidade AS DECIMAL(18,4)), 0)
                       * COALESCE(CAST(NULLIF(REPLACE(TRIM(b.consumo), ',', '.'), '') AS DECIMAL(18,6)), 0)
                   ) AS quantidade
            FROM bomnova b
            JOIN edi e ON TRIM(b.material) = TRIM(e.material)
            WHERE TRIM(b.codigo_componente) IN ($placeholders) AND (b.mrp IS NULL OR UPPER(TRIM(b.mrp)) <> 'N')
              AND (e.atendido = 0 OR e.atendido IS NULL)
            GROUP BY TRIM(b.codigo_componente), e.data_inicio
        ");
        mysqli_stmt_bind_param($stmtDemanda, $tiposCodigos, ...$codigosCalculo);
        mysqli_stmt_execute($stmtDemanda);
        $resDemanda = mysqli_stmt_get_result($stmtDemanda);
        while ($linha = mysqli_fetch_assoc($resDemanda)) {
            $demandaPorComponente[$linha['codigo_componente']][$linha['data']] = (float) $linha['quantidade'];
        }
        mysqli_stmt_close($stmtDemanda);

        // Quantidade BRUTA do EDI por data (sem multiplicar por consumo), só pra exibir
        // ao lado da bolinha de evento. Usa DISTINCT material+data+quantidade pra não
        // contar a mesma linha de EDI várias vezes quando vários componentes compartilham o material.
        $stmtDemandaBruta = mysqli_prepare($conn, "
            SELECT data, SUM(quantidade) AS quantidade
            FROM (
                SELECT DISTINCT e.material, e.data_inicio AS data, e.quantidade
                FROM bomnova b
                JOIN edi e ON TRIM(b.material) = TRIM(e.material)
                WHERE TRIM(b.codigo_componente) IN ($placeholders) AND (b.mrp IS NULL OR UPPER(TRIM(b.mrp)) <> 'N')
                  AND (e.atendido = 0 OR e.atendido IS NULL)
            ) AS materiais_distintos
            GROUP BY data
        ");
        mysqli_stmt_bind_param($stmtDemandaBruta, $tiposCodigos, ...$codigosCalculo);
        mysqli_stmt_execute($stmtDemandaBruta);
        $resDemandaBruta = mysqli_stmt_get_result($stmtDemandaBruta);
        $demandaEdiBrutaPorDia = [];
        while ($linha = mysqli_fetch_assoc($resDemandaBruta)) {
            $demandaEdiBrutaPorDia[$linha['data']] = (float) $linha['quantidade'];
        }
        mysqli_stmt_close($stmtDemandaBruta);

        // Projetos distintos com demanda EDI em cada dia — mostrado numa linha
        // própria, acima da bolinha de evento. Se mais de um projeto tiver
        // demanda no mesmo dia, lista todos juntos, separados por vírgula.
        $stmtProjetosPorDia = mysqli_prepare($conn, "
            SELECT e.data_inicio AS data,
                   GROUP_CONCAT(DISTINCT NULLIF(TRIM(b.projeto), '') ORDER BY TRIM(b.projeto) SEPARATOR ', ') AS projetos
            FROM bomnova b
            JOIN edi e ON TRIM(b.material) = TRIM(e.material)
            WHERE TRIM(b.codigo_componente) IN ($placeholders) AND (b.mrp IS NULL OR UPPER(TRIM(b.mrp)) <> 'N')
              AND (e.atendido = 0 OR e.atendido IS NULL)
            GROUP BY e.data_inicio
        ");
        mysqli_stmt_bind_param($stmtProjetosPorDia, $tiposCodigos, ...$codigosCalculo);
        mysqli_stmt_execute($stmtProjetosPorDia);
        $resProjetosPorDia = mysqli_stmt_get_result($stmtProjetosPorDia);
        while ($linha = mysqli_fetch_assoc($resProjetosPorDia)) {
            $projetosPorDia[$linha['data']] = $linha['projetos'] ?? '';
        }
        mysqli_stmt_close($stmtProjetosPorDia);
    }

    // Monta a lista de dias (mesma para todos os componentes) e o saldo projetado de cada um
    $cursor = $hoje;
    while ($cursor <= $dataLimite) {
        $dias[] = $cursor;
        $cursor = $cursor->modify('+1 day');
    }

    foreach ($componentesParaCalcular as &$componente) {
        $codigo = $componente['codigo_componente'];
        $progComponente = $programacaoPorComponente[$codigo] ?? [];
        $demandaComponente = $demandaPorComponente[$codigo] ?? [];

        // Movimentos com data anterior a hoje (atrasados) entram direto no saldo inicial,
        // em vez de serem ignorados por caírem fora do intervalo de colunas exibido.
        $hojeChave = $hoje->format('Y-m-d');
        $entradaAtrasada = 0.0;
        foreach ($progComponente as $dataMov => $qtd) {
            if ($dataMov < $hojeChave) {
                $entradaAtrasada += $qtd;
            }
        }
        $saidaAtrasada = 0.0;
        foreach ($demandaComponente as $dataMov => $qtd) {
            if ($dataMov < $hojeChave) {
                $saidaAtrasada += $qtd;
            }
        }

        $saldos = [];
        $saldoAnterior = (float) $componente['estoque_atual'] + $entradaAtrasada - $saidaAtrasada;
        foreach ($dias as $dia) {
            $chave = $dia->format('Y-m-d');
            $entrada = $progComponente[$chave] ?? 0.0;
            $saida = $demandaComponente[$chave] ?? 0.0;
            $saldo = $saldoAnterior + $entrada - $saida;
            $saldos[$chave] = $saldo;
            $saldoAnterior = $saldo;
        }
        $componente['saldos'] = $saldos;
    }
    unset($componente);

    // Programação pendente de cada componente em pares "Trânsito N / ETA N" (igual à
    // planilha), em ordem de data. O nº de pares exibido é o maior entre os
    // componentes considerados (página atual, ou todos na exportação).
    $maxEntregas = 0;
    foreach ($componentesParaCalcular as &$componenteProg) {
        $entregas = $programacaoPorComponente[$componenteProg['codigo_componente']] ?? [];
        ksort($entregas);
        $lista = [];
        foreach ($entregas as $dataEntrega => $qtdEntrega) {
            if ((float) $qtdEntrega == 0.0) { continue; }
            $lista[] = ['data' => $dataEntrega, 'quantidade' => (float) $qtdEntrega];
        }
        $componenteProg['entregas'] = $lista;
        $maxEntregas = max($maxEntregas, count($lista));
    }
    unset($componenteProg);

    // Marca os dias em que pelo menos um componente do conjunto calculado tem demanda EDI
    // (calculado antes da exportação para poder usar tanto no CSV quanto na tela)
    $temEdiPorDia = [];
    foreach ($dias as $dia) {
        $temEdiPorDia[$dia->format('Y-m-d')] = false;
    }
    foreach ($demandaPorComponente as $porData) {
        foreach ($porData as $data => $qtd) {
            if ($qtd > 0 && array_key_exists($data, $temEdiPorDia)) {
                $temEdiPorDia[$data] = true;
            }
        }
    }

    // Exportação CSV: gera o arquivo e encerra antes de renderizar HTML
    if ($tipoExportacao === 'csv' && $erroGeral === null) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="evolucao-estoque-' . date('Y-m-d-His') . '.csv"');
        echo "\xEF\xBB\xBF";
        $saida = fopen('php://output', 'w');

        // Linha 1: projetos com demanda EDI naquele dia (mesma linha nova da tela)
        $vaziosEntregas = array_fill(0, $maxEntregas * 2, '');
        $linhaProjetos = array_merge(['', '', '', '', '', ''], $vaziosEntregas);
        foreach ($dias as $dia) {
            $chave = $dia->format('Y-m-d');
            $linhaProjetos[] = $projetosPorDia[$chave] ?? '';
        }
        fputcsv($saida, $linhaProjetos, ';', '"', '');

        // Linha 2: marcador (●) nos dias com demanda EDI
        $linhaMarcador = array_merge(['', '', '', '', '', ''], $vaziosEntregas);
        foreach ($dias as $dia) {
            $chave = $dia->format('Y-m-d');
            $linhaMarcador[] = $temEdiPorDia[$chave] ? '●' : '';
        }
        fputcsv($saida, $linhaMarcador, ';', '"', '');

        // Linha 3: número da semana
        $linhaSemana = array_merge(['', '', '', '', '', ''], $vaziosEntregas);
        foreach ($dias as $dia) {
            $linhaSemana[] = $dia->format('W');
        }
        fputcsv($saida, $linhaSemana, ';', '"', '');

        // Linha 4: cabeçalho com as datas
        $cabecalhoCsv = ['codigo_componente', 'descricao', 'fornecedores', 'projetos', 'consumo', 'estoque_hoje'];
        for ($i = 1; $i <= $maxEntregas; $i++) {
            $cabecalhoCsv[] = 'Transito_' . $i;
            $cabecalhoCsv[] = 'ETA_' . $i;
        }
        foreach ($dias as $dia) {
            $cabecalhoCsv[] = $dia->format('d/m/Y');
        }
        fputcsv($saida, $cabecalhoCsv, ';', '"', '');

        foreach ($componentesParaCalcular as $componente) {
            $linhaCsv = [
                $componente['codigo_componente'],
                $componente['descricao'],
                $componente['fornecedores'],
                $componente['projetos'],
                $componente['consumos'],
                numeroBr($componente['estoque_atual']),
            ];
            for ($i = 0; $i < $maxEntregas; $i++) {
                $ent = $componente['entregas'][$i] ?? null;
                $linhaCsv[] = $ent ? numeroBr($ent['quantidade']) : '';
                $linhaCsv[] = $ent ? date('d/m/Y', strtotime($ent['data'])) : '';
            }
            foreach ($dias as $dia) {
                $linhaCsv[] = numeroBr($componente['saldos'][$dia->format('Y-m-d')] ?? 0);
            }
            fputcsv($saida, $linhaCsv, ';', '"', '');
        }

        fclose($saida);
        exit;
    }

    // Exportação .xlsx COLORIDA: mesmas cores da tela (hoje amarelo, dia com EDI
    // verde, saldo ≤ 0 rosa/vermelho, saldo 1–50 alerta, Trânsito/ETA azul-claro,
    // ETA atrasada em vermelho) e com Componente…Estoque hoje + cabeçalho congelados.
    if ($tipoExportacao === 'xlsx' && $erroGeral === null) {
        // Índices de estilo (cellXfs) definidos em styles.xml mais abaixo
        $E = [
            'cab' => 1, 'texto' => 2, 'num' => 3, 'num_evento' => 4, 'num_hoje' => 5,
            'num_neg' => 6, 'num_alerta' => 7, 'num_hoje_neg' => 8, 'num_hoje_alerta' => 9,
            'transito' => 10, 'eta' => 11, 'eta_atrasada' => 12, 'cab_hoje' => 13, 'cab_transito' => 14,
            'componente' => 15, 'cab_evento' => 16, 'texto_num' => 17, 'cab_escuro' => 18, 'num_escuro' => 19,
        ];
        $hojeChaveX = $hoje->format('Y-m-d');
        $linhasXml = [];
        $celula = function (int $col, int $lin, $valor, int $estilo, bool $numero = false): string {
            $ref = colunaExcelEvolucao($col) . $lin;
            if ($valor === null || $valor === '') {
                return '<c r="' . $ref . '" s="' . $estilo . '"/>';
            }
            if ($numero) {
                return '<c r="' . $ref . '" s="' . $estilo . '"><v>' . (0 + $valor) . '</v></c>';
            }
            return '<c r="' . $ref . '" s="' . $estilo . '" t="inlineStr"><is><t xml:space="preserve">' . xmlEscEvolucao((string) $valor) . '</t></is></c>';
        };
        $colInicioDias = 7 + $maxEntregas * 2;

        // Linhas 1 a 4: projetos do dia, marcador EDI, semana (+ títulos), data
        for ($lin = 1; $lin <= 4; $lin++) {
            $cels = [];
            $titulos = ['Componente', 'Descrição', 'Fornecedor', 'Projeto', 'Consumo', 'Estoque hoje'];
            for ($c = 1; $c <= 6; $c++) {
                $cels[] = $celula($c, $lin, $lin === 4 ? $titulos[$c - 1] : '', ($lin >= 3 || $c === 6) ? $E['cab_escuro'] : $E['cab']);
            }
            for ($i = 1; $i <= $maxEntregas; $i++) {
                $col = 6 + ($i - 1) * 2 + 1;
                $estTr = $lin >= 3 ? $E['cab_escuro'] : $E['cab_transito'];
                $cels[] = $celula($col, $lin, $lin === 4 ? 'Trânsito ' . $i : '', $estTr);
                $cels[] = $celula($col + 1, $lin, $lin === 4 ? 'ETA ' . $i : '', $estTr);
            }
            foreach ($dias as $k => $dia) {
                $chave = $dia->format('Y-m-d');
                $estiloCab = $lin >= 3 ? $E['cab_escuro'] : ($chave === $hojeChaveX ? $E['cab_hoje'] : ($temEdiPorDia[$chave] ? $E['cab_evento'] : $E['cab']));
                if ($lin === 1) { $v = $projetosPorDia[$chave] ?? ''; }
                elseif ($lin === 2) { $v = $temEdiPorDia[$chave] ? '● ' . numeroBr($demandaEdiBrutaPorDia[$chave] ?? 0, 0) : ''; }
                elseif ($lin === 3) { $v = $dia->format('W'); }
                else { $v = $dia->format('d/m/Y'); }
                $cels[] = $celula($colInicioDias + $k, $lin, $v, $estiloCab);
            }
            $linhasXml[] = '<row r="' . $lin . '">' . implode('', $cels) . '</row>';
        }

        $lin = 5;
        foreach ($componentesParaCalcular as $componente) {
            $cels = [];
            $cels[] = $celula(1, $lin, $componente['codigo_componente'], $E['componente']);
            $cels[] = $celula(2, $lin, $componente['descricao'], $E['texto']);
            $cels[] = $celula(3, $lin, $componente['fornecedores'] ?: 'Não informado', $E['texto']);
            $cels[] = $celula(4, $lin, $componente['projetos'] ?: '—', $E['texto']);
            $cels[] = $celula(5, $lin, $componente['consumos'] ?: '—', $E['texto_num']);
            $cels[] = $celula(6, $lin, round((float) $componente['estoque_atual']), $E['num_escuro'], true);
            for ($i = 0; $i < $maxEntregas; $i++) {
                $ent = $componente['entregas'][$i] ?? null;
                $col = 7 + $i * 2;
                $cels[] = $celula($col, $lin, $ent ? round($ent['quantidade']) : '', $E['transito'], true);
                $atrasada = $ent && $ent['data'] < $hojeChaveX;
                $cels[] = $celula($col + 1, $lin, $ent ? date('d/m/Y', strtotime($ent['data'])) : '', $atrasada ? $E['eta_atrasada'] : $E['eta']);
            }
            foreach ($dias as $k => $dia) {
                $chave = $dia->format('Y-m-d');
                $saldo = (float) ($componente['saldos'][$chave] ?? 0);
                $ehHoje = $chave === $hojeChaveX;
                if ($saldo <= 0) { $est = $ehHoje ? $E['num_hoje_neg'] : $E['num_neg']; }
                elseif ($saldo >= 1 && $saldo <= 50) { $est = $ehHoje ? $E['num_hoje_alerta'] : $E['num_alerta']; }
                elseif ($ehHoje) { $est = $E['num_hoje']; }
                elseif ($temEdiPorDia[$chave]) { $est = $E['num_evento']; }
                else { $est = $E['num']; }
                $cels[] = $celula($colInicioDias + $k, $lin, round($saldo), $est, true);
            }
            $linhasXml[] = '<row r="' . $lin . '">' . implode('', $cels) . '</row>';
            $lin++;
        }

        // Larguras das colunas
        $cols = '<cols>'
            . '<col min="1" max="1" width="13" customWidth="1"/>'
            . '<col min="2" max="2" width="34" customWidth="1"/>'
            . '<col min="3" max="3" width="18" customWidth="1"/>'
            . '<col min="4" max="4" width="24" customWidth="1"/>'
            . '<col min="5" max="5" width="9" customWidth="1"/>'
            . '<col min="6" max="6" width="12" customWidth="1"/>';
        if ($maxEntregas > 0) {
            $cols .= '<col min="7" max="' . (6 + $maxEntregas * 2) . '" width="11" customWidth="1"/>';
        }
        $cols .= '<col min="' . $colInicioDias . '" max="' . ($colInicioDias + max(0, count($dias) - 1)) . '" width="11" customWidth="1"/></cols>';

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews><sheetView workbookViewId="0"><pane xSplit="6" ySplit="4" topLeftCell="G5" activePane="bottomRight" state="frozen"/>'
            . '<selection pane="topRight"/><selection pane="bottomLeft"/><selection pane="bottomRight" activeCell="G5" sqref="G5"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . $cols
            . '<sheetData>' . implode('', $linhasXml) . '</sheetData>'
            . '</worksheet>';

        // Cores: mesmas da tela (CSS da evolucao-table)
        $fills = [
            'FFF5F8FB', // 2 cabeçalho
            'FFFFF7C4', // 3 hoje
            'FFEAF8EE', // 4 evento EDI
            'FFFFD6E0', // 5 saldo negativo
            'FFFFF0F5', // 6 alerta 1-50
            'FFF3F7FF', // 7 trânsito/ETA
            'FFFFB366', // 8 (reserva)
            'FF002060', // 9 cabeçalho azul-escuro
        ];
        $fillsXml = '<fills count="' . (2 + count($fills)) . '"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>';
        foreach ($fills as $cor) {
            $fillsXml .= '<fill><patternFill patternType="solid"><fgColor rgb="' . $cor . '"/><bgColor indexed="64"/></patternFill></fill>';
        }
        $fillsXml .= '</fills>';
        // fonts: 0 normal, 1 negrito, 2 vermelho negrito, 3 alerta negrito, 4 azul trânsito
        $fontsXml = '<fonts count="6">'
            . '<font><sz val="10"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="10"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FFC53535"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FF8C7355"/><name val="Calibri"/></font>'
            . '<font><sz val="10"/><color rgb="FF1D3F72"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '</fonts>';
        $bordersXml = '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left/><right style="thin"><color rgb="FFE9EEF3"/></right><top/><bottom style="thin"><color rgb="FFE9EEF3"/></bottom><diagonal/></border></borders>';
        // xf: numFmtId 3 = "#,##0"
        $xf = function (int $num, int $font, int $fill, string $alinh = 'right') {
            return '<xf numFmtId="' . $num . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="1" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="' . $alinh . '" vertical="center"/></xf>';
        };
        $xfs = [
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>', // 0
            $xf(0, 1, 2, 'center'),   // 1 cab
            $xf(0, 0, 0, 'left'),     // 2 texto
            $xf(3, 0, 0),             // 3 num
            $xf(3, 0, 4),             // 4 num evento
            $xf(3, 0, 3),             // 5 num hoje
            $xf(3, 2, 5),             // 6 num negativo
            $xf(3, 3, 6),             // 7 num alerta
            $xf(3, 2, 3),             // 8 hoje + negativo
            $xf(3, 3, 3),             // 9 hoje + alerta
            $xf(3, 4, 7),             // 10 trânsito
            $xf(0, 4, 7, 'center'),   // 11 ETA
            $xf(0, 2, 7, 'center'),   // 12 ETA atrasada
            $xf(0, 1, 3, 'center'),   // 13 cab hoje
            $xf(0, 1, 7, 'center'),   // 14 cab trânsito
            $xf(0, 1, 0, 'left'),     // 15 componente (negrito)
            $xf(0, 1, 4, 'center'),   // 16 cab dia com EDI
            $xf(0, 0, 0, 'right'),    // 17 texto alinhado à direita (consumo)
            $xf(0, 5, 9, 'center'),   // 18 cabeçalho azul-escuro, fonte branca
            $xf(3, 5, 9),             // 19 número azul-escuro, fonte branca (Estoque hoje)
        ];
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $fontsXml . $fillsXml . $bordersXml
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';

        $arquivos = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="Evolução geral" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '</Relationships>',
            'xl/styles.xml' => $styles,
            'xl/worksheets/sheet1.xml' => $sheet,
        ];

        $conteudoXlsx = zipSimplesEvolucao($arquivos);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="evolucao-estoque-' . date('Y-m-d-His') . '.xlsx"');
        header('Content-Length: ' . strlen($conteudoXlsx));
        echo $conteudoXlsx;
        exit;
    }

    // A partir daqui, só interessa a página atual (a exportação já saiu acima)
    $componentesPagina = $exportando ? $componentesPagina : $componentesParaCalcular;
} catch (Throwable $erro) {
    error_log('Erro na evolução geral: ' . $erro->getMessage());
    $erroGeral = 'Não foi possível carregar os dados. Verifique se a tabela "programacao" já foi criada.';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Evolução geral do estoque</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/dashboard.css" rel="stylesheet">
    <style>
        .evolucao-table { border-collapse: separate; border-spacing: 0; font-size: .78rem; }
        .evolucao-table th, .evolucao-table td {
            padding: 7px 9px;
            text-align: right;
            white-space: nowrap;
            border-bottom: 1px solid #e9eef3;
            border-right: 1px solid #f0f3f6;
        }
        .evolucao-table thead th {
            position: sticky;
            background: #f5f8fb;
            z-index: 2;
        }
        .evolucao-table thead tr:nth-child(1) th {
            top: 0; height: 20px; line-height: 20px; padding: 0 8px;
            font-size: .68rem; font-weight: 600; color: #536578;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 110px;
        }
        .evolucao-table thead tr:nth-child(2) th {
            top: 20px; height: 20px; line-height: 20px; padding: 0 8px;
            font-size: .72rem; font-weight: 700; white-space: nowrap;
        }
        .evolucao-table thead tr:nth-child(3) th { top: 40px; height: 28px; line-height: 28px; padding: 0 8px; }
        .evolucao-table thead tr:nth-child(4) th { top: 68px; height: 28px; line-height: 28px; padding: 0 8px; }
        .evolucao-table th:nth-child(-n+6),
        .evolucao-table td:nth-child(-n+6) {
            position: sticky;
            text-align: left;
            background: #fff;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .evolucao-table thead th:nth-child(-n+6) { background: #f5f8fb; z-index: 4; }
        .evolucao-table th:nth-child(1), .evolucao-table td:nth-child(1) { left: 0; width: 110px; min-width: 110px; max-width: 110px; z-index: 3; }
        .evolucao-table th:nth-child(2), .evolucao-table td:nth-child(2) { left: 110px; width: 220px; min-width: 220px; max-width: 220px; z-index: 3; }
        .evolucao-table th:nth-child(3), .evolucao-table td:nth-child(3) { left: 330px; width: 140px; min-width: 140px; max-width: 140px; z-index: 3; }
        .evolucao-table th:nth-child(4), .evolucao-table td:nth-child(4) { left: 470px; width: 150px; min-width: 150px; max-width: 150px; z-index: 3; }
        .evolucao-table th:nth-child(5), .evolucao-table td:nth-child(5) { left: 620px; width: 100px; min-width: 100px; max-width: 100px; z-index: 3; text-align: right; }
        .evolucao-table th:nth-child(6), .evolucao-table td:nth-child(6) { left: 720px; width: 100px; min-width: 100px; max-width: 100px; z-index: 3; text-align: right; box-shadow: 2px 0 0 #dce4ec; }
        /* Colunas Trânsito/ETA (programação): ficam ENTRE "Estoque hoje" e os dias e
           NÃO são fixas — rolam junto com os dias, igual à planilha. */
        .evolucao-table .col-transito { background: #f3f7ff; color: #1d3f72; min-width: 70px; }
        .evolucao-table .col-eta { background: #f3f7ff; color: #1d3f72; min-width: 84px; border-right: 2px solid #dce4ec; }
        .evolucao-table .eta-atrasada { color: #c53535; font-weight: 750; }
        .evolucao-table thead th.col-transito,
        .evolucao-table thead th.col-eta { text-align: center; }
        /* Cabeçalho (títulos + semana e linha das datas) em azul-escuro com fonte
           branca, igual à planilha. !important pra vencer o amarelo de "hoje", o
           azul-claro de Trânsito/ETA e o fundo das colunas fixas. */
        .evolucao-table thead tr:nth-child(3) th,
        .evolucao-table thead tr:nth-child(4) th {
            background: #002060 !important;
            color: #fff !important;
            font-weight: 700;
            border-right-color: #1b3a7a;
            border-bottom-color: #1b3a7a;
        }
        /* Coluna "Estoque hoje" inteira em azul-escuro com fonte branca (mesmo azul
           do cabeçalho), pra destacar o ponto de partida do saldo. */
        .evolucao-table th:nth-child(6),
        .evolucao-table td:nth-child(6) {
            background: #002060 !important;
            color: #fff !important;
            font-weight: 700;
        }
        .col-evento { background: #eaf8ee; }
        .col-hoje { background: #fff7c4 !important; }
        .saldo-negativo { background: #ffd6e0; color: #c53535; font-weight: 750; }
        .saldo-alerta { background: #fff0f5; color: #8c7355; font-weight: 750; }
        .dot-edi {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #2f9e44;
            margin-right: 3px;
            vertical-align: middle;
        }
        /* Clique no componente: marca o FUNDO da célula em laranja (a fonte não
           muda). !important porque a coluna é fixa (sticky) e tem fundo branco. */
        .evolucao-table td.cel-componente { cursor: pointer; user-select: none; }
        .evolucao-table td.cel-componente.marcado { background: #ffb366 !important; }
        /* Tela larga, igual à Programação (98% da largura da janela) */
        .dashboard-container { max-width: 98vw !important; }
        .scroll-wrapper { max-height: 75vh; overflow: auto; border: 1px solid #dce4ec; border-radius: 12px; }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="container-fluid dashboard-container d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <span class="eyebrow">Supply Chain • Planejamento de materiais</span>
                <h1>Evolução geral do estoque</h1>
                <p class="mb-0">Saldo projetado dia a dia, vários componentes lado a lado</p>
            </div>
            <nav class="d-flex flex-wrap gap-2" aria-label="Ações do sistema">
                <a class="btn btn-light btn-sm" href="index.php">🏠 Dashboard</a>
                <a class="btn btn-outline-light btn-sm" href="estoque.php">Estoque</a>
                <a class="btn btn-outline-light btn-sm" href="edi.php">EDI</a>
                <a class="btn btn-outline-light btn-sm" href="bomnova.php">BOM</a>
                <a class="btn btn-outline-light btn-sm" href="programacao.php">Programação</a>
                <a class="btn btn-outline-light btn-sm" href="parametros_compra.php">Parâmetros</a>
                <a class="btn btn-outline-light btn-sm" href="evolucao_geral.php">Evolução geral</a>
                <a class="btn btn-outline-light btn-sm" href="planejamento_compras.php">Planejamento de compras</a>
                <a class="btn btn-outline-light btn-sm" href="pedido_compra.php">📄 Pedido de Compra</a>
            </nav>
        </div>
    </header>

    <main class="container-fluid dashboard-container py-4">
        <?php if ($erroGeral !== null): ?>
            <div class="alert alert-danger" role="alert"><?php echo h($erroGeral); ?></div>
        <?php else: ?>

        <section class="filter-panel mb-4">
            <div class="section-heading">
                <div>
                    <span class="eyebrow text-primary">Filtros</span>
                    <h2>Escolha quais componentes ver</h2>
                </div>
                <a class="btn btn-outline-secondary btn-sm" href="evolucao_geral.php">Limpar filtros</a>
            </div>
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-12 col-lg-4">
                    <label class="form-label" for="busca">Componente, material ou descrição</label>
                    <input class="form-control" id="busca" name="busca" value="<?php echo h($busca); ?>" placeholder="Ex.: 12057429 ou clip">
                </div>
                <div class="col-6 col-md-4 col-lg-3">
                    <label class="form-label" for="fornecedor">Fornecedor</label>
                    <select class="form-select" id="fornecedor" name="fornecedor">
                        <option value="">Todos</option>
                        <?php foreach ($fornecedores as $opcao): ?>
                            <option value="<?php echo h($opcao); ?>" <?php echo $fornecedor === $opcao ? 'selected' : ''; ?>><?php echo h($opcao); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-4 col-lg-3">
                    <label class="form-label" for="projeto">Projeto</label>
                    <select class="form-select" id="projeto" name="projeto">
                        <option value="">Todos</option>
                        <?php foreach ($projetos as $opcao): ?>
                            <option value="<?php echo h($opcao); ?>" <?php echo $projeto === $opcao ? 'selected' : ''; ?>><?php echo h($opcao); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-4 col-lg-1 d-grid">
                    <button class="btn btn-primary" type="submit">Aplicar</button>
                </div>
            </form>
        </section>

        <section class="table-card">
            <div class="table-toolbar">
                <div>
                    <span class="eyebrow text-primary">Projeção</span>
                    <h2>Saldo dia a dia por componente</h2>
                    <p><?php echo numeroBr($totalComponentes); ?> componente(s) encontrado(s) • de <?php echo h($hoje->format('d/m/Y')); ?> até <?php echo h($dataLimite->format('d/m/Y')); ?> • coluna amarela = hoje</p>
                </div>
                <form method="GET" class="d-flex align-items-center gap-2">
                    <?php foreach ($_GET as $chave => $valor): ?>
                        <?php if (!in_array($chave, ['por_pagina', 'pagina', 'exportar'], true)): ?>
                            <input type="hidden" name="<?php echo h($chave); ?>" value="<?php echo h($valor); ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <a class="btn btn-outline-success btn-sm" href="<?php echo h(urlComGeral(['exportar' => 'xlsx', 'pagina' => null])); ?>">📊 Exportar Excel</a>
                    <label class="small text-muted text-nowrap" for="por_pagina">Componentes por página</label>
                    <select class="form-select form-select-sm" id="por_pagina" name="por_pagina" onchange="this.form.submit()">
                        <?php foreach ([10, 20, 50] as $quantidade): ?>
                            <option value="<?php echo $quantidade; ?>" <?php echo $porPagina === $quantidade ? 'selected' : ''; ?>><?php echo $quantidade; ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <div class="scroll-wrapper">
                <table class="evolucao-table mb-0">
                    <thead>
                        <tr>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <?php for ($i = 1; $i <= $maxEntregas; $i++): ?><th class="col-transito"></th><th class="col-eta"></th><?php endfor; ?>
                            <?php foreach ($dias as $dia): ?>
                                <?php $chave = $dia->format('Y-m-d'); ?>
                                <th class="linha-projeto <?php echo $chave === $hoje->format('Y-m-d') ? 'col-hoje' : ''; ?>" title="<?php echo h($projetosPorDia[$chave] ?? ''); ?>">
                                    <?php echo h($projetosPorDia[$chave] ?? ''); ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <?php for ($i = 1; $i <= $maxEntregas; $i++): ?><th class="col-transito"></th><th class="col-eta"></th><?php endfor; ?>
                            <?php foreach ($dias as $dia): ?>
                                <?php $chave = $dia->format('Y-m-d'); ?>
                                <th class="linha-marcador <?php echo $chave === $hoje->format('Y-m-d') ? 'col-hoje' : ''; ?>">
                                    <?php if ($temEdiPorDia[$chave]): ?>
                                        <span class="dot-edi" title="Demanda EDI nesta data"></span><?php echo numeroBr($demandaEdiBrutaPorDia[$chave] ?? 0, 0); ?>
                                    <?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <?php for ($i = 1; $i <= $maxEntregas; $i++): ?><th class="col-transito"></th><th class="col-eta"></th><?php endfor; ?>
                            <?php foreach ($dias as $dia): ?>
                                <?php $chave = $dia->format('Y-m-d'); ?>
                                <th class="<?php echo $chave === $hoje->format('Y-m-d') ? 'col-hoje' : ''; ?>">
                                    <?php echo $dia->format('W'); ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <th>Componente</th>
                            <th>Descrição</th>
                            <th>Fornecedor</th>
                            <th>Projeto</th>
                            <th>Consumo</th>
                            <th>Estoque hoje</th>
                            <?php for ($i = 1; $i <= $maxEntregas; $i++): ?>
                                <th class="col-transito" title="Quantidade da <?php echo $i; ?>ª entrega programada (Programação pendente)">Trânsito <?php echo $i; ?></th>
                                <th class="col-eta" title="Data da <?php echo $i; ?>ª entrega programada">ETA <?php echo $i; ?></th>
                            <?php endfor; ?>
                            <?php foreach ($dias as $dia): ?>
                                <?php $chave = $dia->format('Y-m-d'); ?>
                                <th class="<?php echo $chave === $hoje->format('Y-m-d') ? 'col-hoje' : ''; ?>">
                                    <?php echo $dia->format('d/m/Y'); ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($componentesPagina)): ?>
                            <tr><td colspan="<?php echo 6 + $maxEntregas * 2 + count($dias); ?>" class="empty-state">Nenhum componente encontrado para os filtros selecionados.</td></tr>
                        <?php else: ?>
                            <?php foreach ($componentesPagina as $componente): ?>
                                <tr>
                                    <td class="cel-componente" data-componente="<?php echo h($componente['codigo_componente']); ?>" title="Clique para marcar/desmarcar"><strong class="component-code"><?php echo h($componente['codigo_componente']); ?></strong></td>
                                    <td title="<?php echo h($componente['descricao']); ?>"><?php echo h($componente['descricao']); ?></td>
                                    <td title="<?php echo h($componente['fornecedores'] ?: 'Não informado'); ?>"><?php echo h($componente['fornecedores'] ?: 'Não informado'); ?></td>
                                    <td title="<?php echo h($componente['projetos'] ?: '—'); ?>"><?php echo h($componente['projetos'] ?: '—'); ?></td>
                                    <td class="text-end"><?php echo h($componente['consumos'] ?: '—'); ?></td>
                                    <td><?php echo numeroBr($componente['estoque_atual']); ?></td>
                                    <?php for ($i = 0; $i < $maxEntregas; $i++): ?>
                                        <?php $ent = $componente['entregas'][$i] ?? null; ?>
                                        <td class="col-transito"><?php echo $ent ? numeroBr($ent['quantidade']) : ''; ?></td>
                                        <td class="col-eta <?php echo ($ent && $ent['data'] < $hoje->format('Y-m-d')) ? 'eta-atrasada' : ''; ?>" <?php if ($ent && $ent['data'] < $hoje->format('Y-m-d')): ?>title="Entrega atrasada (data já passou e ainda não foi marcada como atendida)"<?php endif; ?>><?php echo $ent ? h(date('d/m/Y', strtotime($ent['data']))) : ''; ?></td>
                                    <?php endfor; ?>
                                    <?php foreach ($dias as $dia): ?>
                                        <?php
                                            $chave = $dia->format('Y-m-d');
                                            $saldo = $componente['saldos'][$chave] ?? 0.0;
                                            $classes = [];
                                            if ($temEdiPorDia[$chave]) {
                                                $classes[] = 'col-evento';
                                            }
                                            if ($chave === $hoje->format('Y-m-d')) {
                                                $classes[] = 'col-hoje';
                                            }
                                            if ($saldo <= 0) {
                                                $classes[] = 'saldo-negativo';
                                            } elseif ($saldo >= 1 && $saldo <= 50) {
                                                $classes[] = 'saldo-alerta';
                                            }
                                        ?>
                                        <td class="<?php echo h(implode(' ', $classes)); ?>"><?php echo numeroBr($saldo); ?></td>
                                    <?php endforeach; ?>
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
                        <a class="btn btn-outline-secondary btn-sm <?php echo $pagina <= 1 ? 'disabled' : ''; ?>" href="<?php echo h(urlComGeral(['pagina' => max(1, $pagina - 1)])); ?>">Anterior</a>
                        <a class="btn btn-outline-secondary btn-sm <?php echo $pagina >= $totalPaginas ? 'disabled' : ''; ?>" href="<?php echo h(urlComGeral(['pagina' => min($totalPaginas, $pagina + 1)])); ?>">Próxima</a>
                    </div>
                </nav>
            <?php endif; ?>
        </section>

        <?php endif; ?>
    </main>
    <script>
        // Marca/desmarca o componente clicado. A marcação fica guardada neste
        // navegador, então continua lá ao trocar de página, filtrar ou recarregar.
        (function () {
            const CHAVE = 'evolucao_componentes_marcados';
            let marcados = [];
            try { marcados = JSON.parse(localStorage.getItem(CHAVE) || '[]'); } catch (e) { marcados = []; }
            const salvar = () => { try { localStorage.setItem(CHAVE, JSON.stringify(marcados)); } catch (e) {} };

            document.querySelectorAll('td.cel-componente').forEach(function (celula) {
                if (marcados.includes(celula.dataset.componente)) {
                    celula.classList.add('marcado');
                }
                celula.addEventListener('click', function () {
                    const codigo = celula.dataset.componente;
                    const ficouMarcado = celula.classList.toggle('marcado');
                    marcados = marcados.filter((c) => c !== codigo);
                    if (ficouMarcado) { marcados.push(codigo); }
                    salvar();
                });
            });
        })();
    </script>
</body>
</html>
