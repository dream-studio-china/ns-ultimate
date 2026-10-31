<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Service;

use App\Store\Entity\ProductCategory;
use App\Store\Entity\Specification;
use App\Store\Entity\Store;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Store\Entity\Product;

final class ProductCatalogSyncService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return array<string, mixed> */
    public function preview(string $sourceStoreUuid, string $targetStoreUuid): array
    {
        [$source, $target] = $this->resolveStores($sourceStoreUuid, $targetStoreUuid);
        $plan = $this->buildPlan($source, $target);

        return $this->summary($source, $target, $plan, false);
    }

    /** @return array<string, mixed> */
    public function sync(string $sourceStoreUuid, string $targetStoreUuid): array
    {
        [$source, $target] = $this->resolveStores($sourceStoreUuid, $targetStoreUuid);

        return $this->entityManager->wrapInTransaction(function () use ($source, $target): array {
            if (!$this->entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
                $this->entityManager->lock($target, LockMode::PESSIMISTIC_WRITE);
            }

            // Rebuild the plan inside the transaction so rows added after preview
            // are still protected by the same-name rule.
            $plan = $this->buildPlan($source, $target);
            $categoryMap = $this->applyCategories($plan, $target);
            $specificationCount = $this->applyProducts($plan, $target, $categoryMap);

            return $this->summary($source, $target, $plan, true, $specificationCount);
        });
    }

    /** @return array{0: Store, 1: Store} */
    private function resolveStores(string $sourceStoreUuid, string $targetStoreUuid): array
    {
        $sourceStoreUuid = trim($sourceStoreUuid);
        $targetStoreUuid = trim($targetStoreUuid);
        if ($sourceStoreUuid === '' || $targetStoreUuid === '') {
            throw new \InvalidArgumentException('Source and target stores are required.');
        }
        if ($sourceStoreUuid === $targetStoreUuid) {
            throw new \InvalidArgumentException('Source and target stores must be different.');
        }

        $repository = $this->entityManager->getRepository(Store::class);
        $source = $repository->findOneBy(['uuid' => $sourceStoreUuid]);
        $target = $repository->findOneBy(['uuid' => $targetStoreUuid]);
        if (!$source instanceof Store || !$target instanceof Store) {
            throw new \InvalidArgumentException('Source or target store was not found.');
        }

        return [$source, $target];
    }

    /**
     * @return array{
     *   sourceCategories: list<ProductCategory>,
     *   categoryCreates: list<array{category: ProductCategory, slug: string}>,
     *   categoryMap: array<int, ProductCategory>,
     *   categoryAliases: array<int, int>,
     *   skippedCategoryNames: list<string>,
     *   productCreates: list<Product>,
     *   skippedProductNames: list<string>
     * }
     */
    private function buildPlan(Store $source, Store $target): array
    {
        $sourceCategories = $this->orderedSourceCategories($source);
        $categoriesByName = $this->categoriesByName($target);
        $reservedSlugs = $this->categorySlugs($target);
        $categoryMap = [];
        $categoryAliases = [];
        $categoryCreates = [];
        $skippedCategoryNames = [];

        foreach ($sourceCategories as $category) {
            $nameKey = $this->nameKey($category->getName());
            if (isset($categoriesByName[$nameKey])) {
                $match = $categoriesByName[$nameKey];
                if ($match instanceof ProductCategory) {
                    $categoryMap[(int) $category->getId()] = $match;
                } else {
                    $categoryAliases[(int) $category->getId()] = (int) $match->getId();
                }
                $skippedCategoryNames[] = $category->getName();
                continue;
            }

            $slug = $this->uniqueSlug($category->getSlug(), $reservedSlugs);
            $categoryCreates[] = ['category' => $category, 'slug' => $slug];
            // This also makes duplicate source names resolve to the first copy.
            $categoriesByName[$nameKey] = $category;
        }

        $productsByName = $this->productsByName($target);
        $productCreates = [];
        $skippedProductNames = [];
        foreach ($this->sourceProducts($source) as $product) {
            $nameKey = $this->nameKey($product->getName());
            if (isset($productsByName[$nameKey])) {
                $skippedProductNames[] = $product->getName();
                continue;
            }
            $productsByName[$nameKey] = true;
            $productCreates[] = $product;
        }

        return [
            'sourceCategories' => $sourceCategories,
            'categoryCreates' => $categoryCreates,
            'categoryMap' => $categoryMap,
            'categoryAliases' => $categoryAliases,
            'skippedCategoryNames' => $skippedCategoryNames,
            'productCreates' => $productCreates,
            'skippedProductNames' => $skippedProductNames,
        ];
    }

    /** @return list<ProductCategory> */
    private function orderedSourceCategories(Store $source): array
    {
        $categories = $this->entityManager->getRepository(ProductCategory::class)->findBy(
            ['store' => $source],
            ['sortOrder' => 'ASC', 'id' => 'ASC'],
        );
        $byId = [];
        foreach ($categories as $category) {
            $byId[(int) $category->getId()] = $category;
        }

        $ordered = [];
        $state = [];
        $visit = function (ProductCategory $category) use (&$visit, &$ordered, &$state, $byId, $source): void {
            $id = (int) $category->getId();
            if (($state[$id] ?? 0) === 2) {
                return;
            }
            if (($state[$id] ?? 0) === 1) {
                throw new \LogicException('Product category hierarchy contains a cycle.');
            }
            $state[$id] = 1;
            $parent = $category->getParent();
            if ($parent !== null && $parent->getStore()?->getUuid() === $source->getUuid()
                && isset($byId[(int) $parent->getId()])) {
                $visit($byId[(int) $parent->getId()]);
            }
            $state[$id] = 2;
            $ordered[] = $category;
        };
        foreach ($categories as $category) {
            $visit($category);
        }

        return $ordered;
    }

    /** @return array<string, ProductCategory> */
    private function categoriesByName(Store $target): array
    {
        $targetCategories = $this->entityManager->getRepository(ProductCategory::class)->findBy(
            ['store' => $target],
            ['id' => 'ASC'],
        );
        $globalCategories = $this->entityManager->getRepository(ProductCategory::class)->findBy(
            ['store' => null],
            ['id' => 'ASC'],
        );

        $byName = [];
        foreach ([...$targetCategories, ...$globalCategories] as $category) {
            $key = $this->nameKey($category->getName());
            if (!isset($byName[$key])) {
                $byName[$key] = $category;
            }
        }

        return $byName;
    }

    /** @return array<string, true> */
    private function categorySlugs(Store $target): array
    {
        $slugs = [];
        foreach ($this->entityManager->getRepository(ProductCategory::class)->findBy(['store' => $target]) as $category) {
            $slugs[strtolower($category->getSlug())] = true;
        }

        return $slugs;
    }

    /** @return array<string, true> */
    private function productsByName(Store $target): array
    {
        $queryBuilder = $this->entityManager->getRepository(Product::class)->createQueryBuilder('product')
            ->where('product.store = :target OR product.store IS NULL')
            ->setParameter('target', $target);

        $byName = [];
        foreach ($queryBuilder->getQuery()->getResult() as $product) {
            $byName[$this->nameKey($product->getName())] = true;
        }

        return $byName;
    }

    /** @return list<Product> */
    private function sourceProducts(Store $source): array
    {
        return $this->entityManager->getRepository(Product::class)->findBy(
            ['store' => $source, 'isDeleted' => false],
            ['id' => 'ASC'],
        );
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<int, ProductCategory>
     */
    private function applyCategories(array $plan, Store $target): array
    {
        $categoryMap = $plan['categoryMap'];
        $categoryAliases = $plan['categoryAliases'];
        foreach ($plan['categoryCreates'] as $entry) {
            /** @var ProductCategory $sourceCategory */
            $sourceCategory = $entry['category'];
            $category = (new ProductCategory($sourceCategory->getName(), $entry['slug']))
                ->setStore($target)
                ->setDescription($sourceCategory->getDescription())
                ->setSortOrder($sourceCategory->getSortOrder())
                ->setEnabled($sourceCategory->isEnabled());

            $sourceParent = $sourceCategory->getParent();
            if ($sourceParent !== null
                && $sourceParent->getStore()?->getUuid() === $sourceCategory->getStore()?->getUuid()
                && isset($categoryMap[(int) $sourceParent->getId()])) {
                $category->setParent($categoryMap[(int) $sourceParent->getId()]);
            }

            $this->entityManager->persist($category);
            $categoryMap[(int) $sourceCategory->getId()] = $category;
            foreach ($categoryAliases as $aliasId => $firstSourceId) {
                if (isset($categoryMap[$firstSourceId])) {
                    $categoryMap[$aliasId] = $categoryMap[$firstSourceId];
                }
            }
        }
        $this->entityManager->flush();

        return $categoryMap;
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<int, ProductCategory> $categoryMap
     */
    private function applyProducts(array $plan, Store $target, array $categoryMap): int
    {
        $specificationCount = 0;
        foreach ($plan['productCreates'] as $sourceProduct) {
            /** @var Product $sourceProduct */
            $product = new Product($target);
            $product->setName($sourceProduct->getName());
            $product->setDescription($sourceProduct->getDescription());
            $product->setStatus($sourceProduct->getStatus());
            $product->setMetadata($sourceProduct->getMetadata());
            $product->setSortOrder($sourceProduct->getSortOrder());
            $product->setRecommendationLevel($sourceProduct->getRecommendationLevel());
            $product->setIsSoldout($sourceProduct->getIsSoldout());
            $product->setSettings($sourceProduct->getSettings());

            $sourceCategory = $sourceProduct->getCategory();
            if ($sourceCategory !== null) {
                if ($sourceCategory->getStore() === null) {
                    $product->setCategory($sourceCategory);
                } elseif (isset($categoryMap[(int) $sourceCategory->getId()])) {
                    $product->setCategory($categoryMap[(int) $sourceCategory->getId()]);
                }
            }

            foreach ($sourceProduct->getSpecifications() as $sourceSpecification) {
                if ($sourceSpecification->getIsDeleted()) {
                    continue;
                }
                $specification = (new Specification())
                    ->setName($sourceSpecification->getName())
                    ->setPrice($sourceSpecification->getPrice())
                    ->setStatus($sourceSpecification->getStatus())
                    ->setSort($sourceSpecification->getSort());
                $product->addSpecification($specification);
                ++$specificationCount;
            }

            $this->entityManager->persist($product);
        }
        $this->entityManager->flush();

        return $specificationCount;
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function summary(Store $source, Store $target, array $plan, bool $applied, int $specificationCount = 0): array
    {
        $categoryCreates = count($plan['categoryCreates']);
        $productCreates = count($plan['productCreates']);

        return [
            'sourceStore' => ['uuid' => $source->getUuid(), 'name' => $source->getName()],
            'targetStore' => ['uuid' => $target->getUuid(), 'name' => $target->getName()],
            'categories' => [
                $applied ? 'created' : 'toCreate' => $categoryCreates,
                'skipped' => count($plan['skippedCategoryNames']),
                'skippedNames' => array_values(array_unique($plan['skippedCategoryNames'])),
            ],
            'products' => [
                $applied ? 'created' : 'toCreate' => $productCreates,
                'skipped' => count($plan['skippedProductNames']),
                'skippedNames' => array_values(array_unique($plan['skippedProductNames'])),
                'specificationsCreated' => $applied ? $specificationCount : null,
            ],
        ];
    }

    private function nameKey(string $name): string
    {
        return strtolower(trim($name));
    }

    /** @param array<string, true> $reservedSlugs */
    private function uniqueSlug(string $sourceSlug, array &$reservedSlugs): string
    {
        $base = trim($sourceSlug) !== '' ? trim($sourceSlug) : 'category';
        $candidate = $base;
        if (!isset($reservedSlugs[strtolower($candidate)])) {
            $reservedSlugs[strtolower($candidate)] = true;
            return $candidate;
        }

        $base = $this->truncateSlug($base, 90).'-sync';
        $candidate = $base;
        for ($index = 2; isset($reservedSlugs[strtolower($candidate)]); ++$index) {
            $candidate = $base.'-'.$index;
        }
        $reservedSlugs[strtolower($candidate)] = true;

        return $candidate;
    }

    private function truncateSlug(string $slug, int $length): string
    {
        return preg_match('/^.{1,'.$length.'}/us', $slug, $matches) === 1 ? $matches[0] : 'category';
    }
}
