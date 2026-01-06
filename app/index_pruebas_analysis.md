# Observaciones sobre `app/index_pruebas.php`

## Locales programados que desaparecen antes de tiempo
La tabla de "Locales Programados" solo incluye grupos donde **todas** las campañas tienen `countVisita = 0` (`HAVING ... > 0`). En cuanto alguna campaña del local pasa a "en proceso" o incrementa `countVisita`, el local deja de aparecer aunque existan otras campañas pendientes. Esto provoca que un local con varias campañas ya gestionado parcialmente no se muestre para completar las restantes.

- Consulta de programados: `HAVING SUM(CASE WHEN fq.countVisita = 0 THEN 1 ELSE 0 END) > 0` restringe la fila a campañas con visitas en 0.【F:app/index_pruebas.php†L158-L189】
- Actualizaciones de gestión incrementan `countVisita`, por lo que basta con tocar una campaña para que todas queden fuera del criterio de la tabla.

## Modal combina campañas reagendadas y nuevas
El modal por local se llena con campañas donde `countVisita = 0` **o** `pregunta = 'en proceso'`, sin distinguir si el local proviene del panel de programados o del de reagendados. Así, un local reagendado puede mostrar campañas que nunca se reagendaron (siguen en 0) y viceversa, mezclando contextos y confundiendo el número de pendientes.

- Consulta del modal: incluye ambos estados en el `WHERE` (`fq.countVisita = 0 OR fq.pregunta = 'en proceso'`).【F:app/index_pruebas.php†L1160-L1193】
- Consulta de reagendados: se alimenta exclusivamente de registros `pregunta = 'en proceso'`, por lo que la discrepancia viene al construir el modal.【F:app/index_pruebas.php†L226-L259】

## Conteo mostrado vs. campañas listadas
El círculo de la tabla usa `COUNT` y `GROUP_CONCAT` solo sobre campañas con `countVisita = 0`, pero el modal lista también las que están "en proceso". El número visible puede ser menor que la cantidad de campañas que se ofrecen al abrir el modal, generando un desfase en la interfaz.

- Total y `campanasIds` de la tabla solo consideran `countVisita = 0`.【F:app/index_pruebas.php†L170-L210】
- El modal añade campañas en proceso, alterando la expectativa del usuario sobre cuántas quedan pendientes.【F:app/index_pruebas.php†L1160-L1193】

## Mejoras sugeridas
- Alinear los filtros de la tabla, el conteo y el modal para que usen la misma definición de "pendiente" (por ejemplo, incluir `pregunta = 'en proceso'` en el listado principal o excluirlas del modal según corresponda).
  - **Qué problema resuelve:** ahora la tabla y el contador solo consideran campañas con `countVisita = 0`, mientras que el modal también incluye las que están `en proceso`. El usuario ve un número menor en la tabla que el detalle que se abre, generando confusión.
  - **Cómo aplicarlo:** decide una única regla de pendiente (p. ej., `countVisita = 0 OR pregunta = 'en proceso'`) y úsala en la consulta principal que alimenta la tabla, el `COUNT` del círculo y el `GROUP_CONCAT` de ids. Alternativamente, si solo se quiere mostrar `countVisita = 0`, el modal debería filtrar con la misma condición para no agregar campañas en proceso.

- Separar modales por contexto: uno para programados y otro para reagendados, evitando mezclar campañas con estados distintos en el mismo local.
  - **Qué problema resuelve:** el modal actual junta en una sola lista campañas que están en proceso (reagendados) con campañas nunca gestionadas (programados), aunque provengan de paneles distintos. El usuario puede abrir un reagendado y ver campañas nuevas que no le tocan en ese flujo.
  - **Cómo aplicarlo:** genera el modal a partir del origen de la fila: si viene de "Locales Programados", arma el modal solo con `countVisita = 0`; si viene de "Locales Reagendados", limita a `pregunta = 'en proceso'`. Esto implica duplicar el modal o parametrizar la plantilla para que reciba el contexto y aplique un `WHERE` coherente.

- Ajustar la lógica de desaparición del local: mantenerlo visible mientras quede cualquier campaña sin gestionar, incluso si otras ya avanzaron de estado.
  - **Qué problema resuelve:** basta con gestionar una campaña para que todas las del local desaparezcan de "Programados", aunque queden pendientes. El usuario no puede terminar el resto porque el local ya no aparece.
  - **Cómo aplicarlo:** en la consulta de programados, usa un `HAVING` que acepte filas con al menos una campaña pendiente (no que todas estén en cero). Por ejemplo, reemplazar `SUM(CASE WHEN fq.countVisita = 0 THEN 1 ELSE 0 END) > 0` por un conteo de pendientes mayor a 0, sin exigir que todas cumplan. Complementa con la alineación de filtros anterior para que el criterio de pendiente sea el mismo que el modal y el contador.
