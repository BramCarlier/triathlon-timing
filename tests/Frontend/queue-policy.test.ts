import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mayReplay, retryDisposition } from '../../resources/js/queuePolicy.ts';
test('pending timings never replay under another account or an unknown legacy owner',()=>{
  assert.equal(mayReplay(7,7),true);assert.equal(mayReplay(7,8),false);assert.equal(mayReplay(undefined,7),false);
});
test('temporary failures retry; authorization and timing conflicts require review',()=>{
  for(const status of [408,429,500,502,503])assert.equal(retryDisposition(status),'retry');
  for(const status of [200,401,403,409,419,422])assert.equal(retryDisposition(status),'review');
});
