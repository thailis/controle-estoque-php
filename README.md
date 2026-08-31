# Controle de Estoque MRP

Dashboard em PHP para cruzar:

- demanda por semana da tabela `edi`;
- consumo por item da tabela `bomnova`;
- saldo disponível da tabela `estoque`.

## Configuração do banco

As credenciais não ficam mais gravadas no código. Configure estas variáveis no ambiente em que o PHP será executado:

```text
DB_HOST=gateway01.sa-east-1.prod.aws.tidbcloud.com
DB_PORT=4000
DB_NAME=controle_mrp
DB_USER=seu_usuario
DB_PASSWORD=sua_nova_senha
DB_SSL_CA=/etc/ssl/certs/ca-certificates.crt
```

Use `.env.example` somente como referência. O PHP lê as variáveis do servidor com `getenv()`.

> Importante: troque a senha antiga no painel do TiDB antes de publicar esta versão.

## Dashboard

O painel principal agora permite:

- filtrar por ano e intervalo de semanas;
- buscar por componente, material, descrição ou fornecedor;
- filtrar por fornecedor, projeto e status;
- ver estoque, demanda calculada, necessidade de compra e saldo projetado;
- ordenar as colunas;
- exportar o resultado filtrado em CSV;
- identificar componentes sem estoque cadastrado.

### Regras de status

- **Compra urgente:** saldo projetado menor que zero.
- **Atenção:** o estoque cobre a demanda, mas a margem é de até 20%.
- **Estoque OK:** margem acima de 20%.
- **Sem demanda:** nenhuma demanda EDI foi encontrada no período filtrado.

O cálculo da demanda é `quantidade EDI × consumo da BOM`, consolidado pelo código do componente.
