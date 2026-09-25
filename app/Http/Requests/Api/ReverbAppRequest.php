<?php

namespace App\Http\Requests\Api;

use App\Models\ReverbApp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates create (POST) and partial update (PATCH) requests for an app.
 * Authorization is handled by the route's `can:` middleware.
 */
class ReverbAppRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_array($this->input('allowed_origins'))) {
            $this->merge([
                'allowed_origins' => ReverbApp::normalizeOrigins(
                    array_filter($this->input('allowed_origins'), 'is_string')
                ),
            ]);
        }
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            // Immutable after creation: clients connect with it and metrics are keyed by it.
            'app_id' => $creating
                ? ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('reverb_apps', 'app_id')]
                : ['prohibited'],
            // Reverb rejects every connection when this is empty.
            'allowed_origins' => [$required, 'array', 'min:1'],
            'allowed_origins.*' => ['string', 'max:255'],
            'ping_interval' => ['sometimes', 'integer', 'min:1'],
            'activity_timeout' => ['sometimes', 'integer', 'min:1'],
            'max_message_size' => ['sometimes', 'integer', 'min:1'],
            'max_connections' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'accept_client_events_from' => ['sometimes', Rule::in(ReverbApp::CLIENT_EVENTS_FROM)],
            // Same shape as Reverb's own `rate_limiting` app config.
            'rate_limiting' => ['sometimes', 'array:enabled,max_attempts,decay_seconds,terminate_on_limit'],
            'rate_limiting.enabled' => ['sometimes', 'boolean'],
            'rate_limiting.max_attempts' => ['sometimes', 'integer', 'min:1'],
            'rate_limiting.decay_seconds' => ['sometimes', 'integer', 'min:1'],
            'rate_limiting.terminate_on_limit' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The validated input as model attributes: the nested `rate_limiting`
     * object maps onto flat columns, and only fields that were sent are
     * included, so a PATCH leaves the rest alone.
     */
    public function appAttributes(): array
    {
        $validated = $this->validated();
        $rateLimiting = $validated['rate_limiting'] ?? [];
        unset($validated['rate_limiting']);

        foreach ([
            'enabled' => 'rate_limit_enabled',
            'max_attempts' => 'rate_limit_max_attempts',
            'decay_seconds' => 'rate_limit_decay_seconds',
            'terminate_on_limit' => 'rate_limit_terminate',
        ] as $key => $column) {
            if (array_key_exists($key, $rateLimiting)) {
                $validated[$column] = $rateLimiting[$key];
            }
        }

        return $validated;
    }

    public function messages(): array
    {
        return [
            'app_id.prohibited' => 'The app_id cannot be changed after creation.',
            'allowed_origins.min' => 'At least one origin is required. Use "*" to allow any origin.',
        ];
    }
}
