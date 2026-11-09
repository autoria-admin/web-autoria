<?php
// _comun.php — utilidades compartidas por la versión PHP (Hostinger) de los
// servicios de Autoría. Equivale a api/_comun.js de la versión de Vercel.
// No se puede abrir desde el navegador.
if (!defined('AUTORIA')) { http_response_code(404); exit; }

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Madrid');

// Modelos de Gemini en orden de preferencia (si uno falla o está saturado, se prueba el siguiente).
const MODELOS_CHAT    = ['gemini-flash-lite-latest', 'gemini-3.5-flash-lite', 'gemini-3.5-flash'];
const MODELOS_LECTURA = ['gemini-3.5-flash', 'gemini-flash-lite-latest', 'gemini-3.5-flash-lite'];

function raiz_web() { return dirname(__DIR__); }

/* ---------- carpeta privada ----------
   Configuración (clave de la IA, contraseña del panel) y contactos se guardan
   FUERA de la carpeta pública (public_html) si se puede; si no, en php/_datos.
   Siempre se bloquea con .htaccess: si la web está en un subdominio dentro del
   public_html de otra web, la carpeta de al lado sigue siendo pública. */
function dir_privado() {
  static $dir = null;
  if ($dir !== null) return $dir;
  $env = getenv('AUTORIA_PRIVADO');
  $candidatos = $env ? [$env] : [dirname(raiz_web()) . '/autoria-privado', __DIR__ . '/_datos'];
  foreach ($candidatos as $c) {
    if (!is_dir($c)) @mkdir($c, 0750, true);
    if (is_dir($c) && is_writable($c)) {
      if (!is_file($c . '/.htaccess')) {
        @file_put_contents($c . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
        @file_put_contents($c . '/index.php', "<?php http_response_code(404);\n");
      }
      return $dir = $c;
    }
  }
  return $dir = '';
}

function config($recargar = false) {
  static $cfg = null;
  if ($cfg !== null && !$recargar) return $cfg;
  $cfg = ['gemini_api_key' => '', 'panel_hash' => '', 'aviso_email' => ''];
  $f = dir_privado() ? dir_privado() . '/config.php' : '';
  if ($f && is_file($f)) { $c = @include $f; if (is_array($c)) $cfg = array_merge($cfg, $c); }
  return $cfg;
}

function guardar_config(array $nueva) {
  $dir = dir_privado();
  if ($dir === '') return false;
  $c = array_merge(config(), $nueva);
  $php = "<?php\n// Configuración privada de Autoría (generada por la página de instalación).\nreturn " . var_export($c, true) . ";\n";
  $ok = @file_put_contents($dir . '/config.php', $php, LOCK_EX) !== false;
  if ($ok) {
    @chmod($dir . '/config.php', 0640);
    // Sin esto, OPcache puede seguir sirviendo unos segundos la configuración anterior a otras peticiones.
    if (function_exists('opcache_invalidate')) @opcache_invalidate($dir . '/config.php', true);
    config(true);
  }
  return $ok;
}

/* ---------- respuestas ---------- */
// Responde ya al navegador y sigue trabajando (la hoja de Google y el aviso por
// email se envían después, sin hacer esperar a la persona).
function json_out_y_seguir($arr) {
  ignore_user_abort(true);
  @set_time_limit(90);
  $body = json_encode($arr, JSON_UNESCAPED_UNICODE);
  header('Content-Type: application/json; charset=utf-8');
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: no-store');
  header('Content-Length: ' . strlen($body));
  header('Connection: close');
  echo $body;
  if (function_exists('litespeed_finish_request')) litespeed_finish_request();
  elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
  else { @ob_flush(); flush(); }
}

function json_out($arr, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: no-store');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

// Solo peticiones desde la propia web (evita que otras webs gasten la cuota).
function misma_web() {
  $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
  $src  = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
  if ($src === '') return true;
  return strtolower((string) parse_url($src, PHP_URL_HOST)) === $host;
}

function solo_post() {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok' => false, 'reason' => 'metodo'], 405);
  if (!misma_web()) json_out(['ok' => false, 'reason' => 'origin'], 403);
}

function entrada_json() {
  $in = json_decode((string) file_get_contents('php://input'), true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
  return is_array($in) ? $in : [];
}

/* ---------- textos ---------- */
function clean_str($v, $max = 300) {
  if ($v === null || is_array($v) || is_object($v)) return '';
  $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $v));
  return mb_substr($v, 0, $max);
}
function str_or_null($v, $max = 300) {
  $s = clean_str($v, $max);
  return ($s === '' || strtolower($s) === 'null') ? null : $s;
}
function es_email($s) { return (bool) preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', $s) && mb_strlen($s) <= 160; }
function nuevo_id() { return gmdate('ymd-His') . '-' . bin2hex(random_bytes(3)); }
function ahora() { return gmdate('Y-m-d\TH:i:s.000\Z'); }

// Fecha del envío tal como la manda el móvil (ISO con su zona horaria), para que un
// reintento no la cambie. Si falta o no tiene sentido, la hora del servidor.
function fecha_envio($v) {
  $s = clean_str($v, 40);
  if ($s !== '') {
    try {
      $d = new DateTime($s);
      $t = $d->getTimestamp();
      if ($t <= time() + 300 && $t > time() - 30 * 86400) return $d->format('Y-m-d\TH:i:sP');
    } catch (Exception $e) { /* fecha no válida */ }
  }
  return gmdate('Y-m-d\TH:i:s') . '+00:00';
}

// Lee un texto (indicaciones de la IA, base de conocimiento) de los archivos de
// api/ para que Vercel y Hostinger usen exactamente los mismos.
function texto_js($archivo, $nombre) {
  static $cache = [];
  $f = raiz_web() . '/api/' . $archivo;
  if (!isset($cache[$f])) $cache[$f] = is_file($f) ? (string) file_get_contents($f) : '';
  $s = $cache[$f];
  $marca = $nombre === 'default' ? 'export default `' : 'const ' . $nombre . ' = `';
  $i = strpos($s, $marca);
  if ($i === false) return '';
  $i += strlen($marca);
  $j = strpos($s, '`;', $i);
  return $j === false ? '' : substr($s, $i, $j - $i);
}

/* ---------- límites de uso ----------
   Por IP (minuto y día) y tope global diario que protege la cuota de la IA.
   Devuelve null si se puede seguir, o 'min' | 'day' | 'global'. */
function rate_limit($bucket, $perMin, $perDay, $globalDay) {
  $tmp = sys_get_temp_dir();
  $now = time();
  // De vez en cuando, borra los contadores de más de 24 horas (la política de privacidad lo promete).
  if (mt_rand(1, 50) === 1) {
    foreach ((array) glob("$tmp/autoria_*") as $f) { if (@filemtime($f) < $now - 86400) @unlink($f); }
  }
  $h = substr(hash('sha256', 'autoria:' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')), 0, 24);
  $fIp = "$tmp/autoria_{$bucket}_$h.json";
  $stamps = is_file($fIp) ? json_decode((string) @file_get_contents($fIp), true) : [];
  if (!is_array($stamps)) $stamps = [];
  $stamps = array_values(array_filter($stamps, function ($t) use ($now) { return $t > $now - 86400; }));
  $min = count(array_filter($stamps, function ($t) use ($now) { return $t > $now - 60; }));
  $fG = "$tmp/autoria_{$bucket}_global_" . date('Ymd') . '.txt';
  $g = is_file($fG) ? (int) @file_get_contents($fG) : 0;
  $stamps[] = $now;
  @file_put_contents($fIp, json_encode(array_slice($stamps, -300)), LOCK_EX);
  @file_put_contents($fG, (string) ($g + 1), LOCK_EX);
  if ($min + 1 > $perMin) return 'min';
  if (count($stamps) > $perDay) return 'day';
  if ($g + 1 > $globalDay) return 'global';
  return null;
}

/* ---------- Google Gemini ---------- */
function gemini_key() {
  $k = trim((string) (config()['gemini_api_key'] ?? ''));
  return preg_match('/^[A-Za-z0-9._-]{20,}$/', $k) ? $k : '';
}

// Llama a Gemini probando los modelos en orden. Pide el mínimo «razonamiento»
// (respuestas rápidas); si un modelo no admite ese ajuste, repite sin él.
function gemini_call(array $models, array $payload, $timeoutMs = 25000, $deadlineMs = 52000) {
  $key = gemini_key();
  if ($key === '') return ['', 'sin_clave'];
  if (!function_exists('curl_init')) return ['', 'sin_curl'];
  // Que PHP no corte la petición (30 s por defecto) antes del plazo propio.
  @set_time_limit((int) ceil($deadlineMs / 1000) + 15);
  $start = microtime(true);
  $lastErr = '';
  foreach ($models as $model) {
    foreach ([true, false] as $thinking) {
      $left = $deadlineMs - (microtime(true) - $start) * 1000;
      if ($left < 3000) break 2;
      $body = $payload;
      if (!isset($body['generationConfig'])) $body['generationConfig'] = [];
      unset($body['generationConfig']['thinkingConfig']);
      if ($thinking) $body['generationConfig']['thinkingConfig'] = ['thinkingLevel' => 'minimal'];
      $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent');
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT_MS     => (int) min($timeoutMs, $left),
        CURLOPT_CONNECTTIMEOUT => 10,
      ]);
      $raw = curl_exec($ch);
      $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $err = curl_error($ch);
      curl_close($ch);
      if ($raw === false || $code >= 400) {
        $lastErr = "$model http $code $err " . substr((string) $raw, 0, 200);
        if ($thinking && $code === 400) continue;   // no admite el ajuste: repetir sin él
        break;                                      // saturado, retirado, etc.: siguiente modelo
      }
      $data = json_decode($raw, true);
      $text = '';
      foreach (($data['candidates'][0]['content']['parts'] ?? []) as $p) {
        if (isset($p['text']) && empty($p['thought'])) $text .= $p['text'];
      }
      if (trim($text) !== '') return [$text, ''];
      $lastErr = "$model vacío (" . ($data['promptFeedback']['blockReason'] ?? ($data['candidates'][0]['finishReason'] ?? '?')) . ')';
      break;
    }
  }
  @error_log('[autoria-ia] ' . $lastErr);
  return ['', $lastErr ?: 'ia'];
}

function extract_json($s) {
  $s = trim(preg_replace('/```$/', '', trim(preg_replace('/^```(?:json)?/i', '', trim((string) $s)))));
  $a = strpos($s, '{'); $b = strrpos($s, '}');
  if ($a === false || $b === false || $b < $a) return null;
  $d = json_decode(substr($s, $a, $b - $a + 1), true);
  return is_array($d) ? $d : null;
}

/* ---------- contactos ---------- */
function store_record(array $row) {
  $dir = dir_privado();
  if ($dir === '') { @error_log('[autoria-registro-no-guardado] ' . json_encode($row, JSON_UNESCAPED_UNICODE)); return false; }
  return @file_put_contents($dir . '/contactos.jsonl', json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX) !== false;
}

function read_records() {
  $f = dir_privado() . '/contactos.jsonl';
  if (!is_file($f)) return [];
  $out = [];
  foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $r = json_decode($line, true);
    if (is_array($r)) $out[] = $r;
  }
  return array_reverse($out);   // los más recientes primero
}

// ¿Ya se guardó un registro con este id? (evita duplicados y avisos repetidos en los reintentos)
function registro_existe($id) {
  $f = dir_privado() . '/contactos.jsonl';
  if ($id === '' || !is_file($f)) return false;
  return strpos((string) file_get_contents($f), '"id":' . json_encode($id)) !== false;
}

/* ---------- visitas de las charlas ----------
   Contador anónimo por código de charla y página: no guarda IP, cookies ni
   nada que identifique a la persona. Archivo: autoria-privado/visitas.json */
function contar_visita($charla, $pagina) {
  $dir = dir_privado();
  if ($dir === '') return false;
  $fp = @fopen($dir . '/visitas.json', 'c+');
  if (!$fp) return false;
  flock($fp, LOCK_EX);
  $d = json_decode((string) stream_get_contents($fp), true);
  if (!is_array($d)) $d = [];
  $d[$charla][$pagina] = (int) ($d[$charla][$pagina] ?? 0) + 1;
  if (empty($d[$charla]['primera'])) $d[$charla]['primera'] = ahora();
  $d[$charla]['ultima'] = ahora();
  ftruncate($fp, 0); rewind($fp);
  fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE));
  fflush($fp); flock($fp, LOCK_UN); fclose($fp);
  return true;
}
function leer_visitas() {
  $f = dir_privado() . '/visitas.json';
  $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : [];
  return is_array($d) ? $d : [];
}
function codigo_charla($v) { return strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', clean_str($v, 20))); }

/* ---------- hoja de Google (CRM de las charlas) ----------
   Cada envío de las charlas se copia también en la hoja «CRM de las charlas» a
   través de su Apps Script, publicado como aplicación web. La URL y el token van
   en config.php (hoja_url y hoja_token), se guardan desde /panel?vista=hoja y
   nunca llegan al navegador. Si la hoja no contesta, el envío queda en
   autoria-privado/hoja-pendientes.json y se reintenta con el siguiente envío. */
function hoja_configurada() {
  $c = config();
  return trim((string) ($c['hoja_url'] ?? '')) !== '' && trim((string) ($c['hoja_token'] ?? '')) !== '';
}

// Llama al Apps Script con un POST en JSON. Apps Script contesta con una
// redirección 302 a la página con el resultado: se sigue y se lee ese JSON.
// Devuelve [respuesta, ''] o [null, motivo del fallo].
function hoja_llamar(array $datos, $url = null, $token = null) {
  $c = config();
  $url = trim((string) ($url ?? ($c['hoja_url'] ?? '')));
  $token = trim((string) ($token ?? ($c['hoja_token'] ?? '')));
  if ($url === '' || $token === '') return [null, 'la hoja no está configurada'];
  if (!function_exists('curl_init')) return [null, 'el servidor no tiene cURL'];
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['token' => $token] + $datos, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_TIMEOUT        => 6,   // como mucho 6 s: si Google tarda más, queda pendiente
  ]);
  $raw = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if ($raw === false) return [null, 'la hoja no responde (' . $err . ')'];
  $j = json_decode((string) $raw, true);
  if (is_array($j)) return [$j, ''];
  if (strpos((string) $raw, 'Script function not found') !== false) return [null, 'el script publicado no tiene el código: hay que implementar una versión nueva'];
  return [null, 'respuesta inesperada de la hoja (HTTP ' . $code . ')'];
}

