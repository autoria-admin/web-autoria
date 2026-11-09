<?php
// panel.php (se abre como /panel) — panel privado con los contactos y
// diagnósticos recibidos. Equivale a api/_panel.js. La contraseña se elige en
// la página de instalación (/instalar) y se guarda cifrada fuera de la web.
define('AUTORIA', 1);
require __DIR__ . '/_comun.php';

// Inscripciones de una charla, una por correo (la más reciente), que aún no han
// recibido la confirmación o el recordatorio. Los correos de prueba (example.com) no cuentan.
function correos_por_enviar($charla, $clase) {
  $log = correos_registro();
  $hecho = [];
  foreach ($log as $e) if (!empty($e[$clase]['ok']) && ($e['charla'] ?? '') === $charla) $hecho[$e['email'] ?? ''] = true;
  $out = []; $vistos = [];
  foreach (read_records() as $r) {   // los más recientes primero
    if (($r['tipo'] ?? '') !== 'charla-registro' || ($r['charla'] ?? '') !== $charla) continue;
    $em = strtolower((string) ($r['email'] ?? ''));
    if (isset($vistos[$em]) || isset($hecho[$em])) continue;
    $vistos[$em] = true;
    if (preg_match('/@(.+\.)?example\.(com|org|net)$/i', $em)) continue;
    $out[] = $r;
  }
  return $out;
}

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'secure' => $https, 'samesite' => 'Strict']);
session_name('autoria_panel');
session_start();

$hash = config()['panel_hash'] ?? '';
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$error = '';

if (isset($_GET['salir'])) { $_SESSION = []; session_destroy(); header('Location: /panel', true, 303); exit; }

// Tras iniciar sesión se vuelve a la vista pedida (p. ej. /panel?vista=hoja), solo si es una de las del panel.
$vistaPedida = (string) ($_GET['vista'] ?? '');
$volver = in_array($vistaPedida, ['hoja', 'charlas', 'propuestas', 'registros', 'avisos'], true) ? '/panel?vista=' . $vistaPedida : '/panel';
$cPedido = codigo_charla(str_replace(' ', '_', (string) ($_GET['c'] ?? '')));   // también la charla elegida, si la hay
if ($volver !== '/panel' && $cPedido !== '') $volver .= '&c=' . rawurlencode(($_GET['c'] ?? '') === 'SIN CÓDIGO' ? 'SIN CÓDIGO' : $cPedido);

// Ajustes de los avisos por email (solo con la sesión iniciada).
$avisoMsg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_SESSION['ok']) && $hash !== '' && isset($_POST['accion'])) {
  if ($_POST['accion'] === 'guardar_smtp') {
    $u = clean_str($_POST['smtp_user'] ?? '', 160);
    $pw = preg_replace('/\s+/', '', (string) ($_POST['smtp_pass'] ?? ''));
    if (!es_email($u) || strlen($pw) < 16) $avisoMsg = 'Revisa la cuenta de Gmail y la contraseña de aplicación (16 letras).';
    else {
      $err = smtp_gmail($u, $pw, config()['aviso_email'] ?: $u, 'Prueba de avisos — Autoría', "Los avisos de la web ya se envían por Gmail.\n");
      if ($err !== '') $avisoMsg = 'No se ha guardado. ' . $err;
      elseif (guardar_config(['smtp_user' => $u, 'smtp_pass' => $pw])) { header('Location: /panel?vista=avisos&ok=1', true, 303); exit; }
      else $avisoMsg = 'No se pudo guardar la configuración.';
    }
  } elseif ($_POST['accion'] === 'probar') {
    $ok = notify_team('Prueba de avisos — Autoría', ['mensaje' => 'Correo de prueba enviado desde el panel.', 'fecha' => ahora()]);
    header('Location: /panel?vista=avisos&' . ($ok ? 'ok=2' : 'fallo=1'), true, 303); exit;
  } elseif ($_POST['accion'] === 'guardar_hoja') {
    // URL y token del Apps Script de la hoja. El token vacío conserva el que había.
    $url = clean_str($_POST['hoja_url'] ?? '', 300);
    $tok = preg_replace('/\s+/', '', (string) ($_POST['hoja_token'] ?? ''));
    if ($tok === '') $tok = (string) (config()['hoja_token'] ?? '');
    // https siempre; http solo para probar en local con una hoja falsa.
    if (!preg_match('#^(https://|http://(localhost|127\.0\.0\.1)[:/])\S+$#i', $url)) $_SESSION['hoja_msg'] = ['err', 'La URL tiene que empezar por https:// (la que acaba en /exec).'];
    elseif ($tok === '') $_SESSION['hoja_msg'] = ['err', 'Falta el token del script.'];
    elseif (!guardar_config(['hoja_url' => $url, 'hoja_token' => $tok])) $_SESSION['hoja_msg'] = ['err', 'No se pudo guardar la configuración.'];
    else {
      $err = hoja_probar($url, $tok);
      $_SESSION['hoja_msg'] = $err === '' ? ['ok', 'Guardado. La hoja responde y el token es correcto.'] : ['err', 'Guardado, pero la prueba ha fallado: ' . $err . '.'];
    }
    header('Location: /panel?vista=hoja', true, 303); exit;
  } elseif ($_POST['accion'] === 'info_charla') {
    // Fecha, hora y lugar de una charla: va en la confirmación y en el recordatorio («Le esperamos …»).
    $cod = codigo_charla($_POST['c'] ?? '');
    $txt = preg_replace('/\s+/u', ' ', clean_str($_POST['info'] ?? '', 200));
    $info = config()['charlas_info'] ?? [];
    if (!is_array($info)) $info = [];
    if ($txt === '') unset($info[$cod]); else $info[$cod] = $txt;
    $_SESSION['reg_msg'] = guardar_config(['charlas_info' => $info]) ? ['ok', 'Guardado.'] : ['err', 'No se pudo guardar la configuración.'];
    header('Location: /panel?vista=registros&c=' . rawurlencode($cod ?: 'SIN CÓDIGO'), true, 303); exit;
  } elseif ($_POST['accion'] === 'correos_registro') {
    // Envía la tanda siguiente de confirmaciones pendientes o del recordatorio de una charla.
    @set_time_limit(120);
    $cod = codigo_charla($_POST['c'] ?? '');
    $clase = ($_POST['clase'] ?? '') === 'recordatorio' ? 'recordatorio' : 'confirmacion';
    if ($clase === 'recordatorio' && charla_info($cod) === '') $_SESSION['reg_msg'] = ['err', 'Antes de enviar el recordatorio, escriba la fecha, la hora y el lugar de la charla.'];
    else {
      $faltan = correos_por_enviar($cod, $clase);
      $hechos = 0; $fallos = []; $t0 = microtime(true);
      foreach (array_slice($faltan, 0, 25) as $r) {
        if (microtime(true) - $t0 > 50) break;
        $err = enviar_correo_registro($clase, $r);
        if ($err === '') $hechos++; else $fallos[] = $r['email'] . ': ' . $err;
      }
      $quedan = count(correos_por_enviar($cod, $clase));
      $nombre = $clase === 'recordatorio' ? 'Recordatorios' : 'Confirmaciones';
      $_SESSION['reg_msg'] = [$fallos ? 'err' : 'ok', $nombre . ' enviados: ' . $hechos . '.' . ($quedan ? ' Quedan ' . $quedan . ': vuelva a pulsar el botón.' : '') . ($fallos ? ' No se pudieron enviar: ' . implode(' · ', array_slice($fallos, 0, 5)) . '.' : '')];
    }
    header('Location: /panel?vista=registros&c=' . rawurlencode($cod ?: 'SIN CÓDIGO'), true, 303); exit;
  } elseif ($_POST['accion'] === 'reenviar_hoja') {
    // Reenvío a mano: todos los pendientes que quepan en un minuto y los contadores de cada charla.
    @set_time_limit(120);
    if (!hoja_configurada()) $_SESSION['hoja_msg'] = ['err', 'Primero hay que guardar la URL y el token de la hoja.'];
    else {
      $r = hoja_reintentar(1000, 60);
      $n = 0;
      foreach (array_keys(leer_visitas()) as $k) if ($k !== '' && hoja_contadores((string) $k, true)) $n++;
      $txt = $r['ocupado'] ? 'Otro envío estaba reenviando pendientes en este momento; vuelva a intentarlo en un minuto.'
        : 'Reenviados: ' . $r['enviados'] . '. Siguen pendientes: ' . $r['quedan'] . '. Contadores actualizados: ' . $n . ' charlas.';
      $_SESSION['hoja_msg'] = [$r['quedan'] || $r['ocupado'] ? 'err' : 'ok', $txt];
    }
    header('Location: /panel?vista=hoja', true, 303); exit;
  }
}
elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  if (rate_limit('login', 5, 30, 300) !== null) { $error = 'Demasiados intentos. Espera unos minutos.'; http_response_code(429); }
  elseif ($hash !== '' && password_verify((string) ($_POST['clave'] ?? ''), $hash)) { session_regenerate_id(true); $_SESSION['ok'] = 1; header('Location: ' . $volver, true, 303); exit; }
  else { $error = 'Contraseña incorrecta.'; http_response_code(401); }
}
$logged = !empty($_SESSION['ok']) && $hash !== '';

