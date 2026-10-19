<?php

declare(strict_types=1);

namespace NsUltimate\Business\Common\Entity;

use App\Common\Entity\Content as CoreContent;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Content extends CoreContent
{
    #[ORM\Column(type: 'integer', nullable: true, options: ['default' => 0])]
    private int $sortOrder = 0;

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
        $this->touch();

        return $this;
    }
}
