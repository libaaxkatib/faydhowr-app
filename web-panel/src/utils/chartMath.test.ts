import test from 'node:test';
import assert from 'node:assert/strict';

import { barWidth, chartFill, chartStroke, chartSummary, donutSlices, drawable, maxOf, moneyNumber, percentOf, titleCase } from './chartMath.ts';

test('the longest bar is full and the others are in proportion; a value above zero is always visible', () => {
  assert.equal(barWidth(50, 50), 100);
  assert.equal(barWidth(25, 50), 50);
  assert.equal(barWidth(1, 1000), 3, 'tiny but not zero stays visible');
  assert.equal(barWidth(0, 50), 0, 'zero draws nothing');
  assert.equal(barWidth(10, 0), 0, 'nothing to compare with');
});

test('values that cannot be drawn count as zero instead of breaking the chart', () => {
  for (const bad of [-5, Number.NaN, Number.POSITIVE_INFINITY, null, undefined]) assert.equal(drawable(bad as number), 0);
  assert.equal(barWidth(-3, 10), 0);
  assert.equal(maxOf([3, -1, Number.NaN, 9, 4]), 9);
  assert.equal(maxOf([]), 0);
});

test('percentages are whole numbers of the total and 0 when there is no total', () => {
  assert.equal(percentOf(1, 3), 33);
  assert.equal(percentOf(2, 3), 67);
  assert.equal(percentOf(5, 0), 0);
  assert.equal(percentOf(0, 10), 0);
});

test('donut slices follow each other round the circle and add up to the whole circle', () => {
  const radius = 40;
  const circumference = 2 * Math.PI * radius;
  const slices = donutSlices([30, 10, 0, 60], radius);

  assert.equal(slices.length, 4);
  assert.ok(Math.abs(slices.reduce((sum, slice) => sum + slice.length, 0) - circumference) < 1e-9);
  assert.equal(slices[0].offset, -0);
  assert.ok(Math.abs(slices[1].offset + slices[0].length) < 1e-9, 'the second slice starts where the first ends');
  assert.equal(slices[2].length, 0, 'a zero value has no slice');
  assert.ok(Math.abs(slices[3].offset + slices[0].length + slices[1].length) < 1e-9, 'a zero slice does not move the next one');
  assert.deepEqual(donutSlices([0, 0], radius).map((slice) => slice.length), [0, 0], 'nothing to share draws nothing');
});

test('the seven chart colours repeat after the seventh, and a bar and a ring slice at the same position share a colour', () => {
  assert.equal(chartFill(0), 'bg-chart-1');
  assert.equal(chartFill(6), 'bg-chart-7');
  assert.equal(chartFill(7), 'bg-chart-1');
  assert.equal(chartFill(-4), 'bg-chart-1');
  for (let i = 0; i < 9; i += 1) assert.equal(chartStroke(i).replace('stroke-', ''), chartFill(i).replace('bg-', ''));
});

test('money printed by the server becomes a bar length without changing the text that is shown', () => {
  assert.equal(moneyNumber('1,234.50'), 1234.5);
  assert.equal(moneyNumber('$1,234.50'), 1234.5);
  assert.equal(moneyNumber('12.00 USD'), 12);
  assert.equal(moneyNumber('4261.29'), 4261.29);
  assert.equal(moneyNumber('1.234,50'), 1234.5);
  assert.equal(moneyNumber(''), 0);
  assert.equal(moneyNumber(null), 0);
  assert.equal(moneyNumber('n/a'), 0);
  assert.equal(moneyNumber(4261.29 as unknown as string), 4261.29, 'an amount that arrives as a number');
  assert.equal(moneyNumber(0), 0);
  assert.equal(moneyNumber(Number.NaN), 0);
  assert.equal(moneyNumber({} as unknown as string), 0, 'anything else draws nothing instead of crashing');
});

test('the screen-reader summary names every item with its value and share', () => {
  assert.equal(chartSummary('People by status', [{ label: 'Active', value: 3 }, { label: 'Waiting', value: 1 }]), 'People by status: Active 3 (75%), Waiting 1 (25%)');
  assert.equal(chartSummary('Empty', []), 'Empty: ');
});

test('a stored value without a translation is written as plain, capitalised words', () => {
  assert.equal(titleCase('contract_ended'), 'Contract ended');
  assert.equal(titleCase('pending'), 'Pending');
  assert.equal(titleCase('  on_leave '), 'On leave');
  assert.equal(titleCase(''), '');
});
