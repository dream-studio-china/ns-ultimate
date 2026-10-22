<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate;

use App\Common\Entity\Content;
use App\Common\Entity\Tag;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class TagMutationAdapter implements MutationAdapter
{
    private const FIELDS = ['name', 'slug', 'color'];
    public function __construct(private readonly EntityManagerInterface $em, private readonly AuthorizationCheckerInterface $authorization) {}
    public function entityKey(): string { return 'Tag'; }
    public function authorize(string $action, ?string $identifier): void { if (!$this->authorization->isGranted('ROLE_ADMIN')) throw new AccessDeniedException('Tag management requires ROLE_ADMIN.'); if (!in_array($action, ['create','update','delete'], true)) throw new BadRequestHttpException('Unsupported Tag action.'); }
    public function prepare(string $action, ?string $identifier, array $changes): array
    {
        $this->authorize($action, $identifier); $target = $action === 'create' ? null : $this->find($identifier);
        if ($action === 'delete') $this->assertUnused($target);
        $changes = $action === 'delete' ? [] : $this->normalize($changes, $action === 'create');
        $before = $target ? $this->snapshot($target) : null; $after = $action === 'delete' ? null : ($before ?? ['name'=>'','slug'=>'','color'=>null]); if ($action !== 'delete') $after = array_replace($after, $changes);
        if ($action !== 'delete') $this->assertUniqueSlug($after['slug'], $target?->getId());
        $fields = $action === 'delete' ? self::FIELDS : array_values(array_intersect(self::FIELDS, array_keys($changes))); $differences=[];
        foreach ($fields as $field) if ($action !== 'update' || ($before[$field] ?? null) !== ($after[$field] ?? null)) $differences[]=['property'=>$field,'before'=>$before[$field]??null,'after'=>$after[$field]??null];
        return ['identifier'=>$target ? (string)$target->getId() : null,'title'=>$before['name']??$after['name'],'beforeHash'=>$this->hash($before),'changes'=>$changes,'differences'=>$differences];
    }
    public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array
    {
        $this->authorize($action,$identifier);try{$changes=$action==='delete'?[]:$this->normalize($changes,$action==='create');}catch(BadRequestHttpException $error){throw new ProposalConflictException('Tag proposal is no longer valid. Prepare a new preview.',$error);}
        if ($action==='create') $target=new Tag($changes['name'],$changes['slug']); else { try{$target=$this->find($identifier,true);}catch(NotFoundHttpException $e){throw new ProposalConflictException('Tag no longer exists. Prepare a new preview.',$e);} $this->em->refresh($target,LockMode::PESSIMISTIC_WRITE); if(!hash_equals($beforeHash,$this->hash($this->snapshot($target))))throw new ProposalConflictException('Tag changed since preview. Prepare a new preview.'); }
        if($action==='delete'){ $this->revalidate(fn()=>$this->assertUnused($target,true),'Tag associations changed after preview. Prepare a new preview.'); $id=(string)$target->getId();$this->em->remove($target);try{$this->em->flush();}catch(ForeignKeyConstraintViolationException $error){throw new ProposalConflictException('Tag acquired an association after preview. Prepare a new preview.',$error);}return ['identifier'=>$id,'verified'=>$this->em->find(Tag::class,$id)===null]; }
        if(array_key_exists('name',$changes))$target->setName($changes['name']); if(array_key_exists('slug',$changes))$target->setSlug($changes['slug']);if(array_key_exists('color',$changes))$target->setColor($changes['color']);
        $this->revalidate(fn()=>$this->assertUniqueSlug($target->getSlug(),$target->getId()),'Tag slug was claimed after preview. Prepare a new preview.');$expected=array_intersect_key($this->snapshot($target),array_fill_keys(self::FIELDS,true));$this->em->persist($target);try{$this->em->flush();}catch(UniqueConstraintViolationException $error){throw new ProposalConflictException('Tag slug was claimed after preview. Prepare a new preview.',$error);}$this->em->refresh($target);return ['identifier'=>(string)$target->getId(),'verified'=>array_intersect_key($this->snapshot($target),array_fill_keys(self::FIELDS,true))===$expected];
    }
    private function normalize(array $changes,bool $create):array
    {
        if($changes===[]||array_diff(array_keys($changes),self::FIELDS)!==[]||($create&&(!isset($changes['name'])||!isset($changes['slug']))))throw new BadRequestHttpException('Invalid Tag fields.');
        foreach($changes as $field=>$value){$valid=match($field){'name'=>is_string($value)&&trim($value)!==''&&mb_strlen($value)<=255,'slug'=>is_string($value)&&trim($value)!==''&&mb_strlen($value)<=255,'color'=>$value===null||(is_string($value)&&preg_match('/^#[0-9a-f]{6}$/iD',$value)===1),default=>false};if(!$valid)throw new BadRequestHttpException('Invalid Tag field: '.$field.'.');}
        return array_replace(array_intersect_key(array_fill_keys(self::FIELDS, null), $changes), $changes);
    }
    private function find(?string $id,bool $lock=false):Tag
    {
        if($id===null||!preg_match('/^(?:[1-9][0-9]{0,9}|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/iD',$id)||(ctype_digit($id)&&(int)$id>2147483647))throw new BadRequestHttpException('An exact Tag ID or UUID is required.');$e=ctype_digit($id)?$this->em->find(Tag::class,(int)$id,$lock?LockMode::PESSIMISTIC_WRITE:null):$this->em->getRepository(Tag::class)->findOneBy(['uuid'=>strtolower($id)]);if(!$e instanceof Tag)throw new NotFoundHttpException('Tag not found.');return $e;
    }
    private function assertUniqueSlug(string $slug,?int $id):void{$other=$this->em->getRepository(Tag::class)->findOneBy(['slug'=>$slug]);if($other&&$other->getId()!==$id)throw new BadRequestHttpException('Tag slug is already in use.');}
    private function assertUnused(Tag $tag,bool $lockReferences=false):void
    {
        // Join-table reference query prevents deletion from silently detaching tags from content.
        $query=$this->em->createQuery('SELECT c FROM App\\Common\\Entity\\Content c JOIN c.tags t WHERE t.id = :tag')->setParameter('tag',$tag->getId())->setMaxResults(1);
        if($lockReferences)$query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        if($query->getOneOrNullResult()!==null)throw new BadRequestHttpException('Tag is assigned to content and cannot be deleted.');
    }
    private function revalidate(callable $callback,string $message):void{try{$callback();}catch(BadRequestHttpException $error){throw new ProposalConflictException($message,$error);}}
    private function snapshot(Tag $e):array{return ['id'=>$e->getId(),'uuid'=>$e->getUuid(),'name'=>$e->getName(),'slug'=>$e->getSlug(),'color'=>$e->getColor()];}
    private function hash(?array $value):string{return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR));}
}
