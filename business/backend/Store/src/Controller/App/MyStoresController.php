<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Controller\App;

use App\Core\Controller\RestController;
use App\Core\View\ApiViewMessages;
use App\Identity\Entity\User;
use App\Store\Entity\Membership;
use App\Store\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class MyStoresController extends RestController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('/app/me/stores', name: 'app-me-stores', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function listAction(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->warning(ApiViewMessages::ACCESS_DENIED, 401, '', 401);
        }

        $stores = $this->em->createQueryBuilder()
            ->select('s.uuid AS uuid', 's.name AS name', 's.code AS code', 'm.role AS role')
            ->from(Membership::class, 'm')
            ->join('m.store', 's')
            ->where('m.userUuid = :userUuid AND m.status = :membershipStatus AND s.status = :storeStatus')
            ->setParameter('userUuid', $user->getUuid())
            ->setParameter('membershipStatus', Membership::STATUS_ACTIVE)
            ->setParameter('storeStatus', Store::STATUS_ACTIVE)
            ->orderBy('s.name', 'ASC')
            ->addOrderBy('s.code', 'ASC')
            ->getQuery()->getArrayResult();

        $response = $this->success($stores);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
