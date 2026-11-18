## Especificaciones para replicar en el sistema

**Colores**

| Token | Valor | Uso |
|---|---|---|
| `bg/base` | `#0B0B0F` | Fondo de página |
| `bg/surface` | `#121218` | Tarjetas y paneles |
| `border/subtle` | `#1E1E26` | Bordes de tarjetas y separadores |
| `border/input` | `#33333D` | Borde de campos y botones de contorno |
| `text/primary` | `#F4F4F6` | Títulos y texto principal |
| `text/label` | `#E4E4EA` | Etiquetas de campo |
| `text/secondary` | `#8E8E9A` | Descripciones y textos de apoyo |
| `text/placeholder` | `#6F6F7B` | Placeholders |
| `accent/primary` | `#8B5CF6` (configurable) | Inicio del degradado y enlaces |
| `accent/secondary` | `#F43F5E` | Fin del degradado |
| `accent/link` | `#C4B5FD` | Enlaces dentro de texto |
| Degradado de botón | `linear-gradient(90deg, #8B5CF6, #F43F5E)` | Botones primarios |
| Glow de fondo | `radial-gradient(circle, rgba(139,92,246,0.35), transparent 70%)`, 520 px, en la esquina superior izquierda | Fondo decorativo |

**Tipografía** (Inter, pesos 400, 500, 600 y 700)

| Elemento | Tamaño | Peso | Interlineado | Otros |
|---|---|---|---|---|
| Título de formulario | 32 px | 700 | normal | letter-spacing -0.01em |
| Descripción | 15 px | 400 | 1.6 | color `text/secondary` |
| Etiqueta de campo | 14 px | 500 | normal | color `text/label` |
| Texto de campo | 15 px | 400 | normal | |
| Botón primario | 16 px | 600 | normal | |
| Enlace secundario | 14 px | 500 | normal | |
| Logotipo | 20 px | 700 | normal | letter-spacing 0.02em, en mayúsculas |

**Espaciado y tamaños**

- Ancho máximo del formulario: 440 px, centrado, con padding lateral de 24 px.
- Tarjeta: padding de 40 px, radio de 16 px, borde de 1 px `border/subtle`.
- Separación entre campos: 20 px. Entre etiqueta e input: 8 px.
- Campos: alto de 48 px, padding horizontal de 14 px, radio de 10 px, borde de 1 px.
- Botón primario: alto de 52 px, ancho completo, radio de 10 px, margen superior de 8 px.
- Enlace bajo el formulario: margen superior de 24 px, centrado.
- Encabezado: logotipo a la izquierda, botón de contorno «Iniciar sesión» a la derecha, padding de 24 px vertical y 40 px horizontal, borde inferior de 1 px.

**Componentes**

- **Botón de contorno:** fondo transparente, borde de 1 px `border/input`, radio de 10 px, padding de 10 px por 18 px.
- **Campo:** fondo `#0B0B0F`, texto `text/primary`, borde `border/input`.
- **Logotipo:** círculo de 36 px con borde de 2 px en `text/primary` y las letras «FB» centradas.

**Estados que no maqueté** (conviene definirlos en el sistema)
- Foco: cambiar el borde a `accent/primary` con un anillo de 3 px al 25 % de opacidad.
- Error: borde y mensaje en rojo `#F43F5E`, con el mensaje debajo del campo en 13 px.
- Deshabilitado: opacidad de 50 %.
- Botón en carga: mantener el degradado y mostrar un indicador en lugar del texto.

**Fuente de verdad:** los valores salen directamente del código del artboard `Register.dc.html`, así que coinciden con lo que ves en el canvas.
## Aplicación en el frontend

- `/` reproduce la composición de la maqueta: encabezado, introducción,
  funciones, secciones de cobros y privacidad, llamada final y pie de página.
  Los dos espacios reservados para imágenes muestran ejemplos ilustrativos
  con datos ficticios. Los enlaces de cuenta conducen a `/register` y `/login`.
- `/register` usa la tarjeta de la maqueta. `/login` comparte encabezado, tarjeta,
  tipografía y estados de campo. Ambas rutas conservan el envío a la API,
  autocompletado y errores en español de México.
- Los colores y estados compartidos viven en `frontend/src/styles/theme.css`.
  Inter se incluye en la compilación y se sirve desde el mismo sitio.
- Cada sección de la landing bajo la introducción principal se anima una vez al
  entrar en pantalla. El desplazamiento sigue siendo nativo. Si el sistema pide
  movimiento reducido, las secciones aparecen sin animación.
- En pantallas estrechas las tarjetas pasan a una columna y se reduce el
  espaciado de los formularios.

Para revisar, abre `/`, `/register` y `/login` siguiendo la
[guía de desarrollo local](../local-development.md). Recorre la landing con scroll,
prueba los enlaces con teclado y repite con movimiento reducido. En registro,
comprueba foco, mensajes de validación e indicador de envío.
