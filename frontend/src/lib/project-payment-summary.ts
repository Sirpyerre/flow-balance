export function paymentProgress(paymentCount: number, agreed: number | null, newPayment = false) {
  const count = paymentCount + Number(newPayment);
  return {
    count,
    agreed,
    remaining: agreed === null ? null : Math.max(0, agreed - count),
    additional: agreed === null ? null : Math.max(0, count - agreed),
  };
}
