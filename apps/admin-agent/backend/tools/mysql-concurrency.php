<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate\CounterMutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Entity\AgentOperation;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use NsUltimate\Apps\AdminAgent\Backend\Service\AgentOperationService;
use NsUltimate\Apps\AdminAgent\Backend\Service\MutationAdapterRegistry;
use NsUltimate\Business\Counter\Entity\Counter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/* Opt-in disposable-schema exercise. Secrets stay in process memory and are never printed. */
$root = dirname(__DIR__, 4);
$tools = __FILE__;
$worker = ($argv[1] ?? '') === '--worker';
require __DIR__.'/mysql-concurrency-guard.php';
if (($argv[1] ?? '') === '--self-test') {
    nsAgentGuardSelfTest();
    fwrite(STDOUT, "MySQL URL and worker-name guard self-tests passed.\n");
    exit(0);
}
$schema = getenv('NS_AGENT_MYSQL_TEST_SCHEMA') ?: '';
$confirm = getenv('NS_AGENT_MYSQL_TEST_CONFIRM') ?: '';
$envName = null;
$created = false;
$cacheOwned = false;
$workersReaped = true;
$success = false;
$interrupted = false;
if (!$worker && function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, static function () use (&$interrupted): void { $interrupted = true; });
    pcntl_signal(SIGTERM, static function () use (&$interrupted): void { $interrupted = true; });
}
if (!$worker && ($schema !== '' || $confirm !== '')) {
    fwrite(STDERR, "Do not preselect a schema; the harness generates and confirms its own unique disposable name.\n");
    exit(2);
}

