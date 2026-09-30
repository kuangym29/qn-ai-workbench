# DEV-D01 历史内容迁移 Fixture 说明文档

> 分支：`doubao/DEV-D01-content-fixtures`
> 生成日期：2026-10-01
> 数据范围：青柠育见 4 篇已确认历史内容，共 37 页

## 一、文件清单与数据层级

| 文件 | 层级 | 记录数 |
| --- | --- | --- |
| `01_projects.json` | Project | 1 |
| `02_columns.json` | Column（小栏目） | 6 |
| `03_topics.json` | Topic（选题） | 4 |
| `04_content_items.json` | Content Item（篇目） | 4 |
| `05_content_pages.json` | Content Page（逐页） | 37 |
| `06_production_tasks.json` | Production Task | 6 |
| `07_channel_tasks.json` | Channel Task | 6 |
| `test_samples/test_samples.json` | Test Samples | 8 |

## 二、核心状态规则

文案已确认 ≠ 图稿已定稿 ≠ 视频已验收 ≠ 已发布。四个状态独立，不得互相推断。

## 三、关键确认点

- 《孩子出门总磨蹭》图文版收尾句：「你在身边，"我自己来"更有底气。」
- 《孩子出门总磨蹭》动态视频版收尾句：「你等的这一会儿，是她自己来的底气。」
- 两句独立，禁止合并覆盖。

## 四、待人工复核

1. 《积木倒了》图文是否已实际发布到平台
2. 《孩子出门总磨蹭》公众号 3:4 模板方向
3. 《纸箱水果店》整套生图进度
4. 各视频版配音与角色音色版本
5. 安心小日常、爸妈在成长无正式文章，不得用草稿填充

## 五、边界遵守

- 未创建"2.5D 图文"独立 Content Profile
- 未填充安心小日常/爸妈在成长草稿
- 中央导航文件不复制完整正文，仅引用路径