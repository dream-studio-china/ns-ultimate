<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate;

use App\Store\Entity\Product as CoreProduct;
use App\Store\Entity\Store;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use NsUltimate\Business\Store\Entity\Product;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * App-owned kitchen-print opt-in for one exact stored Product.
 *
 * Only Product.settings.kitchenPrinterEnable is writable. Every other Product
 * setting, including sale times and unknown stored keys, is preserved. This
 * adapter never submits a print job or contacts a printer provider.
 */
final class ProductPrintingMutationAdapter implements MutationAdapter
{
    private const FIELD = 'kitchenPrinterEnable';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {}

    public function entityKey(): string { return 'ProductPrinting'; }

    public function authorize(string $action, ?string $identifier): void
    {
        if (!$this->authorization->isGranted('ROLE_ADMIN')) throw new AccessDeniedException('Product printing management requires ROLE_ADMIN.');
        if ($action !== 'update') throw new BadRequestHttpException('Only Product printing configuration updates are supported.');
    }

    public function prepare(string $action, ?string $identifier, array $changes): array
    {
        $this->authorize($action, $identifier);
        $product = $this->find($identifier, false, false);
        if ($product->getIsDeleted()) throw new BadRequestHttpException('Deleted Products cannot be changed.');
        $this->assertScopeActive($product, false);
        $normalized = $this->normalize($changes);
        $settings = $this->settingsOf($product, false);
        $current = $this->currentValue($settings, false);
        if ($current === $normalized[self::FIELD]) {
            throw new BadRequestHttpException('The proposed Product printing update contains no effective changes.');
        }
        $merged = $settings;
        $merged[self::FIELD] = $normalized[self::FIELD];
        $this->validateCandidate($product, $merged, false);
        return [
            'identifier' => (string) $product->getId(),
            'title' => $this->title($product),
            'beforeHash' => $this->hash($this->snapshot($product, $settings)),
            'changes' => $normalized,
            'differences' => [[
                'property' => self::FIELD,
                'before' => array_key_exists(self::FIELD, $settings) ? $current : null,
                'after' => $normalized[self::FIELD],
            ]],
        ];
    }

