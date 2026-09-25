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
        ];
    }

    public function messages(): array
    {
        return [
            'app_id.prohibited' => 'The app_id cannot be changed after creation.',
            'allowed_origins.min' => 'At least one origin is required. Use "*" to allow any origin.',
        ];
    }
}
