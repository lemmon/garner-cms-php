import { redirect } from '@sveltejs/kit';

import { resolve } from '$app/paths';

export async function load() {
  redirect(307, resolve('/site'));
}
