import { useEffect, useState } from 'react';
import { watchPrivateSession } from '../lib/private-session';
import { amountLabel, incomeRequest, resourceQuery, sourceLabel, type Client, type Payment, type Project } from '../lib/income-api';
import IncomeSetupForm from './IncomeSetupForm';
import PaymentForm from './PaymentForm';
import PaymentChargeForm from './PaymentChargeForm';

type Mode = 'client' | 'project' | 'project-detail' | 'payment' | 'payment-detail';
type PageData = { clients?: Client[]; project?: Project; payment?: Payment };

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
  const { project, payment } = data ?? {};
  return <>
    <a href="/dashboard">Volver al tablero</a>
    <p id="status" role="status" hidden={visible}>{status || 'Verificando conexión…'}</p>
    <section className="income-panel" hidden={!visible} key={version}>
      {data && (mode === 'client' || mode === 'project') &&
        <IncomeSetupForm mode={mode} clients={data.clients ?? []} selected={params.get('clientId')} fromProject={params.get('from') === 'project'} />}
      {mode === 'project-detail' && project && <ProjectDetail project={project} />}
      {mode === 'payment' && project && <PaymentForm project={project} />}
      {mode === 'payment-detail' && payment && <>
        <PaymentDetail payment={payment} />
        <PaymentChargeForm key={revision} paymentId={payment.id} onSaved={charge => {
          setData({ payment: { ...payment, charges: [...payment.charges.filter(item => item.id !== charge.id), charge]
            .sort((first, second) => first.paidOn.localeCompare(second.paidOn) || (BigInt(first.id) < BigInt(second.id) ? -1 : 1)) } });
          setRevision(value => value + 1);
        }} />
      </>}
    </section>
  </>;
}

function ProjectDetail({ project }: { project: Project }) {
  return <>
    <h1>{project.name}</h1>
    <dl className="income-details">
      <div><dt>Cliente</dt><dd>{project.client.name}</dd></div>
      <div><dt>Origen del proyecto</dt><dd>{project.acquisitionSource ?? 'Sin registrar'}</dd></div>
      <div><dt>Monto acordado</dt><dd>{project.agreedAmount ? amountLabel(project.agreedAmount) : 'Sin registrar'}</dd></div>
      <div><dt>Avance</dt><dd>{project.agreedPaymentCount === null ? `${project.paymentCount} pagos recibidos · Cantidad acordada: Sin registrar`
        : `${project.paymentCount} de ${project.agreedPaymentCount} pagos recibidos`}</dd></div>
      <div><dt>Total abonado</dt><dd>{amountLabel(project.clientPaidTotal)}</dd></div>
    </dl>
    <a className="button primary" href={`/payments/new?${resourceQuery('projectId', project.id)}`}>Registrar cobro</a>
    <h2>Cobros</h2>
    {project.payments.length === 0 ? <p>Aún no hay cobros.</p> :
      <ul>{project.payments.map(payment => <li key={payment.id}>
        <a href={`/payments/view?${resourceQuery('id', payment.id)}`}>{payment.paidOn} · {amountLabel(payment.clientPaidAmount)}</a>
        <div>{sourceLabel(payment, project.client.name)}</div>
      </li>)}</ul>}
  </>;
}

function PaymentDetail({ payment }: { payment: Payment }) {
  return <>
    <h1>Detalle del cobro</h1>
    <a href={`/projects/view?${resourceQuery('id', payment.project.id)}`}>{payment.project.name}</a>
    <dl className="income-details">
      <div><dt>Cliente</dt><dd>{payment.client.name}</dd></div>
      <div><dt>Fecha de pago</dt><dd>{payment.paidOn}</dd></div>
      <div><dt>Fuente</dt><dd>{sourceLabel(payment, payment.client.name)}</dd></div>
      <div><dt>Importe abonado</dt><dd>{amountLabel(payment.clientPaidAmount.replace(/(\.\d{2})0+$/, '$1'))}</dd></div>
      <div><dt>Comisión de plataforma</dt><dd>{amountLabel(payment.platformFee)}</dd></div>
      <div><dt>Impuestos descontados</dt><dd>{amountLabel(payment.withheldTaxAmount)}</dd></div>
      <div><dt>Dinero recibido al cobrar</dt><dd>{amountLabel(payment.receivedAmount)}</dd></div>
      {payment.excessReason && <div><dt>Razón del excedente</dt><dd>{payment.excessReason}</dd></div>}
    </dl>
    <h2>Cargos pagados por separado</h2>
    {payment.charges.length === 0 ? <p>Aún no hay cargos.</p> :
      <ul>{payment.charges.map(charge => <li key={charge.id}>
        {charge.chargeType === 'commission' ? 'Comisión' : 'Impuesto'} · {charge.paidOn} · {amountLabel(charge.amount)}
      </li>)}</ul>}
  </>;
}
