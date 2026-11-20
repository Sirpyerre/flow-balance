import { useEffect, useRef, useState, type FormEvent } from 'react';
import { incomeRequest, IncomeError, type DeliveryStatus, type Project } from '../lib/income-api';

const deliveryLabel = { in_progress: 'En curso', delivered: 'Entregado' };

export default function ProjectDelivery({ project }: { project: Project }) {
  const [current, setCurrent] = useState(project.deliveryStatus);
  const [selection, setSelection] = useState<DeliveryStatus | ''>(project.deliveryStatus ?? '');
  const [pending, setPending] = useState(false);
  const [error, setError] = useState('');
  const request = useRef<AbortController>(undefined);
  useEffect(() => () => request.current?.abort(), []);

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selection || request.current) return;
    const controller = new AbortController();
    request.current = controller;
    setPending(true);
    setError('');
    try {
      const result = await incomeRequest<Pick<Project, 'deliveryStatus'>>(`/api/projects/${encodeURIComponent(project.id)}`, {
        body: { deliveryStatus: selection }, method: 'PATCH', signal: controller.signal,
      });
      if (!controller.signal.aborted) setCurrent(result.deliveryStatus);
    } catch (failure) {
      if (!controller.signal.aborted) {
        setError(failure instanceof IncomeError ? failure.message : 'No se pudo guardar el estado de entrega. Intenta de nuevo.');
      }
    } finally {
      request.current = undefined;
      if (!controller.signal.aborted) setPending(false);
    }
  }

  return <section className="project-delivery" aria-label="Estado de entrega">
    <p role="status">Entrega · <strong>{current ? deliveryLabel[current] : 'Sin registrar'}</strong></p>
    <form onSubmit={save}>
      <label>Estado de entrega
        <select value={selection} onChange={event => setSelection(event.target.value as DeliveryStatus | '')} disabled={pending}>
          {!current && <option value="" disabled>Sin registrar</option>}
          <option value="in_progress">En curso</option>
          <option value="delivered">Entregado</option>
        </select>
      </label>
      <button type="submit" aria-busy={pending} disabled={pending || !selection || selection === current}>Guardar estado</button>
    </form>
    {error && <p role="alert">{error}</p>}
  </section>;
}
