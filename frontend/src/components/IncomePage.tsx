import { useEffect, useState } from 'react';
import { watchPrivateSession } from '../lib/private-session';
import { amountLabel, collectionStatusLabel, incomeRequest, resourceQuery, sourceLabel, type Client, type Payment, type Project } from '../lib/income-api';
import IncomeSetupForm from './IncomeSetupForm';
import PaymentForm from './PaymentForm';
import PaymentCorrections from './PaymentCorrections';
import PaymentChargeForm from './PaymentChargeForm';
import ProjectDelivery from './ProjectDelivery';

type Mode = 'client' | 'client-detail' | 'project' | 'project-detail' | 'payment' | 'payment-detail';
type PageData = { client?: Client; clients?: Client[]; project?: Project; payment?: Payment };

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
        if (mode === 'project') {
          const dashboard = await incomeRequest<{ clients: Client[] }>('/api/dashboard', options);
          result = { clients: dashboard.clients };
        }
        if (mode === 'project-detail' || mode === 'payment') {
          result = { project: await incomeRequest<Project>(`/api/projects/${id}`, options) };
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
    {(mode !== 'payment' || !visible) && <a className="income-back" href="/dashboard"><span aria-hidden="true">←</span> Volver al tablero</a>}
    <p id="status" role="status" hidden={visible}>{status || 'Verificando conexión…'}</p>
    <section className={mode.endsWith('-detail') ? 'income-panel income-detail-panel' : mode === 'payment' ? 'income-panel payment-entry-panel' : 'income-panel'} hidden={!visible} key={version}>
      {data && (mode === 'client' || mode === 'project') &&
        <IncomeSetupForm mode={mode} clients={data.clients ?? []} selected={params.get('clientId')} fromProject={params.get('from') === 'project'} />}
      {mode === 'client-detail' && client && <>
        <h1>{client.name}</h1>
        <a className="button primary" href={`/projects/new?${resourceQuery('clientId', client.id)}`}>Nuevo proyecto</a>
      </>}
      {mode === 'project-detail' && project && <ProjectDetail project={project} active={visible} />}
      {mode === 'payment' && project && <PaymentForm key={project.id} project={project} active={visible} />}
      {mode === 'payment-detail' && payment && <>
        <PaymentDetail payment={payment} />
        <PaymentCorrections key={payment.id} payment={payment} active={visible}
          onEdited={updated => setData({ payment: updated })} onRefresh={() => location.reload()} />
        <PaymentChargeForm key={revision} paymentId={payment.id} onSaved={charge => {
          void incomeRequest<Payment>(`/api/payments/${encodeURIComponent(payment.id)}`, { passive: true })
            .then(updated => setData(current => current?.payment?.id === payment.id && current.payment.version === payment.version ? { payment: updated } : current))
            .catch(() => {});
          setData({ payment: { ...payment, charges: [...payment.charges.filter(item => item.id !== charge.id), charge]
            .sort((first, second) => first.paidOn.localeCompare(second.paidOn) || (BigInt(first.id) < BigInt(second.id) ? -1 : 1)) } });
          setRevision(value => value + 1);
        }} />
      </>}
    </section>
  </>;
}

