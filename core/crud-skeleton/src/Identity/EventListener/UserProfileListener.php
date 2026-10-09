<?php

declare(strict_types=1);

namespace App\Identity\EventListener;

use App\Identity\Entity\Profile;
use App\Identity\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::postPersist)]
class UserProfileListener
{
    public function postPersist(\Doctrine\ORM\Event\PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof User) {
            return;
        }

        $em = $args->getObjectManager();

        $existing = $em->getRepository(Profile::class)->findOneBy(['user' => $entity]);
        if ($existing !== null) {
            return;
        }

        $profile = new Profile($entity, Profile::LEVEL_BRONZE);
        $em->persist($profile);
        $em->flush();
    }
}