// Envía un registro a la hoja. Devuelve '' si la hoja lo ha guardado (o ya lo tenía) o el motivo del fallo.
function hoja_post(array $datos) {
  [$j, $err] = hoja_llamar($datos);
  if ($err !== '') return $err;
  return !empty($j['ok']) ? '' : 'la hoja contesta «' . clean_str((string) ($j['error'] ?? 'error'), 160) . '»';
}

// Comprueba la URL y el token sin escribir nada: el script valida el token y,
// como el tipo «prueba» no existe, contesta «tipo» solo si el token es bueno.
function hoja_probar($url, $token) {
  [$j, $err] = hoja_llamar(['tipo' => 'prueba'], $url, $token);
  if ($err !== '') return $err;
  if (($j['error'] ?? '') === 'tipo' || !empty($j['ok'])) return '';
  if (($j['error'] ?? '') === 'token') return 'el token no coincide con el del script';
  return 'la hoja contesta «' . clean_str((string) ($j['error'] ?? 'error'), 160) . '»';
}

// Envíos pendientes de la hoja (id => datos, último error, intentos), en orden de llegada.
// Con $cambio, se modifican con el archivo bloqueado.
function hoja_pendientes(?callable $cambio = null) {
  $dir = dir_privado();
  if ($dir === '') return [];
  $fp = @fopen($dir . '/hoja-pendientes.json', 'c+');
  if (!$fp) return [];
  flock($fp, $cambio ? LOCK_EX : LOCK_SH);
  $d = json_decode((string) stream_get_contents($fp), true);
  if (!is_array($d)) $d = [];
  if ($cambio) {
    $d = $cambio($d);
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE));
    fflush($fp);
  }
  flock($fp, LOCK_UN); fclose($fp);
  return $d;
}
function hoja_marcar_pendiente(array $datos, $error) {
  hoja_pendientes(function ($d) use ($datos, $error) {
    $id = (string) $datos['id'];
    $antes = $d[$id] ?? [];
    unset($d[$id]);   // vuelve al final: un envío que falla no bloquea a los demás
    $d[$id] = ['datos' => $datos, 'error' => $error, 'intentos' => (int) ($antes['intentos'] ?? 0) + 1, 'desde' => $antes['desde'] ?? ahora(), 'ultimo' => ahora()];
    return $d;
  });
}
function hoja_quitar_pendiente($id) {
  hoja_pendientes(function ($d) use ($id) { unset($d[$id]); return $d; });
}

