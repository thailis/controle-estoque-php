<?php
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
//   4) O MOQ é tratado só como PISO mínimo (compra pelo menos o MOQ) — não arredonda pra
//      múltiplo dele. Ex.: déficit de 8.464 com MOQ 100 vira 8.464 (não 8.500). Se o
//      déficit for menor que o MOQ, aí sim compra o MOQ inteiro (é o mínimo que dá pra
//      pedir). Depois injeta de volta na simulação, pra próxima revisão já considerar
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
        $fimUrgencia = min($horizonteFim, $checkpoint->modify('+1 month')->modify("+{$leadDias} days")->modify('+30 days'));
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
        for ($k = $iPrimeiroDeficit; $k <= $fimRecuperacao; $k++) {
            if ($saldoPorDia[$k] >= $pisoNoDia($k)) {
                $recuperaSozinho = true;
                break;
            }
        }
        if ($recuperaSozinho) {
            continue;
        }

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
        $dataNecessidade = $dias[$iEvento]->modify('-30 days');
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
        $iDisponibilidade = max(0, $iEvento - 30);

        // Quantidade: cobre o pior déficit dentro da PRÓPRIA janela de urgência (mês da
        // revisão + Lead Time) — o lote fica proporcional ao problema real desse mês, sem
        // olhar mais além (é isso que faz o lote ficar pequeno/mensal em vez de somar tudo
        // de uma janela larga).
        $quantidadeAlvo = $piorDeficit;
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
        $tetoCompra = max(0.0, $maxQtdNoDia - $saldoPorDia[$iDisponibilidade]);

        // MOQ como PISO mínimo, não como múltiplo/lote fechado: compra o déficit sem passar
        // do teto do Máximo, exceto quando o MOQ sozinho já exige mais que isso (aí compra
        // o MOQ, que é o mínimo que o fornecedor aceita).
        $quantidadeBase = $moq > 0
            ? max($moq, min($quantidadeAlvo, $tetoCompra))
            : min($quantidadeAlvo, $tetoCompra);

        // Setup (% de perda/scrap) aplicado por último, depois de todo o cálculo de netting,
        // do piso de MOQ e do teto de Máximo. A quantidade FÍSICA que chega de fato é a
        // final (com setup) — é ela que volta pra simulação pra achar a próxima parcela.
        $quantidadeFinal = $setupPercentual > 0
            ? $quantidadeBase * (1 + $setupPercentual / 100)
            : $quantidadeBase;

        // Urgente = a data sugerida já passou OU cai dentro dos próximos 7 dias (ainda dá
        // tempo de agir esta semana, mas não sobra folga pra esperar a próxima revisão
        // mensal). A partir de 8 dias de folga, entra como "planejar" — normal, com tempo
        // de decidir.
        $status = $dataSugerida <= $hoje->modify('+7 days') ? 'urgente' : 'programada';

        $parcelas[] = [
            'status' => $status,
            'data' => $status === 'urgente' ? $hoje : $dataSugerida,
            'data_necessidade' => $dataNecessidade,
            'data_disponibilidade' => $dias[$iDisponibilidade],
            'quantidade' => $quantidadeFinal,
            'quantidade_base' => $quantidadeBase,
            'setup' => $setupPercentual,
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
