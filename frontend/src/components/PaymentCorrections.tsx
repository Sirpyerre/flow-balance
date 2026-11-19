import { useEffect, useRef, useState, type FormEvent } from 'react';
import { amountLabel, incomeRequest, IncomeError, resourceQuery, todayInMexico, type Payment } from '../lib/income-api';

import ProjectPaymentSummary, { useProjectPaymentSummary } from './ProjectPaymentSummary';

export default function PaymentCorrections({ payment, active, onEdited, onRefresh }: {
  payment: Payment; active: boolean; onEdited: (payment: Payment) => void; onRefresh: () => void;
}) {
  const [mode, setMode] = useState<'edit' | 'delete' | 'saved'>();
  const [selectedPayment, setSelectedPayment] = useState<Payment>();
  const summary = useProjectPaymentSummary(active && (mode === 'edit' || mode === 'saved') ? payment.project.id : undefined);
  const draftPayment = selectedPayment ?? payment;
  const originalCurrency = draftPayment.currency ?? 'MXN';
  const [currency, setCurrency] = useState(payment.currency ?? 'MXN');
  const currencyChanged = currency !== originalCurrency;
  const [source, setSource] = useState(payment.sourceType ?? '');
  const [pending, setPending] = useState(false);
  const [error, setError] = useState('');
  const [conflict, setConflict] = useState(false);
  const [reasonRequired, setReasonRequired] = useState(false);
  const [retrying, setRetrying] = useState(false);
  const request = useRef<AbortController>(undefined);
  const attempt = useRef<{ key: string; body: Record<string, unknown> }>(undefined);
  const dialog = useRef<HTMLDialogElement>(null);
  const deleteButton = useRef<HTMLButtonElement>(null);
  const editButton = useRef<HTMLButtonElement>(null);
  useEffect(() => () => request.current?.abort(), []);
  useEffect(() => {
    if (active && mode === 'delete') dialog.current?.showModal();
  }, [active, mode]);

  function close() {
    if (pending) return;
    dialog.current?.close();
    setMode(undefined);
    setSelectedPayment(undefined);
    setError('');
    setConflict(false);
    setReasonRequired(false);
    setRetrying(false);
    attempt.current = undefined;
    (mode === 'delete' ? deleteButton : editButton).current?.focus();
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (request.current || conflict) return;
    const draft = Object.fromEntries(new FormData(event.currentTarget));
    const controller = new AbortController();
    request.current = controller;
    setPending(true);
    setError('');
    try {
      const path = `/api/payments/${encodeURIComponent(payment.id)}`;
      if (mode === 'delete') {
        await incomeRequest<void>(path, { body: { reason: draft.reason }, method: 'DELETE',
          version: draftPayment.version, signal: controller.signal });
        if (!controller.signal.aborted) location.assign(`/projects/view?${resourceQuery('id', payment.project.id)}`);
      } else {
        const savedAttempt = attempt.current ?? { key: crypto.randomUUID(), body: {
          ...draft, sourceType: source || null, currency,
          exchangeRate: currency === 'USD' ? draft.exchangeRate : null,
          confirmCurrencyChange: currencyChanged && draft.confirmCurrencyChange === 'on',
        } };
        attempt.current = savedAttempt;
        const corrected = await incomeRequest<Payment>(path, { ...savedAttempt, method: 'PATCH',
          version: draftPayment.version, signal: controller.signal });
        if (!controller.signal.aborted) {
          setMode('saved');
          setSelectedPayment(undefined);
          attempt.current = undefined;
          setReasonRequired(false);
          setRetrying(false);
          summary.refresh();
          onEdited(corrected);
          requestAnimationFrame(() => editButton.current?.focus());
        }
      }
    } catch (failure) {
      if (controller.signal.aborted) return;
      if (failure instanceof IncomeError) {
        if ([404, 409, 412].includes(failure.status)) {
          setConflict(true);
          if (mode === 'edit') summary.refresh();
        } else if (failure.status >= 500) {
          setRetrying(mode === 'edit');
        } else {
          attempt.current = undefined;
          setRetrying(false);
          setReasonRequired(Boolean(failure.result.error?.fields?.excessReason));
        }
        setError(failure.message);
      } else {
        setRetrying(mode === 'edit');
        setError('No se pudo confirmar el cambio. Intenta de nuevo.');
      }
    } finally {
      request.current = undefined;
      if (!controller.signal.aborted) setPending(false);
    }
  }

  const feedback = <>
    {error && <p role="alert">{error}</p>}
    {conflict && <button type="button" onClick={onRefresh}>Revisar cobro vigente</button>}
  </>;
  return <section aria-label="Corregir cobro" className="payment-corrections">
    <div className="correction-actions">
      <button ref={editButton} type="button" onClick={() => {
        setSelectedPayment(payment);
        setSource(payment.sourceType ?? '');
        setCurrency(payment.currency ?? 'MXN');
        summary.refresh();
        setMode('edit');
      }} hidden={mode === 'edit' || mode === 'delete'}>Editar cobro</button>
      <button ref={deleteButton} type="button" onClick={() => {
        setSelectedPayment(payment);
        setMode('delete');
      }} hidden={mode === 'edit'}>Eliminar cobro</button>
    </div>
    {mode === 'saved' && <ProjectPaymentSummary summary={summary} mode="saved" />}
    {mode === 'edit' && <form onSubmit={submit} aria-label="Editar cobro">
      <ProjectPaymentSummary summary={summary} mode="edit" />
      <fieldset disabled={pending || retrying || conflict}>
        <label>Moneda<select name="currency" value={currency} onChange={event => setCurrency(event.target.value)}>
          {originalCurrency !== 'MXN' && originalCurrency !== 'USD' && <option value={originalCurrency}>{originalCurrency}</option>}
          <option value="MXN">MXN</option><option value="USD">USD</option>
        </select></label>
        {currency === 'USD' && <label>Tipo de cambio (MXN por USD)<input name="exchangeRate" inputMode="decimal"
          defaultValue={currencyChanged ? '' : draftPayment.exchangeRate ?? ''} required /></label>}
        {currencyChanged && <label className="currency-confirmation" key={currency}><input type="checkbox" name="confirmCurrencyChange" required /> Confirmo los importes en {currency}</label>}
        <label>Importe abonado ({currency})<input autoFocus name="clientPaidAmount" inputMode="decimal" required defaultValue={draftPayment.clientPaidAmount.replace(/(\.\d{2})0+$/, '$1')} /></label>
        <label>Fecha de pago<input name="paidOn" type="date" defaultValue={draftPayment.paidOn} max={todayInMexico()} required /></label>
        <label>Fuente del cobro<select name="sourceType" value={source} onChange={event => setSource(event.target.value)}>
          {!draftPayment.sourceType && <option value="">Sin registrar</option>}
          <option value="direct_client">Cliente directo</option><option value="platform">Plataforma</option><option value="other">Otra</option>
        </select></label>
        {source !== '' && source !== 'direct_client' && <label>Nombre de la fuente<input name="sourceName" defaultValue={draftPayment.sourceName ?? ''} required maxLength={100} /></label>}
        <label>Comisión de plataforma ({currency})<input name="platformFee" inputMode="decimal" defaultValue={draftPayment.platformFee} required /></label>
        <label>Impuestos descontados ({currency})<input name="withheldTaxAmount" inputMode="decimal" defaultValue={draftPayment.withheldTaxAmount} required /></label>
        <label>Razón del excedente<input name="excessReason" maxLength={250} required={reasonRequired} /></label>
      </fieldset>
      {feedback}
      <div className="correction-actions">
        <button type="submit" className="button primary" disabled={pending || conflict || !['MXN', 'USD'].includes(currency)}>{retrying ? 'Reintentar guardado' : 'Guardar cambios'}</button>
        <button type="button" onClick={close} disabled={pending}>Cancelar</button>
      </div>
    </form>}
    {active && mode === 'delete' && <dialog ref={dialog} aria-labelledby="delete-payment-title" onCancel={event => { event.preventDefault(); close(); }}>
      <h2 id="delete-payment-title">Eliminar cobro</h2>
      <p>{amountLabel(draftPayment.clientPaidAmount.replace(/(\.\d{2})0+$/, '$1'), originalCurrency)} · {draftPayment.paidOn}</p>
      {draftPayment.charges.length > 0 && <>
        <p>Estos cargos separados también dejarán de aparecer.</p>
        <ul>{draftPayment.charges.map(charge => <li key={charge.id}>
          {charge.chargeType === 'commission' ? 'Comisión' : 'Impuesto'} · {charge.paidOn} · {amountLabel(charge.amount)}
        </li>)}</ul>
      </>}
      <form onSubmit={submit}>
        <label>Motivo (opcional)<textarea name="reason" maxLength={250} disabled={pending || conflict} /></label>
        {feedback}
        <div className="correction-actions">
          <button autoFocus type="button" onClick={close} disabled={pending}>Cancelar</button>
          <button type="submit" className="button primary" disabled={pending || conflict}>Confirmar eliminación</button>
        </div>
      </form>
    </dialog>}
  </section>;
}
