<?php

use App\Models\Passkey;
use App\Models\User;
use App\Passkeys\GenerateRegisterOptions;
use Spatie\LaravelPasskeys\Actions\ConfigureCeremonyStepManagerFactoryAction;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyAuthenticationOptionsAction;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;

return [

    /*
    |--------------------------------------------------------------------------
    | Required roles
    |--------------------------------------------------------------------------
    |
    | Members of these roles (comma-separated) must sign in with a passkey.
    | One without a passkey gets a code by email after their password, then
    | registers a passkey before anything else. Empty by default, so turning
    | it on is a deliberate step; see the README.
    |
    */

    'required_roles' => array_values(array_filter(array_map('trim', explode(',', (string) env('AUTH_PASSKEY_REQUIRED_ROLES', ''))))),

    /*
    |--------------------------------------------------------------------------
    | Relying party
    |--------------------------------------------------------------------------
    |
    | Passkeys are bound to this domain, taken from APP_URL. Changing the
    | domain later makes existing passkeys unusable.
    |
    */

    'relying_party' => [
        'name' => env('APP_NAME', 'Soundboard'),
        'id' => parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST),
        'icon' => null,
    ],

    // Unused: sign-in goes through Soundboard's own login flow.
    'redirect_to_after_login' => '/admin',

    'actions' => [
        // Requires user verification (a PIN or biometric), so a passkey
        // counts as a second factor, not just a device someone holds.
        'generate_passkey_register_options' => GenerateRegisterOptions::class,
        'store_passkey' => StorePasskeyAction::class,
        'generate_passkey_authentication_options' => GeneratePasskeyAuthenticationOptionsAction::class,
        'find_passkey' => FindPasskeyToAuthenticateAction::class,
        'configure_ceremony_step_manager_factory' => ConfigureCeremonyStepManagerFactoryAction::class,
    ],

    'models' => [
        'passkey' => Passkey::class,
        'authenticatable' => User::class,
    ],

];
