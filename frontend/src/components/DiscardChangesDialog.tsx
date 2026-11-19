import { useRef, type MouseEvent } from 'react';

export default function DiscardChangesDialog({ dirty, href, onDiscard, disabled }: {
  dirty: boolean; href?: string; onDiscard?: () => void; disabled?: boolean;
}) {
  const dialog = useRef<HTMLDialogElement>(null);
  function cancel(event: MouseEvent) {
    if (!dirty && href) return;
    event.preventDefault();
    if (dirty) dialog.current?.showModal();
    else onDiscard?.();
  }
  function discard() {
    dialog.current?.close();
    if (href) location.assign(href);
    else onDiscard?.();
  }
  return <>
    {href ? <a className="button" href={href} onClick={cancel}>Cancelar</a>
      : <button type="button" onClick={cancel} disabled={disabled}>Cancelar</button>}
    <dialog className="discard-changes" ref={dialog} aria-label="Descartar cambios">
      <h2>¿Descartar los cambios?</h2>
      <div className="workspace-actions">
        <button type="button" autoFocus onClick={() => dialog.current?.close()}>Seguir editando</button>
        <button type="button" className="danger" onClick={discard}>Descartar cambios</button>
      </div>
    </dialog>
  </>;
}
