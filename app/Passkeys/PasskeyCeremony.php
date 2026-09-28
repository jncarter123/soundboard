<?php

namespace App\Passkeys;

use App\Models\Passkey;
use App\Models\User;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;
use Spatie\LaravelPasskeys\Support\Config;
use Spatie\LaravelPasskeys\Support\Serializer;
use Throwable;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Registering and checking passkeys. Each ceremony's options are kept in
 * the session under a purpose key and used once, so a response can only
 * answer the challenge issued for that purpose.
 */
class PasskeyCeremony
{
    public function registrationOptions(User $user, string $purpose): string
    {
        $options = app(Config::getActionClass('generate_passkey_register_options', GenerateRegisterOptions::class))->execute($user);
        session()->put($this->key($purpose), $options);

        return $options;
    }

    /**
     * Verify a registration response and save the passkey. Null if it fails.
     */
    public function register(User $user, string $response, string $purpose, string $name): ?Passkey
    {
        $options = session()->pull($this->key($purpose));

        if (blank($options)) {
            return null;
        }

        try {
            /** @var Passkey */
            return app(StorePasskeyAction::class)->execute($user, $response, $options, request()->getHost(), ['name' => $name]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Options for proving possession of a passkey, with user verification
     * required. With a user, only their passkeys are accepted; without one
     * (passkey sign-in), the browser offers whichever it has for this site.
     */
    public function assertionOptions(string $purpose, ?User $user = null): string
    {
        $allow = $user
            ? $user->passkeys->map(fn (Passkey $passkey) => PublicKeyCredentialDescriptor::create('public-key', $passkey->data->publicKeyCredentialId))->all()
            : [];

        $options = Serializer::make()->toJson(new PublicKeyCredentialRequestOptions(
            challenge: random_bytes(32),
            rpId: Config::getRelyingPartyId(),
            allowCredentials: $allow,
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: 120000,
        ));

        session()->put($this->key($purpose), $options);

        return $options;
    }

    /**
     * The passkey that signed this response, if it verifies and, when a user
     * is given, belongs to them.
     */
    public function verify(string $response, string $purpose, ?User $user = null): ?Passkey
    {
        $options = session()->pull($this->key($purpose));

        if (blank($options)) {
            return null;
        }

        try {
            $passkey = app(FindPasskeyToAuthenticateAction::class)->execute($response, $options);
        } catch (Throwable) {
            return null;
        }

        if (! $passkey instanceof Passkey || ($user && $passkey->authenticatable_id !== $user->id)) {
            return null;
        }

        return $passkey;
    }

    private function key(string $purpose): string
    {
        return "passkeys.ceremony.{$purpose}";
    }
}
