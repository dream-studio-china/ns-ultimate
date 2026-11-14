<?php

declare(strict_types=1);

namespace NsUltimate\Business\Promotion\Tests\Integration;

use App\Identity\Entity\Profile;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Promotion\Entity\Promotion;
use App\Promotion\Entity\PromotionTemplate;
use App\Promotion\Service\PromotionTemplateServiceInterface;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderItem;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PromotionScenarioApiTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $token;

    protected static function getKernelClass(): string { return Kernel::class; }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($this->em);
        $tool->dropSchema($metadata); $tool->createSchema($metadata);
        $admin = (new User())->setUsername('scenario-admin')->setEmail('scenario-admin@example.test')->setRoles(['ROLE_ADMIN']);
        $this->em->persist($admin); $this->em->flush();
        $this->token = self::getContainer()->get(TokenManager::class)->createAccessToken($admin);
    }

    public function testCurrentSnapshotAndScenarioWorldsProduceAResultMatrixWithoutWrites(): void
    {
        $this->campaign('Store rate', 'discount', $this->discountDsl(90, 10), ['threshold' => 0, 'rate' => 90], 'STACK-TEST');
        $this->campaign('Other store', 'discount', $this->discountDsl(80, 10), ['threshold' => 0, 'rate' => 80], 'OTHER');
        $this->campaign('Global rate', 'discount', $this->discountDsl(95, 10), ['threshold' => 0, 'rate' => 10], '');
        $snapshot = $this->getJson('/api/v1/manage/promotion-simulation/current?storeCode=STACK-TEST');
        self::assertCount(2, $snapshot['campaigns']);
        self::assertSame('STACK-TEST', $snapshot['campaigns'][0]['storeCode']);
        self::assertArrayHasKey('template', $snapshot['campaigns'][0]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $snapshot['revision']);
        self::assertFalse($snapshot['truncated']);

        $counts = $this->counts();
        $input = [
            'worlds' => [
                ['key' => 'baseline', 'name' => 'Baseline', 'campaigns' => [
                    $this->worldCampaign('a-rate', 'discount', $this->discountDsl(90, 10), ['threshold' => 0, 'rate' => 90]),
                    $this->worldCampaign('a-reduction', 'full_reduction', $this->reductionDsl(5, 5), ['threshold' => 0, 'amount' => 5]),
                ]],
                ['key' => 'experiment', 'name' => 'Experiment', 'campaigns' => [
                    $this->worldCampaign('b-rate', 'discount', $this->discountDsl(80, 10), ['threshold' => 0, 'rate' => 80]),
                    $this->worldCampaign('b-reduction', 'full_reduction', $this->reductionDsl(5, 5), ['threshold' => 0, 'amount' => 5]),
                    $this->worldCampaign('b-disabled', 'discount', $this->discountDsl(50, 1), ['threshold' => 0, 'rate' => 50], false),
                ]],
            ],
            'cases' => [[
                'key' => 'cart', 'name' => '100.00 member', 'storeCode' => 'STACK-TEST',
                'simulatedAt' => '2026-11-12T10:00:00+08:00', 'currency' => 'CNY', 'pricingMode' => 'snapshot',
                'items' => [['specificationId' => 1, 'quantity' => 1, 'unitPrice' => 10000]],
                'user' => ['mode' => 'level', 'level' => 'gold'],
                'expectedByWorld' => [
                    'baseline' => ['totalAmount' => 8500, 'appliedCampaignKeys' => ['a-rate', 'a-reduction'], 'freeShipping' => false, 'giftQuantity' => 0, 'items' => [['specificationId' => 1, 'quantity' => 1, 'price' => 10000]]],
                    'experiment' => ['totalAmount' => 7500, 'appliedCampaignKeys' => ['b-rate', 'b-reduction']],
                ],
            ]],
        ];
        $data = $this->postJson('/api/v1/manage/promotion-simulation/run', $input);
        self::assertSame(2, $data['summary']['total']);
        self::assertSame(2, $data['summary']['passed']);
        self::assertSame(0, $data['summary']['failed']);
        self::assertSame($counts, $this->counts(), 'Scenario simulation must not write promotions, templates or orders.');

        $byWorld = [];
        foreach ($data['results'] as $result) $byWorld[$result['worldKey']] = $result;
        self::assertSame(8500, $byWorld['baseline']['afterAmount']);
        self::assertSame(7500, $byWorld['experiment']['afterAmount']);
        self::assertSame(['a-rate', 'a-reduction'], array_column($byWorld['baseline']['applied'], 'campaignKey'));
        $disabled = array_values(array_filter($byWorld['experiment']['diagnostics'], static fn (array $row): bool => $row['campaignKey'] === 'b-disabled'))[0];
        self::assertSame('disabled', $disabled['status']);
        foreach (array_slice($byWorld['baseline']['diagnostics'], 0, 2) as $diagnostic) self::assertSame('applied', $diagnostic['status']);
    }

    public function testOrderAndUserImportsAreBoundedAndDoNotExposeProfileContactData(): void
    {
        $user = (new User())->setUsername('scenario-customer')->setEmail('scenario-customer@example.test');
        $user->setProfile(new Profile($user, 'gold'));
        $order = (new Order())->setUser($user)->setTotalAmount(9000)->setCurrency('CNY')
            ->setMetadata(['_store' => ['code' => 'STACK-TEST']]);
        $item = (new OrderItem())->setOrder($order)->setSpecificationUuid('00000000-0000-4000-8000-000000000001')
            ->setSpecificationTitle('Sample item')->setQuantity(2)->setUnitPrice(5000)->setPrice(10000)
            ->setSpecSnapshot(['id' => 7, 'name' => 'Sample item']);
        $order->addItem($item);
        $this->em->persist($user); $this->em->persist($order); $this->em->flush();

        $snapshot = $this->getJson('/api/v1/manage/promotion-simulation/orders/'.$order->getId());
        self::assertSame('STACK-TEST', $snapshot['storeCode']);
        self::assertSame('gold', $snapshot['userLevel']);
        self::assertSame(7, $snapshot['items'][0]['specificationId']);
        self::assertSame(5000, $snapshot['items'][0]['unitPrice']);
        self::assertArrayNotHasKey('email', $snapshot);

        $profile = $this->getJson('/api/v1/manage/promotion-simulation/users/'.$user->getId());
        self::assertSame('gold', $profile['level']);
        self::assertSame($user->getId(), $profile['id']);
        self::assertArrayNotHasKey('email', $profile);
        self::assertArrayNotHasKey('username', $profile);
    }

    public function testAppliedCampaignIsNotReclassifiedAfterItsDiscountChangesTheThreshold(): void
    {
        $campaign = $this->worldCampaign('threshold-rate', 'discount', $this->discountDsl(90, 10), ['threshold' => 10000, 'rate' => 90]);
        $data = $this->postJson('/api/v1/manage/promotion-simulation/run', [
            'worlds' => [['key' => 'world', 'name' => 'World', 'campaigns' => [$campaign]]],
            'cases' => [$this->scenarioCase('cart')],
        ]);
        $result = $data['results'][0];
        self::assertSame(9000, $result['afterAmount']);
        self::assertSame(['threshold-rate'], array_column($result['applied'], 'campaignKey'));
        self::assertSame('applied', $result['diagnostics'][0]['status']);
    }

    public function testScheduleAndStoreDiagnosticsAgreeWithTheActualAppliedTrace(): void
    {
        $campaign = $this->worldCampaign('scheduled-rate', 'discount', $this->discountDsl(90, 10), ['threshold' => 0, 'rate' => 90]);
        $campaign['startTime'] = '2026-11-12T09:00:00+08:00';
        $campaign['endTime'] = '2026-11-12T11:00:00+08:00';
        $data = $this->postJson('/api/v1/manage/promotion-simulation/run', [
            'worlds' => [['key' => 'world', 'name' => 'World', 'campaigns' => [$campaign]]],
            'cases' => [
                $this->scenarioCase('global', ['storeCode' => '']),
                $this->scenarioCase('before', ['simulatedAt' => '2026-11-12T00:59:59Z']),
                $this->scenarioCase('start', ['simulatedAt' => '2026-11-12T01:00:00Z']),
                $this->scenarioCase('during'),
                $this->scenarioCase('end', ['simulatedAt' => '2026-11-12T03:00:00Z']),
                $this->scenarioCase('after', ['simulatedAt' => '2026-11-12T03:00:01Z']),
            ],
        ]);
        self::assertSame(['store_mismatch', 'not_started', 'applied', 'applied', 'applied', 'ended'], array_map(static fn (array $result): string => $result['diagnostics'][0]['status'], $data['results']));
        self::assertSame([10000, 10000, 9000, 9000, 9000, 10000], array_column($data['results'], 'afterAmount'));
    }

    public function testInvalidSnapshotsAreReportedAsInvalidRatherThanEligible(): void
    {
        $campaign = $this->worldCampaign('invalid', 'unsupported', 'type: unsupported', []);
        $data = $this->postJson('/api/v1/manage/promotion-simulation/run', [
            'worlds' => [['key' => 'world', 'name' => 'World', 'campaigns' => [$campaign]]],
            'cases' => [$this->scenarioCase('cart')],
        ]);
        self::assertCount(1, $data['worlds'][0]['errors']);
        self::assertSame('invalid', $data['results'][0]['diagnostics'][0]['status']);
        self::assertSame([], $data['results'][0]['applied']);
    }

    private function scenarioCase(string $key, array $overrides = []): array
    {
        return array_replace([
            'key' => $key, 'name' => $key, 'storeCode' => 'STACK-TEST',
            'simulatedAt' => '2026-11-12T10:00:00+08:00', 'currency' => 'CNY', 'pricingMode' => 'snapshot',
            'items' => [['specificationId' => 1, 'quantity' => 1, 'unitPrice' => 10000]],
        ], $overrides);
    }

    public function testCurrentCatalogErrorsAreReportedPerResultAndInvalidRequestsFailClosed(): void
    {
        $world = ['key' => 'world', 'name' => 'World', 'campaigns' => [$this->worldCampaign('rate', 'discount', $this->discountDsl(90, 10), ['threshold' => 0, 'rate' => 90])]];
        $input = ['worlds' => [$world], 'cases' => [[
            'key' => 'missing', 'name' => 'Missing spec', 'storeCode' => '', 'simulatedAt' => '2026-11-12T10:00:00Z',
            'currency' => 'CNY', 'pricingMode' => 'current_catalog',
            'items' => [['specificationId' => 999999, 'quantity' => 1]],
        ]]];
        $data = $this->postJson('/api/v1/manage/promotion-simulation/run', $input);
        self::assertSame('error', $data['results'][0]['status']);
        self::assertStringContainsString('Catalog price resolution failed', $data['results'][0]['error']);
        self::assertSame(1, $data['summary']['errors']);

        self::assertSame(400, $this->request('POST', '/api/v1/manage/promotion-simulation/run', ['worlds' => [], 'cases' => []])->getStatusCode());
        self::assertSame(400, $this->request('POST', '/api/v1/manage/promotion-simulation/run', $input + ['url' => '/evil'])->getStatusCode());
        self::assertSame(404, $this->request('GET', '/api/v1/manage/promotion-simulation/orders/999999')->getStatusCode());
        self::assertSame(404, $this->request('GET', '/api/v1/manage/promotion-simulation/users/999999')->getStatusCode());
    }

    public function testNonAdminAndUnauthenticatedRequestsAreRejected(): void
    {
        $input = ['worlds' => [['key' => 'w', 'name' => 'W', 'campaigns' => []]], 'cases' => [[
            'key' => 'c', 'name' => 'C', 'storeCode' => '', 'simulatedAt' => '2026-11-12T10:00:00Z',
            'currency' => 'CNY', 'pricingMode' => 'snapshot',
            'items' => [['specificationId' => 1, 'quantity' => 1, 'unitPrice' => 100]],
        ]]];
        $this->token = '';
        self::assertSame(401, $this->request('POST', '/api/v1/manage/promotion-simulation/run', $input)->getStatusCode());
        $user = (new User())->setUsername('scenario-user')->setEmail('scenario-user@example.test');
        $this->em->persist($user); $this->em->flush();
        $this->token = self::getContainer()->get(TokenManager::class)->createAccessToken($user);
        self::assertSame(403, $this->request('POST', '/api/v1/manage/promotion-simulation/run', $input)->getStatusCode());
    }

    private function campaign(string $name, string $type, string $dsl, array $config, string $storeCode): Promotion
    {
        $service = self::getContainer()->get(PromotionTemplateServiceInterface::class);
        $template = $service->update($service->new(), ['name' => $name.' template', 'type' => $type, 'phase' => 0, 'enabled' => true, 'dsl' => $dsl]);
        self::assertInstanceOf(PromotionTemplate::class, $template);
        $promotion = (new Promotion())->setName($name)->setTemplate($template)->setStoreCode($storeCode)
            ->setEnabled(true)->setConfig($config)->setConflictMode('stackable');
        $this->em->persist($promotion); $this->em->flush();
        return $promotion;
    }

    /** @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function worldCampaign(string $key, string $type, string $dsl, array $config, bool $enabled = true): array
    {
        return ['key' => $key, 'name' => $key, 'enabled' => $enabled, 'templateEnabled' => true,
            'storeCode' => 'STACK-TEST', 'conflictMode' => 'stackable', 'config' => $config,
            'template' => ['type' => $type, 'phase' => 0, 'dsl' => $dsl]];
    }

    private function discountDsl(float $rate, int $priority): string
    {
        return "type: discount\nwhen:\n  cart.subtotal >= config.threshold\ndo:\n  discount order config.rate%\npriority: $priority\n";
    }

    private function reductionDsl(float $amount, int $priority): string
    {
        return "type: full_reduction\nwhen:\n  cart.subtotal >= config.threshold\ndo:\n  discount order config.amount\npriority: $priority\n";
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function counts(): array
    {
        return [
            (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM promotion'),
            (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM promotion_template'),
            (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM trade_order'),
        ];
    }

    /** @return array<string, mixed> */
    private function getJson(string $path): array
    {
        $response = $this->request('GET', $path);
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame(0, $payload['code']);
        self::assertIsArray($payload['data']);
        return $payload['data'];
    }

    /** @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function postJson(string $path, array $body): array
    {
        $response = $this->request('POST', $path, $body);
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame(0, $payload['code']);
        self::assertIsArray($payload['data']);
        return $payload['data'];
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $path, ?array $body = null): Response
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token];
        if ($body !== null) $server['CONTENT_TYPE'] = 'application/json';
        $request = Request::create($path, $method, server: $server, content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
        return $response;
    }
}
