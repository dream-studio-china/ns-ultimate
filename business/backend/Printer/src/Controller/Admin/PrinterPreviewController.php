<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Controller\Admin;

use NsUltimate\Business\Printer\Service\PrintDocumentRenderer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/printer', name: 'manage-printer-')]
#[IsGranted('ROLE_ADMIN')]
final class PrinterPreviewController
{
    #[Route('/preview', name: 'preview', methods: ['POST'])]
    public function preview(Request $request, PrintDocumentRenderer $renderer): JsonResponse
    {
        try {
            $input = $request->toArray();
            $template = $input['template'] ?? null;
            $data = $input['data'] ?? null;
            if (!is_array($template) || !is_array($data)) {
                return new JsonResponse(['error' => 'template and data must be objects.'], 400);
            }
            $document = $renderer->render($template, $data);
            return new JsonResponse([
                'document' => $document,
                'cURL' => "curl -X POST 'https://your-host/api/v1/manage/printer/preview' \\\n  -H 'Authorization: Bearer YOUR_TOKEN' -H 'Content-Type: application/json' \\\n  --data '<template-and-data-json>'",
                'javascript' => "import request from '@/utils/request'\n\nawait request({ url: '/api/v1/manage/printer/preview', method: 'post', data: { template, data } })",
                'warnings' => ['Simulation is an approximation; verify output on the physical printer.'],
            ]);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 400);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Invalid JSON request.'], 400);
        }
    }
}
