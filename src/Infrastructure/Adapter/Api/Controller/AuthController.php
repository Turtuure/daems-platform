<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Auth\LoginUser\LoginUser;
use Daems\Application\Auth\LoginUser\LoginUserInput;
use Daems\Application\Auth\RegisterUser\RegisterUser;
use Daems\Application\Auth\RegisterUser\RegisterUserInput;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class AuthController
{
    public function __construct(
        private readonly RegisterUser $registerUser,
        private readonly LoginUser $loginUser,
    ) {}

    public function login(Request $request): Response
    {
        $email    = trim((string) $request->input('email'));
        $password = (string) $request->input('password');

        if ($email === '' || $password === '') {
            return Response::badRequest('Email and password are required.');
        }

        $output = $this->loginUser->execute(new LoginUserInput($email, $password));

        if ($output->error !== null) {
            return Response::json(['error' => $output->error], 401);
        }

        return Response::json(['data' => $output->user]);
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

        $output = $this->registerUser->execute(
            new RegisterUserInput($name, $email, $password, $dob),
        );

        if ($output->error !== null) {
            return Response::json(['error' => $output->error], 409);
        }

        return Response::json(['data' => ['id' => $output->id]], 201);
    }
}
