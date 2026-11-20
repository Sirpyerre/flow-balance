import { useEffect, useState } from 'react';
import { watchPrivateSession } from '../lib/private-session';
import { amountLabel, collectionStatusLabel, incomeRequest, resourceQuery, sourceLabel, type Client, type Payment, type Project } from '../lib/income-api';
import IncomeSetupForm from './IncomeSetupForm';
import PaymentForm from './PaymentForm';
import PaymentCorrections from './PaymentCorrections';
import PaymentChargeForm from './PaymentChargeForm';
import ProjectDelivery from './ProjectDelivery';

type Mode = 'client' | 'client-detail' | 'project' | 'project-detail' | 'project-edit' | 'payment' | 'payment-detail';
type PageData = { client?: Client; clients?: Client[]; project?: Project; payment?: Payment; projects?: Pick<Project, "id" | "name" | "client">[] };

export default function IncomePage({ mode }: { mode: Mode }) {
  const [visible, setVisible] = useState(false);
  const [data, setData] = useState<PageData>();
  const [query, setQuery] = useState('');
  const [status, setStatus] = useState('Verificando tu sesión…');
  const [revision, setRevision] = useState(0);
  const [version, setVersion] = useState(0);

  useEffect(() => {
    let request: AbortController | undefined;
    const params = new URLSearchParams(location.search);
    setQuery(location.search);
    function clear() {
      request?.abort();
      setVisible(false);
    }
    function reset() {
      clear();
      setData(undefined);
      setVersion(value => value + 1);
    }
    async function load() {
      request?.abort();
      const current = new AbortController();
      request = current;
      try {
        const options = { signal: current.signal, passive: true };
        const id = encodeURIComponent(params.get(mode === 'payment' ? 'projectId' : 'id') ?? '0');
        let result: PageData = {};
        if (mode === 'client-detail') {
          result = { client: await incomeRequest<Client>(`/api/clients/${id}`, options) };
        }
        if (mode === 'project' || mode === 'project-edit' || mode === 'client') {
          const dashboard = await incomeRequest<{ clients: Client[] }>('/api/dashboard', options);
          result = { clients: dashboard.clients };
        }
        if (mode === 'payment') {
          const dashboard = await incomeRequest<{ clients: Client[]; projects: { id: string; name: string; clientId: string }[] }>('/api/dashboard', options);
          result.projects = dashboard.projects.map(project => ({ ...project, client: dashboard.clients.find(client => client.id === project.clientId) ?? { id: project.clientId, name: '' } }));
          if (params.has('projectId')) result.project = await incomeRequest<Project>(`/api/projects/${id}`, options);
        }
        if (mode === 'project-detail' || mode === 'project-edit') {
          result = { ...result, project: await incomeRequest<Project>(`/api/projects/${id}`, options) };
        }
        if (mode === 'payment-detail') {
          result = { payment: await incomeRequest<Payment>(`/api/payments/${id}`, options) };
        }
        if (current.signal.aborted) return;
        setData(result);
        setStatus('');
        setVisible(true);
      } catch {
        if (!current.signal.aborted) setStatus('No se pudo abrir el registro. Vuelve al tablero e intenta de nuevo.');
      }
    }

    const session = watchPrivateSession(clear, () => void load(), reset);
    const updateDeadline = (event: Event) => session.updateDeadline((event as CustomEvent<Response>).detail);
    addEventListener('income-session-response', updateDeadline);
    session.verify();
    return () => {
      removeEventListener('income-session-response', updateDeadline);
      session.dispose();
      request?.abort();
    };
  }, [mode]);

  const params = new URLSearchParams(query);
  const { client, project, payment } = data ?? {};
  return <>
    {(mode !== 'payment' || !visible) && <a className="back-link" href="/dashboard"><span aria-hidden="true">←</span> Volver al tablero</a>}
    <p id="status" role="status" hidden={visible}>{status || 'Verificando conexión…'}</p>
    <section className={mode.endsWith('-detail') ? 'card page-card detail-card' : mode === 'payment' ? 'card page-card payment-entry-panel' : 'page-workspace'} hidden={!visible} key={version}>
      {data && (mode === 'client' || mode === 'project' || mode === 'project-edit') &&
        <IncomeSetupForm mode={mode === 'project-edit' ? 'project' : mode} project={project} clients={data.clients ?? []} selected={params.get('clientId')} fromProject={params.get('from') === 'project'} />}
      {mode === 'client-detail' && client && <>
        <h1>{client.name}</h1>
        <a className="button primary" href={`/projects/new?${resourceQuery('clientId', client.id)}`}>Nuevo proyecto</a>
      </>}
      {mode === 'project-detail' && project && <ProjectDetail project={project} active={visible} />}
      {mode === 'payment' && data && <PaymentWorkspace projects={data.projects ?? []} initial={project} active={visible} fromDashboard={params.get('from') === 'dashboard'} />}
      {mode === 'payment-detail' && payment && <>
        <PaymentDetail payment={payment} />
        <PaymentCorrections key={payment.id} payment={payment} active={visible}
          onEdited={updated => setData({ payment: updated })} onRefresh={() => location.reload()} />
        <div className="split-layout"><PaymentCharges payment={payment} />
        <PaymentChargeForm key={revision} paymentId={payment.id} onSaved={charge => {
          void incomeRequest<Payment>(`/api/payments/${encodeURIComponent(payment.id)}`, { passive: true })
            .then(updated => setData(current => current?.payment?.id === payment.id && current.payment.version === payment.version ? { payment: updated } : current))
            .catch(() => {});
          setData({ payment: { ...payment, charges: [...payment.charges.filter(item => item.id !== charge.id), charge]
            .sort((first, second) => first.paidOn.localeCompare(second.paidOn) || (BigInt(first.id) < BigInt(second.id) ? -1 : 1)) } });
          setRevision(value => value + 1);
        }} /></div>
      </>}
    </section>
  </>;
}

