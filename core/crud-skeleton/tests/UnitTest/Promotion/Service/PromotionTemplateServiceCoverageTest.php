<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Promotion\Service;

use App\Promotion\Entity\PromotionTemplate;
use App\Promotion\Service\PromotionTemplateService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidatorException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Covers the remaining uncovered lines of PromotionTemplateService.php:
 *   - line 125 update() rejects a DSL whose type does not match the template
 *   - line 128 update() rejects a DSL whose phase does not match the template
 */
#[AllowMockObjectsWithoutExpectations]
final class PromotionTemplateServiceCoverageTest extends TestCase
{
    private EntityManagerInterface $em;
    private ContainerInterface $container;
    private PromotionTemplateService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(EntityRepository::class);
        $this->em->method('getRepository')->with(PromotionTemplate::class)->willReturn($repo);

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
        $validator->method('validate')->willReturn(new ConstraintViolationList());

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

    private function createTemplate(string $name, string $type): PromotionTemplate
    {
        $template = new PromotionTemplate();
        $template->setName($name);
        $template->setType($type);
        return $template;
    }

    public function testUpdateRejectsDslTypeMismatch(): void
    {
        $template = $this->createTemplate('Discount Template', PromotionTemplate::TYPE_DISCOUNT);

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('Template type must match DSL type.');

        $this->service->update($template, [
            'type' => PromotionTemplate::TYPE_GIFT,
            'dsl' => "type: discount\ndo:\n  discount order 10%",
        ]);
    }

    public function testUpdateAcceptsDslTypeMatchWhenObjectTypeUsed(): void
    {
        $template = $this->createTemplate('Discount Template', PromotionTemplate::TYPE_DISCOUNT);

        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->update($template, [
            // no explicit 'type' — falls back to the object's own type
            'dsl' => "type: discount\ndo:\n  discount order 10%",
        ]);

        self::assertNotNull($result);
        self::assertNotNull($template->getAstCache());
    }

    public function testUpdateRejectsDslPhaseMismatch(): void
    {
        $template = $this->createTemplate('Outer DSL Template', PromotionTemplate::TYPE_DISCOUNT);

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('Template phase must match DSL phase.');

        $this->service->update($template, [
            'phase' => PromotionTemplate::PHASE_INNER,
            'dsl' => "type: discount\nphase: outer\ndo:\n  discount order 10%",
        ]);
    }

    public function testUpdateAcceptsDslPhaseMatch(): void
    {
        $template = $this->createTemplate('Inner DSL Template', PromotionTemplate::TYPE_FULL_REDUCTION);

        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->update($template, [
            'phase' => PromotionTemplate::PHASE_INNER,
            'dsl' => "type: full_reduction\nphase: inner\ndo:\n  discount order 20",
        ]);

        self::assertNotNull($result);
        $cached = $template->getAstCache();
        self::assertNotNull($cached);
        self::assertSame(0, $cached['data']['phase']);
    }
}
