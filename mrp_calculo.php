<?php

// Folga entre o RECEBIMENTO do material e o dia da DEMANDA (receber, conferir e
// disponibilizar): data de necessidade = data da demanda − este número de dias.
// Era 30; passou pra 20; agora 15. (Os comentários abaixo que falam em "30 dias" se referem a esta folga.)
if (!defined('MRP_DIAS_RECEBIMENTO_ANTES_DEMANDA')) {
    define('MRP_DIAS_RECEBIMENTO_ANTES_DEMANDA', 15);
}

// Cobertura mínima de uma parcela (dias): não sugere compra que dure menos que isso
// depois de chegar. Se der pra adiar sem faltar, a necessidade é empurrada pra próxima
// parcela; se adiar causaria falta, esta parcela é aumentada pra cobrir esses dias.
if (!defined('MRP_DIAS_COBERTURA_MINIMA_PARCELA')) {
    define('MRP_DIAS_COBERTURA_MINIMA_PARCELA', 30);
}

// ---------- Planta (EDI por planta: 2401 GVT / 2402 GUA) ----------
// Normaliza o texto da planta vindo de planilha/formulário: "2401", "2401 — GVT",
// "GVT", "gvt" → "2401"; "2402", "GUA" → "2402". Outros valores voltam só sem espaços.
if (!function_exists('normalizarPlantaMrp')) {
    function normalizarPlantaMrp(?string $planta): string
    {
        $p = strtoupper(trim((string) $planta));
        if ($p === '') {
            return '';
        }
        if (preg_match('/\b(24\d{2})\b/', $p, $m)) {
            return $m[1];
        }
        $porSigla = ['GVT' => '2401', 'GUA' => '2402'];
        return $porSigla[$p] ?? trim((string) $planta);
    }
}

// true se a coluna existe na tabela (resultado guardado por requisição). Usado pra
// telas funcionarem antes e depois de um ALTER TABLE (ex.: coluna "planta" no EDI).
if (!function_exists('tabelaTemColunaMrp')) {
    function tabelaTemColunaMrp(mysqli $conn, string $tabela, string $coluna): bool
    {
        static $cache = [];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $tabela) || !preg_match('/^[A-Za-z0-9_]+$/', $coluna)) {
            return false;
        }
        $chave = $tabela . '.' . $coluna;
        if (!array_key_exists($chave, $cache)) {
            try {
                $res = mysqli_query($conn, "SHOW COLUMNS FROM `$tabela` LIKE '" . mysqli_real_escape_string($conn, $coluna) . "'");
                $cache[$chave] = $res !== false && mysqli_num_rows($res) > 0;
            } catch (Throwable $e) {
                $cache[$chave] = false;
            }
        }
        return $cache[$chave];
    }
}

// Funções de cálculo do MRP compartilhadas entre telas (Planejamento de Compras e
// Evolução do Estoque). Ficam num arquivo só pra qualquer ajuste na lógica (piso,
// MOQ, Estoque Máximo, timing de disponibilização etc.) valer pras duas telas ao
// mesmo tempo, sem risco de uma ficar desatualizada em relação à outra.

// Calcula o estoque de segurança automaticamente (mesma lógica do dashboard, index.php):
// Z × desvio-padrão da demanda semanal × raiz(lead time em semanas), onde o desvio-padrão
// usa o setup% cadastrado em Parâmetros de Compra como proxy da variabilidade da demanda.
// Sem setup cadastrado (0%), o resultado é 0 — nenhuma margem extra. Z vem de conexao.php
// (MRP_Z_NIVEL_SERVICO).
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

