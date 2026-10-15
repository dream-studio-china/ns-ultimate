<?php

declare(strict_types=1);

namespace App\Tests\Integration\Store;

use App\Store\Entity\Product;
use App\Store\Entity\ProductCategory;
use App\Store\Entity\Specification;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

final class ProductExpansionApiTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(Product::class)->findBy(['name' => ['Expansion product A', 'Expansion product B']]) as $product) {
            $em->remove($product);
        }
        $em->flush();
        foreach (['expansion-category', 'expansion-parent'] as $slug) {
            $existing = $em->getRepository(ProductCategory::class)->findOneBy(['slug' => $slug]);
            if ($existing !== null) {
                $em->remove($existing);
                $em->flush();
            }
        }
        $parent = new ProductCategory('Expansion parent', 'expansion-parent');
        $category = (new ProductCategory('Expansion category', 'expansion-category'))->setParent($parent);
        $em->persist($parent);
        $em->persist($category);
        foreach (['Expansion product A', 'Expansion product B'] as $name) {
            $product = (new Product())->setName($name)->setCategory($category);
            foreach ([1200, 2400] as $price) {
                $specification = (new Specification())->setName('Size '.$price)->setPrice($price);
                $product->addSpecification($specification);
            }
            $em->persist($product);
        }
        $em->flush();
        self::ensureKernelShutdown();
    }

    public static function expansionFormats(): iterable
    {
        yield 'JSON' => ['["specifications","category"]'];
        yield 'single quotes' => ["['specifications','category']"];
        yield 'comma separated' => ['specifications,category'];
        yield 'nested and circular' => ['specifications.product.specifications,category.parent'];
    }

    #[DataProvider('expansionFormats')]
    public function testExpandedRelationsContainFieldsInFinalJson(string $expands): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/app/products', [
            '@expands' => $expands,
            'limit' => 100,
        ]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $products = array_values(array_filter($body['data'], static fn (array $product): bool => str_starts_with($product['name'], 'Expansion product')));
        self::assertCount(2, $products);
        foreach ($products as $product) {
            self::assertSame('Expansion category', $product['category']['__metadata']['name'] ?? null);
            self::assertSame('expansion-category', $product['category']['__metadata']['slug'] ?? null);
            self::assertCount(2, $product['specifications']);
            $prices = array_column(array_column($product['specifications'], '__metadata'), 'price');
            sort($prices);
            self::assertSame([1200, 2400], $prices);
            foreach ($product['specifications'] as $specification) {
                self::assertSame('Size '.$specification['__metadata']['price'], $specification['__metadata']['name']);
            }
            if (str_contains($expands, 'category.parent')) {
                self::assertSame('Expansion parent', $product['category']['__metadata']['parent']['__metadata']['name'] ?? null);
            }
        }
    }

    public function testUnexpandedRelationsRemainReferences(): void
    {
        $client = static::createAuthenticatedClient();
        $client->request('GET', '/api/v1/app/products', ['limit' => 100]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $products = array_values(array_filter($body['data'], static fn (array $product): bool => str_starts_with($product['name'], 'Expansion product')));
        self::assertCount(2, $products);
        foreach ($products as $product) {
            self::assertArrayNotHasKey('__metadata', $product['category']);
            foreach ($product['specifications'] as $specification) {
                self::assertArrayNotHasKey('__metadata', $specification);
            }
        }
    }

    public function testDetailExpandsFieldsAndPreservesEmptyRelations(): void
    {
        $client = static::createAuthenticatedClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $product = $em->getRepository(Product::class)->findOneBy(['name' => 'Expansion product A']);
        self::assertInstanceOf(Product::class, $product);
        $productUuid = $product->getUuid();
        $emptyProduct = (new Product())->setName('Empty expansion fixture');
        $em->persist($emptyProduct);
        $em->flush();
        $emptyUuid = $emptyProduct->getUuid();
        $em->clear();

        $client->request('GET', '/api/v1/app/products/'.$productUuid, ['@expands' => 'specifications,category']);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Expansion category', $body['data']['category']['__metadata']['name'] ?? null);
        $prices = array_column(array_column($body['data']['specifications'], '__metadata'), 'price');
        sort($prices);
        self::assertSame([1200, 2400], $prices);

        $client->request('GET', '/api/v1/app/products/'.$emptyUuid, ['@expands' => 'specifications,category']);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNull($body['data']['category']);
        self::assertSame([], $body['data']['specifications']);
    }
}
