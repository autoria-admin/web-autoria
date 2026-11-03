<?php
// panel.php (se abre como /panel) — panel privado con los contactos y
// diagnósticos recibidos. Equivale a api/_panel.js. La contraseña se elige en
// la página de instalación (/instalar) y se guarda cifrada fuera de la web.
define('AUTORIA', 1);
require __DIR__ . '/_comun.php';

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

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  if (rate_limit('login', 5, 30, 300) !== null) { $error = 'Demasiados intentos. Espera unos minutos.'; http_response_code(429); }
  elseif ($hash !== '' && password_verify((string) ($_POST['clave'] ?? ''), $hash)) { session_regenerate_id(true); $_SESSION['ok'] = 1; header('Location: /panel', true, 303); exit; }
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
$rows = array_values(array_filter($rows, function ($r) { return !in_array($r['tipo'] ?? '', ['propuesta', 'charla-diagnostico'], true); }));
$vistaCharlas = $logged && (($_GET['vista'] ?? '') === 'charlas' || ($_GET['csv'] ?? '') === 'charlas');

// Resumen por código de charla: visitas (contador anónimo), propuestas y diagnósticos pedidos.
$resumen = [];
if ($vistaCharlas) {
  $fila = function () { return ['vp' => 0, 'p' => 0, 'vd' => 0, 'd' => 0]; };
  foreach (leer_visitas() as $k => $v) { $k = $k ?: 'SIN CÓDIGO'; $resumen[$k] = $resumen[$k] ?? $fila(); $resumen[$k]['vp'] += (int) ($v['propuesta'] ?? 0); $resumen[$k]['vd'] += (int) ($v['diagnostico'] ?? 0); }
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
  @media (max-width: 720px) { .card summary, .card.prop { grid-template-columns: 1fr; } }
</style>
</head>
<body><div class="wrap">
<?php if (!$logged): ?>
  <form class="login" method="post" action="/panel" autocomplete="off">
    <h1>Autoría<span>.</span> Panel</h1>
    <p class="meta">Contactos y diagnósticos recibidos desde la web.</p>
    <?php if ($hash === ''): ?><p class="err">El panel aún no tiene contraseña. Ve a <a href="/instalar">/instalar</a> para configurarlo.</p><?php endif; ?>
    <label for="clave" class="meta">Contraseña</label>
    <input id="clave" name="clave" type="password" required autofocus>
    <button class="btn primary" type="submit">Entrar</button>
    <?php if ($error): ?><p class="err"><?php echo $e($error); ?></p><?php endif; ?>
  </form>
<?php elseif ($vistaCharlas): ?>
  <div class="bar">
    <div><h1>Autoría<span>.</span> Charlas</h1><p class="meta">Visitas, propuestas y diagnósticos pedidos por código de charla</p></div>
    <div><a class="btn" href="/panel?vista=propuestas">Propuestas</a> <a class="btn" href="/panel">Contactos</a> <a class="btn" href="/panel?salir=1">Salir</a></div>
  </div>
  <?php if (!$resumen): ?><p class="empty">Todavía no hay datos de ninguna charla.</p><?php else: ?>
  <div class="tabla-wrap"><table class="tabla">
    <thead><tr><th>Charla</th><th>Visitas propuesta</th><th>Propuestas</th><th>Visitas diagnóstico</th><th>Diagnósticos pedidos</th><th>Pedidos / visitas</th></tr></thead>
    <tbody><?php foreach ($resumen as $k => $n): ?>
      <tr><td><a href="/panel?vista=charlas&amp;c=<?php echo rawurlencode($k); ?>"><?php echo $e($k); ?></a></td><td><?php echo $n['vp']; ?></td><td><?php echo $n['p']; ?></td><td><?php echo $n['vd']; ?></td><td><?php echo $n['d']; ?></td><td><?php echo $n['vd'] ? round(100 * $n['d'] / $n['vd']) . ' %' : '—'; ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <p class="meta nota">Las visitas se cuentan una vez por móvil y charla, sin cookies ni datos personales.</p>
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
    <div><a class="btn" href="/panel?csv=1">Descargar Excel (CSV)</a> <a class="btn" href="/panel?vista=charlas">Charlas (<?php echo count($diags); ?> diagnósticos)</a> <a class="btn" href="/panel?vista=propuestas">Propuestas (<?php echo count($props); ?>)</a> <a class="btn" href="/panel?salir=1">Salir</a></div>
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
