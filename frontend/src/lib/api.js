import { error } from '@sveltejs/kit';

import { resolve } from '$app/paths';

const API_BASE = '/api/';

const headers = new Headers([
  ['Content-Type', 'application/json'],
  ['Accept', 'application/json'],
]);

/**
 * Simplified API client that always uses POST with JSON body.
 * @param {string} action - API endpoint path (e.g., 'projects/create')
 * @param {object} data - Request body data (defaults to empty object)
 * @returns {Promise<any>} Parsed JSON response
 */
export async function api(action, data = {}) {
  const response = await fetch(API_BASE + action, {
    method: 'POST',
    credentials: 'same-origin',
    headers,
    body: JSON.stringify(data),
  });

  if (response.status === 401) {
    window.location.href = resolve('/login');
    return new Promise(() => {});
  }

  let json;

  try {
    json = await response.json();
  } catch {
    if (!response.ok) throw error(response.status, response.statusText);
    throw new Error(`Invalid JSON response from ${API_BASE + action}`);
  }

  // Validation responses are expected control flow, not errors — return them
  // normally so callers can render field-level feedback without try/catch.
  if (json.invalid) {
    return json;
  }

  if (!response.ok) {
    throw error(response.status, json.message || response.statusText);
  }

  return json;
}
