<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\App;

use App\Common\Service\CategoryServiceInterface;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/app/categories', name: 'app-categories-')]
class CategoryController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly CategoryServiceInterface $service
    ) {}

    /** @return array<string, mixed> */
    protected function commonFilter(): array
    {
        return ['enabled' => true];
    }

    /**
     * @param array<string, mixed>|\Doctrine\ORM\QueryBuilder|\App\Core\Query\DqlExpression|null $filter
     * @return array<string, mixed>|\Doctrine\ORM\QueryBuilder|\App\Core\Query\DqlExpression|null
     */
    protected function detailFilter(array|\Doctrine\ORM\QueryBuilder|\App\Core\Query\DqlExpression|null $filter = null)
    {
        if (is_array($filter)) {
            unset($filter['enabled']);
        }
        return $filter;
    }
}