    public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array
    {
        $this->authorize($action, $identifier);
        try {
            $product = $this->find($identifier, true, true);
            try { $this->em->refresh($product, LockMode::PESSIMISTIC_WRITE); }
            catch (EntityNotFoundException $error) { throw new ProposalConflictException('Product no longer exists. Prepare a new preview.', $error); }
            if ($product->getIsDeleted()) throw new BadRequestHttpException('Deleted Products cannot be changed.');
            $this->lockRelated($product);
            $this->assertScopeActive($product, true);
            $normalized = $this->normalize($changes);
            $settings = $this->settingsOf($product, true);
            $current = $this->currentValue($settings, true);
            if ($current === $normalized[self::FIELD]) {
                throw new BadRequestHttpException('The proposed Product printing update contains no effective changes.');
            }
            if (!hash_equals($beforeHash, $this->hash($this->snapshot($product, $settings)))) {
                throw new ProposalConflictException('Product printing settings changed since preview. Prepare a new preview.');
            }
            $merged = $settings;
            $merged[self::FIELD] = $normalized[self::FIELD];
            $this->validateCandidate($product, $merged, true);
        } catch (BadRequestHttpException|\InvalidArgumentException $error) {
            throw new ProposalConflictException('Product printing proposal is no longer valid. Prepare a new preview.', $error);
        }

        $product->setSettings($merged);
        $expected = $this->snapshot($product, $merged);
        $this->em->flush();
        $this->em->refresh($product);
        $actual = $this->snapshot($product, $this->settingsOf($product, true));
        return ['identifier' => (string) $product->getId(), 'verified' => $this->canonicalize($actual) === $this->canonicalize($expected)];
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, bool>
     */
    private function normalize(array $changes): array
    {
        if ($changes === [] || array_is_list($changes) || array_diff(array_keys($changes), [self::FIELD]) !== []
            || !is_bool($changes[self::FIELD] ?? null)) {
            throw new BadRequestHttpException('Only a boolean kitchenPrinterEnable change is supported.');
        }
        return [self::FIELD => $changes[self::FIELD]];
    }

    /** @return array<string, mixed> */
    private function settingsOf(Product $product, bool $commit): array
    {
        try { $settings = $product->getSettings(); }
        catch (\TypeError|ConversionException $error) {
            throw $this->invalid($commit, 'Stored Product settings are malformed. Use the standard editor.', $error);
        }
        if ($settings === null) return [];
        if ($settings !== [] && array_is_list($settings)) {
            throw $this->invalid($commit, 'Stored Product settings are malformed. Use the standard editor.');
        }
        return $settings;
    }

    /** @param array<string, mixed> $settings */
    private function currentValue(array $settings, bool $commit): bool
    {
        if (!array_key_exists(self::FIELD, $settings)) return false;
        if (!is_bool($settings[self::FIELD])) {
            throw $this->invalid($commit, 'Stored Product kitchenPrinterEnable is malformed. Use the standard editor.');
        }
        return $settings[self::FIELD];
    }

    /** @param array<string, mixed> $settings */
    private function validateCandidate(Product $product, array $settings, bool $commit): void
    {
        $candidate = clone $product;
        $candidate->setSettings($settings);
        $violations = $this->validator->validate($candidate);
        if (count($violations) > 0) {
            throw $this->invalid($commit, 'Product settings are invalid: '.(string) $violations);
        }
    }

    private function assertScopeActive(Product $product, bool $commit): void
    {
        $store = $product->getStore();
        if ($store === null || $store->isActive()) return;
        if ($commit) throw new ProposalConflictException('Product Store is no longer active. Prepare a new preview.');
        throw new BadRequestHttpException('Product Store must be active to change printing settings.');
    }

    private function lockRelated(Product $product): void
    {
        $entities = [];
        $store = $product->getStore();
        if ($store instanceof Store) $entities[spl_object_id($store)] = $store;
        $category = $product->getCategory();
        if ($category !== null) $entities[spl_object_id($category)] = $category;
        $list = array_values($entities);
        usort($list, static fn(object $left, object $right): int => [get_class($left), (int) $left->getId()] <=> [get_class($right), (int) $right->getId()]);
        foreach ($list as $entity) {
            try {
                $this->em->lock($entity, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($entity, LockMode::PESSIMISTIC_WRITE);
            } catch (EntityNotFoundException $error) {
                throw new ProposalConflictException('A related Product scope record changed. Prepare a new preview.', $error);
            }
        }
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function snapshot(Product $product, array $settings): array
    {
        $store = $product->getStore();
        $category = $product->getCategory();
        return [
            'product' => ['id' => $product->getId(), 'uuid' => $product->getUuid(), 'isDeleted' => $product->getIsDeleted(), 'settings' => $settings],
            'scope' => [
                'store' => $store === null ? null : ['uuid' => $store->getUuid(), 'status' => $store->getStatus()],
                'category' => $category === null ? null : ['id' => $category->getId(), 'uuid' => $category->getUuid(),
                    'enabled' => $category->isEnabled(), 'storeUuid' => $category->getStore()?->getUuid()],
            ],
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($this->canonicalize($snapshot), JSON_THROW_ON_ERROR));
    }

    private function title(Product $product): string
    {
        return sprintf(
            '[ProductPrinting; Product #%s; Store %s] Changes FUTURE automatic kitchen-list printing for orders processed after saving; it does not print anything now or create a print job.',
            $product->getId() ?? 'new',
            $product->getStore()?->getUuid() ?? 'global',
        );
    }

    private function invalid(bool $commit, string $message, ?\Throwable $previous = null): \Throwable
    {
        return $commit ? new ProposalConflictException($message, $previous) : new BadRequestHttpException($message, $previous);
    }

    private function find(?string $identifier, bool $lock, bool $commit): Product
    {
        if ($identifier === null || preg_match('/^(?:[1-9][0-9]{0,9}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/iD', $identifier) !== 1
            || (ctype_digit($identifier) && (int) $identifier > 2147483647)) {
            throw new BadRequestHttpException('An exact signed 32-bit numeric Product ID or UUID is required.');
        }
        try {
            $product = ctype_digit($identifier)
                ? $this->em->find(CoreProduct::class, (int) $identifier, $lock ? LockMode::PESSIMISTIC_WRITE : null)
                : $this->em->getRepository(CoreProduct::class)->findOneBy(['uuid' => strtolower($identifier)]);
        } catch (ConversionException|\TypeError $error) {
            throw $this->invalid($commit, 'Stored Product settings are malformed. Use the standard editor.', $error);
        }
        if (!$product instanceof Product) {
            if ($commit) throw new ProposalConflictException('Product no longer exists. Prepare a new preview.');
            throw new NotFoundHttpException('Product not found.');
        }
        return $product;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map($this->canonicalize(...), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
