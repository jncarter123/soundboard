<?php

namespace App\Passkeys;

use Spatie\LaravelPasskeys\Actions\GeneratePasskeyRegisterOptionsAction;
use Webauthn\AuthenticatorSelectionCriteria;

/**
 * Registration options that require user verification, with a full-strength
 * random challenge.
 */
class GenerateRegisterOptions extends GeneratePasskeyRegisterOptionsAction
{
    protected function challenge(): string
    {
        return random_bytes(32);
    }

    public function authenticatorSelection(): AuthenticatorSelectionCriteria
    {
        return new AuthenticatorSelectionCriteria(
            null,
            AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
        );
    }
}
