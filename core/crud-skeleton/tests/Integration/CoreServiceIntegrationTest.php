<?php

namespace App\Tests\Integration;

use App\Common\Entity\Content;
use App\Core\Parser\ExpressionDqlParser;
use App\Core\Service\ExpressionService;
use Doctrine\ORM\EntityManagerInterface;

final class CoreServiceIntegrationTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::bootKernel();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testExpressionServiceBuildFilterAgainstRealDoctrineMetadata(): void
    {
        $service = new ExpressionService();

        $result = $service->buildFilter(
            'entity.getId() == 1',
            Content::class,
            ['entity' => ''],
            $this->em
        );

        self::assertArrayHasKey('qb', $result);
        self::assertArrayHasKey('parameters', $result);
        self::assertNotEmpty($result['parameters']);
    }

    public function testExpressionServiceBuildsPlainMatchFilter(): void
    {
        $service = new ExpressionService();

        $result = $service->buildFilter(
            "entity.getTitle() matches 'paper'",
            Content::class,
            ['entity' => ''],
            $this->em
        );

        self::assertArrayHasKey('qb', $result);
        self::assertStringContainsString('LIKE', $result['qb']->getDQL());
        self::assertStringContainsString('LIKE', $result['qb']->getQuery()->getSQL());
    }

    public function testPlainMatchExecutesAsLiteralSubstringAgainstDatabase(): void
    {
        $token = 'matches-' . bin2hex(random_bytes(6));
        $expected = new Content($token . '-50%_off!');
        $wildcardDecoy = new Content($token . '-50XXoff!');
        $this->em->persist($expected);
        $this->em->persist($wildcardDecoy);
        $this->em->flush();

        $result = (new ExpressionService())->buildFilter(
            sprintf('entity.getTitle() matches "%s-50%%_off!"', $token),
            Content::class,
            ['entity' => ''],
            $this->em,
        );

        /** @var list<Content> $matches */
        $matches = $result['qb']->getQuery()->getResult();
        self::assertSame([$expected], $matches);
    }

    public function testCustomDqlFunctionsAreRegistered(): void
    {
        $randSql = $this->em
            ->createQuery('SELECT RAND() FROM ' . Content::class . ' content')
            ->getSQL();
        $seededRandSql = $this->em
            ->createQuery('SELECT RAND(1) FROM ' . Content::class . ' content')
            ->getSQL();
        $dateFormatSql = $this->em
            ->createQuery("SELECT DATE_FORMAT(content.createdAt, '%Y-%m') FROM " . Content::class . ' content')
            ->getSQL();

        self::assertStringContainsString('RAND()', $randSql);
        self::assertStringContainsString('RAND(1)', $seededRandSql);
        self::assertStringContainsString('DATE_FORMAT', $dateFormatSql);
    }

    public function testExpressionDqlParserValidateFragmentsWithDoctrineMetadata(): void
    {
        $parser = new ExpressionDqlParser();
        $parser->setDataClass(Content::class)
            ->setValues(['entity' => ''])
            ->setExpression('entity.getId() == 2')
            ->compile();

        $parser->validateFragments($this->em);

        self::assertStringContainsString('filter_entity.id', $parser->getWhere());
    }
}
