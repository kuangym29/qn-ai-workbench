import type { ContentBranch } from '../../api/v2';

export function contentBranchLinks(projectId: number | string, itemId: number | string, branch: Pick<ContentBranch, 'id' | 'channel' | 'format'>): {editorHref?: string; editorLabel?: string; productionHref?: string} {
    const base = `/projects/${projectId}/content/${itemId}/branches/${branch.id}`;
    if (branch.channel === 'wechat_official' && branch.format === 'article') return {editorHref: `${base}/article`, editorLabel: '编辑图文文章'};
    if (branch.channel === 'wechat_channels' && branch.format === 'video') return {editorHref: `${base}/video`, editorLabel: '编辑视频制作'};
    if (branch.channel === 'wechat_official' && branch.format === 'image_post') return {editorHref: `${base}/image-post`, editorLabel: '编辑图片文章', productionHref: `${base}/image-batch`};
    return {};
}
