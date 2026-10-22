<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate;

use App\Store\Entity\Product as CoreProduct;
use App\Store\Entity\ProductCategory;
use App\Store\Entity\Store;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use NsUltimate\Business\Store\Entity\Product;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Restricted Product binding; deliberately excludes deletion and specification cascades. */
final class ProductMutationAdapter implements MutationAdapter
{
    private const FIELDS = ['name', 'description', 'store', 'category', 'sortOrder', 'recommendationLevel', 'status', 'isSoldout'];

    public function __construct(private readonly EntityManagerInterface $em, private readonly ValidatorInterface $validator, private readonly AuthorizationCheckerInterface $authorization) {}
    public function entityKey(): string { return 'Product'; }

    public function authorize(string $action, ?string $identifier): void
    {
        if (!$this->authorization->isGranted('ROLE_ADMIN')) throw new AccessDeniedException('Product management requires ROLE_ADMIN.');
        if (!in_array($action, ['create', 'update'], true)) throw new BadRequestHttpException('Product supports create and update only; deletion is unavailable.');
    }

    public function prepare(string $action, ?string $identifier, array $changes): array
    {
        $this->authorize($action, $identifier);
        $creating = $action === 'create';
        $target = $creating ? null : $this->find($identifier);
        if ($target?->getIsDeleted()) throw new BadRequestHttpException('Deleted Products cannot be changed.');
        $before = $target === null ? null : $this->snapshot($target);
        $changes = $this->normalize($changes, $creating);
        // Never let preview mutate a managed domain object.
        $candidate = $creating ? new Product() : clone $target;
        $this->apply($candidate, $changes, $creating, false);
        $violations = $this->validator->validate($candidate);
        if (count($violations)) throw new BadRequestHttpException((string) $violations);
        $after = $this->snapshot($candidate);
        $diff = [];
        foreach (self::FIELDS as $field) if (array_key_exists($field, $changes) && ($creating || ($before[$field] ?? null) !== ($after[$field] ?? null))) $diff[] = ['property'=>$field, 'before'=>$before[$field] ?? null, 'after'=>$after[$field] ?? null];
        $beforeHash = $creating ? $this->hash(['scope'=>$this->scopeSnapshot($candidate)]) : $this->hash($before);
        return ['identifier'=>$target === null ? null : (string)$target->getId(), 'title'=>$this->title($candidate), 'beforeHash'=>$beforeHash, 'changes'=>$changes, 'differences'=>$diff];
    }

    public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array
    {
        $this->authorize($action, $identifier);
        $creating = $action === 'create';
        try { $target = $creating ? new Product() : $this->find($identifier, true); }
        catch (NotFoundHttpException $error) { throw new ProposalConflictException('Product no longer exists. Prepare a new preview.', $error); }
        if (!$creating) {
            $this->em->refresh($target, LockMode::PESSIMISTIC_WRITE);
            $this->refreshRelations($target);
            if (!hash_equals($beforeHash, $this->hash($this->snapshot($target)))) throw new ProposalConflictException('Product changed since preview. Prepare a new preview.');
        }
        try {
            $changes = $this->normalize($changes, $creating);
            $this->apply($target, $changes, $creating, true);
            if ($creating && !hash_equals($beforeHash, $this->hash(['scope'=>$this->scopeSnapshot($target)]))) throw new ProposalConflictException('Product scope changed since preview. Prepare a new preview.');
            if (count($v = $this->validator->validate($target))) throw new BadRequestHttpException((string)$v);
        } catch (BadRequestHttpException|\InvalidArgumentException $error) {
            throw new ProposalConflictException('Product proposal is no longer valid. Prepare a new preview.', $error);
        }
        $expected = $this->snapshot($target);
        $this->em->persist($target); $this->em->flush(); $this->em->refresh($target);
        $actual = $this->snapshot($target);
        unset($expected['id'], $actual['id']);
        return ['identifier'=>(string)$target->getId(), 'verified'=>$actual === $expected];
    }

