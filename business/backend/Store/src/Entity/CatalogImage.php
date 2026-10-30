<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Entity;

use App\Common\Entity\Media;
use Doctrine\ORM\Mapping as ORM;

/** Store ownership is separate from a staff member's personal media collection. */
#[ORM\Entity]
#[ORM\Table(name: 'store_catalog_image')]
final class CatalogImage
{
    #[ORM\Id, ORM\Column(length: 36)]
    private string $uuid;

    #[ORM\Column(length: 36)]
    private string $storeUuid;

    #[ORM\ManyToOne(targetEntity: Media::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Media $media;

    public function __construct(string $storeUuid, Media $media)
    {
        $this->uuid = $media->getUuid();
        $this->storeUuid = $storeUuid;
        $this->media = $media;
    }

    public function getPath(): string { return $this->media->getPath(); }
}
