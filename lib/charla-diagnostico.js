/* =============================================================
   AUTORÍA — cierre de la charla (/charla/diagnostico?c=C01)
   La persona dice cuál es su red y dónde está su perfil y deja su correo
   para recibir el diagnóstico. Si escribió su propuesta de valor en
   /charla/propuesta en este móvil, se enseña arriba y se envía con el resto.
   Cada envío lleva un id que se genera al cargar el formulario: un doble
   toque, una recarga o un reintento nunca crean dos solicitudes. Sin
   conexión, el envío queda en el móvil y se reintenta solo.
   ============================================================= */
(() => {
  "use strict";

  const $ = (s, el = document) => el.querySelector(s);
  const CLAVE_PROPUESTA = "autoria_propuesta";        // la guarda /charla/propuesta
  const CLAVE_COLA = "autoria_diagnostico_cola";      // envíos pendientes
  const CLAVE_ENVIO = "autoria_diagnostico_envio";    // id del envío y lo que se mandó con él
  const WHATSAPP = ((window.__BRAND__ || {}).WHATSAPP_COMMUNITY_URL || "").trim();

  const ls = {
    raw(k) { try { return localStorage.getItem(k); } catch (_) { return null; } },
    get(k) { try { return JSON.parse(localStorage.getItem(k) || "null"); } catch (_) { return null; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); return true; } catch (_) { return false; } }
  };

  // Código de la charla: ?c=C01 (solo letras, números y guiones).
  const charla = (new URLSearchParams(location.search).get("c") || "").trim().toUpperCase().replace(/[^A-Z0-9_-]/g, "").slice(0, 20);

  const form = $("[data-form]");
  const boton = $("[data-enviar]");
  const error = $("[data-error]");
  const campo = (n) => form.elements[n];
  const pasoForm = $('[data-paso="form"]');
  const pasoFinal = $('[data-paso="final"]');

  /* ---------- id y fecha del envío ---------- */
  function nuevoId() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return Date.now().toString(36) + "-" + Math.random().toString(36).slice(2, 10);
  }
  // ISO con la zona horaria del móvil (2026-11-12T17:05:00-06:00).
  function fechaLocal(d) {
    const p = (n) => String(Math.abs(n)).padStart(2, "0");
    const min = -d.getTimezoneOffset();
    return d.getFullYear() + "-" + p(d.getMonth() + 1) + "-" + p(d.getDate()) + "T" + p(d.getHours()) + ":" + p(d.getMinutes()) + ":" + p(d.getSeconds())
      + (min >= 0 ? "+" : "-") + p(Math.trunc(min / 60)) + ":" + p(min % 60);
  }
  // El id se genera al cargar y se guarda en el móvil. Si recarga y vuelve a enviar lo
  // mismo, sale con el mismo id y no se duplica; si cambia los datos (un correo
  // corregido, otra persona con el mismo móvil), es un envío nuevo con otro id.
  let envio = ls.get(CLAVE_ENVIO);
  if (!envio || !envio.id) { envio = { id: nuevoId(), firma: "", fecha: "" }; ls.set(CLAVE_ENVIO, envio); }

  /* ---------- contador anónimo de visitas (una por móvil y charla) ---------- */
  function contarVisita(pagina) {
    const marca = "autoria_visita_" + pagina + "_" + (charla || "-");
    if (ls.raw(marca)) return;
    fetch("/api/visita", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ pagina, charla }), keepalive: true })
      .then((r) => r.json()).then((j) => { if (j.ok) ls.set(marca, 1); }).catch(() => {});
  }

  /* ---------- propuesta de valor guardada ---------- */
  // El bloque «Su frase» y el titular ya los pone un pequeño script en la página;
  // aquí solo se recupera la frase para enviarla con el resto.
  function propuestaGuardada() {
    const v = ls.get(CLAVE_PROPUESTA);
    if (v && typeof v.frase === "string") return v.frase.trim();
    if (typeof v === "string") return v.trim();
    return "";
  }
  const frase = propuestaGuardada().slice(0, 400);

  /* ---------- errores, debajo de cada campo y sin borrar nada ---------- */
  const caja = { red: $("[data-redes]"), perfil: campo("perfil").closest(".field"), email: campo("email").closest(".field"), consent: $("[data-consent]") };
  function ponerError(n, texto) {
    const p = form.querySelector('[data-error-de="' + n + '"]');
    p.textContent = texto || "";
    p.hidden = !texto;
    caja[n].classList.toggle("is-invalid", !!texto);
    if (n !== "red") campo(n).setAttribute("aria-invalid", texto ? "true" : "false");
  }
  function errorEmail(v) {
    if (!v) return "Escriba su correo: ahí le llega el diagnóstico.";
    if (/\s/.test(v)) return "Revise el correo: no puede llevar espacios.";
    const arrobas = (v.match(/@/g) || []).length;
    if (!arrobas) return "Revise el correo: le falta la @.";
    if (arrobas > 1) return "Revise el correo: tiene más de una @.";
    const [usuario, dominio] = v.split("@");
    if (!usuario) return "Revise el correo: falta lo que va antes de la @.";
    if (!dominio) return "Revise el correo: falta lo que va después de la @.";
    if (!/^[^.]+(\.[^.]+)*\.[^.]{2,}$/.test(dominio)) return "Revise el correo: parece que falta el final, por ejemplo .com.";
    if (v.length > 160) return "Revise el correo: es demasiado largo.";
    return "";
  }
  // Acepta el enlace completo, @usuario o el usuario sin @.
  function errorPerfil(v) {
    if (!v) return "Escriba el enlace o el usuario de su perfil.";
    if (v.replace(/^@+/, "").length < 2) return "Revise el perfil: escriba el enlace completo o su usuario.";
    return "";
  }

  // «ana@gmial.com» → «¿Quiso decir ana@gmail.com?» (solo una sugerencia: no bloquea).
  const DOMINIOS = ["gmail.com", "hotmail.com", "outlook.com", "yahoo.com", "icloud.com", "live.com", "hotmail.es", "outlook.es", "yahoo.es"];
  function distancia(a, b) {
    let prev = Array.from({ length: b.length + 1 }, (_, j) => j);
    for (let i = 1; i <= a.length; i++) {
      const fila = [i];
      for (let j = 1; j <= b.length; j++) fila[j] = Math.min(prev[j] + 1, fila[j - 1] + 1, prev[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
      prev = fila;
    }
    return prev[b.length];
  }
  function sugerencia(v) {
    const i = v.lastIndexOf("@");
    const dom = v.slice(i + 1).toLowerCase();
    if (i < 1 || !dom || DOMINIOS.includes(dom)) return "";
    let mejor = "", min = 3;
    DOMINIOS.forEach((d) => { const k = d[0] === dom[0] ? distancia(dom, d) : 9; if (k < min) { min = k; mejor = d; } });
    return mejor ? v.slice(0, i + 1) + mejor : "";
  }
  function pintarSugerencia() {
    const v = campo("email").value.trim();
    const s = errorEmail(v) ? "" : sugerencia(v);
    $("[data-sugerencia-texto]").textContent = s;
    $("[data-sugerencia]").hidden = !s;
  }
  $("[data-sugerencia-usar]").addEventListener("click", () => {
    campo("email").value = $("[data-sugerencia-texto]").textContent;
    $("[data-sugerencia]").hidden = true;
    ponerError("email", "");
    campo("email").focus();
  });

  const PISTA = { LinkedIn: "linkedin.com/in/su-nombre", Instagram: "@su_usuario", TikTok: "@su_usuario" };
  form.addEventListener("input", (e) => {
    error.hidden = true;
    if (e.target.name in caja) ponerError(e.target.name, "");
    if (e.target.name === "email") $("[data-sugerencia]").hidden = true;
  });
  form.addEventListener("change", (e) => {
    error.hidden = true;
    if (e.target.name === "red") { campo("perfil").placeholder = PISTA[e.target.value]; ponerError("red", ""); }
    if (e.target.name === "consent" && e.target.checked) ponerError("consent", "");
  });
  // El correo se revisa al salir del campo (si ya hay algo escrito).
  campo("email").addEventListener("blur", () => {
    const v = campo("email").value.trim();
    if (v) ponerError("email", errorEmail(v));
    pintarSugerencia();
  });

  function datos() {
    return {
      red: (form.querySelector('input[name="red"]:checked') || {}).value || "",
      perfil: campo("perfil").value.trim(),
      email: campo("email").value.trim(),
      nombre: campo("nombre").value.trim(),
      propuesta: frase,
      consent: campo("consent").checked,
      web: campo("web").value
    };
  }
  function enfocar(n) { (n === "red" ? form.querySelector('input[name="red"]') : campo(n)).focus(); }
  function mensajeDe(n, d) {
    if (n === "red") return "Elija su red principal.";
    if (n === "perfil") return errorPerfil(d.perfil) || "Revise el enlace o el usuario de su perfil.";
    if (n === "email") return errorEmail(d.email) || "Revise el correo: así no podemos enviarle el diagnóstico.";
    return "Marque la casilla para que podamos enviarle el diagnóstico.";
  }

  /* ---------- envío con reintentos ---------- */
  let reintento = null, espera = 4000, enviando = false;
  const cola = () => ls.get(CLAVE_COLA) || [];
  const quitar = (id) => ls.set(CLAVE_COLA, cola().filter((x) => x.id !== id));

  async function enviar(r) {
    try {
      const ctrl = "AbortController" in window ? new AbortController() : null;
      const t = ctrl && setTimeout(() => ctrl.abort(), 15000);
      const res = await fetch("/api/charla-diagnostico", {
        method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(r), signal: ctrl ? ctrl.signal : undefined
      });
      if (t) clearTimeout(t);
      const j = await res.json().catch(() => ({}));
      if (j.ok) return { tipo: "ok" };
      if (j.reason === "datos" || j.reason === "consent") return { tipo: "datos", campo: j.campo || "" };
      return { tipo: "fallo" };   // límite de uso o error del servidor: se reintenta
    } catch (_) { return { tipo: "fallo" }; }   // sin conexión
  }

  async function enviarCola() {
    if (enviando) return;
    const pendientes = cola();
    if (!pendientes.length) return;
    enviando = true;
    clearTimeout(reintento);
    let falta = null;
    for (const r of pendientes) {
      const res = await enviar(r);
      if (res.tipo === "ok") { quitar(r.id); mostrarFinal(r, "ok"); }
      else if (res.tipo === "datos") { quitar(r.id); volverAlForm(r, res.campo); }
      else falta = r;
    }
    enviando = false;
    if (falta) {
      mostrarFinal(falta, "pendiente");
      reintento = setTimeout(enviarCola, espera);
      espera = Math.min(espera * 2, 60000);
    } else espera = 4000;
  }

  /* ---------- pantallas ---------- */
  function mostrarFinal(r, tipo) {
    const ok = tipo === "ok";
    $("[data-final-titulo]").textContent = ok ? "Recibido." : "Casi listo.";
    const texto = $("[data-final-texto]");
    texto.textContent = "";
    const correo = document.createElement("strong");
    correo.textContent = r.email;
    texto.append(ok ? "Mañana le llega a " : "En cuanto se envíe, mañana le llegará a ", correo, " el diagnóstico con tres cambios concretos para su perfil.");
    const aviso = $("[data-final-aviso]");
    aviso.hidden = ok;
    aviso.textContent = ok ? "" : "Sin conexión: su solicitud está guardada en este móvil y se enviará sola en cuanto haya red. Por favor, no cierre esta página hasta que vea «Recibido».";
    $("[data-reintentar]").hidden = ok;
    // La comunidad de la charla, como paso principal, solo cuando el envío ha llegado.
    $("[data-ultimo]").hidden = !ok || !WHATSAPP;
    if (WHATSAPP) $("[data-whatsapp]").href = WHATSAPP;
    const yaVisible = !pasoFinal.hidden;
    pasoForm.hidden = true;
    pasoFinal.hidden = false;
    if (!yaVisible) { window.scrollTo(0, 0); $("[data-final-titulo]").focus({ preventScroll: true }); }
  }

  // El servidor no acepta los datos: se vuelve al formulario con todo lo escrito y el error en su campo.
  function volverAlForm(r, nombreCampo) {
    pasoFinal.hidden = true;
    pasoForm.hidden = false;
    campo("email").value = r.email; campo("nombre").value = r.nombre; campo("perfil").value = r.perfil;
    const radio = form.querySelector('input[name="red"][value="' + r.red + '"]'); if (radio) radio.checked = true;
    boton.disabled = false; boton.textContent = "Quiero mi diagnóstico";
    if (nombreCampo in caja) { ponerError(nombreCampo, mensajeDe(nombreCampo, r)); enfocar(nombreCampo); }
    else { error.textContent = "Revise los datos: falta algo o el correo no es válido."; error.hidden = false; }
  }

  form.addEventListener("submit", (e) => {
    e.preventDefault();
    if (boton.disabled) return;   // doble toque
    const d = datos();
    const errores = {
      red: d.red ? "" : mensajeDe("red", d),
      perfil: errorPerfil(d.perfil),
      email: errorEmail(d.email),
      consent: d.consent ? "" : mensajeDe("consent", d)
    };
    Object.keys(errores).forEach((n) => ponerError(n, errores[n]));
    const primero = ["red", "perfil", "email", "consent"].find((n) => errores[n]);
    if (primero) { enfocar(primero); return; }
    error.hidden = true;
    boton.disabled = true;
    boton.textContent = "Enviando…";
    // Mismos datos que el último envío con este id: se reutiliza el id (no se duplica).
    const firma = JSON.stringify([charla, d.red, d.perfil, d.email.toLowerCase(), d.nombre, d.propuesta]);
    if (envio.firma && envio.firma !== firma) envio = { id: nuevoId(), firma: "", fecha: "" };
    if (!envio.firma) { envio.firma = firma; envio.fecha = fechaLocal(new Date()); }
    ls.set(CLAVE_ENVIO, envio);
    const r = Object.assign({ id: envio.id, fecha: envio.fecha, charla }, d);
    ls.set(CLAVE_COLA, cola().filter((x) => x.id !== r.id).concat(r));
    espera = 4000;
    enviarCola();
  });

  // Cuenta (de forma anónima) cuántos pulsan el botón de la comunidad, por charla.
  $("[data-whatsapp]").addEventListener("click", () => contarVisita("whatsapp"));

  $("[data-reintentar]").addEventListener("click", () => { espera = 4000; enviarCola(); });

  // Último intento al cerrar o cambiar de app (si llega dos veces, el servidor lo cuenta una).
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "hidden") {
      if (navigator.sendBeacon) cola().forEach((r) => navigator.sendBeacon("/api/charla-diagnostico", new Blob([JSON.stringify(r)], { type: "application/json" })));
    } else enviarCola();
  });
  window.addEventListener("online", () => { espera = 4000; enviarCola(); });

  /* ---------- arranque ---------- */
  contarVisita("diagnostico");
  if (cola().length) { mostrarFinal(cola()[0], "pendiente"); enviarCola(); }   // pendiente de una visita anterior
})();
