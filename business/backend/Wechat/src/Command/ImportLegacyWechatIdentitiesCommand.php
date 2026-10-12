<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Command;

use App\Wechat\Entity\WechatUser;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Wechat\Entity\WechatApplication;
use NsUltimate\Business\Wechat\Service\WechatIdentityService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Imports legacy core WechatUser rows into application-scoped identities.
 *
 * The legacy schema has no AppID, so the operator must supply the verified AppID
 * and application type. The command never guesses an AppID and never merges
 * system users.
 */
#[AsCommand(name: 'app:wechat:import-legacy-identities', description: 'Dry-run or import legacy WeChat identities into app-scoped identities')]
final class ImportLegacyWechatIdentitiesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WechatIdentityService $identityService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('app-id', null, InputOption::VALUE_REQUIRED, 'Verified AppID for the legacy rows')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Application type: miniapp or official')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report without writing changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $appId = trim((string) $input->getOption('app-id'));
        $type = trim((string) $input->getOption('type'));
        $dryRun = (bool) $input->getOption('dry-run');

        if ($appId === '' || !in_array($type, WechatApplication::TYPES, true)) {
            $output->writeln('<error>--app-id and --type (miniapp|official) are required.</error>');
            return Command::FAILURE;
        }

        /** @var list<WechatUser> $legacyUsers */
        $legacyUsers = $this->entityManager->getRepository(WechatUser::class)->findBy(['appType' => $type]);

        $imported = 0;
        $skipped = 0;
        $conflicts = [];

        foreach ($legacyUsers as $legacy) {
            $openid = $legacy->getOpenid();
            if ($this->identityService->find($appId, $openid) !== null) {
                $skipped++;
                continue;
            }

            $user = $legacy->getUser();
            if ($dryRun) {
                $imported++;
                continue;
            }

            try {
                $this->identityService->link(
                    $user,
                    $appId,
                    $type,
                    $openid,
                    $legacy->getUnionid(),
                );
                $imported++;
            } catch (\InvalidArgumentException $e) {
                $conflicts[] = $this->redact($openid).': '.$e->getMessage();
            }
        }

        $mode = $dryRun ? 'dry-run' : 'apply';
        $output->writeln(sprintf(
            '<info>%s</info>: scanned %d, %s %d, skipped %d, conflicts %d',
            $mode,
            count($legacyUsers),
            $dryRun ? 'would import' : 'imported',
            $imported,
            $skipped,
            count($conflicts),
        ));
        foreach ($conflicts as $conflict) {
            $output->writeln('  <error>'.$conflict.'</error>');
        }

        return $conflicts === [] ? Command::SUCCESS : Command::FAILURE;
    }

    private function redact(string $openid): string
    {
        return 'openid:'.substr(hash('sha256', $openid), 0, 12);
    }
}
