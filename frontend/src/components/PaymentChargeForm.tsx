import { useEffect, useRef, useState, type FormEvent } from 'react';
import { incomeRequest, IncomeError, todayInMexico, type Charge } from '../lib/income-api';

export default function PaymentChargeForm({ paymentId, onSaved }: { paymentId: string; onSaved: (charge: Charge) => void }) {
  const key = useRef<string | undefined>(undefined);
  const request = useRef<AbortController | undefined>(undefined);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string>();
  useEffect(() => () => request.current?.abort(), []);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (request.current) return;
    const fields = Object.fromEntries(new FormData(event.currentTarget));
    const controller = new AbortController();
    request.current = controller;
    key.current ??= crypto.randomUUID();
    setPending(true);
    setError(undefined);
    try {
      const charge = await incomeRequest<Charge>(`/api/payments/${encodeURIComponent(paymentId)}/charges`, {
        body: { ...fields, currency: 'MXN' }, key: key.current, signal: controller.signal,
      });
      if (!controller.signal.aborted) onSaved(charge);
    } catch (failure) {
      if (!controller.signal.aborted) setError(failure instanceof IncomeError ? failure.message : 'Revisa tu conexión y reintenta el guardado.');
    } finally {
      request.current = undefined;
      if (!controller.signal.aborted) setPending(false);
    }
  }

  return <details className="income-charge-form income-panel">
    <summary>Registrar cargo separado</summary>
    <form onSubmit={submit}>
      <label>Tipo de cargo<select name="chargeType"><option value="commission">Comisión</option><option value="tax">Impuesto</option></select></label>
      <label>Fecha del cargo<input name="paidOn" type="date" defaultValue={todayInMexico()} max={todayInMexico()} required /></label>
      <label>Importe del cargo (MXN)<input name="amount" inputMode="decimal" required /></label>
      {error && <p role="alert">{error}</p>}
      <button className="button primary" aria-busy={pending} disabled={pending}>Guardar cargo</button>
    </form>
  </details>;
}
