<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Spatie\LaravelPasskeys\Models\Passkey as SpatiePasskey;
use Spatie\LaravelPasskeys\Support\CredentialRecordConverter;
use Spatie\LaravelPasskeys\Support\Serializer;
use Webauthn\PublicKeyCredentialSource;

/**
 * Stores credential IDs as base64url. The package converts the raw bytes
 * "to UTF-8", which isn't reversible for arbitrary binary.
 */
class Passkey extends SpatiePasskey
{
    protected $table = 'passkeys';

    public function data(): Attribute
    {
        $serializer = Serializer::make();

        return new Attribute(
            get: fn (string $value): PublicKeyCredentialSource => CredentialRecordConverter::toPublicKeyCredentialSource(
                $serializer->fromJson($value, PublicKeyCredentialSource::class)
            ),
            set: fn (PublicKeyCredentialSource $value) => [
                'credential_id' => static::encodeCredentialId($value->publicKeyCredentialId),
                'data' => $serializer->toJson($value),
            ],
        );
    }

    public static function encodeCredentialId(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
