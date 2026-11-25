<?php
// edi_historico_lib.php
// Funções compartilhadas entre edi.php (que grava o histórico) e
// edi_historico.php (que mostra/compara). Tabelas (rodar o SQL uma vez):
//   edi_versoes  — uma linha por importação da planilha semanal
//   edi_snapshot — "foto" das linhas do EDI dos materiais do arquivo,
//                  ANTES (com os ajustes manuais) e DEPOIS da importação
//   edi_ajustes  — cada alteração manual feita no EDI (duplo clique,
//                  edição, inclusão, exclusão, ação em massa)
// Se as tabelas ainda não existirem, tudo aqui vira "não faz nada" — o EDI
// continua funcionando normalmente.

// Modelo sem espaços, em maiúsculas e sem zeros à esquerda
// ("   09863739380" e "9863739380" viram a mesma chave).
function normalizarModeloEdi(?string $modelo): string
{
    $m = strtoupper(preg_replace('/\s+/u', '', (string) $modelo));
    $semZeros = ltrim($m, '0');
    return $semZeros === '' ? $m : $semZeros;
}

function historicoEdiDisponivel(mysqli $conn): bool
{
    static $disponivel = null;
    if ($disponivel !== null) {
        return $disponivel;
    }
    try {
        $disponivel = (bool) mysqli_query($conn, 'SELECT 1 FROM edi_versoes LIMIT 1')
            && (bool) mysqli_query($conn, 'SELECT 1 FROM edi_snapshot LIMIT 1')
            && (bool) mysqli_query($conn, 'SELECT 1 FROM edi_ajustes LIMIT 1');
    } catch (Throwable $e) {
        $disponivel = false;
    }
    return $disponivel;
}

function usuarioAtualEdi(): ?string
{
    foreach (['usuario_nome', 'nome', 'usuario', 'login', 'email'] as $chave) {
        if (!empty($_SESSION[$chave]) && is_string($_SESSION[$chave])) {
            return mb_substr($_SESSION[$chave], 0, 100);
        }
    }
    return null;
}

