#!/usr/bin/env node
// plugged.host storage agent (WP9).
//
// Runs on the storage box, NOT the panel host. It PULLS: it initiates each rsync from the nodes,
// serves an on-demand tar of a server's mirror behind an HMAC-signed URL (so wings can restore
// from it directly), answers node-reachability probes for the panel, reports mirror size and
// freshness back, and self-updates from a signed bundle the panel serves.
//
// Uses only Node built-ins — no npm dependencies to install on the box.
//
// Config comes from the environment (see install.sh / the systemd unit):
//   PH_PANEL_URL           e.g. https://panel.plugged.host
//   PH_AGENT_SECRET        shared HMAC secret (STORAGE_AGENT_SECRET on the panel)
//   PH_STORAGE_ROOT        default /mnt/storage
//   PH_LISTEN_PORT         default 8687  (inbound: archives + reachability + health)
//   PH_MIN_FREE_PERCENT    default 10    (refuse a pass below this)
//   PH_SNAPSHOTS           default 3     (rotating --link-dest snapshots kept)
//   PH_MIRROR_INTERVAL_MIN default 30
//   PH_UPDATE_INTERVAL_MIN default 15
//   PH_RELEASE_DIR         default /opt/plugged-host-storage   (for self-update symlink swap)
//   PH_VERSION             current version string (stamped by install.sh into the unit)

import http from 'node:http';
import https from 'node:https';
import net from 'node:net';
import crypto from 'node:crypto';
import fs from 'node:fs';
import fsp from 'node:fs/promises';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { pipeline } from 'node:stream/promises';

const CFG = {
  panelUrl: (process.env.PH_PANEL_URL || '').replace(/\/+$/, ''),
  secret: process.env.PH_AGENT_SECRET || '',
  root: process.env.PH_STORAGE_ROOT || '/mnt/storage',
  port: parseInt(process.env.PH_LISTEN_PORT || '8687', 10),
  minFreePercent: parseFloat(process.env.PH_MIN_FREE_PERCENT || '10'),
  snapshots: parseInt(process.env.PH_SNAPSHOTS || '3', 10),
  mirrorIntervalMin: parseInt(process.env.PH_MIRROR_INTERVAL_MIN || '30', 10),
  updateIntervalMin: parseInt(process.env.PH_UPDATE_INTERVAL_MIN || '15', 10),
  releaseDir: process.env.PH_RELEASE_DIR || '/opt/plugged-host-storage',
  version: process.env.PH_VERSION || 'dev',
  // How to turn a plan entry (host + path + uuid) into an rsync source. The pull credential
  // lives HERE, on the storage box, never on the panel. Placeholders: %HOST% %PATH% %UUID%.
  // Default is the SSH form; set to an rsync:// module URL if using an rsync daemon instead.
  rsyncTemplate: process.env.PH_RSYNC_TEMPLATE || '%USER%@%HOST%:%PATH%/',
  rsyncUser: process.env.PH_RSYNC_USER || 'ph-mirror',
  rsyncSshKey: process.env.PH_RSYNC_SSH_KEY || '',
};

const DIRS = {
  mirror: path.join(CFG.root, 'mirror'),
  snapshots: path.join(CFG.root, 'snapshots'),
  archives: path.join(CFG.root, 'archives'),
};

function log(level, msg, extra) {
  const line = JSON.stringify({ ts: new Date().toISOString(), level, msg, ...(extra || {}) });
  (level === 'error' ? process.stderr : process.stdout).write(line + '\n');
}

// ---- HMAC helpers -----------------------------------------------------------

function hmac(data) {
  return crypto.createHmac('sha256', CFG.secret).update(data).digest('hex');
}

// Constant-time compare that never throws on length mismatch.
function safeEqual(a, b) {
  const ba = Buffer.from(String(a));
  const bb = Buffer.from(String(b));
  if (ba.length !== bb.length) return false;
  return crypto.timingSafeEqual(ba, bb);
}

// Signature the panel must present to fetch an archive or a reachability probe:
//   sig = hmac(`${kind}:${arg}:${expires}`)
function verifySignedRequest(kind, arg, expires, sig) {
  if (!expires || !sig) return false;
  if (Number(expires) * 1000 < Date.now()) return false;
  return safeEqual(sig, hmac(`${kind}:${arg}:${expires}`));
}

// ---- Panel API (outbound, agent-signed) -------------------------------------

