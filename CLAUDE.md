# Autoría — contexto del proyecto

Web de **Autoría**, la agencia de marca personal en **redes sociales** (LinkedIn, Instagram, YouTube, TikTok…) para empresarios, directivos y profesionales expertos de España y Costa Rica. Todo el contenido son vídeos contados con la propia voz del cliente.

- **Titular y entidad que factura:** Asociación Junior Empresa ERYON (Madrid), NIF G23915176, Plaza de San Martín, 1, 28013 Madrid. Email de privacidad: eryoncompany@gmail.com.
- **Contacto público:** admin.autoria@gmail.com y la página de empresa de LinkedIn. La agenda de llamadas es de Google Calendar. Los tres están en `lib/manifest.js`. No hay WhatsApp ni teléfono público de contacto; la comunidad de WhatsApp (`WHATSAPP_COMMUNITY_URL` en `lib/manifest.js`) solo aparece al final de `/charla/diagnostico`, después de enviar, y unirse es voluntario.
- **Idioma y tono:** todo en español, tuteando. El tono es editorial, sobrio y profesional, sin jerga de marketing ni emojis.

Hasta el 22/10/2026 el enfoque era solo LinkedIn; ahora es redes sociales en general. Que no vuelva a aparecer «solo LinkedIn» salvo donde tenga sentido, como el PDF de LinkedIn en el diagnóstico.

**Enfoque del mensaje (desde el 9/11/2026,** tras el feedback de la OBV sobre la charla y la reunión con el cliente Pablo Caño):
- El valor de Autoría no está en editar vídeos ni en escribir guiones: eso va incluido y se da por hecho. Está en **pensar**: la estrategia (qué contar, a quién, cuándo y para qué), la dirección de las grabaciones y sacar de cada contenido del cliente todo su valor (de una grabación, varias piezas) para que su conocimiento trabaje para su negocio.
- Primero la emoción y la transformación (del experto invisible a que te conozcan y confíen en ti), después lo técnico. Frases cortas: lo que se dice en 10 palabras no se dice en 40.
- No dar por sabido qué es una marca personal: explicarlo en una frase. Sin jerga («funnel», «engagement»).
- No se mide por número de vídeos: el ritmo depende de la estrategia y de lo que funcione.

## Dónde está publicada

- **Hostinger, plan Business.** El sitio es **`autoriaestudio.com`** desde el 9/11/2026 (DNS de Hostinger, certificado SSL de Hostinger).
  - `www.autoriaestudio.com` redirige a `autoriaestudio.com` (regla en `.htaccess`).
  - La redirección de http a https se activa con «Forzar HTTPS» en hPanel, no en `.htaccess`: detrás de la CDN de Hostinger se podría crear un bucle.
  - El dominio temporal anterior, `silver-alligator-184608.hostingersite.com`, ya no abre la web.
- **Publicación automática.** Cada `git push` a `main` del repositorio **público** `github.com/autoria-admin/web-autoria` (antes `caarloossc/web-autoria`, que redirige) se despliega solo en `public_html`, mediante la aplicación de GitHub de Hostinger. Tarda unos 10–20 s.
  - **Hostinger tiene que publicar desde la rama `main`.** Del 9/11 a las 18:33 UTC hasta las 20:20 UTC estuvo conectado por error a una rama de trabajo de Claude (`claude/loving-cerf-3w8o58`), y lo que se fusionaba en `main` no llegaba a la web. La rama se ve y se cambia en hPanel → GIT, en la lista «Despliegues» (columna «Rama»); «Redistribuir» vuelve a publicar.
  - **Comprobar que se ha aplicado:** la versión `?v=` de `styles.css` en `privacidad.html` publicada tiene que ser la del repositorio.
- **En hPanel:** Sitios web → Administrar sitio web → Avanzado → GIT / Configuración de PHP (8.3).
- **No tocar la web de la empresa** (`eryoncompany.eryoncompany.com`, WordPress) ni su `public_html`.
- **Vercel ya no existe.** Se borró en octubre de 2026. Los archivos `api/*.js`, `vercel.json` y `tools/dev-server.mjs` se conservan porque PHP lee de `api/` los textos de la IA (ver más abajo).

## Estructura

