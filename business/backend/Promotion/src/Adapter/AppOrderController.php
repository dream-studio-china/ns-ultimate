<?php

declare(strict_types=1);

namespace NsUltimate\Business\Promotion\Adapter;

use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Retain upstream routes, ownership filters and transitions. */
#[IsGranted('ROLE_USER')]
final class AppOrderController extends \App\Trade\Controller\App\OrderController
{
    use OrderPromotionCreation;
}
