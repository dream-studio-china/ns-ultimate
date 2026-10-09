<?php

declare(strict_types=1);

namespace App\Tests\Integration\Trade\Controller\Manage;

use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use App\Store\Entity\Product;
use App\Store\Entity\Specification;
use Doctrine\ORM\EntityManagerInterface;

final class SpecificationAllControllerTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->bootTestDatabase();

        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->em = $client->getContainer()->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM App\\Store\\Entity\\Specification')->execute();
        $this->em->createQuery('DELETE FROM App\\Store\\Entity\\Product')->execute();
        self::ensureKernelShutdown();
    }

    public function testCreateListDetailUpdateAndDelete(): void
    {
        $client = static::createAuthenticatedClient();
        $product = $this->createProduct();

        $client->request(
            'POST',
            '/api/v1/manage/specifications',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'name' => '256GB',
                'product' => $product->getId(),
                'price' => 25000,
                'status' => 'active',
                'sort' => 3,
            ]),
        );
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $created = $this->decode($client->getResponse()->getContent());
        self::assertSame('256GB', $created['data']['name']);
        self::assertSame(25000, $created['data']['price']);
        $id = $created['data']['id'];

        $client->request('GET', '/api/v1/manage/specifications');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $list = $this->decode($client->getResponse()->getContent());
        self::assertContains($id, array_column($list['data'], 'id'));

        $client->request('GET', '/api/v1/manage/specifications/' . $id);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $detail = $this->decode($client->getResponse()->getContent());
        self::assertSame($id, $detail['data']['id']);

        $client->request(
            'PUT',
            '/api/v1/manage/specifications/' . $id,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => '512GB', 'price' => 40000]),
        );
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $updated = $this->decode($client->getResponse()->getContent());
        self::assertSame('512GB', $updated['data']['name']);
        self::assertSame(40000, $updated['data']['price']);

        $client->request('DELETE', '/api/v1/manage/specifications/' . $id);
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $this->em->clear();
        self::assertNull($this->em->getRepository(Specification::class)->find($id));
    }

    public function testCreateRequiresNameProductAndPrice(): void
    {
        $client = static::createAuthenticatedClient();
        $product = $this->createProduct();

        $client->request(
            'POST',
            '/api/v1/manage/specifications',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['product' => $product->getId(), 'price' => 100]),
        );

        self::assertSame(400, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
    }

    public function testCreateRejectsUnknownProduct(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request(
            'POST',
            '/api/v1/manage/specifications',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'Ghost', 'product' => 999999, 'price' => 100]),
        );

        self::assertSame(404, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
    }

    public function testDetailReturns404WhenMissing(): void
    {
        $client = static::createAuthenticatedClient();

        $client->request('GET', '/api/v1/manage/specifications/999999');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    private function createProduct(): Product
    {
        $product = new Product();
        $product->setName('Gallery Product ' . bin2hex(random_bytes(4)));
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    /** @return array<string, mixed> */
    private function decode(string|false $content): array
    {
        self::assertIsString($content);

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