// Copia un envío nuevo en la hoja. Si falla, queda pendiente; si va bien,
// aprovecha para reenviar hasta 20 pendientes de envíos anteriores.
function hoja_registrar(array $datos) {
  $err = hoja_post($datos);
  if ($err !== '') { hoja_marcar_pendiente($datos, $err); return false; }
  hoja_reintentar(20);
  return true;
}

// Reenvía pendientes, los más antiguos primero. Solo un proceso a la vez; se para
// en cuanto la hoja falla o se acaba el tiempo. Devuelve cuántos ha enviado,
// cuántos quedan y si otro proceso ya estaba reenviando.
function hoja_reintentar($max = 20, $segundos = 15) {
  $res = ['enviados' => 0, 'quedan' => count(hoja_pendientes()), 'ocupado' => false];
  if (!$res['quedan'] || !hoja_configurada()) return $res;
  $lk = @fopen(dir_privado() . '/hoja-reintento.lock', 'c');
  if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) { if ($lk) fclose($lk); $res['ocupado'] = true; return $res; }
  $t0 = microtime(true);
  foreach (array_slice(hoja_pendientes(), 0, $max, true) as $id => $p) {
    if (microtime(true) - $t0 > $segundos) break;
    $err = hoja_post($p['datos']);
    if ($err !== '') { hoja_marcar_pendiente($p['datos'], $err); break; }
    hoja_quitar_pendiente((string) $id);
    $res['enviados']++;
  }
  flock($lk, LOCK_UN); fclose($lk);
  $res['quedan'] = count(hoja_pendientes());
  return $res;
}

