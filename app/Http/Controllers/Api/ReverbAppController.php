<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReverbAppRequest;
use App\Http\Resources\ReverbAppResource;
use App\Models\ReverbApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ReverbAppController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ReverbAppResource::collection(ReverbApp::orderBy('name')->paginate(50));
    }

    public function store(ReverbAppRequest $request): JsonResponse
    {
        $app = ReverbApp::create([
            ...$request->validated(),
            'key' => ReverbApp::generateKey(),
            'secret' => ReverbApp::generateSecret(),
        ]);

        return ReverbAppResource::make($app->refresh())
            ->additional(['credentials' => $this->credentialsFor($app)])
            ->response()
            ->setStatusCode(201);
    }

    public function show(ReverbApp $app): ReverbAppResource
    {
        return ReverbAppResource::make($app);
    }

    public function update(ReverbAppRequest $request, ReverbApp $app): ReverbAppResource
    {
        $app->update($request->validated());

        return ReverbAppResource::make($app);
    }

    public function destroy(ReverbApp $app): Response
    {
        $app->delete();

        return response()->noContent();
    }

    public function credentials(ReverbApp $app): JsonResponse
    {
        return response()->json(['data' => $this->credentialsFor($app)]);
    }

    public function regenerateCredentials(ReverbApp $app): JsonResponse
    {
        $app->update([
            'key' => ReverbApp::generateKey(),
            'secret' => ReverbApp::generateSecret(),
        ]);

        return response()->json(['data' => $this->credentialsFor($app)]);
    }

    private function credentialsFor(ReverbApp $app): array
    {
        return [
            'app_id' => $app->app_id,
            'key' => $app->key,
            'secret' => $app->secret,
        ];
    }
}
