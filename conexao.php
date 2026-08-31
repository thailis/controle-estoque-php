<?php
// Credenciais devem ser configuradas como variáveis de ambiente no servidor.
// Consulte o README.md para os nomes e um exemplo de configuração.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('DB_HOST') ?: '';
$port = (int) (getenv('DB_PORT') ?: 4000);
$dbname = getenv('DB_NAME') ?: 'controle_mrp';
$user = getenv('DB_USER') ?: '';
$password = getenv('DB_PASSWORD') ?: '';
$sslCa = getenv('DB_SSL_CA') ?: '/etc/ssl/certs/ca-certificates.crt';

if ($host === '' || $user === '' || $password === '') {
    http_response_code(500);
    die('Configuração do banco incompleta. Defina DB_HOST, DB_USER e DB_PASSWORD no servidor.');
}

try {
    $conn = mysqli_init();
    mysqli_ssl_set($conn, null, null, $sslCa, null, null);
    mysqli_real_connect($conn, $host, $user, $password, $dbname, $port, null, MYSQLI_CLIENT_SSL);
    mysqli_set_charset($conn, 'utf8mb4');
} catch (mysqli_sql_exception $erro) {
    error_log('Falha na conexão com o banco: ' . $erro->getMessage());
    http_response_code(500);
    die('Não foi possível conectar ao banco de dados. Verifique a configuração do servidor.');
}
