import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { build } from 'esbuild';
import { parse, compileScript } from '@vue/compiler-sfc';
import { createRenderer, reactive, h, nextTick } from 'vue';

// P5-I02：复用资产新增 / 详情编辑弹层的渲染契约。
//   1. create 模式：名称可编辑、标题「新增人物 / 新增场景」
//   2. edit 模式：名称 / 类型 / 最近使用 只读；状态 / 预览图 / 说明 可编辑
//   3. 状态 UI 只出现业务中文（已有稳定母版 / 待建立），不暴露 existing / missing
//
// 说明：这里用最小自定义渲染器（无 DOM），只断言结构与属性；
// 真实输入 / 保存闭环由 Playwright 浏览器验收覆盖。

const vueUrl = new URL('../../../../node_modules/vue/dist/vue.runtime.esm-bundler.js', import.meta.url).href;

const bundle = await build({
  entryPoints: ['resources/js/components/V2/ReusableAssets/ReusableAssetEditorDialog.vue'],
  bundle: true,
  write: false,
  format: 'esm',
  external: ['vue'],
  plugins: [
    {
      name: 'vue',
      setup(b) {
        b.onLoad({ filter: /\.vue$/ }, (a) => ({
          loader: 'ts',
          contents: compileScript(
            parse(readFileSync(a.path, 'utf8'), { filename: a.path }).descriptor,
            { id: a.path, inlineTemplate: true, fs: { fileExists: existsSync, readFile: (p) => readFileSync(p, 'utf8') } },
          ).content,
        }));
      },
    },
  ],
});

const { default: Dialog } = await import(
  'data:text/javascript;base64,' +
  Buffer.from(bundle.outputFiles[0].text.replaceAll('from "vue"', `from ${JSON.stringify(vueUrl)}`)).toString('base64')
);

const renderer = createRenderer({
  // 伪元素带上 v-model 指令需要的属性（options / multiple / value / addEventListener），
  // 让内置 vModelText / vModelSelect 在没有真实 DOM 时也能安全挂载。
  createElement: (tag) => ({
    tag,
    children: [],
    props: {},
    options: [],
    multiple: false,
    selectedOptions: [],
    addEventListener() {},
    removeEventListener() {},
  }),
  insert: (c, p) => { c.parent = p; p.children.push(c); },
  remove: (n) => { if (n.parent) n.parent.children = n.parent.children.filter((c) => c !== n); },
  setElementText: (n, t) => { n.text = t; },
  createText: (text) => ({ text }),
  createComment: (text) => ({ text }),
  setText: (n, t) => { n.text = t; },
  patchProp: (n, k, o, v) => { n.props[k] = v; },
  parentNode: (n) => n.parent,
  nextSibling: () => null,
  setComment() {},
});

const flush = async () => { await Promise.resolve(); await Promise.resolve(); await nextTick(); };

/** 文本节点 / 注释节点 / 元素节点混在一起，遍历与断言都要做空值保护。 */
const nodeText = (n) => {
  const own = n.text ?? '';
  const kids = (n.children ?? []).map(nodeText).join('');
  return own + kids;
};

function mount(props) {
  const reactiveProps = reactive(props);
  const root = { children: [] };
  const app = renderer.createApp({ setup: () => () => h(Dialog, reactiveProps) });
  app.mount(root);

  const nodes = () => {
    const out = [];
    (function walk(n) { out.push(n); for (const c of n.children ?? []) walk(c); })(root);
    return out;
  };

  return {
    props: reactiveProps,
    app,
    nodes,
    text: () => nodes().map(nodeText).join(' '),
    byTag: (tag) => nodes().filter((n) => n.tag === tag),
    /** 按属性「存在性」筛选（布尔属性值为空字符串，用 hasOwnProperty 判定）。 */
    has: (attr) => nodes().filter((n) => n.props != null && Object.prototype.hasOwnProperty.call(n.props, attr)),
    byProp: (key, value) => nodes().filter((n) => n.props != null && n.props[key] === value),
    propOf: (key, value, prop) => nodes().find((n) => n.props != null && n.props[key] === value)?.props?.[prop],
  };
}

const baseItem = {
  id: 1,
  type: 'character',
  name: '妈妈',
  status: 'missing',
  thumbnail_url: null,
  description: null,
  last_used_at: null,
};

test('create 模式：标题为「新增人物」，名称输入可编辑', async () => {
  const x = mount({ open: true, mode: 'create', type: 'character', item: null, saving: false, error: '' });
  await flush();

  assert.equal(x.has('data-v2-asset-dialog').length, 1, '必须渲染弹层');
  assert.ok(x.text().includes('新增人物'));

  const nameInput = x.byProp('data-v2-field', 'name')[0];
  assert.ok(nameInput, '必须有名称输入框');
  assert.equal(nameInput.tag, 'input');
  assert.equal(nameInput.props.type, 'text');
  assert.notEqual(nameInput.props.readonly, true, 'create 模式名称必须可编辑');

  x.app.unmount();
});

