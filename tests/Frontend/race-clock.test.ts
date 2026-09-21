import assert from 'node:assert/strict';
import { test } from 'node:test';
import { parseRaceTimestamp, raceElapsedMs } from '../../resources/js/raceTime.ts';

test('a new race starts at zero in Brussels and other browser timezones', () => {
  const previous = process.env.TZ;
  try {
    for (const timezone of ['Europe/Brussels', 'America/New_York', 'Asia/Tokyo', 'UTC']) {
      process.env.TZ = timezone;
      const now = Date.parse('2026-09-21T12:00:00.123Z');
      assert.equal(raceElapsedMs('2026-09-21T12:00:00.123Z', null, now), 0);
      assert.equal(raceElapsedMs('2026-09-21 12:00:00.123', null, now), 0);
      assert.equal(raceElapsedMs('2026-09-21T14:00:00.123+02:00', null, now + 1500), 1500);
    }
  } finally {
    if (previous === undefined) delete process.env.TZ;
    else process.env.TZ = previous;
  }
});

test('the elapsed clock freezes at finish, including after a reload days later', () => {
  const start = '2026-09-21T12:00:00.123Z';
  const finish = '2026-09-21T13:30:10.456Z';
  const finishedNow = parseRaceTimestamp(finish);
  assert.equal(raceElapsedMs(start, null, finishedNow), 5410333);
  assert.equal(raceElapsedMs(start, finish, finishedNow + 5000), 5410333);
  assert.equal(raceElapsedMs(start, finish, finishedNow + 7 * 86400000), 5410333);
});

test('missing and invalid starts do not produce a broken or negative clock', () => {
  assert.equal(raceElapsedMs(null, null, Date.now()), 0);
  assert.equal(raceElapsedMs('invalid', null, Date.now()), 0);
  assert.equal(raceElapsedMs('2026-09-21T12:00:00Z', null, Date.parse('2026-09-21T11:59:59Z')), 0);
});
