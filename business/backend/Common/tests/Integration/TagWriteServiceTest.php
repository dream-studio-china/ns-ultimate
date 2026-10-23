<?php

declare(strict_types=1);

namespace NsUltimate\Business\Common\Tests\Integration;

use App\Common\Entity\Content;
use App\Common\Entity\Tag;
use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Common\Service\TagWriteService;
use NsUltimate\Integration\Backend\Kernel;

final class TagWriteServiceTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testFieldSchemaIsTheSingleActionSpecificSource(): void
    {
        $create = TagWriteService::fieldSchema('create');
        self::assertSame(['name', 'slug', 'color'], array_keys($create));
        self::assertSame(['type' => 'string', 'maxLength' => 255, 'requiredOnCreate' => true, 'nonEmpty' => true], $create['name']);
        self::assertSame(['type' => 'color', 'nullable' => true], $create['color']);
        self::assertSame(['name', 'slug', 'color'], array_keys(TagWriteService::fieldSchema('update')));
        self::assertSame([], TagWriteService::fieldSchema('delete'));

        $this->assertThrows(\InvalidArgumentException::class, static fn () => TagWriteService::fieldSchema('archive'));
    }

    public function testNormalizationAndValidationShareTheSchema(): void
    {
        self::bootKernel();
        $service = new TagWriteService(self::getContainer()->get(EntityManagerInterface::class));

        self::assertSame(
            ['name' => '  A tag  ', 'slug' => 'a-tag', 'color' => '#A1b2C3'],
            $service->normalize('create', ['name' => '  A tag  ', 'slug' => 'a-tag', 'color' => '#A1b2C3']),
        );
        self::assertSame(['name' => 'Renamed'], $service->normalize('update', ['name' => 'Renamed']));
        self::assertSame([], $service->normalize('update', [], true));
        self::assertSame([], $service->normalize('delete', []));

        foreach ([
            ['name' => 'Only a name'],
            ['name' => '', 'slug' => 'empty-name'],
            ['name' => 'Tag', 'slug' => ''],
            ['name' => 'Tag', 'slug' => 'a-tag', 'color' => 'red'],
            ['name' => 'Tag', 'slug' => 'a-tag', 'color' => 1],
            ['name' => 'Tag', 'slug' => 'a-tag', 'unexpected' => true],
            ['name' => str_repeat('x', 256), 'slug' => 'long-name'],
        ] as $changes) {
            $this->assertThrows(\InvalidArgumentException::class, static fn () => $service->normalize('create', $changes));
        }
        $this->assertThrows(\InvalidArgumentException::class, static fn () => $service->normalize('update', []));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => $service->normalize('delete', ['name' => 'x']));
    }

    public function testLookupUniquenessUnusedCheckAndStagingDoNotFlush(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = new TagWriteService($em);
        $tag = new Tag('Service tag', 'service-tag');
        $em->persist($tag);
        $em->flush();

        self::assertSame($tag, $service->find((string) $tag->getId()));
        self::assertSame($tag, $service->find($tag->getUuid()));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => $service->find('1garbage'));
        $this->assertThrows(\OutOfBoundsException::class, static fn () => $service->find('999999'));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => $service->assertUniqueSlug('service-tag'));

        $content = new Content('Tag write service content');
        $content->addTag($tag);
        $em->persist($content);
        $em->flush();
        $this->assertThrows(\InvalidArgumentException::class, static fn () => $service->assertUnused($tag));

        $content->removeTag($tag);
        $em->flush();
        $service->assertUnused($tag);

        $staged = $service->create(['name' => 'Staged tag', 'slug' => 'staged-tag', 'color' => null]);
        $service->persist($staged);
        self::assertTrue($em->getUnitOfWork()->isScheduledForInsert($staged));
    }

    public function testStandardAdminTagRouteKeepsItsBaselineSerializerBehavior(): void
    {
        $client = self::createAuthenticatedClient();
        $route = $client->getContainer()->get('router')->getRouteCollection()->get('manage-tags-create');
        self::assertSame('App\\Common\\Controller\\Manage\\TagController::createAction', $route?->getDefault('_controller'));

        // This payload was accepted by the original standard-admin serializer path.
        // The assistant-only strict normalizer is intentionally not applied here.
        $client->jsonRequest('POST', '/api/v1/manage/tags', ['name' => '', 'slug' => 'empty', 'color' => 'red']);
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $created = $created['data'] ?? $created;
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $tag = $em->find(Tag::class, (int) $created['id']);
        self::assertInstanceOf(Tag::class, $tag);
        self::assertSame('', $tag->getName());
        self::assertSame('empty', $tag->getSlug());
        self::assertSame('red', $tag->getColor());
    }

    public function testStandardAdminTagRouteStillRequiresRoleAdmin(): void
    {
        $client = self::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('tag-write-reader@example.test')->setUsername('tag-write-reader')->setRoles(['ROLE_USER']);
        $em->persist($user);
        $em->flush();
        $token = $client->getContainer()->get(TokenManager::class)->createAccessToken($user);

        $client->jsonRequest('POST', '/api/v1/manage/tags', ['name' => 'Denied', 'slug' => 'denied-admin-tag'], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('GET', '/api/v1/manage/tags', [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(403);
    }

    private function assertThrows(string $class, callable $callback): void
    {
        $thrown = null;
        try {
            $callback();
        } catch (\Throwable $error) {
            $thrown = $error;
        }
        self::assertInstanceOf($class, $thrown);
    }
}
