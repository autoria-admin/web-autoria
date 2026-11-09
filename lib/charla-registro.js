/* =============================================================
   AUTORÍA — inscripción a la charla (/charla/registro?c=C01)
   Se abre con el QR del flyer. La persona deja su nombre y apellidos, su
   correo, la asignatura que imparte y si tiene un negocio o trabaja fuera de
   la universidad. Igual que en /charla/diagnostico, cada envío lleva un id
   que se genera al cargar: un doble toque, una recarga o un reintento no
   duplican nada, y sin conexión el envío espera en el móvil.
   ============================================================= */
(() => {
  "use strict";

  const $ = (s, el = document) => el.querySelector(s);
  const CLAVE_COLA = "autoria_registro_cola";     // envíos pendientes
  const CLAVE_ENVIO = "autoria_registro_envio";   // id del envío y lo que se mandó con él

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
  const TEXTO_BOTON = boton.textContent;

  /* ---------- id y fecha del envío ---------- */
  function nuevoId() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return Date.now().toString(36) + "-" + Math.random().toString(36).slice(2, 10);
  }
  // ISO con la zona horaria del móvil (2026-11-12T17:05:00+01:00).
  function fechaLocal(d) {
    const p = (n) => String(Math.abs(n)).padStart(2, "0");
    const min = -d.getTimezoneOffset();
    return d.getFullYear() + "-" + p(d.getMonth() + 1) + "-" + p(d.getDate()) + "T" + p(d.getHours()) + ":" + p(d.getMinutes()) + ":" + p(d.getSeconds())
      + (min >= 0 ? "+" : "-") + p(Math.trunc(min / 60)) + ":" + p(min % 60);
  }
  let envio = ls.get(CLAVE_ENVIO);
  if (!envio || !envio.id) { envio = { id: nuevoId(), firma: "", fecha: "" }; ls.set(CLAVE_ENVIO, envio); }

  /* ---------- contador anónimo de visitas (una por móvil y charla) ---------- */
  function contarVisita(pagina) {
    const marca = "autoria_visita_" + pagina + "_" + (charla || "-");
    if (ls.raw(marca)) return;
    fetch("/api/visita", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ pagina, charla }), keepalive: true })
      .then((r) => r.json()).then((j) => { if (j.ok) ls.set(marca, 1); }).catch(() => {});
  }

  /* ---------- errores, debajo de cada campo y sin borrar nada ---------- */
  const ORDEN = ["nombre", "email", "asignatura", "trabajo", "consent"];
  const caja = {
    nombre: campo("nombre").closest(".field"), email: campo("email").closest(".field"),
    asignatura: campo("asignatura").closest(".field"), trabajo: $("[data-trabajo]"), consent: $("[data-consent]")
  };
  function ponerError(n, texto) {
    const p = form.querySelector('[data-error-de="' + n + '"]');
    p.textContent = texto || "";
    p.hidden = !texto;
    caja[n].classList.toggle("is-invalid", !!texto);
    if (n !== "trabajo") campo(n).setAttribute("aria-invalid", texto ? "true" : "false");
  }
  function errorEmail(v) {
    if (!v) return "Escriba su correo: ahí le llega la confirmación de su plaza.";
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
  function errorNombre(v) {
    if (!v) return "Escriba su nombre y sus apellidos.";
    if (!/\S+\s+\S+/.test(v)) return "Escriba también sus apellidos.";
    return "";
  }
  function mensajeDe(n, d) {
    if (n === "nombre") return errorNombre(d.nombre) || "Revise su nombre y sus apellidos.";
    if (n === "email") return errorEmail(d.email) || "Revise el correo: así no podemos enviarle la confirmación.";
    if (n === "asignatura") return d.asignatura ? "Revise la asignatura." : "Escriba la asignatura que imparte.";
    if (n === "trabajo") return "Elija sí o no.";
    return "Marque la casilla para que podamos gestionar su inscripción.";
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

  form.addEventListener("input", (e) => {
    error.hidden = true;
    if (e.target.name in caja && e.target.name !== "trabajo") ponerError(e.target.name, "");
    if (e.target.name === "email") $("[data-sugerencia]").hidden = true;
  });
  form.addEventListener("change", (e) => {
    error.hidden = true;
    if (e.target.name === "trabajo") ponerError("trabajo", "");
    if (e.target.name === "consent" && e.target.checked) ponerError("consent", "");
  });
  campo("email").addEventListener("blur", () => {
    const v = campo("email").value.trim();
    if (v) ponerError("email", errorEmail(v));
    pintarSugerencia();
  });

  function datos() {
    return {
      nombre: campo("nombre").value.trim().replace(/\s+/g, " "),
      email: campo("email").value.trim(),
      asignatura: campo("asignatura").value.trim().replace(/\s+/g, " "),
      trabajo: (form.querySelector('input[name="trabajo"]:checked') || {}).value || "",
      consent: campo("consent").checked,
      web: campo("web").value
    };
  }
  function enfocar(n) { (n === "trabajo" ? form.querySelector('input[name="trabajo"]') : campo(n)).focus(); }

  /* ---------- envío con reintentos ---------- */
  let reintento = null, espera = 4000, enviando = false, ultimo = null;
  const cola = () => ls.get(CLAVE_COLA) || [];
  const quitar = (id) => ls.set(CLAVE_COLA, cola().filter((x) => x.id !== id));

  async function enviar(r) {
    try {
      const ctrl = "AbortController" in window ? new AbortController() : null;
      const t = ctrl && setTimeout(() => ctrl.abort(), 15000);
      const res = await fetch("/api/charla-registro", {
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
    $("[data-final-titulo]").textContent = ok ? "Plaza reservada." : "Casi listo.";
    const texto = $("[data-final-texto]");
    texto.textContent = "";
    const correo = document.createElement("strong");
    correo.textContent = r.email;
    texto.append(ok ? "En unos minutos le llega la confirmación a " : "En cuanto se envíe, su plaza quedará reservada y le llegará la confirmación a ", correo,
      ok ? ". Si no la ve, mire en la carpeta de correo no deseado. Antes de la charla le enviaremos un recordatorio." : ".");
    const aviso = $("[data-final-aviso]");
    aviso.hidden = ok;
    aviso.textContent = ok ? "" : "Sin conexión: su inscripción está guardada en este móvil y se enviará sola en cuanto haya red. Por favor, no cierre esta página hasta que vea «Plaza reservada».";
    $("[data-reintentar]").hidden = ok;
    $("[data-corregir]").hidden = !ok;
    ultimo = r;
    const yaVisible = !pasoFinal.hidden;
    pasoForm.hidden = true;
    pasoFinal.hidden = false;
    if (!yaVisible) { window.scrollTo(0, 0); $("[data-final-titulo]").focus({ preventScroll: true }); }
  }

  // El servidor no acepta los datos: se vuelve al formulario con todo lo escrito y el error en su campo.
  function volverAlForm(r, nombreCampo) {
    pasoFinal.hidden = true;
    pasoForm.hidden = false;
    campo("nombre").value = r.nombre; campo("email").value = r.email; campo("asignatura").value = r.asignatura;
    const radio = form.querySelector('input[name="trabajo"][value="' + r.trabajo + '"]'); if (radio) radio.checked = true;
    boton.disabled = false; boton.textContent = TEXTO_BOTON;
    if (nombreCampo in caja) { ponerError(nombreCampo, mensajeDe(nombreCampo, r)); enfocar(nombreCampo); }
    else { error.textContent = "Revise los datos: falta algo o el correo no es válido."; error.hidden = false; }
  }

  form.addEventListener("submit", (e) => {
    e.preventDefault();
    if (boton.disabled) return;   // doble toque
    const d = datos();
    const errores = {
      nombre: errorNombre(d.nombre),
      email: errorEmail(d.email),
      asignatura: d.asignatura ? "" : mensajeDe("asignatura", d),
      trabajo: d.trabajo ? "" : mensajeDe("trabajo", d),
      consent: d.consent ? "" : mensajeDe("consent", d)
    };
    ORDEN.forEach((n) => ponerError(n, errores[n]));
    const primero = ORDEN.find((n) => errores[n]);
    if (primero) { enfocar(primero); return; }
    error.hidden = true;
    boton.disabled = true;
    boton.textContent = "Enviando…";
    // Mismos datos que el último envío con este id: se reutiliza el id (no se duplica).
    const firma = JSON.stringify([charla, d.nombre, d.email.toLowerCase(), d.asignatura, d.trabajo]);
    if (envio.firma && envio.firma !== firma) envio = { id: nuevoId(), firma: "", fecha: "" };
    if (!envio.firma) { envio.firma = firma; envio.fecha = fechaLocal(new Date()); }
    ls.set(CLAVE_ENVIO, envio);
    const r = Object.assign({ id: envio.id, fecha: envio.fecha, charla }, d);
    ls.set(CLAVE_COLA, cola().filter((x) => x.id !== r.id).concat(r));
    espera = 4000;
    enviarCola();
  });

  $("[data-reintentar]").addEventListener("click", () => { espera = 4000; enviarCola(); });

  // Vuelve al formulario con todo lo escrito y el correo listo para corregir.
  // Al cambiar el correo es un envío nuevo (otro id); el anterior queda en el panel.
  $("[data-corregir]").addEventListener("click", () => {
    if (!ultimo) return;
    volverAlForm(ultimo, "");
    error.hidden = true;
    campo("consent").checked = true;
    campo("email").focus();
    campo("email").select();
  });

  // Último intento al cerrar o cambiar de app (si llega dos veces, el servidor lo cuenta una).
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "hidden") {
      if (navigator.sendBeacon) cola().forEach((r) => navigator.sendBeacon("/api/charla-registro", new Blob([JSON.stringify(r)], { type: "application/json" })));
    } else enviarCola();
  });
  window.addEventListener("online", () => { espera = 4000; enviarCola(); });

  /* ---------- arranque ---------- */
  contarVisita("registro");
  if (cola().length) { mostrarFinal(cola()[0], "pendiente"); enviarCola(); }   // pendiente de una visita anterior
})();
