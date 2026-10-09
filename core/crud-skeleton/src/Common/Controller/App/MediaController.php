<?php /** @noinspection PhpMissingParentConstructorInspection */

namespace App\Common\Controller\App;

use App\Common\Service\MediaServiceInterface;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Identity\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Exception\ValidatorException;

#[Route('/app/media', name: 'app-media-')]
class MediaController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin, DeleteApiViewMixin;

    public function __construct(
        protected readonly MediaServiceInterface $service
    ) {}

    /** @return array<string, mixed> */
    protected function commonFilter(): array
    {
        return ['user' => $this->getUser()];
    }

    #[Route('/upload', name: 'upload', methods: ['POST'])]
    public function uploadAction(Request $request): Response
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->warning('Uploaded file is required', 400, '', 400);
        }

        try {
            $media = $this->service->createFromUpload(
                $file,
                $request->request->has('storage') ? (string) $request->request->get('storage') : null,
                $request->request->all(),
                $this->uploadOwner(),
            );
        } catch (ValidatorException|\RuntimeException $exception) {
            return $this->warning($exception->getMessage(), 400, '', 400);
        } catch (\Throwable $exception) {
            return $this->warning($exception->getMessage() ?: 'Upload failed', 500, '', 500);
        }

        return $this->success($media, 'Uploaded', 201);
    }

    protected function uploadOwner(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
