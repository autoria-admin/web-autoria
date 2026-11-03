# Autoría — contexto del proyecto

Web de **Autoría**, la agencia de marca personal en **redes sociales** (LinkedIn, Instagram, YouTube, TikTok…) para empresarios, directivos y profesionales expertos de España y Costa Rica. Todo el contenido son vídeos contados con la propia voz del cliente.

- **Titular y entidad que factura:** Asociación Junior Empresa ERYON (Madrid), NIF G23915176, Plaza de San Martín, 1, 28013 Madrid. Email de privacidad: eryoncompany@gmail.com.
- **Contacto público:** admin.autoria@gmail.com y la página de empresa de LinkedIn. La agenda de llamadas es de Google Calendar. Los tres están en `lib/manifest.js`. No hay WhatsApp ni teléfono público de contacto; la comunidad de WhatsApp (`WHATSAPP_COMMUNITY_URL` en `lib/manifest.js`) solo aparece al final de `/charla/diagnostico`.
- **Idioma y tono:** todo en español, tuteando. El tono es editorial, sobrio y profesional, sin jerga de marketing ni emojis.

Hasta el 22/10/2026 el enfoque era solo LinkedIn; ahora es redes sociales en general. Que no vuelva a aparecer «solo LinkedIn» salvo donde tenga sentido, como el PDF de LinkedIn en el diagnóstico.

## Dónde está publicada

- **Hostinger, plan Business.** El sitio es `silver-alligator-184608.hostingersite.com`, un dominio temporal; el dominio definitivo está pendiente.
- **Publicación automática.** Cada `git push` a `main` del repositorio **público** `github.com/autoria-admin/web-autoria` (antes `caarloossc/web-autoria`, que redirige) se despliega solo en `public_html`, mediante la aplicación de GitHub de Hostinger. Tarda unos 10–20 s.
- **En hPanel:** Sitios web → Administrar sitio web → Avanzado → GIT / Configuración de PHP (8.3).
- **No tocar la web de la empresa** (`eryoncompany.eryoncompany.com`, WordPress) ni su `public_html`.
- **Vercel ya no existe.** Se borró en octubre de 2026. Los archivos `api/*.js`, `vercel.json` y `tools/dev-server.mjs` se conservan porque PHP lee de `api/` los textos de la IA (ver más abajo).

## Estructura

- **`index.html`** — portada y secciones numeradas:
  1. Portada
  2. Problema («El experto invisible»)
  3. Método (cuatro tiempos)
  4. Qué hacemos
  5. Contacto
  6. Caso real
  7. Equipo
  8. Diagnóstico
  9. Preguntas
- **`diagnostico.html` + `lib/diagnostico.js`** — el diagnóstico gratis.
  - Pasos: perfil, objetivo, público, frecuencia, tiempo y cámara, calculadora, email y consentimiento, informe.
  - Perfil: capturas de cualquier red o PDF de LinkedIn, leídos con IA. Lo que no aparece se deja en blanco.
  - Informe: puntos fuertes y débiles, bio o titular propuesto, «Dónde centrarte» (1–2 redes), tres ideas de vídeo, ritmo recomendado y llamada.
- **`charla-propuesta.html` + `lib/charla.js`** — dinámica de las charlas, en `/charla/propuesta?c=C01` (código de la charla).
  - No sale en el menú y lleva `noindex`. Trato de usted.
  - Tres campos (para, aporto, gracias a), la frase en vivo, «Listo» y la frase en grande para leerla en voz alta.
  - Guarda anónimo en `/api/propuesta` (`php/propuesta.php`): sin nombre ni correo y sin aviso por email. Se ve en `/panel?vista=propuestas`, filtrable por código y con CSV.
  - La frase queda en `localStorage` con la clave `autoria_propuesta` (JSON con `frase`, `para`, `aporto`, `gracias_a`, `charla` y `fecha`) para que la recupere el diagnóstico. Sin conexión, los envíos esperan en `autoria_propuesta_cola` y se reintentan solos.
- **`charla-diagnostico.html` + `lib/charla-diagnostico.js`** — cierre de las charlas, en `/charla/diagnostico?c=C01`.
  - Correo (obligatorio), nombre (opcional), red principal (LinkedIn, Instagram o TikTok), enlace o usuario del perfil y casilla de consentimiento. Muestra «Su frase» si `autoria_propuesta` está en el móvil; si no, un campo opcional para pegarla.
  - Guarda en `/api/charla-diagnostico` (`php/charla-diagnostico.php`) y avisa al equipo por email de cada envío. El id lo pone el móvil, así que los reintentos y los dobles clics no duplican ni el registro ni el aviso.
  - Sin conexión: «Casi listo» y cola en `autoria_diagnostico_cola`; «Listo» y el recuadro «Último paso» con el botón de la comunidad de WhatsApp solo cuando el envío llega. Unirse **no es obligatorio** (decisión del 03/11/2026: se envía primero el correo y la comunidad es el último paso destacado). Los clics en el botón se cuentan por charla.
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
- **`php/`** — la parte de servidor en Hostinger:
  - `_comun.php`: utilidades, Gemini, límites de uso y guardado;
  - `asistente.php`, `lector-perfil.php`, `diagnostico.php`, `contacto.php`, `propuesta.php`, `charla-diagnostico.php`, `visita.php`, `panel.php` e `instalar.php`.
- **`.htaccess`:**
  - convierte `/api/(asistente|lector-perfil|diagnostico|contacto|propuesta|charla-diagnostico|visita)`, `/panel` y `/instalar` en los archivos `php/*.php`, y `/charla/(propuesta|diagnostico)` en `charla-*.html`;
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

- **Equipo:** faltan fotos, cargos y bios. Hay huecos en `index.html`, sección 7, para Carlos, Mario, Adrián y José.
- **Caso real:** falta el vídeo del cliente, con su permiso, y sus datos. Hay huecos en la sección 6.
- **Dominio definitivo.** Cuando lo haya, hay que cambiar `og:image`, `og:url` y `canonical` en `index.html` y `diagnostico.html`, y revisar el SSL.
- **eryoncompany.com caduca hacia el 19 de noviembre de 2026.** Hay que renovarlo si se va a usar para un subdominio.
- **El panel tiene un registro de prueba** («PRUEBA (borrar) Lucía Ejemplo») que se puede borrar de `contactos.jsonl`.

## Forma de trabajar

- **Commits:** en español, cortos y descriptivos. `main` es lo publicado: hacer push es publicar.
- **Comprobación:** después de un cambio visible se revisa en escritorio y en móvil (375 px), sin scroll horizontal.
- **Explicaciones al usuario:** en español, sin tecnicismos innecesarios.