function ProjectDetail({ project, active }: { project: Project; active: boolean }) {
  return <>
    <header className="detail-header">
      <div><h1>{project.name}</h1><span className={`chip${({ outstanding: ' chip-warn', settled: ' chip-ok' } as Record<string, string>)[project.collectionStatus] ?? ''}`}>{collectionStatusLabel[project.collectionStatus]}</span></div>
      <div className="page-header-actions">
        <a className="button" href={`/projects/edit?${resourceQuery('id', project.id)}`}>Editar proyecto</a>
        <a className="button primary" href={`/payments/new?${resourceQuery('projectId', project.id)}`}>Registrar cobro</a>
      </div>
    </header>
    {active && <ProjectDelivery key={project.id} project={project} />}
    <dl className="detail-list">
      <div><dt>Cliente</dt><dd>{project.client.name}</dd></div>
      <div><dt>Origen del proyecto</dt><dd>{project.acquisitionSource ?? 'Sin registrar'}</dd></div>
      <div><dt>Avance</dt><dd>{project.agreedPaymentCount === null ? `${project.paymentCount} pagos recibidos · Cantidad acordada: Sin registrar`
        : `${project.paymentCount} de ${project.agreedPaymentCount} pagos recibidos`}</dd></div>
    </dl>
    <dl className="metric-grid">
      <div className="metric"><dt>Monto acordado</dt><dd>{project.agreedAmount ? amountLabel(project.agreedAmount) : 'Sin registrar'}</dd></div>
      <div className="metric"><dt>Total abonado</dt><dd>{amountLabel(project.clientPaidTotal)}</dd></div>
      {project.outstandingBalance !== null && <div className="metric metric-primary"><dt>{project.isBalanceProvisional ? 'Saldo pendiente provisional' : 'Saldo pendiente'}</dt><dd>{amountLabel(project.outstandingBalance)}</dd></div>}
      {project.collectionStatus === 'overpaid' && project.excessAmount !== null &&
        <div className="metric metric-primary"><dt>Excedente</dt><dd>{amountLabel(project.excessAmount)}</dd></div>}
    </dl>
    <h2 className="section-heading">Cobros</h2>
    {Boolean(project.unconvertedPaymentCount) && <p>Hay cobros sin convertir. El saldo solo considera los importes con equivalente en MXN.</p>}
    {project.payments.length === 0 ? <p>Aún no hay cobros.</p> :
      <ul className="record-list" role="list">{project.payments.map(payment => <li key={payment.id}>
        <a href={`/payments/view?${resourceQuery('id', payment.id)}`}>{payment.paidOn} · {amountLabel(payment.clientPaidAmount, payment.currency)}</a>
        <span>{sourceLabel(payment, project.client.name)}{payment.conversionPending && ' · Sin convertir'}
          {payment.currency === 'USD' && payment.clientPaidAmountMxn && ` · Equivalente: ${amountLabel(payment.clientPaidAmountMxn)}`}</span>
      </li>)}</ul>}
  </>;
}

