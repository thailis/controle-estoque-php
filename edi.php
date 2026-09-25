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
$linhasProcessadas = [];
$totalProcessadas = 0;
$limiteExibicao = 500;

function registrarLinhaProcessada(
    array &$linhasProcessadas,
    int &$totalProcessadas,
    int $limiteExibicao,
    array $dados,
    string $resultado
): void {
    $totalProcessadas++;

    if (count($linhasProcessadas) >= $limiteExibicao) {
        return;
    }

    $linhasProcessadas[] = [
        'resultado' => $resultado,
        'material' => $dados['material'] ?? '',
        'pn2' => $dados['pn2'] ?? '',
        'projeto' => $dados['projeto'] ?? '',
        'evento' => $dados['evento'] ?? '',
        'semana' => $dados['semana'] ?? '',
        'ano' => $dados['ano'] ?? '',
        'quantidade' => $dados['quantidade'] ?? '',
        'data_inicio' => $dados['data_inicio'] ?? '',
        'data_fim' => $dados['data_fim'] ?? '',
    ];
}

// PN, Tipo, Projeto e Modelo do EDI vêm da BOM (bomnova), pelo Material — não são
// digitados nem lidos do CSV. Regra, por material:
//  - PN: a linha da BOM cujo COMPONENTE é o próprio material (a linha do
//    tanque) -> PN dela; senão, se o material tiver um único PN na BOM, esse;
//    senão vazio. (PN vazio ou "0" é ignorado.)
//  - Tipo, Projeto e Modelo: os da linha do tanque, se existir; senão o valor que mais
//    aparece nas linhas da BOM daquele material.
// Recebe vários materiais de uma vez. Devolve [material => ['pn','tipo','projeto']].
function buscarDadosBom(mysqli $conn, array $materiais): array
{
    $materiais = array_values(array_unique(array_filter(array_map(fn($m) => trim((string) $m), $materiais), fn($m) => $m !== '')));
    if (empty($materiais)) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($materiais), '?'));
    $tipos = str_repeat('s', count($materiais));
    $dados = [];
    foreach ($materiais as $m) {
        $dados[$m] = ['pn' => null, 'tipo' => null, 'projeto' => null, 'modelo' => null];
    }

    // Todas as linhas da BOM desses materiais (mais as linhas "do tanque",
    // cujo componente = material) numa consulta só
    $stmt = mysqli_prepare($conn, "
        SELECT TRIM(material) AS material, TRIM(codigo_componente) AS componente,
               TRIM(COALESCE(pn, '')) AS pn, TRIM(COALESCE(tipo, '')) AS tipo, TRIM(COALESCE(projeto, '')) AS projeto, TRIM(COALESCE(modelo, '')) AS modelo
        FROM bomnova
        WHERE TRIM(material) IN ($ph) OR TRIM(codigo_componente) IN ($ph)
    ");
    $valores = array_merge($materiais, $materiais);
    mysqli_stmt_bind_param($stmt, $tipos . $tipos, ...$valores);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $linhaTanque = [];   // material => linha onde componente = material
    $pnsDoMaterial = []; // material => [pn => true]
    $contTipo = [];      // material => [tipo => qtd]
    $contProjeto = [];
    $contModelo = [];
    while ($l = mysqli_fetch_assoc($res)) {
        if (isset($dados[$l['componente']])) {
            $linhaTanque[$l['componente']] = $l;
        }
        $m = $l['material'];
        if (!isset($dados[$m])) {
            continue;
        }
        if ($l['pn'] !== '' && $l['pn'] !== '0') { $pnsDoMaterial[$m][$l['pn']] = true; }
        if ($l['tipo'] !== '') { $contTipo[$m][$l['tipo']] = ($contTipo[$m][$l['tipo']] ?? 0) + 1; }
        if ($l['projeto'] !== '') { $contProjeto[$m][$l['projeto']] = ($contProjeto[$m][$l['projeto']] ?? 0) + 1; }
        if ($l['modelo'] !== '') { $contModelo[$m][$l['modelo']] = ($contModelo[$m][$l['modelo']] ?? 0) + 1; }
    }
    mysqli_stmt_close($stmt);

    $maisFrequente = function (?array $contagem): ?string {
        if (empty($contagem)) { return null; }
        arsort($contagem);
        return (string) array_key_first($contagem);
    };

    foreach ($materiais as $m) {
        $t = $linhaTanque[$m] ?? null;
        $pnTanque = $t !== null && $t['pn'] !== '' && $t['pn'] !== '0' ? $t['pn'] : null;
        $pnUnico = isset($pnsDoMaterial[$m]) && count($pnsDoMaterial[$m]) === 1 ? (string) array_key_first($pnsDoMaterial[$m]) : null;
        $dados[$m]['pn'] = $pnTanque ?? $pnUnico;
        $dados[$m]['tipo'] = ($t !== null && $t['tipo'] !== '') ? $t['tipo'] : $maisFrequente($contTipo[$m] ?? null);
        $dados[$m]['projeto'] = ($t !== null && $t['projeto'] !== '') ? $t['projeto'] : $maisFrequente($contProjeto[$m] ?? null);
        $dados[$m]['modelo'] = ($t !== null && $t['modelo'] !== '') ? $t['modelo'] : $maisFrequente($contModelo[$m] ?? null);
    }
    return $dados;
}

// Atalho: só o PN (material => pn), pra quem só precisa dele.
function buscarPnsBom(mysqli $conn, array $materiais): array
{
    $mapa = [];
    foreach (buscarDadosBom($conn, $materiais) as $m => $d) {
        if ($d['pn'] !== null) { $mapa[$m] = $d['pn']; }
    }
    return $mapa;
}

function calcularPeriodoSemana(int $semana): ?array
{
    if ($semana >= 30 && $semana <= 53) {
        $ano = 2026;
    } elseif ($semana >= 1 && $semana <= 29) {
        $ano = 2027;
    } else {
        return null;
    }

    $inicio = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->setISODate($ano, $semana, 1);

    return [
        'ano' => $ano,
        'data_inicio' => $inicio->format('Y-m-d'),
        'data_fim' => $inicio->modify('+6 days')->format('Y-m-d'),
    ];
}

// Caminho inverso do calcularPeriodoSemana() acima: em vez de digitar a semana
// e o site calcular a data, agora digita-se a DATA e o site calcula a semana
// (número ISO da semana) e o ano — usando a mesma convenção já existente
// (semana 30–53 = "ano" 2026; semana 1–29 = "ano" 2027). Isso é só um rótulo
// de safra, não precisa bater com o ano civil real da data digitada.
function calcularSemanaEAno(DateTimeImmutable $data): ?array
{
    $semana = (int) $data->format('W');
    if ($semana >= 30 && $semana <= 53) {
        $ano = 2026;
    } elseif ($semana >= 1 && $semana <= 29) {
        $ano = 2027;
    } else {
        return null;
    }
    return ['semana' => $semana, 'ano' => $ano];
}

// Aceita dd/mm/aaaa (formato padrão do site) ou aaaa-mm-dd (formato nativo do
// <input type="date">, caso algum navegador force isso). Devolve sempre um
// DateTimeImmutable, ou null se não reconhecer o formato.
function parseDataEdi(string $valor): ?DateTimeImmutable
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }
    foreach (['d/m/Y', 'Y-m-d'] as $formato) {
        $obj = DateTimeImmutable::createFromFormat('!' . $formato, $valor);
        if ($obj !== false) {
            return $obj;
        }
    }
    return null;
}

// Interpreta a quantidade do CSV, que pode vir em formato BR (ponto de milhar,
// vírgula decimal — ex.: "1.400" = 1400, "1.234,56" = 1234.56) ou já em formato
// simples ("1400"). Sem essa conversão, "1.400" seria gravado como texto e o
// banco leria o ponto como separador decimal (virando 1,4 em vez de 1400).
function parseQuantidadeEdi(string $valor): ?string
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }

    if (str_contains($valor, ',')) {
        // Tem vírgula: ponto é milhar, vírgula é decimal (ex.: "1.234,56")
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    } elseif (substr_count($valor, '.') >= 1) {
        // Só tem ponto(s): só remove como milhar se TODOS os grupos após
        // o primeiro ponto tiverem exatamente 3 dígitos (ex.: "1.400", "45.000").
        // Caso contrário, mantém o ponto como decimal (ex.: "1.5").
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

    return is_numeric($valor) ? $valor : null;
}

