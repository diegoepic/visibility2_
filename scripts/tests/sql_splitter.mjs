// Puerto fiel de partir_sentencias() de scripts/migrate.php, para poder probar el
// algoritmo sin PHP. Si esto se cambia, hay que cambiar el PHP igual.
import fs from 'node:fs';

export function partirSentencias(sql) {
  const sentencias = [];
  let buffer = '';
  let delim = ';';
  let i = 0;
  const n = sql.length;
  const esEspacio = (c) => c !== undefined && /\s/.test(c);

  while (i < n) {
    const c = sql[i];

    // comentario de linea
    if ((c === '-' && sql.substr(i, 2) === '--' && (i + 2 >= n || esEspacio(sql[i + 2]) || sql[i + 2] === '-')) || c === '#') {
      const fin = sql.indexOf('\n', i);
      i = fin === -1 ? n : fin + 1;
      continue;
    }

    // comentario de bloque
    if (c === '/' && sql.substr(i, 2) === '/*') {
      const ejecutable = i + 2 < n && sql[i + 2] === '!';
      const fin = sql.indexOf('*/', i + 2);
      const hasta = fin === -1 ? n : fin + 2;
      if (ejecutable) buffer += sql.substring(i, hasta);
      i = hasta;
      continue;
    }

    // DELIMITER
    if ((c === 'D' || c === 'd') && (i === 0 || sql[i - 1] === '\n' || sql[i - 1] === '\r')) {
      const m = /^DELIMITER[ \t]+(\S+)[ \t]*\r?\n/i.exec(sql.substring(i));
      if (m) {
        if (buffer.trim() !== '') { sentencias.push(buffer.trim()); buffer = ''; }
        delim = m[1];
        i += m[0].length;
        continue;
      }
    }

    // cadenas / backticks
    if (c === "'" || c === '"' || c === '`') {
      const cierre = c;
      buffer += c;
      i++;
      while (i < n) {
        const d = sql[i];
        if (d === '\\' && cierre !== '`') { buffer += sql.substr(i, 2); i += 2; continue; }
        if (d === cierre) {
          if (i + 1 < n && sql[i + 1] === cierre) { buffer += cierre + cierre; i += 2; continue; }
          buffer += d; i++; break;
        }
        buffer += d; i++;
      }
      continue;
    }

    // fin de sentencia
    if (delim !== '' && sql.substr(i, delim.length) === delim) {
      if (buffer.trim() !== '') sentencias.push(buffer.trim());
      buffer = '';
      i += delim.length;
      continue;
    }

    buffer += c;
    i++;
  }

  if (buffer.trim() !== '') sentencias.push(buffer.trim());
  return sentencias;
}

if (process.argv[2]) {
  for (const f of process.argv.slice(2)) {
    const s = partirSentencias(fs.readFileSync(f, 'utf8'));
    console.log(`\n=== ${f}  ->  ${s.length} sentencias ===`);
    s.forEach((x, k) => console.log(`  ${String(k + 1).padStart(2)}. ${x.replace(/\s+/g, ' ').slice(0, 110)}`));
  }
}
