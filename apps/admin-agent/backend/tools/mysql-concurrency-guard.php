<?php

declare(strict_types=1);

/** Pure URL and generated-worker-name guards; safe to exercise without a DB. */
function nsAgentParseMySqlUrl(string $url, string $schema): array
{
    $p = parse_url($url);
    if (!is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'mysql'
        || !in_array(strtolower((string) ($p['host'] ?? '')), ['127.0.0.1', '::1'], true)
        || ($p['path'] ?? '') === '' || isset($p['fragment'])) {
        throw new InvalidArgumentException('Non-loopback, non-MySQL, or malformed URL.');
    }
    $query = [];
    $raw = (string) ($p['query'] ?? '');
    if ($raw !== '') {
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') throw new InvalidArgumentException('Empty query parameter.');
            [$rawKey, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
            $key = rawurldecode(str_replace('+', ' ', $rawKey));
            $value = rawurldecode(str_replace('+', ' ', $rawValue));
            if (!in_array($key, ['charset', 'serverVersion'], true) || array_key_exists($key, $query)) {
                throw new InvalidArgumentException('Unsupported or duplicate connection URL parameter.');
            }
            if ($key === 'charset' && $value !== 'utf8mb4') throw new InvalidArgumentException('Unsupported connection charset.');
            if ($key === 'serverVersion' && !preg_match('/^[0-9][A-Za-z0-9._-]{0,39}$/D', $value)) {
                throw new InvalidArgumentException('Invalid server version value.');
            }
            $query[$key] = $value;
        }
    }
    $sourceName = ltrim((string) $p['path'], '/');
    if ($sourceName === '' || str_contains($sourceName, '/')) throw new InvalidArgumentException('Invalid database path.');
    if (!preg_match('/^ns_agent_mysql_test_[a-f0-9]{16}$/D', $schema)) throw new InvalidArgumentException('Invalid generated schema name.');
    return ['host' => strtolower((string) $p['host']), 'port' => (int) ($p['port'] ?? 3306), 'source_schema' => $sourceName,
        'user' => rawurldecode((string) ($p['user'] ?? '')), 'pass' => rawurldecode((string) ($p['pass'] ?? '')),
        'schema' => $schema, 'query' => $query];
}

