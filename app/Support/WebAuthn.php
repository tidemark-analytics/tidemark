<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Just enough WebAuthn (passkeys) to use them as a second sign-in step: registering a credential and checking an assertion.
 * Supports ES256 and RS256 keys, which covers platform authenticators, phones and security keys. Attestation statements are not
 * checked (we ask for "none"): all that matters is that the key is the one the device will sign with later.
 */
class WebAuthn
{
    public static function b64uEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $text): string
    {
        $raw = base64_decode(strtr($text, '-_', '+/'), true);
        if ($raw === false) {
            throw new InvalidArgumentException('Malformed data.');
        }

        return $raw;
    }

    public static function challenge(): string
    {
        return self::b64uEncode(random_bytes(32));
    }

    /** The relying party id is this installation's host name: passkeys only work on the site they were made for. */
    public static function rpId(): string
    {
        return request()->getHost();
    }

    public static function origin(): string
    {
        return request()->getSchemeAndHttpHost();
    }

    /** Checks a registration response and returns the new credential: id, PEM public key and signature counter. */
    public static function register(string $clientDataJson, string $attestationObject, string $challenge): array
    {
        self::checkClientData($clientDataJson, 'webauthn.create', $challenge);

        try {
            $attestation = Cbor::decode($attestationObject);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('The passkey response could not be read.');
        }
        $auth = $attestation['authData'] ?? null;
        if (! is_string($auth) || strlen($auth) < 55) {
            throw new InvalidArgumentException('The passkey response could not be read.');
        }
        self::checkAuthData($auth);
        if (! (ord($auth[32]) & 0x40)) {
            throw new InvalidArgumentException('The device did not return a passkey.');
        }

        $idLength = unpack('n', substr($auth, 53, 2))[1];
        $credentialId = substr($auth, 55, $idLength);
        if ($idLength === 0 || strlen($credentialId) !== $idLength) {
            throw new InvalidArgumentException('The passkey response could not be read.');
        }
        $offset = 55 + $idLength;
        $key = Cbor::decode($auth, $offset);

        return [
            'credential_id' => self::b64uEncode($credentialId),
            'public_key' => self::pem($key),
            'sign_count' => unpack('N', substr($auth, 33, 4))[1],
        ];
    }

    /** Checks a sign-in response against the stored key and returns the new signature counter. */
    public static function assert(string $clientDataJson, string $authenticatorData, string $signature, string $challenge, string $pem, int $storedCount): int
    {
        self::checkClientData($clientDataJson, 'webauthn.get', $challenge);
        if (strlen($authenticatorData) < 37) {
            throw new InvalidArgumentException('The passkey response could not be read.');
        }
        self::checkAuthData($authenticatorData);

        $signed = $authenticatorData.hash('sha256', $clientDataJson, true);
        if (openssl_verify($signed, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new InvalidArgumentException('The passkey could not be verified.');
        }

        $count = unpack('N', substr($authenticatorData, 33, 4))[1];
        // Authenticators that keep a counter must increase it; a counter that does not move suggests a cloned key.
        if (($count !== 0 || $storedCount !== 0) && $count <= $storedCount) {
            throw new InvalidArgumentException('The passkey could not be verified.');
        }

        return $count;
    }

    private static function checkClientData(string $json, string $type, string $challenge): void
    {
        $data = json_decode($json, true);
        if (! is_array($data) || ($data['type'] ?? null) !== $type
            || ! hash_equals($challenge, (string) ($data['challenge'] ?? ''))
            || ! hash_equals(self::origin(), (string) ($data['origin'] ?? ''))) {
            throw new InvalidArgumentException('The passkey response did not match this request.');
        }
    }

    private static function checkAuthData(string $auth): void
    {
        if (! hash_equals(hash('sha256', self::rpId(), true), substr($auth, 0, 32))) {
            throw new InvalidArgumentException('The passkey belongs to a different site.');
        }
        if (! (ord($auth[32]) & 0x01)) {
            throw new InvalidArgumentException('The passkey needs you to be present.');
        }
    }

    /** Turns a COSE public key (EC2 P-256 or RSA) into PEM. */
    private static function pem(array $key): string
    {
        $type = $key[1] ?? null;
        if ($type === 2 && ($key[3] ?? null) === -7 && ($key[-1] ?? null) === 1 && strlen((string) ($key[-2] ?? '')) === 32 && strlen((string) ($key[-3] ?? '')) === 32) {
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200')."\x04".$key[-2].$key[-3];
        } elseif ($type === 3 && ($key[3] ?? null) === -257 && is_string($key[-1] ?? null) && is_string($key[-2] ?? null)) {
            $rsa = self::der(0x30, self::derInt($key[-1]).self::derInt($key[-2]));
            $der = self::der(0x30, hex2bin('300d06092a864886f70d0101010500').self::der(0x03, "\x00".$rsa));
        } else {
            throw new InvalidArgumentException('This kind of passkey is not supported.');
        }

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function der(int $tag, string $body): string
    {
        $n = strlen($body);
        $len = $n < 128 ? chr($n) : (($n < 256) ? "\x81".chr($n) : "\x82".pack('n', $n));

        return chr($tag).$len.$body;
    }

    private static function derInt(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || ord($bytes[0]) & 0x80) {
            $bytes = "\x00".$bytes;
        }

        return self::der(0x02, $bytes);
    }
}
