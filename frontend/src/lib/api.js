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

  const contentType = response.headers.get('content-type') ?? '';
  const body = await response.text();
  const expectsJson = contentType.includes('application/json');
  let json = {};

  if (response.status === 401) {
    window.location.href = resolve('/login');
    return new Promise(() => {});
  }

  if (body !== '') {
    if (!expectsJson) {
      const message = response.ok
        ? `Expected JSON response from ${API_BASE + action}`
        : `Request to ${API_BASE + action} failed with ${response.status}`;

      throw new Error(message);
    }

    try {
      json = JSON.parse(body);
    } catch {
      throw new Error(`Invalid JSON response from ${API_BASE + action}`);
    }
  }

  // Validation responses are expected, not errors - return them normally
  if (json.invalid) {
    return json;
  }

  if (!response.ok) {
    const message = json.message || response.statusText;
    throw new Error(message);
  }

  return json;
}
