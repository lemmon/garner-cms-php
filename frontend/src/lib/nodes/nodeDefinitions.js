import FileListNode from '$lib/components/nodes/FileListNode.svelte';
import PageListNode from '$lib/components/nodes/PageListNode.svelte';
import TextareaNode from '$lib/components/nodes/TextareaNode.svelte';
import TextNode from '$lib/components/nodes/TextNode.svelte';

/** Internal runtime capabilities for blueprint node types. */
export const nodeDefinitions = {
  file_list: {
    component: FileListNode,
    saveable: false,
  },
  page_list: {
    component: PageListNode,
    saveable: false,
  },
  text: {
    component: TextNode,
    saveable: true,
  },
  textarea: {
    component: TextareaNode,
    saveable: true,
  },
};
