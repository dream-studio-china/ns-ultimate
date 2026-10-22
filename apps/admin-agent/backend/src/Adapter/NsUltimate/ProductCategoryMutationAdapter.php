<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate;

use App\Store\Entity\Product;
use App\Store\Entity\ProductCategory;
use App\Store\Entity\Store;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ProductCategoryMutationAdapter implements MutationAdapter
{
    private const FIELDS=['name','slug','description','parent','sortOrder','enabled','store'];
    public function __construct(private readonly EntityManagerInterface $em,private readonly AuthorizationCheckerInterface $authorization){}
    public function entityKey():string{return 'ProductCategory';}
    public function authorize(string $action,?string $identifier):void{if(!$this->authorization->isGranted('ROLE_ADMIN'))throw new AccessDeniedException('Product category management requires ROLE_ADMIN.');if(!in_array($action,['create','update','delete'],true))throw new BadRequestHttpException('Unsupported ProductCategory action.');}
    public function prepare(string $action,?string $identifier,array $changes):array
    {
        $this->authorize($action,$identifier);$target=$action==='create'?null:$this->find($identifier);if($action==='delete')$this->assertUnused($target);
        $changes=$action==='delete'?[]:$this->normalize($changes,$action==='create');if($target&&array_key_exists('store',$changes))throw new BadRequestHttpException('ProductCategory store assignment is allowed only on create.');
        $before=$target?$this->snapshot($target):null;$after=$action==='delete'?null:($before??['name'=>'','slug'=>'','description'=>null,'parent'=>null,'sortOrder'=>0,'enabled'=>true,'store'=>null]);if($action!=='delete')$after=array_replace($after,$changes);
        $scope=$target?->getScopeKey()??'global';if($action==='create'&&array_key_exists('store',$changes))$scope=$changes['store']===null?'global':'store:'.$this->store($changes['store'])->getUuid();if($action!=='delete'){$this->validateParent($after['parent'],$target,$scope);$this->unique($after['slug'],$scope,$target?->getId());}
        $fields=$action==='delete'?self::FIELDS:array_values(array_intersect(self::FIELDS,array_keys($changes)));$differences=[];foreach($fields as $field)if($action!=='update'||($before[$field]??null)!==($after[$field]??null))$differences[]=['property'=>$field,'before'=>$before[$field]??null,'after'=>$after[$field]??null];
        return ['identifier'=>$target?(string)$target->getId():null,'title'=>$before['name']??$after['name'],'beforeHash'=>$this->hash($before),'changes'=>$changes,'differences'=>$differences];
    }
    public function execute(string $action,?string $identifier,string $beforeHash,array $changes):array
    {
        $this->authorize($action,$identifier);if($action==='delete'||($action==='update'&&array_key_exists('parent',$changes)))$this->lockHierarchy();
        try{$changes=$action==='delete'?[]:$this->normalize($changes,$action==='create');}catch(BadRequestHttpException $error){throw new ProposalConflictException('Product category proposal is no longer valid. Prepare a new preview.',$error);}
        if($action==='create')$target=new ProductCategory($changes['name'],$changes['slug']);else{try{$target=$this->find($identifier,true);}catch(NotFoundHttpException $e){throw new ProposalConflictException('Product category no longer exists. Prepare a new preview.',$e);}$this->em->refresh($target,LockMode::PESSIMISTIC_WRITE);if(!hash_equals($beforeHash,$this->hash($this->snapshot($target))))throw new ProposalConflictException('Product category changed since preview. Prepare a new preview.');}
        if($action==='delete'){$this->revalidate(fn()=>$this->assertUnused($target,true),'Product category references changed after preview. Prepare a new preview.');$id=(string)$target->getId();$this->em->remove($target);try{$this->em->flush();}catch(ForeignKeyConstraintViolationException $error){throw new ProposalConflictException('Product category acquired a reference after preview. Prepare a new preview.',$error);}return ['identifier'=>$id,'verified'=>$this->em->find(ProductCategory::class,$id)===null];}
        $scope=$target->getScopeKey();if($action==='create'&&array_key_exists('store',$changes))$this->revalidate(function()use(&$scope,$changes):void{$scope=$changes['store']===null?'global':'store:'.$this->store($changes['store'],true)->getUuid();},'Product category store scope changed after preview. Prepare a new preview.');
        $this->revalidate(function()use($changes,$target,$scope,$action):void{if(array_key_exists('parent',$changes))$this->validateParent($changes['parent'],$target,$scope,$action==='create');$this->unique($changes['slug']??$target->getSlug(),$scope,$target->getId());},'Product category hierarchy, scope, or slug changed after preview. Prepare a new preview.');
        if(array_key_exists('store',$changes))$target->setStore($changes['store']===null?null:$this->store($changes['store']));if(array_key_exists('name',$changes))$target->setName($changes['name']);if(array_key_exists('slug',$changes))$target->setSlug($changes['slug']);if(array_key_exists('description',$changes))$target->setDescription($changes['description']);if(array_key_exists('parent',$changes))$target->setParent($changes['parent']===null?null:$this->em->find(ProductCategory::class,$changes['parent']));if(array_key_exists('sortOrder',$changes))$target->setSortOrder($changes['sortOrder']);if(array_key_exists('enabled',$changes))$target->setEnabled($changes['enabled']);
        $expected=array_diff_key($this->snapshot($target),['id'=>true,'uuid'=>true]);$this->em->persist($target);try{$this->em->flush();}catch(UniqueConstraintViolationException $error){throw new ProposalConflictException('Product category slug was claimed after preview. Prepare a new preview.',$error);}$this->em->refresh($target);return ['identifier'=>(string)$target->getId(),'verified'=>array_diff_key($this->snapshot($target),['id'=>true,'uuid'=>true])===$expected];
    }
    private function normalize(array $c,bool $create):array
    {
        if($c===[]||array_diff(array_keys($c),self::FIELDS)!==[]||($create&&(!isset($c['name'])||!isset($c['slug']))))throw new BadRequestHttpException('Invalid ProductCategory fields.');foreach($c as $f=>$v){$ok=match($f){'name'=>is_string($v)&&trim($v)!==''&&mb_strlen($v)<=255,'slug'=>is_string($v)&&trim($v)!==''&&mb_strlen($v)<=100,'description'=>$v===null||(is_string($v)&&strlen($v)<=65535),'parent'=>$v===null||$this->isReference($v),'sortOrder'=>is_int($v)&&$v>=0&&$v<=2147483647,'enabled'=>is_bool($v),'store'=>$create&&($v===null||$this->isReference($v)),default=>false};if(!$ok)throw new BadRequestHttpException('Invalid ProductCategory field: '.$f.'.');}
        if(array_key_exists('store',$c)&&$c['store']!==null)$c['store']=$this->store($c['store'])->getId();if(array_key_exists('parent',$c)&&$c['parent']!==null)$c['parent']=$this->parent($c['parent'])->getId();return array_replace(array_intersect_key(array_fill_keys(self::FIELDS,null),$c),$c);
    }
    private function find(?string $id,bool $lock=false):ProductCategory{if($id===null||!$this->isReference($id))throw new BadRequestHttpException('An exact ProductCategory ID or UUID is required.');$e=is_string($id)&&!ctype_digit($id)?$this->em->getRepository(ProductCategory::class)->findOneBy(['uuid'=>strtolower($id)]):$this->em->find(ProductCategory::class,(int)$id,$lock?LockMode::PESSIMISTIC_WRITE:null);if(!$e instanceof ProductCategory)throw new NotFoundHttpException('Product category not found.');return $e;}
    private function isReference(mixed $id):bool{return (is_int($id)&&$id>0&&$id<=2147483647)||(is_string($id)&&(preg_match('/^[1-9][0-9]{0,9}$/D',$id)===1&&(int)$id<=2147483647||preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',$id)===1));}
    private function store(mixed $id,bool $lock=false):Store{if(!$this->isReference($id))throw new BadRequestHttpException('Invalid store identifier.');$e=is_string($id)&&!ctype_digit($id)?$this->em->getRepository(Store::class)->findOneBy(['uuid'=>strtolower($id)]):$this->em->find(Store::class,(int)$id,$lock?LockMode::PESSIMISTIC_READ:null);if(!$e instanceof Store)throw new BadRequestHttpException('Store not found.');if($lock&&!ctype_digit((string)$id))$this->em->refresh($e,LockMode::PESSIMISTIC_READ);return $e;}
    private function parent(mixed $id):ProductCategory{if(!$this->isReference($id))throw new BadRequestHttpException('Invalid parent identifier.');$e=is_string($id)&&!ctype_digit($id)?$this->em->getRepository(ProductCategory::class)->findOneBy(['uuid'=>strtolower($id)]):$this->em->find(ProductCategory::class,(int)$id);if(!$e instanceof ProductCategory)throw new BadRequestHttpException('Product category parent not found.');return $e;}
    private function validateParent(?int $id,?ProductCategory $target,string $scope,bool $lockParent=false):void{if($id===null)return;$p=$this->em->find(ProductCategory::class,$id,$lockParent?LockMode::PESSIMISTIC_READ:null);if(!$p instanceof ProductCategory)throw new BadRequestHttpException('Product category parent no longer exists.');$seen=[];while($p instanceof ProductCategory){$pid=$p->getId();if($p->getScopeKey()!==$scope)throw new BadRequestHttpException('Parent category must have the same store scope.');if(isset($seen[$pid])||($target&&$pid===$target->getId()))throw new BadRequestHttpException('Product category parent would create a cycle.');if(count($seen)>=10000)throw new BadRequestHttpException('Product category hierarchy exceeds the safe traversal limit.');$seen[$pid]=true;$p=$p->getParent();}}
    private function unique(string $slug,string $scope,?int $id):void{$other=$this->em->getRepository(ProductCategory::class)->findOneBy(['scopeKey'=>$scope,'slug'=>$slug]);if($other&&$other->getId()!==$id)throw new BadRequestHttpException('Product category slug is already in use in this scope.');}
    private function assertUnused(ProductCategory $e,bool $lockReferences=false):void{$id=$e->getId();foreach([[ProductCategory::class,'parent'],[Product::class,'category']]as[$class,$field]){$used=$lockReferences?$this->hasLockedReference($class,$field,$id):$this->em->getRepository($class)->count([$field=>$id])>0;if($used)throw new BadRequestHttpException('Product category is referenced and cannot be deleted.');}}
    private function hasLockedReference(string $class,string $field,int $id):bool{$meta=$this->em->getClassMetadata($class);$query=$this->em->createQuery(sprintf('SELECT reference FROM %s reference WHERE reference.%s = :target',$meta->getName(),$field))->setParameter('target',$id)->setMaxResults(1)->setLockMode(LockMode::PESSIMISTIC_WRITE);return $query->getOneOrNullResult()!==null;}
    private function lockHierarchy():void{$this->em->createQuery('SELECT category FROM App\\Store\\Entity\\ProductCategory category ORDER BY category.id ASC')->setLockMode(LockMode::PESSIMISTIC_WRITE)->setHint(Query::HINT_REFRESH,true)->getResult();}
    private function revalidate(callable $callback,string $message):void{try{$callback();}catch(BadRequestHttpException $error){throw new ProposalConflictException($message,$error);}}
    private function snapshot(ProductCategory $e):array{return ['id'=>$e->getId(),'uuid'=>$e->getUuid(),'scope'=>$e->getScopeKey(),'name'=>$e->getName(),'slug'=>$e->getSlug(),'description'=>$e->getDescription(),'parent'=>$e->getParent()?->getId(),'sortOrder'=>$e->getSortOrder(),'enabled'=>$e->isEnabled(),'store'=>$e->getStore()?->getId()];}
    private function hash(?array $v):string{return hash('sha256',json_encode($v,JSON_THROW_ON_ERROR));}
}
