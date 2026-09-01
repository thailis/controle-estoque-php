#!/bin/bash
# Verifica se as principais correções feitas na conversa estão presentes nos arquivos locais.
# Rode a partir da raiz do projeto: bash verificar_atualizacoes.sh

OK="✅"
FALHA="❌"
total=0
passou=0

checar() {
    local arquivo="$1"
    local marcador="$2"
    local descricao="$3"
    total=$((total+1))
    if [ ! -f "$arquivo" ]; then
        echo "$FALHA [$arquivo] $descricao — ARQUIVO NÃO ENCONTRADO"
        return
    fi
    if grep -q "$marcador" "$arquivo" 2>/dev/null; then
        echo "$OK [$arquivo] $descricao"
        passou=$((passou+1))
    else
        echo "$FALHA [$arquivo] $descricao — marcador não encontrado, versão desatualizada?"
    fi
}

echo "======================================================"
echo " Verificando atualizações do projeto"
echo "======================================================"

echo ""
echo "--- index.php (dashboard) ---"
checar "index.php" "saldoNoFimDaZonaTravada" "Acompanhar: verifica se programação cobre o déficit até o fim da zona travada"
checar "index.php" "fim90Chave" "Sem demanda: estoque zerado sem previsão nos próximos 90 dias"
checar "index.php" "horizonteMrp = \$hojeMrp->modify('+180 days')" "Performance: horizonte reduzido pra 180 dias"
checar "index.php" "'acompanhar', 'atencao', 'excesso', 'planejar'" "Todas as categorias de status (crítico/acompanhar/atenção/excesso/planejar/ok/sem_demanda)"
checar "index.php" "debug_tempo" "Ferramenta de diagnóstico de performance"
checar "index.php" "SAP - Site Avançado em Planilhas" "Subtítulo atualizado do dashboard"
checar "index.php" "function h(mixed \$valor)" "Correção de tipo (mixed) nas funções auxiliares"

echo ""
echo "--- estoque.php ---"
checar "estoque.php" "idxMrp" "Ignora coluna 'MRP' na importação (não trata como planta)"

echo ""
echo "--- dashboard.css (dentro de assets/) ---"
checar "assets/dashboard.css" "metric-acompanhar" "Card 'Acompanhar' no topo do dashboard"
checar "assets/dashboard.css" "dot-acompanhar" "Bolinha da legenda 'Acompanhar'"
checar "assets/dashboard.css" "row-acompanhar" "Barra lateral da linha 'Acompanhar' na tabela"
checar "assets/dashboard.css" "repeat(auto-fit" "Grid de métricas flexível (se ajusta ao número de cards)"

echo ""
echo "--- planejamento_compras.php ---"
checar "planejamento_compras.php" "'Planejar'" "Badge renomeado de 'Programada' para 'Planejar'"

echo ""
echo "--- parametros_compra.php ---"
checar "parametros_compra.php" "ON DUPLICATE KEY UPDATE" "Importação em modo upsert (evita duplicata de parâmetros)"

echo ""
echo "--- edi.php ---"
checar "edi.php" "parseQuantidadeEdi" "Correção do formato numérico BR (milhar) na quantidade do EDI"

echo ""
echo "--- Arquivos antigos que NÃO deveriam mais existir ---"
for antigo in import_estoque.php visualizar_estoque.php import_edi.php visualizar_edi.php import_bomnova.php visualizar_bomnova.php import_programacao.php visualizar_programacao.php import_parametros_compra.php visualizar_parametros_compra.php; do
    total=$((total+1))
    if [ -f "$antigo" ]; then
        echo "$FALHA $antigo ainda existe — pode remover, já foi unificado"
    else
        echo "$OK $antigo não existe mais (correto)"
        passou=$((passou+1))
    fi
done

echo ""
echo "======================================================"
echo " Resultado: $passou de $total checagens passaram"
echo "======================================================"
if [ "$passou" -eq "$total" ]; then
    echo "🎉 Tudo atualizado!"
else
    echo "⚠️  Tem coisa desatualizada — revise as linhas com $FALHA acima."
fi