- **`index.html`** — portada y secciones numeradas:
  1. Portada
  2. Problema («El experto invisible», con «Marca personal, en una frase»)
  3. Método (cuatro tiempos)
  4. Qué hacemos («Tú pones lo que sabes. Nosotros, que trabaje para ti.»)
  5. Contacto
  6. Diagnóstico
  7. Preguntas
  - **Caso real y Equipo están ocultos** (atributo `hidden`, sus enlaces del menú están comentados) hasta tener su contenido. Para volver a mostrarlos: quitar `hidden`, recuperar los enlaces y renumerar (Caso real 06, Equipo 07, Diagnóstico 08 y Preguntas 09; también el «Nº» de `diagnostico.html`).
  - **SEO:** título y descripción, datos estructurados (`ProfessionalService`, `WebSite` y `FAQPage`) en la cabecera. Las preguntas del `FAQPage` tienen que coincidir con las de la sección de preguntas: si se cambian, se cambian en los dos sitios.
- **`diagnostico.html` + `lib/diagnostico.js`** — el diagnóstico gratis.
  - Pasos: perfil, objetivo, público, frecuencia, tiempo y cámara, calculadora, email y consentimiento, informe.
  - Perfil: capturas de cualquier red o PDF de LinkedIn, leídos con IA. Lo que no aparece se deja en blanco.
  - Informe: puntos fuertes y débiles, bio o titular propuesto, «Dónde centrarte» (1–2 redes), tres ideas de vídeo, ritmo recomendado y llamada.
- **Charlas** — dos landings con QR en la presentación y una de inscripción con QR en el flyer (`https://autoriaestudio.com/charla/propuesta?c=C01` y `https://autoriaestudio.com/charla/diagnostico?c=C01`): **no se cambian las rutas ni el parámetro `c`**, y los dos QR tienen que usar el mismo dominio (código de la charla: C01 es la UAM del 12/11/2026, P01 la prueba con la company del 9/11 y TEST las pruebas técnicas). No salen en el menú, llevan `noindex`, trato de usted y nada se envía solo a la persona (ni correos ni WhatsApp), **salvo la confirmación de la inscripción** (decisión del 09/11/2026, ver abajo).
- **`charla-propuesta.html` + `lib/charla.js`** — dinámica, en `/charla/propuesta?c=C01`.
  - «Su propuesta de valor.», «Complete la frase. No tiene que quedar perfecta.», tres campos con su palabra delante (Para, aporto, gracias a), el ejemplo completo de la lámina, «Listo» y «Es anónima…».
  - Lo que escriben se guarda en el móvil en cada tecla (`autoria_propuesta_borrador`).
  - Tras «Listo»: la frase en grande, «Editar la frase» y «Copiar», y separado «Cuando acaben en parejas…» con el botón «Pedir mi diagnóstico gratis», que abre `/charla/diagnostico` con el mismo `c`.
  - Guarda anónimo en `/api/propuesta` (`php/propuesta.php`), sin aviso por email. Se ve en `/panel?vista=propuestas`, filtrable por código y con CSV.
  - La frase queda en `localStorage` con la clave `autoria_propuesta` (JSON con `frase`, `para`, `aporto`, `gracias_a`, `charla` y `fecha`) para que la recupere el diagnóstico. Sin conexión, los envíos esperan en `autoria_propuesta_cola` y se reintentan solos.
