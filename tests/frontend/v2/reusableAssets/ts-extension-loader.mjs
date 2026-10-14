// 测试基建：为 node --experimental-strip-types 提供 bundler 式无扩展名解析。
// useVideoEditor.ts / videoApi.ts / videoCore.ts 的跨模块相对导入无扩展名
// （vite/vue-tsc 可解析），node 原生 ESM 需要显式扩展名。
// 本 loader 只对「相对路径 + 无扩展名」的 specifier 依次尝试 .ts / .js / .json。
export async function resolve(specifier, context, nextResolve) {
  const isRelative = specifier.startsWith('./') || specifier.startsWith('../');
  const hasExtension = /\.[a-z0-9]+$/i.test(specifier);
  if (isRelative && !hasExtension) {
    for (const ext of ['.ts', '.js', '.json']) {
      try {
        return await nextResolve(specifier + ext, context);
      } catch {
        // try next extension
      }
    }
  }
  return nextResolve(specifier, context);
}
