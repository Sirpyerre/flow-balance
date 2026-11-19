const periodFormatter = new Intl.DateTimeFormat('es-MX', { month: 'long', year: 'numeric', timeZone: 'UTC' });

export function periodLabel(period: string) {
  const value = periodFormatter.format(new Date(period + '-01T00:00:00Z')).replace(' de ', ' ');
  return value.charAt(0).toUpperCase() + value.slice(1);
}

export function mountMonthPicker(trigger: HTMLButtonElement, selected: () => string, current: () => string, apply: (period: string) => void) {
  const dialog = document.querySelector<HTMLDialogElement>('#month-dialog')!;
  const year = dialog.querySelector<HTMLInputElement>('#report-year')!;
  const previous = dialog.querySelector<HTMLButtonElement>('#previous-year')!;
  const next = dialog.querySelector<HTMLButtonElement>('#next-year')!;
  const confirm = dialog.querySelector<HTMLButtonElement>('#apply-period')!;
  const months = [...dialog.querySelectorAll<HTMLButtonElement>('[data-month]')];
  const monthValue = (button: HTMLButtonElement) => `${year.valueAsNumber}-${button.dataset.month!.padStart(2, '0')}`;
  let draft = '';
  function render() {
    const valid = year.validity.valid;
    previous.disabled = !valid || year.valueAsNumber <= Number(year.min);
    next.disabled = !valid || year.valueAsNumber >= Number(year.max);
    confirm.disabled = !valid || !draft || draft.slice(0, 4) !== year.value || draft > current();
    for (const button of months) {
      const value = monthValue(button);
      button.disabled = !valid || value > current();
      button.setAttribute('aria-pressed', String(value === draft));
      button.setAttribute('aria-label', valid ? periodLabel(value) : button.textContent!.trim());
    }
  }
  trigger.addEventListener('click', () => {
    draft = selected() || current();
    year.max = current().slice(0, 4);
    year.value = draft.slice(0, 4);
    render();
    dialog.showModal();
    (months.find(button => monthValue(button) === draft) ?? year).focus();
  });
  year.addEventListener('input', render);
  previous.addEventListener('click', () => { year.stepDown(); render(); });
  next.addEventListener('click', () => { year.stepUp(); render(); });
  for (const button of months) button.addEventListener('click', () => { draft = monthValue(button); render(); });
  dialog.querySelector('#current-period')!.addEventListener('click', () => {
    draft = current();
    year.value = draft.slice(0, 4);
    render();
  });
  confirm.addEventListener('click', () => { dialog.close(); apply(draft); });
  return dialog;
}
