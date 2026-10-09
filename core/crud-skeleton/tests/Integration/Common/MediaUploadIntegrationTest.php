<?php

declare(strict_types=1);

namespace App\Tests\Integration\Common;

use App\Common\Entity\Category;
use App\Common\Entity\Media;
use App\Common\Service\MediaServiceInterface;
use App\Common\Service\MediaService;
use App\Identity\Entity\User;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Exception\ValidatorException;

final class MediaUploadIntegrationTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;
    private string $uploadRoot;
    private string $tempRoot;

    protected function setUp(): void
    {
        $this->bootTestDatabase();

        self::ensureKernelShutdown();
        $client = static::createClient();
        $container = $client->getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->uploadRoot = dirname(__DIR__, 3) . '/public/uploads';
        $this->tempRoot = sys_get_temp_dir() . '/crud-media-upload-test-' . bin2hex(random_bytes(4));
        mkdir($this->tempRoot, 0775, true);

        $this->em->createQuery('DELETE FROM App\\Common\\Entity\\Media')->execute();
        $this->removeDirectory($this->uploadRoot);
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempRoot);
        $this->removeDirectory($this->uploadRoot);
        self::ensureKernelShutdown();
    }

    public function testManageUploadStoresMediaAndDeleteRemovesFile(): void
    {
        $client = static::createAuthenticatedClient();
        $category = new Category('Upload Category', 'upload-category-' . bin2hex(random_bytes(4)));
        $this->em->persist($category);
        $this->em->flush();

        $client->request(
            'POST',
            '/api/v1/manage/media/upload',
            ['alt' => 'Alt text', 'title' => 'Image title', 'category' => (string) $category->getId()],
            ['file' => $this->uploadedPng('manage.png')],
        );

        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $created = $this->decodeResponse($client->getResponse()->getContent());

        self::assertSame('Uploaded', $created['message']);
        self::assertSame('local', $created['data']['storage']);
        self::assertSame('image/png', $created['data']['mimeType']);
        self::assertSame('Alt text', $created['data']['alt']);
        self::assertSame('Image title', $created['data']['title']);
        self::assertSame($category->getId(), $created['data']['category']['id']);
        self::assertSame(1, $created['data']['width']);
        self::assertSame(1, $created['data']['height']);

        $storedPath = $this->uploadRoot . str_replace('/uploads', '', $created['data']['path']);
        self::assertFileExists($storedPath);

        /** @var Media $media */
        $media = $this->em->getRepository(Media::class)->find($created['data']['id']);
        self::assertNull($media->getUser());
        self::assertSame($category->getId(), $media->getCategory()?->getId());

        $client->request('DELETE', '/api/v1/manage/media/' . $created['data']['id']);

        self::assertSame(204, $client->getResponse()->getStatusCode());
        self::assertFileDoesNotExist($storedPath);
    }

    public function testPublicMediaReadOnlyDoesNotRequireAuthentication(): void
    {
        $owner = new User();
        $owner->setEmail('public-media-owner@example.com');
        $owner->setUsername('public-media-owner');
        $owner->setPassword('test-password');

        $media = new Media('public.png', 'public.png', 'image/png', 10, '/uploads/public.png');
        $ownedMedia = new Media('owned.png', 'owned.png', 'image/png', 10, '/uploads/owned.png');
        $ownedMedia->setUser($owner);
        $this->em->persist($owner);
        $this->em->persist($media);
        $this->em->persist($ownedMedia);
        $this->em->flush();
        $id = $media->getId();

        self::ensureKernelShutdown();
        $client = static::createClient();

        $client->request('GET', '/api/v1/public/media');
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $list = $this->decodeResponse($client->getResponse()->getContent());
        self::assertNotEmpty($list['data']);
        $listIds = array_column($list['data'], 'id');
        self::assertContains($id, $listIds);
        self::assertNotContains($ownedMedia->getId(), $listIds);

        $client->request('GET', '/api/v1/public/media/' . $id);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $detail = $this->decodeResponse($client->getResponse()->getContent());
        self::assertSame($id, $detail['data']['id']);

        self::assertFalse($client->getContainer()->get('router')->getRouteCollection()->get('public-media-create') instanceof \Symfony\Component\Routing\Route);
    }

    public function testAppUploadStoresPdfWithoutDimensions(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request(
            'POST',
            '/api/v1/app/media/upload',
            ['storage' => 'local'],
            ['file' => $this->uploadedPdf('document.pdf')],
        );

        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $created = $this->decodeResponse($client->getResponse()->getContent());

        self::assertSame('application/pdf', $created['data']['mimeType']);
        self::assertNull($created['data']['width']);
        self::assertNull($created['data']['height']);

        /** @var Media $media */
        $media = $this->em->getRepository(Media::class)->find($created['data']['id']);
        /** @var User $user */
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'testauth@example.com']);
        self::assertSame($user->getId(), $media->getUser()?->getId());

        $client->request('GET', '/api/v1/app/media');
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $list = $this->decodeResponse($client->getResponse()->getContent());
        self::assertCount(1, $list['data']);
        self::assertSame($created['data']['id'], $list['data'][0]['id']);

        $client->request('GET', '/api/v1/app/media/' . $created['data']['id']);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $detail = $this->decodeResponse($client->getResponse()->getContent());
        self::assertSame($created['data']['id'], $detail['data']['id']);
    }

    public function testAppDeleteRemovesOnlyOwnUploadedMedia(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request(
            'POST',
            '/api/v1/app/media/upload',
            ['storage' => 'local'],
            ['file' => $this->uploadedPng('own-delete.png')],
        );

        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $created = $this->decodeResponse($client->getResponse()->getContent());
        $storedPath = $this->uploadRoot . str_replace('/uploads', '', $created['data']['path']);
        self::assertFileExists($storedPath);

        $otherUser = new User();
        $otherUser->setEmail('other-media-owner@example.com');
        $otherUser->setUsername('other-media-owner');
        $otherUser->setPassword('test-password');
        $otherMedia = new Media('other.png', 'other.png', 'image/png', 10, '/uploads/other.png');
        $otherMedia->setUser($otherUser);
        $this->em->persist($otherUser);
        $this->em->persist($otherMedia);
        $this->em->flush();
        $otherMediaId = $otherMedia->getId();

        $client->request('DELETE', '/api/v1/app/media/' . $otherMediaId);
        self::assertSame(404, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertNotNull($this->em->getRepository(Media::class)->find($otherMediaId));

        $client->request('DELETE', '/api/v1/app/media/' . $created['data']['id']);
        self::assertSame(204, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertNull($this->em->getRepository(Media::class)->find($created['data']['id']));
        self::assertFileDoesNotExist($storedPath);
    }

    public function testUploadHandlesUnexpectedStorageFailure(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $controller = new class(new class implements MediaServiceInterface {
            public function createFromUpload(UploadedFile $file, ?string $storage = null, array $meta = [], ?User $owner = null): Media
            {
                throw new \Error('Unexpected upload failure');
            }

            public function get($object, bool $directly = false): ?object { return null; }
            public function list($object = null, $order = null, bool $disableRequest = true): array { return []; }
            public function new(): object { return new \stdClass(); }
            public function update($object, ?array $data = null, bool $noFlush = false): object { return new \stdClass(); }
            public function remove($object): bool { return false; }
            public function wrapInTransaction(callable $fn): mixed { return $fn(null); }
        }) extends \App\Common\Controller\App\MediaController {
            protected function uploadOwner(): ?User
            {
                return null;
            }
        };
        $controller->setSerializer($container->get('serializer'));
        $controller->setTranslator($container->get('translator'));
        $controller->setRequestStack($container->get('request_stack'));

        $request = new Request(files: ['file' => $this->uploadedPng('unexpected.png')]);
        $response = $controller->uploadAction($request);
        $data = $this->decodeResponse($response->getContent());

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Unexpected upload failure', $data['message']);
    }

    public function testUploadRequiresFile(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/manage/media/upload');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $data = $this->decodeResponse($client->getResponse()->getContent());
        self::assertSame('Uploaded file is required', $data['message']);
    }

    public function testAppUploadRequiresFile(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('POST', '/api/v1/app/media/upload');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $data = $this->decodeResponse($client->getResponse()->getContent());
        self::assertSame('Uploaded file is required', $data['message']);
    }

    public function testUploadRejectsUnknownStorage(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request(
            'POST',
            '/api/v1/app/media/upload',
            ['storage' => 'missing'],
            ['file' => $this->uploadedPng('missing.png')],
        );

        self::assertSame(400, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $data = $this->decodeResponse($client->getResponse()->getContent());
        self::assertStringContainsString('Unknown media storage driver', $data['message']);
    }

    public function testManageUploadRejectsUnknownStorage(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request(
            'POST',
            '/api/v1/manage/media/upload',
            ['storage' => 'missing'],
            ['file' => $this->uploadedPng('missing-manage.png')],
        );

        self::assertSame(400, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $data = $this->decodeResponse($client->getResponse()->getContent());
        self::assertStringContainsString('Unknown media storage driver', $data['message']);
    }

    public function testUploadRejectsUnknownCategory(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request(
            'POST',
            '/api/v1/manage/media/upload',
            ['category' => '999999'],
            ['file' => $this->uploadedPng('missing-category.png')],
        );

        self::assertSame(400, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $data = $this->decodeResponse($client->getResponse()->getContent());
        self::assertSame('Category is not found', $data['message']);
    }

    public function testMediaServiceRejectsInvalidUploads(): void
    {
        self::bootKernel();
        /** @var MediaService $service */
        $service = static::getContainer()->get(MediaService::class);

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('Uploaded file MIME type is not allowed');

        $service->createFromUpload($this->uploadedText('text.txt'));
    }

    public function testMediaServiceRejectsEmptyFiles(): void
    {
        self::bootKernel();
        /** @var MediaService $service */
        $service = static::getContainer()->get(MediaService::class);

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('Uploaded file is empty');

        $service->createFromUpload($this->uploadedEmptyPng('empty.png'));
    }

    public function testMediaServiceRejectsInvalidUploadError(): void
    {
        self::bootKernel();
        /** @var MediaService $service */
        $service = static::getContainer()->get(MediaService::class);
        $source = $this->tempRoot . '/error.png';
        file_put_contents($source, $this->pngBytes());

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('Uploaded file is invalid');

        $service->createFromUpload(new UploadedFile($source, 'error.png', 'image/png', UPLOAD_ERR_CANT_WRITE, true));
    }

    public function testMediaServiceRejectsOversizedFile(): void
    {
        self::bootKernel();
        /** @var MediaService $service */
        $service = static::getContainer()->get(MediaService::class);
        $source = $this->tempRoot . '/large.png';
        file_put_contents($source, $this->pngBytes() . str_repeat('a', 10485761));

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage('Uploaded file is too large');

        $service->createFromUpload(new UploadedFile($source, 'large.png', 'image/png', null, true));
    }

    public function testMediaServiceAcceptsInvalidImageWithoutDimensions(): void
    {
        self::bootKernel();
        /** @var MediaService $service */
        $service = static::getContainer()->get(MediaService::class);
        $source = $this->tempRoot . '/invalid-image.png';
        file_put_contents($source, 'not actually an image');

        $media = $service->createFromUpload(new class($source) extends UploadedFile {
            public function __construct(string $path)
            {
                parent::__construct($path, 'invalid-image.png', 'image/png', null, true);
            }

            public function getMimeType(): ?string
            {
                return 'image/png';
            }
        });

        self::assertNull($media->getWidth());
        self::assertNull($media->getHeight());
    }

    public function testMediaServiceRemoveReturnsFalseForUnknownMedia(): void
    {
        self::bootKernel();
        /** @var MediaService $service */
        $service = static::getContainer()->get(MediaService::class);

        self::assertFalse($service->remove(null));
    }

    public function testMediaServiceRemoveIgnoresStorageDeleteFailure(): void
    {
        self::bootKernel();
        /** @var MediaService $service */
        $service = static::getContainer()->get(MediaService::class);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $media = new Media('remote.png', 'remote.png', 'image/png', 10, 'https://cdn.example.com/remote.png', 'qiniu');
        $em->persist($media);
        $em->flush();
        $id = $media->getId();

        self::assertTrue($service->remove($media));
        self::assertNull($em->getRepository(Media::class)->find($id));
    }

    public function testMediaServiceRemoveReturnsFalseWhenFlushFails(): void
    {
        self::bootKernel();
        /** @var MediaService $service */
        $service = static::getContainer()->get(MediaService::class);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $media = new Media('flush-fail.png', 'flush-fail.png', 'image/png', 10, '/uploads/flush-fail.png');
        $em->persist($media);
        $em->flush();

        $reflection = new \ReflectionClass($service);
        $emProperty = $reflection->getParentClass()->getProperty('em');
        $originalEm = $emProperty->getValue($service);
        $emProperty->setValue($service, new class {
            public function remove(object $object): void {}
            public function flush(): void { throw new \RuntimeException('flush failed'); }
        });

        try {
            self::assertFalse($service->remove($media));
        } finally {
            $emProperty->setValue($service, $originalEm);
            $em->clear();
        }
    }

    public function testMediaPrePersistInitializesCreatedAt(): void
    {
        $reflection = new \ReflectionClass(Media::class);
        /** @var Media $uninitialized */
        $uninitialized = $reflection->newInstanceWithoutConstructor();
        $uninitialized->prePersist();

        self::assertInstanceOf(\DateTimeImmutable::class, $uninitialized->getCreatedAt());
    }

    #[Group('low-value')]
    public function testMediaAccessorsRoundTrip(): void
    {
        $media = new Media('old.png', 'old-original.png', 'image/png', 1, '/uploads/old.png');

        $media
            ->setFilename('new.png')
            ->setOriginalFilename('new-original.png')
            ->setMimeType('image/jpeg')
            ->setSize(2)
            ->setPath('/uploads/new.png')
            ->setStorage('qiniu');

        self::assertSame('new.png', $media->getFilename());
        self::assertSame('new-original.png', $media->getOriginalFilename());
        self::assertSame('image/jpeg', $media->getMimeType());
        self::assertSame(2, $media->getSize());
        self::assertSame('/uploads/new.png', $media->getPath());
        self::assertSame('qiniu', $media->getStorage());
        self::assertNotNull($media->getUpdatedAt());
    }

    /** @return array<string, mixed> */
    private function decodeResponse(string|false $content): array
    {
        self::assertIsString($content);

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    private function uploadedPng(string $name): UploadedFile
    {
        $source = $this->tempRoot . '/' . $name;
        file_put_contents($source, $this->pngBytes());

        return new UploadedFile($source, $name, 'image/png', null, true);
    }

    private function uploadedEmptyPng(string $name): UploadedFile
    {
        $source = $this->tempRoot . '/' . $name;
        touch($source);

        return new UploadedFile($source, $name, 'image/png', null, true);
    }

    private function uploadedPdf(string $name): UploadedFile
    {
        $source = $this->tempRoot . '/' . $name;
        file_put_contents($source, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        return new UploadedFile($source, $name, 'application/pdf', null, true);
    }

    private function uploadedText(string $name): UploadedFile
    {
        $source = $this->tempRoot . '/' . $name;
        file_put_contents($source, 'plain text');

        return new UploadedFile($source, $name, 'text/plain', null, true);
    }

    private function pngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            is_dir($itemPath) ? $this->removeDirectory($itemPath) : unlink($itemPath);
        }

        rmdir($path);
    }
}
