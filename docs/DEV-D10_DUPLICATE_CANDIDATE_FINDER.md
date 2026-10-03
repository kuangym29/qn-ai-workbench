# DEV-D10｜Duplicate Candidate Finder + Comparable Corpus Core

> 分支：`doubao/DEV-D10-duplicate-candidate-finder`
> 日期：2026-10-03
> 任务性质：纯 PHP 应用核心 / 纯函数 / DTO / Golden Regression
> 前置依赖：DEV-D09 Duplicate Check Core Service

---

## 1. 概述

在 DEV-D09 的 `compare(A, B)` 基础上，本任务补充生产 API 所需的**批量候选发现**能力：

- **DuplicateComparableEntry**：可查重条目 DTO
- **DuplicateCorpusBuilder**：从 Page Snapshot 数组构建 Corpus（不访问 DB）
- **DuplicateCandidate**：候选结果 DTO
- **DuplicateCandidateFinder**：Query vs Corpus 批量查找

**设计原则**：
- 纯函数，无 DB 查询，无 HTTP 调用
- API 层只负责 DB → array，然后交给 Builder
- Working Copy 和 Formal Copy 都可以构造成 Query
- Finder 不知道 Query 来自 Working 还是 Formal

---

## 2. 新增类

```
app/Services/DuplicateCheck/
├── DuplicateComparableEntry.php   # 可查重条目 DTO
├── DuplicateCorpusBuilder.php     # Corpus 构建器
├── DuplicateCandidate.php         # 候选结果 DTO
└── DuplicateCandidateFinder.php   # 批量查找器

tests/Unit/DuplicateCheck/
├── CorpusBuilderTest.php
└── CandidateFinderTest.php
```

---

## 3. DuplicateComparableEntry

```php
final class DuplicateComparableEntry
{
    public function __construct(
        public readonly string $contentItemId,
        public readonly string $contentPageId,
        public readonly string $copyRevisionId,
        public readonly int $pageNo,
        public readonly string $pageType,
        public readonly DuplicateField $field,
        public readonly string $text,
        public readonly bool $slashLineBreakMarker = false,
    ) {}
}
```

---

## 4. 支持字段与 PageType 映射

| PageType | 可查重字段 |
| --- | --- |
| `cover` | `cover_title`, `cover_subtitle` |
| `content` | `page_title`, `page_small_text` |
| `column_closing` | `closing_line` |
| `fixed_back_cover` | （无，固定封底模板不参与查重） |

**永远忽略**：`column_label`, `note`, `page_type`

**null / 空白文本**：不加入 Corpus

---

## 5. DuplicateCorpusBuilder

输入：普通数组形式的 Page Snapshot

```php
$pages = [
    [
        'content_item_id' => 'CI-001',
        'content_page_id' => 'CP-001',
        'copy_revision_id' => 'CR-001',
        'page_no_snapshot' => 1,
        'page_type_snapshot' => 'cover',
        'cover_title' => '...',
        'cover_subtitle' => '...',
        'page_title' => null,
        'page_small_text' => null,
        'closing_line' => null,
    ],
    // ...
];
```

输出：`DuplicateComparableEntry[]`

---

## 6. 查找规则

### 6.1 Same-Field Only
- 严格：`query.field === corpus.field` 才比较
- 禁止跨字段比较（如 closing_line vs page_title）
- V1.0 不做跨字段语义查重

### 6.2 Self Exclusion
- 同 `content_item_id` + `content_page_id` + `copy_revision_id` + `field` → 排除
- **历史 Revision 不排除**：同一 ContentItem 不同 Revision 的重复是有价值的候选

### 6.3 Candidate Inclusion
- 加入候选：`original_exact=true` 或 `normalized_exact=true` 或 `overlap_candidate=true`
- 低于阈值的非候选文本不返回

### 6.4 Slash Marker
- 每个 Entry 有 `slashLineBreakMarker` 字段
- Finder 调用 `DuplicateCheckService.compare()` 时分别传入 Query 和 Match 的 marker
- 不复制 normalization 逻辑

---

## 7. DuplicateCandidate

```php
final class DuplicateCandidate
{
    public const KIND_ORIGINAL_EXACT = 'original_exact';
    public const KIND_NORMALIZED_EXACT = 'normalized_exact';
    public const KIND_OVERLAP = 'overlap';

    public function __construct(
        public readonly DuplicateComparableEntry $query,
        public readonly DuplicateComparableEntry $match,
        public readonly bool $originalExact,
        public readonly bool $normalizedExact,
        public readonly float $overlapScore,
        public readonly bool $overlapCandidate,
        public readonly float $threshold,
        public readonly string $matchKind,
    ) {}
}
```

**match_kind 优先级**：original_exact > normalized_exact > overlap

---

## 8. 排序

1. **match_kind 优先级**：original_exact → normalized_exact → overlap
2. **overlap_score DESC**（同类内按相似度从高到低）
3. **稳定 tie-break**：逐字段比较
   - `content_item_id` ASC（数字感知：纯数字 ID 按整数比较，避免 "10" < "2"）
   - `copy_revision_id` ASC
   - `content_page_id` ASC

保证测试可重复，且适配未来数据库 numeric bigint 主键。

---

## 9. Limit
- 默认 20
- 最小 1
- 传 0 / 负数：抛 `InvalidArgumentException`，不静默当 20

---

## 10. Golden Regression

### 10.1 Golden Corpus
- 数据源：`tests/Fixtures/duplicate_check/yujian_history_baseline.json`
- 4 items / 37 pages
- **GOLDEN_CORPUS_COUNT = 62**（4 cover × 2 = 8，25 content × 2 = 50，4 closing × 1 = 4）
- allowed fields：CoverTitle / CoverSubtitle / PageTitle / PageSmallText / ClosingLine
- closing_line entry count = 4
- channel-specific closing 不进入 formal corpus

### 10.2 Golden Closing Observation
- 4 条正式 closing 两两查询，预期 **results = []**（无任何 candidate）
- 如果意外产生 candidate：测试明确失败，错误消息标注 `GOLDEN_THRESHOLD_OBSERVATION`
- **不调阈值，不过滤，不修改算法**——报告给人工审核

---

## 11. SYN Finder 兼容性

| 案例 | 预期 match_kind |
| --- | --- |
| SYN-001（完全相同） | original_exact |
| SYN-002（中文逗号 vs 英文逗号） | 非 normalized_exact（按通用算法实际结果） |
| SYN-003 marker on（／→\n） | normalized_exact |
| SYN-003 marker off（／保留） | 非 normalized_exact |
| SYN-004（差一个字） | overlap |

---

## 12. 明确不做的事

- ❌ 不访问数据库（纯函数）
- ❌ 不新增 Migration
- ❌ 不实现 HTTP API
- ❌ 不做人工审核持久化（approved / ignored / false_positive）
- ❌ 不做重复检查结果持久化
- ❌ 不使用 Vector DB / Embedding
- ❌ 不跨字段比较
- ❌ 不排除历史 Revision 重复

---

## 13. 后续待办

1. **API 层集成**：DB → array → CorpusBuilder → Finder
2. **Working Copy Query**：当前 Working Copy 也可以构造成 Entry
3. **审核工作流**：独立设计人工确认 / 忽略 / 标记为 false positive
4. **结果持久化**：独立设计 duplicate_checks 表