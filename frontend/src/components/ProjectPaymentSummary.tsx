import { useEffect, useId, useState } from 'react';
import { amountLabel, incomeRequest, type ProjectPaymentData } from '../lib/income-api';
import { paymentProgress } from '../lib/project-payment-summary';

export function useProjectPaymentSummary(projectId: string | undefined, initial?: ProjectPaymentData) {
  const [revision, setRevision] = useState(0);
  const [result, setResult] = useState<{ projectId: string; revision: number; data?: ProjectPaymentData }>();

  useEffect(() => {
    if (!projectId) {
      setResult(undefined);
      return;
    }
    if (initial && revision === 0) return;
    const controller = new AbortController();
    void incomeRequest<ProjectPaymentData>(`/api/projects/${encodeURIComponent(projectId)}`, {
      signal: controller.signal, passive: true,
    }).then(data => {
      if (!controller.signal.aborted) setResult({ projectId, revision, data });
    }).catch(() => {
      if (!controller.signal.aborted) setResult({ projectId, revision });
    });
    return () => controller.abort();
  }, [projectId, revision, initial]);

  const current = projectId && result?.projectId === projectId && result.revision === revision ? result : undefined;
  return {
    data: current?.data ?? (projectId && revision === 0 ? initial : undefined),
    failed: Boolean(current && !current.data),
    refresh: () => setRevision(value => value + 1),
  };
}

export default function ProjectPaymentSummary({ summary, mode }: {
  summary: ReturnType<typeof useProjectPaymentSummary>;
  mode: 'create' | 'edit' | 'saved';
}) {
  const heading = useId();
  const { data, failed, refresh } = summary;
  const compact = mode === 'create' && Boolean(data);
  return <section className={compact ? 'project-payment-summary payment-summary-compact' : 'project-payment-summary'} aria-labelledby={heading}>
    <h2 id={heading} className={compact ? 'payment-summary-accessible' : undefined}>Pagos del proyecto</h2>
    {!data && <p role="status">{failed ? 'El conteo de pagos no está disponible.' : 'Consultando los pagos del proyecto…'}</p>}
    {failed && <button type="button" onClick={refresh}>Reintentar consulta de pagos</button>}
    {data && <div aria-live="polite">
      {compact ? <CompactProgress data={data} /> : <>
        <Progress count={data.paymentCount} agreed={data.agreedPaymentCount} />
        <p>{data.isBalanceProvisional ? 'Saldo pendiente provisional' : 'Saldo pendiente'} <strong>{data.outstandingBalance === null ? 'Por determinar' : amountLabel(data.outstandingBalance)}</strong></p>
      </>}
      {Boolean(data.unconvertedPaymentCount) && <p>Hay cobros sin convertir a MXN.</p>}
      {mode === 'edit' && <p>Esta edición no cambia el número de pagos.</p>}
    </div>}
  </section>;
}

function CompactProgress({ data }: { data: ProjectPaymentData }) {
  const { paymentCount, agreedPaymentCount: agreed, outstandingBalance } = data;
  const progress = paymentProgress(paymentCount, agreed);
  const projected = paymentProgress(paymentCount, agreed, true);
  const segments = agreed && agreed <= 12 ? agreed : 0;
  const displayCount = (count: number) => agreed === null ? count : `${count} / ${agreed}`;
  const balance = outstandingBalance?.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  return <>
    <div className="payment-summary-metrics">
      <div>
        <span className="payment-summary-label">Pagos</span>
        <strong aria-label={agreed === null ? `${paymentCount} pagos recibidos` : `${paymentCount} de ${agreed} pagos recibidos`}>
          {paymentCount}{agreed !== null && <span> / {agreed}</span>}
        </strong>
      </div>
      <div>
        <span className="payment-summary-label">{data.isBalanceProvisional ? 'Saldo pendiente provisional' : 'Saldo pendiente'}</span>
        <strong>{balance === undefined ? 'Por determinar' : amountLabel(balance)}</strong>
      </div>
    </div>
    {agreed && <div className="payment-summary-track" aria-hidden="true">
      {segments > 0 ? Array.from({ length: segments }, (_, index) =>
        <span key={index} className={index < paymentCount ? 'is-filled' : undefined} />,
      ) : <span className="is-filled" style={{ width: `${Math.min(paymentCount / agreed, 1) * 100}%`, flex: 'none' }} />}
    </div>}
    <p className="payment-summary-next">Tras guardar: {displayCount(projected.count)} {projected.count === 1 && (agreed === null || agreed === 1) ? 'pago' : 'pagos'}</p>
    {agreed === null && <p className="payment-summary-note">Cantidad acordada sin registrar</p>}
    {agreed === paymentCount && <p className="payment-summary-note">Cantidad acordada de pagos alcanzada</p>}
    {progress.additional ? <p className="payment-summary-note">{progress.additional === 1 ? '1 pago adicional' : `${progress.additional} pagos adicionales`}</p> : null}
  </>;
}

function Progress({ count, agreed }: { count: number; agreed: number | null }) {
  const progress = paymentProgress(count, agreed);
  const received = (agreed === null ? progress.count : agreed) === 1 && progress.count === 1 ? 'pago recibido' : 'pagos recibidos';
  const countLabel = agreed === null ? `${progress.count} ${received}` : `${progress.count} de ${agreed} ${received}`;
  let status: string | undefined;
  if (progress.remaining === null) status = 'Cantidad acordada sin registrar';
  else if (progress.remaining === 1) status = 'Falta 1 pago';
  else if (progress.remaining > 1) status = `Faltan ${progress.remaining} pagos`;
  else if (progress.additional === 1) status = '1 pago adicional';
  else if (progress.additional) status = `${progress.additional} pagos adicionales`;
  else status = 'Cantidad acordada de pagos alcanzada';

  return <>
    <p><strong>{countLabel}</strong></p>
    {status && <p>{status}</p>}
  </>;
}