function formatarDataBr(?string $data): string
{
    if ($data === null || $data === '') {
        return '-';
    }

    $objetoData = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
    return $objetoData ? $objetoData->format('d/m/Y') : $data;
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

                if ($modo === 'substituir') {
                    mysqli_query($conn, "TRUNCATE TABLE edi");
                    $mensagens[] = "🗑️ Tabela 'edi' esvaziada antes da importação.";
                }

                $cacheDadosBom = [];
                $stmtInsert = mysqli_prepare($conn, "INSERT INTO edi (pn2, material, marca, projeto, modelo, evento, semana, quantidade, ano, data_fim, data_inicio) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtVerifica = mysqli_prepare($conn, "SELECT COUNT(*) AS existe FROM edi WHERE material = ? AND semana = ? AND evento = ?");
                $stmtUpdate = mysqli_prepare($conn, "UPDATE edi SET pn2 = ?, marca = ?, projeto = ?, modelo = ?, quantidade = ?, ano = ?, data_fim = ?, data_inicio = ? WHERE material = ? AND semana = ? AND evento = ?");

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

                    $dados = array_combine($cabecalho, $linha);

                    $material = $dados['material'] ?? null;
                    // PN, Tipo, Projeto e Modelo vêm da BOM pelo material (colunas do CSV
                    // com esses nomes são ignoradas — exceto PN, usado só se a BOM
                    // não tiver PN pra esse material).
                    $chaveMaterialBom = trim((string) $material);
                    if (!array_key_exists($chaveMaterialBom, $cacheDadosBom)) {
                        $cacheDadosBom[$chaveMaterialBom] = buscarDadosBom($conn, [$chaveMaterialBom])[$chaveMaterialBom] ?? ['pn' => null, 'tipo' => null, 'projeto' => null, 'modelo' => null];
                    }
                    $dadosBomLinha = $cacheDadosBom[$chaveMaterialBom];
                    $pnCsv = trim((string) ($dados['pn'] ?? $dados['pn2'] ?? ''));
                    $pn2 = $dadosBomLinha['pn'] ?? ($pnCsv !== '' ? $pnCsv : null);
                    $dados['pn2'] = $pn2;
                    $marca = $dadosBomLinha['tipo'];      // coluna "marca" do banco = Tipo (da BOM)
                    $projeto = $dadosBomLinha['projeto'];
                    $dados['projeto'] = $projeto ?? '';
                    $modelo = $dadosBomLinha['modelo'];  // Modelo também vem da BOM
                    $evento = $dados['evento'] ?? null;
                    $dataBruta = $dados['data'] ?? null;
                    $quantidadeBruta = $dados['quantidade'] ?? '';
                    $quantidade = parseQuantidadeEdi((string) $quantidadeBruta);
                    if ($quantidade === null) {
                        $erros++;
                        $mensagens[] = "⚠️ Linha $linhaNum ignorada: quantidade '$quantidadeBruta' inválida.";
                        registrarLinhaProcessada(
                            $linhasProcessadas,
                            $totalProcessadas,
                            $limiteExibicao,
                            $dados,
                            'erro'
                        );
                        continue;
                    }
                    $dados['quantidade'] = $quantidade;

                    $dataObj = $dataBruta !== null ? parseDataEdi((string) $dataBruta) : null;
                    $periodo = $dataObj !== null ? calcularSemanaEAno($dataObj) : null;
                    if ($periodo === null) {
                        $erros++;
                        $mensagens[] = "⚠️ Linha $linhaNum ignorada: data '$dataBruta' inválida ou fora do período aceito (use dd/mm/aaaa).";
                        registrarLinhaProcessada(
                            $linhasProcessadas,
                            $totalProcessadas,
                            $limiteExibicao,
                            $dados,
                            'erro'
                        );
                        continue;
                    }

                    $semana = $periodo['semana'];
                    $ano = $periodo['ano'];
                    $data_inicio = $dataObj->format('Y-m-d');
                    // Data fim não é mais necessária (a data digitada já é o valor
                    // completo) — mantém igual à data início só por compatibilidade
                    // com a coluna que ainda existe na tabela.
                    $data_fim = $data_inicio;
                    $dados['semana'] = $semana;
                    $dados['ano'] = $ano;
                    $dados['data_inicio'] = $data_inicio;
                    $dados['data_fim'] = $data_fim;

                    $existe = false;
                    if ($modo === 'sem_duplicar' || $modo === 'atualizar') {
                        mysqli_stmt_bind_param($stmtVerifica, "sss", $material, $semana, $evento);
                        mysqli_stmt_execute($stmtVerifica);
                        $resVerifica = mysqli_stmt_get_result($stmtVerifica);
                        $existe = mysqli_fetch_assoc($resVerifica)['existe'] > 0;
                    }

                    if ($modo === 'sem_duplicar' && $existe) {
                        $ignorados++;
                        registrarLinhaProcessada(
                            $linhasProcessadas,
                            $totalProcessadas,
                            $limiteExibicao,
                            $dados,
                            'ignorado'
                        );
                        continue;
                    }

                    if ($modo === 'atualizar' && $existe) {
                        mysqli_stmt_bind_param(
                            $stmtUpdate, "ssssdisssss",
                            $pn2, $marca, $projeto, $modelo, $quantidade, $ano, $data_fim, $data_inicio,
                            $material, $semana, $evento
                        );
                        if (mysqli_stmt_execute($stmtUpdate)) {
                            $atualizados++;
                            registrarLinhaProcessada(
                                $linhasProcessadas,
                                $totalProcessadas,
                                $limiteExibicao,
                                $dados,
                                'atualizado'
                            );
                        } else {
                            $erros++;
                            $mensagens[] = "⚠️ Erro ao atualizar linha $linhaNum: " . mysqli_stmt_error($stmtUpdate);
                            registrarLinhaProcessada(
                                $linhasProcessadas,
                                $totalProcessadas,
                                $limiteExibicao,
                                $dados,
                                'erro'
                            );
                        }
                        continue;
                    }

                    // Inserção normal (modos: adicionar, substituir, ou "atualizar"/"sem_duplicar" quando não existe ainda)
                    mysqli_stmt_bind_param(
                        $stmtInsert, "ssssssssiss",
                        $pn2, $material, $marca, $projeto, $modelo, $evento,
                        $semana, $quantidade, $ano, $data_fim, $data_inicio
                    );

                    if (mysqli_stmt_execute($stmtInsert)) {
                        $importados++;
                        registrarLinhaProcessada(
                            $linhasProcessadas,
                            $totalProcessadas,
                            $limiteExibicao,
                            $dados,
                            'inserido'
                        );
                    } else {
                        $erros++;
                        $mensagens[] = "⚠️ Erro na linha $linhaNum: " . mysqli_stmt_error($stmtInsert);
                        registrarLinhaProcessada(
                            $linhasProcessadas,
                            $totalProcessadas,
                            $limiteExibicao,
                            $dados,
                            'erro'
                        );
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
            fclose($handle);
        }
    }
}

// Explode a BOM do "material" do evento EDI e reflete a demanda no estoque de
// cada componente:
//   - $tornandoAtendido = true  (Pendente -> Atendido): para cada componente
//     ativo (mrp <> 'N') ligado a esse material, subtrai quantidade_edi ×
//     consumo, gravando uma linha NEGATIVA com origem = 'demanda_edi'
//     (ligada ao evento via origem_edi_id). Essas linhas NUNCA são apagadas
//     nem editadas depois — são o histórico permanente usado pela coluna
//     "Demanda" em estoque.php.
//   - $tornandoAtendido = false (Atendido -> Pendente / reabrir): devolve ao
//     estoque físico o que ainda estiver pendente de devolução para esse
//     evento, componente a componente, gravando uma linha POSITIVA de
//     COMPENSAÇÃO com origem = 'reversao_edi' — sem tocar nas linhas
//     'demanda_edi' originais. Resultado: o "Total" do Estoque (soma de
//     todas as origens) volta a refletir o saldo físico real, mas a
//     "Demanda" (soma só de 'demanda_edi') continua acumulando e nunca
//     diminui, mesmo se o evento for reaberto depois.
// Calculado usando o mesmo padrão de explosão de BOM já usado em
// evolucao_geral.php / detalhe_componente.php / parametros_compra.php.
// Busca, numa única consulta (IN), a descrição já cadastrada de vários
// componentes de uma vez — evita 1 consulta por componente (era o principal
// motivo do botão Pendente/Atendido demorar vários segundos em EDIs cuja BOM
// tem muitos componentes: antes eram 2 idas ao banco por componente).
function buscarDescricoesEstoque(mysqli $conn, array $codigos): array
{
    $codigos = array_values(array_unique(array_filter($codigos, fn($c) => $c !== '')));
    if (empty($codigos)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($codigos), '?'));
    $tipos = str_repeat('s', count($codigos));
    $stmt = mysqli_prepare($conn, "
        SELECT codigo_componente, MAX(descricao) AS descricao
        FROM estoque
        WHERE codigo_componente IN ($placeholders)
        GROUP BY codigo_componente
    ");
    mysqli_stmt_bind_param($stmt, $tipos, ...$codigos);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $mapa = [];
    while ($linha = mysqli_fetch_assoc($res)) {
        $mapa[$linha['codigo_componente']] = $linha['descricao'];
    }
    mysqli_stmt_close($stmt);
    return $mapa;
}

