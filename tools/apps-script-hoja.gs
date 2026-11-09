/**
 * CRM de las charlas de Autoría · Firma lo que sabes
 *
 * Recibe los envíos de las landings de las charlas y los apunta en esta hoja:
 * Leads (diagnósticos), Propuestas (dinámica, sin datos personales),
 * Inscripciones (QR del flyer) y Contadores.
 *
 * Copia de referencia: el código que vale es el pegado en la hoja
 * (Extensiones > Apps Script). Si se cambia, hay que publicar una versión nueva:
 * Implementar > Gestionar implementaciones > lápiz > Versión: Nueva versión > Implementar.
 * La URL que acaba en /exec no cambia.
 *
 * Instalación desde cero, una sola vez:
 *   1. Extensiones > Apps Script. Pega este código y guarda.
 *   2. Elige la función configurar y pulsa Ejecutar. Acepta los permisos.
 *      En el registro de ejecución sale el token.
 *   3. Implementar > Nueva implementación > Aplicación web.
 *      Ejecutar como: yo. Quién tiene acceso: cualquier usuario.
 *   4. La URL que acaba en /exec y el token se guardan en /panel?vista=hoja.
 */

var PESTANAS = { leads: 'Leads', propuestas: 'Propuestas', inscripciones: 'Inscripciones', contadores: 'Contadores' };
var COLUMNA_ID = { leads: 15, propuestas: 7, inscripciones: 8 }; // columna O de Leads, G de Propuestas y H de Inscripciones

function configurar() {
  var propiedades = PropertiesService.getScriptProperties();
  var token = propiedades.getProperty('TOKEN');
  if (!token) {
    token = Utilities.getUuid().replace(/-/g, '') + Utilities.getUuid().replace(/-/g, '');
    propiedades.setProperty('TOKEN', token);
  }
  var libro = SpreadsheetApp.getActiveSpreadsheet();
  Object.keys(PESTANAS).forEach(function (clave) {
    if (!libro.getSheetByName(PESTANAS[clave])) throw new Error('Falta la pestaña ' + PESTANAS[clave]);
  });
  Logger.log('Token: ' + token);
}

function doGet() {
  return respuesta_({ ok: true, servicio: 'CRM de las charlas de Autoría' });
}

function doPost(e) {
  var datos;
  try {
    datos = JSON.parse(e.postData.contents);
  } catch (error) {
    return respuesta_({ ok: false, error: 'json' });
  }
  var token = PropertiesService.getScriptProperties().getProperty('TOKEN');
  if (!token || !datos || datos.token !== token) return respuesta_({ ok: false, error: 'token' });

  var candado = LockService.getScriptLock();
  if (!candado.tryLock(15000)) return respuesta_({ ok: false, error: 'ocupado' });
  try {
    var libro = SpreadsheetApp.getActiveSpreadsheet();
    if (datos.tipo === 'diagnostico') return respuesta_(guardarDiagnostico_(libro.getSheetByName(PESTANAS.leads), datos));
    if (datos.tipo === 'propuesta') return respuesta_(guardarPropuesta_(libro.getSheetByName(PESTANAS.propuestas), datos));
    if (datos.tipo === 'registro') return respuesta_(guardarInscripcion_(libro.getSheetByName(PESTANAS.inscripciones), datos));
    if (datos.tipo === 'contadores') return respuesta_(guardarContadores_(libro.getSheetByName(PESTANAS.contadores), datos));
    return respuesta_({ ok: false, error: 'tipo' });
  } catch (error) {
    return respuesta_({ ok: false, error: String(error) });
  } finally {
    candado.releaseLock();
  }
}

// Leads: A Fecha · B Charla · C Nombre · D Correo · E Red · F Perfil · G Propuesta de valor ·
// H Consentimiento · I Estado · J Encargado · K a N las rellena el equipo · O ID
function guardarDiagnostico_(hoja, d) {
  var id = texto_(d.id);
  if (id && existeId_(hoja, COLUMNA_ID.leads, id)) return { ok: true, duplicado: true };
  var fila = ultimaFila_(hoja) + 1;
  var consentimiento = d.consentimiento === true || String(d.consentimiento).toLowerCase() === 'true';
  hoja.getRange(fila, 1, 1, 10).setValues([[
    fecha_(d.fecha), texto_(d.charla), texto_(d.nombre), texto_(d.correo), texto_(d.red),
    texto_(d.perfil), texto_(d.propuesta), consentimiento ? 'Sí' : 'No', 'Pendiente', 'Mario'
  ]]);
  hoja.getRange(fila, COLUMNA_ID.leads).setValue(id);
  return { ok: true, fila: fila };
}

