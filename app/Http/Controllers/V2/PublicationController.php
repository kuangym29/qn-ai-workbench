<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\V2\PublicationService;
use App\Support\ProjectContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicationController extends Controller
{
    public function __construct(private readonly PublicationService $publication) {}

    public function show(Request $request, Project $project, int $item, int $branch, string $target): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);
        $state = $this->publication->state($project, $item, $branch, $target);

        return response()->json(['data' => $state]);
    }

    public function store(Request $request, Project $project, int $item, int $branch, string $target): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);
        $result = $this->publication->publish($project, $item, $branch, $target, $request->all());

        return response()->json([
            'data' => [
                'created' => $result['created'],
                'publication' => $result['publication'],
            ],
        ], $result['created'] ? 201 : 200);
    }
}
