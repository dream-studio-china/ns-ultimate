<?php

declare(strict_types=1);

namespace NsUltimate\Business\Accounting\Controller;

use NsUltimate\Business\Accounting\Service\LedgerError;
use NsUltimate\Business\Accounting\Service\LedgerInput;
use NsUltimate\Business\Accounting\Service\LedgerPage;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response and request helpers shared by the Finance REST controllers.
 *
 * @phpstan-require-extends \App\Core\Controller\RestController
 */
trait LedgerControllerSupport
{
    protected function success(mixed $content = '', string $addition_message = 'SUCCESS', int $status = 200): Response
    {
        if ($content instanceof LedgerPage) {
            return new JsonResponse([
                'data' => $content->items,
                'code' => 0,
                'message' => $addition_message,
                'paginator' => $content->paginator(),
            ] + $content->extra, $status);
        }
        if ($content instanceof \stdClass && property_exists($content, 'metadata')) {
            // The generic serializer would convert empty metadata objects into lists.
            return new JsonResponse(['data' => $content, 'code' => 0, 'message' => $addition_message], $status);
        }

        return parent::success($content, $addition_message, $status);
    }

    /**
     * Query parameters other than the framework locale.
     *
     * @return array<string, mixed>
     */
    protected function ledgerParameters(Request $request): array
    {
        $all = $request->query->all();
        unset($all['_locale']);

        return $all;
    }

    /**
     * Decode a bounded JSON object body. JSON objects (including empty ones)
     * in `metadata` stay objects so they are not confused with JSON lists.
     *
     * @return array<string, mixed>
     */
    protected function ledgerPayload(Request $request): array
    {
        LedgerInput::fields($this->ledgerParameters($request));
        if ($request->getContentTypeFormat() !== 'json') {
            throw new LedgerError('json_required', 415);
        }
        $body = $request->getContent();
        if (strlen($body) > 65536) {
            throw new LedgerError('body_too_large', 413);
        }
        try {
            $object = json_decode($body, false, 16, JSON_THROW_ON_ERROR);
            if (!is_object($object) || (isset($object->lines) && !is_array($object->lines))) {
                throw new LedgerError('invalid_body');
            }
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            if (property_exists($object, 'metadata')) {
                $data['metadata'] = $object->metadata;
            }

            return $data;
        } catch (\JsonException) {
            throw new LedgerError('invalid_body');
        }
    }
}