function panelRequest(method, apiPath, bodyObj) {
  return new Promise((resolve, reject) => {
    if (!CFG.panelUrl) return reject(new Error('PH_PANEL_URL not set'));
    const url = new URL(CFG.panelUrl + apiPath);
    const body = bodyObj ? JSON.stringify(bodyObj) : '';
    const ts = Math.floor(Date.now() / 1000).toString();
    const bodyHash = crypto.createHash('sha256').update(body).digest('hex');
    const sig = hmac(`${ts}\n${method}\n${apiPath}\n${bodyHash}`);
    const lib = url.protocol === 'http:' ? http : https;
    const req = lib.request(
      url,
      {
        method,
        headers: {
          'Content-Type': 'application/json',
          'X-PH-Timestamp': ts,
          'X-PH-Signature': sig,
          'X-PH-Agent-Version': CFG.version,
          'Content-Length': Buffer.byteLength(body),
        },
        timeout: 30000,
      },
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => {
          if (res.statusCode < 200 || res.statusCode >= 300) {
            return reject(new Error(`panel ${method} ${apiPath} -> ${res.statusCode}: ${data.slice(0, 200)}`));
          }
          try {
            resolve(data ? JSON.parse(data) : {});
          } catch {
            resolve({});
          }
        });
      },
    );
    req.on('error', reject);
    req.on('timeout', () => req.destroy(new Error('panel request timeout')));
    if (body) req.write(body);
    req.end();
  });
}

// ---- filesystem helpers -----------------------------------------------------

async function ensureDirs() {
  for (const d of [DIRS.mirror, DIRS.snapshots, DIRS.archives]) {
    await fsp.mkdir(d, { recursive: true });
  }
}

// Percent free on the storage filesystem, via `df`.
function freePercent() {
  return new Promise((resolve) => {
    run('df', ['-P', CFG.root])
      .then(({ stdout }) => {
        const line = stdout.trim().split('\n').pop() || '';
        const cols = line.split(/\s+/);
        const usePct = parseFloat((cols[4] || '100%').replace('%', ''));
        resolve(isNaN(usePct) ? 0 : 100 - usePct);
      })
      .catch(() => resolve(0));
  });
}

function dirSizeBytes(dir) {
  return run('du', ['-sb', dir])
    .then(({ stdout }) => parseInt(stdout.trim().split(/\s+/)[0] || '0', 10))
    .catch(() => 0);
}

function run(cmd, args, opts = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn(cmd, args, { ...opts });
    let stdout = '';
    let stderr = '';
    child.stdout?.on('data', (c) => (stdout += c));
    child.stderr?.on('data', (c) => (stderr += c));
    child.on('error', reject);
    child.on('close', (code) => {
      if (code === 0) resolve({ code, stdout, stderr });
      else reject(new Error(`${cmd} exited ${code}: ${stderr.slice(0, 300)}`));
    });
  });
}

// ---- replication ------------------------------------------------------------

// One delta-mirror pass for a single server.
//   plan entry: { uuid, server_id, rsync_source }
// rsync_source is the storage box's read-only pull spec for that server's volume, e.g.
//   "rsync://mirror@node1/volumes/<uuid>/"  or  "user@node1:/var/lib/pelican/volumes/<uuid>/"
// held ON THE STORAGE BOX (never on the panel).
async function mirrorServer(entry) {
  const uuid = entry.uuid;
  if (!uuid || !entry.host || !entry.path) return;

  const source = CFG.rsyncTemplate
    .replace(/%USER%/g, CFG.rsyncUser)
    .replace(/%HOST%/g, entry.host)
    .replace(/%PATH%/g, entry.path)
    .replace(/%UUID%/g, uuid);

  const dest = path.join(DIRS.mirror, uuid);
  await fsp.mkdir(dest, { recursive: true });

  const free = await freePercent();
  if (free < CFG.minFreePercent) {
    // A truncated mirror is worse than a stale one — refuse and report.
    await report(entry, { state: 'error', last_error: `free space ${free.toFixed(1)}% below floor ${CFG.minFreePercent}%` });
    log('error', 'skipping pass: below free-space floor', { uuid, free });
    return;
  }

  await report(entry, { state: 'replicating' });

  // Rotating hardlink snapshots: link-dest against the most recent snapshot so unchanged files
  // cost only a directory entry. mirror/ and snapshots/ MUST be one filesystem or this silently
  // makes full copies.
  const snapBase = path.join(DIRS.snapshots, uuid);
  await fsp.mkdir(snapBase, { recursive: true });
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const linkDestArgs = [];
  const existing = (await fsp.readdir(snapBase).catch(() => [])).sort();
  const latest = existing[existing.length - 1];
  if (latest) linkDestArgs.push('--link-dest', path.join(snapBase, latest));

  const sshArgs = CFG.rsyncSshKey
    ? ['-e', `ssh -i ${CFG.rsyncSshKey} -o StrictHostKeyChecking=accept-new -o BatchMode=yes`]
    : [];

  const rsyncArgs = [
    '-a', '--delete', '--partial', '--timeout=120',
    ...sshArgs,
    ...linkDestArgs,
    source,
    dest + '/',
  ];

  try {
    await run('rsync', rsyncArgs);
  } catch (e) {
    await report(entry, { state: 'error', last_error: String(e.message).slice(0, 500) });
    log('error', 'rsync failed', { uuid, err: e.message });
    return;
  }

  // Snapshot the freshly-synced mirror by hardlinking it.
  const snapDir = path.join(snapBase, stamp);
  try {
    await run('cp', ['-al', dest, snapDir]);
  } catch (e) {
    log('error', 'snapshot failed (non-fatal)', { uuid, err: e.message });
  }

  // Rotate: keep the newest N snapshots.
  const snaps = (await fsp.readdir(snapBase).catch(() => [])).sort();
  while (snaps.length > CFG.snapshots) {
    const victim = snaps.shift();
    await run('rm', ['-rf', path.join(snapBase, victim)]).catch(() => {});
  }

  const bytes = await dirSizeBytes(dest);
  await report(entry, { state: 'ok', bytes, last_replicated_at: new Date().toISOString(), mirror_path: dest });
  log('info', 'mirror pass complete', { uuid, bytes });
}

