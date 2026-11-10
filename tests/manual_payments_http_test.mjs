// Real multipart uploads and authenticated downloads against an isolated PHP/SQLite fixture.
import assert from 'node:assert/strict';
import { mkdtemp, readdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, resolve, basename } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { setTimeout as delay } from 'node:timers/promises';

const here = dirname(fileURLToPath(import.meta.url));
const testDir = await mkdtemp(join(tmpdir(), 'bplo-payment-http-'));
const reservation = createServer();
await new Promise(resolve => reservation.listen(0, '127.0.0.1', resolve));
const port = reservation.address().port;
await new Promise(resolve => reservation.close(resolve));
const base = `http://127.0.0.1:${port}/api/bplo`;
const php = process.env.PHP_BINARY || (process.platform === 'win32' ? 'C:/xampp/php/php.exe' : 'php');
const server = spawn(php, ['-d', 'upload_max_filesize=6M', '-d', 'post_max_size=8M', '-S', `127.0.0.1:${port}`, join(here, 'manual_payments_http_fixture.php')], {
  cwd: here, env: { ...process.env, BPLO_PAYMENT_HTTP_TEST_DIR: testDir }, windowsHide: true, stdio: ['ignore', 'ignore', 'pipe']
});
let startupError;
let log = '';
server.on('error', error => { startupError = error; });
server.stderr.on('data', chunk => { log = (log + chunk.toString()).slice(-5000); });
const tokens = { admin: 'a'.repeat(64), owner: 'b'.repeat(64), other: 'c'.repeat(64) };
const request = async (path, actor, method = 'GET', body, status = 200) => {
  const form = body instanceof FormData;
  const result = await fetch(base + path, { method, headers: { ...(actor ? { Authorization: `Bearer ${tokens[actor]}` } : {}), ...(body && !form ? { 'Content-Type': 'application/json' } : {}) }, body: body ? (form ? body : JSON.stringify(body)) : undefined });
  assert.equal(result.status, status, `${method} ${path}: ${await result.clone().text()}`);
  return result;
};
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j5WQAAAAASUVORK5CYII=', 'base64');
const today = (() => {
  const p = Object.fromEntries(new Intl.DateTimeFormat('en', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date()).map(v => [v.type, v.value]));
  return `${p.year}-${p.month}-${p.day}`;
})();
const proof = (amount = '12.34', bytes = png) => {
  const form = new FormData();
  Object.entries({ amount, reference: 'HTTP-REF-1234', paidOn: today }).forEach(([key, value]) => form.append(key, value));
  form.append('file', new Blob([bytes], { type: 'image/png' }), 'proof.png');
  return form;
};
try {
  let ready = false;
  for (let i = 0; i < 40; i++) {
    if (startupError) throw startupError;
    try { await request('/payments', null, 'GET', undefined, 401); ready = true; break; }
    catch { await delay(100); }
  }
  assert(ready, `PHP server did not start: ${log}`);
  const fee = { amount: '12.34', purpose: 'HTTP test fee', method: 'GCash', accountName: 'Test only', accountNumber: '09000000000', instructions: 'Do not transfer money' };
  const endpoint = '/applications/HTTP-1/payment';
  await request(endpoint + '/assessment', 'owner', 'PUT', fee, 403);
  const savedFee = await (await request(endpoint + '/assessment', 'admin', 'PUT', fee)).json();
  assert.equal(savedFee.notice.email.status, 'sent');
  assert.equal(savedFee.notice.sms.status, 'accepted');
  await request(endpoint + '/notice', 'owner', 'POST', {}, 403);
  const notice = await (await request(endpoint + '/notice', 'admin', 'POST', {})).json();
  assert.equal(notice.notice.sms.status, 'accepted');
  await request(endpoint, 'other', 'GET', undefined, 403);
  const ownList = await (await request('/payments', 'owner')).json();
  assert.equal(ownList.data.length, 1);
  await request(endpoint + '/submissions', 'owner', 'POST', proof('1'), 422);
  assert.deepEqual(await readdir(join(testDir, 'runtime/private_payments')), [], 'Rejected upload must be cleaned up');
  await request(endpoint + '/submissions', 'owner', 'POST', proof('12.34', Buffer.from('<html>fake image</html>')), 422);
  await request(endpoint + '/submissions', 'owner', 'POST', proof('12.34', Buffer.alloc(5 * 1024 * 1024 + 1)), 422);
  await request(endpoint + '/submissions', 'other', 'POST', proof(), 403);
  await request(endpoint + '/submissions', 'admin', 'POST', proof(), 403);
  await request(endpoint + '/submissions', 'owner', 'POST', proof(), 201);
  let state = await (await request(endpoint, 'owner')).json();
  assert.equal(state.paymentStatus, 'Awaiting verification');
  await request(endpoint + '/notice', 'admin', 'POST', {}, 409);
  const id = state.submissions[0].id;
  const download = `${endpoint}/submissions/${id}/proof`;
  await request(download, null, 'GET', undefined, 401);
  await request(download, 'other', 'GET', undefined, 403);
  const file = await request(download, 'owner');
  assert.equal(file.headers.get('content-type'), 'image/png');
  assert.equal(file.headers.get('cache-control'), 'private, no-store');
  assert.deepEqual(Buffer.from(await file.arrayBuffer()), png);
  await request(endpoint + '/submissions/999999/proof', 'owner', 'GET', undefined, 404);
  await request(`${endpoint}/submissions/${id}/review`, 'owner', 'PUT', { status: 'Verified' }, 403);
  await request(`${endpoint}/submissions/${id}/review`, 'admin', 'PUT', { status: 'Rejected', remarks: '' }, 422);
  await request(`${endpoint}/submissions/${id}/review`, 'admin', 'PUT', { status: 'Rejected', remarks: 'Please provide clearer proof' });
  await request(endpoint + '/submissions', 'owner', 'POST', proof(), 201);
  state = await (await request(endpoint, 'admin')).json();
  assert.equal(state.submissions.length, 2);
  assert.equal(state.submissions[0].review_status, 'Rejected');
  const nextId = state.submissions[1].id;
  await request(`${endpoint}/submissions/${nextId}/review`, 'admin', 'PUT', { status: 'Verified', remarks: 'Matched test transaction' });
  assert.equal((await (await request(endpoint, 'owner')).json()).paymentStatus, 'Paid');
  await request(endpoint + '/submissions', 'owner', 'POST', proof(), 409);
  console.log('PASS: HTTP authentication/ownership, real multipart upload, file size/MIME checks, failed-upload cleanup, protected binary download, rejection/retry and verification.');
} finally {
  if (server.pid && server.exitCode === null && server.signalCode === null) {
    const closed = new Promise(resolve => server.once('close', resolve));
    server.kill(); await closed;
  }
  // Verify the resolved target is the unique test directory created under the OS temp directory.
  const target = resolve(testDir);
  if (dirname(target) !== resolve(tmpdir()) || !basename(target).startsWith('bplo-payment-http-')) throw new Error('Unsafe test cleanup path');
  await rm(target, { recursive: true, force: true });
}
