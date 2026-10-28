import { createApp } from '../src/app.js';
import { createStore } from '../src/store.js';

/**
 * Vercel function: every path is rewritten here (vercel.json) and handled by
 * the careers-site app. The store and app are created once per instance.
 */
let handler;

async function handle(request) {
  handler ??= createApp({ store: await createStore() });

  return handler(request);
}

export const GET = handle;
export const POST = handle;
export const PUT = handle;
export const DELETE = handle;
export const HEAD = handle;