// Dias de cobertura: quantos dias uma quantidade dura contra a demanda, a partir
// de $inicio (dia a dia, até a demanda acumulada passar da quantidade).
//  - $incluirAtrasada = true: demanda com data antes de $inicio entra no 1º dia
//    (é o caso do estoque de hoje, que já precisa cobrir o que está em atraso).
//  - Retorna null quando não existe demanda nenhuma no período (não dá pra medir)
//    e $limiteDias + 1 quando a quantidade dura mais que o limite.
// Usado em "Dias de estoque" (estoque de hoje) e "Dias de forecast" (quanto dura
// a compra sugerida a partir da data em que ela chega).
function diasDeCobertura(array $demandaPorData, DateTimeImmutable $inicio, float $quantidade, bool $incluirAtrasada = false, int $limiteDias = 365): ?int
{
    $inicioChave = $inicio->format('Y-m-d');
    $fimChave = $inicio->modify("+{$limiteDias} days")->format('Y-m-d');
    $atrasada = 0.0;
    $temDemanda = false;
    foreach ($demandaPorData as $d => $q) {
        if ($q <= 0) { continue; }
        if ($d < $inicioChave) {
            if ($incluirAtrasada) { $atrasada += $q; $temDemanda = true; }
        } elseif ($d <= $fimChave) {
            $temDemanda = true;
        }
    }
    if (!$temDemanda) {
        return null;
    }
    if ($quantidade <= 0) {
        return 0;
    }
    $acumulado = $atrasada;
    if ($acumulado > $quantidade) {
        return 0;
    }
    $dia = $inicio;
    for ($k = 0; $k <= $limiteDias; $k++) {
        $acumulado += (float) ($demandaPorData[$dia->format('Y-m-d')] ?? 0.0);
        if ($acumulado > $quantidade + 0.0001) {
            return $k;
        }
        $dia = $dia->modify('+1 day');
    }
    return $limiteDias + 1;
}

// Texto da célula de dias de cobertura (null = sem demanda; acima do limite = "> 365")
function textoDiasCobertura(?int $dias, int $limiteDias = 365): string
{
    if ($dias === null) {
        return 'sem demanda';
    }
    return $dias > $limiteDias ? '> ' . $limiteDias : number_format($dias, 0, ',', '.');
}

