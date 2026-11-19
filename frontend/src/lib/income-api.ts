import { csrfToken } from './auth-api';

export class IncomeError extends Error {
  constructor(public status: number, public result: { error?: { message?: string; fields?: Record<string, string> }; excessAmount?: string }) {
    super(result.error?.message ?? 'No se pudo completar la operación. Intenta de nuevo.');
  }
}

export async function incomeRequest<T>(
  path: string,
  options: { body?: Record<string, unknown>; key?: string; signal?: AbortSignal; passive?: boolean; method?: 'PATCH' | 'DELETE'; version?: string } = {},
): Promise<T> {
  const { body, key, signal, passive, method, version } = options;
  const headers = new Headers();
  if (passive) headers.set('X-Session-Activity', 'passive');
  if (body) headers.set('Content-Type', 'application/json');
  if (version) headers.set('If-Match', `"${version}"`);
  if (key) headers.set('Idempotency-Key', key);
  async function send() {
    if (body) headers.set('X-CSRF-Token', await csrfToken(signal));
    return fetch(path, {
      method: method ?? (body ? 'POST' : 'GET'), headers, signal, cache: 'no-store',
      body: body ? JSON.stringify(body) : undefined,
    });
  }
  const response = body && navigator.locks
    ? await navigator.locks.request('flow-balance-income-write', { signal }, send)
    : await send();
  if (response.status === 401) location.replace('/login');
  const data = response.status === 204 ? undefined : await response.json();
  if (!response.ok) throw new IncomeError(response.status, data);
  dispatchEvent(new CustomEvent('income-session-response', { detail: response }));
  return data as T;
}

export const amountLabel = (amount: string, currency = 'MXN') => `${currency} ${amount}`;
export const resourceQuery = (key: string, id: string) => new URLSearchParams({ [key]: id }).toString();

export type Client = { id: string; name: string };
export type PaymentCurrency = 'MXN' | 'USD';
export type PaymentEquivalents = { clientPaidAmount: string; platformFee: string; withheldTaxAmount: string; receivedAmount: string };
export type PaymentSummary = {
  currency?: string; conversionPending?: boolean; clientPaidAmountMxn?: string | null;
  id: string; paidOn: string; clientPaidAmount: string; sourceType: string | null; sourceName: string | null;
};
export const collectionStatusLabel = {
  outstanding: 'Pendiente',
  settled: 'Liquidado',
  overpaid: 'Con excedente',
  undetermined: 'Saldo por determinar',
};

export type CollectionBalance = {
  outstandingBalance: string | null;
  isBalanceProvisional?: boolean; unconvertedPaymentCount?: number;
  excessAmount: string | null;
  collectionStatus: keyof typeof collectionStatusLabel;
};

export type ProjectBalanceRow = CollectionBalance & {
  projectId: string; name: string; currency: 'MXN'; agreedAmount: string | null; clientPaidTotal: string;
};

export type ProjectPaymentData = Pick<CollectionBalance, 'outstandingBalance' | 'isBalanceProvisional' | 'unconvertedPaymentCount'> & {
  agreedPaymentCount: number | null; paymentCount: number;
};

export type DeliveryStatus = 'in_progress' | 'delivered';

export type Project = CollectionBalance & ProjectPaymentData & {
  id: string; name: string; client: Client; agreedAmount: string | null; acquisitionSource: string | null;
  canChangeClient?: boolean; deliveryStatus: DeliveryStatus | null; clientPaidTotal: string; payments: PaymentSummary[];
};
export type Charge = { id: string; chargeType: string; paidOn: string; amount: string };
export type Payment = PaymentSummary & {
  version: string; project: Pick<Project, 'id' | 'name'>; client: Client; platformFee: string; withheldTaxAmount: string; receivedAmount: string;
  exchangeRate?: string | null; equivalents?: PaymentEquivalents | null;
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

export type PaymentHistory = {
  payments: DashboardPayment[]; nextCursor: string | null; recentMonths: string[];
  summary: { paymentCount: number; latestPaidOn: string | null; totalsByCurrency: Record<string, string> };
};

export type MonthlyReport = {
  clientPaidTotal: string; platformFeeTotal: string; taxTotal: string; netReceivedTotal: string;
  unconvertedPaymentCount: number; payments: DashboardPayment[];
  movements: {
    paymentId: string; paidOn: string; kind: 'payment' | 'commission' | 'tax';
    currency: string; originalPaidAmount: string; clientPaidAmount: string | null; netAmount: string | null;
  }[];
};

export type DashboardPayment = Pick<PaymentSummary, 'id' | 'paidOn' | 'clientPaidAmount'> & { currency: string };
