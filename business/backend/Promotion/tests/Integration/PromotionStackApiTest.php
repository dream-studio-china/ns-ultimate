<?php

declare(strict_types=1);

namespace NsUltimate\Business\Promotion\Tests\Integration;

use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Promotion\Entity\Promotion;
use App\Promotion\Entity\PromotionTemplate;
use App\Promotion\Service\PromotionTemplateServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PromotionStackApiTest extends KernelTestCase
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
        $user = (new User())->setUsername('stack-admin')->setEmail('stack-admin@example.test')->setRoles(['ROLE_ADMIN']);
        $this->em->persist($user); $this->em->flush();
        $this->token = self::getContainer()->get(TokenManager::class)->createAccessToken($user);
    }

    public function testSelectedCampaignsRunThroughTheRealCalculatorWithATrace(): void
    {
        $rate = $this->campaign($this->template('Ten percent', $this->discountDsl(90, 10), 'discount'), ['threshold' => 0, 'rate' => 90]);
        $reduction = $this->campaign($this->template('Five off', $this->reductionDsl(5, 5), 'full_reduction'), ['threshold' => 0, 'amount' => 5]);

        $response = $this->request($this->body(['campaignIds' => [$rate->getId(), $reduction->getId()], 'cases' => [[
            'items' => $this->items(), 'expected' => ['totalAmount' => 8500, 'appliedCampaignIds' => [$rate->getId(), $reduction->getId()]],
        ]]]));
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $data = $this->data($response);
        self::assertTrue($data['verified'], json_encode($data));
        $case = $data['cases'][0];
        self::assertSame(10000, $case['beforeAmount']);
        self::assertSame(8500, $case['afterAmount']);
        self::assertSame(1500, $case['discountAmount']);
        self::assertSame(8500, $case['expected']['totalAmount']);
        self::assertCount(2, $case['applied']);
        self::assertSame([$rate->getId(), $reduction->getId()], array_column($case['applied'], 'promotionId'));
        self::assertSame([9000, 8500], array_column($case['applied'], 'totalAfter'));
        self::assertSame(['inner', 'inner'], array_column($case['applied'], 'phase'));
        self::assertSame([], $case['notAppliedCampaignIds']);
    }

    public function testEnvironmentModeAndSelectedModeRespectStoreAndEligibility(): void
    {
        $rate = $this->campaign($this->template('Store rate', $this->discountDsl(90, 10), 'discount'), ['threshold' => 0, 'rate' => 90]);
        $other = $this->campaign($this->template('Other store', $this->discountDsl(80, 10), 'discount'), ['threshold' => 0, 'rate' => 80], 'stackable', 'OTHER');
        $disabled = $this->campaign($this->template('Disabled', $this->reductionDsl(5, 5), 'full_reduction'), ['threshold' => 0, 'amount' => 5], 'stackable', 'STACK-TEST', false);

        $environment = $this->data($this->request(['mode' => 'environment', 'storeCode' => 'STACK-TEST', 'cases' => $this->case(9000)]));
        self::assertTrue($environment['verified'], json_encode($environment));
        self::assertSame(9000, $environment['cases'][0]['afterAmount']);
        self::assertSame([$rate->getId()], array_column($environment['cases'][0]['applied'], 'promotionId'));

        $selected = $this->data($this->request($this->body(['campaignIds' => [$rate->getId(), $other->getId(), $disabled->getId()]])));
        self::assertSame(9000, $selected['cases'][0]['afterAmount']);
        self::assertSame([$other->getId(), $disabled->getId()], $selected['cases'][0]['notAppliedCampaignIds']);
    }

    public function testConflictModesStopLaterCampaigns(): void
    {
        foreach (['exclusive', 'lock_item'] as $mode) {
            $first = $this->campaign($this->template('First '.$mode, $this->discountDsl(90, 20), 'discount'), ['threshold' => 0, 'rate' => 90], $mode);
            $second = $this->campaign($this->template('Second '.$mode, $this->reductionDsl(5, 10), 'full_reduction'), ['threshold' => 0, 'amount' => 5]);
            $data = $this->data($this->request($this->body(['campaignIds' => [$first->getId(), $second->getId()], 'cases' => $this->case(9000)])));
            self::assertSame(9000, $data['cases'][0]['afterAmount'], $mode);
            self::assertSame([$first->getId()], array_column($data['cases'][0]['applied'], 'promotionId'), $mode);
            self::assertTrue($data['verified'], $mode.json_encode($data));
        }
    }

    public function testOuterPhaseRunsAfterInnerAndBestPriceChoosesTheLowestTotal(): void
    {
        $inner = $this->campaign($this->template('Inner rate', $this->discountDsl(90, 20), 'discount'), ['threshold' => 0, 'rate' => 90]);
        $outer = $this->campaign($this->template('Outer reduction', $this->reductionDsl(5, 10, 1), 'full_reduction'), ['threshold' => 0, 'amount' => 5]);
        $data = $this->data($this->request($this->body(['campaignIds' => [$inner->getId(), $outer->getId()]])));
        self::assertSame(8500, $data['cases'][0]['afterAmount']);
        self::assertSame(['inner', 'outer'], array_column($data['cases'][0]['applied'], 'phase'));

        $ten = $this->campaign($this->template('Best ten', $this->discountDsl(90, 20), 'discount'), ['threshold' => 0, 'rate' => 90], 'best_price');
        $twenty = $this->campaign($this->template('Best twenty', $this->reductionDsl(20, 10), 'full_reduction'), ['threshold' => 0, 'amount' => 20], 'best_price');
        $data = $this->data($this->request($this->body(['campaignIds' => [$ten->getId(), $twenty->getId()], 'cases' => $this->case(8000)])));
        self::assertSame(8000, $data['cases'][0]['afterAmount']);
        self::assertSame($twenty->getId(), $data['cases'][0]['bestPrice']['promotionId']);
        self::assertCount(2, $data['cases'][0]['bestPrice']['candidates']);
        self::assertCount(1, $data['cases'][0]['applied']);
    }

    public function testDraftsMatchEquivalentPersistedCampaignsWithoutWriting(): void
    {
        $rate = $this->campaign($this->template('Parity rate', $this->discountDsl(90, 10), 'discount'), ['threshold' => 0, 'rate' => 90]);
        $reduction = $this->campaign($this->template('Parity reduction', $this->reductionDsl(5, 5), 'full_reduction'), ['threshold' => 0, 'amount' => 5]);
        $selected = $this->data($this->request($this->body(['campaignIds' => [$rate->getId(), $reduction->getId()]])))['cases'][0];
        $counts = $this->counts();

        $drafts = $this->data($this->request([
            'mode' => 'drafts', 'storeCode' => 'STACK-TEST',
            'drafts' => [
                ['name' => 'Parity rate', 'type' => 'discount', 'phase' => 0, 'dsl' => $this->discountDsl(90, 10), 'config' => ['threshold' => 0, 'rate' => 90]],
                ['name' => 'Parity reduction', 'type' => 'full_reduction', 'phase' => 0, 'dsl' => $this->reductionDsl(5, 5), 'config' => ['threshold' => 0, 'amount' => 5]],
            ],
            'cases' => $this->case(),
        ]));
        self::assertTrue($drafts['verified'], json_encode($drafts));
        $case = $drafts['cases'][0];
        self::assertSame($selected['beforeAmount'], $case['beforeAmount']);
        self::assertSame($selected['afterAmount'], $case['afterAmount']);
        self::assertSame($selected['discountAmount'], $case['discountAmount']);
        self::assertSame(array_column($selected['applied'], 'totalAfter'), array_column($case['applied'], 'totalAfter'));
        self::assertSame([0, 1], array_column($case['applied'], 'draftIndex'));
        self::assertArrayNotHasKey('promotionId', $case['applied'][0]);
        self::assertSame($counts, $this->counts(), 'Draft simulation must not write promotions or templates.');
    }

    public function testGiftAndFreeShippingActionsAreSummarizedWithoutReturningRawItems(): void
    {
        $giftTemplate = $this->template('Gift stack', "type: gift\nwhen:\n  cart.subtotal >= config.threshold\ndo:\n  add gift spec 12 count 2\n", 'gift');
        $gift = $this->campaign($giftTemplate, ['threshold' => 0]);
        $shippingTemplate = $this->template('Shipping stack', "type: free_shipping\ndo:\n  free shipping\n", 'free_shipping');
        $shipping = $this->campaign($shippingTemplate, []);
        $data = $this->data($this->request($this->body([
            'campaignIds' => [$gift->getId(), $shipping->getId()],
            'cases' => [['items' => $this->items(), 'expected' => ['totalAmount' => 10000, 'freeShipping' => true]]],
        ])));
        self::assertTrue($data['verified'], json_encode($data));
        self::assertSame(10000, $data['cases'][0]['afterAmount']);
        self::assertTrue($data['cases'][0]['freeShipping']);
        self::assertSame(['groups' => 2, 'giftGroups' => 1, 'giftQuantity' => 2], $data['cases'][0]['itemSummary']);
        self::assertCount(2, $data['cases'][0]['applied']);
        self::assertArrayNotHasKey('items', $data['cases'][0]);
    }

    public function testExpectedDifferencesAndDraftValidationAreExplicit(): void
    {
        $rate = $this->campaign($this->template('Difference rate', $this->discountDsl(90, 10), 'discount'), ['threshold' => 0, 'rate' => 90]);
        $data = $this->data($this->request($this->body(['campaignIds' => [$rate->getId()], 'cases' => [[
            'items' => $this->items(), 'expected' => ['totalAmount' => 1, 'freeShipping' => true],
        ]]])));
        self::assertFalse($data['verified']);
        self::assertFalse($data['cases'][0]['passed']);
        self::assertSame(['totalAmount', 'freeShipping'], array_column($data['cases'][0]['differences'], 'path'));

        $response = $this->request(['mode' => 'drafts', 'drafts' => [[
            'type' => 'discount', 'phase' => 0, 'dsl' => $this->discountDsl(90, 1), 'config' => ['threshold' => 0, 'rate' => 90, 'unknown' => 1],
        ]], 'cases' => $this->case()]);
        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('unknown_config', (string) $response->getContent());
    }

    public function testAuthorizationBoundsAndReadOnlyGuarantees(): void
    {
        $rate = $this->campaign($this->template('Bounds rate', $this->discountDsl(90, 10), 'discount'), ['threshold' => 0, 'rate' => 90]);
        $counts = $this->counts();
        $body = $this->body(['campaignIds' => [$rate->getId()]]);

        self::assertSame(400, $this->request($body + ['url' => '/arbitrary'])->getStatusCode());
        self::assertSame(400, $this->request($this->body(['campaignIds' => [$rate->getId()], 'cases' => array_fill(0, 9, [])]))->getStatusCode());
        self::assertSame(400, $this->request($this->body(['campaignIds' => [999999]]))->getStatusCode());
        self::assertSame($counts, $this->counts(), 'Every rejected request must remain read-only.');

        $this->token = '';
        self::assertSame(401, $this->request($body)->getStatusCode());
        $user = (new User())->setUsername('stack-user')->setEmail('stack-user@example.test');
        $this->em->persist($user); $this->em->flush();
        $this->token = self::getContainer()->get(TokenManager::class)->createAccessToken($user);
        self::assertSame(403, $this->request($body)->getStatusCode());
    }

    private function template(string $name, string $dsl, string $type): PromotionTemplate
    {
        $service = self::getContainer()->get(PromotionTemplateServiceInterface::class);
        $template = $service->update($service->new(), ['name' => $name, 'type' => $type, 'phase' => str_contains($dsl, 'phase: outer') ? 1 : 0, 'enabled' => true, 'dsl' => $dsl]);
        self::assertInstanceOf(PromotionTemplate::class, $template);
        return $template;
    }

    /** @param array<string, mixed> $config */
    private function campaign(PromotionTemplate $template, array $config, string $conflictMode = 'stackable', string $storeCode = 'STACK-TEST', bool $enabled = true): Promotion
    {
        $promotion = (new Promotion())->setName($template->getName())->setTemplate($template)->setStoreCode($storeCode)
            ->setEnabled($enabled)->setConfig($config)->setConflictMode($conflictMode);
        $this->em->persist($promotion); $this->em->flush();
        return $promotion;
    }

    private function discountDsl(float $rate, int $priority, int $phase = 0): string
    {
        return "type: discount\nphase: ".($phase === 1 ? 'outer' : 'inner')."\nwhen:\n  cart.subtotal >= config.threshold\ndo:\n  discount order config.rate%\npriority: $priority\n";
    }

    private function reductionDsl(float $amount, int $priority, int $phase = 0): string
    {
        return "type: full_reduction\nphase: ".($phase === 1 ? 'outer' : 'inner')."\nwhen:\n  cart.subtotal >= config.threshold\ndo:\n  discount order config.amount\npriority: $priority\n";
    }

    /** @return list<array{specificationId: int, quantity: int, unitPrice: int}> */
    private function items(): array
    {
        return [['specificationId' => 1, 'quantity' => 1, 'unitPrice' => 10000]];
    }

    /** @return list<array{items: list<array{specificationId: int, quantity: int, unitPrice: int}>, expected: array{totalAmount: int, appliedCampaignIds?: list<int>}}> */
    private function case(int $total = 8500): array
    {
        return [['items' => $this->items(), 'expected' => ['totalAmount' => $total]]];
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return array_replace(['mode' => 'selected', 'storeCode' => 'STACK-TEST', 'cases' => $this->case()], $overrides);
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
    private function data(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame(0, $payload['code']);
        self::assertIsArray($payload['data']);
        return $payload['data'];
    }

    /** @param array<string, mixed> $body */
    private function request(array $body): Response
    {
        $request = Request::create('/api/v1/manage/promotion-stacking/check', 'POST',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token],
            content: json_encode($body, JSON_THROW_ON_ERROR));
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
        return $response;
    }
}