// Propuestas: A Fecha · B Charla · C Para · D Aporto · E Gracias a · F fórmula de la hoja · G ID
function guardarPropuesta_(hoja, d) {
  var id = texto_(d.id);
  if (id && existeId_(hoja, COLUMNA_ID.propuestas, id)) return { ok: true, duplicado: true };
  var fila = ultimaFila_(hoja) + 1;
  hoja.getRange(fila, 1, 1, 5).setValues([[
    fecha_(d.fecha), texto_(d.charla), texto_(d.para), texto_(d.aporto), texto_(d.gracias_a)
  ]]);
  hoja.getRange(fila, COLUMNA_ID.propuestas).setValue(id);
  return { ok: true, fila: fila };
}

// Inscripciones: A Fecha · B Charla · C Nombre y apellidos · D Correo · E Asignatura ·
// F Negocio o trabajo fuera de la universidad · G Consentimiento · H ID
function guardarInscripcion_(hoja, d) {
  var id = texto_(d.id);
  if (id && existeId_(hoja, COLUMNA_ID.inscripciones, id)) return { ok: true, duplicado: true };
  var fila = ultimaFila_(hoja) + 1;
  var consentimiento = d.consentimiento === true || String(d.consentimiento).toLowerCase() === 'true';
  hoja.getRange(fila, 1, 1, 8).setValues([[
    fecha_(d.fecha), texto_(d.charla), texto_(d.nombre), texto_(d.correo), texto_(d.asignatura),
    texto_(d.negocio_o_trabajo), consentimiento ? 'Sí' : 'No', id
  ]]);
  return { ok: true, fila: fila };
}

// Contadores: una fila por charla. A Charla · B Visitas a la dinámica · C Visitas al diagnóstico ·
// D Pulsaciones en «Unirme a la comunidad» · E Actualizado · F Visitas a la inscripción
function guardarContadores_(hoja, d) {
  var charla = texto_(d.charla);
  if (!charla) return { ok: false, error: 'charla' };
  var ultima = ultimaFila_(hoja);
  var fila = ultima + 1;
  if (ultima >= 2) {
    var codigos = hoja.getRange(2, 1, ultima - 1, 1).getValues();
    for (var i = 0; i < codigos.length; i++) {
      if (String(codigos[i][0]) === charla) { fila = i + 2; break; }
    }
  }
  hoja.getRange(fila, 1, 1, 6).setValues([[
    charla, numero_(d.visitas_propuesta), numero_(d.visitas_diagnostico), numero_(d.clics_comunidad), new Date(),
    numero_(d.visitas_registro)
  ]]);
  return { ok: true, fila: fila };
}

// Última fila con algo escrito en la columna A (la fecha o la charla).
function ultimaFila_(hoja) {
  var valores = hoja.getRange('A:A').getValues();
  for (var i = valores.length - 1; i >= 0; i--) {
    if (valores[i][0] !== '' && valores[i][0] !== null) return i + 1;
  }
  return 1;
}

function existeId_(hoja, columna, id) {
  var ultima = ultimaFila_(hoja);
  if (ultima < 2) return false;
  var ids = hoja.getRange(2, columna, ultima - 1, 1).getValues();
  for (var i = 0; i < ids.length; i++) {
    if (String(ids[i][0]) === id) return true;
  }
  return false;
}

// Texto limpio y corto. Si empieza por = + - @ se guarda como texto y no como fórmula.
function texto_(valor) {
  var s = (valor === undefined || valor === null) ? '' : String(valor).trim().slice(0, 500);
  if (/^[=+\-@]/.test(s)) s = "'" + s;
  return s;
}

function numero_(valor) {
  var n = Number(valor);
  return isFinite(n) && n >= 0 ? Math.round(n) : 0;
}

function fecha_(valor) {
  var f = valor ? new Date(valor) : new Date();
  return isNaN(f.getTime()) ? new Date() : f;
}

function respuesta_(objeto) {
  return ContentService.createTextOutput(JSON.stringify(objeto)).setMimeType(ContentService.MimeType.JSON);
}