function PaymentDetail({ payment }: { payment: Payment }) {
  return <>
    <header className="detail-header">
      <div><h1>Detalle del cobro</h1><p>{new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(payment.paidOn + 'T00:00:00Z'))}</p></div>
    </header>
    <dl className="detail-list">
      <div><dt>Cliente</dt><dd>{payment.client.name}</dd></div>
      <div><dt>Fecha de pago</dt><dd>{payment.paidOn}</dd></div>
      <div><dt>Fuente</dt><dd>{sourceLabel(payment, payment.client.name)}</dd></div>
      <div><dt>Proyecto</dt><dd><a href={`/projects/view?${resourceQuery('id', payment.project.id)}`}>{payment.project.name}</a></dd></div>
    </dl>
    <dl className="metric-grid">
      <div className="metric"><dt>Importe abonado</dt><dd>{amountLabel(payment.clientPaidAmount.replace(/(\.\d{2})0+$/, '$1'), payment.currency)}</dd></div>
      <div className="metric"><dt>Comisión de plataforma</dt><dd>{amountLabel(payment.platformFee, payment.currency)}</dd></div>
      <div className="metric"><dt>Impuestos descontados</dt><dd>{amountLabel(payment.withheldTaxAmount, payment.currency)}</dd></div>
      <div className="metric metric-primary"><dt>Dinero recibido al cobrar</dt><dd>{amountLabel(payment.receivedAmount, payment.currency)}</dd></div>
    </dl>
    {payment.conversionPending && <p>Este cobro está pendiente de conversión a MXN.</p>}
    {payment.exchangeRate && payment.equivalents && <dl className="detail-list">
      <div><dt>Tipo de cambio aplicado</dt><dd>{payment.exchangeRate} MXN por USD</dd></div>
      <div><dt>Importe abonado equivalente</dt><dd>{amountLabel(payment.equivalents.clientPaidAmount)}</dd></div>
      <div><dt>Comisión equivalente</dt><dd>{amountLabel(payment.equivalents.platformFee)}</dd></div>
      <div><dt>Impuestos equivalentes</dt><dd>{amountLabel(payment.equivalents.withheldTaxAmount)}</dd></div>
      <div><dt>Dinero recibido equivalente</dt><dd>{amountLabel(payment.equivalents.receivedAmount)}</dd></div>
    </dl>}
    {payment.excessReason && <dl className="detail-list"><div><dt>Razón del excedente</dt><dd>{payment.excessReason}</dd></div></dl>}
  </>;
}

function PaymentCharges({ payment }: { payment: Payment }) {
  return <section className="card page-card">
    <h2 className="section-heading">Cargos pagados por separado</h2>
    {payment.charges.length === 0 ? <p>Aún no hay cargos.</p> :
      <ul className="record-list" role="list">{payment.charges.map(charge => <li key={charge.id}>
        <span>{charge.chargeType === 'commission' ? 'Comisión' : 'Impuesto'} · {charge.paidOn} · <strong>{amountLabel(charge.amount)}</strong></span>
      </li>)}</ul>}
  </section>;
}

function PaymentWorkspace({ projects, initial, active, fromDashboard }: {
  projects: NonNullable<PageData['projects']>; initial?: Project; active: boolean; fromDashboard: boolean;
}) {
  const [id, setId] = useState(initial?.id ?? '');
  const [project, setProject] = useState(initial);
  const [failed, setFailed] = useState(false);
  useEffect(() => {
    if (!active || !id || project?.id === id) return;
    const controller = new AbortController();
    setFailed(false);
    void incomeRequest<Project>(`/api/projects/${encodeURIComponent(id)}`, { signal: controller.signal, passive: true })
      .then(result => { if (!controller.signal.aborted) setProject(result); })
      .catch(() => { if (!controller.signal.aborted) setFailed(true); });
    return () => controller.abort();
  }, [active, id, project?.id]);

  const selection = <label>Proyecto<select value={id} onChange={event => setId(event.target.value)}>
    <option value="">Selecciona un proyecto</option>
    {projects.map(item => <option value={item.id} key={item.id}>{item.name}{item.client.name && ` · ${item.client.name}`}</option>)}
  </select></label>;
  const cancelPath = fromDashboard ? '/dashboard' : initial ? `/projects/view?${resourceQuery('id', initial.id)}` : '/payments';
  const ready = !!id && project?.id === id;
  return <>
    {!initial && <a className="back-link" href="/payments">Volver a cobros</a>}
    {!ready && <header className="payment-entry-header"><h1>Registrar cobro</h1></header>}
    {!ready && (projects.length > 0 ? selection : <a className="button primary" href="/projects/new">Nuevo proyecto</a>)}
    {id && !ready && <p role="status">{failed ? 'No se pudo consultar el proyecto. Vuelve a seleccionarlo.' : 'Consultando el proyecto…'}</p>}
    {project && <div hidden={!ready}><PaymentForm project={project} active={active && ready} selection={selection} cancelPath={cancelPath} /></div>}
  </>;
}
