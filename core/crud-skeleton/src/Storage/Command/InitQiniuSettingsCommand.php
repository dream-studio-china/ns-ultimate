<?php

declare(strict_types=1);

namespace App\Storage\Command;

use App\Common\Entity\Setting;
use App\Common\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:storage:qiniu:settings:init',
    description: 'Create missing Qiniu storage settings in common_setting.'
)]
final class InitQiniuSettingsCommand extends Command
{
    private const GROUP = 'storage';

    /** @var array<string, array{option:string, label:string, description:string, sort:int}> */
    private const SETTINGS = [
        'qiniu.access_key' => [
            'option' => 'access-key',
            'label' => 'Qiniu Access Key',
            'description' => 'Qiniu Kodo access key used by the qiniu media storage driver.',
            'sort' => 10,
        ],
        'qiniu.secret_key' => [
            'option' => 'secret-key',
            'label' => 'Qiniu Secret Key',
            'description' => 'Qiniu Kodo secret key used by the qiniu media storage driver.',
            'sort' => 20,
        ],
        'qiniu.bucket' => [
            'option' => 'bucket',
            'label' => 'Qiniu Bucket',
            'description' => 'Qiniu Kodo bucket name for uploaded media files.',
            'sort' => 30,
        ],
        'qiniu.domain' => [
            'option' => 'domain',
            'label' => 'Qiniu Domain',
            'description' => 'Public Qiniu bucket domain, for example https://cdn.example.com.',
            'sort' => 40,
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SettingRepository $settingRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('access-key', null, InputOption::VALUE_REQUIRED, 'Initial value for qiniu.access_key')
            ->addOption('secret-key', null, InputOption::VALUE_REQUIRED, 'Initial value for qiniu.secret_key')
            ->addOption('bucket', null, InputOption::VALUE_REQUIRED, 'Initial value for qiniu.bucket')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Initial value for qiniu.domain')
            ->setHelp(<<<'HELP'
Create missing Qiniu media storage settings in common_setting.

Existing settings are not overwritten.

Examples:
  php bin/console app:storage:qiniu:settings:init
  php bin/console app:storage:qiniu:settings:init --access-key=xxx --secret-key=yyy --bucket=my-bucket --domain=https://cdn.example.com
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $created = [];
        $skipped = [];

        foreach (self::SETTINGS as $key => $definition) {
            if ($this->settingRepository->findByKey($key) instanceof Setting) {
                $skipped[] = $key;
                continue;
            }

            $optionValue = $input->getOption($definition['option']);
            $value = is_string($optionValue) ? trim($optionValue) : null;
            $value = $value === '' ? null : $value;

            $setting = (new Setting($key))
                ->setValue($value)
                ->setType('string')
                ->setGroupName(self::GROUP)
                ->setLabel($definition['label'])
                ->setDescription($definition['description'])
                ->setSortOrder($definition['sort']);

            $this->entityManager->persist($setting);
            $created[] = $key;
        }

        if ($created !== []) {
            $this->entityManager->flush();
        }

        if ($created === [] && $skipped !== []) {
            $io->success('All Qiniu storage settings already exist.');
        } else {
            $io->success(sprintf('Created %d Qiniu storage setting(s).', count($created)));
        }

        $io->table(
            ['created', 'skipped'],
            [[implode(', ', $created) ?: '-', implode(', ', $skipped) ?: '-']]
        );

        return Command::SUCCESS;
    }
}
