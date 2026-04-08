import FileListNode from '$lib/components/nodes/FileListNode.svelte';
import PageListNode from '$lib/components/nodes/PageListNode.svelte';
import TextareaNode from '$lib/components/nodes/TextareaNode.svelte';
import TextNode from '$lib/components/nodes/TextNode.svelte';

/** Maps blueprint `node.type` strings to their editor components (all contexts). */
export const nodeComponents = {
  file_list: FileListNode,
  page_list: PageListNode,
  text: TextNode,
  textarea: TextareaNode,
};
