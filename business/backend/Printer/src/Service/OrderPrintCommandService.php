<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Service;

use App\Core\Utils\UUID;
use App\Store\Entity\Store;
use App\Trade\Entity\Order;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\Printer\Adapter\TradeOrderPrintAdapter;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterOrderRequest;
use NsUltimate\Business\Printer\Entity\PrinterOrderRule;
use NsUltimate\Business\Printer\Entity\PrinterTemplate;
use NsUltimate\Business\Store\Service\StaffCommandService;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Staff commands freeze trusted order data; only the dispatcher contacts providers. */
final class OrderPrintCommandService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StaffCommandService $commands,
        private readonly OrderPrintRequestWriter $writer,
        private readonly TradeOrderPrintAdapter $snapshots,
        private readonly PrintDocumentRenderer $renderer,
        private readonly OrderPrintRuleMatcher $matcher,
    ) {}

    /** @return list<array<string, mixed>> */
    public function options(Store $store): array
    {
        $options = [];
        foreach ($this->em->getRepository(PrinterOrderRule::class)->findBy(['storeUuid' => $store->getUuid(), 'enabled' => true], ['id' => 'ASC'], 50) as $rule) {
            if (!in_array('manual', $rule->getEvents(), true) || !$this->matcher->isValid($rule->toRule())) continue;
            try { [$device, $template] = $this->output($rule, $store); }
            catch (HttpException) { continue; }
            $options[] = ['ruleUuid' => $rule->getUuid(), 'name' => $rule->getName(), 'deviceName' => $device->getName(),
                'kind' => $device->getKind(), 'copies' => $rule->getCopies(), 'ticketMode' => $template->getTicketMode()];
        }
        return $options;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed> */
    public function enqueue(Store $store, string $actorUuid, string $tradeOrderUuid, array $payload): array
    {
        if (array_diff(array_keys($payload), ['commandUuid', 'ruleUuid', 'reason']) !== []
            || !is_string($payload['ruleUuid'] ?? null) || !UUID::is_valid($payload['ruleUuid'])
            || !is_string($payload['reason'] ?? null) || trim($payload['reason']) === '' || strlen($payload['reason']) > 500) {
            throw new HttpException(422, 'invalid_fields');
        }
        $payload['reason'] = trim($payload['reason']);
        return $this->commands->run($store, $actorUuid, 'order.print.'.$tradeOrderUuid, $payload, function () use ($store, $actorUuid, $tradeOrderUuid, $payload): array {
            $order = $this->em->getRepository(Order::class)->findByUuidForUpdate($tradeOrderUuid);
            $scope = $this->em->getRepository(OrderStoreLifecycle::class)->findOneBy(['tradeOrderUuid' => $tradeOrderUuid, 'storeUuid' => $store->getUuid()]);
            if (!$order instanceof Order || !$scope instanceof OrderStoreLifecycle) throw new HttpException(404, 'order_not_found');
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            if (!in_array($order->getStatus(), [Order::STATUS_CONFIRMED, Order::STATUS_PAID, Order::STATUS_COMPLETED], true)) {
                throw new HttpException(409, 'order_not_printable');
            }
            $rule = $this->em->getRepository(PrinterOrderRule::class)->findOneBy(['uuid' => $payload['ruleUuid'], 'storeUuid' => $store->getUuid()]);
            if (!$rule instanceof PrinterOrderRule) throw new HttpException(404, 'print_rule_not_found');
            $this->em->refresh($rule, LockMode::PESSIMISTIC_WRITE);
            if (!$rule->isEnabled() || $rule->getStoreUuid() !== $store->getUuid()) throw new HttpException(409, 'print_rule_unavailable');
            if (!in_array('manual', $rule->getEvents(), true) || !$this->matcher->isValid($rule->toRule())) {
                throw new HttpException(409, 'print_rule_not_manual');
            }
            [$device, $template] = $this->output($rule, $store);
            $definition = $template->getDefinition();
            if ($device->getKind() === PrinterDevice::KIND_LABEL) {
                if ($device->getWidthMm() !== null) $definition['widthMm'] = $device->getWidthMm();
                if ($device->getHeightMm() !== null) $definition['heightMm'] = $device->getHeightMm();
            }
            try {
                $tickets = $this->snapshots->buildPrintData($order, $store, $template->getTicketMode() === PrinterTemplate::TICKET_PER_UNIT, $template->getItemFilter());
            } catch (\OverflowException) { throw new HttpException(422, 'print_limit_exceeded'); }
            if ($tickets === []) throw new HttpException(409, 'no_printable_items');
            $configuration = $rule->toRule();
            $configuration['templateSnapshot'] = $definition;
            $configuration['ticketMode'] = $template->getTicketMode();
            $configuration['itemFilter'] = $template->getItemFilter();
            $configuration['actorUuid'] = $actorUuid;
            $configuration['reason'] = $payload['reason'];
            $requests = [];
            $pages = 0;
            foreach ($tickets as $index => $data) {
                $pages += count($this->renderer->render($definition, $data)['pages']) * $rule->getCopies();
                if ($pages > 500) throw new HttpException(422, 'print_limit_exceeded');
                $key = hash('sha256', json_encode(['staff', $store->getUuid(), $actorUuid, $payload['commandUuid'], $index], JSON_THROW_ON_ERROR));
                $request = new PrinterOrderRequest($key, $tradeOrderUuid, $store->getUuid(), $rule->getUuid(), 'manual', $configuration, $data);
                $this->writer->insert($request);
                $requests[] = $request->getUuid();
            }
            return ['before' => ['tradeOrderUuid' => $tradeOrderUuid, 'ruleUuid' => $rule->getUuid(), 'orderStatus' => $order->getStatus()],
                'result' => ['commandUuid' => $payload['commandUuid'], 'tradeOrderUuid' => $tradeOrderUuid, 'status' => 'queued', 'requestUuids' => $requests]];
        });
    }

    /** @return array{PrinterDevice, PrinterTemplate} */
    private function output(PrinterOrderRule $rule, Store $store): array
    {
        $device = $this->em->getRepository(PrinterDevice::class)->findOneBy(['uuid' => $rule->getDeviceUuid()]);
        $template = $this->em->getRepository(PrinterTemplate::class)->findOneBy(['uuid' => $rule->getTemplateUuid()]);
        if (!$device instanceof PrinterDevice || !$template instanceof PrinterTemplate
            || !$device->isEnabled() || !$device->getAccount()->isEnabled() || !$template->isEnabled()
            || $device->getStoreUuid() !== $store->getUuid() || $device->getKind() !== $template->getKind()
            || $rule->getCopies() < 1 || $rule->getCopies() > 100) throw new HttpException(409, 'print_output_unavailable');
        return [$device, $template];
    }
}
