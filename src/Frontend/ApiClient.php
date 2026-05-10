<?php

declare(strict_types=1);

namespace Daems\Frontend;

final class ApiClient
{
    private static string $baseUrl = 'http://daems-platform.local/api/v1';

    /** @return string[] */
    private static function authHeaders(): array
    {
        $headers = ['Accept: application/json'];
        $headers[] = 'Accept-Language: ' . self::currentLocale();
        $token = $_SESSION['token'] ?? null;
        if (is_string($token) && $token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        return $headers;
    }

    /**
     * Resolve the locale to advertise to the platform API. Uses I18n when
     * available (normal request path); otherwise falls back to fi_FI.
     * Kept private so callers never bypass it inadvertently.
     */
    private static function currentLocale(): string
    {
        if (class_exists(I18n::class, false)) {
            /** @var string $loc */
            $loc = I18n::locale();
            return $loc;
        }
        return 'fi_FI';
    }

    public static function get(string $path, array $query = []): mixed
    {
        $url = self::$baseUrl . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => self::authHeaders(),
        ]);

        $json = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($json === false || $status < 200 || $status >= 300) {
            return null;
        }

        $body = json_decode((string) $json, true);
        return $body['data'] ?? null;
    }

    public static function post(string $path, array $data): array
    {
        return self::request('POST', $path, $data);
    }

    /**
     * @param array<mixed> $data
     * @return array{status:int, body:array<mixed>}
     */
    public static function patch(string $path, array $data): array
    {
        return self::request('PATCH', $path, $data);
    }

    /**
     * @param array<mixed> $data
     * @return array{status:int, body:array<mixed>}
     */
    public static function put(string $path, array $data): array
    {
        return self::request('PUT', $path, $data);
    }

    /**
     * @return array{status:int, body:array<mixed>}
     */
    public static function delete(string $path): array
    {
        return self::request('DELETE', $path, null);
    }

    /**
     * Generic verb dispatcher used by post/patch/delete.
     *
     * @param array<mixed>|null $data
     * @return array{status:int, body:array<mixed>}
     */
    private static function request(string $method, string $path, ?array $data): array
    {
        $url = self::$baseUrl . $path;
        $payload = $data === null ? '' : (string) json_encode($data);

        $ch = curl_init($url);
        $headers = self::authHeaders();
        if ($data !== null) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($payload);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $json = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($json === false) {
            return ['status' => 503, 'body' => ['error' => 'Service unavailable.']];
        }

        $body = json_decode((string) $json, true) ?? [];
        return ['status' => $status, 'body' => $body];
    }
}
