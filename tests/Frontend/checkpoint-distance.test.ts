import { test } from 'node:test';
import assert from 'node:assert/strict';
import { checkpointTotalKm, checkpointDistanceText } from '../../resources/js/checkpointDistance.ts';
const race={settings:{swim_km:1,bike_km:35,run_km:8}};
test('cumulative distances include preceding sports and retain zero at transition exits',()=>{
  const points=[['swim','1'],['bike','0'],['bike','35'],['run','0'],['run','8']] as const;
  assert.deepEqual(points.map(([discipline,distance_km])=>checkpointTotalKm(race,{discipline,distance_km,kind:'transition'})),[1,1,36,36,44]);
  assert.equal(checkpointDistanceText(race,{discipline:'bike',distance_km:'0',kind:'transition'}),'Bike: 0 km · 1 km total');
  assert.equal(checkpointDistanceText(race,{discipline:'run',distance_km:'8',kind:'finish'}),'Run: 8 km · 44 km total');
  assert.equal(checkpointTotalKm(race,{discipline:'run',distance_km:'4',kind:'split'}),40);
});
test('unknown course distances are not silently treated as zero',()=>{
  assert.equal(checkpointTotalKm({}, {discipline:'run',distance_km:'8',kind:'finish'}),null);
  assert.equal(checkpointTotalKm(race,{discipline:'bike',distance_km:null,kind:'split'}),null);
  assert.equal(checkpointTotalKm({settings:{swim_km:0.75,bike_km:20.5}},{discipline:'run',distance_km:'5',kind:'finish'}),26.25);
});
