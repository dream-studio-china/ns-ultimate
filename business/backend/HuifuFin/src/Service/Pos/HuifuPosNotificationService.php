<?php

declare(strict_types=1);

namespace NsUltimate\Business\HuifuFin\Service\Pos;

use App\Payment\DTO\PaymentNotifyResult;
use App\Payment\Entity\Invoice;
use App\Payment\Exception\PaymentVerificationException;
use App\Payment\Service\InvoiceServiceInterface;
use App\Trade\Entity\OrderStoreLifecycle;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use NsUltimate\Business\HuifuFin\Client\HuifuResponseParser;
use NsUltimate\Business\HuifuFin\Client\HuifuSigner;
use NsUltimate\Business\HuifuFin\Entity\HuifuPosDevice;
use NsUltimate\Business\HuifuFin\Entity\HuifuPosReceipt;
use NsUltimate\Business\HuifuFin\Entity\StoreHuifuSetting;
use NsUltimate\Business\HuifuFin\Service\Configuration\HuifuProfileResolver;
use Symfony\Component\HttpFoundation\Request;

final class HuifuPosNotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HuifuProfileResolver $profiles,
        private readonly HuifuSigner $signer,
        private readonly HuifuResponseParser $responses,
        private readonly InvoiceServiceInterface $invoices,
    ) {
    }

    public function handle(Request $request, string $merchantUuid): string
    {
        $content = $this->content($request);
        $outerCode = $content['resp_code'] ?? null;
        if (is_scalar($outerCode) && (string) $outerCode !== '00000000') {
            throw new PaymentVerificationException('Huifu POS notification response code is not successful.');
        }
        $respData = $content['resp_data'] ?? null;
        if (is_array($respData)) {
            $data = $respData;
            $signedData = json_encode($respData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } elseif (is_string($respData)) {
            $decoded = json_decode($respData, true);
            $data = is_array($decoded) ? $decoded : [];
            $signedData = $respData;
        } else {
            throw new PaymentVerificationException('Huifu POS notification is missing resp_data.');
        }
        $signature = $content['sign'] ?? null;
        if (!is_string($signature) || $signature === '') {
            throw new PaymentVerificationException('Huifu POS notification is not signed.');
        }
        $resolved = $this->profiles->resolveForMerchant($merchantUuid, false);
        $publicKey = $resolved['credentials']['async_public_key'] ?? $resolved['credentials']['huifu_public_key'] ?? '';
        if ($publicKey === '' || !$this->signer->verify($signedData, $signature, $publicKey)) {
            throw new PaymentVerificationException('Huifu POS notification signature verification failed.');
        }
        $huifuId = $data['huifu_id'] ?? null;
        if (!is_string($huifuId) || $huifuId !== $resolved['merchant']->getHuifuId()) {
            throw new PaymentVerificationException('Huifu POS notification merchant does not match the notified merchant.');
        }
        $terminalId = $data['devs_id'] ?? null;
        if (!is_string($terminalId) || $terminalId === '') {
            throw new PaymentVerificationException('Huifu POS notification is missing the terminal ID.');
        }
        if (isset($data['resp_code']) && !in_array((string) $data['resp_code'], ['00000000', '00000100'], true)) {
            throw new PaymentVerificationException('Huifu POS transaction response code is not successful.');
        }
        $device = $this->entityManager->getRepository(HuifuPosDevice::class)
            ->findOneBy(['merchant' => $resolved['merchant'], 'terminalId' => $terminalId]);
        if (!$device instanceof HuifuPosDevice || !$device->isEnabled()) {
            throw new PaymentVerificationException('Huifu POS device is not registered or is disabled.');
        }
        $setting = $this->entityManager->getRepository(StoreHuifuSetting::class)
            ->findOneBy(['storeUuid' => $device->getStoreUuid()]);
        if (!$setting instanceof StoreHuifuSetting || !$setting->isEnabled() || !$setting->isPosEnabled()) {
            throw new PaymentVerificationException('Huifu POS is not enabled for this Store.');
        }
        if ($this->responses->transStatus($data) !== 'S') {
            return 'RECV_ORD_ID_'.(string) ($data['req_seq_id'] ?? '');
        }
        $transactionId = (string) ($data['out_trans_id'] ?? $data['hf_seq_id'] ?? '');
        if ($transactionId === '') {
            throw new PaymentVerificationException('Huifu POS notification has no provider transaction ID.');
        }
        $existing = $this->entityManager->getRepository(HuifuPosReceipt::class)
            ->findOneBy(['providerTransactionId' => $transactionId]);
        if ($existing instanceof HuifuPosReceipt) {
            return 'RECV_ORD_ID_'.(string) ($data['req_seq_id'] ?? '');
        }
        $amount = $this->amountToCents((string) ($data['trans_amt'] ?? ''));
        if ($amount <= 0) {
            throw new PaymentVerificationException('Huifu POS notification has an invalid amount.');
        }
        $receipt = new HuifuPosReceipt(
            providerTransactionId: $transactionId,
            merchantUuid: $resolved['merchant']->getUuid(),
            storeUuid: $device->getStoreUuid(),
            terminalId: $terminalId,
            amount: $amount,
            currency: (string) ($data['currency'] ?? 'CNY'),
            paidAt: $this->date($data['end_time'] ?? null),
        );
        $receipt->setRawData($this->snapshot($data));
        $this->entityManager->persist($receipt);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return 'RECV_ORD_ID_'.(string) ($data['req_seq_id'] ?? '');
        }

        $invoice = $this->entityManager->getRepository(Invoice::class)
            ->findOneBy(['outTradeNo' => (string) ($data['req_seq_id'] ?? '')]);
        if ($invoice instanceof Invoice && $this->belongsToStore($invoice, $device->getStoreUuid())) {
            if (($data['currency'] ?? null) !== null && strtoupper((string) $data['currency']) !== $invoice->getCurrency()) {
                $receipt->markRejected('POS currency does not match the invoice.');
                $this->entityManager->flush();
                throw new PaymentVerificationException('Huifu POS currency does not match the invoice.');
            }
            if ($invoice->getAmount() !== $amount) {
                $receipt->markRejected('POS amount does not match the invoice.');
                $this->entityManager->flush();
                throw new PaymentVerificationException('Huifu POS amount does not match the invoice.');
            }
            if (!in_array($invoice->getStatus(), [Invoice::STATUS_PENDING, Invoice::STATUS_PAYING], true)) {
                $receipt->markUnclaimed('The invoice is no longer payable.');
                $this->entityManager->flush();
                throw new PaymentVerificationException('Huifu POS invoice is no longer payable.');
            }
            try {
                $this->invoices->handleNotifyResult(new PaymentNotifyResult(
                    payment: 'huifu-pos',
                    outTradeNo: $invoice->getOutTradeNo(),
                    status: Invoice::STATUS_PAID,
                    amount: $amount,
                    currency: $invoice->getCurrency(),
                    transactionId: $transactionId,
                    paidAt: $receipt->getPaidAt(),
                    rawData: $this->snapshot($data),
                    responseBody: 'RECV_ORD_ID_'.(string) ($data['req_seq_id'] ?? ''),
                ));
                $receipt->setInvoice($invoice);
                $receipt->markLinked();
            } catch (\Throwable $exception) {
                $receipt->markUnclaimed('Invoice payment failed: '.$exception->getMessage());
                $this->entityManager->flush();
                throw $exception;
            }
        } else {
            $receipt->markUnclaimed('No matching store-scoped invoice.');
        }
        $this->entityManager->flush();

        return 'RECV_ORD_ID_'.(string) ($data['req_seq_id'] ?? '');
    }

    private function belongsToStore(Invoice $invoice, string $storeUuid): bool
    {
        if ($invoice->getSourceType() !== 'trade_order') {
            return false;
        }
        $lifecycle = $this->entityManager->getRepository(OrderStoreLifecycle::class)
            ->findOneBy(['tradeOrderUuid' => $invoice->getSourceId()]);

        return $lifecycle instanceof OrderStoreLifecycle && $lifecycle->getStoreUuid() === $storeUuid;
    }

    /** @return array<string, mixed> */
    private function content(Request $request): array
    {
        $content = $request->request->all();
        if ($content === []) {
            parse_str($request->getContent(), $content);
        }
        if ($content === []) {
            $decoded = json_decode($request->getContent(), true);
            $content = is_array($decoded) ? $decoded : [];
        }

        return $content;
    }

    private function amountToCents(string $amount): int
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim($amount), $matches)) {
            return 0;
        }
        $fraction = str_pad($matches[2] ?? '0', 2, '0');

        return ((int) $matches[1]) * 100 + (int) $fraction;
    }

    private function date(mixed $value): \DateTimeImmutable
    {
        if (is_string($value) && preg_match('/^\d{14}$/', $value)) {
            $date = \DateTimeImmutable::createFromFormat('YmdHis', $value);
            if ($date instanceof \DateTimeImmutable) {
                return $date;
            }
        }
        if (is_string($value) && $value !== '') {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Throwable) {
            }
        }

        return new \DateTimeImmutable();
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed> */
    private function snapshot(array $data): array
    {
        $keys = ['resp_code', 'resp_desc', 'req_seq_id', 'req_date', 'huifu_id', 'trans_amt', 'trans_stat', 'out_trans_id', 'hf_seq_id', 'devs_id', 'end_time'];
        $snapshot = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $snapshot[$key] = $data[$key];
            }
        }

        return $snapshot;
    }
}
