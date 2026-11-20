import { useEffect, useRef, useState, type FormEvent } from 'react';
import FieldError from './FieldError';
import { fieldErrorsOf, focusFirstInvalid, invalidProps, type FieldErrors } from '../lib/form-errors';
import { incomeRequest, IncomeError, todayInMexico, type Charge } from '../lib/income-api';

export default function PaymentChargeForm({ paymentId, onSaved }: { paymentId: string; onSaved: (charge: Charge) => void }) {
  const key = useRef<string | undefined>(undefined);
  const request = useRef<AbortController | undefined>(undefined);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string>();
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  useEffect(() => () => request.current?.abort(), []);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (request.current) return;
    const form = event.currentTarget;
    const fields = Object.fromEntries(new FormData(form));
    const controller = new AbortController();
    request.current = controller;
    key.current ??= crypto.randomUUID();
    setPending(true);
    setError(undefined);
    setFieldErrors({});
    try {
      const charge = await incomeRequest<Charge>(`/api/payments/${encodeURIComponent(paymentId)}/charges`, {
        body: { ...fields, currency: 'MXN' }, key: key.current, signal: controller.signal,
      });
      if (!controller.signal.aborted) onSaved(charge);
    } catch (failure) {
      if (!controller.signal.aborted) {
        setError(failure instanceof IncomeError ? failure.message : 'Revisa tu conexión y reintenta el guardado.');
        setFieldErrors(fieldErrorsOf(failure));
        focusFirstInvalid(form, fieldErrorsOf(failure));
      }
    } finally {
      request.current = undefined;
      if (!controller.signal.aborted) setPending(false);
    }
  }

  return <details className="charge-form card page-card">
    <summary>Registrar cargo separado</summary>
    <form onSubmit={submit}>
      <label>Tipo de cargo<select name="chargeType"><option value="commission">Comisión</option><option value="tax">Impuesto</option></select></label>
      <label>Fecha del cargo<input name="paidOn" type="date" defaultValue={todayInMexico()} max={todayInMexico()} required {...invalidProps(fieldErrors, 'paidOn')} /><FieldError errors={fieldErrors} name="paidOn" /></label>
      <label>Importe del cargo (MXN)<input name="amount" inputMode="decimal" required {...invalidProps(fieldErrors, 'amount')} /><FieldError errors={fieldErrors} name="amount" /></label>
      {error && <p role="alert">{error}</p>}
      <button className="button primary" aria-busy={pending} disabled={pending}>Guardar cargo</button>
    </form>
  </details>;
}
