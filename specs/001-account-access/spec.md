# Especificación: Registro, inicio de sesión y acceso a datos personales

**Proyecto**: Flow Balance

**Rama actual**: `main`; esta especificación no crea una rama.

**Directorio de la funcionalidad**: `specs/001-account-access`

**Creación**: 2026-11-18

**Estado**: Especificación validada; pendiente de revisión antes del plan técnico.

**Entrada**: Como profesional freelance, quiero crear una cuenta con mi nombre,
correo electrónico y contraseña, iniciar y cerrar sesión desde la web o la PWA,
y recuperar mis clientes, proyectos, cobros y reportes al volver a entrar.

**Problema**: La información del trabajo freelance debe permanecer disponible para
su titular entre sesiones y dispositivos, sin quedar expuesta a otras cuentas.
El módulo debe ofrecer un acceso sencillo y proteger tanto los registros como
las cifras calculadas a partir de ellos.

## Clarifications

### Session 2026-11-18

- Q: ¿Qué duración debe tener una sesión? → A: Opción C: vence tras 24 horas sin
  actividad o 7 días desde el inicio de sesión, lo que ocurra primero.
- Q: ¿Cómo responder ante intentos repetidos de acceso fallidos? → A: Opción A:
  tras 5 fallos en 15 minutos para el mismo correo y origen de conexión, impedir
  nuevos intentos de esa combinación durante 15 minutos, con la misma regla para
  correos registrados y no registrados.

## Escenarios de usuario y pruebas *(obligatorio)*

### Historia de usuario 1 - Crear mi cuenta (Prioridad: P1)

Como profesional freelance nuevo, quiero registrarme y llegar a un tablero vacío
que me permita comenzar con mi primer proyecto.

**Motivo de la prioridad**: Permite identificar al titular y empezar sin datos
ajenos ni cuentas duplicadas.

**Prueba independiente**: Registrar una cuenta nueva, verificar el estado inicial
y repetir el registro con variantes del mismo correo.

**Escenarios de aceptación**:

1. **Dado** un correo no registrado y un nombre, correo, contraseña y confirmación
   válidos, **cuando** envío el registro, **entonces** se crea una sola cuenta,
   quedo con la sesión iniciada y veo un tablero sin clientes, proyectos ni cobros,
   con totales en cero y la opción exacta “Crear mi primer proyecto”.
2. **Dado** el formulario de registro, **cuando** omito un campo, envío un nombre
   compuesto solo de espacios, un correo inválido o una contraseña fuera de los
   límites de FR-003, **entonces** no se crea la cuenta y se señala cada campo
   inválido con un mensaje en español de México.
3. **Dada** una contraseña válida, **cuando** la confirmación no coincide exactamente,
   **entonces** veo “Las contraseñas no coinciden” y no se crea la cuenta.
4. **Dada** una cuenta con `ana@example.com`, **cuando** intento registrar
   `ANA@EXAMPLE.COM` o ` ana@example.com `, **entonces** no se crea otra cuenta,
   se informa “No se pudo crear la cuenta con este correo” y la cuenta existente
   permanece sin cambios.
5. **Dados** dos envíos simultáneos de registro con el mismo correo, incluso con
   mayúsculas distintas, **cuando** se procesan, **entonces** existe una sola cuenta
   y únicamente el registro exitoso inicia sesión como la cuenta recién creada.
6. **Dado** el tablero inicial, **cuando** elijo “Crear mi primer proyecto”,
   **entonces** accedo al flujo de creación de un proyecto propio.
7. **Dados** nombres con acentos, espacios internos, apóstrofos o guiones dentro de
   los límites, **cuando** registro cuentas con correos distintos y datos válidos,
   **entonces** se aceptan, incluso si dos titulares tienen el mismo nombre. Los
   límites de FR-002 y FR-003 se comprueban en su valor mínimo, máximo y fuera de rango.
8. **Dado** un formulario con errores, **cuando** lo corrijo, **entonces** nombre y
   correo válidos permanecen; contraseña y confirmación aparecen ocultas y nunca se
   incluyen en mensajes ni en los datos de cuenta devueltos al usuario.

