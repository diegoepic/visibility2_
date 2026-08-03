PERFECT STORE STUDIO - EDITOR PHP
=================================

Esta versión no requiere Node.js, npm ni Passenger.

ACTUALIZACIÓN DEL SERVIDOR
--------------------------
Reemplaza el contenido de:

public_html/visibility2/portal/modulos/mod_create_dashboard/

por el contenido de este paquete, conservando la estructura:

mod_create_dashboard/
  index.php
  assets/
  render/
  api/
  uploads/

La carpeta uploads debe permitir escritura al usuario de PHP. Normalmente 755
es suficiente. No uses permisos 777.

PRUEBAS
-------
Interfaz:
https://visibility.cl/visibility2/portal/modulos/mod_create_dashboard/

Comprobación PHP:
https://visibility.cl/visibility2/portal/modulos/mod_create_dashboard/api/health.php

FUNCIONAMIENTO
--------------
1. Selecciona una plantilla y presiona "Usar esta plantilla".
2. Selecciona división, subdivisión, actividad/gestión y desde qué fecha buscar información.
3. El módulo replica Estado visita, Estado actividad y Motivo desde descargar_excel.php.
4. El menú Estados reúne todas sus opciones dentro de un solo desplegable.
5. El menú Encuestas usa form_questions y form_question_responses, como descargar_encuesta_csv.php.
6. Agrega estados, encuestas, títulos e imágenes al lienzo como fuentes de KPI.
7. Mueve y redimensiona elementos o cambia sus propiedades.
8. "Descargar diseño" genera un archivo JSON con el borrador completo.

FORMAS DEL LIENZO
-----------------
El menu "+ Formas" permite agregar rectangulos, rectangulos redondeados,
circulos/elipses, lineas, flechas, triangulos y rombos. Todas las formas se
pueden mover, redimensionar, rotar, ordenar por capas y personalizar con color
de relleno, contorno, grosor y estilo de linea.

CONFIGURACION DE KPI DE ENCUESTAS
---------------------------------
Al pulsar "Agregar como KPI", el editor consulta solamente las respuestas de
esa pregunta. Detecta preguntas Si/No, numericas y de seleccion multiple.

- Si/No y seleccion multiple: permite elegir una o varias respuestas y cuenta
  locales distintos mediante id_local.
- Si/No y seleccion multiple también pueden calcular un ratio: locales distintos
  que cumplen la respuesta dividido por el total de id_local visitados en el
  mismo alcance y desde la fecha inicial del informe.
- Numericas: permite calcular promedio, suma, minimo, maximo o locales distintos.
- La ficha creada guarda la pregunta, metrica y respuestas en el JSON.

FILTRO DE FECHA EN EL LIENZO
----------------------------
El boton "+ Filtro fecha" agrega un control independiente al lienzo. Se puede
mover, redimensionar y configurar desde el panel de propiedades. El JSON lo
guarda como filtro global de fecha de visita aplicable a todos los KPI.

La fecha solicitada al definir el alcance limita la información base del
informe: gestiones desde formularioQuestion.fechaVisita y respuestas desde
form_question_responses.created_at. Los KPI no pueden recuperar datos anteriores
a esa fecha inicial.

KPI DE ESTADOS
--------------
Cada estado se agrega como una ficha KPI y muestra locales distintos. El valor
se obtiene contando una sola vez cada id_local asociado al estado.

El estado PLANIFICADO reúne los id_local con fechaPropuesta desde la fecha del
informe y también los locales visitados del período. Todos los estados permiten
elegir conteo distinto o ratio sobre el total PLANIFICADO; por ejemplo,
VISITADO / PLANIFICADO.

KPI DE MATERIALES
-----------------
El desplegable Materiales usa formularioQuestion.material. Para cada material
muestra SUM(valor) como cantidad implementada, SUM(valor_propuesto) como
cantidad planificada y el ratio implementado / planificado. Los tres valores y
la métrica seleccionada quedan guardados en el JSON.

KPI DE INFORMACION
------------------
El desplegable Informacion agrega indicadores de comunas unicas, regiones
unicas y usuarios unicos. Los conteos se realizan por sus identificadores dentro
de la division, subdivision y periodo seleccionados.

ALCANCE TODAS LAS SUBDIVISIONES
-------------------------------
El selector de subdivision incluye "Todas". Esta opcion filtra solamente por
division e incluye todas las subdivisiones y los formularios cuyo campo de
subdivision este vacio o sea NULL.

FILTRO DE ACTIVIDAD O GESTION
-----------------------------
Después de seleccionar la subdivisión se carga un listado de formulario.nombre.
Se puede elegir una actividad concreta o "Todas las actividades / gestiones".
El id_formulario seleccionado se aplica a estados, materiales, información,
encuestas, opciones de respuesta, conteos y ratios, y se guarda en el JSON.

GRAFICOS KPI
------------
El botón "+ Gráfico" permite crear visualizaciones de dona, barras y línea.
El indicador se configura y calcula dentro del mismo diálogo, sin crear una
ficha KPI intermedia. Se puede elegir un Estado (VISITADO, IMPLEMENTADO,
PLANIFICADO, etc.) o una pregunta de Encuesta y sus respuestas.

En Estados se pueden marcar hasta ocho indicadores para compararlos dentro del
mismo gráfico. Por ejemplo, VISITADO y NO VISITADO generan dos barras por cada
región, o dos líneas por fecha, con colores y leyenda independientes. La API
lee los datos base una sola vez para calcular todas las series seleccionadas.

El configurador muestra un catálogo con buscador, contador de KPI seleccionados
y acción para seleccionar o quitar los resultados visibles. Los KPI estándar
PLANIFICADO, VISITADO, NO VISITADO, IMPLEMENTADO y NO IMPLEMENTADO permanecen
disponibles aunque su valor actual sea cero.

Los resultados pueden agruparse por región o por fecha de visita/respuesta.
Cada id_local se cuenta una sola vez dentro de cada región o día. En preguntas
numéricas también están disponibles promedio y suma. Las series temporales
incluyen con valor cero los días intermedios sin actividad.

Desde Propiedades se puede cambiar entre dona, barras y línea, editar una
paleta de cuatro colores y mover la leyenda de la dona arriba, abajo, izquierda
o derecha, además de ocultarla. La configuración y la serie calculada quedan
guardadas en el JSON del diseño.

SEGURIDAD
---------
La API reutiliza la sesión de Visibility y la conexión existente:
/visibility2/portal/con_.php

No se incluyen credenciales dentro de este módulo.
Los archivos permitidos son JPG, PNG y WEBP, con máximo de 10 MB.
