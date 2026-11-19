import { csrfToken } from './auth-api';

export class IncomeError extends Error {
  constructor(public status: number, public result: { error?: { message?: string; fields?: Record<string, string> }; excessAmount?: string }) {
    super(result.error?.message ?? 'No se pudo completar la operación. Intenta de nuevo.');
  }
}

export async function incomeRequest<T>(
  path: string,
  options: { body?: Record<string, unknown>; key?: string; signal?: AbortSignal; passive?: boolean } = {},
): Promise<T> {
  const { body, key, signal, passive } = options;
  const headers = new Headers();
  if (passive) headers.set('X-Session-Activity', 'passive');
  if (body) headers.set('Content-Type', 'application/json');
  if (key) headers.set('Idempotency-Key', key);
  async function send() {
    if (body) headers.set('X-CSRF-Token', await csrfToken(signal));
    return fetch(path, {
      method: body ? 'POST' : 'GET', headers, signal, cache: 'no-store',
      body: body ? JSON.stringify(body) : undefined,
    });
  }
  const response = body && navigator.locks
    ? await navigator.locks.request('flow-balance-income-write', { signal }, send)
    : await send();
  if (response.status === 401) location.replace('/login');
  const data = await response.json();
  if (!response.ok) throw new IncomeError(response.status, data);
  dispatchEvent(new CustomEvent('income-session-response', { detail: response }));
  return data as T;
}

export const amountLabel = (amount: string) => `MXN ${amount}`;
export const resourceQuery = (key: string, id: string) => new URLSearchParams({ [key]: id }).toString();

export type Client = { id: string; name: string };
export type PaymentSummary = {
  id: string; paidOn: string; clientPaidAmount: string; sourceType: string | null; sourceName: string | null;
};
export type Project = {
  id: string; name: string; client: Client; agreedAmount: string | null; acquisitionSource: string | null;
  agreedPaymentCount: number | null; paymentCount: number; clientPaidTotal: string; payments: PaymentSummary[];
};
export type Charge = { id: string; chargeType: string; paidOn: string; amount: string };
export type Payment = PaymentSummary & {
  project: Project; client: Client; platformFee: string; withheldTaxAmount: string; receivedAmount: string;
  excessReason: string | null; charges: Charge[]; excessAmount?: string | null;
};

export function sourceLabel(payment: PaymentSummary, clientName: string) {
  if (payment.sourceType === 'direct_client') return `Cliente directo · ${clientName}`;
  if (payment.sourceType === 'platform') return `Plataforma · ${payment.sourceName}`;
  return payment.sourceName ?? 'Sin registrar';
}

export function todayInMexico() {
  const date = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'America/Mexico_City', year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(new Date());
  return ['year', 'month', 'day'].map(type => date.find(part => part.type === type)?.value).join('-');
}
