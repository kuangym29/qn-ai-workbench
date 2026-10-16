# V2-P2-I01 小绿书集成计划

> 执行方式：Work 主程序按 executing-plans/TDD 执行；以用户本任务为正式规格，不扩大到 C02/C03。

**Goal:** 将 A01/B01-R1/C01 接为单一真实小绿书生产链路。
**Architecture:** branch-aware 后端为解析、归一化、校验、diff、apply 的唯一真源；前端负责 raw text、预览展示、确认和重新GET。人工PUT只版本化变化内容。
**Tech Stack:** Laravel/PHP、Vue/TypeScript、SQLite/MySQL8.4。
**Spec:** 本聊天任务 V2-P2-I01-IMAGE-POST-INTEGRATION。

## 边界与审查重点

- 固定起点1fe589f；独立work/v2-p2-image-post-integration，不merge main/foundation，不引入C02/C03。
- 保留三来源历史，特别验证B01 1647666祖先关系，再关闭PR28。
- core_message唯一内部字段；删生产前端parser/diff/内存apply和DEV预览入口。
- fixed_back_cover只读，不新增/修改，warning正常展示；跨Project404，非image_post拒绝。
- Cancel不写库；Apply后重新GET；局部P4不重写其他页；人工未变不新增版本。

## Tasks

- [x] 1. 核对并合入A01/B01/C01原始提交，记录冲突与正式契约。
- [x] 2. 为branch-aware正式页面/入口/API客户端/preview/apply写失败测试，运行RED。
- [x] 3. 接通真实GET/PUT/preview/apply，统一core_message；删除Stub/DEV冗余，GREEN。
- [x] 4. 核对后端保护与集成边界；运行PHP/SQLite/MySQL8.4、前端tests、typecheck/build。
- [x] 5. 独立测试数据真实浏览器完成用户20项清单，必要小修按RED/GREEN复验。
- [x] 6. 最终自审、台账/验收文档；验证B01历史完整后说明superseded并关闭PR28；推送仅集成分支，复核git状态并停止。
