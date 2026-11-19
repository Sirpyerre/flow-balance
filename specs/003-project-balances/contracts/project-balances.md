# Contrato de consulta

## GET /api/projects/{id}

Conservar los campos existentes y agregar `outstandingBalance`, `excessAmount` y `collectionStatus`. Los campos del acuerdo, el total abonado y los cobros existentes permiten explicar el saldo. Para el saldo y su desglose, cada abono en MXN se redondea al centavo más cercano, con empates hacia arriba. La fila del reporte usa el mismo total. Un recurso ajeno e inexistente reciben el mismo 404.

## GET /api/reports/project-balances

Consulta autenticada, sin parámetros de periodo. Devuelve 200 con `projects`, una lista ordenada por nombre e identificador. Cada fila incluye nombre y los campos definidos en el [modelo](../data-model.md). Una cuenta vacía recibe `{"projects":[]}`. Las respuestas privadas llevan `Cache-Control: private, no-store`. Una sesión inválida devuelve 401. El frontend enlaza cada fila a `/projects/view?id={id}`.

Ejemplo de fila:

```json
{
  "projectId": "42",
  "name": "Identidad visual",
  "currency": "MXN",
  "agreedAmount": "5000.00",
  "clientPaidTotal": "3800.00",
  "outstandingBalance": "1200.00",
  "excessAmount": "0.00",
  "collectionStatus": "outstanding"
}
```
