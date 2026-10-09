<?php

declare(strict_types=1);

namespace App\Tests\LowValue\Common\Controller;


use PHPUnit\Framework\Attributes\Group;
use App\Common\Controller\App\MediaController as AppMediaController;
use App\Common\Controller\Manage\MediaController as ManageMediaController;
use App\Common\Entity\Media;
use App\Common\Service\MediaServiceInterface;
use App\Identity\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Group('low-value')]
final class MediaControllerUploadTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir() . '/crud-media-controller-test-' . bin2hex(random_bytes(4));
        mkdir($this->tempRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempRoot . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->tempRoot)) {
            rmdir($this->tempRoot);
        }
    }

    public function testAppUploadReturns500ForUnexpectedError(): void
    {
        $service = new class implements MediaServiceInterface {
            public function createFromUpload(UploadedFile $file, ?string $storage = null, array $meta = [], ?User $owner = null): Media
            {
                throw new \LogicException('unexpected app failure');
            }

            public function get($object, bool $directly = false) { return null; }
            public function list($object = null, $order = null, bool $disableRequest = true) { return []; }
            public function new() { return new \stdClass(); }
            public function update($object, ?array $data = null, bool $noFlush = false) { return $object; }
            public function remove($object): bool { return false; }
            public function wrapInTransaction(callable $fn): mixed { return $fn(null); }
        };

        $controller = new class($service) extends AppMediaController {
            protected function uploadOwner(): ?User
            {
                return null;
            }
        };
        $this->configureController($controller);

        $response = $controller->uploadAction($this->requestWithFile());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('unexpected app failure', $data['message']);
    }

    public function testManageUploadReturns500ForUnexpectedError(): void
    {
        $service = $this->createMock(MediaServiceInterface::class);
        $service->method('createFromUpload')->willThrowException(
            new \LogicException('unexpected manage failure')
        );

        $controller = new ManageMediaController($service);
        $this->configureController($controller);

        $response = $controller->uploadAction($this->requestWithFile());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('unexpected manage failure', $data['message']);
    }

    private function configureController(object $controller): void
    {
        $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
        $translator = new class implements TranslatorInterface {
            public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return strtr((string) $id, $parameters);
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };

        $controller->setSerializer($serializer);
        $controller->setTranslator($translator);
    }

    private function requestWithFile(): Request
    {
        $path = $this->tempRoot . '/upload.png';
        file_put_contents($path, 'png');

        return new Request(files: ['file' => new UploadedFile($path, 'upload.png', 'image/png', null, true)]);
    }
}
