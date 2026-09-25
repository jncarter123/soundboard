<?php

namespace App\Http\Resources;

use App\Models\ReverbApp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * App settings without credentials. Credentials are only returned by the
 * dedicated credentials endpoints, which require apps.update.
 *
 * @mixin ReverbApp
 */
class ReverbAppResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'app_id' => $this->app_id,
            'name' => $this->name,
            'allowed_origins' => $this->allowed_origins,
            'ping_interval' => $this->ping_interval,
            'activity_timeout' => $this->activity_timeout,
            'max_message_size' => $this->max_message_size,
            'max_connections' => $this->max_connections,
            'accept_client_events_from' => $this->accept_client_events_from,
            'rate_limiting' => [
                'enabled' => $this->rate_limit_enabled,
                'max_attempts' => $this->rate_limit_max_attempts,
                'decay_seconds' => $this->rate_limit_decay_seconds,
                'terminate_on_limit' => $this->rate_limit_terminate,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