// Mapa modelo normalizado => ['material' => ..., 'modelo' => modelo como está na BOM]
function mapaModeloMaterialBom(mysqli $conn): array
{
    $mapa = [];
    $res = mysqli_query($conn, "
        SELECT TRIM(modelo) AS modelo, TRIM(material) AS material, COUNT(*) AS n
        FROM bomnova
        WHERE modelo IS NOT NULL AND TRIM(modelo) <> '' AND material IS NOT NULL AND TRIM(material) <> ''
        GROUP BY TRIM(modelo), TRIM(material)
        ORDER BY n DESC
    ");
    while ($res && ($l = mysqli_fetch_assoc($res))) {
        $chave = normalizarModeloEdi($l['modelo']);
        if ($chave !== '' && !isset($mapa[$chave])) {
            $mapa[$chave] = ['material' => $l['material'], 'modelo' => $l['modelo']];
        }
    }
    return $mapa;
}

// Material => modelo (da BOM), pra mostrar o modelo nas telas do histórico
function mapaMaterialModeloBom(mysqli $conn): array
{
    $mapa = [];
    $res = mysqli_query($conn, "
        SELECT TRIM(material) AS material, TRIM(modelo) AS modelo, MAX(TRIM(projeto)) AS projeto, COUNT(*) AS n
        FROM bomnova
        WHERE material IS NOT NULL AND TRIM(material) <> ''
        GROUP BY TRIM(material), TRIM(modelo)
        ORDER BY n DESC
    ");
    while ($res && ($l = mysqli_fetch_assoc($res))) {
        if (!isset($mapa[$l['material']]) || ($mapa[$l['material']]['modelo'] === '' && $l['modelo'] !== '')) {
            $mapa[$l['material']] = ['modelo' => (string) $l['modelo'], 'projeto' => (string) ($l['projeto'] ?? '')];
        }
    }
    return $mapa;
}

function criarVersaoEdi(mysqli $conn, ?string $geracao, string $arquivo): ?int
{
    if (!historicoEdiDisponivel($conn)) {
        return null;
    }
    $usuario = usuarioAtualEdi();
    $arquivo = mb_substr($arquivo, 0, 255);
    $stmt = mysqli_prepare($conn, "INSERT INTO edi_versoes (geracao, arquivo, usuario) VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'sss', $geracao, $arquivo, $usuario);
    mysqli_stmt_execute($stmt);
    $id = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    return $id > 0 ? $id : null;
}

// Copia pro edi_snapshot as linhas atuais do EDI desses materiais
// (pendentes E atendidos), marcadas com o momento 'antes' ou 'depois'.
function fotografarEdi(mysqli $conn, ?int $versaoId, string $momento, array $materiais): int
{
    if ($versaoId === null || empty($materiais) || !historicoEdiDisponivel($conn)) {
        return 0;
    }
    $total = 0;
    foreach (array_chunk(array_values(array_unique($materiais)), 400) as $bloco) {
        $ph = implode(',', array_fill(0, count($bloco), '?'));
        $stmt = mysqli_prepare($conn, "
            INSERT INTO edi_snapshot (versao_id, momento, material, modelo, projeto, evento, data, quantidade, atendido)
            SELECT ?, ?, TRIM(material), modelo, projeto, evento, data_inicio,
                   CAST(quantidade AS DECIMAL(18,4)), COALESCE(atendido, 0)
            FROM edi
            WHERE TRIM(material) IN ($ph)
        ");
        mysqli_stmt_bind_param($stmt, 'is' . str_repeat('s', count($bloco)), $versaoId, $momento, ...$bloco);
        mysqli_stmt_execute($stmt);
        $total += max(0, mysqli_stmt_affected_rows($stmt));
        mysqli_stmt_close($stmt);
    }
    return $total;
}

// Lê uma linha do EDI (pra guardar o "antes" de um ajuste)
function linhaEdiParaHistorico(mysqli $conn, int $id): ?array
{
    $stmt = mysqli_prepare($conn, "SELECT _tidb_rowid AS id, TRIM(material) AS material, modelo, projeto, evento, data_inicio, quantidade, COALESCE(atendido, 0) AS atendido FROM edi WHERE _tidb_rowid = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $linha = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    return $linha;
}

function linhasEdiParaHistorico(mysqli $conn, array $ids): array
{
    $linhas = [];
    foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 500) as $bloco) {
        $ph = implode(',', array_fill(0, count($bloco), '?'));
        $stmt = mysqli_prepare($conn, "SELECT _tidb_rowid AS id, TRIM(material) AS material, modelo, projeto, evento, data_inicio, quantidade, COALESCE(atendido, 0) AS atendido FROM edi WHERE _tidb_rowid IN ($ph)");
        mysqli_stmt_bind_param($stmt, str_repeat('i', count($bloco)), ...$bloco);
        mysqli_stmt_execute($stmt);
        foreach (mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC) as $l) {
            $linhas[(int) $l['id']] = $l;
        }
        mysqli_stmt_close($stmt);
    }
    return $linhas;
}

// Grava um ajuste manual. $acao: 'edicao' | 'inclusao' | 'exclusao' | 'massa'.
// Nunca derruba a tela: se der erro, só registra no log do servidor.
function registrarAjusteEdi(mysqli $conn, ?array $linha, string $acao, string $campo, $valorAnterior, $valorNovo): void
{
    if ($linha === null || !historicoEdiDisponivel($conn)) {
        return;
    }
    if ($acao === 'edicao' && (string) $valorAnterior === (string) $valorNovo) {
        return;
    }
    try {
        $usuario = usuarioAtualEdi();
        $ediId = (int) ($linha['id'] ?? 0);
        $material = (string) ($linha['material'] ?? '');
        $modelo = $linha['modelo'] ?? null;
        $projeto = $linha['projeto'] ?? null;
        $evento = $linha['evento'] ?? null;
        $data = $linha['data_inicio'] ?? null;
        $anterior = $valorAnterior === null ? null : mb_substr((string) $valorAnterior, 0, 100);
        $novo = $valorNovo === null ? null : mb_substr((string) $valorNovo, 0, 100);
        $stmt = mysqli_prepare($conn, "
            INSERT INTO edi_ajustes (edi_id, acao, campo, material, modelo, projeto, evento, data, valor_anterior, valor_novo, usuario)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        mysqli_stmt_bind_param($stmt, 'issssssssss', $ediId, $acao, $campo, $material, $modelo, $projeto, $evento, $data, $anterior, $novo, $usuario);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } catch (Throwable $e) {
        error_log('Histórico EDI: ' . $e->getMessage());
    }
}

// ---------- Leitura da planilha (CSV ou XLSX) ----------
// Devolve ['cabecalho' => [...], 'linhas' => [[...], ...]] ou ['erro' => '...'].
function lerPlanilhaEdi(string $caminho, string $nomeOriginal): array
{
    $ext = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        return lerXlsxEdi($caminho);
    }
    $conteudo = (string) file_get_contents($caminho);
    $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo);
    $conteudo = preg_replace("/\r\n?/", "\n", $conteudo);
    if (!mb_check_encoding($conteudo, 'UTF-8')) {
        $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252');
    }
    $primeira = strtok($conteudo, "\n");
    $sep = substr_count((string) $primeira, ';') > substr_count((string) $primeira, ',') ? ';' : ',';
    $h = fopen('php://temp', 'r+');
    fwrite($h, $conteudo);
    rewind($h);
    $cabecalho = fgetcsv($h, 0, $sep, '"', '\\');
    if ($cabecalho === false) {
        fclose($h);
        return ['erro' => 'Arquivo vazio ou inválido.'];
    }
    $linhas = [];
    while (($l = fgetcsv($h, 0, $sep, '"', '\\')) !== false) {
        $linhas[] = $l;
    }
    fclose($h);
    return ['cabecalho' => $cabecalho, 'linhas' => $linhas];
}

// XLSX lido "na mão" (é um zip com XML dentro) — não precisa de biblioteca.
// Usa só a primeira aba.
function lerXlsxEdi(string $caminho): array
{
    $temp = tempnam(sys_get_temp_dir(), 'edi') . '.zip';
    if (!copy($caminho, $temp)) {
        return ['erro' => 'Não foi possível ler o arquivo XLSX.'];
    }
    try {
        $zip = new PharData($temp, 0, null, Phar::ZIP);
        $ler = function (string $interno) use ($zip): ?string {
            return isset($zip[$interno]) ? file_get_contents($zip[$interno]->getPathname()) : null;
        };
        $compartilhadas = [];
        $xmlStrings = $ler('xl/sharedStrings.xml');
        if ($xmlStrings !== null) {
            $sx = simplexml_load_string($xmlStrings);
            foreach ($sx->si as $si) {
                if (isset($si->t)) {
                    $compartilhadas[] = (string) $si->t;
                } else {
                    $texto = '';
                    foreach ($si->r as $r) { $texto .= (string) $r->t; }
                    $compartilhadas[] = $texto;
                }
            }
        }
        $xmlAba = $ler('xl/worksheets/sheet1.xml');
        if ($xmlAba === null) {
            return ['erro' => 'Não encontrei a primeira aba do XLSX.'];
        }
        $aba = simplexml_load_string($xmlAba);
        $todas = [];
        foreach ($aba->sheetData->row as $row) {
            $valores = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $letras = preg_replace('/\d+/', '', $ref);
                $idx = 0;
                foreach (str_split($letras) as $ch) { $idx = $idx * 26 + (ord($ch) - 64); }
                $idx--;
                $tipo = (string) $c['t'];
                if ($tipo === 's') {
                    $v = $compartilhadas[(int) $c->v] ?? '';
                } elseif ($tipo === 'inlineStr') {
                    $v = (string) $c->is->t;
                } else {
                    $v = (string) $c->v;
                }
                $valores[$idx] = $v;
            }
            if (empty($valores)) { continue; }
            $max = max(array_keys($valores));
            $linha = [];
            for ($i = 0; $i <= $max; $i++) { $linha[] = $valores[$i] ?? ''; }
            $todas[] = $linha;
        }
        if (empty($todas)) {
            return ['erro' => 'Planilha vazia.'];
        }
        $cabecalho = array_shift($todas);
        $n = count($cabecalho);
        $todas = array_map(fn($l) => array_slice(array_pad($l, $n, ''), 0, $n), $todas);
        return ['cabecalho' => $cabecalho, 'linhas' => $todas];
    } catch (Throwable $e) {
        return ['erro' => 'Não foi possível ler o XLSX: ' . $e->getMessage()];
    } finally {
        @unlink($temp);
    }
}

// Cabeçalho → chave simples: minúsculas, sem acento, espaços únicos
function normalizarCabecalhoEdi(string $texto): string
{
    $t = mb_strtolower(trim($texto), 'UTF-8');
    $t = strtr($t, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c']);
    $t = preg_replace('/[_\.]+/', ' ', $t);
    return trim(preg_replace('/\s+/', ' ', $t));
}

// Data da planilha: dd/mm/aaaa, aaaa-mm-dd ou número de série do Excel
function parseDataPlanilhaEdi(string $valor): ?DateTimeImmutable
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }
    if (preg_match('/^\d{5}(\.\d+)?$/', $valor)) {
        return (new DateTimeImmutable('1899-12-30'))->modify('+' . (int) $valor . ' days');
    }
    foreach (['d/m/Y', 'Y-m-d', 'd/m/y'] as $formato) {
        $obj = DateTimeImmutable::createFromFormat('!' . $formato, $valor);
        if ($obj !== false) {
            return $obj;
        }
    }
    return null;
}
