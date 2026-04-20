<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Backstage\DeleteEventImage\DeleteEventImage;
use Daems\Application\Backstage\DeleteEventImage\DeleteEventImageInput;
use Daems\Application\Backstage\UploadEventImage\UploadEventImage;
use Daems\Application\Backstage\UploadEventImage\UploadEventImageInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Storage\ImageStorageException;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class MediaController
{
    public function __construct(
        private readonly UploadEventImage $uploadEventImage,
        private readonly DeleteEventImage $deleteEventImage,
    ) {}

    public function uploadEventImage(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $id = (string) ($params['id'] ?? '');
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || !isset($file['tmp_name'], $file['type'], $file['size']) || $file['error'] !== UPLOAD_ERR_OK) {
            return Response::json(['error' => 'validation_failed', 'errors' => ['file' => 'upload_error']], 422);
        }
        if ($file['size'] > 10 * 1024 * 1024) {
            return Response::json(['error' => 'validation_failed', 'errors' => ['file' => 'too_large']], 422);
        }
        try {
            $out = $this->uploadEventImage->execute(new UploadEventImageInput(
                $acting, $id, (string) $file['tmp_name'], (string) $file['type'],
            ));
            return Response::json(['data' => $out->toArray()], 201);
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        } catch (NotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        } catch (ValidationException $e) {
            return Response::json(['error' => 'validation_failed', 'errors' => $e->fields()], 422);
        } catch (ImageStorageException $e) {
            return Response::json(['error' => 'upload_failed', 'reason' => $e->getMessage()], 422);
        }
    }

    public function deleteEventImage(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $id = (string) ($params['id'] ?? '');
        $url = (string) $request->string('url');
        if ($url === '') {
            return Response::json(['error' => 'validation_failed', 'errors' => ['url' => 'required']], 422);
        }
        try {
            $this->deleteEventImage->execute(new DeleteEventImageInput($acting, $id, $url));
            return Response::json(null, 204);
        } catch (ForbiddenException) {
            return Response::json(['error' => 'forbidden'], 403);
        } catch (NotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        }
    }
}
