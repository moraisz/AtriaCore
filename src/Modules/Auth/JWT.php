<?php

declare(strict_types=1);

namespace Atria\Modules\Auth;

class JWT
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function encode(array $payload, string $secret, string $algorithm = 'HS256'): string
    {
        $header = ['alg' => $algorithm, 'typ' => 'JWT'];
        $encodedHeader = json_encode($header);
        $encodedPayload = json_encode($payload);
        $segments = [
            self::base64UrlEncode($encodedHeader !== false ? $encodedHeader : '{}'),
            self::base64UrlEncode($encodedPayload !== false ? $encodedPayload : '{}'),
        ];
        $signingInput = implode('.', $segments);
        $signature = self::sign($signingInput, $secret, $algorithm);
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * @param array<int, string> $allowedAlgorithms
     * @return array<string, mixed>
     */
    public static function decode(string $jwt, string $secret, array $allowedAlgorithms = ['HS256']): array
    {
        $segments = explode('.', $jwt);
        if (count($segments) !== 3) {
            throw new \InvalidArgumentException('Invalid JWT format');
        }
        [$encodedHeader, $encodedPayload, $encodedSignature] = $segments;

        $decodedHeader = self::base64UrlDecode($encodedHeader);

        /** @var array<string, mixed>|null $header */
        $header = json_decode($decodedHeader, true);
        if (!is_array($header) || !isset($header['alg']) || !is_string($header['alg'])) {
            throw new \InvalidArgumentException('Invalid header');
        }
        if (!in_array($header['alg'], $allowedAlgorithms, true)) {
            throw new \InvalidArgumentException('Unsupported algorithm');
        }

        $decodedPayload = self::base64UrlDecode($encodedPayload);

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($decodedPayload, true);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid payload');
        }

        $signature = self::base64UrlDecode($encodedSignature);
        if (!self::verify("{$encodedHeader}.{$encodedPayload}", $signature, $secret, $header['alg'])) {
            throw new \InvalidArgumentException('Invalid signature');
        }
        return $payload;
    }

    private static function sign(string $data, string $secret, string $algorithm): string
    {
        switch ($algorithm) {
            case 'HS256':
                return hash_hmac('sha256', $data, $secret, true);
            default:
                throw new \InvalidArgumentException('Unsupported algorithm');
        }
    }

    private static function verify(string $data, string $signature, string $secret, string $algorithm): bool
    {
        return hash_equals(self::sign($data, $secret, $algorithm), $signature);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $encodedHeader): string
    {
        $remainder = strlen($encodedHeader) % 4;

        if ($remainder > 0) {
            $encodedHeader .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($encodedHeader, '-_', '+/'));
    }
}