### Historia de usuario 2 - Iniciar sesión y recuperar mis datos (Prioridad: P1)

Como titular de una cuenta, quiero entrar desde la web o la PWA y encontrar mi
información guardada, sin tener que crearla otra vez.

**Motivo de la prioridad**: Garantiza continuidad de uso y acceso al historial propio.

**Prueba independiente**: Usar una cuenta con clientes, proyectos y cobros guardados,
cerrar la sesión e ingresar de nuevo desde otra superficie compatible.

**Escenarios de aceptación**:

1. **Dada** una cuenta existente, **cuando** ingreso su correo y contraseña correctos
   en la web o la PWA, **entonces** entro al tablero de esa cuenta. Se admite variar
   las mayúsculas del correo o añadir espacios en sus extremos.
2. **Dados** clientes, proyectos y cobros guardados y sus reportes, **cuando** vuelvo
   a entrar, incluso desde otro dispositivo, **entonces** encuentro los mismos
   registros y los reportes correspondientes, sin pérdidas ni duplicados por iniciar
   sesión. Los importes, monedas y relaciones conservan sus valores.
3. **Dado** un correo registrado con contraseña incorrecta o un correo no registrado,
   **cuando** intento entrar, **entonces** veo en ambos casos el mismo mensaje:
   “El correo o la contraseña son incorrectos”, sin indicar si el correo existe,
   y no obtengo acceso a datos privados.
4. **Dado** el formulario de acceso, **cuando** dejo un campo vacío o ingreso un
   correo de formato inválido, **entonces** se indican los errores de los campos
   en español y no se inicia una sesión.
5. **Dada** una contraseña registrada, **cuando** cambio sus mayúsculas o agrego
   espacios, **entonces** solo se permite entrar si coincide exactamente con la
   contraseña original; no se corrige ni transforma silenciosamente.
6. **Dado** que una cuenta no tiene cobros propios en el mes consultado y otra sí,
   **cuando** consulta su tablero y reporte de ese mes, **entonces** ve un total de
   cero y ningún cobro ajeno, aunque cambie los filtros o el periodo.

7. **Dada** una combinación de correo y origen de conexión sin pausa vigente,
   **cuando** acumula 5 intentos de credenciales incorrectas dentro de los últimos
   15 minutos, **entonces** el quinto intento activa una pausa de 15 minutos para
   esa combinación. Durante la pausa, incluso las credenciales correctas se rechazan
   con “Demasiados intentos. Intenta de nuevo en 15 minutos”, sin iniciar sesión.
   El resultado es el mismo para correos registrados y no registrados.
8. **Dada** una pausa vigente, **cuando** se repite un intento con mayúsculas o
   espacios exteriores diferentes en el correo, **entonces** continúa la misma pausa.
   Los intentos rechazados durante ella no prolongan su duración. Justo antes de
   que termine se deniega el acceso; al cumplirse los 15 minutos se permite volver
   a intentarlo y las credenciales correctas pueden iniciar sesión.
9. **Dados** fallos para un correo desde un origen, **cuando** se intenta entrar
   con ese correo desde otro origen, o con otro correo desde el origen inicial,
   **entonces** esos intentos no heredan el conteo ni la pausa de la combinación
   original. Un inicio de sesión exitoso antes de alcanzar el límite limpia el conteo
   de su combinación; al terminar una pausa, el conteo empieza de nuevo. Los fallos
   anteriores a la ventana de 15 minutos no cuentan para activar una nueva pausa.

### Historia de usuario 3 - Trabajar únicamente con mis datos (Prioridad: P1)

Como titular, quiero que mis clientes, proyectos, cobros y reportes sean privados,
aunque alguien conozca una dirección o identificador de mis registros.

**Motivo de la prioridad**: El aislamiento entre cuentas es una condición de acceso,
incluidos los listados, los detalles, las modificaciones y las sumas.

**Prueba independiente**: Preparar dos cuentas con registros propios y comprobar
lectura, creación, modificación y reportes tanto desde las pantallas como mediante
solicitudes directas a la API.

