/* =============================================================
   AUTORÍA — cierre de la charla (/charla/diagnostico?c=C01)
   El asistente deja su correo para recibir el diagnóstico de su perfil.
   Recupera la propuesta de valor que escribió en /charla/propuesta.
   Si no hay conexión, el envío queda en el móvil y se reintenta solo;
   un doble clic o un reintento nunca crean dos solicitudes.
   ============================================================= */
(() => {
  "use strict";

  const $ = (s, el = document) => el.querySelector(s);
  const CLAVE_PROPUESTA = "autoria_propuesta";          // la guarda /charla/propuesta
  const CLAVE_COLA = "autoria_diagnostico_cola";        // envíos pendientes
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

  /* ---------- contador anónimo de visitas (una por móvil y charla) ---------- */
  function contarVisita(pagina) {
    const marca = "autoria_visita_" + pagina + "_" + (charla || "-");
    if (ls.raw(marca)) return;
    fetch("/api/visita", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ pagina, charla }), keepalive: true })
      .then((r) => r.json()).then((j) => { if (j.ok) ls.set(marca, 1); }).catch(() => {});
  }

  /* ---------- propuesta de valor guardada ---------- */
  // Puede ser el JSON de /charla/propuesta o, por si acaso, la frase sola.
  function propuestaGuardada() {
    const v = ls.get(CLAVE_PROPUESTA);
    if (v && typeof v.frase === "string") return v.frase.trim();
    if (typeof v === "string") return v.trim();
    return "";
  }
  const frase = propuestaGuardada().slice(0, 400);
  if (frase) {
    $("[data-frase-texto]").textContent = frase;
    $("[data-frase-box]").hidden = false;
  } else {
    $("[data-frase-campo]").hidden = false;
  }

  /* ---------- validación ---------- */
  const esEmail = (s) => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(s) && s.length <= 160;
  const PISTA = { LinkedIn: "linkedin.com/in/su-nombre", Instagram: "@usuario o instagram.com/usuario", TikTok: "@usuario o tiktok.com/@usuario" };
  // Al corregir cualquier campo, el aviso general desaparece (se recalcula al volver a enviar).
  form.addEventListener("input", () => { error.hidden = true; });
  form.addEventListener("change", (e) => {
    error.hidden = true;
    if (e.target.name === "red") { campo("perfil").placeholder = PISTA[e.target.value]; $("[data-redes]").classList.remove("is-invalid"); }
    if (e.target.name === "consent") $("[data-consent]").classList.remove("is-invalid");
  });
  ["email", "perfil"].forEach((n) => campo(n).addEventListener("input", () => campo(n).closest(".field").classList.remove("is-invalid")));
  campo("email").addEventListener("blur", () => {
    const v = campo("email").value.trim();
    const mal = v !== "" && !esEmail(v);
    $("[data-error-email]").hidden = !mal;
    campo("email").closest(".field").classList.toggle("is-invalid", mal);
  });

  function validar() {
    const d = {
      email: campo("email").value.trim(),
      nombre: campo("nombre").value.trim(),
      red: (form.querySelector('input[name="red"]:checked') || {}).value || "",
      perfil: campo("perfil").value.trim(),
      propuesta: frase || campo("propuesta").value.trim(),
      consent: campo("consent").checked
    };
    const fallos = [];
    if (!esEmail(d.email)) fallos.push(["email", campo("email"), "Escriba un correo válido."]);
    if (!d.red) fallos.push(["red", form.querySelector('input[name="red"]'), "Elija su red principal."]);
    if (!d.perfil) fallos.push(["perfil", campo("perfil"), "Indique el enlace o el usuario de su perfil."]);
    if (!d.consent) fallos.push(["consent", campo("consent"), "Para enviarle el diagnóstico necesitamos su permiso: marque la casilla."]);
    campo("email").closest(".field").classList.toggle("is-invalid", !esEmail(d.email));
    $("[data-error-email]").hidden = esEmail(d.email) || d.email === "";
    $("[data-redes]").classList.toggle("is-invalid", !d.red);
    campo("perfil").closest(".field").classList.toggle("is-invalid", !d.perfil);
    $("[data-consent]").classList.toggle("is-invalid", !d.consent);
    return { d, fallos };
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
      if (j.ok) return "ok";
      if (j.reason === "datos" || j.reason === "consent") return "datos";
      return "fallo";   // límite de uso o error del servidor: se reintenta
    } catch (_) { return "fallo"; }   // sin conexión
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
      if (res === "ok") { quitar(r.id); mostrarFinal(r, "ok"); }
      else if (res === "datos") { quitar(r.id); volverAlForm(r, "Revise los datos: falta algo o el correo no es válido."); }
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
    $("[data-final-titulo]").textContent = ok ? "Listo." : "Casi listo.";
    const texto = $("[data-final-texto]");
    texto.textContent = "";
    texto.append(ok ? "Mañana le llega su diagnóstico a " : "En cuanto se envíe, mañana le llegará su diagnóstico a ");
    const b = document.createElement("strong"); b.textContent = r.email; texto.append(b, ".");
    const aviso = $("[data-final-aviso]");
    aviso.hidden = ok;
    aviso.textContent = ok ? "" : "Sin conexión: su solicitud está guardada en este móvil y se enviará sola en cuanto haya red. Por favor, no cierre esta página hasta que vea «Listo».";
    $("[data-reintentar]").hidden = ok;
    // Último paso, destacado: la comunidad de WhatsApp (solo cuando el envío ha llegado).
    $("[data-ultimo]").hidden = !ok || !WHATSAPP;
    if (WHATSAPP) $("[data-whatsapp]").href = WHATSAPP;
    const yaVisible = !pasoFinal.hidden;
    pasoForm.hidden = true;
    pasoFinal.hidden = false;
    if (!yaVisible) { window.scrollTo(0, 0); $("[data-final-titulo]").focus({ preventScroll: true }); }
  }

  function volverAlForm(r, mensaje) {
    pasoFinal.hidden = true;
    pasoForm.hidden = false;
    campo("email").value = r.email; campo("nombre").value = r.nombre; campo("perfil").value = r.perfil;
    const radio = form.querySelector('input[name="red"][value="' + r.red + '"]'); if (radio) radio.checked = true;
    error.textContent = mensaje; error.hidden = false;
    boton.disabled = false; boton.textContent = "Quiero mi diagnóstico";
  }

  function nuevoId() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return Date.now().toString(36) + "-" + Math.random().toString(36).slice(2, 10);
  }

  form.addEventListener("submit", (e) => {
    e.preventDefault();
    if (boton.disabled) return;   // doble clic
    const { d, fallos } = validar();
    if (fallos.length) {
      error.textContent = fallos.map((f) => f[2]).join(" ");
      error.hidden = false;
      fallos[0][1].focus();
      return;
    }
    error.hidden = true;
    boton.disabled = true;
    boton.textContent = "Enviando…";
    // Si ya hay una solicitud pendiente con el mismo correo, se reutiliza en vez de duplicarla.
    const previa = cola().find((x) => x.email.toLowerCase() === d.email.toLowerCase() && x.charla === charla);
    const r = Object.assign({}, previa || { id: nuevoId(), fecha: new Date().toISOString() }, d, { charla });
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