// Simula o saldo dia a dia (estoque + programação − demanda) e revisa a situação MÊS A MÊS
// (hoje, +1 mês, +2 meses...) em vez de tentar cobrir tudo de uma vez com uma única compra
// gigante — isso evita concentrar o pagamento de meses de estoque numa parcela só quando o
// problema real só aparece mais à frente.
//
// Em cada revisão mensal:
//   1) Só gera uma parcela se houver necessidade dentro do prazo de reação (até a PRÓXIMA
//      revisão + Lead Time do fornecedor) — uma necessidade mais distante é decidida na
//      revisão do mês em que ela realmente estiver chegando, não antecipada agora.
//   2) O gatilho (piso) não é só "saldo negativo": é a demanda da janela do Estoque Mínimo
//      (dias) cadastrado SOMADA ao Estoque de Segurança calculado (ver
//      calcularEstoqueSegurancaQtd) — ou seja, a compra não só cobre a demanda conhecida,
//      como também RECOMPÕE a folga de segurança depois que essa demanda for consumida.
//      Sem isso, uma compra "resolvida" podia deixar o saldo projetado zerado (sem
//      nenhuma margem), mesmo cobrindo o buraco.
//   3) A quantidade cobre o PIOR déficit dentro da janela de urgência do checkpoint, mas
//      sem passar do teto do Estoque Máximo (dias) cadastrado — a não ser que o MOQ do
//      fornecedor sozinho já exija mais que isso (aí o MOQ vence, é o mínimo que dá pra
//      pedir, mesmo passando do Máximo).
//   4) O MOQ é tratado como MÚLTIPLO (lote fechado): a quantidade (já com setup) é
//      arredondada pra cima pro próximo múltiplo do MOQ. Ex.: déficit de 8.464 com MOQ
//      100 vira 8.500; déficit de 120 com MOQ 500 vira 500. Depois injeta de volta na
//      simulação, pra próxima revisão já considerar
//      essa compra — na data em que o material realmente fica disponível (ver
//      "$iDisponibilidade" mais abaixo), não no dia em que o piso apenas detectou o
//      problema, nem no dia da demanda que gerou a necessidade.
//
// Retorna ['parcelas' => [...], 'saldo_por_dia' => ['Y-m-d' => saldo]] — o segundo campo
// é a simulação final (já com todas as parcelas injetadas), útil pra telas que queiram
// mostrar uma pré-visualização de "como ficaria o saldo com a(s) compra(s) sugerida(s)".
function calcularParcelasCompraPlanejamento(
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
    float $setupPercentual = 0.0,
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
        return ['parcelas' => [], 'saldo_por_dia' => []];
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
    $saldoInicialPlanejamento = $saldoAnterior;
    foreach ($dias as $i => $dia) {
        $chave = $dia->format('Y-m-d');
        $entrada = $programacaoPorData[$chave] ?? 0.0;
        $saida = $demandaPorData[$chave] ?? 0.0;
        $saldo = $saldoAnterior + $entrada - $saida;
        $saldoPorDia[$i] = $saldo;
        $demandaPorDia[$i] = $saida;
        $saldoAnterior = $saldo;
    }

    $indicePorData = [];
    foreach ($dias as $i => $dia) {
        $indicePorData[$dia->format('Y-m-d')] = $i;
    }

    // Prefixo de demanda: soma rápida de "quanto de demanda existe do dia k em diante,
    // por N dias" — usado pra calcular o piso do Estoque Mínimo de forma DINÂMICA, dia a
    // dia (ver comentário mais abaixo), sem precisar refazer a soma em cada iteração.
    $prefixoDemanda = array_fill(0, $n + 1, 0.0);
    for ($i = 0; $i < $n; $i++) {
        $prefixoDemanda[$i + 1] = $prefixoDemanda[$i] + $demandaPorDia[$i];
    }
    // Soma a demanda da janela ao Estoque de Segurança (em vez de só o maior entre os
    // dois) — assim a compra cobre a demanda conhecida E ainda deixa a folga de segurança
    // de pé depois que essa demanda for consumida (recomposição do mínimo, como no
    // exemplo de referência: Necessidade = Demanda + Estoque mínimo − Saldo).
    $pisoNoDia = function (int $indiceDia) use ($prefixoDemanda, $n, $minDias, $segurancaQtd): float {
        $fim = min($n, $indiceDia + $minDias);
        $minQtdNoDia = $prefixoDemanda[$fim] - $prefixoDemanda[$indiceDia];
        return $minQtdNoDia + $segurancaQtd;
    };

    $leadDias = $frozenDias + $transitDias;

    // Pontos de revisão mensal, do hoje até o fim do horizonte.
    $checkpoints = [];
    $cursorCheckpoint = $hoje;
    while ($cursorCheckpoint <= $horizonteFim) {
        $checkpoints[] = $cursorCheckpoint;
        $cursorCheckpoint = $cursorCheckpoint->modify('+1 month');
    }

    $parcelas = [];
    foreach ($checkpoints as $checkpoint) {
        $chaveCheckpoint = $checkpoint->format('Y-m-d');
        $iCheckpoint = $indicePorData[$chaveCheckpoint] ?? null;
        if ($iCheckpoint === null) {
            continue;
        }

        // Só age agora se o furo acontecer dentro do prazo de reação (até a próxima
        // revisão mensal + Lead Time + os 30 dias de receber/conferir/disponibilizar) — um
        // furo mais distante espera a revisão do mês dele. Os 30 dias entram aqui porque a
        // cadeia completa é: evento de demanda → -30 dias = data de necessidade
        // (recebimento) → -Lead Time = data sugerida do pedido. Sem somar os 30 dias, a
        // janela cortava o evento antes do ponto em que a DECISÃO de compra (data sugerida)
        // ainda cairia dentro do prazo desta revisão, deixando a necessidade escapar pra
        // revisão seguinte mesmo quando já era hora de agir.
        $fimUrgencia = min($horizonteFim, $checkpoint->modify('+1 month')->modify("+{$leadDias} days")->modify('+' . MRP_DIAS_RECEBIMENTO_ANTES_DEMANDA . ' days'));
        $iFimUrgencia = $indicePorData[$fimUrgencia->format('Y-m-d')] ?? ($n - 1);

        // Piso do Estoque Mínimo calculado DIA A DIA, olhando pra frente a partir de CADA
        // dia (não fixo a partir da data da revisão) — se calculássemos uma vez só a partir
        // do checkpoint, um evento grande de demanda dentro da janela de min dias inflava o
        // piso a ponto de um saldo positivo logo após esse mesmo evento (já coberto, sem
        // furar) parecer "abaixo do mínimo" só porque aquele evento generoso fazia parte da
        // conta do próprio piso. Calculando a partir do dia sendo avaliado, o piso reflete
        // só a demanda que ainda ESTÁ POR VIR dali pra frente.
        $piorDeficit = null;
        $iPior = null;
        $iPrimeiroDeficit = null;
        for ($k = $iCheckpoint; $k <= $iFimUrgencia; $k++) {
            $deficit = $pisoNoDia($k) - $saldoPorDia[$k];
            if ($piorDeficit === null || $deficit > $piorDeficit) {
                $piorDeficit = $deficit;
                $iPior = $k;
            }
            if ($deficit > 0 && $iPrimeiroDeficit === null) {
                $iPrimeiroDeficit = $k; // primeiro dia que já fura, não só o pior
            }
        }

        if ($piorDeficit === null || $piorDeficit <= 0) {
            continue; // nenhum dia fura o piso dinâmico nesta janela — nada a fazer
        }

        // Antes de sugerir uma compra NOVA, verifica se a programação que JÁ FOI colocada
        // (mesmo atrasada — ela ainda vai chegar) resolve esse mergulho sozinha. A checagem
        // parte do PRIMEIRO dia que já fura ($iPrimeiroDeficit), não do pior — um furo
        // detectado bem na borda da janela (porque o piso de 30 dias já "enxerga" um evento
        // futuro) nunca conseguiria ver uma recuperação que só acontece um pouco depois
        // dessa borda, mesmo que ela já esteja garantida por uma entrada programada. A janela
        // de recuperação usa o mesmo horizonte do Estoque Mínimo (minDias): se dentro desse
        // prazo o saldo volta a ficar igual ou acima do piso (também recalculado dia a dia),
        // é só um problema de PRAZO de entrega — não dispara uma compra nova em cima da que
        // já está a caminho.
        $fimRecuperacao = min($n - 1, $iPrimeiroDeficit + $minDias);
        $recuperaSozinho = false;
        // Só conta como "recupera sozinho" se o SALDO subir (entrou programação) — não
        // quando é o PISO que cai. Logo depois de um dia de demanda, a janela do Mínimo
        // pode ficar vazia por alguns dias e o piso despenca pro Estoque de Segurança;
        // antes, isso era confundido com recuperação e a revisão pulava a compra,
        // empurrando a necessidade pra revisão seguinte (já atrasada).
        for ($k = $iPrimeiroDeficit; $k <= $fimRecuperacao; $k++) {
            if ($saldoPorDia[$k] >= $pisoNoDia($k)) {
                $recuperaSozinho = true;
                break;
            }
        }
        // A checagem "recupera sozinho" acima ficou só como referência e NÃO pula
        // mais a revisão: ela se enganava (uma entrada que sobe o saldo por poucos
        // dias, ou o piso caindo logo depois de uma demanda, contavam como
        // "resolvido") e fazia revisões inteiras serem puladas — em item de lead
        // time longo isso gerava furo (ex.: 12051184). Agora quem decide é a regra
        // "precisa agora?" mais abaixo, que já considera a programação colocada,
        // porque o déficit é medido a partir da chegada.
        unset($recuperaSozinho);

        // O evento de demanda real que gera a necessidade: caminha a partir do PRIMEIRO
        // furo ($iPrimeiroDeficit) — não do pior — até achar o primeiro dia com demanda
        // real. Antes, a busca partia do pior dia e podia correr sem limite até o fim do
        // horizonte de 12 meses atrás de um evento real, gerando datas de necessidade fora
        // do escopo da própria revisão mensal.
        //
        // O limite da busca NÃO é só $iFimUrgencia: pisoNoDia($iPrimeiroDeficit) soma a
        // demanda de [$iPrimeiroDeficit, $iPrimeiroDeficit + $minDias) — ou seja, o piso já
        // "enxergou" um evento de demanda até $minDias dias à frente pra disparar o furo
        // logo em $iPrimeiroDeficit. Se essa janela de $minDias for maior que o que sobra
        // até $iFimUrgencia, o evento real existe mas fica fora do alcance da busca, cai no
        // fallback e gera uma data de necessidade artificial (baseada no dia em que o piso
        // apenas acusou o problema, não num dia de demanda de verdade). Por isso a busca vai
        // até o maior entre os dois limites — garante achar o evento que efetivamente
        // motivou o furo detectado por pisoNoDia, e só cai no fallback quando o furo é
        // mesmo por erosão pura de segurança, sem nenhuma demanda futura associada.
        $limiteBuscaEvento = min($n - 1, max($iFimUrgencia, $iPrimeiroDeficit + $minDias));
        $iEvento = $iPrimeiroDeficit;
        while ($iEvento < $n && $iEvento <= $limiteBuscaEvento && $demandaPorDia[$iEvento] <= 0) {
            $iEvento++;
        }
        if ($iEvento > $limiteBuscaEvento || $iEvento >= $n) {
            $iEvento = $iPrimeiroDeficit; // furo por erosão de segurança, sem demanda real associada
        }
        $dataNecessidade = $dias[$iEvento]->modify('-' . MRP_DIAS_RECEBIMENTO_ANTES_DEMANDA . ' days');
        $dataSugerida = $dataNecessidade->modify("-{$leadDias} days");

        // $iEvento é o dia da DEMANDA, não o dia em que o material fica disponível — a
        // disponibilidade (receber + conferir) acontece 30 dias ANTES da demanda, que é
        // exatamente o que $dataNecessidade já calcula acima. $iDisponibilidade é o índice
        // correspondente a essa mesma data dentro de $dias (os dias são consecutivos a
        // partir de $hoje, então "30 dias antes" em data equivale a "-30" em índice).
        // Usar $iEvento aqui — como o código fazia antes — descreve a disponibilidade como
        // se fosse o próprio dia da demanda, sem nenhuma folga: o teto do Máximo e a
        // injeção no saldo ficavam 30 dias atrasados em relação à data de necessidade já
        // calculada, deixando o saldo simulado artificialmente baixo durante esse período
        // (o material já estaria fisicamente disponível, mas a simulação não "sabia" disso).
        $iDisponibilidade = max(0, $iEvento - MRP_DIAS_RECEBIMENTO_ANTES_DEMANDA);

        // DATA RETROATIVA: se a data sugerida de pedido já passou (ex.: era pra pedir
        // em agosto com chegada em 18/09 e nada foi programado), não adianta mostrar
        // uma chegada no passado. Recalcula a situação REAL de hoje: pedido colocado
        // hoje → chega em hoje + Lead Time + Transit Time. A quantidade passa a cobrir
        // tudo o que falta até essa nova chegada (o saldo negativo acumulado) MAIS a
        // demanda futura da janela do Estoque Mínimo a partir da nova chegada.
        // O pedido não pode ser colocado antes da própria revisão: na revisão de hoje,
        // o mais cedo é hoje; numa revisão futura (simulada), o mais cedo é a data dela.
        // Antes, revisões futuras comparavam só com "hoje" e podiam sugerir uma chegada
        // ANTERIOR à própria revisão (ex.: revisão de 08/11 com chegada em 22/10), o que
        // gerava parcelas fantasmas (só o MOQ) e deixava a falta real sem cobertura.
        $recalculadaRetroativa = false;
        $dataNecessidadeOriginal = $dataNecessidade;
        if ($dataSugerida < $checkpoint) {
            $recalculadaRetroativa = $dataSugerida < $hoje;
            $dataSugerida = $checkpoint;
            $dataNecessidade = $checkpoint->modify("+{$leadDias} days");
            $iDisponibilidade = min($n - 1, $iCheckpoint + $leadDias);
        }

        // QUANTIDADE A PARTIR DA CHEGADA: o pior déficit usado pra dimensionar a compra
        // é procurado SÓ do dia em que o material chega ($iDisponibilidade) em diante —
        // até o fim da janela da revisão (ou, no mínimo, a janela do Estoque Mínimo
        // depois da chegada). Antes, a busca começava na data da revisão e podia
        // dimensionar a compra por um furo que acontece ANTES da chegada (que esse pedido
        // não resolve fisicamente). Como o saldo é acumulado, o saldo do dia da chegada
        // já carrega tudo o que faltou antes dela — então nada fica sem cobrir.
        $fimQuantidade = min($n - 1, max($iFimUrgencia, $iDisponibilidade + max($minDias, 1)));
        $piorDeficit = null;
        for ($k = $iDisponibilidade; $k <= $fimQuantidade; $k++) {
            $deficitK = $pisoNoDia($k) - $saldoPorDia[$k];
            if ($piorDeficit === null || $deficitK > $piorDeficit) {
                $piorDeficit = $deficitK;
                $iPior = $k;
            }
        }

        // Quantidade: cobre o pior déficit dentro da PRÓPRIA janela de urgência (mês da
        // revisão + Lead Time) — o lote fica proporcional ao problema real desse mês, sem
        // olhar mais além (é isso que faz o lote ficar pequeno/mensal em vez de somar tudo
        // de uma janela larga).
        $quantidadeAlvo = (float) ($piorDeficit ?? 0.0);
        if ($quantidadeAlvo <= 0) {
            continue;
        }

        // Teto do Estoque Máximo (dias): não compra mais do que o necessário pra chegar
        // nesse teto, calculado a partir de $iDisponibilidade — o dia em que a compra
        // efetivamente chega na simulação (ver injeção mais abaixo). Se o MOQ sozinho já
        // exigir mais que esse teto, o MOQ vence — é o mínimo que o fornecedor aceita,
        // mesmo passando do Máximo.
        $maxQtdNoDia = $maxDias > 0
            ? ($prefixoDemanda[min($n, $iDisponibilidade + $maxDias)] - $prefixoDemanda[$iDisponibilidade])
            : 0.0;

        // ATRASO NÃO É REPOSTO: se o saldo já está negativo no dia anterior à chegada
        // (demanda que ficou descoberta antes desta compra poder chegar), essa falta NÃO
        // entra na compra — a compra cobre só a demanda a partir da chegada, limitada ao
        // Estoque Máximo. A demanda descoberta é "baixada" da simulação a partir da chegada
        // (senão o saldo seguiria negativo e as revisões seguintes comprariam esse atraso).
        $saldoAntesChegada = $iDisponibilidade > 0 ? $saldoPorDia[$iDisponibilidade - 1] : $saldoInicialPlanejamento;
        $atrasoNaoReposto = max(0.0, -$saldoAntesChegada);
        if ($atrasoNaoReposto > 0) {
            for ($k = $iDisponibilidade; $k < $n; $k++) {
                $saldoPorDia[$k] += $atrasoNaoReposto;
            }
            // Recalcula o déficit (já sem o atraso) pra dimensionar a compra.
            $piorDeficit = null;
            for ($k = $iDisponibilidade; $k <= $fimQuantidade; $k++) {
                $deficitK = $pisoNoDia($k) - $saldoPorDia[$k];
                if ($piorDeficit === null || $deficitK > $piorDeficit) {
                    $piorDeficit = $deficitK;
                    $iPior = $k;
                }
            }
            $quantidadeAlvo = (float) ($piorDeficit ?? 0.0);
            if ($quantidadeAlvo <= 0) {
                continue;
            }
        }
        $tetoCompra = max(0.0, $maxQtdNoDia - $saldoPorDia[$iDisponibilidade]);

        // O Estoque Máximo nunca pode CAUSAR falta: a compra tem que ser no mínimo o
        // suficiente pra o saldo não ficar negativo até a próxima compra poder chegar
        // (próxima revisão mensal + Lead Time + Transit Time). Sem isso, como a chegada
        // é 30 dias antes da demanda, um Máximo curto (ex.: 45 dias) deixava de fora uma
        // demanda grande logo depois da janela — e a revisão seguinte já não conseguia
        // mais cobri-la a tempo (ex.: furo de 01/01 com a compra chegando só em 02/01).
        $iProximaChegada = min($n - 1, ($indicePorData[$checkpoint->modify('+1 month')->format('Y-m-d')] ?? ($n - 1)) + $leadDias);
        $coberturaMinima = 0.0;
        for ($k = $iDisponibilidade; $k <= $iProximaChegada; $k++) {
            if (-$saldoPorDia[$k] > $coberturaMinima) {
                $coberturaMinima = -$saldoPorDia[$k];
            }
        }
        $tetoCompra = max($tetoCompra, $coberturaMinima);

        // PRECISA AGORA? Só cria a parcela nesta revisão se o saldo furar o piso
        // ANTES da próxima compra poder chegar (próxima revisão + Lead Time +
        // Transit Time). Se o furo só acontece depois disso, a próxima revisão
        // ainda resolve a tempo — evita antecipar compra sem necessidade.
        $precisaAgora = false;
        for ($k = $iDisponibilidade; $k < $iProximaChegada; $k++) {
            if ($pisoNoDia($k) - $saldoPorDia[$k] > 0.0001) {
                $precisaAgora = true;
                break;
            }
        }
        if (!$precisaAgora) {
            continue;
        }

        // Estoque já no teto do Máximo e sem risco de ficar negativo: não há o que
        // comprar. Sem isso, o MOQ forçava compras "picadas" (só o MOQ, ex.: 100 un).
        if ($tetoCompra <= 0.0001 && $coberturaMinima <= 0.0001) {
            continue;
        }

        // Necessidade = déficit sem passar do teto do Máximo.
        $quantidadeBase = min($quantidadeAlvo, $tetoCompra);

        // Setup (% de perda/scrap) aplicado sobre a necessidade.
        $quantidadeComSetup = $setupPercentual > 0
            ? $quantidadeBase * (1 + $setupPercentual / 100)
            : $quantidadeBase;

        // MOQ como MÚLTIPLO (lote fechado): arredonda PRA CIMA pro próximo múltiplo do
        // MOQ, já com o setup incluído — assim a quantidade final é sempre um pedido
        // válido pro fornecedor. Ex.: MOQ 500, necessidade 1.320 → 1.500; necessidade
        // 120 → 500. A quantidade FÍSICA que chega é a final — é ela que volta pra
        // simulação pra achar a próxima parcela (a sobra do arredondamento já entra
        // no saldo e adia/reduz a próxima compra).
        $quantidadeFinal = $moq > 0
            ? max($moq, ceil(round($quantidadeComSetup / $moq, 6)) * $moq)
            : $quantidadeComSetup;

        // REGRA DOS 30 DIAS: não sugere parcela que dure menos de
        // MRP_DIAS_COBERTURA_MINIMA_PARCELA dias contando SÓ a quantidade da compra contra
        // a demanda a partir da data de entrega (mesma conta da coluna "Dias (programação)"
        // da tela), mesmo que o saldo esteja abaixo do Estoque Mínimo.
        //  - Se adiar NÃO causa falta (saldo continua >= 0 até a próxima compra poder
        //    chegar): pula esta revisão — a próxima parcela absorve a necessidade.
        //  - Se adiar causa falta: aumenta ESTA parcela o suficiente pra que ESTOQUE +
        //    COMPRA cubram os 30 dias a partir da entrega (saldo >= 0). Antes a regra
        //    exigia que a compra SOZINHA cobrisse os 30 dias, ignorando o estoque que já
        //    existia — inflava a compra (ex.: 12008619 precisava de ~92 e pedia 4.000).
        $ajustada30Dias = false;
        $demanda30Dias = $prefixoDemanda[min($n, $iDisponibilidade + MRP_DIAS_COBERTURA_MINIMA_PARCELA)]
            - $prefixoDemanda[$iDisponibilidade];
        if ($quantidadeFinal + 0.0001 < $demanda30Dias) {
            $faltaSeAdiar = false;
            for ($k = $iDisponibilidade; $k <= $iProximaChegada; $k++) {
                if ($saldoPorDia[$k] < -0.0001) {
                    $faltaSeAdiar = true;
                    break;
                }
            }
            if (!$faltaSeAdiar) {
                continue; // adia: a próxima revisão compra junto
            }
            // Quanto precisa chegar pra o saldo (estoque + programação + esta compra) não
            // ficar negativo nos 30 dias a partir da entrega.
            $fimCoberturaMinima = min($n - 1, $iDisponibilidade + MRP_DIAS_COBERTURA_MINIMA_PARCELA - 1);
            $necessidadeMinima = 0.0;
            for ($k = $iDisponibilidade; $k <= $fimCoberturaMinima; $k++) {
                if (-$saldoPorDia[$k] > $necessidadeMinima) {
                    $necessidadeMinima = -$saldoPorDia[$k];
                }
            }
            if ($quantidadeFinal + 0.0001 < $necessidadeMinima) {
                $quantidadeComSetup = $necessidadeMinima;
                $quantidadeBase = $setupPercentual > 0
                    ? $necessidadeMinima / (1 + $setupPercentual / 100)
                    : $necessidadeMinima;
                $quantidadeFinal = $moq > 0
                    ? max($moq, ceil(round($quantidadeComSetup / $moq, 6)) * $moq)
                    : $quantidadeComSetup;
                $ajustada30Dias = true;
            }
        }

        // Urgente =a data sugerida já passou OU cai dentro dos próximos 7 dias (ainda dá
        // tempo de agir esta semana, mas não sobra folga pra esperar a próxima revisão
        // mensal). A partir de 8 dias de folga, entra como "planejar" — normal, com tempo
        // de decidir.
        $status = $dataSugerida <= $hoje->modify('+7 days') ? 'urgente' : 'programada';

        $parcelas[] = [
            'status' => $status,
            'data' => $status === 'urgente' ? $hoje : $dataSugerida,
            'data_necessidade' => $dataNecessidade,
            'data_disponibilidade' => $dias[$iDisponibilidade],
            // true = a data original já tinha passado sem pedido; a data acima foi
            // recalculada como hoje + Lead Time + Transit Time.
            'recalculada' => $recalculadaRetroativa,
            'data_necessidade_original' => $dataNecessidadeOriginal,
            'quantidade' => $quantidadeFinal,
            'quantidade_base' => $quantidadeBase,
            'setup' => $setupPercentual,
            // true = aumentada pra cobrir 30 dias (adiar pra próxima causaria falta)
            'ajustada_30_dias' => $ajustada30Dias,
        ];

        // Injeta a quantidade a partir de $iDisponibilidade — o dia em que o material
        // realmente fica disponível (dataSugerida + Lead Time = $dataNecessidade, que é o
        // recebimento físico; +30 dias de receber/conferir/disponibilizar seria a demanda,
        // então a disponibilidade em si é 30 dias ANTES do dia da demanda, $iEvento) — e
        // não a partir do dia da demanda em si, nem do dia em que o piso apenas DETECTOU
        // o problema ($iPrimeiroDeficit). Injetar em $iEvento (como o código fazia antes)
        // atrasava a disponibilidade em 30 dias, deixando o saldo simulado artificialmente
        // baixo nesse intervalo mesmo com o material já fisicamente disponível.
        for ($k = $iDisponibilidade; $k < $n; $k++) {
            $saldoPorDia[$k] += $quantidadeFinal;
        }
    }

    $saldoPorDiaChave = [];
    foreach ($dias as $i => $dia) {
        $saldoPorDiaChave[$dia->format('Y-m-d')] = $saldoPorDia[$i];
    }

    return ['parcelas' => $parcelas, 'saldo_por_dia' => $saldoPorDiaChave];
}