**Escenarios de aceptación**:

1. **Dada** Ana con sesión iniciada, **cuando** crea, consulta o modifica sus propios
   clientes, proyectos y cobros con datos válidos, **entonces** la operación está
   permitida y únicamente afecta a sus registros. Sus reportes se derivan de ellos.
2. **Dadas** dos cuentas distintas, Ana y Luis, sin otros cobros, **y dado** que Ana
   registra un cobro de MXN 3,000.00 y Luis uno de MXN 800.00 en el mismo mes,
   sin comisiones, impuestos ni conversiones, **cuando** cada titular consulta su
   tablero y reporte mensual, **entonces** el total de cobros de Ana es únicamente
   MXN 3,000.00 y el de Luis únicamente MXN 800.00; ninguno muestra MXN 3,800.00.
   Cada total permite consultar solo el cobro propio que lo compone.
3. **Dado** el cobro de Ana del escenario anterior, **cuando** Luis intenta abrirlo
   usando su URL o identificador, desde la interfaz o directamente por la API,
   **entonces** se deniega el acceso sin revelar sus datos ni confirmar si existe.
4. **Dado** ese mismo cobro, **cuando** Luis intenta cambiarlo de MXN 3,000.00 a
   MXN 1.00 usando su identificador, **entonces** se deniega la modificación;
   **cuando** Ana vuelve a consultarlo, **entonces** conserva MXN 3,000.00 y ambos
   tableros y reportes conservan los totales del escenario 2.
5. **Dados** un cliente y un proyecto de Ana, **cuando** Luis intenta consultarlos
   o modificarlos por URL o identificador, **entonces** se deniegan las operaciones
   sin revelar información y ambos registros permanecen sin cambios.
6. **Dada** la sesión de Luis, **cuando** solicita crear o modificar un registro
   asignándolo a Ana o vinculándolo con un cliente o proyecto de Ana, **entonces**
   se rechaza la operación completa sin crear ni alterar registros de ninguna cuenta.
7. **Dados** registros de Ana y Luis, **cuando** Luis consulta listados, búsquedas,
   filtros, detalles o totales de reportes, incluso solicitándolos directamente o
   intentando seleccionar a Ana como titular, **entonces** solo recibe información
   propia o una denegación; no recibe registros ni contribuciones monetarias de Ana.
8. **Dados** un identificador ajeno y uno inexistente, **cuando** Luis intenta
   consultarlos o modificarlos, **entonces** recibe la misma respuesta pública de
   recurso no disponible, sin datos ni modificaciones.

### Historia de usuario 4 - Cerrar sesión y proteger el acceso posterior (Prioridad: P1)

Como titular, quiero cerrar mi sesión y que nadie pueda seguir usándola para
consultar o modificar mi información.

**Motivo de la prioridad**: El cierre debe retirar el acceso efectivo, incluso si
se conserva una pantalla abierta o una solicitud anterior.

**Prueba independiente**: Cerrar una sesión activa y volver a usarla desde una
pantalla privada, otra pestaña y una solicitud directa.

**Escenarios de aceptación**:

1. **Dada** una sesión activa, **cuando** elijo “Cerrar sesión” y la operación termina
   correctamente, **entonces** la sesión queda invalidada en el servidor y se muestra
   el inicio de sesión. Los datos guardados de la cuenta permanecen intactos.
2. **Dada** esa sesión cerrada, **cuando** vuelvo a una pantalla privada por su URL,
   recargo, uso el botón Atrás o intento acceder desde otra pestaña que compartía
   esa sesión, **entonces** no se muestran datos privados y se requiere iniciar sesión.
3. **Dada** esa misma sesión invalidada, **cuando** consulto o intento modificar
   información directamente por la API reutilizando el acceso anterior, **entonces**
   la operación se deniega por falta de sesión y no se revela ni modifica información.
4. **Dada** una persona sin sesión o con una sesión vencida o inválida, **cuando**
   intenta abrir cualquier pantalla privada o consultar o modificar datos por la API,
   **entonces** se exige iniciar sesión y no se ejecuta la operación solicitada.