    private function normalize(array $c, bool $create): array
    {
        if (!$c || array_diff(array_keys($c), self::FIELDS)) throw new BadRequestHttpException('Unsupported or empty Product changes.');
        if ($create && !isset($c['name'])) throw new BadRequestHttpException('Product creation requires name.');
        if ($create && !array_key_exists('store', $c)) $c['store'] = null;
        foreach ($c as $k=>$v) {
            $ok = match($k) {
                'name' => is_string($v) && trim($v) !== '' && mb_strlen($v) <= 255 && $v === trim($v) && !str_contains($v, '<'),
                'description' => $v === null || (is_string($v) && strlen($v) <= 65535 && !preg_match('/<\/?[a-z][^>]*>/i', $v)),
                'store' => $v === null || (is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $v)),
                'category' => $v === null || (is_int($v) && $v > 0 && $v <= 2147483647),
                'sortOrder','recommendationLevel' => is_int($v) && $v >= -2147483648 && $v <= 2147483647,
                'status' => in_array($v, ['active','inactive'], true), 'isSoldout' => is_bool($v), default => false,
            };
            if (!$ok) throw new BadRequestHttpException('Invalid Product field: '.$k.'.');
        }
        if ($create && is_string($c['store'])) $c['store'] = strtolower($c['store']);
        $canonical = [];
        foreach (self::FIELDS as $field) if (array_key_exists($field, $c)) $canonical[$field] = $c[$field];
        return $canonical;
    }

    private function apply(Product $p, array $c, bool $create, bool $lock): void
    {
        $store = $create ? null : $p->getStore();
        if (array_key_exists('store', $c)) {
            if (!$create) throw new BadRequestHttpException('Product store cannot be reassigned.');
            $store = $c['store'] === null ? null : $this->findStore($c['store'], $lock);
            $p->setStore($store);
        } elseif ($store !== null && $lock) {
            $this->lockAndRefresh($store);
        }
        foreach ($c as $k=>$v) switch($k) {
            case 'name': $p->setName($v); break;
            case 'description': $p->setDescription($v); break;
            case 'status': $p->setStatus($v); break;
            case 'sortOrder': $p->setSortOrder($v); break;
            case 'recommendationLevel': $p->setRecommendationLevel($v); break;
            case 'isSoldout': $p->setIsSoldout($v); break;
            case 'category':
                $category = $v === null ? null : $this->findCategory($v, $lock);
                if ($v !== null && (!$category->isEnabled() || $category->getStore()?->getId() !== $store?->getId())) throw new BadRequestHttpException('Category must be enabled and belong to the exact Product store scope (global for a global Product).');
                $p->setCategory($category); break;
        }
    }

    private function findStore(string $uuid, bool $lock): ?Store
    {
        $store=$this->em->getRepository(Store::class)->findOneBy(['uuid'=>$uuid]);
        if (!$store instanceof Store) throw new BadRequestHttpException('Store not found.');
        if ($lock) $this->lockAndRefresh($store);
        return $store;
    }
    private function findCategory(int $id, bool $lock): ProductCategory
    {
        $category=$this->em->find(ProductCategory::class,$id,$lock?LockMode::PESSIMISTIC_WRITE:null);
        if (!$category instanceof ProductCategory) throw new BadRequestHttpException('Category not found.');
        if ($lock) $this->em->refresh($category,LockMode::PESSIMISTIC_WRITE);
        return $category;
    }
    private function lockAndRefresh(Store $store): void
    {
        $this->em->lock($store,LockMode::PESSIMISTIC_WRITE);
        $this->em->refresh($store,LockMode::PESSIMISTIC_WRITE);
    }
    private function refreshRelations(Product $product): void
    {
        $store=$product->getStore();
        if ($store!==null) $this->lockAndRefresh($store);
        $category=$product->getCategory();
        if ($category!==null) $this->em->refresh($category,LockMode::PESSIMISTIC_WRITE);
    }
    private function find(?string $id, bool $lock=false): Product
    {
        if ($id === null || !preg_match('/^[1-9][0-9]{0,9}$/D', $id) || (int)$id > 2147483647) throw new BadRequestHttpException('An exact signed 32-bit numeric Product ID is required.');
        $p = $this->em->find(CoreProduct::class, (int)$id, $lock ? LockMode::PESSIMISTIC_WRITE : null);
        if (!$p instanceof Product) throw new NotFoundHttpException('Product not found.'); return $p;
    }
    private function snapshot(Product $p): array
    {
        $specifications = array_map(static fn($s)=>$s->getId(), $p->getSpecifications()->toArray());
        sort($specifications, SORT_NUMERIC);
        return ['id'=>$p->getId(),'uuid'=>$p->getUuid(),'storeId'=>$p->getStore()?->getId(),'store'=>$p->getStore()?->getUuid(),'category'=>$p->getCategory()?->getId(),'scope'=>$this->scopeSnapshot($p),
            'name'=>$p->getName(),'description'=>$p->getDescription(),'status'=>$p->getStatus(),'isDeleted'=>$p->getIsDeleted(),
            'metadata'=>$p->getMetadata(),'settings'=>$p->getSettings(),'sortOrder'=>$p->getSortOrder(),
            'recommendationLevel'=>$p->getRecommendationLevel(),'isSoldout'=>$p->isSoldout(),'specifications'=>$specifications];
    }
    private function scopeSnapshot(Product $product): array
    {
        $store=$product->getStore(); $category=$product->getCategory();
        return ['store'=>['uuid'=>$store?->getUuid(),'status'=>$store?->getStatus()],
            'category'=>['id'=>$category?->getId(),'uuid'=>$category?->getUuid(),'enabled'=>$category?->isEnabled(),
                'storeUuid'=>$category?->getStore()?->getUuid()]];
    }
    private function title(Product $product): string
    {
        $store=$product->getStore();
        return sprintf('[Product #%s; %s] %s', $product->getId() ?? 'new',
            $store === null ? 'Store global' : 'Store '.$store->getUuid(), $product->getName());
    }
    private function hash(?array $s): string { return hash('sha256', json_encode($s, JSON_THROW_ON_ERROR)); }
}
