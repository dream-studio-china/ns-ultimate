<?php

declare(strict_types=1);

$root = dirname(__DIR__);
umask(0077);

function prompt(string $label, string $default): string
{
    fwrite(STDOUT, sprintf('%s [%s]: ', $label, $default));
    $value = fgets(STDIN);

    return $value === false || trim($value) === '' ? $default : trim($value);
}

function promptSecret(string $label): string
{
    if (!stream_isatty(STDIN)) {
        throw new RuntimeException('A terminal is required to enter the database password securely.');
    }

    fwrite(STDOUT, $label.': ');
    exec('stty -echo 2>/dev/null');
    try {
        $password = fgets(STDIN);
    } finally {
        exec('stty echo 2>/dev/null');
        fwrite(STDOUT, PHP_EOL);
    }

    if ($password === false || trim($password) === '') {
        throw new RuntimeException('Database password cannot be empty.');
    }

    return rtrim($password, "\r\n");
}

/** @param list<string> $command */
function runCommand(array $command, string $root): void
{
    $process = proc_open($command, [
        0 => STDIN,
        1 => STDOUT,
        2 => STDERR,
    ], $pipes, $root);

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start: '.basename($command[1] ?? $command[0]));
    }

    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException(sprintf('Command failed with exit code %d.', $status));
    }
}

/** @param array<string, string> $values */
function writeDatabaseUrl(string $path, array $values): void
{
    if (is_link($path)) {
        throw new RuntimeException('Refusing to write the database URL through a symlinked env file.');
    }
    $contents = is_file($path) ? file_get_contents($path) : '';
    if (!is_string($contents)) {
        throw new RuntimeException('Cannot read integration/backend/.env.local.');
    }
    $stat = is_file($path) ? stat($path) : false;
    if (is_array($stat) && ($stat['nlink'] ?? 0) > 1) {
        throw new RuntimeException('Refusing to modify an env file with multiple hard links.');
    }

    $host = str_contains($values['host'], ':') && !str_starts_with($values['host'], '[')
        ? '['.$values['host'].']'
        : $values['host'];
    $url = sprintf(
        'mysql://%s:%s@%s:%s/%s?serverVersion=%s&charset=utf8mb4',
        rawurlencode($values['user']),
        rawurlencode($values['password']),
        $host,
        $values['port'],
        rawurlencode($values['database']),
        rawurlencode($values['server_version']),
    );
    $line = 'DATABASE_URL="'.$url.'"';

    if (preg_match('/^\s*DATABASE_URL\s*=.*$/m', $contents) === 1) {
        $contents = preg_replace('/^\s*DATABASE_URL\s*=.*$/m', $line, $contents, 1) ?? $contents;
    } else {
        $contents .= ($contents === '' || str_ends_with($contents, "\n") ? '' : "\n").$line."\n";
    }

    if (file_put_contents($path, $contents, LOCK_EX) === false || !chmod($path, 0600)) {
        throw new RuntimeException('Cannot securely write integration/backend/.env.local.');
    }
}

function persistAdminEmail(string $root, string $email): void
{
    $passwordPath = getenv('NS_INITIAL_PASSWORD_FILE') ?: 'var/keys/admin-initial-password.txt';
    $path = getenv('NS_ADMIN_EMAIL_FILE') ?: dirname($passwordPath).'/admin-email.txt';
    if (!str_starts_with($path, '/')) {
        $path = $root.'/'.$path;
    }
    if (is_link($path)) {
        throw new RuntimeException('Refusing to write the admin email through a symlink.');
    }
    if (file_put_contents($path, $email.PHP_EOL, LOCK_EX) === false || !chmod($path, 0600)) {
        throw new RuntimeException('Cannot securely save the development admin email.');
    }
}

