<?php

namespace App\Core\Serializer\Callbacks;

class ObjectCallback
{
    public static function handle(object $object): mixed
    {
        if (method_exists($object, 'getId')) {
            return $object->getId();
        }

        return null;
    }
}
