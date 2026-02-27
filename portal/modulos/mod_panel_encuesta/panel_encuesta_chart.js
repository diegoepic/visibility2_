(function($){
  const PE = window.PE;

  // ========= Inicializaci¨®n =========
  // Este m¨®dulo carga al final para garantizar que core, filters, presets y export
  // ya hayan registrado sus funciones en window.PE antes de llamarlas.
  PE.initPreguntaSelect2();
  PE.refreshPresetsMenu();
  PE.renderActiveFilters();

  // runRedBullAutofill usa metadata embebida desde PEConfig (sin esperar 'preguntas-ready')
  PE.runRedBullAutofill();

})(jQuery);
