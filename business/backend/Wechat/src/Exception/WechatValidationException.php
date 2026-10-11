<?php

declare(strict_types=1);

namespace NsUltimate\Business\Wechat\Exception;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Raised when WeChat configuration input fails validation before publication.
 */
final class WechatValidationException extends BadRequestHttpException
{
}