function nsAgentCanonicalMySqlUrl(array $params): string
{
    $authority = rawurlencode($params['user']).':'.rawurlencode($params['pass']).'@'.$params['host'].':'.$params['port'];
    $query = ['charset' => 'utf8mb4'];
    if (isset($params['query']['serverVersion'])) $query['serverVersion'] = $params['query']['serverVersion'];
    return 'mysql://'.$authority.'/'.$params['schema'].'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function nsAgentAssertEffectiveTarget(array $params, string $schema, int $expectedPort): void
{
    if (($params['dbname'] ?? null) !== $schema
        || !in_array(strtolower((string) ($params['host'] ?? '')), ['127.0.0.1', '::1'], true)
        || isset($params['unix_socket']) && $params['unix_socket'] !== null && $params['unix_socket'] !== ''
        || (int) ($params['port'] ?? 3306) !== $expectedPort) {
        throw new RuntimeException('Doctrine effective database target failed safety validation.');
    }
}

function nsAgentAssertWorkerSchema(string $schema): void
{
    if (!preg_match('/^ns_agent_mysql_test_[a-f0-9]{16}$/D', $schema)) throw new InvalidArgumentException('Invalid worker schema name.');
}

function nsAgentResolveRunEnvironment(bool $worker, ?string $inherited, string $schema): string
{
    nsAgentAssertWorkerSchema($schema);
    $expected = 'mysql_harness_'.substr($schema, strlen('ns_agent_mysql_test_'));
    if (!$worker) return $expected;
    if (!is_string($inherited) || !preg_match('/^mysql_harness_[a-f0-9]{16}$/D', $inherited) || $inherited !== $expected) {
        throw new InvalidArgumentException('Worker environment does not match this generated schema run.');
    }
    return $inherited;
}

function nsAgentCountingAdapter(object $delegate, object $connection, string $phase): object
{
    return new class($delegate, $connection, $phase) implements NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter {
        public function __construct(private object $delegate, private object $connection, private string $phase) {}
        public function entityKey(): string { return $this->delegate->entityKey(); }
        public function authorize(string $action, ?string $identifier): void { $this->delegate->authorize($action, $identifier); }
        public function prepare(string $action, ?string $identifier, array $changes): array { return $this->delegate->prepare($action, $identifier, $changes); }
        public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array
        {
            $updated = $this->connection->executeStatement('UPDATE ns_agent_harness_executions SET executions = executions + 1 WHERE phase = ?', [$this->phase]);
            if ($updated !== 1) throw new RuntimeException('Harness execution counter was not updated.');
            return $this->delegate->execute($action, $identifier, $beforeHash, $changes);
        }
    };
}

function nsAgentGuardSelfTest(): void
{
    $schema = 'ns_agent_mysql_test_0123456789abcdef';
    $safe = nsAgentParseMySqlUrl('mysql://u%40x:p%2Fq@127.0.0.1:3307/ns_ultimate?charset=utf8mb4&serverVersion=8.0.41', $schema);
    $canonical = nsAgentCanonicalMySqlUrl($safe);
    if (!str_contains($canonical, '/'.$schema.'?') || str_contains($canonical, 'ns_ultimate') || !str_contains($canonical, 'u%40x:p%2Fq@')) {
        throw new RuntimeException('Canonical URL self-test failed.');
    }
    foreach ([
        '?dbname=ns_ultimate', '?host=evil.example', '?port=3307', '?unix_socket=%2Ftmp%2Fmysql.sock',
        '?url=mysql%3A%2F%2Fevil%2Fns_ultimate', '?x[y]=value', '?charset=utf8mb4&charset=latin1',
        '?%64bname=ns_ultimate', '?serverVersion=8.0.41&unknown=x',
    ] as $query) {
        try {
            nsAgentParseMySqlUrl('mysql://u:p@127.0.0.1/ns_ultimate'.$query, $schema);
            throw new RuntimeException('Adversarial URL self-test was not rejected.');
        } catch (InvalidArgumentException) {
        }
    }
    foreach (['ns_ultimate', 'ns_agent_mysql_test_0123456789abcdeg', 'ns_agent_mysql_test_0123456789abcdef;DROP'] as $bad) {
        try {
            nsAgentAssertWorkerSchema($bad);
            throw new RuntimeException('Worker-name self-test was not rejected.');
        } catch (InvalidArgumentException) {
        }
    }
    foreach ([
        ['host' => 'evil.example', 'dbname' => $schema, 'port' => 3306],
        ['host' => '127.0.0.1', 'dbname' => 'ns_ultimate', 'port' => 3306],
        ['host' => '127.0.0.1', 'dbname' => $schema, 'port' => 3307],
        ['host' => '127.0.0.1', 'dbname' => $schema, 'port' => 3306, 'unix_socket' => '/tmp/mysql.sock'],
    ] as $effective) {
        try {
            nsAgentAssertEffectiveTarget($effective, $schema, 3306);
            throw new RuntimeException('Effective-target self-test was not rejected.');
        } catch (RuntimeException $error) {
            if ($error->getMessage() === 'Effective-target self-test was not rejected.') throw $error;
        }
    }
    $generatedEnv = 'mysql_harness_0123456789abcdef';
    foreach (['dev', 'prod', 'test', 'malformed', null] as $inherited) {
        if (nsAgentResolveRunEnvironment(false, $inherited, $schema) !== $generatedEnv) throw new RuntimeException('Parent environment generation self-test failed.');
    }
    foreach (['dev', 'prod', 'test', 'mysql_harness_bad', 'mysql_harness_ffffffffffffffff', null] as $inherited) {
        try {
            nsAgentResolveRunEnvironment(true, $inherited, $schema);
            throw new RuntimeException('Worker environment self-test was not rejected.');
        } catch (InvalidArgumentException) {
        }
    }
    if (nsAgentResolveRunEnvironment(true, $generatedEnv, $schema) !== $generatedEnv) throw new RuntimeException('Worker matching environment self-test failed.');

    $temporaryRoot = sys_get_temp_dir().'/ns-agent-cache-guard-'.bin2hex(random_bytes(8));
    $cacheBase = $temporaryRoot.'/var/cache/backend';
    if (!mkdir($cacheBase, 0700, true)) throw new RuntimeException('Cache self-test setup failed.');
    $testEnv = 'mysql_harness_0123456789abcdef';
    $preexisting = $cacheBase.'/'.$testEnv;
    mkdir($preexisting, 0700);
    file_put_contents($preexisting.'/sentinel', 'preserve');
    if (nsAgentAcquireCache($temporaryRoot, $testEnv)) throw new RuntimeException('Pre-existing cache path was claimed.');
    if (!nsAgentRemoveOwnedCache($temporaryRoot, $testEnv, false)) throw new RuntimeException('Unowned cache refusal self-test failed.');
    if (!is_file($preexisting.'/sentinel')) throw new RuntimeException('Unowned cache contents were deleted.');
    unlink($preexisting.'/sentinel');
    rmdir($preexisting);
    if (!nsAgentAcquireCache($temporaryRoot, $testEnv)) throw new RuntimeException('Exclusive cache ownership claim failed.');
    file_put_contents($preexisting.'/owned', 'remove');
    if (!nsAgentRemoveOwnedCache($temporaryRoot, $testEnv, true)) throw new RuntimeException('Owned cache cleanup result failed.');
    if (file_exists($preexisting)) throw new RuntimeException('Owned cache cleanup failed.');
    $external = $temporaryRoot.'/external';
    mkdir($external, 0700);
    file_put_contents($external.'/sentinel', 'preserve');
    if (!symlink($external, $preexisting)) throw new RuntimeException('Cache symlink self-test setup failed.');
    if (nsAgentRemoveOwnedCache($temporaryRoot, $testEnv, true)) throw new RuntimeException('Owned symlink cache was not refused.');
    if (!is_file($external.'/sentinel') || !is_link($preexisting)) throw new RuntimeException('Cache symlink guard failed.');
    unlink($preexisting);
    unlink($external.'/sentinel');
    rmdir($external);
    rmdir($cacheBase);
    rmdir(dirname($cacheBase));
    rmdir(dirname(dirname($cacheBase)));
    rmdir($temporaryRoot);
}

function nsAgentAcquireCache(string $root, string $environment): bool
{
    if (!preg_match('/^mysql_harness_[a-f0-9]{16}$/D', $environment)) return false;
    $base = $root.'/var/cache/backend';
    $path = $base.'/'.$environment;
    if (!is_dir($base) || is_link($base)) return false;
    return @mkdir($path, 0700);
}

function nsAgentRemoveOwnedCache(string $root, string $environment, bool $owned): bool
{
    if (!$owned) return true;
    if (!preg_match('/^mysql_harness_[a-f0-9]{16}$/D', $environment)) return false;
    $base = $root.'/var/cache/backend';
    $path = $base.'/'.$environment;
    if (!is_dir($base) || is_link($base)) return false;
    if (!file_exists($path) && !is_link($path)) return true;
    if (!is_dir($path) || is_link($path)) return false;
    $remove = static function (string $target) use (&$remove): void {
        if (is_link($target) || is_file($target)) { unlink($target); return; }
        if (!is_dir($target)) return;
        foreach (new DirectoryIterator($target) as $entry) {
            if ($entry->isDot()) continue;
            $remove($entry->getPathname());
        }
        rmdir($target);
    };
    $remove($path);
    return !file_exists($path) && !is_link($path);
}
