<?php

declare(strict_types=1);

namespace NsUltimate\Business\Store\Adapter;

use App\Store\Controller\Manage\ProductController as CoreProductController;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Reuses upstream CRUD actions while explicitly allowing the project field. */
#[IsGranted('ROLE_ADMIN')]
final class ManageProductController extends CoreProductController
{
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['name', 'description', 'status', 'metadata', 'store', 'category', 'sortOrder'];

    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'description', 'status', 'metadata', 'store', 'category', 'sortOrder'];
}