5. **Dado** que Ana cerró su sesión en un dispositivo, **cuando** Luis entra en ese
   mismo dispositivo, **entonces** solo ve sus datos, sin contenido residual de Ana.
6. **Dada** una interrupción de conexión al cerrar sesión, **cuando** no se puede
   confirmar su invalidación, **entonces** no se anuncia un cierre exitoso; se muestra
   un error en español con opción de reintentar. Tras un reintento exitoso se cumplen
   los escenarios 1–3; repetir el cierre no vuelve a activar la sesión.

7. **Dada** una sesión con menos de 7 días desde su inicio, **cuando** se cumplen
   24 horas desde la última actividad del usuario, **entonces** vence y cualquier
   nuevo acceso privado exige iniciar sesión, sin revelar datos ni aplicar cambios.
   Justo antes del límite sigue siendo válida si no se cerró por otro motivo.
8. **Dada** una sesión con actividad dentro de las últimas 24 horas, **cuando** se
   cumplen 7 días desde su inicio, **entonces** vence aunque se haya usado de forma
   continua. La actividad no prolonga ese límite máximo.
9. **Dada** una sesión aún válida, **cuando** el usuario realiza una consulta o acción
   autenticada, **entonces** las 24 horas de inactividad se cuentan desde esa acción;
   las actualizaciones automáticas en segundo plano no reinician ese plazo. Ambos
   límites se aplican igual en la web y en la PWA.

### Historia de usuario 5 - Usar formularios desde el celular (Prioridad: P2)

Como profesional freelance en el celular, quiero completar los formularios con
el navegador o mi gestor de contraseñas sin pasos innecesarios.

**Motivo de la prioridad**: Reduce errores y facilita el acceso habitual desde la PWA.
La prioridad indica el orden de entrega; estos comportamientos son obligatorios.

**Prueba independiente**: Completar registro y acceso en navegador móvil y PWA,
con datos escritos, autocompletados y pegados desde un gestor de contraseñas.

**Escenarios de aceptación**:

1. **Dado** el formulario de registro en un celular, **cuando** uso autocompletado
   para nombre y correo y una contraseña generada por el gestor, **entonces** los
   valores se colocan en sus campos correspondientes y puedo completar la
   confirmación y enviar el registro sin transcribirlos manualmente.
2. **Dado** el formulario de acceso en la web móvil o la PWA, **cuando** selecciono
   mis credenciales guardadas, **entonces** correo y contraseña se completan en los
   campos correctos y puedo iniciar sesión con una sola acción de envío.
3. **Dado** cualquiera de esos formularios, **cuando** pego una contraseña, completo
   la confirmación, corrijo un error o envío desde el teclado, **entonces** no se
   bloquean esas acciones y no necesito cambiar a la versión de escritorio.
4. **Dada** una pantalla móvil de 360 píxeles de ancho, **cuando** completo el registro
   o acceso y aparecen errores, **entonces** todos los campos, mensajes y acciones
   son legibles y utilizables sin desplazamiento horizontal. Cada formulario ocupa
   una sola pantalla de flujo, aunque pueda requerir desplazamiento vertical.

### Casos límite

- Dos registros simultáneos con correos equivalentes generan una sola cuenta (H1.5).
- Los espacios exteriores del correo se ignoran; los de la contraseña se conservan
  y su confirmación es exacta (H1.4, H2.5).
- Los nombres con acentos, espacios internos, apóstrofos o guiones son válidos dentro
  de los límites; un nombre vacío tras quitar espacios exteriores no lo es (FR-002).
- La confirmación vacía o diferente impide el registro sin borrar nombre y correo
  válidos; los campos de contraseña se mantienen ocultos (FR-004, FR-005).
- Identificadores ajenos o inexistentes no permiten obtener información ni modificar
  datos; cambiar al titular o la relación de un registro no evita el control (H3.3–8).
- Los fallos de acceso se agrupan por correo normalizado y origen de conexión;
  variar mayúsculas no evita la pausa y los intentos durante ella no la extienden.
  Un correo inexistente recibe el mismo tratamiento (H2.7–9, FR-009).
