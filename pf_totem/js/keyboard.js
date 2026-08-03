/* ============================================================
   PFKeyboard — teclado táctil propio para el formulario del
   ganador. No dependemos del teclado del sistema: en un tótem
   en modo kiosko (Android WebView o Chrome kiosk) el teclado
   nativo es impredecible. Los inputs van con readonly.
   Modos: 'text' | 'email' | 'tel'
   ============================================================ */
window.PFKeyboard = (function () {
  'use strict';

  var container = null;
  var activeInput = null;
  var shift = true; // primera letra en mayúscula por defecto
  var onDone = null;

  var ROWS_TEXT = [
    ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'],
    ['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p'],
    ['a', 's', 'd', 'f', 'g', 'h', 'j', 'k', 'l', 'ñ'],
    ['SHIFT', 'z', 'x', 'c', 'v', 'b', 'n', 'm', 'BACK'],
    ['ESPACIO', 'LISTO'],
  ];
  var ROWS_EMAIL = [
    ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'],
    ['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p'],
    ['a', 's', 'd', 'f', 'g', 'h', 'j', 'k', 'l', 'ñ'],
    ['@', 'z', 'x', 'c', 'v', 'b', 'n', 'm', 'BACK'],
    ['.', '-', '_', '.com', '.cl', 'LISTO'],
  ];
  var ROWS_TEL = [
    ['1', '2', '3'],
    ['4', '5', '6'],
    ['7', '8', '9'],
    ['+', '0', 'BACK'],
    ['LISTO'],
  ];

  function render(mode) {
    var rows = mode === 'email' ? ROWS_EMAIL : mode === 'tel' ? ROWS_TEL : ROWS_TEXT;
    container.innerHTML = '';
    container.className = 'osk osk-' + mode;
    rows.forEach(function (row) {
      var div = document.createElement('div');
      div.className = 'osk-row';
      row.forEach(function (key) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'osk-key';
        b.dataset.key = key;
        if (key === 'BACK') { b.textContent = '⌫'; b.classList.add('osk-back'); }
        else if (key === 'SHIFT') { b.textContent = '⇧'; b.classList.add('osk-shift'); }
        else if (key === 'ESPACIO') { b.textContent = 'espacio'; b.classList.add('osk-space'); }
        else if (key === 'LISTO') { b.textContent = 'Listo ✓'; b.classList.add('osk-done'); }
        else { b.textContent = key; if (key.length > 1) b.classList.add('osk-wide'); }
        div.appendChild(b);
      });
      container.appendChild(div);
    });
    updateShiftView();
  }

  function updateShiftView() {
    if (!container) return;
    container.querySelectorAll('.osk-key').forEach(function (b) {
      var k = b.dataset.key;
      if (k.length === 1 && /[a-zñ]/.test(k)) b.textContent = shift ? k.toUpperCase() : k;
      if (k === 'SHIFT') b.classList.toggle('osk-on', shift);
    });
  }

  function press(key) {
    if (!activeInput) return;
    var v = activeInput.value;
    if (key === 'BACK') {
      activeInput.value = v.slice(0, -1);
    } else if (key === 'SHIFT') {
      shift = !shift; updateShiftView(); return;
    } else if (key === 'ESPACIO') {
      activeInput.value = v + ' ';
      shift = true; updateShiftView(); // mayúscula tras espacio (nombres)
    } else if (key === 'LISTO') {
      if (onDone) onDone(activeInput);
      return;
    } else {
      var ch = key;
      if (ch.length === 1 && /[a-zñ]/.test(ch) && shift) {
        ch = ch.toUpperCase(); shift = false; updateShiftView();
      }
      var max = parseInt(activeInput.getAttribute('maxlength') || '80', 10);
      if ((v + ch).length <= max) activeInput.value = v + ch;
    }
    activeInput.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function init(el, doneCb) {
    container = el;
    onDone = doneCb || null;
    container.addEventListener('pointerdown', function (e) {
      var b = e.target.closest('.osk-key');
      if (!b) return;
      e.preventDefault();
      b.classList.add('osk-tap');
      setTimeout(function () { b.classList.remove('osk-tap'); }, 120);
      if (window.PFAudio) PFAudio.tick();
      press(b.dataset.key);
    });
  }

  function attach(input, mode) {
    activeInput = input;
    shift = (mode === 'text' && input.value === '');
    render(mode);
    document.querySelectorAll('.osk-input').forEach(function (i) {
      i.classList.toggle('osk-focus', i === input);
    });
  }

  function detach() {
    activeInput = null;
    if (container) container.innerHTML = '';
    document.querySelectorAll('.osk-input').forEach(function (i) { i.classList.remove('osk-focus'); });
  }

  return { init: init, attach: attach, detach: detach };
})();
