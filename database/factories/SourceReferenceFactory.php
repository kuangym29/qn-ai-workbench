<?php

namespace Database\Factories;

use App\Enums\SourceRole;
use App\Models\Project;
use App\Models\SourceReference;
use Illuminate\Database\Eloquent\Factories\Factory;

class SourceReferenceFactory extends Factory
{
    protected $model = SourceReference::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'content_item_id' => null,
            'role' => SourceRole::ContentLedger,
            'authority' => SourceRole::ContentLedger->defaultAuthority(),
            'source_path' => '00_总入口与归档索引/六栏目内容台账.md',
            'note' => null,
        ];
    }

    private function forRole(SourceRole $role): static
    {
        return $this->state([
            'role' => $role,
            'authority' => $role->defaultAuthority(),
            'source_path' => match ($role) {
                SourceRole::FinalImageCopy => '01_2.5D家庭IP形象/生活小能力/图文/最终上图文案.md',
                SourceRole::SourceScript => '2.5D家庭IP形象/04_内容与封面模板/图文轮播标准/逐页内容脚本.md',
                SourceRole::ContentLedger => '00_总入口与归档索引/六栏目内容台账.md',
                SourceRole::ClosingLineRegistry => '00_总入口与归档索引/2.5D栏目收尾文案台账.md',
                SourceRole::NavigationIndex => '00_总入口与归档索引/2.5D逐篇最终上图文案.md',
            },
        ]);
    }

    public function finalImageCopy(): static
    {
        return $this->forRole(SourceRole::FinalImageCopy);
    }

    public function sourceScript(): static
    {
        return $this->forRole(SourceRole::SourceScript);
    }

    public function contentLedger(): static
    {
        return $this->forRole(SourceRole::ContentLedger);
    }

    public function closingLineRegistry(): static
    {
        return $this->forRole(SourceRole::ClosingLineRegistry);
    }

    public function navigationIndex(): static
    {
        return $this->forRole(SourceRole::NavigationIndex);
    }
}
