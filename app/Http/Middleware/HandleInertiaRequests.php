<?php

namespace App\Http\Middleware;

use App\Http\Resources\ProjectResource;
use App\Support\ProjectContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'currentProject' => fn (): ?array => ($project = app(ProjectContext::class)->current($request))
                ? (new ProjectResource($project))->resolve()
                : null,
        ]);
    }
}