- **`charla-diagnostico.html` + `lib/charla-diagnostico.js`** — cierre de las charlas, en `/charla/diagnostico?c=C01`. Los textos salen de una revisión con la heurística de conversión de MECLABS.
  - Si `autoria_propuesta` está en el móvil, arriba va «Su frase» y el titular es «¿Se nota esta frase en su perfil hoy?» (lo pone un script dentro de la página, sin esperar al JS); si no, «¿Se nota su propuesta de valor en su perfil hoy?».
  - Campos, en este orden: red principal (tres botones), enlace o usuario del perfil (el ejemplo cambia con la red), correo, nombre (opcional), consentimiento y el recuadro de la llamada. Errores en lenguaje normal debajo de cada campo, sin borrar nada; sugerencia «¿Quiso decir…?» para errores típicos en gmail, hotmail, etc.; campo trampa `web` para robots.
  - Guarda en `/api/charla-diagnostico` (`php/charla-diagnostico.php`) y avisa al equipo por email. El id se genera al cargar el formulario y se guarda en `autoria_diagnostico_envio`: un doble toque, una recarga o un reintento con los mismos datos no duplican nada; si cambian los datos, es un envío nuevo. La fecha va en ISO con la zona horaria del móvil.
  - Lo escrito se guarda en el móvil (`autoria_diagnostico_borrador`, sin la casilla del consentimiento) por si la página se recarga al cambiar de aplicación (por ejemplo, para copiar el enlace del perfil); se borra al llegar el envío.
  - **Unirse a la comunidad es voluntario y no se pide para el diagnóstico.** Es la decisión del 03/11/2026, confirmada el 09/11/2026: ese día se probó como paso obligatorio y se quitó, porque condicionar el diagnóstico a unirse puede no cumplir el RGPD (el consentimiento tiene que ser libre, art. 7.4). No volver a hacerlo obligatorio.
  - **Reservar la llamada de 30 minutos es obligatorio para confirmar** (decisión del 12/11/2026). La llamada se presenta como parte del diagnóstico («los repasamos con usted en una llamada de 30 minutos»), así que no es un consentimiento aparte, como pasaba con la comunidad.
    - El recuadro oscuro «Para confirmar» va entre el consentimiento y el botón «Confirmar mi diagnóstico». Lleva «Reserve su llamada de 30 minutos.», el botón naranja «Reservar mi llamada →», que da tres pulsos al aparecer en pantalla, y la agenda de las charlas (`CHARLA_AGENDA_URL` en `lib/manifest.js`, distinta de `reservaUrl`).
    - Al abrir la agenda aparece la casilla «Ya he reservado mi llamada.» (marca `autoria_agenda_<código>` en el móvil). El botón de confirmar se ve apagado hasta marcarla; si se pulsa antes, explica qué falta.
    - La web no puede comprobar la reserva en Google Calendar: se fía de la casilla. Si `CHARLA_AGENDA_URL` está vacío, el paso no aparece.
  - Tras enviar: «Recibido.», «Mañana le llega a {correo}… Lo repasamos con usted en la llamada que ha reservado.» y, debajo, la comunidad: «Mientras tanto, únase a la comunidad de la charla en WhatsApp.» con un botón verde de WhatsApp (#25D366, con el logo de Simple Icons y «Unirme en WhatsApp») y «Firma lo que sabes Autoría…» debajo.
  - Las aperturas de la agenda se cuentan por charla (página `agenda` de `/api/visita`) y salen en `/panel?vista=charlas`. No van a la hoja: el script no tiene esa columna.
  - Sin conexión: «Casi listo» y cola en `autoria_diagnostico_cola`; «Recibido» y la comunidad solo cuando el envío llega. Los clics en WhatsApp se cuentan por charla.
- **`charla-registro.html` + `lib/charla-registro.js`** — inscripción de los docentes de la UAM a «Firma lo que sabes» desde el QR del flyer, en `/charla/registro?c=C01` (mismo código que las otras dos).
  - Textos con la misma revisión MECLABS que las otras dos: «Charla · Firma lo que sabes», «Reserve su plaza.», «Gratis · Confirmación al momento en su correo», una ayuda bajo cada pregunta que explica para qué se pide y «Un minuto. Solo usamos sus datos…» bajo el botón.
  - Campos: nombre y apellidos, correo (con «¿Quiso decir…?»), asignatura que imparte, «¿Tiene un negocio o trabaja fuera de la universidad?» (Sí/No) y consentimiento; campo trampa `web`. Sin enlace a la comunidad de WhatsApp.
  - Guarda en `/api/charla-registro` (`php/charla-registro.php`, tipo `charla-registro`), avisa al equipo por email y se copia en la pestaña **Inscripciones** de la hoja (tipo `registro`).
  - Mismo sistema de id y cola que el diagnóstico (`autoria_registro_envio` y `autoria_registro_cola`). Tras enviar: «Plaza reservada.», «En unos minutos le llega la confirmación a {correo}…» y «¿El correo no es correcto? Corregirlo» (corregir crea un envío nuevo; el anterior sigue en el panel).
  - **Correos automáticos a la persona** (única excepción a «nada se envía solo»), por el Gmail de los avisos (`enviar_correo()` en `_comun.php`, remitente «Autoría»):
    - la **confirmación** sale sola al inscribirse, después de contestar al móvil; una por correo y charla, y como mucho 250 al día (el Gmail admite unos 500 diarios, avisos al equipo incluidos);
    - el **recordatorio** lo lanza el equipo desde `/panel?vista=registros&c=CÓDIGO`, de 25 en 25, con la fecha, la hora y el lugar de la charla: se escriben en ese mismo panel (`charlas_info` en `config.php`) o, si no hay nada, salen de `CHARLAS_INFO` en `_comun.php` (C01: «el jueves 12 de noviembre a las 16:00», hora de Costa Rica). Se lee «Le esperamos …» en los dos correos, y `php/charla.php` lo pone también en la landing, delante de «Gratis»;
    - lo enviado queda en `autoria-privado/correos-registro.json` y se ve en el panel; los correos `@example.com` nunca se envían;
    - sin la contraseña de aplicación de Gmail (`/panel?vista=avisos`), salen por `mail()` desde no-reply@autoriaestudio.com con respuesta a admin.autoria@gmail.com (así llegaban los avisos el 09/11/2026).
  - Se ve en `/panel?vista=registros`, filtrable por código y con CSV; las visitas (`registro`) y los inscritos salen también en `/panel?vista=charlas`.
- **Medición de las charlas:** `/api/visita` (`php/visita.php`) cuenta una visita por móvil, página y código, sin cookies ni IP, en `autoria-privado/visitas.json`. El panel (`/panel?vista=charlas`) muestra visitas, propuestas y diagnósticos pedidos por código, y el CSV de las solicitudes.
- **`main.js`** — efectos y comportamiento de la portada:
  - anotaciones a mano (`data-mark` circle, underline, strike o tick), que el JS reescala al tamaño real;
  - textos que aparecen al hacer scroll (`data-split`, `data-reveal`);
  - GSAP con las tarjetas del método apiladas;
  - menú, formulario de contacto y asistente de chat.
- **`lib/manifest.js`** — contacto, ritmos de publicación (4/2, 6/3 y 8/4 vídeos y posts al mes) y suposiciones de la calculadora.
- **`styles.css`** — sistema de diseño:
  - colores: papel `#F5F2EB`, tinta `#15161C` y acento `#E9441F`;
  - tipografías Newsreader e Instrument Sans, alojadas en `assets/fonts`.
- **Páginas legales:** `privacidad.html`, `aviso-legal.html` y `creditos.html`.
- **`sitemap.xml`, `robots.txt` y `llms.txt`** — páginas indexables (sin las de las charlas, que llevan `noindex`), bloqueo de `/panel`, `/instalar` y `/api/`, y un resumen de Autoría para los buscadores con IA. Al añadir una página indexable, se añade al `sitemap.xml`.
- **`php/`** — la parte de servidor en Hostinger:
  - `_comun.php`: utilidades, Gemini, límites de uso y guardado;
  - `asistente.php`, `lector-perfil.php`, `diagnostico.php`, `contacto.php`, `propuesta.php`, `charla-diagnostico.php`, `charla-registro.php`, `visita.php`, `charla.php`, `panel.php` e `instalar.php`.
- **`.htaccess`:**
  - convierte `/api/(asistente|lector-perfil|diagnostico|contacto|propuesta|charla-diagnostico|charla-registro|visita)`, `/panel` y `/instalar` en los archivos `php/*.php`, y `/charla/(propuesta|diagnostico|registro)` en `php/charla.php`, que sirve `charla-*.html` con `styles.css` dentro de la página (una petición menos: con «3G», el formulario aparece unos 5 s antes);
  - bloquea `php/`, `api/`, `tools/`, `.git`, los archivos que empiezan por punto, `*.md` y los `*.json` de configuración.
- **`api/_conocimiento.js`, `api/_diagnostico.js` y `api/_lector-perfil.js`** — **fuente única** de las indicaciones de la IA. PHP las lee con `texto_js()`, que busca `` const NOMBRE = `…`; `` o `` export default `…`; ``. Por eso esos textos no pueden llevar comillas invertidas dentro.
  - Si cambian los campos del diagnóstico o del lector, hay que tocar **a la vez** `api/_*.js`, `php/*.php` y `lib/diagnostico.js`.
- **`assets/img`** — fotos de Unsplash en WebP:
  - `portada` (1024×1280), `problema` (1200×800), `servicio` (800×1000) y `voz` (900×900);
  - `og-autoria.jpg` (1200×630, solo texto);
  - los créditos están en `assets/credits.json` y en `creditos.html`.
- **Caché:** los CSS y JS se enlazan con `?v=AAAAMMDDx`. Hay que subir la versión en todos los `.html` al cambiar `styles.css`, `main.js` o `lib/*.js`.

## Servidor en Hostinger

- **Configuración:** `/instalar` se usa una sola vez y pide la clave de Gemini, la contraseña del panel y el email de avisos.
  - Se guarda en `autoria-privado/config.php`, **fuera de `public_html`**. La carpeta queda bloqueada con `.htaccess`.
  - Después la página se bloquea. Para cambiar algo, se borra ese `config.php` desde el Administrador de archivos y se vuelve a `/instalar`.
- **Hoja de Google «CRM de las charlas · Firma lo que sabes»** (ID `1k-7WB9EwgCDpRDYbo6MWDUoBy8-VdHDAX0XbdMQHsTM`, de la cuenta admin.autoria@gmail.com; pestañas Leads, Propuestas, Inscripciones y Contadores): cada envío de las charlas se copia también ahí desde el servidor, con su Apps Script publicado como aplicación web. Copia de referencia del script: `tools/apps-script-hoja.gs`. La plantilla original, de una cuenta personal, se llama «… descartado» y no se usa.
  - Si se cambia el código del script, hay que publicar una versión nueva: Implementar → Gestionar implementaciones → editar → Nueva versión (la URL no cambia).
  - La URL (`…/exec`) y el token van en `config.php` (`hoja_url` y `hoja_token`) y se guardan desde `/panel?vista=hoja`, que además los prueba sin escribir nada (manda el tipo «prueba» y el script contesta «tipo» si el token es bueno).
  - POST en JSON con cURL que sigue el 302 de Apps Script, 6 s como mucho y siempre después de contestar al móvil (`json_out_y_seguir`). Formatos: `diagnostico`, `propuesta`, `registro` y `contadores`, los que acepta el script. Un envío que el script rechaza por tipo («tipo») no frena el reenvío de los demás.
  - Si falla, el envío queda en `autoria-privado/hoja-pendientes.json` y se reintenta con el siguiente envío que sí llegue (hasta 20, uno solo a la vez). En `/panel?vista=hoja` se ven y se reenvían a mano; el script descarta los id repetidos.
  - Contadores (visitas a la dinámica, al diagnóstico y a la inscripción, y pulsaciones en la comunidad; la columna F es la de la inscripción): con cada envío y cada pulsación, como mucho una vez por minuto por charla y solo si han cambiado (`hoja-contadores.json`). «Reenviar pendientes» los pone al día: pulsarlo al acabar cada charla.
  - `guardar_config()` invalida OPcache; sin eso, otras peticiones siguen viendo unos segundos la configuración anterior.
- **Contactos y diagnósticos:** van a `autoria-privado/contactos.jsonl`.
  - Se ven en `/panel`, con contraseña, y se descargan como CSV para Excel (punto y coma y BOM UTF-8).
  - Los avisos por email van a la dirección de `/instalar`. Se envían por el SMTP de Gmail con una contraseña de aplicación que se guarda desde `/panel?vista=avisos` (`smtp_user` y `smtp_pass` en `config.php`). Sin ella se usa `mail()`, pero el dominio temporal tiene SPF `-all` y Gmail descarta esos correos.
- **Gemini:** la clave es de **pago**, con la facturación activada, porque los usuarios están en el EEE.
  - Los modelos se prueban en orden, con alternativa si uno falla (`MODELOS_CHAT` y `MODELOS_LECTURA` en `_comun.php`).
  - Se pide `thinkingLevel: minimal`; si el modelo no lo admite, se repite sin ese ajuste.
- **Límites de uso:** por IP (minuto y día) más un tope global diario. Los contadores van en archivos temporales, con la IP seudonimizada, y se borran a las 24 h.

## Reglas obligatorias del contenido

1. **Nada de precios, tarifas, planes, paquetes ni pagos** en la web, el asistente o el diagnóstico.
   - Todo lleva a **reservar una llamada**.
   - Lo único de condiciones que se puede decir es la permanencia mínima de 3 meses.
   - Tampoco se dice que «no hay planes» ni que «todo es a medida».
2. **No se prometen seguidores, visualizaciones, clientes ni resultados.**
3. **La voz es siempre la del cliente:** nada de voz en off de IA, avatares ni clonación.
4. **Imágenes:** nada de personas generadas con IA, porque vendemos autenticidad. Se usan fotos reales de banco de imágenes, sin logos legibles cuando se pueda, y nunca se presentan como clientes. Se descartan las cuentas de marca que puedan publicar imágenes hechas con IA.
5. **No se inventan datos:**
   - ni testimonios, ni casos, ni cifras;
   - el lector de perfiles deja en blanco lo que no aparece;
   - la idea de vídeo «Lo que vi» va siempre como plantilla («Cuenta [algo vivido]…»).
6. **El asistente:**
   - no dice que «no te quitará tiempo» (el cliente graba con la guía y aprueba guiones y cortes);
   - no dice que «basta con el móvil»;
   - no da números de teléfono.
7. **Redes:** se trabaja en las que encajan con el objetivo y el público de cada persona, no en todas. Para muchos profesionales LinkedIn es la base.
8. **Consentimiento:** todos los formularios llevan una casilla de consentimiento que enlaza a la política de privacidad.

## Seguridad

- **El repositorio es público.** Nunca se ponen claves, contraseñas ni datos de contactos en el repo, en el HTML ni en el JS.
- **Los datos privados están fuera del repositorio,** en `C:\Users\carlo\Desktop\Web Autoría - privado`:
  - el acceso al panel;
  - el `.env.local` antiguo;
  - las pruebas locales de Hostinger;
  - la copia exportada de la conversación de desarrollo, que contiene la clave de Gemini y **no se sube a ningún sitio**.
- **Navegador:** no se escriben contraseñas ni claves en formularios; las pone el usuario (`/instalar`, inicio de sesión en hPanel o en el panel).
- **Datos de prueba:** las pruebas contra la web publicada usan `prueba@example.com` y nombres que empiezan por «PRUEBA», o, mejor, el servidor local.

## Probar en local

- **Servidor que imita a Hostinger:** la configuración `autoria-hostinger` de `.claude/launch.json` ejecuta `php -S localhost:8089 -t . tools/router-php.php`.
- **Datos y configuración de prueba,** con una clave de Gemini real: `../Web Autoría - privado/hostinger-pruebas`.
- **Comprobaciones tras publicar:**
  - las páginas y todos los recursos responden 200;
  - `/php/`, `/api/*.js`, `/.git/config`, `*.md` y `package.json` responden 403;
  - `/api/*` por GET responde 405;
  - el asistente contesta por POST si la petición viene de la propia web;
  - `/instalar` muestra «La web ya está configurada».

## Pendiente

- **Hoja de Google:** borrar las filas de prueba (charla TEST y la fila EJEMPLO) antes de la charla del 12/11.
- **Equipo (oculto):** faltan fotos, cargos y bios. Hay huecos en `index.html`, sección `#equipo`, para Carlos, Mario, Adrián y José.
- **Caso real (oculto):** falta el vídeo del cliente, con su permiso, y sus datos. Hay huecos en la sección `#caso`.
- **SEO fuera del código:** dar de alta `autoriaestudio.com` en Google Search Console (verificación por DNS en Hostinger) y en Bing Webmaster Tools, enviar el `sitemap.xml` y poner la web en la página de empresa de LinkedIn.
- **eryoncompany.com caduca hacia el 19 de noviembre de 2026.** Hay que renovarlo si se va a usar para un subdominio.
- **Correos con Gmail:** el 09/11/2026 la contraseña de aplicación de Gmail no estaba puesta (los avisos llegaban desde no-reply@autoriaestudio.com). Conviene ponerla en `/panel?vista=avisos` para que las confirmaciones de las charlas salgan desde admin.autoria@gmail.com.
- **El panel tiene un registro de prueba** («PRUEBA (borrar) Lucía Ejemplo») que se puede borrar de `contactos.jsonl`.

## Forma de trabajar

- **Commits:** en español, cortos y descriptivos. `main` es lo publicado: hacer push es publicar.
- **Comprobación:** después de un cambio visible se revisa en escritorio y en móvil (375 px), sin scroll horizontal.
- **Explicaciones al usuario:** en español, sin tecnicismos innecesarios.
