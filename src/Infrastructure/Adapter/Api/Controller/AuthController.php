<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Auth\CreateAuthToken\CreateAuthToken;
use Daems\Application\Auth\CreateAuthToken\CreateAuthTokenInput;
use Daems\Application\Auth\LoginUser\LoginUser;
use Daems\Application\Auth\LoginUser\LoginUserInput;
use Daems\Application\Auth\LogoutUser\LogoutUser;
use Daems\Application\Auth\LogoutUser\LogoutUserInput;
use Daems\Application\Auth\RegisterUser\RegisterUser;
use Daems\Application\Auth\RegisterUser\RegisterUserInput;
use Daems\Domain\Auth\UnauthorizedException;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class AuthController
{
    public function __construct(
        private readonly RegisterUser $registerUser,
        private readonly LoginUser $loginUser,
        private readonly CreateAuthToken $createAuthToken,
        private readonly LogoutUser $logoutUser,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function login(Request $request): Response
    {
        $email    = trim((string) $request->input('email'));
        $password = (string) $request->input('password');

        if ($email === '' || $password === '') {
            return Response::badRequest('Email and password are required.');
        }

        $output = $this->loginUser->execute(new LoginUserInput($email, $password, $request->clientIp()));

        if ($output->error !== null) {
            return Response::json(['error' => $output->error], 401);
        }

        $user = $this->users->findByEmail($email);
        if ($user === null) {
            return Response::serverError('Authentication error.');
        }

        $token = $this->createAuthToken->execute(new CreateAuthTokenInput(
            $user->id(),
            $request->header('User-Agent'),
            $request->clientIp(),
        ));

        return Response::json([
            'data' => [
                'user'       => $output->user,
                'token'      => $token->rawToken,
                'expires_at' => $token->expiresAt->format('c'),
            ],
        ]);
    }

    public function register(Request $request): Response
    {
        $name     = trim((string) $request->input('name'));
        $email    = trim((string) $request->input('email'));
        $password = (string) $request->input('password');
        $dob      = trim((string) $request->input('date_of_birth'));

        if ($name === '' || $email === '' || $password === '' || $dob === '') {
            return Response::badRequest('Name, email, password and date of birth are required.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::badRequest('Invalid email address.');
        }

        if (strlen($password) < 8) {
            return Response::badRequest('Password must be at least 8 characters.');
        }

        if (strlen($password) > 72) {
            return Response::badRequest('Password must be at most 72 bytes.');
        }

        $output = $this->registerUser->execute(
            new RegisterUserInput($name, $email, $password, $dob),
        );

        if ($output->error !== null) {
            return Response::json(['error' => $output->error], 409);
        }

        return Response::json(['data' => ['id' => $output->id]], 201);
    }

    public function logout(Request $request): Response
    {
        $raw = $request->bearerToken();
        if ($raw === null) {
            throw new UnauthorizedException();
        }
        $this->logoutUser->execute(new LogoutUserInput($raw));
        return Response::json(null, 204);
    }
}
