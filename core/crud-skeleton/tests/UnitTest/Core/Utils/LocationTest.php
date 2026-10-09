<?php

declare(strict_types=1);

namespace Curl {
    /**
     * Test-only double for php-curl-class's Curl\Curl.
     *
     * The real package (php-curl-class/php-curl-class) is NOT installed in this
     * project (composer.json does not require it), so `Location`'s hard-coded
     * `new Curl()` cannot be executed at all without this stub. The stub mimics
     * the real class contract: `get()` returns the response body as a string
     * and `getResponse()` returns the same body.
     */
    final class Curl
    {
        /** @var string */
        public static string $responseBody = '';

        public static bool $throwErrorException = false;

        public function get(string $url): string
        {
            if (self::$throwErrorException) {
                throw new \ErrorException('Simulated network failure');
            }

            return self::$responseBody;
        }

        public function getResponse(): string
        {
            return self::$responseBody;
        }
    }
}

namespace App\Tests\UnitTest\Core\Utils {

    use App\Core\Utils\Location;
    use PHPUnit\Framework\TestCase;

    /**
     * Unit tests for App\Core\Utils\Location, driven through a test-only stub
     * of the absent php-curl-class dependency. No network is involved.
     *
     * Coverage notes:
     *  - getAddress() is NOT exercised: it calls `$data->getResponse()` where
     *    `$data` is the string returned by `Curl::get()`, which raises an
     *    uncaught \Error at runtime (see report, bug L-2). No test asserts that
     *    broken behaviour.
     *
     * @see docs/issues/coverage-2026-08-09/core-utils-di.md
     */
    final class LocationTest extends TestCase
    {
        protected function setUp(): void
        {
            \Curl\Curl::$responseBody = '';
            \Curl\Curl::$throwErrorException = false;
        }

        public function testGetLocationDecodesJsonResponse(): void
        {
            \Curl\Curl::$responseBody = '{"status":0,"result":{"location":{"lat":39.9042,"lng":116.4074}}}';

            $location = Location::getLocation('北京市海淀区');

            self::assertIsObject($location);
            self::assertSame(39.9042, $location->lat);
            self::assertSame(116.4074, $location->lng);
        }

        public function testGetLocationReturnsNullOnNetworkError(): void
        {
            \Curl\Curl::$throwErrorException = true;

            self::assertNull(Location::getLocation('any address'));
        }

        public function testGetDistanceReturnsKilometres(): void
        {
            \Curl\Curl::$responseBody = '{"status":0,"result":{"elements":[{"distance":1234.5}]}}';

            $km = Location::getDistance(116.4074, 39.9042, 121.4737, 31.2304);

            self::assertSame(1.2345, $km);
        }

        public function testGetDistanceReturnsNullOnNetworkError(): void
        {
            \Curl\Curl::$throwErrorException = true;

            self::assertNull(Location::getDistance(0.0, 0.0, 1.0, 1.0));
        }

        public function testGetAddressIsBrokenByStringMethodCall(): void
        {
            // getAddress() calls $data->getResponse() on the string returned by
            // Curl::get(). That is an uncaught \Error (not \ErrorException), so
            // the method can never return successfully. Covered by report bug L-2.
            $this->markTestSkipped('See report — bug L-2 (getAddress uses getResponse() on a string).');
        }
    }
}
