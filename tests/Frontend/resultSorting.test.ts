import { test } from 'node:test';
import assert from 'node:assert/strict';
import { sortResults, type SortableResult } from '../../resources/js/resultSorting.ts';

const rows: (SortableResult & {id:number})[] = [
  {id:1,place:1,bib_number:'10',name:'Zoë',total_ms:41239,gap_ms:0,latest_elapsed_ms:41239,splits:[{checkpoint_id:9,elapsed_ms:41239},{checkpoint_id:2,elapsed_ms:null}]},
  {id:2,place:2,bib_number:'002',name:'alice',total_ms:41240,gap_ms:1,latest_elapsed_ms:41240,splits:[{checkpoint_id:9,elapsed_ms:41240},{checkpoint_id:2,elapsed_ms:null}]},
  {id:3,place:null,bib_number:null,name:'Bob',total_ms:null,gap_ms:null,latest_elapsed_ms:null,splits:[{checkpoint_id:9,elapsed_ms:null},{checkpoint_id:2,elapsed_ms:null}]},
];
const ids = (sorted:typeof rows) => sorted.map(row=>row.id);

test('bib sorting is numeric, names are case-insensitive, and missing values stay last', () => {
  assert.deepEqual(ids(sortResults(rows,'bib_number','asc')), [2,1,3]);
  assert.deepEqual(ids(sortResults(rows,'bib_number','desc')), [1,2,3]);
  assert.deepEqual(ids(sortResults(rows,'name','asc','nl-BE')), [2,3,1]);
  assert.deepEqual(ids(sortResults(rows,'place','desc')), [2,1,3]);
});

test('time sorting uses full precision, checkpoint identity, and puts missing times last in both directions', () => {
  for (const key of ['total_ms','gap_ms','checkpoint:9'] as const) {
    assert.deepEqual(ids(sortResults(rows,key,'asc')), [1,2,3]);
    assert.deepEqual(ids(sortResults(rows,key,'desc')), [2,1,3]);
  }
  assert.deepEqual(ids(sortResults(rows,'checkpoint:2','desc')), [1,2,3]);
  const zero = {...rows[2],total_ms:0};
  assert.deepEqual(ids(sortResults([...rows.slice(0,2),zero],'total_ms','asc')), [3,1,2]);
});

test('sorting does not mutate official ranks or input, ties keep race order, and refreshed times are re-sorted', () => {
  const before = structuredClone(rows);
  sortResults(rows,'name','asc');
  assert.deepEqual(rows, before);
  assert.deepEqual(ids(sortResults([rows[0],{...rows[1],place:1},rows[2]],'place','desc')), [1,2,3]);
  const refreshed = rows.map(row=>row.id===2?{...row,total_ms:40000}:row);
  assert.deepEqual(ids(sortResults(refreshed,'total_ms','asc')), [2,1,3]);
  assert.deepEqual(refreshed.map(row=>row.place), [1,2,null]);
});

test('latest checkpoint sorts by course progress then full elapsed time', () => {
  const further = {...rows[2],latest_elapsed_ms:50000,splits:[{checkpoint_id:9,elapsed_ms:null},{checkpoint_id:2,elapsed_ms:50000}]};
  assert.deepEqual(ids(sortResults([...rows.slice(0,2),further],'latest_checkpoint','asc')), [1,2,3]);
  assert.deepEqual(ids(sortResults([...rows.slice(0,2),further],'latest_checkpoint','desc')), [3,2,1]);
});
