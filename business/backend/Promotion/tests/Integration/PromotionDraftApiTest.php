<?php

declare(strict_types=1);

namespace NsUltimate\Business\Promotion\Tests\Integration;

use App\Identity\Entity\User;
use App\Identity\Security\TokenManager;
use App\Promotion\Entity\PromotionTemplate;
use App\Promotion\Entity\Promotion;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use NsUltimate\Integration\Backend\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PromotionDraftApiTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $token;

    protected static function getKernelClass(): string { return Kernel::class; }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($this->em);
        $tool->dropSchema($metadata); $tool->createSchema($metadata);
        $user = (new User())->setUsername('draft-admin')->setEmail('draft-admin@example.test')->setRoles(['ROLE_ADMIN']);
        $this->em->persist($user); $this->em->flush();
        $this->token = self::getContainer()->get(TokenManager::class)->createAccessToken($user);
    }

    public function testDraftRouteExecutesStrategiesWithoutPersistingAnything(): void
    {
        $response = $this->request($this->input());
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $data = json_decode($response->getContent(), true)['data'];
        self::assertSame(0, json_decode($response->getContent(), true)['code']);
        self::assertTrue($data['valid']); self::assertTrue($data['passed']);
        self::assertSame(9000, $data['cases'][1]['actual']['totalAmount']);
        foreach (['promotion', 'promotion_template', 'trade_order'] as $table) self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table));
    }

    public function testStoredTemplateRevisionChangesAndMissingRelationsFailClosed(): void
    {
        $input = $this->input();
        $template = (new PromotionTemplate())->setName('Stored draft')->setType('full_reduction')->setDsl($input['template']['dsl']);
        $this->em->persist($template); $this->em->flush();
        unset($input['template']); $input['templateId'] = $template->getId();
        $first = json_decode($this->request($input)->getContent(), true)['data'];
        self::assertTrue($first['passed']);
        $template->setEnabled(true); $this->em->flush();
        $second = json_decode($this->request($input)->getContent(), true)['data'];
        self::assertNotSame($first['templateRevision'], $second['templateRevision']);
        self::assertSame(404, $this->request(['templateId' => 999999])->getStatusCode());
        self::assertSame(400, $this->request(['templateId' => '1'])->getStatusCode());
    }

    public function testAuthorizationAndBoundedInvalidRequests(): void
    {
        $this->token = '';
        self::assertSame(401, $this->request($this->input())->getStatusCode());
        $user = (new User())->setUsername('draft-user')->setEmail('draft-user@example.test');
        $this->em->persist($user); $this->em->flush();
        $this->token = self::getContainer()->get(TokenManager::class)->createAccessToken($user);
        self::assertSame(403, $this->request($this->input())->getStatusCode());
    }

    public function testCampaignInspectionReturnsOnlyValidatedParametersWithoutWrites(): void
    {
        $input = $this->input();
        $template = (new PromotionTemplate())->setName('Inspection template')->setType('full_reduction')->setDsl($input['template']['dsl']);
        $campaign = (new Promotion())->setName('Inspection campaign')->setTemplate($template)->setConfig($input['config']);
        $this->em->persist($template); $this->em->persist($campaign); $this->em->flush();
        $response = $this->request(['campaignId' => $campaign->getId()]);
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $data = json_decode($response->getContent(), true)['data'];
        self::assertTrue($data['valid']);
        self::assertSame($input['config'], $data['resolved']['config']);
        self::assertSame($template->getId(), $data['resolved']['templateId']);
        self::assertArrayNotHasKey('name', $data['resolved']);
        $campaign->setConfig($input['config'] + ['unknown' => 'not-disclosed']); $this->em->flush();
        $data = json_decode($this->request(['campaignId' => $campaign->getId()])->getContent(), true)['data'];
        self::assertFalse($data['valid']); self::assertArrayNotHasKey('resolved', $data);
        self::assertSame(400, $this->request(['campaignId' => $campaign->getId(), 'config' => []])->getStatusCode());
    }

    public function testUnknownArgumentsAndBrokenDslAreNotSuccessfulChecks(): void
    {
        self::assertSame(400, $this->request($this->input() + ['url' => '/arbitrary'])->getStatusCode());
        $input = $this->input(); $input['template']['dsl'] = 'invalid';
        $response = $this->request($input);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true)['data'];
        self::assertFalse($data['valid']); self::assertSame('syntax', $data['errors'][0]['code']);
        self::assertSame(413, $this->request(['template' => ['dsl' => str_repeat('x', 65000)]])->getStatusCode());
    }

    private function input(): array
    {
        return ['template' => ['type' => 'full_reduction', 'phase' => 0, 'dsl' => "type: full_reduction\nwhen:\n  cart.subtotal >= config.threshold\ndo:\n  discount order config.amount\n"],
            'config' => ['threshold' => 10000, 'amount' => 10], 'cases' => [
                ['items' => [['specificationId' => 1, 'quantity' => 1, 'unitPrice' => 9999]], 'expected' => ['totalAmount' => 9999]],
                ['items' => [['specificationId' => 1, 'quantity' => 1, 'unitPrice' => 10000]], 'expected' => ['totalAmount' => 9000]],
            ]];
    }

    private function request(array $body): Response
    {
        $request = Request::create('/api/v1/manage/promotion-drafts/check', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], content: json_encode($body, JSON_THROW_ON_ERROR));
        $response = self::$kernel->handle($request);
        self::$kernel->terminate($request, $response);
        return $response;
    }
}
