import { createServer } from 'node:http';
import { Readable } from 'node:stream';
import { createApp } from './src/app.js';
import { createStore } from './src/store.js';

/**
 * Local run without Vercel: `npm run dev` (PORT, default 3070). Data goes to
 * ./.data unless BLOB_READ_WRITE_TOKEN is set.
 */
const port = Number.parseInt(process.env.PORT || '3070', 10);
const handle = createApp({ store: await createStore() });

createServer(async (req, res) => {
  // Behind a tunnel or proxy (e.g. Cloudflare), visitors arrive over https.
  const scheme = req.headers['x-forwarded-proto'] === 'https' ? 'https' : 'http';
  const request = new Request(`${scheme}://${req.headers.host || `127.0.0.1:${port}`}${req.url}`, {
    method: req.method,
    headers: req.headers,
    body: ['GET', 'HEAD'].includes(req.method) ? undefined : Readable.toWeb(req),
    duplex: 'half',
  });

  const response = await handle(request);
  res.writeHead(response.status, Object.fromEntries(response.headers));
  res.end(Buffer.from(await response.arrayBuffer()));
}).listen(port, '127.0.0.1', () => {
  console.log(`Careers site on http://127.0.0.1:${port}/careers`);
});