async function report(entry, fields) {
  try {
    await panelRequest('POST', '/plugged-host/storage/agent/report', {
      server_id: entry.server_id,
      uuid: entry.uuid,
      ...fields,
    });
  } catch (e) {
    log('error', 'report failed', { uuid: entry.uuid, err: e.message });
  }
}

// crc32-style stagger so servers do not all replicate on the same tick.
function staggerOffsetMinutes(uuid) {
  let h = 0;
  for (const ch of uuid) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
  return h % Math.max(1, CFG.mirrorIntervalMin);
}

let mirroring = false;
async function replicationTick() {
  if (mirroring) return;
  mirroring = true;
  try {
    const plan = await panelRequest('GET', '/plugged-host/storage/agent/plan');
    const servers = Array.isArray(plan.servers) ? plan.servers : [];
    const minute = new Date().getMinutes() % Math.max(1, CFG.mirrorIntervalMin);
    for (const entry of servers) {
      if (staggerOffsetMinutes(String(entry.uuid || '')) !== minute) continue;
      await mirrorServer(entry).catch((e) => log('error', 'mirrorServer threw', { err: e.message }));
    }
  } catch (e) {
    log('error', 'replication tick failed', { err: e.message });
  } finally {
    mirroring = false;
  }
}

// ---- inbound HTTP: archives, reachability, health ---------------------------

async function buildArchive(uuid) {
  const src = path.join(DIRS.mirror, uuid);
  if (!fs.existsSync(src)) return null;
  const out = path.join(DIRS.archives, `${uuid}.tar.gz`);
  // Rebuild if older than 5 min or absent, so a restore gets a fresh point-in-time tar.
  try {
    const st = await fsp.stat(out);
    if (Date.now() - st.mtimeMs < 5 * 60 * 1000) return out;
  } catch { /* not built yet */ }
  await run('tar', ['-czf', out, '-C', src, '.']);
  return out;
}

function probeTcp(host, port, timeoutMs = 3000) {
  return new Promise((resolve) => {
    const sock = net.connect({ host, port: Number(port) });
    const done = (ok) => {
      sock.destroy();
      resolve(ok);
    };
    sock.setTimeout(timeoutMs);
    sock.once('connect', () => done(true));
    sock.once('timeout', () => done(false));
    sock.once('error', () => done(false));
  });
}

