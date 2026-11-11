<?php

declare(strict_types=1);

namespace NsUltimate\Business\Promotion\Tests\Integration;

use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Promotion\Entity\Promotion;
use App\Promotion\Entity\PromotionTemplate;
use App\Promotion\Service\PromotionServiceInterface;
use App\Promotion\Service\PromotionTemplateServiceInterface;
use App\Trade\Service\Pricing\PriceCalculationContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Business\Promotion\Adapter\PromotionDateService;
use NsUltimate\Integration\Backend\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PromotionDateApiTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $token;
    private int $templateId;

    protected static function getKernelClass(): string { return Kernel::class; }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata); $tool->createSchema($metadata);
        $user = (new User())->setUsername('promotion-admin')->setEmail('promotion-admin@example.test')->setRoles(['ROLE_ADMIN']);
        $this->em->persist($user); $this->em->flush();
        $this->token = self::getContainer()->get(TokenManager::class)->createAccessToken($user);
        $templates = self::getContainer()->get(PromotionTemplateServiceInterface::class);
        $template = $templates->update($templates->new(), ['name' => 'Order discount template', 'type' => 'discount', 'phase' => 1, 'enabled' => true,
            'dsl' => "type: discount\nphase: outer\nwhen:\n  cart.subtotal > 0\ndo:\n  discount order 80%\n"]);
        self::assertInstanceOf(PromotionTemplate::class, $template);
        $this->templateId = $template->getId();
    }

    public function testCreateWithOffsetsPersistsImmutableDatesAndPreservesInstants(): void
    {
        self::assertInstanceOf(PromotionDateService::class, self::getContainer()->get(PromotionServiceInterface::class));
        $response = $this->request('POST', '', $this->body() + ['startTime' => '2026-11-12T10:30:00+08:00', 'endTime' => '2026-11-13T15:00:00-05:00']);
        self::assertSame(201, $response->getStatusCode(), $response->getContent());
        $promotion = $this->reload($this->data($response)['uuid']);
        self::assertInstanceOf(\DateTimeImmutable::class, $promotion->getStartTime());
        self::assertSame((new \DateTimeImmutable('2026-11-12T10:30:00+08:00'))->getTimestamp(), $promotion->getStartTime()->getTimestamp());
        self::assertSame((new \DateTimeImmutable('2026-11-13T15:00:00-05:00'))->getTimestamp(), $promotion->getEndTime()->getTimestamp());
    }

    public function testUpdatePickerDatesAndOmittedBoundariesRemainUnchanged(): void
    {
        $created = $this->request('POST', '', $this->body() + ['startTime' => '2026-11-12 09:00:00', 'endTime' => '2026-12-31 23:59:59']);
        self::assertSame(201, $created->getStatusCode());
        $id = $this->data($created)['id'];
        $uuid = $this->data($created)['uuid'];
        self::assertSame(200, $this->request('PUT', '/'.$id, ['startTime' => '2026-11-13 10:00:00'])->getStatusCode());
        $promotion = $this->reload($uuid);
        self::assertSame('2026-11-13 10:00:00', $promotion->getStartTime()->format('Y-m-d H:i:s'));
        self::assertSame('2026-12-31 23:59:59', $promotion->getEndTime()->format('Y-m-d H:i:s'));
        self::assertSame(200, $this->request('PUT', '/'.$id, ['description' => 'Updated description'])->getStatusCode());
        $promotion = $this->reload($uuid);
        self::assertSame('Updated description', $promotion->getDescription());
        self::assertSame('2026-11-13 10:00:00', $promotion->getStartTime()->format('Y-m-d H:i:s'));
        self::assertSame('2026-12-31 23:59:59', $promotion->getEndTime()->format('Y-m-d H:i:s'));
    }

    public function testExplicitNullClearsOnlyTheSpecifiedBoundary(): void
    {
        $response = $this->request('POST', '', $this->body() + ['startTime' => '2026-11-12', 'endTime' => '2026-12-31']);
        self::assertSame(201, $response->getStatusCode());
        $data = $this->data($response);
        self::assertSame(200, $this->request('PUT', '/'.$data['id'], ['startTime' => null])->getStatusCode());
        $promotion = $this->reload($data['uuid']);
        self::assertNull($promotion->getStartTime());
        self::assertSame('2026-12-31', $promotion->getEndTime()->format('Y-m-d'));
        self::assertSame(200, $this->request('PUT', '/'.$data['id'], ['endTime' => null])->getStatusCode());
        self::assertNull($this->reload($data['uuid'])->getEndTime());
        $response = $this->request('POST', '', array_replace($this->body(), ['name' => 'Unlimited campaign', 'startTime' => null, 'endTime' => null]));
        self::assertSame(201, $response->getStatusCode());
        $promotion = $this->reload($this->data($response)['uuid']);
        self::assertNull($promotion->getStartTime());
        self::assertNull($promotion->getEndTime());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDates(): iterable
    {
        foreach (['empty' => '', 'relative' => 'tomorrow', 'garbage' => 'not-a-date', 'overflow-day' => '2026-02-30 12:00:00',
            'overflow-month' => '2026-13-01', 'overflow-time' => '2026-11-12 25:00:00', 'number' => 123, 'boolean' => false, 'array' => []] as $name => $value) yield $name => [$value];
    }

    #[DataProvider('invalidDates')]
    public function testInvalidDateIsBadRequestAndDoesNotCreateCampaign(mixed $value): void
    {
        $response = $this->request('POST', '', $this->body() + ['startTime' => $value]);
        self::assertSame(400, $response->getStatusCode(), $response->getContent());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM promotion'));
    }

    public function testInvalidSecondDateDoesNotPersistFirstDateOrOtherFields(): void
    {
        $created = $this->request('POST', '', $this->body() + ['startTime' => '2026-11-12', 'endTime' => '2026-12-31']);
        $data = $this->data($created);
        self::assertSame(400, $this->request('PUT', '/'.$data['id'], ['startTime' => null, 'endTime' => 'bad-date', 'description' => 'Must not persist'])->getStatusCode());
        $promotion = $this->reload($data['uuid']);
        self::assertSame('2026-11-12', $promotion->getStartTime()->format('Y-m-d'));
        self::assertSame('2026-12-31', $promotion->getEndTime()->format('Y-m-d'));
        self::assertNull($promotion->getDescription());
    }

    public function testBatchCreationRollsBackIfAnotherCampaignDateIsInvalid(): void
    {
        $response = $this->request('POST', '', [$this->body() + ['startTime' => '2026-11-12'], array_replace($this->body(), ['name' => 'Invalid campaign', 'endTime' => 'invalid'])]);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM promotion'));
    }

    public function testServiceAcceptsDateObjectsAndPreservesNoFlush(): void
    {
        $service = self::getContainer()->get(PromotionServiceInterface::class);
        $mutable = new \DateTime('2026-11-12T10:30:00+08:00');
        $promotion = $service->update($service->new(), $this->body() + ['startTime' => $mutable, 'endTime' => new \DateTimeImmutable('2026-12-31T00:00:00Z')], true);
        self::assertInstanceOf(Promotion::class, $promotion);
        self::assertInstanceOf(\DateTimeImmutable::class, $promotion->getStartTime());
        self::assertSame('+08:00', $mutable->format('P'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM promotion'));
        $this->em->flush();
        $id = $promotion->getId();
        $service->update($promotion, ['startTime' => null], true);
        self::assertNotNull($this->em->getConnection()->fetchOne('SELECT start_time FROM promotion WHERE id = ?', [$id]));
        $this->em->flush();
        self::assertNull($this->em->getConnection()->fetchOne('SELECT start_time FROM promotion WHERE id = ?', [$id]));
    }

    public function testScheduleAndDiscountDelegationRemainUnchanged(): void
    {
        $response = $this->request('POST', '', $this->body() + ['startTime' => '2000-01-01T00:00:00Z', 'endTime' => '2099-01-01T00:00:00Z']);
        self::assertSame(201, $response->getStatusCode());
        $id = $this->data($response)['id'];
        $service = self::getContainer()->get(PromotionServiceInterface::class);
        $context = new PriceCalculationContext([]);
        $context->storeCode = 'DATE-TEST'; $context->totalAmount = 10000;
        self::assertCount(1, $service->getAvailable($context, 1));
        self::assertSame([], $service->getAvailable($context, 1, [$id]));
        $promotion = $service->getFirstAvailable($context, 1);
        self::assertInstanceOf(Promotion::class, $promotion);
        $service->apply($promotion, $context);
        self::assertSame(8000, $context->totalAmount);
        self::assertSame(200, $this->request('PUT', '/'.$id, ['endTime' => '2001-01-01T00:00:00Z'])->getStatusCode());
        self::assertSame([], $service->getAvailable($context, 1));
        self::assertSame(200, $this->request('PUT', '/'.$id, ['startTime' => '2098-01-01T00:00:00Z', 'endTime' => '2099-01-01T00:00:00Z'])->getStatusCode());
        self::assertNull($service->getFirstAvailable($context, 1));
        self::assertSame(200, $this->request('PUT', '/'.$id, ['startTime' => null, 'endTime' => null])->getStatusCode());
        self::assertCount(1, $service->getAvailable($context, 1));
    }

    public function testOrdinaryUserCannotChangeCampaignDates(): void
    {
        $user = (new User())->setUsername('promotion-user')->setEmail('promotion-user@example.test');
        $this->em->persist($user); $this->em->flush();
        $this->token = self::getContainer()->get(TokenManager::class)->createAccessToken($user);
        self::assertSame(403, $this->request('POST', '', $this->body() + ['startTime' => '2026-11-12'])->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function body(): array { return ['name' => 'Scheduled campaign', 'template' => $this->templateId, 'storeCode' => 'DATE-TEST', 'enabled' => true, 'conflictMode' => 'exclusive']; }

    private function reload(string $uuid): Promotion
    {
        $this->em->clear();
        $promotion = $this->em->getRepository(Promotion::class)->findOneBy(['uuid' => $uuid]);
        self::assertInstanceOf(Promotion::class, $promotion);
        return $promotion;
    }

    /** @param array<mixed> $body */
    private function request(string $method, string $suffix, array $body): Response
    {
        return self::$kernel->handle(Request::create('/api/v1/manage/promotions'.$suffix, $method,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR)));
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array { return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data']; }
}