function aplicarDemandaEdiNoEstoque(mysqli $conn, int $idEdi, string $material, float $quantidadeEdi, bool $tornandoAtendido): void
{
    $material = trim($material);
    if ($material === '' || $idEdi <= 0) {
        return;
    }

    if ($tornandoAtendido) {
        if ($quantidadeEdi <= 0) {
            return;
        }
        $stmtBom = mysqli_prepare($conn, "
            SELECT TRIM(b.codigo_componente) AS codigo_componente,
                   COALESCE(CAST(NULLIF(REPLACE(TRIM(b.consumo), ',', '.'), '') AS DECIMAL(18,6)), 0) AS consumo
            FROM bomnova b
            WHERE TRIM(b.material) = ? AND (b.mrp IS NULL OR UPPER(TRIM(b.mrp)) <> 'N')
        ");
        mysqli_stmt_bind_param($stmtBom, 's', $material);
        mysqli_stmt_execute($stmtBom);
        $resBom = mysqli_stmt_get_result($stmtBom);
        $itensBom = mysqli_fetch_all($resBom, MYSQLI_ASSOC);
        mysqli_stmt_close($stmtBom);

        // Monta a lista de linhas a inserir primeiro (sem tocar no banco),
        // depois busca todas as descrições de uma vez e grava tudo num único
        // INSERT com múltiplas linhas — bem mais rápido que 1 SELECT + 1
        // INSERT por componente.
        $linhasParaInserir = [];
        foreach ($itensBom as $linhaBom) {
            $codigoComponente = $linhaBom['codigo_componente'];
            $consumo = (float) $linhaBom['consumo'];
            if ($codigoComponente === '' || $consumo <= 0) {
                continue;
            }
            $quantidadeSubtrair = $quantidadeEdi * $consumo;
            if ($quantidadeSubtrair <= 0) {
                continue;
            }
            $linhasParaInserir[$codigoComponente] = -$quantidadeSubtrair;
        }

        if (empty($linhasParaInserir)) {
            return;
        }

        $descricoes = buscarDescricoesEstoque($conn, array_keys($linhasParaInserir));

        $partes = [];
        foreach ($linhasParaInserir as $codigoComponente => $estoqueNegativo) {
            $descricao = $descricoes[$codigoComponente] ?? null;
            $descricaoSql = $descricao === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, (string) $descricao) . "'";
            $partes[] = "('" . mysqli_real_escape_string($conn, (string) $codigoComponente) . "', "
                . $descricaoSql . ", " . (float) $estoqueNegativo . ", NULL, 'demanda_edi', " . $idEdi . ")";
        }

        $sqlInsereLote = "INSERT INTO estoque (codigo_componente, descricao, estoque, planta, origem, origem_edi_id) VALUES "
            . implode(', ', $partes);
        mysqli_query($conn, $sqlInsereLote);
    } else {
        $stmtNet = mysqli_prepare($conn, "
            SELECT codigo_componente, SUM(COALESCE(CAST(estoque AS DECIMAL(18,4)), 0)) AS saldo
            FROM estoque
            WHERE origem_edi_id = ? AND origem IN ('demanda_edi', 'reversao_edi')
            GROUP BY codigo_componente
        ");
        mysqli_stmt_bind_param($stmtNet, 'i', $idEdi);
        mysqli_stmt_execute($stmtNet);
        $pendentes = mysqli_fetch_all(mysqli_stmt_get_result($stmtNet), MYSQLI_ASSOC);
        mysqli_stmt_close($stmtNet);

        $devolucoes = [];
        foreach ($pendentes as $linhaPendente) {
            $codigoComponente = $linhaPendente['codigo_componente'];
            $saldo = (float) $linhaPendente['saldo'];
            // saldo negativo = ainda falta devolver |saldo| ao estoque físico
            if ($saldo >= -0.0001) {
                continue;
            }
            $devolucoes[$codigoComponente] = -$saldo;
        }

        if (empty($devolucoes)) {
            return;
        }

        $descricoes = buscarDescricoesEstoque($conn, array_keys($devolucoes));

        $partes = [];
        foreach ($devolucoes as $codigoComponente => $devolver) {
            $descricao = $descricoes[$codigoComponente] ?? null;
            $descricaoSql = $descricao === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, (string) $descricao) . "'";
            $partes[] = "('" . mysqli_real_escape_string($conn, (string) $codigoComponente) . "', "
                . $descricaoSql . ", " . (float) $devolver . ", NULL, 'reversao_edi', " . $idEdi . ")";
        }

        $sqlInsereLote = "INSERT INTO estoque (codigo_componente, descricao, estoque, planta, origem, origem_edi_id) VALUES "
            . implode(', ', $partes);
        mysqli_query($conn, $sqlInsereLote);
    }
}

// Chamado depois que a QUANTIDADE de um evento EDI já existente é editada
// (tanto pelo duplo clique quanto pelo formulário de edição completa). Se o
// evento já estiver marcado Atendido, o valor subtraído do estoque tinha
// ficado baseado na quantidade ANTIGA — aqui ele é recalculado do zero:
// reverte 100% do que estava lançado pra esse evento (como se tivesse
// reaberto) e relança já com a quantidade nova (como se tivesse marcado
// Atendido de novo). Se o evento estiver Pendente, não há nada no estoque
// pra corrigir ainda, então não faz nada.
function sincronizarQuantidadeEdiNoEstoque(mysqli $conn, int $idEdi): void
{
    $stmt = mysqli_prepare($conn, "SELECT material, quantidade, atendido FROM edi WHERE _tidb_rowid = ?");
    mysqli_stmt_bind_param($stmt, 'i', $idEdi);
    mysqli_stmt_execute($stmt);
    $item = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$item || (int) $item['atendido'] !== 1) {
        return;
    }

    $material = (string) ($item['material'] ?? '');
    $quantidadeAtual = (float) ($item['quantidade'] ?? 0);

    mysqli_begin_transaction($conn);
    try {
        aplicarDemandaEdiNoEstoque($conn, $idEdi, $material, $quantidadeAtual, false); // zera o que estava lançado
        aplicarDemandaEdiNoEstoque($conn, $idEdi, $material, $quantidadeAtual, true);  // relança com a quantidade nova
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
    }
}

// Alternar o status "atendido" de um evento EDI, sem apagar a linha
//
// Versão AJAX: mesma lógica acima, mas responde em JSON pra atualizar o
// botão sem recarregar a página.
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

    // Busca material/quantidade/estado ANTES de alternar, pra saber a direção
    // da transição (virando Atendido ou reabrindo) antes de mexer no estoque.
    $stmtAntes = mysqli_prepare($conn, "SELECT material, quantidade, atendido FROM edi WHERE _tidb_rowid = ?");
    mysqli_stmt_bind_param($stmtAntes, 'i', $idAlternar);
    mysqli_stmt_execute($stmtAntes);
    $itemAntes = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtAntes));
    mysqli_stmt_close($stmtAntes);

    if (!$itemAntes) {
        echo json_encode(['ok' => false, 'erro' => 'Registro não encontrado.']);
        exit;
    }

    $tornandoAtendido = (int) $itemAntes['atendido'] !== 1;

    mysqli_begin_transaction($conn);
    try {
        $stmtToggle = mysqli_prepare($conn, "UPDATE edi SET atendido = IF(atendido = 1, 0, 1) WHERE _tidb_rowid = ?");
        mysqli_stmt_bind_param($stmtToggle, 'i', $idAlternar);
        mysqli_stmt_execute($stmtToggle);
        mysqli_stmt_close($stmtToggle);

        aplicarDemandaEdiNoEstoque(
            $conn,
            $idAlternar,
            (string) ($itemAntes['material'] ?? ''),
            (float) ($itemAntes['quantidade'] ?? 0),
            $tornandoAtendido
        );

        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        echo json_encode(['ok' => false, 'erro' => 'Erro ao atualizar estoque: ' . $e->getMessage()]);
        exit;
    }

    $novoAtendido = $tornandoAtendido;
    echo json_encode([
        'ok' => true,
        'atendido' => $novoAtendido,
        'texto' => $novoAtendido ? 'Atendido' : 'Pendente',
        'classe' => $novoAtendido ? 'is-atendido' : 'is-pendente',
        'title' => $novoAtendido ? 'Clique para reabrir' : 'Clique para marcar como atendido',
    ]);
    exit;
}