// Agrupar: el diagnóstico se guarda en dos líneas (datos y resultado de la IA).
$rows = [];
if ($logged) {
  $results = []; $byId = [];
  foreach (read_records() as $r) {
    $id = $r['id'] ?? '';
    if (($r['tipo'] ?? '') === 'diagnostico-resultado') { if (!array_key_exists($id, $results)) $results[$id] = $r['resultado'] ?? null; continue; }
    if (!isset($byId[$id])) $byId[$id] = $r;
  }
  foreach ($byId as $id => $r) { if (array_key_exists($id, $results)) $r['resultado'] = $results[$id]; $rows[] = $r; }
}

// Propuestas de valor de las charlas (anónimas): van en su propia vista.
$props = array_values(array_filter($rows, function ($r) { return ($r['tipo'] ?? '') === 'propuesta'; }));
$diags = array_values(array_filter($rows, function ($r) { return ($r['tipo'] ?? '') === 'charla-diagnostico'; }));
$regs = array_values(array_filter($rows, function ($r) { return ($r['tipo'] ?? '') === 'charla-registro'; }));
$rows = array_values(array_filter($rows, function ($r) { return !in_array($r['tipo'] ?? '', ['propuesta', 'charla-diagnostico', 'charla-registro'], true); }));
$vistaCharlas = $logged && (($_GET['vista'] ?? '') === 'charlas' || ($_GET['csv'] ?? '') === 'charlas');

