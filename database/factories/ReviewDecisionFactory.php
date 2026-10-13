<?php

namespace Database\Factories;

use App\Enums\ReviewDecision as ReviewDecisionEnum;
use App\Models\ReviewDecision;
use App\Models\ReviewRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReviewDecision> */
class ReviewDecisionFactory extends Factory
{
    protected $model = ReviewDecision::class;

    public function definition(): array
    {
        return [
            'review_request_id' => ReviewRequest::factory(),
            'decision_no' => 1,
            'decision' => ReviewDecisionEnum::Approved,
            'comment' => null,
            'target_label' => null,
        ];
    }

    public function changesRequested(): static
    {
        return $this->state(fn (): array => ['decision' => ReviewDecisionEnum::ChangesRequested]);
    }

    public function withComment(string $comment): static
    {
        return $this->state(fn (): array => ['comment' => $comment]);
    }
}