// Exclui um evento EDI específico (apaga a linha da tabela "edi"). Não mexe
// na programação (independente do EDI), mas apaga também as linhas de
// estoque geradas por ESSE evento (origem_edi_id) — tanto a demanda original
// ('demanda_edi') quanto qualquer reversão ('reversao_edi') — pra não deixar
// no estoque um desconto "órfão" de um evento que nem existe mais.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_evento_edi') {
    exigirComprador();
    $idExcluir = (int) ($_POST['id'] ?? 0);
    if ($idExcluir > 0) {
        mysqli_begin_transaction($conn);
        try {
            $stmtExcluirEstoqueEdi = mysqli_prepare($conn, "DELETE FROM estoque WHERE origem_edi_id = ?");
            mysqli_stmt_bind_param($stmtExcluirEstoqueEdi, 'i', $idExcluir);
            mysqli_stmt_execute($stmtExcluirEstoqueEdi);
            mysqli_stmt_close($stmtExcluirEstoqueEdi);

            $stmtExcluir = mysqli_prepare($conn, "DELETE FROM edi WHERE _tidb_rowid = ?");
            mysqli_stmt_bind_param($stmtExcluir, 'i', $idExcluir);
            mysqli_stmt_execute($stmtExcluir);
            mysqli_stmt_close($stmtExcluir);

            mysqli_commit($conn);
        } catch (Throwable $e) {
            mysqli_rollback($conn);
        }
    }

    header('Location: edi.php?' . http_build_query([
        'pagina'   => $_POST['pagina_atual'] ?? 1,
        'busca'    => $_POST['busca_atual'] ?? '',
        'ano'      => $_POST['ano_atual'] ?? '',
        'projeto'  => $_POST['projeto_atual'] ?? '',
        'modelo'   => $_POST['modelo_atual'] ?? '',
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
        $stmtAntesFallback = mysqli_prepare($conn, "SELECT material, quantidade, atendido FROM edi WHERE _tidb_rowid = ?");
        mysqli_stmt_bind_param($stmtAntesFallback, 'i', $idAlternar);
        mysqli_stmt_execute($stmtAntesFallback);
        $itemAntesFallback = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtAntesFallback));
        mysqli_stmt_close($stmtAntesFallback);

        if ($itemAntesFallback) {
            $tornandoAtendidoFallback = (int) $itemAntesFallback['atendido'] !== 1;

            mysqli_begin_transaction($conn);
            try {
                $stmtToggle = mysqli_prepare($conn, "UPDATE edi SET atendido = IF(atendido = 1, 0, 1) WHERE _tidb_rowid = ?");
                mysqli_stmt_bind_param($stmtToggle, 'i', $idAlternar);
                mysqli_stmt_execute($stmtToggle);
                mysqli_stmt_close($stmtToggle);

                aplicarDemandaEdiNoEstoque(
                    $conn,
                    $idAlternar,
                    (string) ($itemAntesFallback['material'] ?? ''),
                    (float) ($itemAntesFallback['quantidade'] ?? 0),
                    $tornandoAtendidoFallback
                );

                mysqli_commit($conn);
            } catch (Throwable $e) {
                mysqli_rollback($conn);
            }
        }
    }

    header('Location: edi.php?' . http_build_query([
        'pagina'  => $_POST['pagina_atual'] ?? 1,
        'busca'   => $_POST['busca_atual'] ?? '',
        'ano'     => $_POST['ano_atual'] ?? '',
        'projeto' => $_POST['projeto_atual'] ?? '',
        'modelo'  => $_POST['modelo_atual'] ?? '',
        'filtro'  => $_POST['filtro_atual'] ?? '',
    ]) . '#linha-' . $idAlternar);
    exit;
}

// Inclusão manual de um novo evento EDI direto pelo site, sem CSV.
// Semana/Ano são calculados a partir da data digitada (caminho inverso do
// que já usávamos antes — agora a data é o dado de entrada, não a semana).
// ---------- Atualizar PN / Tipo / Projeto de todos os EDIs pela BOM ----------
// Regrava nos EDIs já existentes o PN, Tipo (coluna "marca" no banco) e Projeto
// vindos da BOM, material a material. Onde a BOM não tiver o dado, mantém o que
// já estava. Útil depois de mudar a BOM, e pra acertar EDIs antigos (que tinham
// esses campos digitados/importados) — também deixa o filtro de Projeto certo.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'sincronizar_bom_edi') {
    exigirComprador();
    $resMateriaisEdi = mysqli_query($conn, "SELECT DISTINCT TRIM(material) AS material FROM edi WHERE material IS NOT NULL AND TRIM(material) <> ''");
    $materiaisEdi = [];
    while ($l = mysqli_fetch_assoc($resMateriaisEdi)) { $materiaisEdi[] = $l['material']; }
    $dadosBomTodos = buscarDadosBom($conn, $materiaisEdi);
    $linhasSync = 0;
    $semBom = 0;
    $stmtSync = mysqli_prepare($conn, "UPDATE edi SET pn2 = COALESCE(?, pn2), marca = COALESCE(?, marca), projeto = COALESCE(?, projeto), modelo = COALESCE(?, modelo) WHERE TRIM(material) = ?");
    foreach ($dadosBomTodos as $mat => $d) {
        if ($d['pn'] === null && $d['tipo'] === null && $d['projeto'] === null && $d['modelo'] === null) {
            $semBom++;
            continue;
        }
        mysqli_stmt_bind_param($stmtSync, 'sssss', $d['pn'], $d['tipo'], $d['projeto'], $d['modelo'], $mat);
        mysqli_stmt_execute($stmtSync);
        $linhasSync += max(0, mysqli_stmt_affected_rows($stmtSync));
    }
    mysqli_stmt_close($stmtSync);
    header('Location: edi.php?' . http_build_query(['flash' => 'sync_bom', 'sync_linhas' => $linhasSync, 'sync_sem_bom' => $semBom]));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'inserir_manual') {
    exigirComprador();
    $materialManual = trim($_POST['material_manual'] ?? '');
    // PN, Tipo, Projeto e Modelo não são digitados: vêm da BOM pelo material
    $dadosBomManual = buscarDadosBom($conn, [$materialManual])[$materialManual] ?? ['pn' => null, 'tipo' => null, 'projeto' => null, 'modelo' => null];
    $pn2Manual = $dadosBomManual['pn'];
    $marcaManual = $dadosBomManual['tipo'];
    $projetoManual = $dadosBomManual['projeto'];
    $modeloManual = $dadosBomManual['modelo']; // Modelo vem da BOM
    $eventoManual = trim($_POST['evento_manual'] ?? '');
    $dataManualTexto = trim($_POST['data_manual'] ?? '');
    $quantidadeManual = parseQuantidadeEdi(trim($_POST['quantidade_manual'] ?? ''));
    $dataManualObj = $dataManualTexto !== '' ? parseDataEdi($dataManualTexto) : null;
    $periodoManual = $dataManualObj !== null ? calcularSemanaEAno($dataManualObj) : null;

    $flash = 'erro_dados';
    if ($materialManual !== '' && $eventoManual !== '' && $quantidadeManual !== null && $periodoManual !== null) {
        $dataInicioManual = $dataManualObj->format('Y-m-d');
        $stmtInsManual = mysqli_prepare($conn, "INSERT INTO edi (pn2, material, marca, projeto, modelo, evento, semana, quantidade, ano, data_fim, data_inicio) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param(
            $stmtInsManual, "ssssssidiss",
            $pn2Manual, $materialManual, $marcaManual, $projetoManual, $modeloManual, $eventoManual,
            $periodoManual['semana'], $quantidadeManual, $periodoManual['ano'], $dataInicioManual, $dataInicioManual
        );
        mysqli_stmt_execute($stmtInsManual);
        mysqli_stmt_close($stmtInsManual);
        $flash = 'inserido';
    }

    header('Location: edi.php?' . http_build_query([
        'pagina'  => $_POST['pagina_atual'] ?? 1,
        'busca'   => $_POST['busca_atual'] ?? '',
        'ano'     => $_POST['ano_atual'] ?? '',
        'projeto' => $_POST['projeto_atual'] ?? '',
        'modelo'  => $_POST['modelo_atual'] ?? '',
        'filtro'  => $_POST['filtro_atual'] ?? '',
        'flash'   => $flash,
    ]));
    exit;
}

