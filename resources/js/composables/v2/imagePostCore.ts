// Transport contract and display predicate only; backend owns parsing and diff.
import type { ImagePostEditorWorkspace, ImagePostImportPreview, ImagePostSaveInput, ImagePostWarning } from '../../types/v2/imagePost';
interface Transport { get(url: string): Promise<{ data: unknown }>; put(url: string, input: unknown): Promise<{ data: unknown }>; post(url: string, input: unknown): Promise<{ data: unknown }> }
export function createImagePostClient(http: Transport) {
 const base = (p: number | string, i: number | string, b: number | string) => '/api/projects/'+Number(p)+'/items/'+Number(i)+'/branches/'+Number(b)+'/image-post';
 return {
 async loadWorkspace(p: number | string,i: number | string,b: number | string) { return (await http.get(base(p,i,b))).data as ImagePostEditorWorkspace; },
 async save(p: number | string,i: number | string,b: number | string,input: ImagePostSaveInput) { return (await http.put(base(p,i,b),input)).data as { data: { status: string }; warnings: ImagePostWarning[] }; },
 async preview(p: number | string,i: number | string,b: number | string,raw: string) { return (await http.post(base(p,i,b)+'/preview',{raw})).data as ImagePostImportPreview; },
 async apply(p: number | string,i: number | string,b: number | string,raw: string) { return (await http.post(base(p,i,b)+'/apply',{raw})).data; },
 };
}
export function previewHasChanges(p: ImagePostImportPreview): boolean { return p.item.changed || p.pages.added.length>0 || p.pages.modified.length>0; }
