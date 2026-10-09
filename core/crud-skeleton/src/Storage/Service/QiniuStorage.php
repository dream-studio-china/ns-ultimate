<?php

declare(strict_types=1);

namespace App\Storage\Service;

use App\Common\Repository\SettingRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class QiniuStorage implements MediaStorageInterface
{
    public function __construct(private SettingRepository $settings) {}

    public static function getName(): string
    {
        return 'qiniu';
    }

    public function store(UploadedFile $file, string $name): string
    {
        $config = $this->config();
        $this->assertSdkInstalled();

        $authClass = 'Qiniu\\Auth';
        $uploadManagerClass = 'Qiniu\\Storage\\UploadManager';

        $auth = new $authClass($config['accessKey'], $config['secretKey']);
        $token = $auth->uploadToken($config['bucket']);
        $uploadManager = new $uploadManagerClass();
        [$result, $error] = $uploadManager->putFile($token, $name, $file->getPathname());

        if ($error !== null) {
            throw new \RuntimeException('Qiniu upload failed: ' . $error->message());
        }

        $key = is_array($result) && isset($result['key']) ? $result['key'] : $name;

        return rtrim($config['domain'], '/') . '/' . ltrim($key, '/');
    }

    public function delete(string $path): void
    {
        $config = $this->config();
        $this->assertSdkInstalled();

        $key = ltrim(str_replace(rtrim($config['domain'], '/') . '/', '', $path), '/');
        if ($key === '') {
            return;
        }

        $authClass = 'Qiniu\\Auth';
        $bucketManagerClass = 'Qiniu\\Storage\\BucketManager';

        $auth = new $authClass($config['accessKey'], $config['secretKey']);
        $bucketManager = new $bucketManagerClass($auth);
        $error = $bucketManager->delete($config['bucket'], $key);

        if ($error !== null) {
            throw new \RuntimeException('Qiniu delete failed: ' . $error->message());
        }
    }

    /** @return array{accessKey:string, secretKey:string, bucket:string, domain:string} */
    private function config(): array
    {
        $config = [
            'accessKey' => $this->setting('qiniu.access_key'),
            'secretKey' => $this->setting('qiniu.secret_key'),
            'bucket' => $this->setting('qiniu.bucket'),
            'domain' => $this->setting('qiniu.domain'),
        ];

        if (in_array('', $config, true)) {
            throw new \RuntimeException('Qiniu storage is not configured.');
        }

        return $config;
    }

    private function setting(string $key): string
    {
        return trim((string) $this->settings->findByKey($key)?->getValue());
    }

    private function assertSdkInstalled(): void
    {
        if (!class_exists('Qiniu\\Auth') || !class_exists('Qiniu\\Storage\\UploadManager') || !class_exists('Qiniu\\Storage\\BucketManager')) {
            throw new \RuntimeException('Qiniu PHP SDK is not installed. Install qiniu/php-sdk to use qiniu storage.');
        }
    }
}
