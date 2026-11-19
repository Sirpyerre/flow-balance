import { expect, test } from '@playwright/test';
import { paymentProgress } from '../src/lib/project-payment-summary';

test('derives current and projected payment progress', () => {
  for (const [count, agreed, remaining, additional] of [
    [0, 4, 4, 0], [1, 4, 3, 0], [2, 4, 2, 0], [3, 4, 1, 0],
    [4, 4, 0, 0], [5, 4, 0, 1], [2, null, null, null],
  ] as const) {
    expect(paymentProgress(count, agreed)).toEqual({ count, agreed, remaining, additional });
  }
  expect(paymentProgress(2, 4, true)).toEqual({ count: 3, agreed: 4, remaining: 1, additional: 0 });
  expect(paymentProgress(4, 4, true)).toEqual({ count: 5, agreed: 4, remaining: 0, additional: 1 });
  expect(paymentProgress(2, null, true)).toEqual({ count: 3, agreed: null, remaining: null, additional: null });
});
