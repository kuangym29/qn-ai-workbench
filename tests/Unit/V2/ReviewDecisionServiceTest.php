<?php

namespace Tests\Unit\V2;

use App\Enums\ReviewDecision as ReviewDecisionEnum;
use App\Enums\ReviewStatus;
use App\Models\ReviewDecision;
use App\Models\ReviewRequest;
use App\Services\V2\ReviewDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use Tests\TestCase;

/**
 * ReviewDecisionService：提交决策。
 *
 * 关键不变量：
 *   - 仅 pending 可决策；已决策 → DomainException（API 层转 409）。
 *   - changes_requested 必须 comment；approved 可空。
 *   - review_decisions append-only：decision_no 从 1 递增，只新增不更新。
 *   - 落库后更新 review_request.status 与 decided_at。
 */
class ReviewDecisionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_sets_status_decided_at_and_decision_no_one(): void
    {
        $request = ReviewRequest::factory()->create();

        $decision = (new ReviewDecisionService())->decide($request, ReviewDecisionEnum::Approved, null, null);

        $request->refresh();
        $this->assertSame(ReviewStatus::Approved, $request->status);
        $this->assertNotNull($request->decided_at);
        $this->assertSame(1, ReviewDecision::count());
        $this->assertSame(1, $decision->decision_no);
        $this->assertSame(ReviewDecisionEnum::Approved, $decision->decision);
    }

    public function test_changes_requested_with_comment_sets_status_and_stores_comment(): void
    {
        $request = ReviewRequest::factory()->create();

        $decision = (new ReviewDecisionService())->decide(
            $request,
            ReviewDecisionEnum::ChangesRequested,
            '正文再口语一点',
            'P4'
        );

        $request->refresh();
        $this->assertSame(ReviewStatus::ChangesRequested, $request->status);
        $this->assertSame('正文再口语一点', $decision->comment);
        $this->assertSame('P4', $decision->target_label);
    }

    public function test_changes_requested_without_comment_throws(): void
    {
        $request = ReviewRequest::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        (new ReviewDecisionService())->decide($request, ReviewDecisionEnum::ChangesRequested, null, null);
    }

    public function test_decide_on_non_pending_throws_domain_exception(): void
    {
        $request = ReviewRequest::factory()->approved()->create();

        $this->expectException(\DomainException::class);
        (new ReviewDecisionService())->decide($request, ReviewDecisionEnum::Approved, null, null);
    }

    public function test_decision_no_increments_when_preexisting_decision_exists(): void
    {
        $request = ReviewRequest::factory()->create();
        // 模拟已有一条 decision_no=1（结构保持 append-only，仍 pending 时可继续追加）。
        ReviewDecision::factory()->for($request)->create([
            'decision_no' => 1,
            'decision' => ReviewDecisionEnum::Approved,
        ]);

        $next = (new ReviewDecisionService())->decide($request, ReviewDecisionEnum::Approved, null, null);

        $this->assertSame(2, ReviewDecision::count());
        $this->assertSame(2, $next->decision_no);
        $this->assertSame(2, (int) $request->decisions()->max('decision_no'));
    }

    public function test_decisions_are_never_updated_only_appended(): void
    {
        // 已决策后再次提交会抛 DomainException（不覆盖原决策），由 API 层转 409。
        $request = ReviewRequest::factory()->approved()->create();
        ReviewDecision::factory()->for($request)->create(['decision_no' => 1]);

        try {
            (new ReviewDecisionService())->decide($request, ReviewDecisionEnum::Approved, null, null);
            $this->fail('预期 DomainException');
        } catch (\DomainException) {
            // 通过：原 decision_no=1 的记录未被覆盖，也没有新记录被追加。
            $this->assertSame(1, ReviewDecision::count());
        }
    }
}
