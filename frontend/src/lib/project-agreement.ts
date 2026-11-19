export function estimatedPayment(amount: string, count: string): string | null {
  if (!/^\d{1,14}(?:\.\d{1,2})?$/.test(amount) || !/^\d{1,16}$/.test(count)) return null;
  const [whole, fraction = ''] = amount.split('.');
  const cents = BigInt(whole + fraction.padEnd(2, '0'));
  const payments = BigInt(count);
  if (cents === 0n || payments === 0n || payments > BigInt(Number.MAX_SAFE_INTEGER)) return null;
  const average = (cents * 2n + payments) / (payments * 2n);
  return `${average / 100n}.${(average % 100n).toString().padStart(2, '0')}`;
}
