/* =============================================================
   AUTORÍA — dinámica de la charla (/charla/propuesta?c=C01)
   El asistente escribe su propuesta de valor, la lee en voz alta y la
   respuesta se guarda de forma anónima. Si no hay conexión, la frase se
   muestra igual, queda en el móvil y el envío se reintenta solo.
   ============================================================= */
(() => {
  "use strict";

  const $ = (s, el = document) => el.querySelector(s);
  const CLAVE = "autoria_propuesta";              // la recupera después la landing del diagnóstico
  const CLAVE_COLA = "autoria_propuesta_cola";    // envíos pendientes
  const CLAVE_BORRADOR = "autoria_propuesta_borrador";
  const MAX = 90;

  const ls = {
    get(k) { try { return JSON.parse(localStorage.getItem(k) || "null"); } catch (_) { return null; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); return true; } catch (_) { return false; } },
    del(k) { try { localStorage.removeItem(k); } catch (_) {} }
  };

  // Código de la charla: ?c=C01 (solo letras, números y guiones).
  const charla = (new URLSearchParams(location.search).get("c") || "").trim().toUpperCase().replace(/[^A-Z0-9_-]/g, "").slice(0, 20);

  const form = $("[data-form]");
  const campos = { para: $("#ch-para"), aporto: $("#ch-aporto"), gracias_a: $("#ch-gracias") };
  const pasoEscribir = $('[data-paso="escribir"]');
  const pasoLeer = $('[data-paso="leer"]');
  const preview = $("[data-preview]");
  const error = $("[data-error]");
  const fraseEl = $("[data-frase]");
  const estado = $("[data-estado]");
  const copiar = $("[data-copiar]");

  /* ---------- la frase ---------- */
  // Si alguien escribe la palabra de la plantilla («para dueños…»), no se repite.
  const PREFIJOS = { para: /^para\s+/i, aporto: /^aporto\s+/i, gracias_a: /^gracias\s+a\s+/i };
  function limpio(nombre) {
    return campos[nombre].value.replace(/\s+/g, " ").trim().replace(PREFIJOS[nombre], "").replace(/[\s.,;:]+$/, "");
  }
  function partes() { return { para: limpio("para"), aporto: limpio("aporto"), gracias_a: limpio("gracias_a") }; }
  function frase(p) { return "Para " + p.para + ", aporto " + p.aporto + ", gracias a " + p.gracias_a + "."; }

  // Vista previa en vivo: los huecos vacíos se ven como una raya.
  function pintarPreview() {
    const p = partes();
    preview.textContent = "";
    [["Para ", p.para], [", aporto ", p.aporto], [", gracias a ", p.gracias_a]].forEach(([texto, valor]) => {
      preview.append(texto);
      const s = document.createElement("span");
      s.className = valor ? "ch-fill" : "ch-gap";
      s.textContent = valor || "   ";
      preview.append(s);
    });
    preview.append(".");
  }

  /* ---------- envío con reintentos ---------- */
  let reintento = null, espera = 4000, enviando = false;
  const cola = () => ls.get(CLAVE_COLA) || [];

  async function enviarCola() {
    if (enviando) return;
    let pendientes = cola();
    if (!pendientes.length) return;
    enviando = true;
    clearTimeout(reintento);
    let falta = false;
    for (const r of pendientes) {
      let ok = false, descartar = false;
      try {
        const ctrl = "AbortController" in window ? new AbortController() : null;
        const t = ctrl && setTimeout(() => ctrl.abort(), 12000);
        const res = await fetch("/api/propuesta", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(r),
          signal: ctrl ? ctrl.signal : undefined
        });
        if (t) clearTimeout(t);
        const j = await res.json().catch(() => ({}));
        ok = !!j.ok;
        descartar = j.reason === "datos";   // datos no válidos: reintentar no sirve
      } catch (_) { /* sin conexión: se reintenta */ }
      if (ok || descartar) {
        pendientes = cola().filter((x) => x.id !== r.id);
        ls.set(CLAVE_COLA, pendientes);
      } else falta = true;
    }
    enviando = false;
    if (falta) {
      mostrarEstado("pendiente");
      reintento = setTimeout(enviarCola, espera);
      espera = Math.min(espera * 2, 60000);
    } else {
      espera = 4000;
      mostrarEstado("enviada");
    }
  }

  function mostrarEstado(tipo) {
    if (pasoLeer.hidden) return;
    estado.textContent = tipo === "pendiente"
      ? "Sin conexión: su frase queda guardada en este móvil y se enviará sola en cuanto haya red."
      : "Guardada. Gracias.";
    estado.dataset.tipo = tipo;
  }

  // Último intento al cerrar o cambiar de app (si llega dos veces, el panel la cuenta una).
  function enviarAlSalir() {
    if (!navigator.sendBeacon) return;
    cola().forEach((r) => navigator.sendBeacon("/api/propuesta", new Blob([JSON.stringify(r)], { type: "application/json" })));
  }

  /* ---------- pasos ---------- */
  function irA(paso) {
    const leer = paso === "leer";
    pasoEscribir.hidden = leer;
    pasoLeer.hidden = !leer;
    window.scrollTo(0, 0);
    if (leer) fraseEl.focus({ preventScroll: true });
  }

  function nuevoId() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return Date.now().toString(36) + "-" + Math.random().toString(36).slice(2, 10);
  }

  form.addEventListener("submit", (e) => {
    e.preventDefault();
    const p = partes();
    const vacio = Object.keys(campos).find((k) => !p[k]);
    Object.keys(campos).forEach((k) => campos[k].closest(".field").classList.toggle("is-invalid", !p[k]));
    if (vacio) {
      error.textContent = "Complete las tres partes. Basta con unas pocas palabras.";
      error.hidden = false;
      campos[vacio].focus();
      return;
    }
    error.hidden = true;

    const texto = frase(p);
    const anterior = ls.get(CLAVE);
    // Si vuelve a pulsar «Listo» con la misma frase, no se envía otra vez.
    if (!anterior || anterior.frase !== texto || anterior.charla !== charla) {
      const r = { id: nuevoId(), charla, para: p.para, aporto: p.aporto, gracias_a: p.gracias_a, fecha: new Date().toISOString() };
      ls.set(CLAVE, { frase: texto, para: p.para, aporto: p.aporto, gracias_a: p.gracias_a, charla, fecha: r.fecha });
      ls.set(CLAVE_COLA, cola().concat(r));
    }
    ls.del(CLAVE_BORRADOR);

    fraseEl.textContent = texto;
    copiar.textContent = "Copiar";
    estado.textContent = "Guardando…";
    irA("leer");
    espera = 4000;
    if (cola().length) enviarCola(); else mostrarEstado("enviada");
  });

  copiar.addEventListener("click", async () => {
    const texto = fraseEl.textContent;
    try { await navigator.clipboard.writeText(texto); copiar.textContent = "Copiada"; }
    catch (_) {
      // Navegadores sin portapapeles: se selecciona la frase para copiarla a mano.
      const sel = window.getSelection(), rango = document.createRange();
      rango.selectNodeContents(fraseEl); sel.removeAllRanges(); sel.addRange(rango);
      copiar.textContent = "Mantenga pulsado para copiar";
    }
  });

  $("[data-editar]").addEventListener("click", () => { irA("escribir"); campos.para.focus(); });

  /* ---------- arranque ---------- */
  Object.values(campos).forEach((el) => {
    el.maxLength = MAX;
    el.addEventListener("input", () => {
      el.closest(".field").classList.remove("is-invalid");
      pintarPreview();
      ls.set(CLAVE_BORRADOR, { para: campos.para.value, aporto: campos.aporto.value, gracias_a: campos.gracias_a.value });
    });
  });
  // Enter en un campo pasa al siguiente; en el último, envía.
  const orden = Object.values(campos);
  orden.forEach((el, i) => el.addEventListener("keydown", (e) => {
    if (e.key === "Enter" && i < orden.length - 1) { e.preventDefault(); orden[i + 1].focus(); }
  }));

  // Si recarga la página, recupera lo que estaba escribiendo o su última frase.
  const previo = ls.get(CLAVE_BORRADOR) || ls.get(CLAVE);
  if (previo) Object.keys(campos).forEach((k) => { if (typeof previo[k] === "string") campos[k].value = previo[k].slice(0, MAX); });
  pintarPreview();

  window.addEventListener("online", () => { espera = 4000; enviarCola(); });
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "hidden") enviarAlSalir();
    else enviarCola();
  });
  enviarCola();   // envíos que quedaron pendientes de una visita anterior

  // Contador anónimo de visitas por charla (una por móvil), para comparar unas charlas con otras.
  try {
    const marca = "autoria_visita_propuesta_" + (charla || "-");
    if (!localStorage.getItem(marca)) {
      fetch("/api/visita", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ pagina: "propuesta", charla }), keepalive: true })
        .then((r) => r.json()).then((j) => { if (j.ok) localStorage.setItem(marca, "1"); }).catch(() => {});
    }
  } catch (_) {}
})();
