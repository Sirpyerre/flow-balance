import { useEffect, useRef, useState, type FormEvent } from 'react';
import { IncomeError, amountLabel, collectionStatusLabel, incomeRequest, resourceQuery, type Client, type Project } from '../lib/income-api';
import { estimatedPayment } from '../lib/project-agreement';
import DiscardChangesDialog from './DiscardChangesDialog';

type AgreementPreview = { excessAmount: string; requiresAdjustmentReason: boolean; isBalanceProvisional: boolean };

export default function IncomeSetupForm({ mode, clients, selected, fromProject, project }: {
  mode: 'client' | 'project'; clients: Client[]; selected: string | null; fromProject: boolean; project?: Project;
}) {
  const isClient = mode === 'client';
  const [choices, setChoices] = useState(clients);
  const [clientId, setClientId] = useState(selected ?? project?.client.id ?? '');
  const [amount, setAmount] = useState(project?.agreedAmount ?? '');
  const [count, setCount] = useState(String(project?.agreedPaymentCount ?? ''));
  const [preview, setPreview] = useState<AgreementPreview>();
  const [excess, setExcess] = useState<string>();
  const [error, setError] = useState<string>();
  const [pending, setPending] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [clientError, setClientError] = useState('');
  const [clientSaving, setClientSaving] = useState(false);
  const addClient = useRef<HTMLDialogElement>(null);
  const projectPath = project ? `/api/projects/${encodeURIComponent(project.id)}` : '/api/projects';
  const cancelPath = project ? `/projects/view?${resourceQuery('id', project.id)}` : isClient ? '/clients' : '/projects';
  const estimate = estimatedPayment(amount, count);
  const paidPercentage = project?.agreedAmount
    ? Math.min(100, Math.round(Number(project.clientPaidTotal) / Number(project.agreedAmount) * 100))
    : 0;
  const projectedExcess = excess ?? (preview?.requiresAdjustmentReason ? preview.excessAmount : undefined);

  useEffect(() => {
    setPreview(undefined);
    setExcess(undefined);
    if (!project || !estimatedPayment(amount, '1') || amount === project.agreedAmount) return;
    const controller = new AbortController();
    const timer = setTimeout(() => {
      void incomeRequest<AgreementPreview>(projectPath + '/agreement-preview', {
        body: { agreedAmount: amount }, passive: true, signal: controller.signal,
      }).then(value => { if (!controller.signal.aborted) setPreview(value); }).catch(() => {});
    }, 200);
    return () => { clearTimeout(timer); controller.abort(); };
  }, [amount, project, projectPath]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (pending) return;
    const fields = Object.fromEntries(new FormData(event.currentTarget));
    setPending(true);
    setError(undefined);
    try {
      const body: Record<string, unknown> = isClient ? { name: fields.name } : { ...fields };
      if (!isClient) {
        if (fields.agreedPaymentCount) body.agreedPaymentCount = Number(fields.agreedPaymentCount);
        else delete body.agreedPaymentCount;
        if (project) {
          if (!fields.agreedAmount) delete body.agreedAmount;
          if (!fields.acquisitionSource) delete body.acquisitionSource;
        } else {
          body.agreedCurrency = 'MXN';
        }
      }
      const { id } = await incomeRequest<{ id: string }>(isClient ? '/api/clients' : projectPath, {
        body, method: project ? 'PATCH' : undefined,
      });
      location.assign(isClient
        ? fromProject ? `/projects/new?${resourceQuery('clientId', id)}` : '/dashboard'
        : `/projects/view?${resourceQuery('id', id)}`);
    } catch (failure) {
      setError(failure instanceof IncomeError ? failure.message : 'Revisa tu conexión e intenta de nuevo.');
      if (failure instanceof IncomeError && failure.result.error?.fields?.agreementAdjustmentReason) {
        setExcess(failure.result.excessAmount);
      }
      setPending(false);
    }
  }

  async function createClient(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (clientSaving) return;
    const form = event.currentTarget;
    setClientSaving(true);
    setClientError('');
    try {
      const client = await incomeRequest<Client>('/api/clients', { body: { name: new FormData(form).get('name') } });
      setChoices(current => [...current, client]);
      setClientId(client.id);
      setDirty(true);
      form.reset();
      addClient.current?.close();
    } catch (failure) {
      setClientError(failure instanceof IncomeError ? failure.message : 'Revisa tu conexión e intenta de nuevo.');
    } finally {
      setClientSaving(false);
    }
  }

  return <>
    <h1>{isClient ? 'Nuevo cliente' : project ? 'Editar proyecto' : 'Nuevo proyecto'}</h1>
    <div className="workspace-grid">
      <form className="income-panel" onSubmit={submit} onChange={() => setDirty(true)}>
        {!isClient && <>
          <h2>Datos del proyecto</h2>
          <div className="workspace-field-heading">
            <label htmlFor="project-client">Cliente</label>
            {!project && <button type="button" onClick={() => addClient.current?.showModal()}>Crear cliente</button>}
          </div>
          <select id="project-client" name="clientId" required value={clientId} disabled={project?.canChangeClient === false || Boolean(project?.paymentCount)} onChange={event => setClientId(event.target.value)}>
            <option value="" disabled>Selecciona un cliente</option>
            {choices.map(client => <option key={client.id} value={client.id}>{client.name}</option>)}
          </select>
        </>}
        <label>{isClient ? 'Nombre del cliente' : 'Nombre del proyecto'}<input name="name" maxLength={100} defaultValue={project?.name} required /></label>
        {isClient && <p className="workspace-note">Así aparecerá al registrar proyectos y cobros.</p>}
        {!isClient && <>
          <label>Origen del proyecto<input name="acquisitionSource" maxLength={100} defaultValue={project?.acquisitionSource ?? ''} required={!project} /></label>
          <h2>Acuerdo de pago</h2>
          <div className="workspace-fields">
            <label>Monto acordado (MXN)<input name="agreedAmount" inputMode="decimal" value={amount} onChange={event => setAmount(event.target.value)} required={!project} /></label>
            <label>Pagos acordados<input name="agreedPaymentCount" type="number" inputMode="numeric" min="1" step="1" value={count} onChange={event => setCount(event.target.value)} required={!project} /></label>
          </div>
          {projectedExcess && <label>Razón del ajuste
            <span>Excedente previsto: {amountLabel(projectedExcess)}</span>
            <textarea name="agreementAdjustmentReason" maxLength={250} required />
          </label>}
        </>}
        {error && <p role="alert">{error}</p>}
        <div className="workspace-actions">
          <DiscardChangesDialog dirty={dirty} href={cancelPath} />
          <button className="button primary" aria-busy={pending} disabled={pending}>{isClient ? 'Guardar cliente' : project ? 'Guardar cambios' : 'Guardar proyecto'}</button>
        </div>
      </form>
      <aside className="income-panel workspace-side">
        {isClient ? <><h2>Clientes registrados</h2>
          {choices.length ? <ul className="income-records">{choices.map(client => <li key={client.id}><a href={`/clients/view?${resourceQuery('id', client.id)}`}>{client.name}</a></li>)}</ul> : <p>Aún no tienes clientes.</p>}</>
          : project ? <><h2>Estado del proyecto</h2>
            <p>{collectionStatusLabel[project.collectionStatus]}</p>
            <progress aria-label="Avance de cobro" max={100} value={paidPercentage} />
            <p>{project.paymentCount}{project.agreedPaymentCount ? ` de ${project.agreedPaymentCount}` : ''} pagos{project.agreedAmount && ` · ${paidPercentage} % abonado`}</p>
            <dl><dt>Abonado</dt><dd>{amountLabel(project.clientPaidTotal)}</dd><dt>Saldo pendiente</dt><dd>{project.outstandingBalance === null ? 'Por determinar' : amountLabel(project.outstandingBalance)}</dd></dl>
            {(project.isBalanceProvisional || preview?.isBalanceProvisional) && <p>Saldo provisional. Hay cobros sin convertir.</p>}
          </> : <><h2>Vista previa del acuerdo</h2>
            <dl><dt>Monto por pago</dt><dd>{estimate ? amountLabel(estimate) : 'MXN —'}</dd><dt>Pagos</dt><dd>{count || '—'}</dd></dl>
            <p className="workspace-note">{estimate ? 'Monto orientativo por pago.' : 'Completa el monto y el número de pagos para ver el monto por pago.'}</p></>}
      </aside>
    </div>
    {!isClient && !project && <dialog className="discard-changes" ref={addClient} aria-label="Nuevo cliente">
      <h2>Nuevo cliente</h2>
      <form onSubmit={createClient}>
        <label>Nombre del cliente<input name="name" maxLength={100} required /></label>
        {clientError && <p role="alert">{clientError}</p>}
        <div className="workspace-actions"><button type="button" disabled={clientSaving} onClick={() => addClient.current?.close()}>Cancelar</button><button className="button primary" disabled={clientSaving}>Guardar cliente</button></div>
      </form>
    </dialog>}
  </>;
}