// Totales de una charla para la pestaña Contadores de la hoja.
function hoja_totales($charla) {
  $v = leer_visitas()[$charla] ?? [];
  return ['visitas_propuesta' => (int) ($v['propuesta'] ?? 0), 'visitas_diagnostico' => (int) ($v['diagnostico'] ?? 0), 'clics_comunidad' => (int) ($v['whatsapp'] ?? 0)];
}

// Envía los contadores de una charla, como mucho una vez por minuto y solo si han
// cambiado desde el último envío. Con $forzar (reenvío desde el panel), siempre.
// Estado en autoria-privado/hoja-contadores.json.
function hoja_contadores($charla, $forzar = false) {
  if ($charla === '' || !hoja_configurada()) return false;
  $dir = dir_privado();
  $fp = @fopen($dir . '/hoja-contadores.json', 'c+');
  if (!$fp) return false;
  flock($fp, LOCK_EX);
  $d = json_decode((string) stream_get_contents($fp), true);
  if (!is_array($d)) $d = [];
  $totales = hoja_totales($charla);
  $e = $d[$charla] ?? [];
  $toca = $forzar || (($e['enviados'] ?? null) !== $totales && time() - (int) ($e['intento'] ?? 0) >= 60);
  if ($toca) {
    $e['intento'] = time();   // reserva el turno antes de llamar a Google
    $d[$charla] = $e;
    ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE)); fflush($fp);
  }
  flock($fp, LOCK_UN); fclose($fp);
  if (!$toca) return false;

  $err = hoja_post(['tipo' => 'contadores', 'charla' => $charla] + $totales);
  $fp = @fopen($dir . '/hoja-contadores.json', 'c+');
  if (!$fp) return $err === '';
  flock($fp, LOCK_EX);
  $d = json_decode((string) stream_get_contents($fp), true);
  if (!is_array($d)) $d = [];
  $d[$charla]['error'] = $err;
  if ($err === '') { $d[$charla]['enviados'] = $totales; $d[$charla]['enviado_en'] = ahora(); }
  ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE)); fflush($fp);
  flock($fp, LOCK_UN); fclose($fp);
  return $err === '';
}
function hoja_estado_contadores() {
  $f = dir_privado() . '/hoja-contadores.json';
  $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : [];
  return is_array($d) ? $d : [];
}

