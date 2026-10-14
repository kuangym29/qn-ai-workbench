import { http } from '../../../api/http';
import type { ArticleEditorWorkspace, ArticleImportPreview, ArticleSaveInput } from '../../../types/v2/article/article';

/** Backend is the only parser/diff/apply source; writes return status, followed by GET. */
export function createArticleClient(transport: Pick<typeof http, 'get' | 'put' | 'post'>) {
  const base = (p: number | string, i: number | string, b: number | string) =>
    '/api/projects/' + Number(p) + '/items/' + Number(i) + '/branches/' + Number(b) + '/article';
  async function loadWorkspace(p: number | string, i: number | string, b: number | string): Promise<ArticleEditorWorkspace> {
    return (await transport.get<ArticleEditorWorkspace>(base(p, i, b))).data;
  }
  return {
    loadWorkspace,
    async save(p: number | string, i: number | string, b: number | string, input: ArticleSaveInput): Promise<ArticleEditorWorkspace> {
      await transport.put(base(p, i, b), { ...input, image_suggestions: input.image_suggestions ?? [] });
      return loadWorkspace(p, i, b);
    },
    async preview(p: number | string, i: number | string, b: number | string, raw: string): Promise<ArticleImportPreview> {
      return (await transport.post<ArticleImportPreview>(base(p, i, b) + '/preview', { raw })).data;
    },
    async apply(p: number | string, i: number | string, b: number | string, raw: string): Promise<ArticleEditorWorkspace> {
      await transport.post(base(p, i, b) + '/apply', { raw });
      return loadWorkspace(p, i, b);
    },
  };
}
export const articleApi = createArticleClient(http);