function startServer() {
  const server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, 'http://localhost');
      const q = url.searchParams;

      if (url.pathname === '/health') {
        const free = await freePercent();
        res.writeHead(200, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({ ok: true, version: CFG.version, free_percent: free }));
      }

      // GET /archive/<uuid>?expires=..&sig=..  -> streams the on-demand tar for wings to pull.
      if (url.pathname.startsWith('/archive/')) {
        const uuid = decodeURIComponent(url.pathname.slice('/archive/'.length));
        if (!/^[a-f0-9-]{8,}$/i.test(uuid) || !verifySignedRequest('archive', uuid, q.get('expires'), q.get('sig'))) {
          res.writeHead(403); return res.end('forbidden');
        }
        const tar = await buildArchive(uuid);
        if (!tar) { res.writeHead(404); return res.end('no mirror'); }
        res.writeHead(200, { 'Content-Type': 'application/gzip' });
        return pipeline(fs.createReadStream(tar), res).catch(() => res.destroy());
      }

      // GET /reachable?host=..&port=..&expires=..&sig=..  -> second-witness node probe.
      if (url.pathname === '/reachable') {
        const host = q.get('host') || '';
        const port = q.get('port') || '';
        if (!verifySignedRequest('reachable', `${host}:${port}`, q.get('expires'), q.get('sig'))) {
          res.writeHead(403); return res.end('forbidden');
        }
        const ok = await probeTcp(host, port);
        res.writeHead(200, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({ reachable: ok }));
      }

      res.writeHead(404); res.end('not found');
    } catch (e) {
      log('error', 'request handler threw', { err: e.message });
      try { res.writeHead(500); res.end('error'); } catch { /* already sent */ }
    }
  });
  server.listen(CFG.port, () => log('info', 'agent listening', { port: CFG.port }));
}

// ---- self-update (pull, verify signature + digest, swap symlink) ------------

async function fetchSigned(apiPath) {
  // Returns { buffer, headers } for a panel-signed static file.
  return new Promise((resolve, reject) => {
    const url = new URL(CFG.panelUrl + apiPath);
    const lib = url.protocol === 'http:' ? http : https;
    lib.get(url, { timeout: 60000 }, (res) => {
      if (res.statusCode !== 200) { res.resume(); return reject(new Error(`${apiPath} -> ${res.statusCode}`)); }
      const chunks = [];
      res.on('data', (c) => chunks.push(c));
      res.on('end', () => resolve({ buffer: Buffer.concat(chunks), headers: res.headers }));
    }).on('error', reject);
  });
}

async function updateTick() {
  try {
    const { buffer, headers } = await fetchSigned('/plugged-host/storage/agent/manifest.json');
    // manifest is signed with hmac over its own JSON body, in X-PH-Signature.
    if (!safeEqual(headers['x-ph-signature'] || '', hmac(buffer.toString()))) {
      log('error', 'manifest signature mismatch, ignoring'); return;
    }
    const manifest = JSON.parse(buffer.toString());
    if (!manifest.version || manifest.version === CFG.version) return; // up to date

    const { buffer: bundle } = await fetchSigned('/plugged-host/storage/agent/bundle.tar.gz');
    const digest = crypto.createHash('sha256').update(bundle).digest('hex');
    if (!safeEqual(digest, manifest.sha256 || '')) {
      log('error', 'bundle digest mismatch, refusing update', { want: manifest.sha256, got: digest }); return;
    }

    const releaseDir = path.join(CFG.releaseDir, 'releases', String(manifest.version));
    await fsp.mkdir(releaseDir, { recursive: true });
    const tmpTar = path.join(CFG.releaseDir, `.bundle-${manifest.version}.tar.gz`);
    await fsp.writeFile(tmpTar, bundle);
    await run('tar', ['-xzf', tmpTar, '-C', releaseDir]);
    await fsp.unlink(tmpTar).catch(() => {});

    // Atomically swap current -> the new release; the previous stays for rollback.
    const currentLink = path.join(CFG.releaseDir, 'current');
    const tmpLink = currentLink + '.new';
    await fsp.rm(tmpLink, { force: true }).catch(() => {});
    await fsp.symlink(releaseDir, tmpLink);
    await fsp.rename(tmpLink, currentLink);

    log('info', 'updated, restarting for new version', { version: manifest.version });
    process.exit(0); // systemd Restart=always brings us back on the new symlink
  } catch (e) {
    log('error', 'update tick failed', { err: e.message });
  }
}

// ---- main -------------------------------------------------------------------

async function main() {
  if (!CFG.secret) { log('error', 'PH_AGENT_SECRET not set; refusing to start'); process.exit(1); }
  await ensureDirs();
  startServer();

  // Kick immediately, then on interval. Replication checks the per-server stagger each minute.
  const everyMinute = 60 * 1000;
  setInterval(() => replicationTick(), everyMinute);
  setInterval(() => updateTick(), CFG.updateIntervalMin * everyMinute);
  replicationTick();
  updateTick();

  log('info', 'plugged.host storage agent started', { version: CFG.version, root: CFG.root });
}

main().catch((e) => { log('error', 'fatal', { err: e.message }); process.exit(1); });
