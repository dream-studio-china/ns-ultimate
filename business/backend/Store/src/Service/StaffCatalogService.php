<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Service;

use App\Core\Utils\UUID;
use App\Store\Entity\Product as CoreProduct;
use App\Store\Entity\ProductCategory;
use App\Store\Entity\Store;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Store\Entity\CatalogImage;
use NsUltimate\Business\Store\Entity\Product;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class StaffCatalogService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /** @return array<string, mixed> */
    public function product(CoreProduct $product): array
    {
        $metadata = $product->getMetadata() ?? [];
        $settings = $product instanceof Product ? ($product->getSettings() ?? []) : [];
        $result = ['uuid' => $product->getUuid(), 'name' => $product->getName(), 'description' => $product->getDescription(),
            'status' => $product->getStatus(), 'category' => $product->getCategory() ? ['uuid' => $product->getCategory()->getUuid()] : null,
            'metadata' => ['subtitle' => $metadata['subtitle'] ?? null, 'cover' => $metadata['cover'] ?? null],
            'isSoldout' => $product instanceof Product && $product->isSoldout(),
            'sortOrder' => $product instanceof Product ? $product->getSortOrder() : 0,
            'recommendationLevel' => $product instanceof Product ? $product->getRecommendationLevel() : 0,
            'settings' => ['kitchenPrinterEnable' => $settings['kitchenPrinterEnable'] ?? false,
                'startSaleTime' => $settings['startSaleTime'] ?? '', 'endSaleTime' => $settings['endSaleTime'] ?? ''],
            'extensionEditable' => $product instanceof Product];
        $result['version'] = StaffCommandService::version($result + ['storedMetadata' => $metadata, 'storedSettings' => $settings,
            'updatedAt' => $product->getUpdatedAt()?->format(DATE_ATOM)]);
        return $result;
    }

    public function find(Store $store, string $uuid): CoreProduct
    {
        $product = $this->em->getRepository(CoreProduct::class)->findOneBy(['uuid' => $uuid, 'store' => $store, 'isDeleted' => false]);
        if (!$product instanceof CoreProduct) throw new HttpException(404, 'product_not_found');
        $this->em->refresh($product, LockMode::PESSIMISTIC_WRITE);
        return $product;
    }

    /** @param array<string, mixed> $data
     * @return array{before: array<string, mixed>, result: array<string, mixed>} */
    public function save(Store $store, ?string $uuid, array $data): array
    {
        $allowed = ['commandUuid', 'version', 'name', 'description', 'status', 'category', 'subtitle', 'coverImageUuid',
            'sortOrder', 'recommendationLevel', 'isSoldout', 'kitchenPrinterEnable', 'startSaleTime', 'endSaleTime'];
        if (array_diff(array_keys($data), $allowed)) throw new HttpException(422, 'unknown_field');
        $product = $uuid === null ? new Product($store) : $this->find($store, $uuid);
        $before = $uuid === null ? [] : $this->product($product);
        if ($uuid !== null) StaffCommandService::expectVersion($data, $before['version']);
        foreach (['name' => 60, 'description' => 5000, 'subtitle' => 255] as $key => $max) {
            if (array_key_exists($key, $data) && (!is_string($data[$key]) || mb_strlen($data[$key]) > $max)) throw new HttpException(422, 'invalid_field');
        }
        if (($uuid === null && !isset($data['name'])) || (isset($data['name']) && trim($data['name']) === '')) throw new HttpException(422, 'invalid_name');
        if (array_key_exists('status', $data) && !in_array($data['status'], ['active', 'inactive'], true)) throw new HttpException(422, 'invalid_status');
        foreach (['sortOrder' => 1000000, 'recommendationLevel' => 100] as $key => $max) {
            if (array_key_exists($key, $data) && (!is_int($data[$key]) || $data[$key] < 0 || $data[$key] > $max)) throw new HttpException(422, 'invalid_field');
        }
        foreach (['isSoldout', 'kitchenPrinterEnable'] as $key) {
            if (array_key_exists($key, $data) && !is_bool($data[$key])) throw new HttpException(422, 'invalid_field');
        }
        foreach (['startSaleTime', 'endSaleTime'] as $key) {
            if (array_key_exists($key, $data) && (!is_string($data[$key]) || !preg_match('/^(?:(?:[01][0-9]|2[0-3]):[0-5][0-9])?$/D', $data[$key]))) throw new HttpException(422, 'invalid_sale_window');
        }
        $settings = $product instanceof Product ? ($product->getSettings() ?? []) : [];
        $start = $data['startSaleTime'] ?? $settings['startSaleTime'] ?? '';
        $end = $data['endSaleTime'] ?? $settings['endSaleTime'] ?? '';
        if (($start === '') !== ($end === '') || ($start !== '' && $start === $end)) throw new HttpException(422, 'invalid_sale_window');
        if (!($product instanceof Product) && array_intersect(array_keys($data), ['isSoldout', 'sortOrder', 'recommendationLevel', 'kitchenPrinterEnable', 'startSaleTime', 'endSaleTime'])) throw new HttpException(409, 'extension_unavailable');
        if (array_key_exists('category', $data)) {
            if ($data['category'] !== null && $data['category'] !== '' && (!is_string($data['category']) || !UUID::is_valid($data['category']))) throw new HttpException(422, 'invalid_category');
            $category = $data['category'] === null || $data['category'] === '' ? null : $this->em->getRepository(ProductCategory::class)->findOneBy(['uuid' => $data['category']]);
            if (($data['category'] !== null && $data['category'] !== '' && !$category instanceof ProductCategory)
                || ($category instanceof ProductCategory && ((!$category->isEnabled() && $product->getCategory()?->getUuid() !== $category->getUuid()) || ($category->getStore() !== null && $category->getStore()->getUuid() !== $store->getUuid())))) throw new HttpException(422, 'invalid_category');
            $product->setCategory($category);
        }
        $metadata = $product->getMetadata() ?? [];
        if (array_key_exists('subtitle', $data)) $metadata['subtitle'] = trim($data['subtitle']);
        if (array_key_exists('coverImageUuid', $data)) {
            if ($data['coverImageUuid'] === null) $metadata['cover'] = '';
            else {
                if (!is_string($data['coverImageUuid'])) throw new HttpException(422, 'invalid_image');
                $image = $this->em->getRepository(CatalogImage::class)->findOneBy(['uuid' => $data['coverImageUuid'], 'storeUuid' => $store->getUuid()]);
                if (!$image instanceof CatalogImage) throw new HttpException(422, 'invalid_image');
                $metadata['cover'] = $image->getPath();
            }
        }
        if (isset($data['name'])) $product->setName(trim($data['name']));
        if (isset($data['description'])) $product->setDescription(trim($data['description']));
        if (isset($data['status'])) $product->setStatus($data['status']);
        $product->setMetadata($metadata ?: null);
        if ($product instanceof Product) {
            if (isset($data['sortOrder'])) $product->setSortOrder($data['sortOrder']);
            if (isset($data['recommendationLevel'])) $product->setRecommendationLevel($data['recommendationLevel']);
            if (isset($data['isSoldout'])) $product->setIsSoldout($data['isSoldout']);
            foreach (['kitchenPrinterEnable', 'startSaleTime', 'endSaleTime'] as $key) if (array_key_exists($key, $data)) $settings[$key] = $data[$key];
            $product->setSettings($settings ?: null);
        }
        $this->em->persist($product);
        return ['before' => $before, 'result' => $this->product($product)];
    }

    /** Explicit selected rows, never a misleading all-store batch based on one loaded page.
     * @param array<string, mixed> $data
     * @return array{before: array<string, mixed>, result: array<string, mixed>} */
    public function availability(Store $store, array $data): array
    {
        if (array_diff(array_keys($data), ['commandUuid', 'items', 'isSoldout']) || !is_bool($data['isSoldout'] ?? null)
            || !is_array($data['items'] ?? null) || !array_is_list($data['items']) || count($data['items']) < 1 || count($data['items']) > 30) throw new HttpException(422, 'invalid_batch');
        $items = $data['items'];
        $seen = [];
        $products = [];
        foreach ($items as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['uuid', 'version']) || !is_string($item['uuid'] ?? null) || isset($seen[$item['uuid']])) throw new HttpException(422, 'invalid_batch');
            $seen[$item['uuid']] = true;
            $product = $this->find($store, $item['uuid']);
            if (!$product instanceof Product) throw new HttpException(409, 'extension_unavailable');
            StaffCommandService::expectVersion($item, $this->product($product)['version']);
            $products[] = $product;
        }
        $before = ['items' => array_map($this->product(...), $products)];
        foreach ($products as $product) $product->setIsSoldout($data['isSoldout']);
        return ['before' => $before, 'result' => ['items' => array_map($this->product(...), $products)]];
    }
}
