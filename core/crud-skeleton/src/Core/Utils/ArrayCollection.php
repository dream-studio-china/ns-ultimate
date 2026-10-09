<?php

namespace App\Core\Utils;

class ArrayCollection {
    /**
     * @return \Doctrine\Common\Collections\ArrayCollection<array-key, mixed>
     */
    public static function init(mixed $array): \Doctrine\Common\Collections\ArrayCollection
    {
        return new \Doctrine\Common\Collections\ArrayCollection(is_array($array) ? $array : []);
    }

    /**
     * @return \Doctrine\Common\Collections\ArrayCollection<array-key, mixed>
     */
    public static function fromJsonString(string $json): \Doctrine\Common\Collections\ArrayCollection
    {
        return new \Doctrine\Common\Collections\ArrayCollection(json_decode($json, true));
    }

    /**
     * @return \Doctrine\Common\Collections\ArrayCollection<array-key, mixed>
     */
    public static function map(mixed $array, mixed $key): \Doctrine\Common\Collections\ArrayCollection
    {
        if(!($array instanceof \Doctrine\Common\Collections\ArrayCollection)) {
            $array = new \Doctrine\Common\Collections\ArrayCollection($array);
        }

        return $array->map(function ($item) use ($key) {
            $getter = 'get' . ucfirst($key);
            return $item->$getter();
        });
    }
}
