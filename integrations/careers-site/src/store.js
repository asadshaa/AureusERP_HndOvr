import { mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { dirname, join, normalize, sep } from 'node:path';
import { tmpdir } from 'node:os';

/**
 * Storage for jobs, applications and CVs. On Vercel with a Blob store
 * (BLOB_READ_WRITE_TOKEN set) everything is a PRIVATE blob: only this site,
 * with its token, can read it. Elsewhere (local runs, tests) files go to
 * DATA_DIR (default ./.data).
 *
 * Keys: jobs/{token}.json, applications/{id}.json, cvs/{id}.{ext}
 */
export async function createStore(env = process.env) {
  if (env.BLOB_READ_WRITE_TOKEN) {
    return new BlobStore(await import('@vercel/blob'), env.BLOB_READ_WRITE_TOKEN);
  }

  const dir = env.DATA_DIR || (env.VERCEL ? join(tmpdir(), 'careers-site') : join(process.cwd(), '.data'));

  return new FileStore(dir);
}

export class FileStore {
  constructor(dir) {
    this.dir = dir;
  }

  path(key) {
    const full = normalize(join(this.dir, key));

    if (!full.startsWith(normalize(this.dir) + sep)) {
      throw new Error('Invalid storage key.');
    }

    return full;
  }

  async getJson(key) {
    try {
      return JSON.parse(await readFile(this.path(key), 'utf8'));
    } catch (error) {
      if (error.code === 'ENOENT') {
        return null;
      }

      throw error;
    }
  }

  async putJson(key, value) {
    await this.putFile(key, Buffer.from(JSON.stringify(value)), 'application/json');
  }

  async putFile(key, bytes, contentType) {
    const full = this.path(key);
    await mkdir(dirname(full), { recursive: true });
    await writeFile(full, bytes);
    await writeFile(`${full}.type`, contentType || 'application/octet-stream');
  }

  async getFile(key) {
    try {
      const bytes = await readFile(this.path(key));
      const contentType = await readFile(`${this.path(key)}.type`, 'utf8').catch(() => 'application/octet-stream');

      return { bytes, contentType, size: bytes.length };
    } catch (error) {
      if (error.code === 'ENOENT') {
        return null;
      }

      throw error;
    }
  }

  async list(prefix) {
    try {
      const folder = this.path(prefix.replace(/\/$/, '') + '/x').slice(0, -2);
      const files = await readdir(folder);

      return files.filter((file) => !file.endsWith('.type')).map((file) => `${prefix.replace(/\/$/, '')}/${file}`);
    } catch (error) {
      if (error.code === 'ENOENT') {
        return [];
      }

      throw error;
    }
  }

  async delete(key) {
    await rm(this.path(key), { force: true });
    await rm(`${this.path(key)}.type`, { force: true });
  }
}

export class BlobStore {
  constructor(blob, token) {
    this.blob = blob;
    this.token = token;
  }

  async getJson(key) {
    const file = await this.getFile(key);

    return file ? JSON.parse(Buffer.from(file.bytes).toString('utf8')) : null;
  }

  async putJson(key, value) {
    await this.putFile(key, JSON.stringify(value), 'application/json');
  }

  async putFile(key, bytes, contentType) {
    await this.blob.put(key, bytes, {
      access: 'private',
      addRandomSuffix: false,
      allowOverwrite: true,
      contentType: contentType || 'application/octet-stream',
      token: this.token,
    });
  }

  async getFile(key) {
    try {
      const result = await this.blob.get(key, { access: 'private', useCache: false, token: this.token });

      if (!result || result.statusCode !== 200 || !result.stream) {
        return null;
      }

      const bytes = Buffer.from(await new Response(result.stream).arrayBuffer());

      return { bytes, contentType: result.blob.contentType, size: bytes.length };
    } catch (error) {
      if (error?.name === 'BlobNotFoundError' || error instanceof this.blob.BlobNotFoundError) {
        return null;
      }

      throw error;
    }
  }

  async list(prefix) {
    const keys = [];
    let cursor;

    do {
      const page = await this.blob.list({ prefix: prefix.replace(/\/$/, '') + '/', cursor, token: this.token });
      keys.push(...page.blobs.map((blob) => blob.pathname));
      cursor = page.hasMore ? page.cursor : undefined;
    } while (cursor);

    return keys;
  }

  async delete(key) {
    await this.blob.del(key, { token: this.token });
  }
}
