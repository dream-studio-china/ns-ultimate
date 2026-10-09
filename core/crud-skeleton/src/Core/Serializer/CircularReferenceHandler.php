<?php

namespace App\Core\Serializer;

final class CircularReferenceHandler
{
    /**
     * Return a stable non-object value to break circular references when serializing.
     * Keep this static and dependency-free so it can be referenced easily from config.
     *
     * @param object $object
     * @return string
     */
    public static function handle($object): string
    {
        // Prefer an ID if available, otherwise fall back to a stable hash
        if (is_object($object) && method_exists($object, 'getId')) {
            $id = $object->getId();
            return (string) $id;
        }

        // As a fallback, return the object's spl_object_hash — unique during request
        return is_object($object) ? spl_object_hash($object) : (string) $object;
    }
}

