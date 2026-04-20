<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\User\AnonymiseAccount\AnonymiseAccount;
use Daems\Application\User\AnonymiseAccount\AnonymiseAccountInput;
use Daems\Application\User\ChangePassword\ChangePassword;
use Daems\Application\User\ChangePassword\ChangePasswordInput;
use Daems\Application\User\GetProfile\GetProfile;
use Daems\Application\User\GetProfile\GetProfileInput;
use Daems\Application\User\GetUserActivity\GetUserActivity;
use Daems\Application\User\GetUserActivity\GetUserActivityInput;
use Daems\Application\User\UpdateProfile\UpdateProfile;
use Daems\Application\User\UpdateProfile\UpdateProfileInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class UserController
{
    public function __construct(
        private readonly GetProfile $getProfile,
        private readonly UpdateProfile $updateProfile,
        private readonly ChangePassword $changePassword,
        private readonly GetUserActivity $getUserActivity,
        private readonly AnonymiseAccount $anonymiseAccount,
    ) {}

    public function profile(Request $request, array $params): Response
    {
        $id = $params['id'] ?? '';
        if ($id === '') {
            return Response::badRequest('User ID is required.');
        }
        $acting = $request->requireActingUser();

        $output = $this->getProfile->execute(new GetProfileInput($acting, $id));

        if ($output->error !== null) {
            return Response::json(['error' => $output->error], 404);
        }

        return Response::json(['data' => $output->profile]);
    }

    public function update(Request $request, array $params): Response
    {
        $id = $params['id'] ?? '';
        if ($id === '') {
            return Response::badRequest('User ID is required.');
        }
        $acting = $request->requireActingUser();

        $b = $request->all();
        $pick = static fn(string $key): ?string => array_key_exists($key, $b) ? trim((string) $b[$key]) : null;

        $output = $this->updateProfile->execute(new UpdateProfileInput(
            acting:         $acting,
            userId:         $id,
            firstName:      $pick('first_name'),
            lastName:       $pick('last_name'),
            email:          $pick('email'),
            dob:            $pick('dob'),
            country:        $pick('country'),
            addressStreet:  $pick('address_street'),
            addressZip:     $pick('address_zip'),
            addressCity:    $pick('address_city'),
            addressCountry: $pick('address_country'),
        ));

        if ($output->error !== null) {
            return Response::badRequest($output->error);
        }

        return Response::json(['data' => ['updated' => true]]);
    }

    public function anonymise(Request $request, array $params): Response
    {
        $id = $params['id'] ?? '';
        if ($id === '') {
            return Response::badRequest('User ID is required.');
        }
        $acting = $request->requireActingUser();

        try {
            $this->anonymiseAccount->execute(new AnonymiseAccountInput($id, $acting));
        } catch (ForbiddenException $e) {
            return Response::json(['error' => 'forbidden', 'message' => $e->getMessage()], 403);
        } catch (NotFoundException) {
            return Response::json(['error' => 'not_found'], 404);
        } catch (ValidationException $e) {
            return Response::json(['error' => 'validation_failed', 'errors' => $e->fields()], 422);
        }

        return Response::json(null, 204);
    }

    public function activity(Request $request, array $params): Response
    {
        $id = $params['id'] ?? '';
        if ($id === '') {
            return Response::badRequest('User ID is required.');
        }
        $acting = $request->requireActingUser();

        $output = $this->getUserActivity->execute(new GetUserActivityInput($acting, $id));
        return Response::json(['data' => $output->data]);
    }

    public function changePasswordAction(Request $request, array $params): Response
    {
        $id = $params['id'] ?? '';
        if ($id === '') {
            return Response::badRequest('User ID is required.');
        }
        $acting = $request->requireActingUser();

        $b = $request->all();
        $currentPassword = (string) ($b['current_password'] ?? '');
        $newPassword     = (string) ($b['new_password'] ?? '');
        $confirmPassword = (string) ($b['confirm_password'] ?? '');

        if ($newPassword !== $confirmPassword) {
            return Response::badRequest('New passwords do not match.');
        }

        $output = $this->changePassword->execute(
            new ChangePasswordInput($acting, $id, $currentPassword, $newPassword),
        );

        if ($output->error !== null) {
            return Response::json(['error' => $output->error], 422);
        }

        return Response::json(['data' => ['updated' => true]]);
    }
}
