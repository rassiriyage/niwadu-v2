<?php

require_once dirname(__DIR__).'/vendor/autoload.php';

$readEnvironment = static fn (string $key): string => (string) ($_ENV[$key] ?? getenv($key) ?: '');
$required = [
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '55434', 'DB_USERNAME' => 'niwadu_catalog_test', 'DB_URL' => '',
    'MAIL_MAILER' => 'log', 'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
];
foreach ($required as $key => $expected) {
    if ($readEnvironment($key) !== $expected) {
        throw new RuntimeException('Unsafe PostgreSQL test configuration: '.$key);
    }
}
$database = $readEnvironment('DB_DATABASE');
if (! preg_match('/\Aniwadu_catalog_test_[a-z0-9_]+\z/', $database) || strlen($database) > 63) {
    throw new RuntimeException('Unsafe PostgreSQL test database name.');
}
if ($readEnvironment('DB_PASSWORD') === '') {
    throw new RuntimeException('PostgreSQL test password must be supplied from local ignored configuration.');
}
if ($readEnvironment('APP_CONFIG_CACHE') !== '' || file_exists(dirname(__DIR__).'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Cached application configuration is forbidden for PostgreSQL tests.');
}

$connection = new PDO(
    'pgsql:host=127.0.0.1;port=55434;dbname='.$database.';connect_timeout=3;sslmode=disable',
    'niwadu_catalog_test', $readEnvironment('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$identity = $connection->query("SELECT current_database() AS database, current_user AS username,
    current_setting('server_version_num')::integer AS version,
    current_setting('cluster_name') AS cluster, host(inet_server_addr()) AS address,
    inet_server_port() AS port, rolsuper::integer AS superuser, rolcreatedb::integer AS createdb,
    rolcreaterole::integer AS createrole, rolreplication::integer AS replication,
    rolbypassrls::integer AS bypassrls FROM pg_roles WHERE rolname = current_user")->fetch(PDO::FETCH_ASSOC);
if ($identity['database'] !== $database || $identity['username'] !== 'niwadu_catalog_test'
    || $identity['version'] < 170000 || $identity['version'] >= 180000
    || $identity['cluster'] !== 'niwadu_catalog_isolated' || $identity['address'] !== '127.0.0.1'
    || (int) $identity['port'] !== 55434
    || array_sum(array_intersect_key($identity, array_flip(['superuser', 'createdb', 'createrole', 'replication', 'bypassrls']))) !== 0) {
    throw new RuntimeException('PostgreSQL test server or role identity is not the isolated catalog target.');
}
$connection = null;
