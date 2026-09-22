import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatDuration } from '../../resources/js/duration.ts';
test('hundredths display close finishes and splits without changing stored precision',()=>{
  assert.equal(formatDuration(41230,2),'00:00:41.23');
  assert.equal(formatDuration(41240,2),'00:00:41.24');
  assert.equal(formatDuration(41239,3),'00:00:41.239');
  assert.equal(formatDuration(41239,2),'00:00:41.23');
  assert.equal(formatDuration(59999,2),'00:00:59.99');
  assert.equal(formatDuration(60000,2),'00:01:00.00');
  assert.equal(formatDuration(3600001,true),'01:00:00.001');
  assert.equal(formatDuration(0,2),'00:00:00.00');
  assert.equal(formatDuration(null,2),'—');
  assert.equal(formatDuration(Infinity,2),'—');
});
