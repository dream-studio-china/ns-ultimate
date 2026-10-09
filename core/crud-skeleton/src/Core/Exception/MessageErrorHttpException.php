<?php
/**
 * Created by IntelliJ IDEA.
 * User: immane
 * Date: 5/26/2019
 * Time: 11:10 PM
 */

namespace App\Core\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

class MessageErrorHttpException extends HttpException
{
    /**
     * @param string $message The internal exception message
     * @param string|null $redirectUrl
     */
    public function __construct(?string $message = null, ?string $redirectUrl = null)
    {
        parent::__construct(403, $message, null, ['redirectUrl' => $redirectUrl ], 0);
    }
}