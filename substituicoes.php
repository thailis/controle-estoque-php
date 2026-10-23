<?php
// substituicoes.php
// Substituição de código de componente (ex.: 12010876 → 12011775), cadastrada na
// BOM pela coluna "substitui": a linha do componente NOVO diz qual código antigo
// ele substitui. Regras usadas no cálculo de compra:
//   - o código antigo NÃO gera sugestão de compra;
//   - o estoque + entradas programadas + demanda que ainda sobrar do antigo entram
//     no cálculo do novo, então o novo só pede compra depois de consumir o antigo.
// Aceita cadeia (A → B → C): o último da cadeia ("cabeça") consome todos os anteriores.
// Se a coluna "substitui" ainda não existir no banco, tudo volta vazio (sem efeito).

function carregarSubstituicoes(mysqli $conn): array
{
    $novoPorAntigo = [];
    try {
        $res = mysqli_query($conn, "
            SELECT DISTINCT TRIM(substitui) AS antigo, TRIM(codigo_componente) AS novo
            FROM bomnova
            WHERE substitui IS NOT NULL AND TRIM(substitui) <> ''
              AND codigo_componente IS NOT NULL AND TRIM(codigo_componente) <> ''
              AND TRIM(substitui) <> TRIM(codigo_componente)
        ");
        if ($res) {
            while ($l = mysqli_fetch_assoc($res)) {
                // Se o mesmo antigo aparecer com dois novos diferentes, fica o primeiro.
                if (!isset($novoPorAntigo[$l['antigo']])) {
                    $novoPorAntigo[$l['antigo']] = $l['novo'];
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Substituições indisponíveis: ' . $e->getMessage());
    }

    $antigosPorNovo = [];
    foreach ($novoPorAntigo as $antigo => $novo) {
        $antigosPorNovo[(string) $novo][] = (string) $antigo; // chaves numéricas viram int no PHP
    }
    return ['novo_por_antigo' => $novoPorAntigo, 'antigos_por_novo' => $antigosPorNovo];
}

// Código atual (último da cadeia) que substitui $codigo. Null se $codigo não foi substituído.
function codigoSubstitutoFinal(array $subs, string $codigo): ?string
{
    $atual = $codigo;
    $vistos = [$codigo => true];
    while (isset($subs['novo_por_antigo'][$atual])) {
        $atual = $subs['novo_por_antigo'][$atual];
        if (isset($vistos[$atual])) {
            break; // ciclo (A→B→A): para aqui
        }
        $vistos[$atual] = true;
    }
    return $atual !== $codigo ? $atual : null;
}

// Todos os códigos antigos que $codigo consome (diretos e da cadeia), do mais antigo pro mais novo.
function codigosAntigosDe(array $subs, string $codigo): array
{
    $resultado = [];
    $fila = [$codigo];
    $vistos = [$codigo => true];
    while ($fila) {
        $atual = array_shift($fila);
        foreach ($subs['antigos_por_novo'][$atual] ?? [] as $antigo) {
            if (isset($vistos[$antigo])) {
                continue;
            }
            $vistos[$antigo] = true;
            $resultado[] = $antigo;
            $fila[] = $antigo;
        }
    }
    return array_reverse($resultado);
}