- Un reporte sin cobros propios en el periodo muestra cero aunque otra cuenta tenga
  cobros; consultar otro mes nunca amplía el conjunto de titulares (FR-013).
- Cerrar y volver a entrar no crea nuevos cobros ni cambia sus fechas o monedas
  (H2.2); los reportes conservan la trazabilidad hacia los registros propios.
- Sin conexión no se confirma un registro, inicio o cierre exitoso. La reconexión
  no habilita una sesión invalidada; una PWA sin sesión válida no muestra datos
  privados previamente consultados (H4.2–6, FR-017).

## Requisitos *(obligatorio)*

### Requisitos funcionales

- **FR-001**: El registro DEBE solicitar nombre, correo, contraseña y confirmación;
  los cuatro campos son obligatorios (H1.1–3).
- **FR-002**: El nombre DEBE contener de 1 a 100 caracteres después de quitar
  espacios exteriores, admitir los caracteres descritos en los casos límite y no
  exigir nombres únicos. El correo DEBE tener formato válido y no superar 254
  caracteres después de quitar espacios exteriores (H1.2, 4, 7).
- **FR-003**: La contraseña DEBE admitir de 12 a 128 caracteres, incluidos espacios,
  permitir pegado y generación por gestores, y coincidir exactamente con su
  confirmación, sin imponer combinaciones de tipos de caracteres (H1.2–3, 7, H5.1–3).
- **FR-004**: Un registro inválido DEBE señalar los campos y motivos en español de
  México, conservar nombre y correo válidos y no crear una cuenta ni autenticar al
  solicitante. Se permite mantener una sesión anónima exclusivamente para proteger
  el formulario contra CSRF; esa sesión no concede acceso a datos privados (H1.2–3, 8).
- **FR-005**: Las contraseñas y su confirmación DEBEN mostrarse ocultas al escribir;
  nunca deben formar parte de datos de cuenta o mensajes devueltos al usuario
  (H1.8).
- **FR-006**: Solo DEBE existir una cuenta por correo, ignorando mayúsculas y espacios
  exteriores, incluso con registros simultáneos. Un rechazo no DEBE modificar la
  cuenta existente ni iniciar sesión como ella (H1.4–5).
- **FR-007**: Un registro exitoso DEBE iniciar sesión, mostrar el tablero vacío con
  totales cero y ofrecer “Crear mi primer proyecto” hacia su flujo de creación
  (H1.1, H1.6).
- **FR-008**: El acceso DEBE aceptar correo y contraseña correctos en web y PWA,
  tratar el correo como FR-006 y comparar la contraseña exactamente. Datos vacíos
  o un correo de formato inválido DEBEN señalarse antes de permitir acceso (H2.1, 4–5).
- **FR-009**: Una contraseña incorrecta y un correo no registrado DEBEN recibir el
  mismo mensaje “El correo o la contraseña son incorrectos” y el mismo resultado
  público de acceso rechazado, sin revelar la existencia de la cuenta, salvo que
  exista una pausa por intentos repetidos. Tras 5 fallos de credenciales dentro de
  los últimos 15 minutos para la misma combinación de correo y origen de conexión,
  se DEBEN impedir nuevos intentos de esa combinación durante 15 minutos desde el
  quinto fallo, incluso con credenciales correctas. Durante esa pausa se DEBE mostrar
  “Demasiados intentos. Intenta de nuevo en 15 minutos”. Se DEBE usar la equivalencia
  de correos de FR-006 y la misma regla para correos registrados y no registrados.
  Los intentos durante la pausa no la prolongan; al terminar, el conteo comienza
  de nuevo. Un acceso exitoso antes del límite limpia el conteo de su combinación;
  otras combinaciones no heredan sus fallos ni su pausa (H2.3, 7–9).
- **FR-010**: Al volver a entrar, la cuenta DEBE recuperar sus clientes, proyectos y
  cobros guardados y poder consultar sus reportes, también desde otro dispositivo.
  Entrar o salir no DEBE crear, borrar ni modificar esos datos (H2.2, H4.1).
