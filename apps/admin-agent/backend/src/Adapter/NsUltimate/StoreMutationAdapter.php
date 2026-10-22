<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate;

use App\Store\Entity\Store;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Apps\AdminAgent\Backend\Contract\MutationAdapter;
use NsUltimate\Apps\AdminAgent\Backend\Exception\ProposalConflictException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Agent-specific limited Store editor; lifecycle, settings, code and locale are not exposed. */
final class StoreMutationAdapter implements MutationAdapter
{
    private const FIELDS = ['name', 'contact', 'address'];

    public function __construct(private readonly EntityManagerInterface $em, private readonly AuthorizationCheckerInterface $authorization) {}
    public function entityKey(): string { return 'Store'; }
    public function authorize(string $action, ?string $identifier): void
    {
        if (!$this->authorization->isGranted('ROLE_ADMIN')) throw new AccessDeniedException('Store management requires ROLE_ADMIN.');
        if ($action !== 'update') throw new BadRequestHttpException('Only Store updates are supported.');
    }
    public function prepare(string $action, ?string $identifier, array $changes): array
    {
        $this->authorize($action, $identifier);
        $normalized = $this->normalize($changes);
        $target = $this->find($identifier);
        $before = $this->snapshot($target);
        $diff = [];
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $normalized)) continue;
            $effective = in_array($field, ['contact','address'], true) ? $this->merge($before[$field], $normalized[$field]) : $normalized[$field];
            if ($this->canonicalize($before[$field] ?? null) !== $this->canonicalize($effective)) $diff[] = ['property'=>$field, 'before'=>in_array($field, ['contact','address'], true) ? null : $before[$field], 'after'=>$normalized[$field]];
        }
        return ['identifier'=>(string)$target->getId(), 'title'=>$target->getName(), 'beforeHash'=>$this->hash($before), 'changes'=>$normalized, 'differences'=>$diff];
    }
    public function execute(string $action, ?string $identifier, string $beforeHash, array $changes): array
    {
        $this->authorize($action, $identifier);
        $changes = $this->normalize($changes);
        try { $target = $this->find($identifier, true); }
        catch (NotFoundHttpException $e) { throw new ProposalConflictException('Store no longer exists. Prepare a new preview.', $e); }
        $this->em->refresh($target, LockMode::PESSIMISTIC_WRITE);
        if (!hash_equals($beforeHash, $this->hash($this->snapshot($target)))) throw new ProposalConflictException('Store changed since preview. Prepare a new preview.');
        foreach ($changes as $field => $value) {
            if ($field === 'name') $target->setName($value);
            if ($field === 'contact') $target->setContact($this->merge($target->getContact(), $value));
            if ($field === 'address') $target->setAddress($this->merge($target->getAddress(), $value));
        }
        $expected = [];
        foreach (array_keys($changes) as $field) $expected[$field] = $field === 'contact' ? $target->getContact() : ($field === 'address' ? $target->getAddress() : $target->getName());
        $id = (string)$target->getId(); $this->em->flush(); $this->em->refresh($target);
        foreach ($expected as $field => $value) {
            $actual = $field === 'contact' ? $target->getContact() : ($field === 'address' ? $target->getAddress() : $target->getName());
            if ($this->canonicalize($actual) !== $this->canonicalize($value)) return ['identifier'=>$id, 'verified'=>false];
        }
        return ['identifier'=>$id, 'verified'=>true];
    }
    private function normalize(array $changes): array
    {
        if ($changes === [] || array_diff(array_keys($changes), self::FIELDS) !== []) throw new BadRequestHttpException('Only Store name, contact, and address may be updated.');
        if (array_key_exists('name', $changes) && (!is_string($changes['name']) || trim($changes['name']) === '' || mb_strlen(trim($changes['name'])) > 255)) throw new BadRequestHttpException('name must be non-empty and at most 255 characters.');
        if (isset($changes['name'])) $changes['name'] = trim($changes['name']);
        if (array_key_exists('contact', $changes)) $changes['contact'] = $this->validateObject($changes['contact'], 'contact', self::contactSchema());
        if (array_key_exists('address', $changes)) $changes['address'] = $this->validateObject($changes['address'], 'address', self::addressSchema());
        $ordered = [];
        foreach (self::FIELDS as $field) if (array_key_exists($field, $changes)) $ordered[$field] = $changes[$field];
        return $ordered;
    }
    private function validateObject(mixed $value, string $field, array $schema): ?array
    {
        if ($value === null) return null;
        if (!is_array($value) || array_is_list($value) && $value !== []) throw new BadRequestHttpException($field.' must be a structured object or null.');
        foreach ($value as $key => $item) {
            if (!isset($schema[$key])) throw new BadRequestHttpException('Unknown '.$field.' field.');
            $rule = $schema[$key];
            $valid = match ($rule['type']) {
                'string' => is_string($item) && mb_strlen($item) <= $rule['max'] && (!isset($rule['min']) || mb_strlen($item) >= $rule['min']) && (!isset($rule['pattern']) || preg_match($rule['pattern'], $item) === 1) && (!isset($rule['email']) || filter_var($item, FILTER_VALIDATE_EMAIL) !== false),
                'number' => (is_float($item) || is_int($item)) && $item >= $rule['min'] && $item <= $rule['max'],
                'array' => is_array($item) && array_is_list($item) && count($item) <= $rule['max'] && count(array_unique($item, SORT_REGULAR)) === count($item) && array_reduce($item, static fn(bool $ok, mixed $v): bool => $ok && is_string($v) && mb_strlen($v) >= 1 && mb_strlen($v) <= 30, true),
                default => false,
            };
            if (!$valid) throw new BadRequestHttpException('Invalid '.$field.' field: '.$key.'.');
        }
        if ($field === 'address' && (array_key_exists('latitude', $value) xor array_key_exists('longitude', $value))) throw new BadRequestHttpException('address latitude and longitude must be provided together.');
        return $this->canonicalize($value);
    }
    /** Explicit contact/address proposal replaces only its named keys; no full stored blob is included in previews. */
    private function merge(?array $current, ?array $proposal): ?array
    {
        if ($proposal === null) return null;
        return array_replace($current ?? [], $proposal);
    }
    private static function contactSchema(): array
    {
        return ['phone'=>['type'=>'string','max'=>20,'pattern'=>'/^(\+?[0-9\- ]{7,20}|1[3-9][0-9]{9})$/D'],'managerPhone'=>['type'=>'string','max'=>20,'pattern'=>'/^(\+?[0-9\- ]{7,20}|1[3-9][0-9]{9})$/D'],'email'=>['type'=>'string','max'=>255,'email'=>true],'managerEmail'=>['type'=>'string','max'=>255,'email'=>true],'managerName'=>['type'=>'string','min'=>1,'max'=>40],'managerUserUuid'=>['type'=>'string','max'=>36,'pattern'=>'/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD'],'wechat'=>['type'=>'string','max'=>64],'serviceHours'=>['type'=>'string','max'=>100],'subTitle'=>['type'=>'string','min'=>1,'max'=>100],'tags'=>['type'=>'array','max'=>20]];
    }
    private static function addressSchema(): array
    {
        return ['province'=>['type'=>'string','min'=>1,'max'=>40],'city'=>['type'=>'string','min'=>1,'max'=>40],'district'=>['type'=>'string','min'=>1,'max'=>40],'street'=>['type'=>'string','min'=>1,'max'=>100],'detail'=>['type'=>'string','min'=>1,'max'=>255],'building'=>['type'=>'string','max'=>100],'floor'=>['type'=>'string','max'=>40],'postalCode'=>['type'=>'string','max'=>20,'pattern'=>'/^[0-9A-Za-z\- ]{3,20}$/D'],'formattedAddress'=>['type'=>'string','max'=>255],'latitude'=>['type'=>'number','min'=>-90,'max'=>90],'longitude'=>['type'=>'number','min'=>-180,'max'=>180],'geohash'=>['type'=>'string','max'=>12,'pattern'=>'/^[0-9bcdefghjkmnpqrstuvwxyz]{1,12}$/D'],'poiId'=>['type'=>'string','max'=>64]];
    }
    private function find(?string $id, bool $lock = false): Store
    {
        if ($id === null || !preg_match('/^(?:[1-9][0-9]{0,14}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/iD', $id)) throw new BadRequestHttpException('An exact Store ID or UUID is required.');
        $entity = ctype_digit($id) ? $this->em->find(Store::class, (int)$id, $lock ? LockMode::PESSIMISTIC_WRITE : null) : $this->em->getRepository(Store::class)->findOneBy(['uuid'=>strtolower($id)]);
        if (!$entity instanceof Store) throw new NotFoundHttpException('Store not found.'); return $entity;
    }
    private function snapshot(Store $store): array { return ['id'=>$store->getId(),'uuid'=>$store->getUuid(),'name'=>$store->getName(),'contact'=>$store->getContact(),'address'=>$store->getAddress()]; }
    private function hash(array $snapshot): string { return hash('sha256', json_encode($this->canonicalize($snapshot), JSON_THROW_ON_ERROR)); }
    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map($this->canonicalize(...), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