try {
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('The pdo_mysql PHP extension is required.');
    }

    $isDockerSetup = getenv('NS_SKIP_DB_OWNERSHIP_MARKER') === '1';
    if (!$isDockerSetup) {
        putenv('NS_DEV_DATA_DIR=var/local-dev/data');
        putenv('NS_DEV_KEYS_DIR=var/local-dev/keys');
        putenv('NS_DEV_BACKEND_ENV_FILE=integration/backend/.env.dev.local');
        putenv('NS_DEV_APP_SHARE_DOTENV=${NS_PROJECT_ROOT}/var/local-dev/data');
        putenv('NS_DEV_PRIVATE_KEY_DOTENV=${NS_PROJECT_ROOT}/var/local-dev/keys/private.pem');
        putenv('NS_DEV_PUBLIC_KEY_DOTENV=${NS_PROJECT_ROOT}/var/local-dev/keys/public.pem');
        putenv('NS_DEV_DATABASE_TOKEN_FILE='.$root.'/var/local-dev/database-token.txt');
        putenv('NS_INITIAL_PASSWORD_FILE=var/local-dev/keys/admin-initial-password.txt');
    }

    fwrite(STDOUT, "Development database and administrator setup\n\n");
    if ($isDockerSetup) {
        $host = (string) getenv('NS_DEV_DB_HOST');
        $port = (string) getenv('NS_DEV_DB_PORT');
        $user = (string) getenv('NS_DEV_DB_USER');
        $password = (string) getenv('NS_DEV_DB_PASSWORD');
        $database = (string) getenv('NS_DEV_DB_NAME');
        if (in_array('', [$host, $port, $user, $password, $database], true)) {
            throw new RuntimeException('Docker development MySQL settings are incomplete in Compose configuration.');
        }
        fwrite(STDOUT, sprintf("Using Compose development MySQL at %s:%s/%s.\n", $host, $port, $database));
    } else {
        $host = prompt('MySQL host', getenv('NS_DEV_DB_HOST') ?: '127.0.0.1');
        $port = prompt('MySQL port', getenv('NS_DEV_DB_PORT') ?: '3306');
        $user = prompt('MySQL username', getenv('NS_DEV_DB_USER') ?: 'root');
        $password = getenv('NS_DEV_DB_PASSWORD') ?: promptSecret('MySQL password');
        $database = prompt('Development database name', getenv('NS_DEV_DB_NAME') ?: 'ns_ultimate');
    }
    $adminEmail = prompt('Initial admin email', 'admin@example.com');
    $adminUsername = prompt('Initial admin username', 'admin');

    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('MySQL port must be between 1 and 65535.');
    }
    if (preg_match('/^[A-Za-z0-9_.:-]+$/', $host) !== 1) {
        throw new RuntimeException('MySQL host contains unsupported characters.');
    }
    if (getenv('NS_SKIP_DB_OWNERSHIP_MARKER') !== '1' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('Local make dev setup only supports a MySQL server on this machine. Use make docker-dev for a separate container database.');
    }
    if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
        throw new RuntimeException('Database name may contain only letters, digits, and underscores.');
    }
    if (getenv('NS_SKIP_DB_OWNERSHIP_MARKER') !== '1' && $database !== 'ns_ultimate') {
        throw new RuntimeException('Local make dev setup is restricted to the ns_ultimate database.');
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('A valid admin email address is required.');
    }
    if (preg_match('/^[A-Za-z0-9_.-]{1,180}$/', $adminUsername) !== 1) {
        throw new RuntimeException('Admin username may contain letters, digits, dots, underscores, and hyphens.');
    }

    $serverDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, (int) $port);
    $server = new PDO($serverDsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $serverIdentity = null;
    if (getenv('NS_SKIP_DB_OWNERSHIP_MARKER') !== '1') {
        require_once $root.'/scripts/dev-database-guard.php';
        $serverIdentity = getMySqlServerIdentity($server);
    }
    $serverVersion = (string) $server->query('SELECT VERSION()')->fetchColumn();
    $versionMatch = [];
    if (preg_match('/^(\d+\.\d+\.\d+)/', $serverVersion, $versionMatch) !== 1) {
        throw new RuntimeException('Could not determine the MySQL server version.');
    }
    $serverVersion = str_contains(strtolower($serverVersion), 'mariadb')
        ? 'mariadb-'.$versionMatch[1]
        : $versionMatch[1];

    $displayHost = str_contains($host, ':') && !str_starts_with($host, '[') ? '['.$host.']' : $host;
    $target = sprintf('%s:%d/%s', $displayHost, (int) $port, $database);
    fwrite(STDOUT, sprintf(
        "\nTarget: %s (MySQL server %s:%d, MySQL-compatible %s)\n",
        $target,
        $serverIdentity['hostname'] ?? $host,
        $serverIdentity['port'] ?? (int) $port,
        $serverVersion,
    ));
    fwrite(STDOUT, "This will register this as a development database, create it if missing, apply migrations, and create the admin if absent.\n");
    fwrite(STDOUT, sprintf('Type the exact target "%s" to continue: ', $target));
    if (trim((string) fgets(STDIN)) !== $target) {
        fwrite(STDOUT, "Cancelled; no database changes were made.\n");
        exit(1);
    }

    $databaseExists = $server->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :database');
    $databaseExists->execute(['database' => $database]);
    if ($databaseExists->fetchColumn() === false) {
        $server->exec(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $database,
        ));
    }
    $databaseDsn = sprintf('%s;dbname=%s', $serverDsn, $database);
    $connection = new PDO($databaseDsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $tokenFile = getenv('NS_DEV_DATABASE_TOKEN_FILE');
    if (!$isDockerSetup && $tokenFile !== false && $tokenFile !== '') {
        if (is_link($root.'/var') || is_link(dirname($tokenFile)) || is_link($tokenFile)) {
            throw new RuntimeException('Refusing to use a symlinked local development ownership-token path.');
        }
        $tableExists = $connection->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :database AND TABLE_NAME = :table',
        );
        $tableExists->execute(['database' => $database, 'table' => 'ns_ultimate_dev_guard']);
        $hasGuardTable = (int) $tableExists->fetchColumn() > 0;
        $databaseToken = null;
        if ($hasGuardTable) {
            $databaseToken = $connection->query('SELECT token FROM ns_ultimate_dev_guard WHERE id = 1')->fetchColumn();
            $databaseToken = is_string($databaseToken) ? $databaseToken : null;
        }
        $localToken = is_file($tokenFile) ? trim((string) file_get_contents($tokenFile)) : null;
        if ($databaseToken !== null && $localToken !== null && !hash_equals($databaseToken, $localToken)) {
            throw new RuntimeException('The MySQL development ownership token does not match this checkout. Refusing to continue.');
        }
        $token = $databaseToken ?? $localToken ?? bin2hex(random_bytes(32));
        if (!$hasGuardTable) {
            $connection->exec(
                'CREATE TABLE ns_ultimate_dev_guard (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, token CHAR(64) NOT NULL)',
            );
        }
        if ($databaseToken === null) {
            $insertToken = $connection->prepare('INSERT INTO ns_ultimate_dev_guard (id, token) VALUES (1, :token)');
            $insertToken->execute(['token' => $token]);
        }
        if ($localToken === null) {
            $tokenDirectory = dirname($tokenFile);
            if (!is_dir($tokenDirectory) && !mkdir($tokenDirectory, 0700, true)) {
                throw new RuntimeException('Cannot create the local development ownership-token directory.');
            }
            $tokenHandle = fopen($tokenFile, 'x');
            if ($tokenHandle === false) {
                throw new RuntimeException('Cannot securely create the local development ownership token.');
            }
            chmod($tokenFile, 0600);
            try {
                if (fwrite($tokenHandle, $token.PHP_EOL) === false) {
                    throw new RuntimeException('Cannot write the local development ownership token.');
                }
            } finally {
                fclose($tokenHandle);
            }
        }
    }

    putenv('APP_ENV=dev');
    runCommand([PHP_BINARY, $root.'/scripts/env.php', 'env-init'], $root);
    if (getenv('NS_SKIP_DATABASE_URL_WRITE') !== '1') {
        putenv('DATABASE_URL');
        unset($_SERVER['DATABASE_URL'], $_ENV['DATABASE_URL']);
        $backendEnvFile = getenv('NS_DEV_BACKEND_ENV_FILE') ?: ($isDockerSetup ? 'integration/backend/.env.local' : 'integration/backend/.env.dev.local');
        $backendEnvFilePath = str_starts_with($backendEnvFile, '/') ? $backendEnvFile : $root.'/'.$backendEnvFile;
        if (is_link($backendEnvFilePath)) {
            throw new RuntimeException('Refusing to write the local database URL through a symlinked env file.');
        }
        writeDatabaseUrl($backendEnvFilePath, [
            'host' => $host,
            'port' => $port,
            'user' => $user,
            'password' => $password,
            'database' => $database,
            'server_version' => $serverVersion,
        ]);
    }
    putenv('APP_ENV=dev');
    unset($_SERVER['APP_ENV'], $_ENV['APP_ENV']);

    fwrite(STDOUT, "\nApplying pending project migrations...\n");
    runCommand([
        PHP_BINARY,
        $root.'/integration/backend/bin/console',
        'doctrine:migrations:migrate',
        '--no-interaction',
    ], $root);

    $lookup = $connection->prepare('SELECT id, email, username, roles FROM users WHERE email = :email OR username = :username');
    $lookup->execute(['email' => strtolower($adminEmail), 'username' => strtolower($adminUsername)]);
    $existingUsers = $lookup->fetchAll(PDO::FETCH_ASSOC);
    if ($existingUsers !== []) {
        if (count($existingUsers) === 1) {
            $roles = json_decode((string) $existingUsers[0]['roles'], true);
            if (is_array($roles) && in_array('ROLE_ADMIN', $roles, true)) {
                persistAdminEmail($root, (string) $existingUsers[0]['email']);
                fwrite(STDOUT, sprintf(
                    "\nAdmin already exists (id %s, %s); account was left unchanged.\n",
                    $existingUsers[0]['id'],
                    $existingUsers[0]['username'],
                ));
                exit(0);
            }
        }
        throw new RuntimeException('The requested admin email or username is already used by a non-admin or conflicting account. No account was changed.');
    }

    $passwordFile = getenv('NS_INITIAL_PASSWORD_FILE') ?: $root.'/var/keys/admin-initial-password.txt';
    if (!str_starts_with($passwordFile, '/')) {
        $passwordFile = $root.'/'.$passwordFile;
    }
    if (file_exists($passwordFile)) {
        throw new RuntimeException($passwordFile.' already exists; move it aside before creating a new admin password.');
    }

    $initialPassword = bin2hex(random_bytes(24));
    $passwordHandle = fopen($passwordFile, 'x');
    if ($passwordHandle === false) {
        throw new RuntimeException('Cannot securely create the initial admin password file.');
    }
    chmod($passwordFile, 0600);
    try {
        if (fwrite($passwordHandle, $initialPassword.PHP_EOL) === false) {
            throw new RuntimeException('Cannot write the initial admin password file.');
        }
    } finally {
        fclose($passwordHandle);
    }

    try {
        persistAdminEmail($root, strtolower($adminEmail));
        $createAdmin = <<<'PHP'
$configuration = require $argv[1];
chdir($configuration['core_directory']);
$kernel = new \NsUltimate\Integration\Backend\Kernel('dev', false);
$application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
$input = new \Symfony\Component\Console\Input\ArrayInput([
    'command' => 'app:identity:user:create',
    'email' => $argv[2],
    'username' => $argv[3],
    'password' => is_file($argv[4]) && is_readable($argv[4])
        ? trim((string) file_get_contents($argv[4]))
        : '',
    '--admin' => true,
    '--no-interaction' => true,
]);
exit($application->run($input));
PHP;
        if (!is_file($passwordFile) || !is_readable($passwordFile) || trim((string) file_get_contents($passwordFile)) === '') {
            throw new RuntimeException('The initial admin password file was not created correctly.');
        }
        runCommand([
            PHP_BINARY,
            '-r',
            $createAdmin,
            $root.'/integration/backend/bootstrap.php',
            strtolower($adminEmail),
            strtolower($adminUsername),
            $passwordFile,
        ], $root);
    } catch (Throwable $exception) {
        unlink($passwordFile);
        throw $exception;
    }

    fwrite(STDOUT, sprintf(
        "Initial admin password saved to %s (permissions 0600; ignored by Git).\n",
        $passwordFile,
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, 'dev-init failed: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
