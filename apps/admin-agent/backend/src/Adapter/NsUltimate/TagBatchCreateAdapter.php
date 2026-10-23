<?php

declare(strict_types=1);

namespace NsUltimate\Apps\AdminAgent\Backend\Adapter\NsUltimate;

/** Tag batch creation uses the existing single-record Tag policy and slug uniqueness. */
final class TagBatchCreateAdapter extends DelegatingBatchCreateAdapter
{
    public function __construct(TagMutationAdapter $inner)
    {
        parent::__construct($inner);
    }

    protected function identity(array $changes): string
    {
        // The common_tag.slug unique index uses a case-insensitive collation.
        return 'slug:'.mb_strtolower($changes['slug'], 'UTF-8');
    }
}
