import { IncomeError } from './income-api';

export type FieldErrors = Record<string, string>;

/** Field messages the API rejected, keyed by input name. */
export function fieldErrorsOf(failure: unknown): FieldErrors {
  return failure instanceof IncomeError ? failure.result.error?.fields ?? {} : {};
}

/** Accessibility attributes for a control whose field was rejected. */
export function invalidProps(errors: FieldErrors, name: string) {
  return errors[name] ? { 'aria-invalid': true, 'aria-describedby': `${name}-error` } as const : {};
}

/** Moves focus to the first rejected control that exists in the form. */
export function focusFirstInvalid(form: HTMLFormElement | null, errors: FieldErrors) {
  requestAnimationFrame(() => {
    for (const name of Object.keys(errors)) {
      const control = form?.elements.namedItem(name);
      if (control instanceof HTMLElement) { control.focus(); return; }
    }
  });
}
