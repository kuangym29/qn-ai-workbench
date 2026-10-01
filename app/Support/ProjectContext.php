<?php

namespace App\Support;

use App\Models\Project;
use Illuminate\Http\Request;

class ProjectContext
{
    public function current(Request $request): ?Project
    {
        $id = $request->session()->get('current_project_id');

        return $id ? Project::find($id) : null;
    }

    public function select(Request $request, Project $project): void
    {
        $request->session()->put('current_project_id', $project->id);
    }

    public function assertCurrent(Request $request, Project $project): void
    {
        abort_unless($this->current($request)?->is($project), 404);
    }
}
