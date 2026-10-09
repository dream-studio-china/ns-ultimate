<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Service;

use App\Promotion\Entity\PromotionTemplate;
use App\Promotion\Repository\PromotionTemplateRepository;
use App\Promotion\Service\PromotionTemplateService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class PromotionTemplateServiceTest extends TestCase
{
    private EntityManagerInterface $em;
    private EntityRepository $repo;
    private ContainerInterface $container;
    private PromotionTemplateService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->repo = $this->createMock(EntityRepository::class);

        $entityClass = PromotionTemplate::class;
        $this->em->method('getRepository')->with($entityClass)->willReturn($this->repo);

        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('deserialize')
            ->willReturnCallback(function (string $data, string $class, string $format, array $context) {
                $object = $context['object_to_populate'] ?? null;
                if ($object === null) {
                    return null;
                }
                $parsed = json_decode($data, true);
                if (is_array($parsed)) {
                    foreach ($parsed as $key => $value) {
                        $setter = 'set' . ucfirst($key);
                        if (method_exists($object, $setter)) {
                            $object->$setter($value);
                        }
                    }
                }
                return $object;
            });

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new \Symfony\Component\Validator\ConstraintViolationList());

        $logger = $this->createMock(LoggerInterface::class);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $this->container = $this->createMock(ContainerInterface::class);
        $this->container->method('get')
            ->willReturnCallback(function (string $id) use ($serializer, $validator, $logger, $tokenStorage) {
                return match ($id) {
                    'doctrine.orm.entity_manager' => $this->em,
                    'logger' => $logger,
                    'security.token_storage' => $tokenStorage,
                    'validator' => $validator,
                    'serializer' => $serializer,
                    default => null,
                };
            });
        $this->container->method('has')->willReturn(true);

        $this->service = new PromotionTemplateService($this->container);
    }

    // ──────────────────────── parseDsl ────────────────────────

    public function testParseDslValidReturnsAst(): void
    {
        $dsl = "type: full_reduction\ndo:\n  discount order 10.00";

        $result = $this->service->parseDsl($dsl);

        self::assertNotNull($result['ast']);
        self::assertEmpty($result['errors']);
        self::assertArrayHasKey('type', $result['ast']);
    }

    public function testParseDslInvalidReturnsErrors(): void
    {
        $result = $this->service->parseDsl('@#$%invalid_syntax!!!');

        self::assertNull($result['ast']);
        self::assertNotEmpty($result['errors']);
        self::assertArrayHasKey('message', $result['errors'][0]);
    }

    public function testParseDslEmptyStringReturnsEmptyProgram(): void
    {
        $result = $this->service->parseDsl('');

        self::assertNotNull($result['ast']);
        self::assertSame([], $result['errors']);
        self::assertSame('program', $result['ast']['type'] ?? null);
    }

    public function testParseDslFullReduction(): void
    {
        $dsl = <<<DSL
type: full_reduction
phase: inner
when:
  cart.subtotal >= 5000
do:
  discount order 10.00
DSL;

        $result = $this->service->parseDsl($dsl);

        self::assertNotEmpty($result['ast']);
        self::assertEmpty($result['errors']);
        self::assertSame('full_reduction', $result['ast']['data']['type'] ?? null);
    }

    public function testParseDslDiscountWithPercent(): void
    {
        $dsl = <<<DSL
type: discount
do:
  discount order 10% max 50.00
DSL;

        $result = $this->service->parseDsl($dsl);

        self::assertEmpty($result['errors']);
        self::assertNotEmpty($result['ast']);
    }

    // ──────────────────────── simulate ────────────────────────

    public function testSimulateReturnsStructure(): void
    {
        $template = $this->createTemplate('Test Template', PromotionTemplate::TYPE_FULL_REDUCTION);
        $template->setDsl("type: full_reduction\ndo:\n  discount order 10.00");

        $result = $this->service->simulate($template, ['totalAmount' => 50000]);

        self::assertArrayHasKey('template_id', $result);
        self::assertArrayHasKey('type', $result);
        self::assertArrayHasKey('dsl', $result);
        self::assertArrayHasKey('sampleContext', $result);
        self::assertArrayHasKey('matched', $result);
        self::assertArrayHasKey('actions', $result);
        self::assertSame(PromotionTemplate::TYPE_FULL_REDUCTION, $result['type']);
        self::assertFalse($result['matched']);
    }

    public function testSimulatePassesSampleContext(): void
    {
        $template = $this->createTemplate('Ctx Template', PromotionTemplate::TYPE_DISCOUNT);
        $sampleCtx = ['totalAmount' => 100000, 'items' => []];

        $result = $this->service->simulate($template, $sampleCtx);

        self::assertSame($sampleCtx, $result['sampleContext']);
    }

    public function testSimulateWithEmptyContext(): void
    {
        $template = $this->createTemplate('Empty Ctx', PromotionTemplate::TYPE_FREE_SHIPPING);

        $result = $this->service->simulate($template, []);

        self::assertSame([], $result['sampleContext']);
        self::assertSame(PromotionTemplate::TYPE_FREE_SHIPPING, $result['type']);
    }

    // ──────────────────────── simulate with astCache ────────────────────────

    public function testSimulateUsesAstCacheWhenAvailable(): void
    {
        $template = $this->createTemplate('Cached Template', PromotionTemplate::TYPE_FULL_REDUCTION);
        $template->setDsl(''); // empty DSL — simulate must use cache, not fall back to parseDsl

        $template->setAstCache([
            'type' => 'program',
            'data' => [],
            'children' => [
                [
                    'type' => 'when',
                    'data' => [],
                    'children' => [
                        [
                            'type' => 'condition',
                            'data' => [
                                'op' => '>=',
                                'left' => ['type' => 'path', 'data' => ['value' => 'cart.subtotal'], 'children' => []],
                                'right' => ['type' => 'literal', 'data' => ['value' => 10000], 'children' => []],
                            ],
                            'children' => [],
                        ],
                    ],
                ],
                [
                    'type' => 'do',
                    'data' => [],
                    'children' => [
                        [
                            'type' => 'action_discount',
                            'data' => ['target' => 'order', 'value' => 10],
                            'children' => [],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $this->service->simulate($template, ['totalAmount' => 50000]);

        self::assertTrue($result['matched']);
        self::assertCount(1, $result['actions']);
        self::assertSame('action_discount', $result['actions'][0]['type']);
    }

    public function testSimulateUsesAstCacheWhenConditionNotMet(): void
    {
        $template = $this->createTemplate('Cached Fail', PromotionTemplate::TYPE_FULL_REDUCTION);
        $template->setDsl('');

        $template->setAstCache([
            'type' => 'program',
            'data' => [],
            'children' => [
                [
                    'type' => 'when',
                    'data' => [],
                    'children' => [
                        [
                            'type' => 'not',
                            'data' => [],
                            'children' => [
                                [
                                    'type' => 'condition',
                                    'data' => [
                                        'op' => '==',
                                        'left' => ['type' => 'literal', 'data' => ['value' => 1], 'children' => []],
                                        'right' => ['type' => 'literal', 'data' => ['value' => 1], 'children' => []],
                                    ],
                                    'children' => [],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'do',
                    'data' => [],
                    'children' => [
                        [
                            'type' => 'action_discount',
                            'data' => ['target' => 'order', 'value' => 10],
                            'children' => [],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $this->service->simulate($template, ['totalAmount' => 100]);

        self::assertFalse($result['matched']);
        self::assertCount(1, $result['actions']);
    }

    public function testSimulateWithoutAstCacheReParsesDsl(): void
    {
        $template = $this->createTemplate('NoCache Template', PromotionTemplate::TYPE_FULL_REDUCTION);
        $template->setAstCache(null);
        $template->setDsl("type: full_reduction\nwhen:\n  cart.subtotal >= 1000\ndo:\n  discount order 5.00");

        $result = $this->service->simulate($template, ['totalAmount' => 50000]);

        self::assertTrue($result['matched']);
        self::assertCount(1, $result['actions']);
    }

    public function testSimulateWithoutAstCacheConditionNotMet(): void
    {
        $template = $this->createTemplate('NoCache Unmet', PromotionTemplate::TYPE_FULL_REDUCTION);
        $template->setAstCache(null);
        $template->setDsl("type: full_reduction\nwhen:\n  not:\n    cart.subtotal >= 100\ndo:\n  discount order 5.00");

        $result = $this->service->simulate($template, ['totalAmount' => 100]);

        self::assertFalse($result['matched']);
        self::assertCount(1, $result['actions']);
    }

    public function testSimulateWithInvalidDslReturnsErrors(): void
    {
        $template = $this->createTemplate('Broken Template', PromotionTemplate::TYPE_FULL_REDUCTION);
        $template->setAstCache(null);
        $template->setDsl('@@@@@');

        $result = $this->service->simulate($template, ['totalAmount' => 50000]);

        self::assertArrayHasKey('errors', $result);
        self::assertNotEmpty($result['errors']);
    }

    // ──────────────────────── update ────────────────────────

    public function testUpdateSetsAstCacheWhenDslIsValid(): void
    {
        $template = $this->createTemplate('Update Template', PromotionTemplate::TYPE_FULL_REDUCTION);

        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->update($template, [
            'name' => 'Updated Name',
            'dsl' => "type: full_reduction\ndo:\n  discount order 10.00",
        ]);

        self::assertNotNull($result);
        self::assertNotNull($template->getAstCache());
        self::assertArrayHasKey('type', $template->getAstCache());
    }

    public function testUpdateDoesNotSetAstCacheWhenNoDsl(): void
    {
        $template = $this->createTemplate('No Dsl Template', PromotionTemplate::TYPE_DISCOUNT);

        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->update($template, [
            'name' => 'Renamed',
        ]);

        self::assertNotNull($result);
        self::assertNull($template->getAstCache());
    }

    public function testUpdateWithDataNullDoesNothing(): void
    {
        $template = $this->createTemplate('Null Data Template', PromotionTemplate::TYPE_FULL_REDUCTION);

        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->update($template, null);

        self::assertNotNull($result);
        self::assertNull($template->getAstCache());
    }

    public function testUpdateWithInvalidDslDoesNotSetAstCache(): void
    {
        $template = $this->createTemplate('Invalid Dsl Template', PromotionTemplate::TYPE_FULL_REDUCTION);
        $template->setAstCache(null);

        $caught = false;
        try {
            $this->service->update($template, [
                'dsl' => '@#$%',
            ]);
        } catch (\Throwable $e) {
            $caught = true;
        }

        self::assertTrue($caught);
        self::assertNull($template->getAstCache());
    }

    public function testUpdateNoFlushPersistsButDoesNotFlush(): void
    {
        $template = $this->createTemplate('NoFlush Template', PromotionTemplate::TYPE_MEMBER_DISCOUNT);

        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::never())->method('flush');

        $result = $this->service->update($template, [
            'name' => 'No Flush',
            'dsl' => "type: member_discount\ndo:\n  member discount 90%",
        ], true);

        self::assertNotNull($result);
    }

    // ──────────────────────── update with already-parsed DSL ────────────────────────

    public function testUpdateWithAlreadyParsedDslOverwritesAstCache(): void
    {
        $template = $this->createTemplate('Cached Template', PromotionTemplate::TYPE_FULL_REDUCTION);
        $template->setAstCache(['type' => 'program', 'data' => ['old' => true], 'children' => []]);

        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->update($template, [
            'type' => PromotionTemplate::TYPE_DISCOUNT,
            'dsl' => "type: discount\ndo:\n  discount order 20%",
        ]);

        self::assertNotNull($result);
        $cached = $template->getAstCache();
        self::assertNotNull($cached);
        self::assertSame('program', $cached['type']);
        self::assertSame('discount', $cached['data']['type']);
    }

    #[Group('low-value')]
    public function testUpdateWithDslDataOnlyParsesAndSetsCache(): void
    {
        $template = $this->createTemplate('DslOnly Template', PromotionTemplate::TYPE_NTH_DISCOUNT);

        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->update($template, [
            'dsl' => "type: nth_discount\ndo:\n  discount item 2 50%",
        ]);

        self::assertNotNull($result);
        self::assertNotNull($template->getAstCache());
        self::assertSame('nth_discount', $template->getAstCache()['data']['type']);
    }

    // ──────────────────────── parseDsl advanced types ────────────────────────

    public function testParseDslTieredType(): void
    {
        $dsl = <<<DSL
type: full_reduction
do:
  tiered:
    - threshold: 100 discount: 10
    - threshold: 200 discount: 30
    - threshold: 500 discount: 100
DSL;

        $result = $this->service->parseDsl($dsl);

        self::assertEmpty($result['errors']);
        self::assertNotEmpty($result['ast']);
        self::assertSame('program', $result['ast']['type']);
    }

    public function testParseDslNthDiscountType(): void
    {
        $dsl = <<<DSL
type: nth_discount
do:
  discount item 3 20%
DSL;

        $result = $this->service->parseDsl($dsl);

        self::assertEmpty($result['errors']);
        self::assertNotEmpty($result['ast']);
        self::assertSame('nth_discount', $result['ast']['data']['type']);
    }

    public function testParseDslMemberDiscountType(): void
    {
        $dsl = <<<DSL
type: member_discount
do:
  member discount 95%
DSL;

        $result = $this->service->parseDsl($dsl);

        self::assertEmpty($result['errors']);
        self::assertNotEmpty($result['ast']);
        self::assertSame('member_discount', $result['ast']['data']['type']);
    }

    // ───────────────────── helpers ─────────────────────

    private function createTemplate(string $name, string $type): PromotionTemplate
    {
        $template = new PromotionTemplate();
        $template->setName($name);
        $template->setType($type);
        return $template;
    }
}
