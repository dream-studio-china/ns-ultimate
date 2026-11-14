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
use NsUltimate\Business\Printer\Service\OrderItemFilter;
use NsUltimate\Business\Printer\Service\PrinterTemplateService;
use NsUltimate\Business\Printer\Service\TemplateDefinitionInput;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/printer/templates', name: 'manage-printer-templates-')]
#[IsGranted('ROLE_ADMIN')]
final class PrinterTemplateController extends RestController
{
    use ApiView, ListApiViewMixin, DetailApiViewMixin, CreateApiViewMixin, UpdateApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name', 'kind', 'definition'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['name', 'kind', 'definition', 'sampleData', 'ticketMode', 'itemFilter'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'definition', 'sampleData', 'ticketMode', 'itemFilter'];

    public function __construct(
        protected readonly PrinterTemplateService $service,
        private readonly PrintDocumentRenderer $renderer,
        private readonly OrderItemFilter $itemFilter,
    ) {}

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processCreateContent(array $content, object $entity): array
    {
        if (!$entity instanceof PrinterTemplate) {
            throw new \InvalidArgumentException('Template definition must be an object.');
        }
        $content['definition'] = TemplateDefinitionInput::normalize($content['definition'] ?? null);
        if (!is_string($content['name'] ?? null) || trim($content['name']) === '') {
            throw new \InvalidArgumentException('Template name is required.');
        }
        if (!in_array($content['kind'] ?? null, [PrinterDevice::KIND_RECEIPT, PrinterDevice::KIND_LABEL], true)
            || ($content['definition']['kind'] ?? null) !== $content['kind']) {
            throw new \InvalidArgumentException('Template kind must match the definition kind.');
        }
        $this->validateTicketOptions($content);
        // Sample data is optional; when an API client supplies it, the renderer
        // also validates the definition against that sample.
        if (array_key_exists('sampleData', $content)) {
            if (!is_array($content['sampleData'])) {
                throw new \InvalidArgumentException('Template sampleData must be an object.');
            }
            $this->renderer->render($content['definition'], $content['sampleData']);
        }
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
            $content['definition'] = TemplateDefinitionInput::normalize($content['definition']);
            if (($content['definition']['kind'] ?? null) !== $entity->getKind()) {
                throw new \InvalidArgumentException('Template kind cannot be changed after creation.');
            }
            if (array_key_exists('sampleData', $content)) {
                if (!is_array($content['sampleData'])) {
                    throw new \InvalidArgumentException('Template sampleData must be an object.');
                }
                $this->renderer->render($content['definition'], $content['sampleData']);
            }
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
        if (array_key_exists('itemFilter', $content)) {
            if ($content['itemFilter'] !== null && !is_string($content['itemFilter'])) {
                throw new \InvalidArgumentException('Item filter must be a filter string or null.');
            }
            $this->itemFilter->validate($content['itemFilter']);
        }
    }
}
