<?php
// importacao/conexao.php
//
// Conexão com o PRÓPRIO banco do site de importação (controle_importacao).
// Usa variáveis de ambiente com prefixo IMPORT_ — como esse site roda no
// mesmo Codespace/repositório do MRP, não dá pra reusar DB_HOST/DB_USER/etc,
// esses nomes já apontam pro banco do MRP.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('IMPORT_DB_HOST') ?: '';
$port = (int) (getenv('IMPORT_DB_PORT') ?: 4000);
$dbname = getenv('IMPORT_DB_NAME') ?: 'controle_importacao';
$user = getenv('IMPORT_DB_USER') ?: '';
$password = getenv('IMPORT_DB_PASSWORD') ?: '';
$sslCa = getenv('IMPORT_DB_SSL_CA') ?: '/etc/ssl/certs/ca-certificates.crt';

if ($host === '' || $user === '' || $password === '') {
    http_response_code(500);
    die('Configuração do banco de importação incompleta. Defina IMPORT_DB_HOST, IMPORT_DB_USER e IMPORT_DB_PASSWORD no servidor.');
}

try {
    $conn = mysqli_init();
    mysqli_ssl_set($conn, null, null, $sslCa, null, null);
    mysqli_real_connect($conn, $host, $user, $password, $dbname, $port, null, MYSQLI_CLIENT_SSL);
    mysqli_set_charset($conn, 'utf8mb4');
} catch (mysqli_sql_exception $erro) {
    error_log('Falha na conexão com o banco de importação: ' . $erro->getMessage());
    http_response_code(500);
    die('Não foi possível conectar ao banco de dados de importação. Verifique a configuração do servidor.');
}
