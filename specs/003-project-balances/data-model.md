# Modelo de datos

| Campo derivado | Representación y origen |
| --- | --- |
| `projectId` | Identificador del proyecto, como cadena |
| `currency` | MXN para el alcance de la consulta |
| `agreedAmount` | Cadena decimal de dos posiciones o nulo, desde el proyecto |
| `clientPaidTotal` | Cadena decimal de dos posiciones. Suma de abonos en MXN del mismo titular y proyecto, redondeados individualmente al centavo más cercano con empates hacia arriba. Cero si no hay abonos |
| `outstandingBalance` | Cadena decimal de dos posiciones o nulo si falta acuerdo |
| `excessAmount` | Cadena decimal de dos posiciones o nulo si falta acuerdo |
| `collectionStatus` | `outstanding`, `settled`, `overpaid` o `undetermined` |

El acuerdo ausente produce dos importes nulos. Un acuerdo conocido produce dos importes no negativos y como máximo uno positivo. El excedente de esta lectura es vigente. El motivo registrado con un cobro conserva su función histórica. El modelo es derivado de las tablas existentes de proyectos y cobros.
