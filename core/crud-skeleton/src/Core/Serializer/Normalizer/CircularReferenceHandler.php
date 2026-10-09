<?php

namespace App\Core\Serializer\Normalizer;

class CircularReferenceHandler
{
    /**
     * @return ?array{id: mixed}
     */
    public static function handle(object $object): ?array
    {
        if (method_exists($object, 'getId')) {
            $id = $object->getId();
            return is_scalar($id) ? ['id' => $id] : null;
        }

        throw new \Exception('Every entity should have `getId` method');
    }
}