// Edição direta de data e quantidade de um evento EDI já existente, sem CSV.
// A data digitada aqui recalcula sozinha a semana e o ano (mesma regra
// inversa usada na importação/cadastro manual) — não precisa mais editar
// esses dois campos separadamente.
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

    if ($id <= 0 || !in_array($campo, ['data', 'quantidade'], true)) {
        echo json_encode(['ok' => false, 'erro' => 'Requisição inválida.']);
        exit;
    }

    if ($campo === 'quantidade') {
        $quantidadeEditada = parseQuantidadeEdi($valor);
        if ($quantidadeEditada === null) {
            echo json_encode(['ok' => false, 'erro' => 'Quantidade inválida.']);
            exit;
        }
        $stmt = mysqli_prepare($conn, "UPDATE edi SET quantidade = ? WHERE _tidb_rowid = ?");
        mysqli_stmt_bind_param($stmt, 'di', $quantidadeEditada, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        sincronizarQuantidadeEdiNoEstoque($conn, $id);
        echo json_encode(['ok' => true, 'exibido' => (string) $quantidadeEditada]);
        exit;
    }

    // campo === 'data'
    $dataEditadaObj = parseDataEdi($valor);
    if ($dataEditadaObj === null) {
        echo json_encode(['ok' => false, 'erro' => 'Data inválida. Use dd/mm/aaaa.']);
        exit;
    }
    $periodoEditado = calcularSemanaEAno($dataEditadaObj);
    $novaData = $dataEditadaObj->format('Y-m-d');
    $stmt = mysqli_prepare($conn, "UPDATE edi SET data_inicio = ?, data_fim = ?, semana = ?, ano = ? WHERE _tidb_rowid = ?");
    mysqli_stmt_bind_param($stmt, 'ssiii', $novaData, $novaData, $periodoEditado['semana'], $periodoEditado['ano'], $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    echo json_encode(['ok' => true, 'exibido' => $dataEditadaObj->format('d/m/Y')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'editar_registro') {
    exigirComprador();
    $idEditar = (int) ($_POST['id'] ?? 0);
    $dataEditadaBruta = trim($_POST['data_editada'] ?? '');
    $quantidadeEditada = parseQuantidadeEdi(trim($_POST['quantidade_editada'] ?? ''));
    $dataEditadaObj = parseDataEdi($dataEditadaBruta);
    $periodoEditado = $dataEditadaObj !== null ? calcularSemanaEAno($dataEditadaObj) : null;

    $flash = 'erro_dados';
    if ($idEditar > 0 && $periodoEditado !== null && $quantidadeEditada !== null) {
        $novaDataInicio = $dataEditadaObj->format('Y-m-d');
        // Data fim não é mais necessária — mantém igual à data início, só
        // por compatibilidade com a coluna que ainda existe na tabela.
        $novaDataFim = $novaDataInicio;

        $stmtEditar = mysqli_prepare($conn, "UPDATE edi SET data_inicio = ?, data_fim = ?, quantidade = ?, semana = ?, ano = ? WHERE _tidb_rowid = ?");
        mysqli_stmt_bind_param(
            $stmtEditar, "ssdiii",
            $novaDataInicio, $novaDataFim, $quantidadeEditada, $periodoEditado['semana'], $periodoEditado['ano'], $idEditar
        );
        mysqli_stmt_execute($stmtEditar);
        mysqli_stmt_close($stmtEditar);
        sincronizarQuantidadeEdiNoEstoque($conn, $idEditar);
        $flash = 'editado';
    }

    header('Location: edi.php?' . http_build_query([
        'pagina'  => $_POST['pagina_atual'] ?? 1,
        'busca'   => $_POST['busca_atual'] ?? '',
        'ano'     => $_POST['ano_atual'] ?? '',
        'projeto' => $_POST['projeto_atual'] ?? '',
        'modelo'  => $_POST['modelo_atual'] ?? '',
        'filtro'  => $_POST['filtro_atual'] ?? '',
        'flash'   => $flash,
    ]) . '#linha-' . $idEditar);
    exit;
}

$porPagina = 50;
$pagina = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$offset = ($pagina - 1) * $porPagina;

$busca  = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$filtro = isset($_GET['filtro']) ? trim($_GET['filtro']) : ''; // '', 'pendente', 'atendido'
$anoFiltro = isset($_GET['ano']) ? trim($_GET['ano']) : ''; // '', ou um ano específico
$projetoFiltro = isset($_GET['projeto']) ? trim($_GET['projeto']) : ''; // '', ou um projeto específico
$modeloFiltro = isset($_GET['modelo']) ? trim($_GET['modelo']) : ''; // '', ou um modelo específico
$editando = isset($_GET['editar']) ? (int) $_GET['editar'] : 0;
$flash = isset($_GET['flash']) ? trim($_GET['flash']) : '';

$flashMap = [
    'inserido'   => ['success', '✅ Evento adicionado com sucesso.'],
    'editado'    => ['success', '✅ Registro atualizado.'],
    'erro_dados' => ['danger', '❌ Confira material, evento, semana (30-53/2026 ou 1-29/2027) e quantidade.'],
    'sync_bom'   => ['success', '🔄 PN, Tipo, Projeto e Modelo atualizados pela BOM — ' . (int) ($_GET['sync_linhas'] ?? 0) . ' linha(s) de EDI alterada(s)' . ((int) ($_GET['sync_sem_bom'] ?? 0) > 0 ? '; ' . (int) $_GET['sync_sem_bom'] . ' material(is) sem dados na BOM (mantidos como estavam).' : '.')],
];

// Lista de materiais existentes na BOM, pra sugerir no campo de inclusão manual
$materiaisDisponiveis = [];
$resMat = mysqli_query($conn, "SELECT DISTINCT TRIM(material) AS material FROM bomnova WHERE material IS NOT NULL AND TRIM(material) <> '' ORDER BY material");
if ($resMat) {
    while ($linhaMat = mysqli_fetch_assoc($resMat)) {
        $materiaisDisponiveis[] = $linhaMat['material'];
    }
}

$condicoes = [];
$params = [];
$tipos = '';

if ($busca !== '') {
    $condicoes[] = "(material LIKE ? OR pn2 LIKE ? OR projeto LIKE ?)";
    $buscaLike = "%$busca%";
    array_push($params, $buscaLike, $buscaLike, $buscaLike);
    $tipos .= 'sss';
}

if ($filtro === 'pendente') {
    $condicoes[] = "(atendido = 0 OR atendido IS NULL)";
} elseif ($filtro === 'atendido') {
    $condicoes[] = "atendido = 1";
}

if ($anoFiltro !== '' && ctype_digit($anoFiltro)) {
    $condicoes[] = "ano = ?";
    $params[] = (int) $anoFiltro;
    $tipos .= 'i';
}

if ($projetoFiltro !== '') {
    $condicoes[] = "TRIM(projeto) = ?";
    $params[] = $projetoFiltro;
    $tipos .= 's';
}

if ($modeloFiltro !== '') {
    $condicoes[] = "TRIM(modelo) = ?";
    $params[] = $modeloFiltro;
    $tipos .= 's';
}

$where = $condicoes ? ('WHERE ' . implode(' AND ', $condicoes)) : '';

// Anos disponíveis na base, pra montar o combo de filtro dinamicamente
$anosDisponiveis = [];
$resAnos = mysqli_query($conn, "SELECT DISTINCT ano FROM edi WHERE ano IS NOT NULL ORDER BY ano DESC");
if ($resAnos) {
    while ($linhaAno = mysqli_fetch_assoc($resAnos)) {
        $anosDisponiveis[] = $linhaAno['ano'];
    }
}

// Projetos e modelos disponíveis na base, pra montar os combos de filtro dinamicamente
$projetosDisponiveis = [];
$resProjetos = mysqli_query($conn, "SELECT DISTINCT TRIM(projeto) AS projeto FROM edi WHERE projeto IS NOT NULL AND TRIM(projeto) <> '' ORDER BY projeto");
if ($resProjetos) {
    while ($linhaProjeto = mysqli_fetch_assoc($resProjetos)) {
        $projetosDisponiveis[] = $linhaProjeto['projeto'];
    }
}

$modelosDisponiveis = [];
$resModelos = mysqli_query($conn, "SELECT DISTINCT TRIM(modelo) AS modelo FROM edi WHERE modelo IS NOT NULL AND TRIM(modelo) <> '' ORDER BY modelo");
if ($resModelos) {
    while ($linhaModelo = mysqli_fetch_assoc($resModelos)) {
        $modelosDisponiveis[] = $linhaModelo['modelo'];
    }
}

// Total de registros (para calcular número de páginas)
$sqlTotal = "SELECT COUNT(*) AS total FROM edi $where";
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

// Exportação CSV: traz TODOS os registros filtrados (ignora a paginação da tela)
if (($_GET['exportar'] ?? '') === 'csv') {
    $sqlExport = "SELECT pn2, material, marca, projeto, modelo, evento, semana, quantidade, ano, data_inicio, atendido
                  FROM edi $where
                  ORDER BY data_inicio ASC";
    if (!empty($params)) {
        $stmtExport = mysqli_prepare($conn, $sqlExport);
        mysqli_stmt_bind_param($stmtExport, $tipos, ...$params);
        mysqli_stmt_execute($stmtExport);
        $resultExport = mysqli_stmt_get_result($stmtExport);
    } else {
        $resultExport = mysqli_query($conn, $sqlExport);
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="edi-' . date('Y-m-d-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $saida = fopen('php://output', 'w');
    fputcsv($saida, ['PN', 'Material', 'Tipo', 'Projeto', 'Modelo', 'Evento', 'Semana', 'Quantidade', 'Ano', 'Data', 'Atendido'], ';', '"', '');
    $linhasExport = mysqli_fetch_all($resultExport, MYSQLI_ASSOC);
    $dadosBomExport = buscarDadosBom($conn, array_map(fn($l) => $l['material'], $linhasExport));
    foreach ($linhasExport as $linhaExport) {
        $dExp = $dadosBomExport[trim((string) $linhaExport['material'])] ?? null;
        $pnExport = $dExp['pn'] ?? $linhaExport['pn2'];
        $linhaExport['marca'] = $dExp['tipo'] ?? $linhaExport['marca'];
        $linhaExport['projeto'] = $dExp['projeto'] ?? $linhaExport['projeto'];
        $linhaExport['modelo'] = $dExp['modelo'] ?? $linhaExport['modelo'];
        fputcsv($saida, [
            $pnExport, $linhaExport['material'], $linhaExport['marca'], $linhaExport['projeto'],
            $linhaExport['modelo'], $linhaExport['evento'], $linhaExport['semana'], $linhaExport['quantidade'],
            $linhaExport['ano'], formatarDataBr($linhaExport['data_inicio']),
            ((int) ($linhaExport['atendido'] ?? 0) === 1) ? 'Sim' : 'Não',
        ], ';', '"', '');
    }
    fclose($saida);
    exit;
}

// Busca os dados da página atual — ordenado pela DATA de verdade (crescente,
// mais antiga primeiro, mais recente por último), não mais pelos rótulos de
// ano/semana (que ainda
// existem, mas hoje são só derivados da data, não a fonte da ordenação).
$sql = "SELECT _tidb_rowid AS id, pn2, material, marca, projeto, modelo, evento, semana, quantidade, ano, data_inicio, data_fim, atendido 
        FROM edi $where 
        ORDER BY data_inicio ASC 
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
// PN / Tipo / Projeto exibidos sempre da BOM (fonte oficial); se a BOM não
// tiver o dado pra aquele material, mostra o que está gravado no EDI.
$dadosBomPagina = buscarDadosBom($conn, array_map(fn($r) => $r['material'], $rows));
foreach ($rows as &$r) {
    $d = $dadosBomPagina[trim((string) $r['material'])] ?? null;
    if ($d !== null) {
        $r['pn2'] = $d['pn'] ?? $r['pn2'];
        $r['marca'] = $d['tipo'] ?? $r['marca'];
        $r['projeto'] = $d['projeto'] ?? $r['projeto'];
        $r['modelo'] = $d['modelo'] ?? $r['modelo'];
    }
}
unset($r);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>📋 EDI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; padding: 20px; }
        .card { border-radius: 15px; box-shadow: 0 2px 20px rgba(0,0,0,0.08); }
        .bg-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important; }
        .table th { background: #f8f9fa; white-space: nowrap; }
        .table td { white-space: nowrap; vertical-align: middle; }
        .form-check { padding: 10px; border: 1px solid #e0e0e0; border-radius: 8px; margin-bottom: 8px; }
        .form-check:hover { background: #f8f9fa; }
        .resultado-table th { white-space: nowrap; background: #f8f9fa; }
        .resultado-table td { vertical-align: middle; }
        .codigo-material { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-weight: 700; }
        summary { cursor: pointer; font-weight: 700; color: #405164; }

        /* Badge-botão de situação: um único elemento clicável, sem quebrar linha */
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

        .table-hover tbody tr:hover > * { background: #f7faff; }

        /* Tabela principal: fonte e espaçamento mais compactos pra caber mais colunas sem sobrepor */
        .edi-table { font-size: .8rem; }
        .edi-table th,
        .edi-table td { padding: 8px 10px; }
        .edi-table th { font-size: .72rem; text-transform: uppercase; letter-spacing: .03em; color: #536578; }
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
    <div class="container" style="max-width: 1700px; min-width: 1700px;">
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
            <h1>📋 EDI</h1>
            <p class="mb-0"><?php echo number_format($total, 0, ',', '.'); ?> registro(s) na base</p>
        </div>

        <div class="card p-3 mb-4">
            <details <?php echo (!empty($mensagens) || !empty($linhasProcessadas)) ? 'open' : ''; ?>>
                <summary>📥 Importar novo arquivo CSV</summary>
                <div class="mt-3">
                    <?php if (!empty($mensagens)): ?>
                    <div class="mb-3">
                        <?php foreach ($mensagens as $msg): ?>
                            <div><?php echo htmlspecialchars($msg); ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($linhasProcessadas)): ?>
                    <div class="mb-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <div>
                                <h2 class="h6 mb-1">Itens processados nesta importação</h2>
                                <p class="text-muted mb-0 small">
                                    <?php echo number_format($totalProcessadas, 0, ',', '.'); ?> linha(s) processada(s)
                                </p>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <span class="badge text-bg-success"><?php echo $importados; ?> inserido(s)</span>
                                <span class="badge text-bg-primary"><?php echo $atualizados; ?> atualizado(s)</span>
                                <span class="badge text-bg-secondary"><?php echo $ignorados; ?> ignorado(s)</span>
                                <span class="badge text-bg-danger"><?php echo $erros; ?> erro(s)</span>
                            </div>
                        </div>

                        <?php if ($totalProcessadas > $limiteExibicao): ?>
                            <div class="alert alert-info py-2">
                                A importação processou todas as linhas. Para manter a página rápida, a tabela abaixo mostra somente as primeiras <?php echo $limiteExibicao; ?>.
                            </div>
                        <?php endif; ?>

                        <div class="table-responsive">
                            <table class="table table-hover table-sm resultado-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Resultado</th>
                                        <th>Material</th>
                                        <th>PN</th>
                                        <th>Projeto</th>
                                        <th>Evento</th>
                                        <th>Semana</th>
                                        <th>Ano</th>
                                        <th>Data inicial</th>
                                        <th>Data final</th>
                                        <th class="text-end">Quantidade</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $badgesResultado = [
                                        'inserido' => ['success', 'Inserido'],
                                        'atualizado' => ['primary', 'Atualizado'],
                                        'ignorado' => ['secondary', 'Ignorado'],
                                        'erro' => ['danger', 'Erro'],
                                    ];
                                    ?>
                                    <?php foreach ($linhasProcessadas as $linhaProcessada): ?>
                                        <?php $badge = $badgesResultado[$linhaProcessada['resultado']]; ?>
                                        <tr>
                                            <td><span class="badge text-bg-<?php echo $badge[0]; ?>"><?php echo $badge[1]; ?></span></td>
                                            <td><span class="codigo-material"><?php echo htmlspecialchars($linhaProcessada['material']); ?></span></td>
                                            <td><?php echo htmlspecialchars($linhaProcessada['pn2']); ?></td>
                                            <td><?php echo htmlspecialchars($linhaProcessada['projeto']); ?></td>
                                            <td><?php echo htmlspecialchars($linhaProcessada['evento']); ?></td>
                                            <td><?php echo htmlspecialchars($linhaProcessada['semana']); ?></td>
                                            <td><?php echo htmlspecialchars($linhaProcessada['ano']); ?></td>
                                            <td><?php echo htmlspecialchars(formatarDataBr($linhaProcessada['data_inicio'])); ?></td>
                                            <td><?php echo htmlspecialchars(formatarDataBr($linhaProcessada['data_fim'])); ?></td>
                                            <td class="text-end"><?php echo htmlspecialchars($linhaProcessada['quantidade']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
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
                                <strong>Adicionar sem duplicar</strong> — ignora linhas cujo Material+Semana+Evento já existe
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="modo" id="modo_atualizar" value="atualizar">
                            <label class="form-check-label" for="modo_atualizar">
                                <strong>Adicionar e atualizar</strong> — se já existir (mesmo Material+Semana+Evento), atualiza os dados; senão insere novo
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="modo" id="modo_substituir" value="substituir">
                            <label class="form-check-label" for="modo_substituir">
                                <strong>Substituir tudo</strong> — apaga todos os dados atuais e importa somente o que está no arquivo
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary mt-2">Importar</button>
                    </form>

                    <hr>
                    <small class="text-muted">
                        <strong>Colunas esperadas no CSV</strong> (primeira linha = cabeçalho, qualquer ordem):<br>
                        <code>material, evento, data, quantidade</code><br>
                        <strong>PN, Tipo, Projeto e Modelo</strong> não precisam vir: são puxados da BOM pelo material (colunas com esses nomes no CSV são ignoradas; o <code>pn</code> do CSV só é usado se a BOM não tiver PN pro material).<br>
                        A coluna é <code>data</code> (formato dd/mm/aaaa), não mais "semana" — o site calcula sozinho o número da semana ISO e o "ano" (rótulo de safra: semanas 30–53 = 2026; semanas 1–29 = 2027) a partir da data digitada. Separador: vírgula ou ponto e vírgula.
                    </small>
                </div>
            </details>
        </div>

        <?php if ($flash !== '' && isset($flashMap[$flash])): ?>
            <div class="alert alert-<?php echo $flashMap[$flash][0]; ?> py-2"><?php echo $flashMap[$flash][1]; ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['excluido'])): ?>
            <div class="alert alert-success py-2">✅ Evento excluído.</div>
        <?php endif; ?>

        <datalist id="lista_materiais">
            <?php foreach ($materiaisDisponiveis as $m): ?>
                <option value="<?php echo htmlspecialchars($m); ?>">
            <?php endforeach; ?>
        </datalist>

        <div class="card p-3 mb-4">
            <form method="POST" class="d-flex flex-wrap align-items-center gap-3 m-0" onsubmit="return confirm('Regravar PN, Tipo, Projeto e Modelo de TODOS os EDIs com os dados da BOM?');">
                <input type="hidden" name="acao" value="sincronizar_bom_edi">
                <button type="submit" class="btn btn-outline-primary btn-sm">🔄 Atualizar PN / Tipo / Projeto / Modelo pela BOM</button>
                <small class="text-muted">PN, Tipo, Projeto e Modelo sempre vêm da BOM pelo material. Use depois de alterar a BOM, ou pra acertar EDIs antigos.</small>
            </form>
        </div>

        <div class="card p-3 mb-4">
            <details>
                <summary>➕ Novo evento (entrada manual)</summary>
                <form method="POST" class="row g-2 align-items-end mt-3">
                    <input type="hidden" name="acao" value="inserir_manual">
                    <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                    <input type="hidden" name="busca_atual" value="<?php echo htmlspecialchars($busca); ?>">
                    <input type="hidden" name="ano_atual" value="<?php echo htmlspecialchars($anoFiltro); ?>">
                    <input type="hidden" name="projeto_atual" value="<?php echo htmlspecialchars($projetoFiltro); ?>">
                    <input type="hidden" name="modelo_atual" value="<?php echo htmlspecialchars($modeloFiltro); ?>">
                    <input type="hidden" name="filtro_atual" value="<?php echo htmlspecialchars($filtro); ?>">
                    <div class="col-auto">
                        <label class="form-label small mb-1">Material *</label>
                        <input type="text" name="material_manual" list="lista_materiais" class="form-control form-control-sm" style="width:140px" required>
                    </div>
                    <div class="col-auto">
                        <label class="form-label small mb-1">Evento *</label>
                        <input type="text" name="evento_manual" class="form-control form-control-sm" style="width:120px" required>
                    </div>
                    <div class="col-auto">
                        <label class="form-label small mb-1">Data *</label>
                        <input type="text" name="data_manual" class="form-control form-control-sm" style="width:120px" placeholder="dd/mm/aaaa" required>
                    </div>
                    <div class="col-auto">
                        <label class="form-label small mb-1">Quantidade *</label>
                        <input type="text" name="quantidade_manual" class="form-control form-control-sm text-end" style="width:110px" required>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary btn-sm">Adicionar</button>
                    </div>
                    <div class="col-12">
                        <small class="text-muted">Semana e ano são calculados automaticamente a partir da data (rótulo de safra: semana 30–53 = 2026; 1–29 = 2027), igual ao CSV.</small>
                    </div>
                </form>
            </details>
        </div>

        <div class="card p-3 mb-4">
            <form method="GET" class="row g-2 align-items-center">
                <input type="hidden" name="filtro" value="<?php echo htmlspecialchars($filtro); ?>">
                <div class="col-auto flex-grow-1">
                    <input type="text" name="busca" class="form-control" placeholder="Buscar por material, PN ou projeto..." value="<?php echo htmlspecialchars($busca); ?>">
                </div>
                <div class="col-auto">
                    <select name="ano" class="form-select" onchange="this.form.submit()">
                        <option value="">Todos os anos</option>
                        <?php foreach ($anosDisponiveis as $anoOpcao): ?>
                            <option value="<?php echo (int) $anoOpcao; ?>" <?php echo ((string) $anoFiltro === (string) $anoOpcao) ? 'selected' : ''; ?>><?php echo (int) $anoOpcao; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <select name="projeto" class="form-select" onchange="this.form.submit()">
                        <option value="">Todos os projetos</option>
                        <?php foreach ($projetosDisponiveis as $projetoOpcao): ?>
                            <option value="<?php echo htmlspecialchars($projetoOpcao); ?>" <?php echo ($projetoFiltro === $projetoOpcao) ? 'selected' : ''; ?>><?php echo htmlspecialchars($projetoOpcao); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <select name="modelo" class="form-select" onchange="this.form.submit()">
                        <option value="">Todos os modelos</option>
                        <?php foreach ($modelosDisponiveis as $modeloOpcao): ?>
                            <option value="<?php echo htmlspecialchars($modeloOpcao); ?>" <?php echo ($modeloFiltro === $modeloOpcao) ? 'selected' : ''; ?>><?php echo htmlspecialchars($modeloOpcao); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <div class="btn-group" role="group">
                        <a href="?busca=<?php echo urlencode($busca); ?>&ano=<?php echo urlencode($anoFiltro); ?>&projeto=<?php echo urlencode($projetoFiltro); ?>&modelo=<?php echo urlencode($modeloFiltro); ?>&filtro=" class="btn btn-outline-secondary btn-sm <?php echo $filtro === '' ? 'active' : ''; ?>">Todos</a>
                        <a href="?busca=<?php echo urlencode($busca); ?>&ano=<?php echo urlencode($anoFiltro); ?>&projeto=<?php echo urlencode($projetoFiltro); ?>&modelo=<?php echo urlencode($modeloFiltro); ?>&filtro=pendente" class="btn btn-outline-secondary btn-sm <?php echo $filtro === 'pendente' ? 'active' : ''; ?>">Pendentes</a>
                        <a href="?busca=<?php echo urlencode($busca); ?>&ano=<?php echo urlencode($anoFiltro); ?>&projeto=<?php echo urlencode($projetoFiltro); ?>&modelo=<?php echo urlencode($modeloFiltro); ?>&filtro=atendido" class="btn btn-outline-secondary btn-sm <?php echo $filtro === 'atendido' ? 'active' : ''; ?>">Atendidos</a>
                    </div>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">Buscar</button>
                    <a href="edi.php" class="btn btn-outline-secondary">Limpar</a>
                    <a href="?busca=<?php echo urlencode($busca); ?>&ano=<?php echo urlencode($anoFiltro); ?>&projeto=<?php echo urlencode($projetoFiltro); ?>&modelo=<?php echo urlencode($modeloFiltro); ?>&filtro=<?php echo urlencode($filtro); ?>&exportar=csv" class="btn btn-outline-primary">Exportar CSV</a>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-body table-responsive">
                <table class="table table-hover table-sm edi-table">
                    <thead>
                        <tr>
                            <th>Situação</th>
                            <th>PN</th>
                            <th>Material</th>
                            <th>Tipo</th>
                            <th>Projeto</th>
                            <th>Modelo</th>
                            <th>Evento</th>
                            <th class="text-center">Semana</th>
                            <th class="text-center">Quantidade</th>
                            <th>Data</th>
                            <th title="Excluir">Excluir</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="11" class="text-center text-muted">Nenhum registro encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $estaAtendido = (int) ($row['atendido'] ?? 0) === 1;
                                $idLinha = (int) $row['id'];
                                $emEdicao = ($editando === $idLinha);
                                $linkVoltar = '?pagina=' . $pagina . '&busca=' . urlencode($busca) . '&ano=' . urlencode($anoFiltro) . '&projeto=' . urlencode($projetoFiltro) . '&modelo=' . urlencode($modeloFiltro) . '&filtro=' . urlencode($filtro);
                                ?>
                                <tr id="linha-<?php echo $idLinha; ?>">
                                    <td>
                                        <form method="POST" class="m-0">
                                            <input type="hidden" name="acao" value="alternar_atendido">
                                            <input type="hidden" name="id" value="<?php echo $idLinha; ?>">
                                            <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                                            <input type="hidden" name="busca_atual" value="<?php echo htmlspecialchars($busca); ?>">
                                            <input type="hidden" name="ano_atual" value="<?php echo htmlspecialchars($anoFiltro); ?>">
                                            <input type="hidden" name="projeto_atual" value="<?php echo htmlspecialchars($projetoFiltro); ?>">
                                            <input type="hidden" name="modelo_atual" value="<?php echo htmlspecialchars($modeloFiltro); ?>">
                                            <input type="hidden" name="filtro_atual" value="<?php echo htmlspecialchars($filtro); ?>">
                                            <button type="submit"
                                                    class="situacao-toggle <?php echo $estaAtendido ? 'is-atendido' : 'is-pendente'; ?>"
                                                    title="<?php echo $estaAtendido ? 'Clique para reabrir' : 'Clique para marcar como atendido'; ?>">
                                                <span class="dot"></span>
                                                <?php echo $estaAtendido ? 'Atendido' : 'Pendente'; ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['pn2'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['material'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['marca'] ?? ''); ?></td>
                                    <td title="<?php echo htmlspecialchars($row['projeto'] ?? ''); ?>"><span class="text-truncate-cell"><?php echo htmlspecialchars($row['projeto'] ?? ''); ?></span></td>
                                    <td title="<?php echo htmlspecialchars($row['modelo'] ?? ''); ?>"><span class="text-truncate-cell"><?php echo htmlspecialchars($row['modelo'] ?? ''); ?></span></td>
                                    <td><?php echo htmlspecialchars($row['evento'] ?? ''); ?></td>
                                    <td class="text-center"><?php echo htmlspecialchars($row['semana'] ?? ''); ?></td>

                                    <td class="text-center celula-editavel" data-id="<?php echo $idLinha; ?>" data-campo="quantidade" data-valor-bruto="<?php echo htmlspecialchars($row['quantidade'] ?? ''); ?>" title="Duplo clique para editar"><?php echo htmlspecialchars($row['quantidade'] ?? ''); ?></td>
                                    <td class="celula-editavel" data-id="<?php echo $idLinha; ?>" data-campo="data" data-valor-bruto="<?php echo htmlspecialchars(formatarDataBr($row['data_inicio'] ?? null)); ?>" title="Duplo clique para editar"><?php echo htmlspecialchars(formatarDataBr($row['data_inicio'] ?? null)); ?></td>
                                    <td>
                                        <form method="POST" class="m-0" onsubmit="return confirm('Excluir este evento EDI? Essa ação não pode ser desfeita.');">
                                            <input type="hidden" name="acao" value="excluir_evento_edi">
                                            <input type="hidden" name="id" value="<?php echo $idLinha; ?>">
                                            <input type="hidden" name="pagina_atual" value="<?php echo $pagina; ?>">
                                            <input type="hidden" name="busca_atual" value="<?php echo htmlspecialchars($busca); ?>">
                                            <input type="hidden" name="ano_atual" value="<?php echo htmlspecialchars($anoFiltro); ?>">
                                            <input type="hidden" name="projeto_atual" value="<?php echo htmlspecialchars($projetoFiltro); ?>">
                                            <input type="hidden" name="modelo_atual" value="<?php echo htmlspecialchars($modeloFiltro); ?>">
                                            <input type="hidden" name="filtro_atual" value="<?php echo htmlspecialchars($filtro); ?>">
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
                    <a href="?pagina=<?php echo $pagina - 1; ?>&busca=<?php echo urlencode($busca); ?>&ano=<?php echo urlencode($anoFiltro); ?>&projeto=<?php echo urlencode($projetoFiltro); ?>&modelo=<?php echo urlencode($modeloFiltro); ?>&filtro=<?php echo urlencode($filtro); ?>" class="btn btn-outline-primary btn-sm">← Anterior</a>
                <?php endif; ?>
            </div>
            <div class="text-muted">Página <?php echo $pagina; ?> de <?php echo $totalPaginas; ?></div>
            <div>
                <?php if ($pagina < $totalPaginas): ?>
                    <a href="?pagina=<?php echo $pagina + 1; ?>&busca=<?php echo urlencode($busca); ?>&ano=<?php echo urlencode($anoFiltro); ?>&projeto=<?php echo urlencode($projetoFiltro); ?>&modelo=<?php echo urlencode($modeloFiltro); ?>&filtro=<?php echo urlencode($filtro); ?>" class="btn btn-outline-primary btn-sm">Próxima →</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="text-center mt-4">
            <a href="index.php" class="btn btn-outline-secondary">Voltar ao Dashboard</a>
        </div>
    </div>
    <script>
        window.INLINE_EDIT_ENDPOINT = 'edi.php';
        window.INLINE_EDIT_ACAO = 'ajax_editar_campo';
        window.FILTRO_ATUAL = <?php echo json_encode($filtro); ?>;
    </script>
    <script src="assets/inline-edit.js"></script>
    <script>
        // Alterna "Pendente"/"Atendido" via AJAX, sem recarregar a página.
        // Se a linha deixar de bater com o filtro atual (ex.: filtro=pendente
        // e a linha virou atendida), ela é removida da tabela na hora.
        document.addEventListener('submit', function (evento) {
            const form = evento.target;
            const acaoInput = form.querySelector('input[name="acao"]');
            if (!acaoInput || acaoInput.value !== 'alternar_atendido') return;

            evento.preventDefault();
            const dados = new URLSearchParams(new FormData(form));
            dados.set('acao', 'ajax_alternar_atendido');

            fetch('edi.php', {
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
            if (acaoInput && acaoInput.value === 'excluir_evento_edi') {
                sessionStorage.setItem('edi_scroll', String(window.scrollY));
            }
        });
        window.addEventListener('DOMContentLoaded', function () {
            const scrollSalvo = sessionStorage.getItem('edi_scroll');
            if (scrollSalvo !== null) {
                window.scrollTo(0, parseInt(scrollSalvo, 10) || 0);
                sessionStorage.removeItem('edi_scroll');
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
