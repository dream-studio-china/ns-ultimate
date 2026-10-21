<?php

declare(strict_types=1);

namespace NsUltimate\Business\Printer\Tests;

use NsUltimate\Business\Printer\Controller\Admin\PrinterPreviewController;
use NsUltimate\Business\Printer\Entity\PrinterAccount;
use NsUltimate\Business\Printer\Entity\PrinterDevice;
use NsUltimate\Business\Printer\Entity\PrinterJob;
use NsUltimate\Business\Printer\Service\PrintDocumentRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PrinterJobPreviewTest extends TestCase
{
    public function testReceiptPreviewUsesStoredContentAndSnapshotWidth(): void
    {
        $device = new PrinterDevice(new PrinterAccount(), 'Receipt', 'receipt-demo', 'receipt');
        $snapshot = ['kind' => 'receipt', 'widthMm' => 58, 'nodes' => [['type' => 'unsupported']]];
        $job = new PrinterJob($device, 3, $snapshot, ['name' => 'Different data'], 'Saved<BR>receipt', 'receipt-preview');
        $snapshot['widthMm'] = 80;

        self::assertSame([
            'kind' => 'receipt', 'pages' => ['Saved<BR>receipt'], 'content' => 'Saved<BR>receipt',
            'widthMm' => 58, 'heightMm' => null,
        ], $job->previewDocument());
        self::assertSame(3, $job->getCopies());
    }

    public function testLabelPreviewExtractsSavedPagesAndDoesNotUseCurrentDeviceDimensions(): void
    {
        $device = new PrinterDevice(new PrinterAccount(), 'Label', 'label-demo', 'label', widthMm: 100, heightMm: 80);
        $pages = ['<TEXT x="8" y="8" font="9">Saved\nlabel</TEXT>', '<TEXT x="8" y="8" font="9">Second</TEXT>'];
        $content = implode('', array_map(static fn (string $page): string => '<PAGE><SIZE>50,40</SIZE>'.$page.'</PAGE>', $pages));
        $job = new PrinterJob($device, 2, ['kind' => 'label', 'widthMm' => 50, 'heightMm' => 40, 'nodes' => []], [], $content, 'label-preview');

        self::assertSame([
            'kind' => 'label', 'pages' => $pages, 'content' => $content,
            'widthMm' => 50, 'heightMm' => 40,
        ], $job->previewDocument());
    }

    public function testStoredPreviewDimensionDefaults(): void
    {
        $account = new PrinterAccount();
        $receipt = new PrinterJob(new PrinterDevice($account, 'Receipt', 'receipt', 'receipt'), 1, [], [], 'Saved', 'receipt-default');
        $label = new PrinterJob(new PrinterDevice($account, 'Label', 'label', 'label', widthMm: 50, heightMm: 40), 1, [], [], '<PAGE><SIZE>40,30</SIZE>Saved</PAGE>', 'label-default');

        self::assertSame(80, $receipt->previewDocument()['widthMm']);
        self::assertNull($receipt->previewDocument()['heightMm']);
        self::assertSame(40, $label->previewDocument()['widthMm']);
        self::assertSame(30, $label->previewDocument()['heightMm']);
    }

    public function testPostPreviewIncludesDefaultAndCustomDimensions(): void
    {
        foreach ([['receipt', [], 80, null], ['label', [], 40, 30], ['label', ['widthMm' => 50, 'heightMm' => 40], 50, 40], ['receipt', ['widthMm' => 58], 58, null]] as [$kind, $dimensions, $width, $height]) {
            $request = new Request(content: json_encode([
                'template' => ['kind' => $kind, 'nodes' => [['type' => 'text', 'value' => 'Preview']]] + $dimensions,
                'data' => [],
            ], JSON_THROW_ON_ERROR));
            $response = (new PrinterPreviewController())->preview($request, new PrintDocumentRenderer());
            self::assertSame(200, $response->getStatusCode());
            $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($width, $body['document']['widthMm']);
            self::assertSame($height, $body['document']['heightMm']);
        }
    }
}
