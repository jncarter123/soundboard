<?php

namespace Tests\Support;

use OpenSSLAsymmetricKey;

/**
 * A minimal passkey authenticator for tests: an ES256 key pair that answers
 * registration and sign-in challenges the way a browser and platform
 * authenticator would, with "none" attestation. Real signatures, so the
 * server's WebAuthn verification runs for real.
 */
class SoftwareAuthenticator
{
    public string $credentialId;

    private OpenSSLAsymmetricKey $key;

    private int $signCount = 0;

    public function __construct(
        public string $rpId = 'localhost',
        public string $origin = 'https://localhost',
    ) {
        $this->credentialId = random_bytes(32);
        $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    }

    /**
     * The browser's registration response for these creation options.
     */
    public function register(string $optionsJson, bool $userVerified = true): string
    {
        $options = json_decode($optionsJson, true);
        $clientData = $this->clientData('webauthn.create', $options['challenge']);

        $details = openssl_pkey_get_details($this->key)['ec'];
        $coseKey = $this->cbor([1 => 2, 3 => -7, -1 => 1, -2 => new CborBytes($details['x']), -3 => new CborBytes($details['y'])]);

        $authData = hash('sha256', $this->rpId, true)
            .chr(0x41 | ($userVerified ? 0x04 : 0))   // UP, AT, and UV when verified
            .pack('N', $this->signCount)
            .str_repeat("\0", 16)                     // AAGUID
            .pack('n', strlen($this->credentialId)).$this->credentialId
            .$coseKey;

        $attestation = $this->cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => new CborBytes($authData)]);

        return json_encode([
            'id' => self::b64($this->credentialId),
            'rawId' => self::b64($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64($clientData),
                'attestationObject' => self::b64($attestation),
                'transports' => ['internal'],
            ],
            'clientExtensionResults' => (object) [],
        ]);
    }

    /**
     * The browser's sign-in response for these request options.
     */
    public function authenticate(string $optionsJson, string $userHandle, bool $userVerified = true): string
    {
        $options = json_decode($optionsJson, true);
        $clientData = $this->clientData('webauthn.get', $options['challenge']);

        $authData = hash('sha256', $this->rpId, true)
            .chr(0x01 | ($userVerified ? 0x04 : 0))   // UP, and UV when verified
            .pack('N', ++$this->signCount);

        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return json_encode([
            'id' => self::b64($this->credentialId),
            'rawId' => self::b64($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64($clientData),
                'authenticatorData' => self::b64($authData),
                'signature' => self::b64($signature),
                'userHandle' => self::b64($userHandle),
            ],
            'clientExtensionResults' => (object) [],
        ]);
    }

    private function clientData(string $type, string $challenge): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $this->origin, 'crossOrigin' => false]);
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Just enough CBOR: maps, text, byte strings, and small integers.
     */
    private function cbor(mixed $value): string
    {
        return match (true) {
            $value instanceof CborBytes => $this->cborHead(2, strlen($value->bytes)).$value->bytes,
            is_int($value) && $value >= 0 => $this->cborHead(0, $value),
            is_int($value) => $this->cborHead(1, -1 - $value),
            is_string($value) => $this->cborHead(3, strlen($value)).$value,
            is_array($value) => $this->cborHead(5, count($value)).implode('', array_map(
                fn ($k, $v) => $this->cbor($k).$this->cbor($v), array_keys($value), $value,
            )),
        };
    }

    private function cborHead(int $major, int $length): string
    {
        return match (true) {
            $length < 24 => chr($major << 5 | $length),
            $length < 256 => chr($major << 5 | 24).chr($length),
            default => chr($major << 5 | 25).pack('n', $length),
        };
    }
}

final class CborBytes
{
    public function __construct(public string $bytes) {}
}
