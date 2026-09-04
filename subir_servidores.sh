#!/bin/bash
# subir_servidores.sh
#
# Sobe os dois sites de uma vez, cada um na porta e pasta certa — evita o erro
# de esquecer o "-t importacao" e a porta 8001 acabar servindo o MRP por engano.
#
# Uso: bash subir_servidores.sh
# Pra parar os dois: Ctrl+C (mata os dois, já que rodam em segundo plano deste script)

cd "$(dirname "$0")" || exit 1

echo "Derrubando qualquer servidor antigo nas portas 8000/8001..."
pkill -f "php -S 0.0.0.0:8000" 2>/dev/null
pkill -f "php -S 0.0.0.0:8001" 2>/dev/null
sleep 1

echo "Subindo MRP na porta 8000..."
php -S 0.0.0.0:8000 &
PID_MRP=$!

echo "Subindo site de Importação na porta 8001 (pasta importacao/)..."
php -S 0.0.0.0:8001 -t importacao &
PID_IMPORT=$!

echo ""
echo "✅ MRP rodando (PID $PID_MRP) — porta 8000"
echo "✅ Importação rodando (PID $PID_IMPORT) — porta 8001, pasta importacao/"
echo ""
echo "Pressione Ctrl+C pra derrubar os dois."

# Espera os dois processos — Ctrl+C mata o script e (via trap) os dois PHPs junto
trap "kill $PID_MRP $PID_IMPORT 2>/dev/null" EXIT
wait
