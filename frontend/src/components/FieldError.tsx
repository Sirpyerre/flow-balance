import type { FieldErrors } from '../lib/form-errors';

/** Message shown next to a rejected control; pairs with `invalidProps`. */
export default function FieldError({ errors, name }: { errors: FieldErrors; name: string }) {
  return errors[name] ? <small className="field-error" id={`${name}-error`}>{errors[name]}</small> : null;
}
