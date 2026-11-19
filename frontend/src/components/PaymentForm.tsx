import { useEffect, useRef, useState, type FormEvent } from 'react';
import { amountLabel, IncomeError, incomeRequest, resourceQuery, todayInMexico, type Payment, type PaymentCurrency, type PaymentEquivalents, type Project } from '../lib/income-api';

import ProjectPaymentSummary, { useProjectPaymentSummary } from './ProjectPaymentSummary';

type Preview = { equivalents: PaymentEquivalents; receivedAmount: string; excessAmount: string | null; requiresExcessReason: boolean };

export default function PaymentForm({ project, active }: { project: Project; active: boolean }) {
  const [amount, setAmount] = useState('');
  const [fee, setFee] = useState('');
  const [tax, setTax] = useState('');
  const [currency, setCurrency] = useState<PaymentCurrency>('MXN');
  const [exchangeRate, setExchangeRate] = useState('');
  const [source, setSource] = useState('direct_client');
  const [preview, setPreview] = useState<Preview>();
  const [error, setError] = useState<IncomeError>();
  const [pending, setPending] = useState(false);
  const [saved, setSaved] = useState<Payment>();
  const form = useRef<HTMLFormElement>(null);
  const inFlight = useRef<AbortController | undefined>(undefined);
  const [retrying, setRetrying] = useState(false);
  const key = useRef<string>(undefined);
  const attempt = useRef<Record<string, unknown>>(undefined);
  const summary = useProjectPaymentSummary(active ? project.id : undefined, project);
  const money = { currency, exchangeRate: currency === 'USD' ? exchangeRate : null, projectId: project.id, clientPaidAmount: amount, platformFee: fee || '0', withheldTaxAmount: tax || '0' };

  useEffect(() => () => inFlight.current?.abort(), []);
  useEffect(() => {
    setPreview(undefined);
    if (!amount || saved || (currency === 'USD' && !exchangeRate)) return;
    const controller = new AbortController();
    const timer = setTimeout(() => {
      void incomeRequest<Preview>('/api/payments/preview', {
        body: money,
        signal: controller.signal, passive: true,
      }).then(result => { if (!controller.signal.aborted) setPreview(result); }).catch(() => {});
    }, 250);
    return () => { clearTimeout(timer); controller.abort(); };
  }, [project.id, amount, fee, tax, currency, exchangeRate, saved]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (inFlight.current) return;
    const fields = Object.fromEntries(new FormData(event.currentTarget));
    const controller = new AbortController();
    inFlight.current = controller;
    key.current ??= crypto.randomUUID();
    attempt.current ??= { ...fields, ...money };
    setPending(true);
    setError(undefined);
    try {
      const result = await incomeRequest<Payment>('/api/payments', {
        body: attempt.current, key: key.current, signal: controller.signal,
      });
      if (!controller.signal.aborted) {
        setSaved(result);
        summary.refresh();
      }
    } catch (failure) {
      if (controller.signal.aborted) return;
      const result = failure instanceof IncomeError ? failure : new IncomeError(0, { error: { message: 'Se perdió la conexión. Reintenta el mismo guardado.' } });
      const uncertain = result.status === 0 || result.status >= 500;
      setRetrying(uncertain);
      if (uncertain) summary.refresh();
      else attempt.current = undefined;
      setError(result);
      const field = Object.keys(result.result.error?.fields ?? {})[0];
      if (field) requestAnimationFrame(() => (form.current?.elements.namedItem(field) as HTMLElement | null)?.focus());
    } finally {
      inFlight.current = undefined;
      if (!controller.signal.aborted) setPending(false);
    }
  }

  const excess = error?.result.excessAmount ?? (preview?.requiresExcessReason ? preview.excessAmount : null);
  if (saved) return <>
    <h1>Cobro guardado</h1>
    <p role="status">{saved.excessAmount && saved.excessAmount !== '0.00'
      ? `Excedente al guardar: ${amountLabel(saved.excessAmount)}.` : 'Tu cobro quedó registrado.'}</p>
    <ProjectPaymentSummary summary={summary} mode="saved" />
    <a className="button primary" href={`/payments/view?${resourceQuery('id', saved.id)}`}>Ver detalle del cobro</a>
    <a className="button" href={`/projects/view?${resourceQuery('id', project.id)}`}>Volver al proyecto</a>
  </>;

  return <>
    <header className="payment-entry-header">
      <h1>Registrar cobro</h1>
      <p>{project.name} · {project.client.name}</p>
    </header>
    <form ref={form} className="payment-entry-form" onSubmit={submit} noValidate>
      <fieldset className="payment-entry-source" disabled={pending || retrying}>
        <label>Fecha de pago<input name="paidOn" type="date" defaultValue={todayInMexico()} max={todayInMexico()} required /></label>
        <label>Fuente del cobro<select name="sourceType" value={source} onChange={event => setSource(event.target.value)}>
          <option value="direct_client">Cliente directo</option><option value="platform">Plataforma</option><option value="other">Otra</option>
        </select></label>
        {source !== 'direct_client' && <label>Nombre de la fuente<input name="sourceName" maxLength={100} required /></label>}
        <label>Moneda<select name="currency" value={currency} onChange={event => setCurrency(event.target.value as PaymentCurrency)}>
          <option value="MXN">MXN</option><option value="USD">USD</option>
        </select></label>
        {currency === 'USD' && <label>Tipo de cambio (MXN por USD)<input name="exchangeRate" inputMode="decimal" value={exchangeRate} onChange={event => setExchangeRate(event.target.value)} required /></label>}
      </fieldset>
      <ProjectPaymentSummary summary={summary} mode="create" />
      <fieldset className="payment-entry-amounts" disabled={pending || retrying}>
        <label>Importe ({currency})<input name="clientPaidAmount" placeholder="0.00" inputMode="decimal" value={amount} onChange={event => { setAmount(event.target.value); setError(undefined); }} required /></label>
        <label>Comisión{currency === 'USD' && ' (USD)'}<input name="platformFee" inputMode="decimal" value={fee} onChange={event => setFee(event.target.value)} placeholder="0.00" /></label>
        <label>Impuestos{currency === 'USD' && ' (USD)'}<input name="withheldTaxAmount" inputMode="decimal" value={tax} onChange={event => setTax(event.target.value)} placeholder="0.00" /></label>
        {preview && <output>Dinero recibido al cobrar: {amountLabel(preview.receivedAmount, currency)}
          {currency === 'USD' && preview.equivalents && <><br />Importe abonado equivalente: {amountLabel(preview.equivalents.clientPaidAmount)}
            <br />Dinero recibido equivalente: {amountLabel(preview.equivalents.receivedAmount)}</>}
        </output>}
        <div hidden={!excess}>
          {excess && <p>El total supera el monto acordado en {amountLabel(excess)}.</p>}
          <label>Razón del excedente<textarea name="excessReason" maxLength={250} disabled={!excess} required={Boolean(excess)} /></label>
        </div>
      </fieldset>
      {error && <p role="alert">{error.message}</p>}
      <div className="payment-entry-actions">
        <a className="button" href={`/projects/view?${resourceQuery('id', project.id)}`}>Cancelar</a>
        <button className="button primary" disabled={pending}>Guardar cobro</button>
      </div>
    </form>
  </>;
}