// Resumen por código de charla: visitas (contador anónimo), propuestas y diagnósticos pedidos.
$resumen = [];
if ($vistaCharlas) {
  $fila = function () { return ['vr' => 0, 'r' => 0, 'vp' => 0, 'p' => 0, 'vd' => 0, 'd' => 0, 'wa' => 0, 'ag' => 0]; };
  foreach (leer_visitas() as $k => $v) { $k = $k ?: 'SIN CÓDIGO'; $resumen[$k] = $resumen[$k] ?? $fila(); $resumen[$k]['vr'] += (int) ($v['registro'] ?? 0); $resumen[$k]['vp'] += (int) ($v['propuesta'] ?? 0); $resumen[$k]['vd'] += (int) ($v['diagnostico'] ?? 0); $resumen[$k]['wa'] += (int) ($v['whatsapp'] ?? 0); $resumen[$k]['ag'] += (int) ($v['agenda'] ?? 0); }
  foreach ($regs as $r) { $k = ($r['charla'] ?? '') ?: 'SIN CÓDIGO'; $resumen[$k] = $resumen[$k] ?? $fila(); $resumen[$k]['r']++; }
  foreach ($props as $r) { $k = ($r['charla'] ?? '') ?: 'SIN CÓDIGO'; $resumen[$k] = $resumen[$k] ?? $fila(); $resumen[$k]['p']++; }
  foreach ($diags as $r) { $k = ($r['charla'] ?? '') ?: 'SIN CÓDIGO'; $resumen[$k] = $resumen[$k] ?? $fila(); $resumen[$k]['d']++; }
  ksort($resumen);
}
$vistaProps = $logged && (($_GET['vista'] ?? '') === 'propuestas' || ($_GET['csv'] ?? '') === 'propuestas');
$codigos = array_values(array_unique(array_map(function ($r) { return ($r['charla'] ?? '') ?: 'SIN CÓDIGO'; }, $props)));
sort($codigos);
$codigo = clean_str($_GET['c'] ?? '', 20);
if ($codigo !== '') $props = array_values(array_filter($props, function ($r) use ($codigo) { return (($r['charla'] ?? '') ?: 'SIN CÓDIGO') === $codigo; }));
function frase_propuesta($r) { return 'Para ' . ($r['para'] ?? '') . ', aporto ' . ($r['aporto'] ?? '') . ', gracias a ' . ($r['gracias_a'] ?? '') . '.'; }

$codigosD = array_keys($resumen);

// Inscripciones a las charlas (QR del flyer): su propia vista, filtrable por código y con CSV.
$vistaRegs = $logged && (($_GET['vista'] ?? '') === 'registros' || ($_GET['csv'] ?? '') === 'registros');
$codigosR = array_values(array_unique(array_map(function ($r) { return ($r['charla'] ?? '') ?: 'SIN CÓDIGO'; }, $regs)));
sort($codigosR);
if ($vistaRegs && $codigo !== '') $regs = array_values(array_filter($regs, function ($r) use ($codigo) { return (($r['charla'] ?? '') ?: 'SIN CÓDIGO') === $codigo; }));
if ($vistaRegs && ($_GET['csv'] ?? '') === 'registros') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="autoria-inscripciones-' . ($codigo !== '' ? preg_replace('/[^A-Za-z0-9_-]/', '', $codigo) . '-' : '') . date('Y-m-d') . '.csv"');
  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF");
  fputcsv($out, ['fecha', 'charla', 'nombre y apellidos', 'email', 'asignatura', 'negocio o trabajo fuera de la universidad', 'consentimiento'], ';');
  foreach ($regs as $r) fputcsv($out, [$r['fecha'] ?? '', $r['charla'] ?? '', $r['nombre'] ?? '', $r['email'] ?? '', $r['asignatura'] ?? '', $r['negocio_o_trabajo'] ?? '', $r['consentimiento'] ?? ''], ';');
  exit;
}
if ($vistaCharlas && $codigo !== '') $diags = array_values(array_filter($diags, function ($r) use ($codigo) { return (($r['charla'] ?? '') ?: 'SIN CÓDIGO') === $codigo; }));

if ($vistaCharlas && ($_GET['csv'] ?? '') === 'charlas') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="autoria-diagnosticos-charla-' . ($codigo !== '' ? preg_replace('/[^A-Za-z0-9_-]/', '', $codigo) . '-' : '') . date('Y-m-d') . '.csv"');
  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF");
  fputcsv($out, ['fecha', 'charla', 'email', 'nombre', 'red', 'perfil', 'propuesta de valor', 'consentimiento'], ';');
  foreach ($diags as $r) fputcsv($out, [$r['fecha'] ?? '', $r['charla'] ?? '', $r['email'] ?? '', $r['nombre'] ?? '', $r['red'] ?? '', $r['perfil'] ?? '', $r['propuesta'] ?? '', $r['consentimiento'] ?? ''], ';');
  exit;
}

if ($vistaProps && ($_GET['csv'] ?? '') === 'propuestas') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="autoria-propuestas-' . ($codigo !== '' ? preg_replace('/[^A-Za-z0-9_-]/', '', $codigo) . '-' : '') . date('Y-m-d') . '.csv"');
  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF");
  fputcsv($out, ['fecha', 'charla', 'para', 'aporto', 'gracias_a', 'frase'], ';');
  foreach ($props as $r) fputcsv($out, [$r['fecha'] ?? '', $r['charla'] ?? '', $r['para'] ?? '', $r['aporto'] ?? '', $r['gracias_a'] ?? '', frase_propuesta($r)], ';');
  exit;
}

if ($logged && isset($_GET['csv'])) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="autoria-contactos-' . date('Y-m-d') . '.csv"');
  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF");
  fputcsv($out, ['fecha', 'tipo', 'nombre', 'email', 'telefono', 'pais', 'motivo/objetivo', 'cargo', 'ritmo recomendado', 'indice', 'mensaje/publico', 'red del perfil', 'redes recomendadas', 'titular o bio propuesto'], ';');
  foreach ($rows as $r) {
    fputcsv($out, [
      $r['fecha'] ?? '', $r['tipo'] ?? '', $r['nombre'] ?? '', $r['email'] ?? '', $r['telefono'] ?? '', $r['pais'] ?? '',
      $r['motivo'] ?? ($r['respuestas']['objetivo'] ?? ''), $r['cargo'] ?? ($r['perfil']['cargo_actual'] ?? ''),
      $r['ritmo'] ?? ($r['plan'] ?? ''), $r['indice'] ?? '', $r['mensaje'] ?? ($r['respuestas']['publico'] ?? ''),
      $r['perfil']['red'] ?? '', implode(', ', $r['resultado']['redes'] ?? []), $r['resultado']['titular_propuesto'] ?? '',
    ], ';');
  }
  exit;
}

