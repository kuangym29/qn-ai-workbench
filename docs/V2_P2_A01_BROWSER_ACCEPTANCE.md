# V2-P2-A01 Foundation 浏览器验收

日期：2026-10-13。执行者：Work 主程序。

## Status

V2-P2-A01_DONE：2026-10-13 已补齐完整真实浏览器验收。下方保留初次阻塞记录，最终结论与证据见末尾“恢复后的最终验收”。

## 基线与范围

- Start HEAD：1fe589f8be6461ab6010b13ce5637699a3d647f8。
- 执行分支：work/v2-foundation-browser-acceptance。最终提交 SHA 以 git log 为准。
- 起始工作区干净。仅修改 Foundation 已有 Topics/Pool.vue 和本验收文档/执行台账。
- 未改 main、未合并其他 AI 分支、未开发或修改 Phase 2 新功能文件。
- 浏览器环境：本机 127.0.0.1:8787，独立 SQLite foundation-browser.sqlite，专用虚构测试账号、项目 A/B 与测试栏目；非业务库。桌面验收尺寸1280×900。

## 浏览器已实际完成

1. 登录并进入项目 A；灵感只填正文即可保存。
2. 生成选题跳到选题池，标题/说明自动预填，来源为真实 Inspiration；仅需选择栏目，无重复手填。
3. 保存选题，标备用、移入暂存并切换列表；备用独立于状态。
4. 开始创作默认选择公众号小绿书和视频号，禁用小红书。
5. 创建后跳到 ContentItem 1；保存一次共享核心表达/故事线。
6. 后续添加公众号图文，三个分支在同一母体显示。
7. 进入进行中，只有一行内容，公众号汇总小绿书+图文，视频号显示视频。
8. 点击母内容返回详情，点击小绿书“停止制作”后浏览器输入连接阻塞。

只读数据库核对：Topic 1（parked/备用）→ ContentItem 1；分支 ID1=公众号image_post，ID2=视频号video，ID3=公众号article，均属于ContentItem1，无重复母体或重复组合。确认框未完成前 stopped_at 均为null，不能把点击当作停止成功。

## 问题与热修

- 确定性小问题：保存灵感选题后 URL 仍带 inspiration=1，刷新重新显示相同灵感的创建表单。已真实导航重现。根因是只清除组件字段，未清除导航参数。
- 热修：保存成功后用 Inertia router.replace 清除一次性灵感参数，保留当前组件状态和滚动位置，不新增网络请求或操作步骤。类型检查、构建已通过；浏览器 GREEN 复验仍待解除输入阻塞，暂不作为已验收修复。
- 环境阻塞：点击 window.confirm 后 Input.dispatchMouseEvent/Emulation.setFocusEmulationEnabled 超时；getJsDialog 未读取到确认框。新标签能导航和读页面，但点击不产生业务效果。已尝试文档支持的确认框、键盘、恢复标签页方式，未通过；未绕过浏览器或修改产品来迎合工具。
- 非阻塞观察：进入项目首先到栏目设置；478px窄侧栏视口下固定导航挤压内容。桌面1280px下信息层级清楚。此任务未扩大范围修改旧入口或重做响应式导航。

## 初次中断时的待完成清单（已在下方全部补验）

- 停止一个分支，核对 stopped_at 与其他分支不变；恢复并证明 ID1 原记录复用、总数仍3。
- 再次开始创作/返回已有内容，确认 Topic1 仅有 ContentItem1。
- 内容库返回、浏览器后退/前进、直建无来源选题。
- Project A/B 切换、跨项目直接地址404；隔离自动测试已通过，不能替代本条手验。
- 热修保存后 URL 清理、刷新不重新预填、后退不重复创建。

## 本轮技术检查

- PHP 全量：294 tests / 3471 assertions PASS（本机PHP8.5.10）。
- MySQL8.4 gate：实际重新运行正式基线工作流38292118246；新job114961218151 SUCCESS，真库全量307 tests / 3543 assertions、定向13 tests / 122 assertions PASS。后端/schema本任务未改。
- npm run typecheck：PASS（热修后再次运行）。
- npm run build：PASS，862 modules（热修后再次运行）。
- git branch/status/log/diff --check：已执行；最终状态随提交/推送复核。
- 未把之前绿色CI当作本轮重跑；未宣称浏览器完整通过。

## 初次中断时的建议（历史记录）

关闭原验收页原生确认框后，继续此编号任务的待完成清单并记录结果。未验收完前不要把本分支视为集成批准；不得继续其他任务或自行合并。

## 恢复后的最终验收（2026-10-13）

- 用户反馈未看到确认框；重新连接时页面为“无法访问此站点”，恢复本地服务后登录页正常。初次将全部阻塞归因于可见确认框的结论证据不足，予以更正。
- 小型UX热修：ContentLibrary/Show.vue 把window.confirm改为有标题、历史保留提示、取消/确认按钮的页面内dialog，与已有开始/添加分支交互一致。未改变API、数据模型、状态、权限或Phase2主体。
- 浏览器GREEN：取消不提交；重新确认停止后出现“已停止，历史记录保留”；SQLite读回只有Branch1 stopped_at有值，Branch2/3仍null。
- 浏览器点击恢复后停止状态消失；最终读回仍为Branch1/2/3，Branch1 stopped_at清空，ContentItem仍仅ID1/Topic1，没有新母体或新分支。
- 选题暂存+备用筛选后只提供“查看内容”，回到同一/projects/1/content/1；没有重复开始入口。
- 返回内容库、浏览器后退回详情、前进回内容库均通过；来源展开显示真实灵感和选题。
- 项目切B后内容库/进行中/灵感为空；B路径请求A的Item1返回404；当前Project为B时直接请求A URL同样404；后退、切回A正常。
- 保存新的灵感来源回归选题后URL为/projects/1/topics，不再带inspiration；刷新显示空白“直接创建选题”表单，原回归选题仍在池中，不重复预填。
- 直接创建测试Topic3，来源显示直接创建，数据库inspiration_id=null；额外Topic2/3仅用于回归，不创建ContentItem，不影响原Topic1的单一母体约束。
- 待我审核仍为清晰占位页，小红书禁用；未扩展Phase2功能。
- 最终桌面截图：foundation-acceptance-done.jpg（聊天输出附件）；展示一行内容、公众号两形式和视频号。
- Typecheck、build在页面内确认框热修后再次PASS，862modules；PHP全量最终结果见执行台账。MySQL gate本任务重跑307/3543 PASS，PHP/schema未改，后端与正式基线一致。
- 非阻塞观察仍保留：首次进入项目落到栏目设置；窄侧栏视口拥挤；进行中“开始制作”在Foundation只进入详情，实际制作属于Phase2。本任务不扩大范围处理这些观察。
- Next Recommended：交集成阶段审阅此独立验收分支；不自行merge，不继续下一任务。最终HEAD见git log与执行报告。