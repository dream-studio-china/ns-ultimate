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
    $contents = is_file($path) ? file_get_contents($path) : '';
    if (!is_string($contents)) {
        throw new RuntimeException('Cannot read integration/backend/.env.local.');
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

try {
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('The pdo_mysql PHP extension is required.');
    }

    fwrite(STDOUT, "Local development database and administrator setup\n\n");
    $host = prompt('MySQL host', '127.0.0.1');
    $port = prompt('MySQL port', '3306');
    $user = prompt('MySQL username', 'root');
    $password = promptSecret('MySQL password');
    $database = prompt('Development database name', 'ns_ultimate');
    $adminEmail = prompt('Initial admin email', 'admin@example.com');
    $adminUsername = prompt('Initial admin username', 'admin');

    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('MySQL port must be between 1 and 65535.');
    }
    if (preg_match('/^[A-Za-z0-9_.:-]+$/', $host) !== 1) {
        throw new RuntimeException('MySQL host contains unsupported characters.');
    }
    if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
        throw new RuntimeException('Database name may contain only letters, digits, and underscores.');
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('A valid admin email address is required.');
    }
    if (preg_match('/^[A-Za-z0-9_.-]{1,180}$/', $adminUsername) !== 1) {
        throw new RuntimeException('Admin username may contain letters, digits, dots, underscores, and hyphens.');
    }

    $serverDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, (int) $port);
    $server = new PDO($serverDsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $serverVersion = (string) $server->query('SELECT VERSION()')->fetchColumn();
    $versionMatch = [];
    if (preg_match('/^(\d+\.\d+\.\d+)/', $serverVersion, $versionMatch) !== 1) {
        throw new RuntimeException('Could not determine the MySQL server version.');
    }
    $serverVersion = str_contains(strtolower($serverVersion), 'mariadb')
        ? 'mariadb-'.$versionMatch[1]
        : $versionMatch[1];

    fwrite(STDOUT, sprintf(
        "\nTarget: %s:%d / %s (MySQL-compatible %s)\n",
        $host,
        (int) $port,
        $database,
        $serverVersion,
    ));
    fwrite(STDOUT, "This will create the database if missing, apply pending migrations, and create the admin if absent.\n");
    fwrite(STDOUT, 'Continue? [y/N]: ');
    $confirmation = strtolower(trim((string) fgets(STDIN)));
    if (!in_array($confirmation, ['y', 'yes'], true)) {
        fwrite(STDOUT, "Cancelled; no database changes were made.\n");
        exit(0);
    }

    $server->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        $database,
    ));
    $databaseDsn = sprintf('%s;dbname=%s', $serverDsn, $database);
    $connection = new PDO($databaseDsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    putenv('APP_ENV=dev');
    putenv('DATABASE_URL');
    unset($_SERVER['APP_ENV'], $_SERVER['DATABASE_URL'], $_ENV['APP_ENV'], $_ENV['DATABASE_URL']);

    runCommand([PHP_BINARY, $root.'/scripts/env.php', 'env-init'], $root);
    writeDatabaseUrl($root.'/integration/backend/.env.local', [
        'host' => $host,
        'port' => $port,
        'user' => $user,
        'password' => $password,
        'database' => $database,
        'server_version' => $serverVersion,
    ]);

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

    $passwordFile = $root.'/var/keys/admin-initial-password.txt';
    if (file_exists($passwordFile)) {
        throw new RuntimeException('var/keys/admin-initial-password.txt already exists; move it aside before creating a new admin password.');
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
        $createAdmin = <<<'PHP'
$configuration = require $argv[1];
chdir($configuration['core_directory']);
$kernel = new \NsUltimate\Integration\Backend\Kernel('dev', false);
$application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
$input = new \Symfony\Component\Console\Input\ArrayInput([
    'command' => 'app:identity:user:create',
    'email' => $argv[2],
    'username' => $argv[3],
    'password' => trim((string) file_get_contents($argv[4])),
    '--admin' => true,
    '--no-interaction' => true,
]);
exit($application->run($input));
PHP;
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

    fwrite(STDOUT, "Initial admin password saved to var/keys/admin-initial-password.txt (permissions 0600; ignored by Git). Change it after first login.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'dev-init failed: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