function ProjectDetail({ project, active }: { project: Project; active: boolean }) {
  return <>
    <header className="income-detail-header">
      <div><h1>{project.name}</h1><span className="income-status">{collectionStatusLabel[project.collectionStatus]}</span></div>
      <a className="button primary" href={`/payments/new?${resourceQuery('projectId', project.id)}`}>Registrar cobro</a>
    </header>
    {active && <ProjectDelivery key={project.id} project={project} />}
    <dl className="income-details">
      <div><dt>Cliente</dt><dd>{project.client.name}</dd></div>
      <div><dt>Origen del proyecto</dt><dd>{project.acquisitionSource ?? 'Sin registrar'}</dd></div>
      <div><dt>Avance</dt><dd>{project.agreedPaymentCount === null ? `${project.paymentCount} pagos recibidos · Cantidad acordada: Sin registrar`
        : `${project.paymentCount} de ${project.agreedPaymentCount} pagos recibidos`}</dd></div>
    </dl>
    <dl className="income-metrics">
      <div><dt>Monto acordado</dt><dd>{project.agreedAmount ? amountLabel(project.agreedAmount) : 'Sin registrar'}</dd></div>
      <div><dt>Total abonado</dt><dd>{amountLabel(project.clientPaidTotal)}</dd></div>
      {project.outstandingBalance !== null && <div className="income-metric-primary"><dt>{project.isBalanceProvisional ? 'Saldo pendiente provisional' : 'Saldo pendiente'}</dt><dd>{amountLabel(project.outstandingBalance)}</dd></div>}
      {project.collectionStatus === 'overpaid' && project.excessAmount !== null &&
        <div className="income-metric-primary"><dt>Excedente</dt><dd>{amountLabel(project.excessAmount)}</dd></div>}
    </dl>
    <h2 className="income-section-heading">Cobros</h2>
    {Boolean(project.unconvertedPaymentCount) && <p>Hay cobros sin convertir. El saldo solo considera los importes con equivalente en MXN.</p>}
    {project.payments.length === 0 ? <p>Aún no hay cobros.</p> :
      <ul className="income-records" role="list">{project.payments.map(payment => <li key={payment.id}>
        <a href={`/payments/view?${resourceQuery('id', payment.id)}`}>{payment.paidOn} · {amountLabel(payment.clientPaidAmount, payment.currency)}</a>
        <span>{sourceLabel(payment, project.client.name)}{payment.conversionPending && ' · Sin convertir'}
          {payment.currency === 'USD' && payment.clientPaidAmountMxn && ` · Equivalente: ${amountLabel(payment.clientPaidAmountMxn)}`}</span>
      </li>)}</ul>}
  </>;
}

function PaymentDetail({ payment }: { payment: Payment }) {
  return <>
    <header className="income-detail-header">
      <div><h1>Detalle del cobro</h1>
        <a href={`/projects/view?${resourceQuery('id', payment.project.id)}`}>{payment.project.name}</a>
      </div>
    </header>
    <dl className="income-details">
      <div><dt>Cliente</dt><dd>{payment.client.name}</dd></div>
      <div><dt>Fecha de pago</dt><dd>{payment.paidOn}</dd></div>
      <div><dt>Fuente</dt><dd>{sourceLabel(payment, payment.client.name)}</dd></div>
    </dl>
    <dl className="income-metrics">
      <div><dt>Importe abonado</dt><dd>{amountLabel(payment.clientPaidAmount.replace(/(\.\d{2})0+$/, '$1'), payment.currency)}</dd></div>
      <div><dt>Comisión de plataforma</dt><dd>{amountLabel(payment.platformFee, payment.currency)}</dd></div>
      <div><dt>Impuestos descontados</dt><dd>{amountLabel(payment.withheldTaxAmount, payment.currency)}</dd></div>
      <div className="income-metric-primary"><dt>Dinero recibido al cobrar</dt><dd>{amountLabel(payment.receivedAmount, payment.currency)}</dd></div>
    </dl>
    {payment.conversionPending && <p>Este cobro está pendiente de conversión a MXN.</p>}
    {payment.exchangeRate && payment.equivalents && <dl className="income-details">
      <div><dt>Tipo de cambio aplicado</dt><dd>{payment.exchangeRate} MXN por USD</dd></div>
      <div><dt>Importe abonado equivalente</dt><dd>{amountLabel(payment.equivalents.clientPaidAmount)}</dd></div>
      <div><dt>Comisión equivalente</dt><dd>{amountLabel(payment.equivalents.platformFee)}</dd></div>
      <div><dt>Impuestos equivalentes</dt><dd>{amountLabel(payment.equivalents.withheldTaxAmount)}</dd></div>
      <div><dt>Dinero recibido equivalente</dt><dd>{amountLabel(payment.equivalents.receivedAmount)}</dd></div>
    </dl>}
    {payment.excessReason && <dl className="income-details"><div><dt>Razón del excedente</dt><dd>{payment.excessReason}</dd></div></dl>}
    <h2 className="income-section-heading">Cargos pagados por separado</h2>
    {payment.charges.length === 0 ? <p>Aún no hay cargos.</p> :
      <ul className="income-records" role="list">{payment.charges.map(charge => <li key={charge.id}>
        <span>{charge.chargeType === 'commission' ? 'Comisión' : 'Impuesto'} · {charge.paidOn} · <strong>{amountLabel(charge.amount)}</strong></span>
      </li>)}</ul>}
  </>;
}