- **FR-011**: Toda creación, consulta o modificación DEBE estar limitada al titular
  autenticado. Cada cliente, proyecto y cobro tiene un único titular; los datos de
  otra cuenta no se DEBEN revelar ni alterar por pantallas, URL, identificador o
  solicitudes directas a la API (H3.1, 3–8).
- **FR-012**: No se DEBE permitir crear ni modificar registros para otro titular ni
  vincular registros entre cuentas. Si una operación lo intenta, se rechaza completa
  sin cambios parciales (H3.6).
- **FR-013**: Listados, búsquedas, filtros y totales del tablero y reportes DEBEN
  considerar únicamente datos propios, en cualquier periodo. Sin cobros propios el
  total es cero; cada cifra DEBE poder rastrearse solo a cobros del titular
  (H2.6, H3.2, 7).
- **FR-014**: Los intentos de leer o modificar recursos ajenos e inexistentes DEBEN
  producir la misma respuesta pública de recurso no disponible; no se DEBE revelar
  el titular, el contenido ni la existencia del recurso (H3.3–5, 8).
- **FR-015**: “Cerrar sesión” DEBE invalidar la sesión en el servidor. Solo se anuncia
  éxito tras confirmarlo; un fallo permite reintentar sin reactivar sesiones cerradas
  (H4.1, 6).
- **FR-016**: Sin sesión válida, incluidas sesiones cerradas o vencidas, toda pantalla
  privada DEBE requerir inicio de sesión y toda consulta o modificación por la API
  DEBE rechazarse sin devolver datos privados ni aplicar cambios. El control incluye
  otra pestaña que comparta la sesión y la reutilización del acceso anterior.
  La sesión DEBE vencer al cumplirse 24 horas sin actividad del usuario o 7 días
  desde su inicio, lo que ocurra primero. Solo las consultas o acciones autenticadas
  del usuario reinician el plazo de inactividad; las actualizaciones automáticas
  no lo reinician y ninguna actividad extiende los 7 días (H4.2–4, 7–9).
- **FR-017**: Tras cerrar sesión o cambiar de cuenta no se DEBE mostrar contenido
  privado de la sesión anterior al volver atrás, recargar, abrir otra pestaña o
  reabrir la PWA, aun sin conexión (H4.2, 5–6).
- **FR-018**: Los formularios DEBEN funcionar con autocompletado y gestores de
  contraseñas, permitir pegado y envío desde el teclado, y cumplir el recorrido móvil
  de una sola etapa por formulario sin desplazamiento horizontal desde 360 píxeles
  de ancho (H5.1–4).
- **FR-019**: Todos los textos visibles, incluidos errores y estados vacíos, DEBEN
  estar en español de México; web y PWA DEBEN aplicar las mismas reglas de acceso
  y aislamiento (H1–H5).

### Entidades clave *(la funcionalidad involucra datos)*

- **Cuenta**: Titular identificado por nombre y correo único, con una contraseña
  para acreditar el acceso. La confirmación solo valida el registro.
- **Sesión**: Acceso vigente de una cuenta; puede invalidarse y no concede permisos
  sobre otras cuentas.
- **Cliente**: Registro privado de un único titular, relacionado con sus proyectos.
- **Proyecto**: Trabajo de un titular; sus relaciones con clientes y cobros deben
  pertenecer a la misma cuenta. Su estado de entrega es independiente del de cobro.
- **Cobro (`Payment`)**: Registro monetario de un titular y su proyecto, con importe,
  moneda y fecha. Comisiones, impuestos y dinero recibido son conceptos distintos;
  el módulo de acceso preserva estos datos y sus reglas existentes.
- **Reporte**: Consulta de datos y agregados de una cuenta para un periodo. Cada cifra
  se deriva exclusivamente de sus cobros y permite rastrearlos; no se presupone que
  el reporte deba guardarse como un registro independiente.

## Criterios de éxito *(obligatorio)*

### Resultados medibles

- **SC-001**: El 100 % de los registros válidos de aceptación termina con una sola
  cuenta, sesión iniciada y tablero vacío con “Crear mi primer proyecto”; todos los
  casos inválidos o duplicados quedan rechazados sin alterar cuentas existentes.
