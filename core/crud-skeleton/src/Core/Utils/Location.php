<?php

namespace App\Core\Utils;

use Curl\Curl;

class Location {
    const KEY = '4ESBZ-6TO34-7TFUE-XPXBK-O2G26-OLFKZ';

    const GET_LOCATION_API = 'https://apis.map.qq.com/ws/geocoder/v1/?key=%s&address=%s';
    const GET_ADDRESS_API = 'https://apis.map.qq.com/ws/geocoder/v1/?key=%s&location=%s,%s';
    const GET_DISTANCE_API = 'https://apis.map.qq.com/ws/distance/v1/?key=%s&mode=driving&from=%s&to=%s';

    public static function getLocation(mixed $address): mixed {
        $api_url = sprintf(self::GET_LOCATION_API, self::KEY, $address);

        try {
            $curl = new Curl();
            $data = $curl->get($api_url);
            $data = json_decode($data);

            return $data->result->location;
        } catch (\ErrorException $e) {
            return null;
        }
    }

    public static function getAddress(mixed $latitude, mixed $longitude): mixed
    {
        $api_url = sprintf(self::GET_ADDRESS_API, self::KEY, $latitude, $longitude);

        try {
            $curl = new Curl();
            $data = $curl->get($api_url);
            $data = json_decode($data->getResponse());

            return $data->result->address;
        } catch (\ErrorException $e) {
            return $e->getMessage();
        }
    }

    public static function getDistance(mixed $longitude1, mixed $latitude1, mixed $longitude2, mixed $latitude2): mixed {
        $from = sprintf('%lf,%lf', $latitude1, $longitude1 );
        $to = sprintf('%lf,%lf', $latitude2, $longitude2 );

        $api_url = sprintf(self::GET_DISTANCE_API, self::KEY, $from, $to);

        try {
            $curl = new Curl();
            $data = $curl->get($api_url);
            $data = json_decode($data);

            return $data->result->elements[0]->distance / 1000.0;
        } catch (\ErrorException $e) {
            return null;
        }
    }
}
