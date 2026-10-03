# DEV-D09｜Duplicate Check V1.0 Core Service

> 分支：`doubao/DEV-D09-duplicate-core`
> 日期：2026-10-03
> 任务性质：纯算法核心 / Golden Dataset 回归测试
> 规则依据：`docs/DEV-D08_DUPLICATE_CLOSINGLINE_BASELINE.md`

---

## 1. 概述

本任务实现 Duplicate Check 的纯算法核心服务，不依赖数据库、不调用 HTTP、不使用 Vector DB。

**设计原则**：
- 纯函数输入输出，方便测试和复用
- 三层 Normalization 严格分离，可解释
- 字段级阈值独立配置
- 所有候选最终由人工确认，不自动判死刑

---

## 2. 目录结构

```
app/Services/DuplicateCheck/
├── DuplicateField.php          # 枚举 + 字段阈值
├── DuplicateCheckResult.php    # 结果 DTO
├── Normalizer.php              # 三层 Normalization
├── NgramGenerator.php           # Unicode 安全 n-gram
├── JaccardSimilarity.php       # Jaccard 相似度
└── DuplicateCheckService.php  # 核心 compare 服务

tests/Unit/DuplicateCheck/
├── NormalizerTest.php
├── NgramGeneratorTest.php
├── DuplicateCheckServiceTest.php
└── GoldenDatasetRegressionTest.php
```

---

## 3. 三层 Normalization

### Layer 1: Source Representation Canonicalization

**目的**：把同一内容在不同来源文件中的表示法还原成相同语义结构。

**操作**：
- CRLF / CR → LF
- 可选：`／` → `\n`（仅当 `slashLineBreakMarker = true` 时）

**重要**：不是模糊匹配。只有来源格式明确规定的表示法差异才处理。

### Layer 2: Exact Normalization

**目的**：消除无意义格式差异，用于 Exact Duplicate 判定。

**顺序**：
1. Source Representation Canonicalization
2. **Unicode NFKC（仅非标点 run）**
3. 首尾 trim
4. 连续非换行空白折叠为单空格

**NFKC 标点保真设计**：

NFKC 只应用于**非标点字符 run**。Unicode 标点字符（`\p{P}`）原样保留，不做兼容映射。

**原因**：完整执行 NFKC 会把全角标点兼容映射成 ASCII 标点，破坏 Exact 的标点差异判定：
- `，`（U+FF0C 全角逗号）→ NFKC 会变成 `,`（U+002C）→ 错误
- `／`（U+FF0F 全角斜杠）→ NFKC 会变成 `/`（U+002F）→ marker off 时错误

**保留不变**：
- 语义换行
- 所有中文标点（保留原始 Unicode 码位）
- 所有英文标点（保留原始 Unicode 码位）
- 中文引号 / 英文引号差异
- 大小写差异

**Runtime Fail-Closed**：
- 如果 `Normalizer` 类不可用，直接抛出 `RuntimeException`
- 不允许静默降级——同一套算法在不同服务器必须产生相同结果

### Layer 3: Overlap Normalization

**目的**：激进简化，仅用于候选发现。

**额外操作**（在 Exact 基础上）：
- 语义换行 → 空格
- 删除所有 Unicode 标点（`\p{P}`）
- 再次折叠空白

**绝对禁止**：Overlap form 永远不能参与 `normalized_exact` 判定。

---

## 4. Unicode 3-gram

- 按 Unicode 字符拆分（`preg_split('//u', ...)`），不按 bytes
- 默认 n = 3

### Short-text Fallback

当任一文本的字符数 < 3 时：
- n = min(3, 两边最短字符长度)
- 极短文本（n < 文本长度）时，返回整个文本作为单个 n-gram
- 空字符串：返回空数组，不产生虚假 1.0

---

## 5. Jaccard Similarity

- 基于 Set（不是 Bag，不按出现次数加权）
- 公式：`|A ∩ B| / |A ∪ B|`
- 空输入：返回 0.0，不产生 NaN / division by zero

---

## 6. 字段级阈值（初始参考）

| 字段 | 初始阈值 | 说明 |
| --- | --- | --- |
| `closing_line` | 0.4 | 高优先级，短文本，宁可多报候选 |
| `cover_title` | 0.5 | 中优先级 |
| `cover_subtitle` | 0.5 | 中优先级 |
| `page_title` | 0.6 | 低优先级，正文标题可能自然相似 |
| `page_small_text` | 0.5 | 低优先级 |
| `column_label` | 不查重 | 栏目名是分类，不是文案 |

> ⚠️ 这些阈值是未经真实语料充分校准的初始参考，不是已验证准确率。

---

## 7. Result 结构

```php
DuplicateCheckResult {
    bool $originalExact       // 原文逐字符完全相同
    bool $normalizedExact     // 经 Exact Normalization 后完全相同
    float $overlapScore       // Overlap Jaccard 分数 (0.0-1.0)
    bool $overlapCandidate    // 非 normalized_exact 且 score >= threshold
    float $threshold         // 当前字段阈值
}
```

**关键**：`overlap_candidate = true` 永远不与 `normalized_exact = true` 同时为 true。Exact 重复不被标记为普通候选。

---

## 8. 核心 API

```php
$service = new DuplicateCheckService();
$result = $service->compare(
    left: $textA,
    right: $textB,
    field: DuplicateField::ClosingLine,
    leftSlashLineBreakMarker: false,
    rightSlashLineBreakMarker: false,
);
```

---

## 9. Golden Regression

### 数据源
- `tests/Fixtures/duplicate_check/yujian_history_baseline.json`

### 验证项
- JSON 可解析
- 4 items / 37 pages
- PageType 分布：cover 4 / content 25 / column_closing 4 / fixed_back_cover 4
- 4 条 formal closing 两两比较：均非 Exact
- **6 对 pairwise overlap observation**：DEV-D08 基线预期无 overlap candidate；任何 candidate=true 会明确失败并报告 `GOLDEN_THRESHOLD_OBSERVATION`，不偷偷调阈值
- Channel-specific closing 不混入 Formal Copy
- 4 个 column_slug 正确

---

## 10. SYN 测试案例

| 编号 | 场景 | 预期 |
| --- | --- | --- |
| SYN-001 | 完全相同文本 | original_exact=true, normalized_exact=true |
| SYN-002 | 中文逗号 vs 英文逗号 | 非 Exact（NFKC 不转换标点），进入 Overlap |
| SYN-003 (marker on) | 正式版换行 vs 台账 ／ 且 marker=true | normalized_exact=true |
| SYN-003 (marker off) | 正式版换行 vs 台账 ／ 且 marker=false | normalized_exact=false（／ 保留为标点，不被 NFKC 偷换成 /） |
| SYN-004 | 差一个字的收尾句 | 非 Exact，Overlap Candidate |

---

## 11. 明确不做的事

- ❌ 不使用 Vector DB / Embedding
- ❌ 不查询数据库（纯函数）
- ❌ 不新增 Migration
- ❌ 不实现 HTTP API
- ❌ 不实现人工审核界面
- ❌ 不自动判定 duplicate（所有候选人工确认）
- ❌ 不做缓存 / Redis / Queue / 倒排索引

---

## 12. 后续待办

1. **阈值校准**：用更多真实历史数据校准各字段阈值
2. **数据库集成**：在 API 层调用本 Service，查询 ContentPageVersion
3. **ClosingLine Registry**：独立设计收尾句持久化方案
4. **查重结果持久化**：独立设计 duplicate_checks / review_decisions 表