<?php
// propuesta.php (se abre como /api/propuesta) — guarda, de forma anónima, la
// propuesta de valor que escriben los asistentes a una charla
// (charla-propuesta.html) y la copia en la hoja de Google de las charlas.
// No pide nombre ni correo y no avisa por email.
define('AUTORIA', 1);
require __DIR__ . '/_comun.php';

solo_post();
$in = entrada_json();

$para = clean_str($in['para'] ?? '', 90);
$aporto = clean_str($in['aporto'] ?? '', 90);
$gracias = clean_str($in['gracias_a'] ?? '', 90);
if ($para === '' || $aporto === '' || $gracias === '') json_out(['ok' => false, 'reason' => 'datos']);

// Un aula entera puede salir a internet con la misma IP: límite por IP generoso.
$limit = rate_limit('propuesta', 300, 1500, 5000);
if ($limit !== null) json_out(['ok' => false, 'reason' => 'rate_' . $limit]);

// El id lo pone el móvil: si un reintento llega dos veces, no se guarda ni se copia otra vez.
$id = preg_replace('/[^a-zA-Z0-9-]/', '', clean_str($in['id'] ?? '', 40));
$id = 'p-' . ($id !== '' ? $id : nuevo_id());
if (registro_existe($id)) json_out(['ok' => true]);

$row = [
  'id' => $id, 'fecha' => fecha_envio($in['fecha'] ?? ''), 'recibida' => ahora(), 'tipo' => 'propuesta',
  'charla' => codigo_charla($in['charla'] ?? ''), 'para' => $para, 'aporto' => $aporto, 'gracias_a' => $gracias,
];
$hoja = ['tipo' => 'propuesta', 'id' => $id, 'fecha' => $row['fecha'], 'charla' => $row['charla'], 'para' => $para, 'aporto' => $aporto, 'gracias_a' => $gracias];

// Primero se guarda en el servidor y se contesta; la hoja va después, sin hacer esperar.
if (store_record($row)) {
  json_out_y_seguir(['ok' => true]);
  hoja_registrar($hoja);
  hoja_contadores($row['charla']);
  exit;
}
json_out(['ok' => hoja_post($hoja) === '']);   // si no se pudo guardar en el servidor, al menos en la hoja
