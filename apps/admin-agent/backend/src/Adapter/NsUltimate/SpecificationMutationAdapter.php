<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate;

use App\Store\Entity\Product;
use App\Store\Entity\Specification;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Specification mutations require productId in the canonical proposal to preserve the nested route scope. */
final class SpecificationMutationAdapter implements MutationAdapter
{
    private const FIELDS = ['productId','name','price','status','sort'];
    public function __construct(private readonly EntityManagerInterface $em, private readonly AuthorizationCheckerInterface $authorization) {}
    public function entityKey(): string { return 'Specification'; }
    public function authorize(string $action, ?string $identifier): void
    {
        if (!$this->authorization->isGranted('ROLE_ADMIN')) throw new AccessDeniedException('Specification management requires ROLE_ADMIN.');
        if (!in_array($action, ['create','update'], true)) throw new BadRequestHttpException('Specification deletion is disabled because references cannot be safely locked and verified.');
    }
    public function prepare(string $action, ?string $identifier, array $changes): array
    {
        $this->authorize($action, $identifier);
        $create = $action === 'create';
        $target = $create ? null : $this->find($identifier);
        $changes = $this->normalize($changes, $create);
        $productId = $changes['productId'];
        if ($target !== null && $target->getProduct()?->getId() !== $productId) throw new BadRequestHttpException('Specification does not belong to productId.');
        $product = $this->loadProduct($productId);
        $this->assertProductAvailable($product, false);
        $scope = $this->productSnapshot($product);
        $before = $target === null ? null : $this->snapshot($target);
        if ($before !== null && $before['isDeleted']) throw new BadRequestHttpException('Deleted Specifications cannot be changed.');
        $after = $before === null
            ? ['productId'=>$productId,'name'=>$changes['name'],'price'=>$changes['price'],'status'=>$changes['status'] ?? 'active','sort'=>$changes['sort'] ?? 0]
            : array_replace($before, $changes);
        $diff=[];
        foreach (self::FIELDS as $field) if (array_key_exists($field, $changes) && ($create || ($before[$field] ?? null) !== ($after[$field] ?? null))) $diff[]=['property'=>$field,'before'=>$before[$field] ?? null,'after'=>$after[$field] ?? null];
        $beforeHash = $this->hash(['product'=>$scope, 'specification'=>$before]);
        $titleName = $changes['name'] ?? $before['name'] ?? null;
        if ($titleName === null) throw new BadRequestHttpException('Specification name is required.');
        return ['identifier'=>$target ? (string)$target->getId() : null,'title'=>$this->title($product, $titleName, $target?->getId()),
            'beforeHash'=>$beforeHash,'changes'=>$changes,'differences'=>$diff];
    }
    public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array
    {
        $this->authorize($action, $identifier); $create=$action==='create';
        try { $changes=$this->normalize($changes,$create); }
        catch (BadRequestHttpException $error) { throw new ProposalConflictException('Specification proposal is no longer valid. Prepare a new preview.', $error); }
        $productId=$changes['productId'];
        if ($create) {
            try { $product=$this->loadProduct($productId,true); }
            catch (BadRequestHttpException $error) { throw new ProposalConflictException('Product no longer exists. Prepare a new preview.', $error); }
            $this->assertProductAvailable($product,true);
            if (!hash_equals($beforeHash,$this->hash(['product'=>$this->productSnapshot($product),'specification'=>null]))) throw new ProposalConflictException('Product scope changed since preview. Prepare a new preview.');
            $target=new Specification(); $target->setProduct($product);
        } else {
            try { $product=$this->loadProduct($productId,true); }
            catch (BadRequestHttpException $error) { throw new ProposalConflictException('Product scope no longer exists. Prepare a new preview.', $error); }
            $this->assertProductAvailable($product,true);
            try { $target=$this->find($identifier,true); }
            catch (NotFoundHttpException $error) { throw new ProposalConflictException('Specification no longer exists. Prepare a new preview.', $error); }
            $this->em->refresh($target,LockMode::PESSIMISTIC_WRITE);
            if ($target->getProduct()?->getId() !== $productId) throw new ProposalConflictException('Specification parent scope changed since preview.');
            if (!hash_equals($beforeHash,$this->hash(['product'=>$this->productSnapshot($product),'specification'=>$this->snapshot($target)]))) throw new ProposalConflictException('Specification or Product changed since preview. Prepare a new preview.');
            if ($target->getIsDeleted()) throw new ProposalConflictException('Deleted Specifications cannot be changed.');
        }
        foreach ($changes as $k=>$v) switch($k) { case 'name':$target->setName($v);break;case 'price':$target->setPrice($v);break;case 'status':$target->setStatus($v);break;case 'sort':$target->setSort($v);break; }
        $expected=$this->snapshot($target); $this->em->persist($target); $this->em->flush(); $this->em->refresh($target);
        $actual=$this->snapshot($target); unset($expected['id'],$actual['id']);
        return ['identifier'=>(string)$target->getId(),'verified'=>$actual===$expected];
    }
    /**
     * @param array<string, mixed> $c
     * @return array<string, mixed>
     */
    private function normalize(array $c,bool $create):array
    {
        if (array_diff(array_keys($c),self::FIELDS) || !isset($c['productId'])) throw new BadRequestHttpException('Only productId, name, price, status and sort are supported.');
        if ($create && (!isset($c['name']) || !array_key_exists('price',$c))) throw new BadRequestHttpException('Creation requires name and price.');
        foreach($c as $k=>$v) { $ok=match($k) {'productId'=>is_int($v)&&$v>0&&$v<=2147483647,'name'=>is_string($v)&&trim($v)!==''&&$v===trim($v)&&mb_strlen($v)<=255&&!str_contains($v,'<'),'price'=>is_int($v)&&$v>=0&&$v<=2147483647,'status'=>in_array($v,['active','inactive'],true),'sort'=>is_int($v)&&$v>=-2147483648&&$v<=2147483647,default=>false}; if(!$ok) throw new BadRequestHttpException('Invalid Specification field: '.$k.'.'); }
        $canonical = [];
        foreach (self::FIELDS as $field) if (array_key_exists($field, $c)) $canonical[$field] = $c[$field];
        return $canonical;
    }
    private function loadProduct(int $id, bool $lock=false): Product
    {
        $product=$this->em->find(Product::class,$id,$lock?LockMode::PESSIMISTIC_WRITE:null);
        if (!$product instanceof Product) throw new BadRequestHttpException('Product not found.');
        if ($lock) $this->refreshProduct($product);
        return $product;
    }
    private function refreshProduct(Product $product): void
    {
        $this->em->refresh($product,LockMode::PESSIMISTIC_WRITE);
        $store=$product->getStore();
        if ($store!==null) $this->em->refresh($store,LockMode::PESSIMISTIC_WRITE);
        $category=$product->getCategory();
        if ($category!==null) $this->em->refresh($category,LockMode::PESSIMISTIC_WRITE);
    }
    private function find(?string $id,bool $lock=false):Specification { if($id===null||!preg_match('/^[1-9][0-9]{0,9}$/D',$id)||(int)$id>2147483647) throw new BadRequestHttpException('An exact signed 32-bit numeric Specification ID is required.'); $s=$this->em->find(Specification::class,(int)$id,$lock?LockMode::PESSIMISTIC_WRITE:null); if(!$s instanceof Specification) throw new NotFoundHttpException('Specification not found.'); return $s; }
    /** @return array{id: int|null, uuid: string, productId: int|null, name: string, price: int, status: string, sort: int, isDeleted: bool} */
    private function snapshot(Specification $s):array
    {
        $product=$s->getProduct();
        return ['id'=>$s->getId(),'uuid'=>$s->getUuid(),'productId'=>$product?->getId(),'name'=>$s->getName(),'price'=>$s->getPrice(),
            'status'=>$s->getStatus(),'sort'=>$s->getSort(),'isDeleted'=>$s->getIsDeleted()];
    }
    /** @return array<string, mixed> */
    private function productSnapshot(Product $product): array
    {
        $store=$product->getStore();
        $category=$product->getCategory();
        return ['id'=>$product->getId(),'uuid'=>$product->getUuid(),'name'=>$product->getName(),'status'=>$product->getStatus(),
            'isDeleted'=>$product->getIsDeleted(),'storeUuid'=>$store?->getUuid(),'storeStatus'=>$store?->getStatus(),
            'category'=>['id'=>$category?->getId(),'uuid'=>$category?->getUuid(),'enabled'=>$category?->isEnabled(),
                'storeUuid'=>$category?->getStore()?->getUuid()]];
    }
    private function assertProductAvailable(Product $product, bool $conflict): void
    {
        if ($product->getIsDeleted()) {
            if ($conflict) throw new ProposalConflictException('Product was deleted or is no longer available. Prepare a new preview.');
            throw new BadRequestHttpException('Cannot manage Specifications under a deleted Product.');
        }
    }
    private function title(Product $product, string $specificationName, ?int $specificationId): string
    {
        return sprintf('[Product #%d; Specification #%s; %s] %s', $product->getId(), $specificationId ?? 'new',
            $product->getStore() === null ? 'Store global' : 'Store '.$product->getStore()->getUuid(), $specificationName);
    }
    /** @param array<string, mixed>|null $s */
    private function hash(?array $s):string { return hash('sha256',json_encode($s,JSON_THROW_ON_ERROR)); }
}
