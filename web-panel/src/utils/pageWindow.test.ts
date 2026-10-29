import assert from 'node:assert/strict';
import test from 'node:test';

import { pageWindow, parsePageInput } from './pageWindow.ts';

test('up to seven pages are all shown', () => {
  assert.deepEqual(pageWindow(1, 1), [1]);
  assert.deepEqual(pageWindow(3, 7), [1, 2, 3, 4, 5, 6, 7]);
  assert.deepEqual(pageWindow(1, 0), [1], 'an empty list still has page 1');
});

test('the start, middle and end of a long list', () => {
  assert.deepEqual(pageWindow(1, 20), [1, 2, 3, 4, 'gap', 20]);
  assert.deepEqual(pageWindow(3, 20), [1, 2, 3, 4, 'gap', 20]);
  assert.deepEqual(pageWindow(10, 20), [1, 'gap', 9, 10, 11, 'gap', 20]);
  assert.deepEqual(pageWindow(18, 20), [1, 'gap', 17, 18, 19, 20]);
  assert.deepEqual(pageWindow(20, 20), [1, 'gap', 17, 18, 19, 20]);
});

test('a gap never hides just one page', () => {
  assert.deepEqual(pageWindow(5, 9), [1, 'gap', 4, 5, 6, 'gap', 9]);
  assert.deepEqual(pageWindow(4, 9), [1, 2, 3, 4, 5, 'gap', 9]);
  assert.deepEqual(pageWindow(6, 9), [1, 'gap', 5, 6, 7, 8, 9]);
});

test('every window is sorted, unique and contains the current page', () => {
  for (let last = 1; last <= 40; last += 1) {
    for (let current = 1; current <= last; current += 1) {
      const pages = pageWindow(current, last).filter((item): item is number => item !== 'gap');
      assert.deepEqual(pages, [...new Set(pages)].sort((a, b) => a - b));
      assert.ok(pages.includes(current) && pages.includes(1) && pages.includes(last), `${current}/${last}`);
    }
  }
});

test('an out-of-range current page is clamped', () => {
  assert.deepEqual(pageWindow(99, 20), pageWindow(20, 20));
  assert.deepEqual(pageWindow(-4, 20), pageWindow(1, 20));
});

test('go-to-page accepts only a whole number inside the range', () => {
  assert.equal(parsePageInput('7', 20), 7);
  assert.equal(parsePageInput(' 20 ', 20), 20);
  assert.equal(parsePageInput('21', 20), null);
  assert.equal(parsePageInput('0', 20), null);
  assert.equal(parsePageInput('', 20), null);
  assert.equal(parsePageInput('2.5', 20), null);
  assert.equal(parsePageInput('-3', 20), null);
  assert.equal(parsePageInput('abc', 20), null);
});
