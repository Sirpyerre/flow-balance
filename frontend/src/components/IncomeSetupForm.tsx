import { useState, type FormEvent } from 'react';
import { IncomeError, incomeRequest, resourceQuery, type Client } from '../lib/income-api';

export default function IncomeSetupForm({ mode, clients, selected, fromProject }: {
  mode: 'client' | 'project'; clients: Client[]; selected: string | null; fromProject: boolean;
}) {
  const isClient = mode === 'client';
  const [error, setError] = useState<string>();
  const [pending, setPending] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (pending) return;
    const fields = Object.fromEntries(new FormData(event.currentTarget));
    setPending(true);
    setError(undefined);
    try {
      const body = isClient ? { name: fields.name } : {
        ...fields, agreedPaymentCount: Number(fields.agreedPaymentCount), agreedCurrency: 'MXN',
      };
      const { id } = await incomeRequest<{ id: string }>(isClient ? '/api/clients' : '/api/projects', { body });
      location.assign(isClient
        ? fromProject ? `/projects/new?${resourceQuery('clientId', id)}` : '/dashboard'
        : `/projects/view?${resourceQuery('id', id)}`);
    } catch (failure) {
      setError(failure instanceof IncomeError ? failure.message : 'Revisa tu conexión e intenta de nuevo.');
      setPending(false);
    }
  }

  return <>
    <h1>{isClient ? 'Nuevo cliente' : 'Nuevo proyecto'}</h1>
    {!isClient && <p><a href="/clients/new?from=project">Crear cliente</a></p>}
    {!isClient && clients.length === 0 ? <p>Agrega un cliente para crear tu proyecto.</p> :
      <form onSubmit={submit}>
        {!isClient && <label>Cliente<select name="clientId" required defaultValue={selected ?? ''}>
          <option value="" disabled>Selecciona un cliente</option>
          {clients.map(client => <option key={client.id} value={client.id}>{client.name}</option>)}
        </select></label>}
        <label>{isClient ? 'Nombre del cliente' : 'Nombre del proyecto'}<input name="name" maxLength={100} required /></label>
        {!isClient && <>
          <label>Origen del proyecto<input name="acquisitionSource" maxLength={100} required /></label>
          <label>Monto acordado (MXN)<input name="agreedAmount" inputMode="decimal" required /></label>
          <label>Pagos acordados<input name="agreedPaymentCount" type="number" inputMode="numeric" min="1" step="1" required /></label>
        </>}
        {error && <p role="alert">{error}</p>}
        <button className="button primary" disabled={pending}>{isClient ? 'Guardar cliente' : 'Guardar proyecto'}</button>
      </form>}
  </>;
}