try {
    // Let the project bootstrap resolve its ordinary local dev config internally.
    $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'dev';
    putenv('APP_ENV=dev');
    require $root.'/integration/backend/bootstrap.php';
    $sourceUrl = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
    if (!is_string($sourceUrl) || $sourceUrl === '') {
        throw new RuntimeException('No database URL was configured.');
    }
    if ($worker) {
        $schema = getenv('NS_AGENT_MYSQL_TEST_SCHEMA') ?: '';
        nsAgentAssertWorkerSchema($schema);
        $parts = nsAgentParseMySqlUrl($sourceUrl, $schema);
        if ($parts['source_schema'] !== $schema || getenv('NS_AGENT_MYSQL_TEST_CONFIRM') !== $schema) throw new RuntimeException('Worker URL schema mismatch.');
    } else {
        $schema = 'ns_agent_mysql_test_'.bin2hex(random_bytes(8));
        $confirm = $schema; // Confirmation is generated here, not accepted from caller input.
        $parts = nsAgentParseMySqlUrl($sourceUrl, $schema);
        $sourceUrl = nsAgentCanonicalMySqlUrl($parts);
        putenv('NS_AGENT_MYSQL_TEST_SCHEMA='.$schema);
        putenv('NS_AGENT_MYSQL_TEST_CONFIRM='.$confirm);
        $_SERVER['NS_AGENT_MYSQL_TEST_SCHEMA'] = $_ENV['NS_AGENT_MYSQL_TEST_SCHEMA'] = $schema;
    }
    $envName = nsAgentResolveRunEnvironment($worker, getenv('NS_AGENT_MYSQL_TEST_APP_ENV') ?: null, $schema);
    $sourceUrl = nsAgentCanonicalMySqlUrl($parts);
    putenv('DATABASE_URL='.$sourceUrl);
    $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = $sourceUrl;
    putenv('NS_AGENT_MYSQL_TEST_APP_ENV='.$envName);
    putenv('APP_ENV='.$envName);
    $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $envName;
    $dsn = 'mysql:host='.$parts['host'].';port='.$parts['port'].';dbname='.$schema.';charset=utf8mb4';
    $serverPdo = new PDO('mysql:host='.$parts['host'].';port='.$parts['port'].';charset=utf8mb4',
        $parts['user'], $parts['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $kernelClass = 'NsUltimate\\Integration\\Backend\\Kernel';
    /** @var KernelInterface $kernel */
    $cachePath = $root.'/var/cache/backend/'.$envName;
    if (!$worker) {
        $cacheOwned = nsAgentAcquireCache($root, $envName);
        if (!$cacheOwned) throw new RuntimeException('Generated harness cache path could not be exclusively acquired.');
    } elseif (!is_dir($cachePath) || is_link($cachePath)) {
        throw new RuntimeException('Worker cache path is not the active run cache.');
    }
    $kernel = new $kernelClass($envName, false);
    $kernel->boot();
    $container = $kernel->getContainer();
    $em = $container->get('doctrine')->getManager();
    $effective = $em->getConnection()->getParams();
    nsAgentAssertEffectiveTarget($effective, $schema, $parts['port']);
    if ($effective['host'] !== $parts['host'] || $effective['dbname'] !== $schema) {
        throw new RuntimeException('Doctrine connection did not resolve to the disposable schema.');
    }
    if (!$worker) {
        $found = $serverPdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ".$serverPdo->quote($schema))->fetchColumn();
        if ($found !== false) throw new RuntimeException('Generated schema unexpectedly already exists.');
        $serverPdo->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $created = true;
    }
    $pdo = new PDO($dsn, $parts['user'], $parts['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($em->getConnection()->fetchOne('SELECT DATABASE()') !== $schema || $pdo->query('SELECT DATABASE()')->fetchColumn() !== $schema) {
        throw new RuntimeException('Active PDO/Doctrine database identity mismatch.');
    }
    // The generated non-dev environment has no file handlers in the composed Monolog config.

    if ($worker) {
        $action = $argv[2] ?? '';
        $operationId = $argv[3] ?? '';
        $ready = $argv[4] ?? '';
        $go = $argv[5] ?? '';
        if (!in_array($action, ['same', 'stale'], true) || $ready === '' || $go === '') {
            throw new RuntimeException('Invalid worker invocation.');
        }
        file_put_contents($ready, 'ready', LOCK_EX);
        $deadline = microtime(true) + 20;
        while (!is_file($go) && microtime(true) < $deadline) { usleep(10000); }
        if (!is_file($go)) throw new RuntimeException('Worker barrier timed out.');
        $auth = new class implements AuthorizationCheckerInterface {
            public function isGranted(mixed $attribute, mixed $subject = null, ?Symfony\Component\Security\Core\Authorization\AccessDecision $accessDecision = null): bool { return true; }
        };
        $operation = $em->getRepository(AgentOperation::class)->find($operationId);
        if (!$operation instanceof AgentOperation) throw new RuntimeException('Worker operation not found.');
        $proposedName = $operation->getChanges()['name'] ?? null;
        $adapter = nsAgentCountingAdapter(new CounterMutationAdapter($em, $auth), $em->getConnection(), $action);
        $service = new AgentOperationService($em, new MutationAdapterRegistry([$adapter]));
        try {
            $result = $service->commit('mysql-harness-actor', $operationId);
            $outcome = ['status' => 'succeeded', 'identifier' => $result['identifier'], 'name' => $proposedName];
        } catch (ProposalConflictException $error) {
            $outcome = ['status' => 'proposal_conflict'];
        } catch (Symfony\Component\HttpKernel\Exception\ConflictHttpException $error) {
            $outcome = ['status' => 'proposal_conflict'];
        }
        file_put_contents($ready.'.result', json_encode($outcome, JSON_THROW_ON_ERROR), LOCK_EX);
        $kernel->shutdown();
        exit(0);
    }

    $identity = $pdo->query('SELECT DATABASE() db, VERSION() version, @@version_comment comment, @@transaction_isolation isolation')->fetch(PDO::FETCH_ASSOC);
    if (($identity['db'] ?? null) !== $schema) throw new RuntimeException('Connected schema identity mismatch.');

    $application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
    $application->setAutoExit(false);
    $code = $application->run(new ArrayInput(['command' => 'doctrine:schema:create', '--env' => 'dev', '--quiet' => true]), new NullOutput());
    if ($code !== 0) throw new RuntimeException('Doctrine schema creation failed.');
    $pdo->exec('CREATE TABLE ns_agent_harness_executions (phase VARCHAR(16) PRIMARY KEY, executions INT NOT NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO ns_agent_harness_executions (phase, executions) VALUES ('same', 0), ('stale', 0)");

    $counter = (new Counter())->setStoreUuid('00000000-0000-4000-8000-000000000001')->setCode('mysql-harness')->setName('Original');
    $em->persist($counter);
    $em->flush();
    $id = (string) $counter->getId();
    $auth = new class implements AuthorizationCheckerInterface {
        public function isGranted(mixed $attribute, mixed $subject = null, ?Symfony\Component\Security\Core\Authorization\AccessDecision $accessDecision = null): bool { return true; }
    };
    $adapter = new CounterMutationAdapter($em, $auth);
    $service = new AgentOperationService($em, new MutationAdapterRegistry([$adapter]));
    $single = $service->prepare('mysql-harness-actor', ['entity' => 'Counter', 'action' => 'update', 'identifier' => $id, 'changes' => ['name' => 'Single']]);
    $runWorkers = static function (string $mode, array $ids) use ($tools, $root, &$workersReaped, &$interrupted): array {
        $tmp = sys_get_temp_dir().'/ns-agent-mysql-'.bin2hex(random_bytes(8));
        if (!mkdir($tmp, 0700)) throw new RuntimeException('Could not create private worker coordination directory.');
        $procs = [];
        try {
            foreach ($ids as $index => $opId) {
                $ready = $tmp.'/'.$index;
                $command = [PHP_BINARY, $tools, '--worker', $mode, $opId, $ready, $tmp.'/go'];
                $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $tmp.'/'.$index.'.out', 'w'], 2 => ['file', $tmp.'/'.$index.'.err', 'w']], $pipes, $root);
                if (!is_resource($proc)) throw new RuntimeException('Could not start PHP worker.');
                fclose($pipes[0]);
                $procs[] = ['proc' => $proc, 'ready' => $ready, 'exit' => null];
            }
            $deadline = microtime(true) + 30;
            do {
                if ($interrupted) throw new RuntimeException('Interrupted while waiting for workers.');
                $allReady = count(array_filter($procs, static fn ($item) => is_file($item['ready']))) === count($procs);
                if ($allReady) break;
                usleep(10000);
            } while (microtime(true) < $deadline);
            if (!$allReady) throw new RuntimeException('Worker readiness barrier timed out.');
            touch($tmp.'/go');
            $deadline = microtime(true) + 90;
            do {
                if ($interrupted) break;
                $running = false;
                foreach ($procs as &$child) {
                    if ($child['exit'] !== null) continue;
                    $status = proc_get_status($child['proc']);
                    if ($status['running']) $running = true;
                    else $child['exit'] = $status['exitcode'];
                }
                unset($child);
                if (!$running) break;
                usleep(10000);
            } while (microtime(true) < $deadline);
            $timedOut = $interrupted || microtime(true) >= $deadline;
            if ($timedOut) {
                foreach ($procs as $child) if ($child['exit'] === null) proc_terminate($child['proc']);
                $grace = microtime(true) + 2;
                do {
                    $running = false;
                    foreach ($procs as &$child) {
                        if ($child['exit'] !== null) continue;
                        $status = proc_get_status($child['proc']);
                        if ($status['running']) $running = true;
                        else $child['exit'] = $status['exitcode'];
                    }
                    unset($child);
                    if (!$running) break;
                    usleep(10000);
                } while (microtime(true) < $grace);
                foreach ($procs as $child) if ($child['exit'] === null) proc_terminate($child['proc'], 9);
                $killDeadline = microtime(true) + 3;
                do {
                    $running = false;
                    foreach ($procs as &$child) {
                        if ($child['exit'] !== null) continue;
                        $status = proc_get_status($child['proc']);
                        if ($status['running']) $running = true;
                        else $child['exit'] = $status['exitcode'];
                    }
                    unset($child);
                    if (!$running) break;
                    usleep(10000);
                } while (microtime(true) < $killDeadline);
                if ($running) $workersReaped = false;
                throw new RuntimeException('Worker group exceeded its bounded runtime or was interrupted.');
            }
            $outcomes = [];
            foreach ($procs as &$child) {
                if ($child['exit'] === null) throw new RuntimeException('Worker did not exit before deadline.');
                $closeCode = proc_close($child['proc']);
                if (($child['exit'] !== 0 && $child['exit'] !== -1) || ($closeCode !== 0 && $closeCode !== -1)
                    || !is_file($child['ready'].'.result')) throw new RuntimeException('A worker failed; diagnostics suppressed.');
                $outcomes[] = json_decode((string) file_get_contents($child['ready'].'.result'), true, flags: JSON_THROW_ON_ERROR);
                $child['proc'] = null;
            }
            unset($child);
            return $outcomes;
        } finally {
            foreach ($procs as &$child) {
                if (!is_resource($child['proc'] ?? null)) continue;
                $status = proc_get_status($child['proc']);
                if ($status['running']) {
                    proc_terminate($child['proc']);
                    usleep(100000);
                    $status = proc_get_status($child['proc']);
                    if ($status['running']) proc_terminate($child['proc'], 9);
                    $deadline = microtime(true) + 2;
                    do {
                        $status = proc_get_status($child['proc']);
                        if (!$status['running']) break;
                        usleep(10000);
                    } while (microtime(true) < $deadline);
                }
                if (!$status['running']) proc_close($child['proc']);
                else $workersReaped = false;
                $child['proc'] = null;
            }
            unset($child);
            foreach (['go', '0', '1', '2', '3'] as $name) {
                foreach ([$tmp.'/'.$name, $tmp.'/'.$name.'.result', $tmp.'/'.$name.'.out', $tmp.'/'.$name.'.err'] as $file) {
                    if (is_file($file) && !is_link($file)) unlink($file);
                }
            }
            rmdir($tmp);
        }
    };

    $same = $runWorkers('same', array_fill(0, 4, $single['operationId']));
    $em->clear();
    $sameName = $em->find(Counter::class, (int) $id)->getName();
    $sameStatuses = array_count_values(array_column($same, 'status'));
    if (($sameStatuses['succeeded'] ?? 0) !== 4 || $sameName !== 'Single') throw new RuntimeException('Same-proposal idempotency assertion failed.');
    $sameExecutionCount = (int) $pdo->query("SELECT executions FROM ns_agent_harness_executions WHERE phase = 'same'")->fetchColumn();
    if ($sameExecutionCount !== 1 || count(array_unique(array_column($same, 'identifier'))) !== 1
        || $same[0]['identifier'] !== $id) throw new RuntimeException('Repeated proposal executed more than once or returned inconsistent targets.');
    $sameReceipt = $em->find(AgentOperation::class, $single['operationId']);
    if (!$sameReceipt instanceof AgentOperation || $sameReceipt->getStatus() !== 'succeeded' || $sameReceipt->getTargetId() !== $id) {
        throw new RuntimeException('Same-proposal succeeded receipt target assertion failed.');
    }

    $em->find(Counter::class, (int) $id)->setName('Snapshot');
    $em->flush();
    $proposalIds = [];
    foreach (['Race A', 'Race B', 'Race C', 'Race D'] as $name) {
        $draft = $service->prepare('mysql-harness-actor', ['entity' => 'Counter', 'action' => 'update', 'identifier' => $id, 'changes' => ['name' => $name]]);
        $proposalIds[] = $draft['operationId'];
    }
    $stale = $runWorkers('stale', $proposalIds);
    $staleStatuses = array_count_values(array_column($stale, 'status'));
    $em->clear();
    $finalName = $em->find(Counter::class, (int) $id)->getName();
    $staleExecutionCount = (int) $pdo->query("SELECT executions FROM ns_agent_harness_executions WHERE phase = 'stale'")->fetchColumn();
    $winners = array_values(array_filter($stale, static fn (array $result): bool => $result['status'] === 'succeeded'));
    if (($staleStatuses['succeeded'] ?? 0) !== 1 || ($staleStatuses['proposal_conflict'] ?? 0) !== 3
        || $staleExecutionCount !== 1 || count($winners) !== 1 || $finalName !== $winners[0]['name']) {
        throw new RuntimeException('Stale-proposal race assertion failed.');
    }
    fwrite(STDOUT, "MySQL Counter concurrency checks passed.\nSchema: {$schema}\nServer: ".(string) $identity['version'].' ('.(string) $identity['comment'].')'."\nIsolation: ".(string) $identity['isolation']."\nWorkers: 4 per race\nSame proposal: 4 succeeded; adapter execute count 1; one succeeded receipt and one target.\nIndependent snapshots: 1 succeeded, 3 proposal_conflict; adapter execute count 1; winner matched final name.\n");
    $success = true;
    $kernel->shutdown();
} catch (Throwable $error) {
    // Never echo exception text: it may contain connection details.
    $detail = $error instanceof Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException ? ' service='.$error->getId() : '';
    fwrite(STDERR, 'MySQL concurrency harness stopped safely ('.get_class($error).')'.$detail."\n");
    $exit = 1;
} finally {
    if (!$worker) {
        if (isset($kernel)) $kernel->shutdown();
        if (!$workersReaped) {
            $success = false;
            $exit = 1;
            if (isset($schema) && preg_match('/^ns_agent_mysql_test_[a-f0-9]{16}$/D', $schema)) {
                fwrite(STDERR, "WARNING: worker reaping failed; schema not dropped. Disposable schema requiring manual cleanup: {$schema}\n");
            }
        } elseif (isset($created) && $created && isset($serverPdo) && isset($schema)
            && preg_match('/^ns_agent_mysql_test_[a-f0-9]{16}$/D', $schema)
            && ($confirm ?? null) === $schema) {
            try {
                $stillExists = $serverPdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ".$serverPdo->quote($schema))->fetchColumn();
                if ($stillExists !== false) $serverPdo->exec('DROP DATABASE `'.$schema.'`');
                $remaining = $serverPdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ".$serverPdo->quote($schema))->fetchColumn();
                if ($remaining !== false) throw new RuntimeException('Disposable schema still exists after cleanup.');
                fwrite(STDOUT, "Disposable schema cleanup verified.\n");
            } catch (Throwable) {
                $success = false;
                $exit = 1;
                fwrite(STDERR, "WARNING: cleanup failed. Disposable schema requiring manual cleanup: {$schema}\n");
            }
        }
        if (is_string($envName)) {
            try {
                if (!nsAgentRemoveOwnedCache($root, $envName, $cacheOwned)) throw new RuntimeException('Owned cache cleanup failed.');
            }
            catch (Throwable) { $success = false; $exit = 1; fwrite(STDERR, "WARNING: harness cache cleanup failed.\n"); }
        }
    }
}
if (!$worker && !$success) exit(1);
if (isset($exit)) exit($exit);
