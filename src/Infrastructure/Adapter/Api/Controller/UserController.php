<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\User\ChangePassword\ChangePassword;
use Daems\Application\User\ChangePassword\ChangePasswordInput;
use Daems\Application\User\DeleteAccount\DeleteAccount;
use Daems\Application\User\DeleteAccount\DeleteAccountInput;
use Daems\Application\User\GetProfile\GetProfile;
use Daems\Application\User\GetProfile\GetProfileInput;
use Daems\Application\User\GetUserActivity\GetUserActivity;
use Daems\Application\User\GetUserActivity\GetUserActivityInput;
use Daems\Application\User\UpdateProfile\UpdateProfile;
use Daems\Application\User\UpdateProfile\UpdateProfileInput;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class UserController
{
    public function __construct(
        private readonly GetProfile $getProfile,
        private readonly UpdateProfile $updateProfile,
        private readonly ChangePassword $changePassword,
        private readonly GetUserActivity $getUserActivity,
        private readonly DeleteAccount $deleteAccount,
    ) {}

    public function profile(Request $request, array $params): Response
    {
        $id = $params['id'] ?? '';
        if ($id === '') {
            return Response::badRequest('User ID is required.');
        }

        $output = $this->getProfile->execute(new GetProfileInput($id));

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

        $b = $request->all();

        $output = $this->updateProfile->execute(new UpdateProfileInput(
            userId:         $id,
            firstName:      trim((string) ($b['first_name'] ?? '')),
            lastName:       trim((string) ($b['last_name'] ?? '')),
            email:          trim((string) ($b['email'] ?? '')),
            dob:            trim((string) ($b['dob'] ?? '')),
            country:        trim((string) ($b['country'] ?? '')),
            addressStreet:  trim((string) ($b['address_street'] ?? '')),
            addressZip:     trim((string) ($b['address_zip'] ?? '')),
            addressCity:    trim((string) ($b['address_city'] ?? '')),
            addressCountry: trim((string) ($b['address_country'] ?? '')),
        ));

        if ($output->error !== null) {
            return Response::badRequest($output->error);
        }

        return Response::json(['data' => ['updated' => true]]);
    }

    public function delete(Request $request, array $params): Response
    {
        $id = $params['id'] ?? '';
        if ($id === '') {
            return Response::badRequest('User ID is required.');
        }

        $output = $this->deleteAccount->execute(new DeleteAccountInput($id));

        if (!$output->deleted) {
            return Response::json(['error' => $output->error], 404);
        }

        return Response::json(['data' => ['deleted' => true]]);
    }

    public function activity(Request $request, array $params): Response
    {
        $id = $params['id'] ?? '';
        if ($id === '') {
            return Response::badRequest('User ID is required.');
        }

        $output = $this->getUserActivity->execute(new GetUserActivityInput($id));
        return Response::json(['data' => $output->data]);
    }

    public function changePasswordAction(Request $request, array $params): Response
    {
        $id = $params['id'] ?? '';
        if ($id === '') {
            return Response::badRequest('User ID is required.');
        }
        $acting = $request->actingUser();
        if ($acting === null) {
            throw new \Daems\Domain\Auth\UnauthorizedException();
        }

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