function fecha_es($iso) {
  try { $d = new DateTime($iso); $d->setTimezone(new DateTimeZone('Europe/Madrid')); return $d->format('d/m/Y H:i'); }
  catch (Exception $x) { return (string) $iso; }
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Panel · Autoría</title>
<style>
  :root { --paper:#F5F2EB; --paper2:#ECE7DC; --ink:#15161C; --mute:#67655F; --line:rgba(21,22,28,.14); --accent:#E9441F; }
  * { box-sizing: border-box; margin: 0; }
  body { font-family: "Instrument Sans", system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--paper); color: var(--ink); line-height: 1.5; }
  .wrap { width: min(100% - 2rem, 1200px); margin: 0 auto; padding: 2rem 0 4rem; }
  h1 { font-family: Georgia, serif; font-weight: 400; font-size: 2rem; letter-spacing: -.02em; }
  h1 span { color: var(--accent); }
  .bar { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; justify-content: space-between; padding-bottom: 1rem; border-bottom: 1px solid var(--ink); margin-bottom: 1.4rem; }
  .btn { display: inline-block; padding: .6rem 1rem; border: 1px solid var(--ink); border-radius: 3px; color: var(--ink); text-decoration: none; font-weight: 600; font-size: .9rem; background: none; cursor: pointer; font-family: inherit; }
  .btn.primary { background: var(--accent); border-color: var(--accent); }
  .login { max-width: 380px; margin: 12vh auto; display: grid; gap: 1rem; }
  input { padding: .8rem; border: 1px solid rgba(21,22,28,.3); border-radius: 3px; font: inherit; background: #fff; }
  .err { color: #B9330F; font-size: .9rem; }
  .meta { color: var(--mute); font-size: .9rem; }
  .card { background: #fff; border: 1px solid var(--line); border-radius: 4px; padding: 1rem 1.1rem; margin-bottom: .8rem; }
  .card summary { cursor: pointer; display: grid; grid-template-columns: 9rem 7rem 1fr auto; gap: .8rem; align-items: baseline; list-style: none; }
  .card summary::-webkit-details-marker { display: none; }
  .tag { font-size: .7rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; padding: .2rem .45rem; border-radius: 2px; background: var(--paper2); }
  .tag.d { background: var(--accent); }
  .who strong { display: block; }
  .detail { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--line); display: grid; gap: .3rem; font-size: .92rem; }
  .detail dt { font-size: .72rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--mute); }
  .detail dd { margin: 0 0 .6rem; white-space: pre-wrap; }
  .empty { padding: 3rem 0; text-align: center; color: var(--mute); }
  .filtro { display: flex; gap: .6rem; align-items: center; margin-bottom: 1rem; }
  select { padding: .5rem .7rem; border: 1px solid rgba(21,22,28,.3); border-radius: 3px; font: inherit; background: #fff; }
  .card.prop { display: grid; grid-template-columns: 9rem 7rem 1fr; gap: .8rem; align-items: baseline; }
  .bar.sub { margin-top: 2.4rem; }
  h2 { font-family: Georgia, serif; font-weight: 400; font-size: 1.4rem; }
  .tabla-wrap { overflow-x: auto; }
  .tabla { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid var(--line); font-size: .95rem; }
  .tabla th, .tabla td { padding: .65rem .8rem; border-bottom: 1px solid var(--line); text-align: right; white-space: nowrap; }
  .tabla th:first-child, .tabla td:first-child { text-align: left; }
  .tabla th { font-size: .7rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--mute); }
  .tabla a { color: var(--ink); font-weight: 600; }
  .nota { margin-top: .6rem; }
  .okmsg { color: #1f6b3a; font-weight: 600; margin-bottom: 1rem; }
  .login.ajustes { margin: 1.4rem 0 1rem; }
  .tabla th.izq, .tabla td.izq { text-align: left; white-space: normal; min-width: 14rem; }
  @media (max-width: 720px) { .card summary, .card.prop { grid-template-columns: 1fr; } }
</style>
</head>
<body><div class="wrap">
<?php if (!$logged): ?>
  <form class="login" method="post" action="<?php echo $e($volver); ?>" autocomplete="off">
    <h1>Autoría<span>.</span> Panel</h1>
    <p class="meta">Contactos y diagnósticos recibidos desde la web.</p>
    <?php if ($hash === ''): ?><p class="err">El panel aún no tiene contraseña. Ve a <a href="/instalar">/instalar</a> para configurarlo.</p><?php endif; ?>
    <label for="clave" class="meta">Contraseña</label>
    <input id="clave" name="clave" type="password" required autofocus>
    <button class="btn primary" type="submit">Entrar</button>
    <?php if ($error): ?><p class="err"><?php echo $e($error); ?></p><?php endif; ?>
  </form>
<?php elseif (($_GET['vista'] ?? '') === 'avisos'): $cfgA = config(); ?>
  <div class="bar">
    <div><h1>Autoría<span>.</span> Avisos por email</h1><p class="meta">Los avisos llegan a <?php echo $e(($cfgA['aviso_email'] ?? '') ?: 'ningún correo (configúralo en /instalar)'); ?></p></div>
    <div><a class="btn" href="/panel">Contactos</a> <a class="btn" href="/panel?vista=charlas">Charlas</a> <a class="btn" href="/panel?salir=1">Salir</a></div>
  </div>
  <?php if (($_GET['ok'] ?? '') === '1'): ?><p class="okmsg">Guardado. Te hemos enviado un correo de prueba.</p><?php endif; ?>
  <?php if (($_GET['ok'] ?? '') === '2'): ?><p class="okmsg">Correo de prueba enviado.</p><?php endif; ?>
  <?php if (isset($_GET['fallo'])): ?><p class="err">No se pudo enviar el correo de prueba.</p><?php endif; ?>
  <?php if ($avisoMsg): ?><p class="err"><?php echo $e($avisoMsg); ?></p><?php endif; ?>
  <p class="meta">Envío: <strong><?php echo !empty($cfgA['smtp_user']) ? 'por Gmail (' . $e($cfgA['smtp_user']) . ')' : 'por el servidor (el dominio temporal no puede enviar: Gmail lo descarta)'; ?></strong></p>
  <form class="login ajustes" method="post" action="/panel?vista=avisos" autocomplete="off">
    <input type="hidden" name="accion" value="guardar_smtp">
    <label for="smtp_user" class="meta">Cuenta de Gmail que envía los avisos</label>
    <input id="smtp_user" name="smtp_user" type="email" value="<?php echo $e(($cfgA['smtp_user'] ?? '') ?: 'admin.autoria@gmail.com'); ?>" required>
    <label for="smtp_pass" class="meta">Contraseña de aplicación de esa cuenta (16 letras)</label>
    <input id="smtp_pass" name="smtp_pass" type="password" required>
    <button class="btn primary" type="submit">Guardar y enviar prueba</button>
  </form>
  <form method="post" action="/panel?vista=avisos"><input type="hidden" name="accion" value="probar"><button class="btn" type="submit">Enviar solo un correo de prueba</button></form>
<?php elseif (($_GET['vista'] ?? '') === 'hoja'):
  $cfgH = config(); $pendH = hoja_pendientes(); $contH = hoja_estado_contadores();
  $msgH = $_SESSION['hoja_msg'] ?? null; unset($_SESSION['hoja_msg']); ?>
  <div class="bar">
    <div><h1>Autoría<span>.</span> Hoja de Google</h1><p class="meta">«CRM de las charlas»: cada envío de las charlas se copia también en la hoja</p></div>
    <div><a class="btn" href="/panel?vista=charlas">Charlas</a> <a class="btn" href="/panel">Contactos</a> <a class="btn" href="/panel?salir=1">Salir</a></div>
  </div>
  <?php if ($msgH): ?><p class="<?php echo $msgH[0] === 'ok' ? 'okmsg' : 'err'; ?>"><?php echo $e($msgH[1]); ?></p><?php endif; ?>
  <p class="meta">Estado: <strong><?php echo hoja_configurada() ? 'conectada a ' . $e(preg_replace('#^(https://[^/]+/macros/s/.{6}).*(.{6}/exec)$#', '$1…$2', (string) $cfgH['hoja_url'])) : 'sin configurar (los envíos se guardan y esperan aquí como pendientes)'; ?></strong></p>
  <form class="login ajustes" method="post" action="/panel?vista=hoja" autocomplete="off">
    <input type="hidden" name="accion" value="guardar_hoja">
    <label for="hoja_url" class="meta">URL del script (la que acaba en /exec)</label>
    <input id="hoja_url" name="hoja_url" type="url" value="<?php echo $e($cfgH['hoja_url'] ?? ''); ?>" placeholder="https://script.google.com/macros/s/…/exec" required>
    <label for="hoja_token" class="meta">Token del script<?php echo !empty($cfgH['hoja_token']) ? ' (ya hay uno guardado: déjelo vacío para conservarlo)' : ''; ?></label>
    <input id="hoja_token" name="hoja_token" type="password" autocomplete="new-password"<?php echo empty($cfgH['hoja_token']) ? ' required' : ''; ?>>
    <button class="btn primary" type="submit">Guardar y probar</button>
  </form>

  <div class="bar sub">
    <div><h2>Pendientes de la hoja</h2><p class="meta"><?php echo count($pendH); ?> envíos guardados en el servidor que aún no están en la hoja. Se reintentan solos con cada envío nuevo (hasta 20 cada vez).</p></div>
    <div><form method="post" action="/panel?vista=hoja"><input type="hidden" name="accion" value="reenviar_hoja"><button class="btn primary" type="submit">Reenviar pendientes</button></form></div>
  </div>
  <?php if ($pendH): ?>
  <div class="tabla-wrap"><table class="tabla">
    <thead><tr><th>Envío</th><th>Charla</th><th>Fecha</th><th>Intentos</th><th>Último error</th></tr></thead>
    <tbody><?php foreach (array_reverse($pendH, true) as $id => $p): $dt = $p['datos'] ?? []; ?>
      <tr><td><?php echo $e(($dt['tipo'] ?? '') === 'diagnostico' ? 'Diagnóstico · ' . ($dt['correo'] ?? '') : (($dt['tipo'] ?? '') === 'registro' ? 'Inscripción · ' . ($dt['correo'] ?? '') : 'Propuesta (anónima)')); ?></td><td><?php echo $e(($dt['charla'] ?? '') ?: '—'); ?></td><td><?php echo $e(fecha_es($dt['fecha'] ?? '')); ?></td><td><?php echo (int) ($p['intentos'] ?? 0); ?></td><td class="izq"><?php echo $e($p['error'] ?? ''); ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php else: ?><p class="meta">No hay ninguno.</p><?php endif; ?>

  <div class="bar sub"><div><h2>Contadores en la hoja</h2><p class="meta">Se envían con cada envío y cada pulsación en «Unirme a la comunidad», como mucho una vez por minuto. «Reenviar pendientes» los pone al día: conviene pulsarlo al acabar cada charla.</p></div></div>
  <?php $codH = array_values(array_filter(array_map('strval', array_keys(leer_visitas())), 'strlen')); sort($codH); ?>
  <?php if ($codH): ?>
  <div class="tabla-wrap"><table class="tabla">
    <thead><tr><th>Charla</th><th>Ahora (dinámica · diagnóstico · comunidad · inscripción)</th><th>En la hoja</th><th>Enviados</th><th>Último error</th></tr></thead>
    <tbody><?php foreach ($codH as $k): $t = hoja_totales($k); $h = $contH[$k] ?? []; $en = $h['enviados'] ?? null; ?>
      <tr><td><?php echo $e($k); ?></td><td><?php echo implode(' · ', $t); ?></td><td><?php echo $en ? implode(' · ', array_map('intval', $en)) : '—'; ?></td><td><?php echo $e(!empty($h['enviado_en']) ? fecha_es($h['enviado_en']) : '—'); ?></td><td class="izq"><?php echo $e($h['error'] ?? ''); ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php else: ?><p class="meta">Todavía no hay visitas de ninguna charla.</p><?php endif; ?>
<?php elseif ($vistaCharlas): ?>
  <div class="bar">
    <div><h1>Autoría<span>.</span> Charlas</h1><p class="meta">Visitas, inscritos, propuestas y diagnósticos pedidos por código de charla</p></div>
    <div><a class="btn" href="/panel?vista=registros">Inscripciones</a> <a class="btn" href="/panel?vista=propuestas">Propuestas</a> <a class="btn" href="/panel?vista=hoja">Hoja de Google</a> <a class="btn" href="/panel">Contactos</a> <a class="btn" href="/panel?salir=1">Salir</a></div>
  </div>
  <?php if (!$resumen): ?><p class="empty">Todavía no hay datos de ninguna charla.</p><?php else: ?>
  <div class="tabla-wrap"><table class="tabla">
    <thead><tr><th>Charla</th><th>Visitas inscripción</th><th>Inscritos</th><th>Visitas propuesta</th><th>Propuestas</th><th>Visitas diagnóstico</th><th>Diagnósticos pedidos</th><th>Pedidos / visitas</th><th>Clics en WhatsApp</th><th>Clics en la agenda</th></tr></thead>
    <tbody><?php foreach ($resumen as $k => $n): ?>
      <tr><td><a href="/panel?vista=charlas&amp;c=<?php echo rawurlencode($k); ?>"><?php echo $e($k); ?></a></td><td><?php echo $n['vr']; ?></td><td><?php echo $n['r']; ?></td><td><?php echo $n['vp']; ?></td><td><?php echo $n['p']; ?></td><td><?php echo $n['vd']; ?></td><td><?php echo $n['d']; ?></td><td><?php echo $n['vd'] ? round(100 * $n['d'] / $n['vd']) . ' %' : '—'; ?></td><td><?php echo $n['wa']; ?></td><td><?php echo $n['ag']; ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <p class="meta nota">Las visitas y los clics en WhatsApp y en la agenda se cuentan una vez por móvil y charla, sin cookies ni datos personales. Un clic no garantiza que la persona se haya unido ni que haya reservado.</p>
  <?php endif; ?>
  <div class="bar sub">
    <div><h2>Diagnósticos pedidos</h2><p class="meta"><?php echo count($diags); ?> solicitudes<?php echo $codigo !== '' ? ' de la charla ' . $e($codigo) : ''; ?> · las más recientes primero</p></div>
    <div><a class="btn primary" href="/panel?csv=charlas<?php echo $codigo !== '' ? '&amp;c=' . rawurlencode($codigo) : ''; ?>">Descargar Excel (CSV)</a></div>
  </div>
  <form class="filtro" method="get" action="/panel">
    <input type="hidden" name="vista" value="charlas">
    <label for="c" class="meta">Charla</label>
    <select id="c" name="c" onchange="this.form.submit()">
      <option value="">Todas</option>
      <?php foreach ($codigosD as $k): ?><option value="<?php echo $e($k); ?>"<?php echo $k === $codigo ? ' selected' : ''; ?>><?php echo $e($k); ?></option><?php endforeach; ?>
    </select>
    <noscript><button class="btn" type="submit">Filtrar</button></noscript>
  </form>
  <?php if (!$diags): ?><p class="empty">Todavía no ha llegado ninguna solicitud.</p><?php endif; ?>
  <?php foreach ($diags as $r): ?>
    <details class="card">
      <summary>
        <span class="meta"><?php echo $e(fecha_es($r['fecha'] ?? '')); ?></span>
        <span><span class="tag d"><?php echo $e(($r['charla'] ?? '') ?: 'Sin código'); ?></span></span>
        <span class="who"><strong><?php echo $e(($r['nombre'] ?? '') ?: '—'); ?></strong><span class="meta"><?php echo $e($r['email'] ?? ''); ?></span></span>
        <span class="meta"><?php echo $e($r['red'] ?? ''); ?></span>
      </summary>
      <dl class="detail">
        <dt>Perfil</dt><dd><?php echo $e($r['perfil'] ?? ''); ?></dd>
        <dt>Propuesta de valor</dt><dd><?php echo $e(($r['propuesta'] ?? '') ?: '—'); ?></dd>
        <dt>Consentimiento</dt><dd><?php echo $e($r['consentimiento'] ?? '—'); ?></dd>
      </dl>
    </details>
  <?php endforeach; ?>
<?php elseif ($vistaRegs): ?>
  <div class="bar">
    <div><h1>Autoría<span>.</span> Inscripciones</h1><p class="meta"><?php echo count($regs); ?> inscritos<?php echo $codigo !== '' ? ' en la charla ' . $e($codigo) : ''; ?> desde el QR del flyer · los más recientes primero</p></div>
    <div><a class="btn primary" href="/panel?csv=registros<?php echo $codigo !== '' ? '&amp;c=' . rawurlencode($codigo) : ''; ?>">Descargar Excel (CSV)</a> <a class="btn" href="/panel?vista=charlas">Charlas</a> <a class="btn" href="/panel">Contactos</a> <a class="btn" href="/panel?salir=1">Salir</a></div>
  </div>
  <form class="filtro" method="get" action="/panel">
    <input type="hidden" name="vista" value="registros">
    <label for="c" class="meta">Charla</label>
    <select id="c" name="c" onchange="this.form.submit()">
      <option value="">Todas</option>
      <?php foreach ($codigosR as $k): ?><option value="<?php echo $e($k); ?>"<?php echo $k === $codigo ? ' selected' : ''; ?>><?php echo $e($k); ?></option><?php endforeach; ?>
    </select>
    <noscript><button class="btn" type="submit">Filtrar</button></noscript>
  </form>
  <?php $msgR = $_SESSION['reg_msg'] ?? null; unset($_SESSION['reg_msg']); if ($msgR): ?><p class="<?php echo $msgR[0] === 'ok' ? 'okmsg' : 'err'; ?>"><?php echo $e($msgR[1]); ?></p><?php endif; ?>
  <?php if ($codigo === ''): ?>
    <p class="meta nota">La confirmación de la plaza se envía sola al inscribirse. Para escribir la fecha, la hora y el lugar o enviar el recordatorio, elija una charla.</p>
  <?php else: $codR = $codigo === 'SIN CÓDIGO' ? '' : $codigo; $infoR = charla_info($codR); $faltaC = count(correos_por_enviar($codR, 'confirmacion')); $faltaR = count(correos_por_enviar($codR, 'recordatorio')); ?>
  <div class="card">
    <h2>Correos de la charla <?php echo $e($codigo); ?></h2>
    <p class="meta">Se envían desde <?php echo $e((config()['smtp_user'] ?? '') ?: 'el servidor (sin Gmail configurado en «Avisos por email» pueden acabar en spam)'); ?>. Una persona recibe cada correo una sola vez aunque se haya inscrito dos veces.</p>
    <form class="login ajustes" method="post" action="/panel?vista=registros&amp;c=<?php echo rawurlencode($codigo); ?>">
      <input type="hidden" name="accion" value="info_charla"><input type="hidden" name="c" value="<?php echo $e($codR); ?>">
      <label for="info" class="meta">Fecha, hora y lugar. Se lee «Le esperamos …» en la confirmación y en el recordatorio</label>
      <input id="info" name="info" type="text" maxlength="200" value="<?php echo $e($infoR); ?>" placeholder="el jueves 12 de noviembre, a las 17:00, en el aula 3">
      <button class="btn" type="submit">Guardar</button>
    </form>
    <p class="meta">Confirmaciones sin enviar: <strong><?php echo $faltaC; ?></strong> · Recordatorios por enviar: <strong><?php echo $faltaR; ?></strong></p>
    <div class="filtro">
      <?php if ($faltaC): ?><form method="post" action="/panel?vista=registros&amp;c=<?php echo rawurlencode($codigo); ?>"><input type="hidden" name="accion" value="correos_registro"><input type="hidden" name="c" value="<?php echo $e($codR); ?>"><input type="hidden" name="clase" value="confirmacion"><button class="btn" type="submit">Reenviar confirmaciones (<?php echo $faltaC; ?>)</button></form><?php endif; ?>
      <?php if ($faltaR): ?><form method="post" action="/panel?vista=registros&amp;c=<?php echo rawurlencode($codigo); ?>" onsubmit="return confirm('¿Enviar el recordatorio a <?php echo $faltaR; ?> inscritos?')"><input type="hidden" name="accion" value="correos_registro"><input type="hidden" name="c" value="<?php echo $e($codR); ?>"><input type="hidden" name="clase" value="recordatorio"><button class="btn primary" type="submit"<?php echo $infoR === '' ? ' disabled title="Primero escriba la fecha, la hora y el lugar"' : ''; ?>>Enviar recordatorio (<?php echo $faltaR; ?>)</button></form><?php endif; ?>
    </div>
    <p class="meta">Se envían como mucho 25 por pulsación; si quedan más, vuelva a pulsar.</p>
  </div>
  <?php endif; ?>
  <?php if (!$regs): ?><p class="empty">Todavía no se ha inscrito nadie.</p><?php else: $logR = correos_registro();
    $estado = function ($id, $clase) use ($logR) { $c = $logR[$id][$clase] ?? null; if (!$c) return '—'; if (!empty($c['ok'])) return 'Enviado ' . fecha_es($c['fecha'] ?? ''); $err = (string) ($c['error'] ?? '');
      if (strpos($err, 'ya se le envió') !== false) return 'No hacía falta: ya la tenía'; if (strpos($err, 'omitido') === 0) return 'Omitido: correo de prueba'; return 'Falló: ' . $err; }; ?>
  <div class="tabla-wrap"><table class="tabla">
    <thead><tr><th>Nombre y apellidos</th><th class="izq">Correo</th><th class="izq">Asignatura</th><th>Negocio o trabajo</th><th>Charla</th><th>Fecha</th><th class="izq">Confirmación</th><th class="izq">Recordatorio</th></tr></thead>
    <tbody><?php foreach ($regs as $r): $idR = (string) ($r['id'] ?? ''); ?>
      <tr><td><?php echo $e($r['nombre'] ?? ''); ?></td><td class="izq"><?php echo $e($r['email'] ?? ''); ?></td><td class="izq"><?php echo $e($r['asignatura'] ?? ''); ?></td><td><?php echo $e($r['negocio_o_trabajo'] ?? ''); ?></td><td><?php echo $e(($r['charla'] ?? '') ?: '—'); ?></td><td><?php echo $e(fecha_es($r['fecha'] ?? '')); ?></td><td class="izq"><?php echo $e($estado($idR, 'confirmacion')); ?></td><td class="izq"><?php echo $e($estado($idR, 'recordatorio')); ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
<?php elseif ($vistaProps): ?>
  <div class="bar">
    <div><h1>Autoría<span>.</span> Propuestas</h1><p class="meta"><?php echo count($props); ?> propuestas de valor<?php echo $codigo !== '' ? ' de la charla ' . $e($codigo) : ''; ?> · las más recientes primero</p></div>
    <div><a class="btn" href="/panel?csv=propuestas<?php echo $codigo !== '' ? '&amp;c=' . rawurlencode($codigo) : ''; ?>">Descargar Excel (CSV)</a> <a class="btn" href="/panel?vista=charlas">Charlas</a> <a class="btn" href="/panel">Contactos</a> <a class="btn" href="/panel?salir=1">Salir</a></div>
  </div>
  <form class="filtro" method="get" action="/panel">
    <input type="hidden" name="vista" value="propuestas">
    <label for="c" class="meta">Charla</label>
    <select id="c" name="c" onchange="this.form.submit()">
      <option value="">Todas</option>
      <?php foreach ($codigos as $k): ?><option value="<?php echo $e($k); ?>"<?php echo $k === $codigo ? ' selected' : ''; ?>><?php echo $e($k); ?></option><?php endforeach; ?>
    </select>
    <noscript><button class="btn" type="submit">Filtrar</button></noscript>
  </form>
  <?php if (!$props): ?><p class="empty">Todavía no ha llegado ninguna propuesta.</p><?php endif; ?>
  <?php foreach ($props as $r): ?>
    <div class="card prop">
      <span class="meta"><?php echo $e(fecha_es($r['fecha'] ?? '')); ?></span>
      <span><span class="tag"><?php echo $e(($r['charla'] ?? '') ?: 'Sin código'); ?></span></span>
      <span><?php echo $e(frase_propuesta($r)); ?></span>
    </div>
  <?php endforeach; ?>
<?php else: ?>
  <div class="bar">
    <div><h1>Autoría<span>.</span> Panel</h1><p class="meta"><?php echo count($rows); ?> registros · los más recientes primero</p></div>
    <div><a class="btn" href="/panel?csv=1">Descargar Excel (CSV)</a> <a class="btn" href="/panel?vista=charlas">Charlas (<?php echo count($diags); ?> diagnósticos)</a> <a class="btn" href="/panel?vista=registros">Inscripciones (<?php echo count($regs); ?>)</a> <a class="btn" href="/panel?vista=propuestas">Propuestas (<?php echo count($props); ?>)</a> <a class="btn" href="/panel?vista=hoja">Hoja de Google (<?php echo count(hoja_pendientes()); ?> pendientes)</a> <a class="btn" href="/panel?vista=avisos">Avisos por email</a> <a class="btn" href="/panel?salir=1">Salir</a></div>
  </div>
  <?php if (!$rows): ?><p class="empty">Todavía no ha llegado ningún contacto.</p><?php endif; ?>
  <?php foreach ($rows as $r): $isD = ($r['tipo'] ?? '') === 'diagnostico'; $p = $r['perfil'] ?? []; $q = $r['respuestas'] ?? []; $c = $r['calculo'] ?? []; $res = $r['resultado'] ?? null; ?>
    <details class="card">
      <summary>
        <span class="meta"><?php echo $e(fecha_es($r['fecha'] ?? '')); ?></span>
        <span><span class="tag <?php echo $isD ? 'd' : ''; ?>"><?php echo $isD ? 'Diagnóstico' : 'Contacto'; ?></span></span>
        <span class="who"><strong><?php echo $e(($r['nombre'] ?? '') ?: '—'); ?></strong><span class="meta"><?php echo $e($r['email'] ?? ''); ?><?php echo !empty($r['telefono']) ? ' · ' . $e($r['telefono']) : ''; ?></span></span>
        <span class="meta"><?php echo $e($isD ? ($r['ritmo'] ?? '') : ($r['motivo'] ?? '')); ?></span>
      </summary>
      <dl class="detail">
        <?php if ($isD): ?>
          <dt>País · índice orientativo</dt><dd><?php echo $e(($r['pais'] ?? '') . ' · ' . ($r['indice'] ?? '—') . '/100'); ?></dd>
          <dt>Red · titular o bio actual</dt><dd><?php echo $e((($p['red'] ?? '') ?: '—') . ' · ' . (($p['titular'] ?? '') ?: '—')); ?></dd>
          <dt>Cargo · empresa · sector</dt><dd><?php echo $e(implode(' · ', array_filter([$p['cargo_actual'] ?? '', $p['empresa_actual'] ?? '', $p['sector'] ?? ''])) ?: '—'); ?></dd>
          <dt>Objetivo · público</dt><dd><?php echo $e(($q['objetivo'] ?? '—') . ' · ' . ($q['publico'] ?? '—')); ?></dd>
          <dt>Publica hoy · tiempo · cámara</dt><dd><?php echo $e(($q['frecuencia'] ?? '—') . ' · ' . ($q['tiempo'] ?? '—') . ' · ' . ($q['camara'] ?? '—')); ?></dd>
          <dt>Ritmo recomendado · calculadora</dt><dd><?php echo $e(($r['ritmo'] ?? '—') . ' · por su cuenta: ' . ($c['horasMes'] ?? '—') . ' h/mes' . (isset($c['jornadas']) ? ' (unas ' . $c['jornadas'] . ' jornadas)' : '')); ?></dd>
          <?php if ($res): ?>
            <dt>Resumen</dt><dd><?php echo $e($res['resumen'] ?? ''); ?></dd>
            <?php if (!empty($res['redes'])): ?><dt>Redes recomendadas</dt><dd><?php echo $e(implode(', ', $res['redes']) . (!empty($res['redes_por_que']) ? ' — ' . $res['redes_por_que'] : '')); ?></dd><?php endif; ?>
            <dt>Titular o bio propuesto</dt><dd><?php echo $e($res['titular_propuesto'] ?? ''); ?></dd>
            <dt>Ideas de vídeo</dt><dd><?php foreach (($res['ideas_video'] ?? []) as $i) echo $e('«' . ($i['formato'] ?? '') . '» ' . ($i['titulo'] ?? '') . ' — ' . ($i['gancho'] ?? '')) . "\n"; ?></dd>
          <?php else: ?>
            <dt>Diagnóstico IA</dt><dd>No se generó. Conviene escribirle personalmente.</dd>
          <?php endif; ?>
        <?php else: ?>
          <dt>País · cargo</dt><dd><?php echo $e(($r['pais'] ?? '—') . ' · ' . (($r['cargo'] ?? '') ?: '—')); ?></dd>
          <dt>Mensaje</dt><dd><?php echo $e(($r['mensaje'] ?? '') ?: '—'); ?></dd>
        <?php endif; ?>
        <dt>Consentimiento</dt><dd><?php echo $e($r['consentimiento'] ?? '—'); ?></dd>
      </dl>
    </details>
  <?php endforeach; ?>
<?php endif; ?>
</div></body>
</html>
