import assert from 'node:assert/strict';
import { test } from 'node:test';
import { bestSample, sampleOffset } from '../../resources/js/timing/clockSync.ts';

test('the midpoint of a round trip maps the device clock onto the server clock', () => {
  // Device clock is 6 s fast; request took 200 ms each way.
  const sample = sampleOffset(1_000_000, 1_000_400, 994_200);
  assert.equal(sample.rttMs, 400);
  assert.equal(sample.offsetMs, -6000);
});

test('the fastest recent sample wins and stale samples are ignored when fresher ones exist', () => {
  const now = 2_000_000;
  const samples = [
    { offsetMs: -6000, rttMs: 900, takenAt: now - 1000 },
    { offsetMs: -5990, rttMs: 80, takenAt: now - 2000 },
    { offsetMs: 100, rttMs: 10, takenAt: now - 20 * 60_000 },
  ];
  assert.equal(bestSample(samples, now)?.offsetMs, -5990);
  assert.equal(bestSample([], now), null);
  assert.equal(bestSample([samples[2]], now)?.offsetMs, 100, 'an old sample is still better than none');
});
