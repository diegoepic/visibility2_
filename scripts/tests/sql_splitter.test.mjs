/**
 * Pruebas del partidor de sentencias SQL de scripts/migrate.php.
 *
 * OJO: `sql_splitter.mjs` es un PUERTO del PHP, no el código que corre en producción.
 * Existe porque en este entorno no hay PHP CLI y el partidor es la pieza del runner donde
 * un fallo hace daño de verdad: si corta mal, ejecuta media sentencia contra el esquema.
 * Si tocas `partir_sentencias()` en el PHP, hay que tocar el puerto igual.
 *
 * Ejecutar:  node --test "scripts/tests/*.test.mjs"
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { partirSentencias } from './sql_splitter.mjs';

test('separa sentencias simples', () => {
  assert.deepEqual(partirSentencias('SELECT 1; SELECT 2;'), ['SELECT 1', 'SELECT 2']);
});

test('la última sentencia sin punto y coma también cuenta', () => {
  // phpMyAdmin no siempre cierra la última.
  assert.deepEqual(partirSentencias('SELECT 1'), ['SELECT 1']);
});

test('un punto y coma dentro de una cadena NO corta', () => {
  // El caso que rompe cualquier explode(';').
  const sql = "INSERT INTO t (a) VALUES ('hola; adios'); SELECT 2;";
  const r = partirSentencias(sql);
  assert.equal(r.length, 2);
  assert.ok(r[0].includes("'hola; adios'"));
});

test('comilla escapada con barra invertida', () => {
  const sql = "INSERT INTO t VALUES ('O\\'Higgins; SA'); SELECT 1;";
  assert.equal(partirSentencias(sql).length, 2);
});

test("comilla duplicada ('') dentro de la cadena", () => {
  const sql = "INSERT INTO t VALUES ('dijo ''hola''; y se fue'); SELECT 1;";
  const r = partirSentencias(sql);
  assert.equal(r.length, 2);
  assert.ok(r[0].includes("''hola''"));
});

test('un punto y coma dentro de un backtick tampoco corta', () => {
  // Nombres de columna raros existen en esta base.
  const sql = 'ALTER TABLE t ADD COLUMN `raro;nombre` INT; SELECT 1;';
  assert.equal(partirSentencias(sql).length, 2);
});

test('los comentarios de línea se descartan, con su punto y coma dentro', () => {
  const sql = [
    '-- esto es un comentario; con punto y coma',
    '# y este tambien; otro',
    'SELECT 1;'
  ].join('\n');
  assert.deepEqual(partirSentencias(sql), ['SELECT 1']);
});

test('"--" pegado a un número no es comentario', () => {
  // `a--1` es "a menos menos uno" en SQL, no un comentario: MySQL exige espacio después.
  const r = partirSentencias('SELECT 5--1;');
  assert.equal(r.length, 1);
  assert.ok(r[0].includes('--1'));
});

test('los comentarios de bloque se descartan', () => {
  const sql = '/* nota;\n   en varias lineas; */ SELECT 1;';
  assert.deepEqual(partirSentencias(sql), ['SELECT 1']);
});

test('los comentarios ejecutables de MySQL se conservan', () => {
  // /*!40101 ... */ los emite mysqldump y SÍ se ejecutan. Descartarlos cambiaría el volcado.
  const r = partirSentencias('/*!40101 SET NAMES utf8 */;\nSELECT 1;');
  assert.equal(r.length, 2);
  assert.ok(r[0].startsWith('/*!40101'));
});

test('DELIMITER permite triggers y procedimientos', () => {
  const sql = [
    'DELIMITER $$',
    'CREATE TRIGGER t BEFORE INSERT ON x FOR EACH ROW',
    'BEGIN',
    '  SET NEW.a = 1;',
    '  SET NEW.b = 2;',
    'END$$',
    'DELIMITER ;',
    'SELECT 1;'
  ].join('\n');
  const r = partirSentencias(sql);
  assert.equal(r.length, 2, 'el cuerpo del trigger no se debe partir por sus ; internos');
  assert.ok(r[0].includes('SET NEW.a = 1;'), 'los ; internos se conservan dentro del cuerpo');
  assert.equal(r[1], 'SELECT 1');
});

test('la palabra DELIMITER a media línea no cambia nada', () => {
  const r = partirSentencias("SELECT 'DELIMITER $$' AS x; SELECT 1;");
  assert.equal(r.length, 2);
});

test('los espacios en blanco entre sentencias no generan sentencias vacías', () => {
  assert.deepEqual(partirSentencias(';;\n\n  ;SELECT 1;\n\n;;'), ['SELECT 1']);
});

test('un archivo vacío o sólo comentarios no da nada que ejecutar', () => {
  assert.deepEqual(partirSentencias(''), []);
  assert.deepEqual(partirSentencias('-- nada\n\n/* nada */\n'), []);
});
