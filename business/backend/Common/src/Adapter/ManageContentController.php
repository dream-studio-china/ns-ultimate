<?php

declare(strict_types=1);

namespace NsUltimate\Business\Common\Adapter;

use App\Common\Entity\Content;
use App\Common\Repository\CategoryRepository;
use App\Common\Repository\TagRepository;
use App\Common\Service\ContentServiceInterface;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Reuses the core CRUD mixins without depending on its final concrete service. */
#[IsGranted('ROLE_ADMIN')]
final class ManageContentController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['title'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['title', 'body', 'category', 'tags', 'metadata', 'sortOrder'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['title', 'body', 'category', 'tags', 'metadata', 'sortOrder'];

    public function __construct(
        protected readonly ContentServiceInterface $service,
        private readonly CategoryRepository $categoryRepository,
        private readonly TagRepository $tagRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processCreateContent(array $content, object $entity): array
    {
        return $this->processContent($content, $entity);
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function processContent(array $content, ?object $entity = null): array
    {
        if (!$entity instanceof Content) {
            return $content;
        }

        // Core mutation reflection cannot inspect private association fields inherited by a subtype.
        if (array_key_exists('category', $content)) {
            $id = $content['category'];
            if ($id !== null && !is_int($id) && !is_string($id)) {
                throw new \InvalidArgumentException('category must be an id or null.');
            }
            $category = $id ? $this->categoryRepository->find($id) : null;
            if ($id && $category === null) {
                throw new NotFoundHttpException('Content category not found.');
            }
            $entity->setCategory($category);
            unset($content['category']);
        }

        if (array_key_exists('tags', $content)) {
            if (!is_array($content['tags'])) {
                throw new \InvalidArgumentException('tags must be an array of ids.');
            }
            $tags = [];
            foreach ($content['tags'] as $id) {
                if (!is_int($id) && !is_string($id)) {
                    throw new \InvalidArgumentException('tags must be an array of ids.');
                }
                $tag = $this->tagRepository->find($id);
                if ($tag === null) {
                    throw new NotFoundHttpException('Content tag not found.');
                }
                $tags[] = $tag;
            }
            foreach ($entity->getTags()->toArray() as $tag) {
                $entity->removeTag($tag);
            }
            foreach ($tags as $tag) {
                $entity->addTag($tag);
            }
            unset($content['tags']);
        }

        return $content;
    }
}
