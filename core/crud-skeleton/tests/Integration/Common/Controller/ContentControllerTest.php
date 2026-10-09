<?php

declare(strict_types=1);

namespace App\Tests\Integration\Common\Controller;

use App\Common\Entity\Content;
use App\Tests\Integration\DatabaseBootstrapTrait;
use App\Tests\Integration\IntegrationWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Covers the previously 0%-covered App\Common\Controller\App\ContentController
 * (listAction / detailAction from the ListApiViewMixin / DetailApiViewMixin traits).
 */
final class ContentControllerTest extends IntegrationWebTestCase
{
    use DatabaseBootstrapTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->bootTestDatabase();
        self::ensureKernelShutdown();
        $this->client = static::createAuthenticatedClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM ' . Content::class)->execute();
    }

    public function testListReturnsAllContents(): void
    {
        $this->em->persist(new Content('First Title', 'First body'));
        $this->em->persist(new Content('Second Title', 'Second body'));
        $this->em->flush();

        $this->client->request('GET', '/api/v1/app/contents');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $data['code']);
        self::assertCount(2, $data['data']);

        $titles = array_column($data['data'], 'title');
        self::assertContains('First Title', $titles);
        self::assertContains('Second Title', $titles);
    }

    public function testDetailReturnsContent(): void
    {
        $content = new Content('Detail Title', 'Detail body');
        $this->em->persist($content);
        $this->em->flush();
        $id = (int) $content->getId();

        $this->client->request('GET', '/api/v1/app/contents/' . $id);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $data['code']);
        self::assertSame('Detail Title', $data['data']['title']);
        self::assertSame('Detail body', $data['data']['body']);
        self::assertSame($id, $data['data']['id']);
    }

    public function testDetailMissingReturns404(): void
    {
        $this->client->request('GET', '/api/v1/app/contents/999999');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testListRequiresAuthentication(): void
    {
        self::ensureKernelShutdown();
        $anonymous = static::createClient();

        $anonymous->request('GET', '/api/v1/app/contents');
        self::assertNotSame(Response::HTTP_OK, $anonymous->getResponse()->getStatusCode());
    }
}