test('create 模式（场景）：标题为「新增场景」', async () => {
  const x = mount({ open: true, mode: 'create', type: 'scene', item: null, saving: false, error: '' });
  await flush();
  assert.ok(x.text().includes('新增场景'));
  x.app.unmount();
});

test('edit 模式：名称只读、类型只读渲染、最近使用只读', async () => {
  const x = mount({ open: true, mode: 'edit', type: 'character', item: baseItem, saving: false, error: '' });
  await flush();

  assert.ok(x.text().includes('人物详情'));

  const nameInput = x.byProp('data-v2-field', 'name')[0];
  assert.ok(nameInput, '必须有名称输入框');
  assert.equal(nameInput.props.readonly, true, '名称必须只读（稳定身份）');

  const typeField = x.byProp('data-v2-field', 'type')[0];
  assert.ok(typeField, '类型必须以只读文本呈现');
  assert.equal(typeField.tag, 'p', '类型不是输入框');
  assert.equal(nodeText(typeField), '人物');
  assert.equal(typeField.props.readonly, undefined, '类型不是输入框');

  const lastUsed = x.byProp('data-v2-field', 'last_used_at')[0];
  assert.ok(lastUsed, '必须展示最近使用（只读）');
  assert.equal(lastUsed.tag, 'p', '最近使用不是输入框（只读文本）');
  assert.equal(nodeText(lastUsed), '未使用');
  assert.equal(lastUsed.props.readonly, undefined, '最近使用不是输入框');

  x.app.unmount();
});

test('状态下拉：选项只出现业务中文，不暴露 existing / missing', async () => {
  const x = mount({ open: true, mode: 'edit', type: 'scene', item: { ...baseItem, type: 'scene' }, saving: false, error: '' });
  await flush();

  const select = x.byProp('data-v2-field', 'status')[0];
  assert.ok(select, '必须有状态下拉');
  assert.equal(select.tag, 'select');

  const optionTexts = x.byTag('option').map((o) => nodeText(o));
  assert.deepEqual(optionTexts, ['已有稳定母版', '待建立'], '只出现业务中文标签');

  const rendered = x.text();
  assert.ok(!rendered.includes('existing'), '不得显示 existing');
  assert.ok(!rendered.includes('missing'), '不得显示 missing');

  x.app.unmount();
});

test('预览图地址与说明可编辑（非只读），保存按钮存在', async () => {
  const x = mount({ open: true, mode: 'edit', type: 'character', item: baseItem, saving: false, error: '' });
  await flush();

  const thumb = x.byProp('data-v2-field', 'thumbnail_url')[0];
  const desc = x.byProp('data-v2-field', 'description')[0];
  assert.ok(thumb, '必须有预览图地址输入');
  assert.ok(desc, '必须有说明输入');
  assert.notEqual(thumb.props.readonly, true, '预览图地址必须可编辑');
  assert.notEqual(desc.props.readonly, true, '说明必须可编辑');

  assert.equal(x.has('data-v2-asset-save').length, 1, '必须有保存按钮');

  x.app.unmount();
});

test('保存中：按钮禁用并显示「保存中…」', async () => {
  const x = mount({ open: true, mode: 'create', type: 'character', item: null, saving: true, error: '' });
  await flush();

  const save = x.has('data-v2-asset-save')[0];
  assert.ok(save, '必须有保存按钮');
  assert.equal(save.props.disabled, true);
  assert.ok(nodeText(save).includes('保存中'), '保存中必须显示「保存中…」');

  x.app.unmount();
});

test('错误：error prop 呈现在弹层内（可恢复，不关弹层）', async () => {
  const x = mount({ open: true, mode: 'create', type: 'character', item: null, saving: false, error: '同名人物已存在（妈妈）。' });
  await flush();

  const alertBox = x.has('data-v2-asset-error')[0];
  assert.ok(alertBox, '错误必须显示在弹层内');
  assert.equal(alertBox.props.role, 'alert');
  assert.ok(nodeText(alertBox).includes('同名人物已存在'), '错误必须是可读中文');
  assert.equal(x.has('data-v2-asset-dialog').length, 1, '出错时弹层必须保持打开');

  x.app.unmount();
});

test('open=false 时不渲染弹层；open=true 时有弹层与关闭入口', async () => {
  const closed = mount({ open: false, mode: 'create', type: 'character', item: null, saving: false, error: '' });
  await flush();
  assert.equal(closed.has('data-v2-asset-dialog').length, 0, '关闭状态不得渲染弹层');
  assert.equal(closed.has('data-v2-field').length, 0, '关闭状态不得渲染任何字段');
  closed.app.unmount();

  const open = mount({ open: true, mode: 'create', type: 'character', item: null, saving: false, error: '' });
  await flush();
  assert.equal(open.has('data-v2-asset-dialog').length, 1);
  assert.ok(open.has('data-v2-asset-dialog-close')[0], '必须有关闭入口');
  open.app.unmount();
});