// Aviso por email al equipo (opcional: solo si se configuró un email en la instalación).
// Si en el panel se guardó una contraseña de aplicación de Gmail, se envía por
// el SMTP de Gmail: el dominio temporal de Hostinger no puede enviar correo propio
// (su SPF es «-all») y Gmail descarta esos mensajes sin avisar.
function notify_team($asunto, array $row) {
  $to = config()['aviso_email'] ?? '';
  if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
  $texto = '';
  foreach ($row as $k => $v) {
    if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $texto .= str_pad($k, 16) . ': ' . ($v === null || $v === '' ? '—' : $v) . "\n";
  }
  $cfg = config();
  if (!empty($cfg['smtp_user']) && !empty($cfg['smtp_pass'])) return smtp_gmail($cfg['smtp_user'], $cfg['smtp_pass'], $to, $asunto, $texto) === '';
  if (!function_exists('mail')) return false;
  $host = preg_replace('/[^a-z0-9.\-]/i', '', preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'autoria'));
  $cab = 'From: Autoria <no-reply@' . $host . ">\r\nContent-Type: text/plain; charset=utf-8";
  return @mail($to, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $texto, $cab);
}

// Envío mínimo por el SMTP de Gmail (SSL, puerto 465) con una contraseña de aplicación.
// Devuelve '' si se envió o un texto corto con el motivo del fallo.
function smtp_gmail($user, $pass, $to, $asunto, $texto, $servidor = 'ssl://smtp.gmail.com:465') {
  $fp = @stream_socket_client($servidor, $errno, $errstr, 10);
  if (!$fp) return 'sin conexión con smtp.gmail.com (' . $errstr . ')';
  stream_set_timeout($fp, 10);
  $leer = function () use ($fp) {
    $r = '';
    while (($l = fgets($fp, 515)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; }
    return $r;
  };
  $paso = function ($cmd, $espera) use ($fp, $leer) {
    if ($cmd !== null) fwrite($fp, $cmd . "\r\n");
    $r = $leer();
    return substr($r, 0, 3) === $espera ? '' : trim($r);
  };
  $cuerpo = preg_replace('/^\./m', '..', str_replace("\n", "\r\n", $texto));
  $msg = 'From: Autoria <' . $user . ">\r\n" . 'To: <' . $to . ">\r\n"
    . 'Subject: =?UTF-8?B?' . base64_encode($asunto) . "?=\r\n" . 'Date: ' . date('r') . "\r\n"
    . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $cuerpo . "\r\n.";
  $pasos = [[null, '220'], ['EHLO autoria', '250'], ['AUTH LOGIN', '334'], [base64_encode($user), '334'], [base64_encode($pass), '235'],
            ['MAIL FROM:<' . $user . '>', '250'], ['RCPT TO:<' . $to . '>', '250'], ['DATA', '354'], [$msg, '250']];
  foreach ($pasos as $i => [$cmd, $espera]) {
    $err = $paso($cmd, $espera);
    if ($err !== '') { @fwrite($fp, "QUIT\r\n"); fclose($fp); return ($i === 4 ? 'Gmail no acepta la contraseña de aplicación: ' : '') . $err; }
  }
  fwrite($fp, "QUIT\r\n"); fclose($fp);
  return '';
}
