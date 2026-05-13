<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Http;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Auth\TooManyRequestsException;
use Daems\Domain\Auth\UnauthorizedException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Logging\LoggerInterface;
use Throwable;

final class Kernel
{
    public function __construct(
        private readonly Container $container,
        private readonly LoggerInterface $logger,
        private readonly bool $debug = false,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            $router = $this->container->make(Router::class);
            assert($router instanceof Router);
            return $router->dispatch($request);
        } catch (UnauthorizedException $e) {
            return Response::unauthorized($e->getMessage());
        } catch (ForbiddenException $e) {
            return Response::forbidden($e->getMessage());
        } catch (NotFoundException $e) {
            return Response::notFound($e->getMessage());
        } catch (ValidationException $e) {
            return Response::badRequest($e->getMessage());
        } catch (\InvalidArgumentException $e) {
            // Value-object constructors (Uuid7, Email, …) throw this on
            // malformed user input. Map to 400 so fuzzers and bad clients
            // get a structured error. Hide the message in production so
            // we don't echo attacker payloads back ("Invalid UUID: <script>").
            return Response::badRequest($this->debug ? $e->getMessage() : 'Invalid request.');
        } catch (TooManyRequestsException $e) {
            return Response::tooManyRequests($e->getMessage(), $e->retryAfter);
        } catch (\DomainException $e) {
            // SPL DomainException + every domain-rule violation that extends
            // it (BoardNotBootstrapped, InvoiceAlreadyPaid, …) is a client-
            // facing semantic error, not a server fault. 409 keeps it
            // distinct from "malformed request" (400) and "auth missing"
            // (401/403) without leaking internal stack traces.
            return Response::conflict($e->getMessage());
        } catch (Throwable $e) {
            $this->logger->error('Unhandled exception', ['exception' => $e]);
            $body = $this->debug
                ? sprintf('%s: %s (%s:%d)', $e::class, $e->getMessage(), $e->getFile(), $e->getLine())
                : 'Internal server error.';
            return Response::serverError($body);
        }
    }

    public function send(Response $response): void
    {
        $response->send();
    }

    /**
     * Expose the wired container so non-routed entry points (e.g. the
     * public/sites-router.php fallback for tenants without a custom
     * frontend) can resolve domain services without re-bootstrapping.
     */
    public function container(): Container
    {
        return $this->container;
    }
}
