<?php
function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

function jwt_encode(array $payload, string $secret): string {
    $h = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $p = base64url_encode(json_encode($payload));
    $s = base64url_encode(hash_hmac('sha256', "$h.$p", $secret, true));
    return "$h.$p.$s";
}

function jwt_decode(string $token, string $secret): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$h.$p", $secret, true));
    if (!hash_equals($expected, $s)) return null;
    $data = json_decode(base64url_decode($p), true);
    if (!$data || ($data['exp'] ?? 0) < time()) return null;
    return $data;
}
