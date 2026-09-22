import { test } from 'node:test';
import assert from 'node:assert/strict';
import { csrfHeaders } from '../../resources/js/csrf.ts';
test('JSON requests use the rotated cookie after an Inertia sign-in',()=>{
  assert.deepEqual(csrfHeaders('other=abc; XSRF-TOKEN=new%3Dtoken','stale-guest-token'),{'X-XSRF-TOKEN':'new=token'});
  assert.deepEqual(csrfHeaders('','meta-token'),{'X-CSRF-TOKEN':'meta-token'});
});