- **SC-002**: El 100 % de las comprobaciones de reingreso recupera los mismos registros
  y cifras de la cuenta, tanto desde web como desde PWA, sin pérdida ni duplicados.
- **SC-003**: En el escenario de Ana y Luis, los tableros y reportes muestran exactamente
  MXN 3,000.00 y MXN 800.00 respectivamente. Todos los intentos de acceso cruzado se
  deniegan, el cobro de Ana conserva MXN 3,000.00 y no se filtran sus datos.
- **SC-004**: El 100 % de los intentos posteriores al cierre confirmado, sin una nueva
  sesión, queda sin acceso a información privada ni capacidad de modificarla,
  incluidos Atrás, otra pestaña y solicitudes directas. Lo mismo se cumple al
  alcanzar cualquiera de los dos límites de vencimiento de FR-016, incluidos los
  casos con actividad continua y actualizaciones automáticas.
- **SC-005**: Todos los escenarios de credenciales incorrectas muestran exactamente
  el mismo mensaje y resultado público bajo las mismas condiciones de pausa,
  independientemente de que el correo exista. En el 100 % de las pruebas del límite,
  5 fallos en 15 minutos activan exactamente 15 minutos de pausa para la combinación
  afectada; se verifican los instantes anteriores y posteriores al vencimiento y
  la independencia de otras combinaciones.
- **SC-006**: En una prueba de usabilidad con cinco personas con datos preparados y
  conexión disponible, al menos cuatro completan el registro en dos minutos o menos
  y el acceso en un minuto o menos, sin ayuda. Los recorridos con autocompletado y
  gestor se completan en web móvil y PWA sin transcripción manual ni pasos extra.
- **SC-007**: El 100 % de los formularios y mensajes de aceptación se puede utilizar
  desde 360 píxeles de ancho sin desplazamiento horizontal y está en español de México.

## Supuestos

- **Alcance**: Registro, acceso con correo y contraseña, cierre de la sesión actual,
  recuperación de datos guardados y autorización por cuenta. No incluye recuperación
  o cambio de contraseña, verificación de correo, acceso social, autenticación de
  varios factores, cuentas compartidas, roles administrativos ni eliminación de cuenta.
- **Valores predeterminados**: Se adoptan los límites de FR-002 y FR-003 para hacer
  verificable la validación. No se requiere aprobación ni confirmación por correo
  antes de entrar al tablero tras un registro válido.
- **Identidad del correo**: Se ignoran mayúsculas y espacios exteriores. No se fusionan
  direcciones por alias, puntos u otras reglas particulares de un proveedor.
- **Sesiones**: Cerrar sesión afecta a la sesión actual y a las pestañas que la
  compartan; no supone cerrar las sesiones independientes de otros dispositivos.
  La duración confirmada es de 24 horas de inactividad y un máximo de 7 días desde
  el inicio, según FR-016. Un nuevo inicio de sesión exitoso inicia nuevos plazos;
  no reactiva la sesión vencida ni modifica datos guardados.
- **Conectividad**: Registrar, iniciar sesión y confirmar el cierre requieren conexión.
  No se incluye acceso a datos privados sin conexión en esta funcionalidad.
- **Dependencias**: Los módulos de clientes, proyectos, cobros y reportes deben ofrecer
  los flujos que se protegen. Esta especificación define el aislamiento y sus pruebas,
  pero no amplía sus operaciones ni diseña las pantallas del primer proyecto. Para
  verificar H2 y H3 se necesitan registros propios de dos cuentas en esos módulos.
- **Ejemplo monetario**: Ana y Luis usan el mismo mes calendario y MXN; sus cobros
  de ejemplo no tienen comisiones, impuestos ni conversiones. Se compara el total de
  cobros, sin equipararlo por regla general al monto acordado o al dinero neto recibido.
  El ejemplo no redefine cálculos monetarios ni las reglas de cierre mensual.
- **Constitución**: La constitución vigente se titula “Ingresos Freelance”; se aplica
  a Flow Balance como el mismo proyecto, sin modificarla en esta especificación.
