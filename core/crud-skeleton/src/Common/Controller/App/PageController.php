<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\App;

use App\Common\Service\PageServiceInterface;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/app/pages', name: 'app-pages-')]
class PageController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly PageServiceInterface $service
    ) {}

    /** @return array<string, string> */
    protected function commonFilter(): array
    {
        return ['status' => 'published'];
    }

    /**
     * @param array<string, mixed>|\Doctrine\ORM\QueryBuilder|\App\Core\Query\DqlExpression|null $filter
     * @return array<string, mixed>|\Doctrine\ORM\QueryBuilder|\App\Core\Query\DqlExpression|null
     */
    protected function detailFilter(array|\Doctrine\ORM\QueryBuilder|\App\Core\Query\DqlExpression|null $filter = null)
    {
        if (is_array($filter)) {
            unset($filter['status']);
        }
        return $filter;
    }
}
