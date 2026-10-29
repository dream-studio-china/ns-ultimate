<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Controller\Admin;

use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterTemplate;
use NsUltimate\Business\Printer\Service\PrintDocumentRenderer;
use NsUltimate\Business\Printer\Service\PrinterTemplateService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/printer/templates', name: 'manage-printer-templates-')]
#[IsGranted('ROLE_ADMIN')]
final class PrinterTemplateController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name', 'kind', 'definition', 'sampleData'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['name', 'kind', 'definition', 'sampleData', 'ticketMode', 'itemFilter'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'definition', 'sampleData', 'ticketMode', 'itemFilter'];

    public function __construct(
        protected readonly PrinterTemplateService $service,
        private readonly PrintDocumentRenderer $renderer,
    ) {}

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof PrinterTemplate || !is_array($content['definition'] ?? null) || !is_array($content['sampleData'] ?? null)) {
            throw new \InvalidArgumentException('Template definition and sampleData must be objects.');
        }
        if (!is_string($content['name'] ?? null) || trim($content['name']) === '') {
            throw new \InvalidArgumentException('Template name is required.');
        }
        if (!in_array($content['kind'] ?? null, [PrinterDevice::KIND_RECEIPT, PrinterDevice::KIND_LABEL], true)
            || ($content['definition']['kind'] ?? null) !== $content['kind']) {
            throw new \InvalidArgumentException('Template kind must match the definition kind.');
        }
        $this->validateTicketOptions($content);
        $this->renderer->render($content['definition'], $content['sampleData']);
        unset($content['sampleData']);
        return $content;
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processUpdateContent(array $content, ?object $entity = null): array
    {
        if (!$entity instanceof PrinterTemplate) { throw new \LogicException('Unexpected printer template entity.'); }
        if (isset($content['name']) && (!is_string($content['name']) || trim($content['name']) === '')) {
            throw new \InvalidArgumentException('Template name cannot be empty.');
        }
        $this->validateTicketOptions($content);
        if (array_key_exists('definition', $content)) {
            if (!is_array($content['definition']) || !is_array($content['sampleData'] ?? null)) {
                throw new \InvalidArgumentException('Template updates require a definition and sampleData object.');
            }
            if (($content['definition']['kind'] ?? null) !== $entity->getKind()) {
                throw new \InvalidArgumentException('Template kind cannot be changed after creation.');
            }
            $this->renderer->render($content['definition'], $content['sampleData']);
        }
        unset($content['sampleData']);
        return $content;
    }

    /** @param array<string, mixed> $content */
    private function validateTicketOptions(array $content): void
    {
        if (array_key_exists('ticketMode', $content)
            && !in_array($content['ticketMode'], [PrinterTemplate::TICKET_SINGLE, PrinterTemplate::TICKET_PER_UNIT], true)) {
            throw new \InvalidArgumentException('Ticket mode must be single or per_unit.');
        }
        if (array_key_exists('itemFilter', $content)
            && !in_array($content['itemFilter'], [PrinterTemplate::ITEMS_ALL, PrinterTemplate::ITEMS_KITCHEN], true)) {
            throw new \InvalidArgumentException('Item filter must be all or kitchen.');
        }
    }
}
