/* =============================================================
   AUTORÍA — datos de marca y ajustes editables
   -------------------------------------------------------------
   Este es el ÚNICO archivo que hace falta tocar para rellenar los
   datos de contacto (email, LinkedIn, enlace de reservas) y para
   ajustar las suposiciones de la calculadora del diagnóstico.
   Si «reservaUrl» está vacío, «Reserva una llamada» lleva al formulario.
   ============================================================= */
(function () {
  "use strict";

  window.__BRAND__ = {
    name: "Autoría",

    /* ---------- Contacto ---------- */
    contacto: {
      email: "admin.autoria@gmail.com",
      linkedin: "https://www.linkedin.com/company/movixmaker/",
      reservaUrl: "https://calendar.app.google/NVB6W3cA4QMxrXoA6"   // agenda de Google Calendar. Vacío = lleva al formulario
    },

    /* ---------- Comunidad de WhatsApp ----------
       Solo aparece al final de /charla/diagnostico, después de enviar, con el botón verde
       «Unirme en WhatsApp». Es voluntaria: no hace falta para el diagnóstico (no hacerla
       obligatoria, por el RGPD). Para cambiar la invitación, basta con cambiar este enlace.
       Vacío = no se muestra el botón. */
    WHATSAPP_COMMUNITY_URL: "https://chat.whatsapp.com/JlPh7IRTGPeARNMwtpkHHl",

    /* ---------- Agenda de las charlas ----------
       Al final de /charla/diagnostico, después de la comunidad y destacada, para que
       reserven una llamada nada más pedir el diagnóstico. Es una agenda propia de las charlas,
       distinta de «reservaUrl». Vacío = no se muestra el botón. */
    CHARLA_AGENDA_URL: "https://calendar.app.google/ZmgpjEmU3PF2fDYz7",

    /* ---------- Ritmos de publicación que recomienda el diagnóstico ----------
       Según las respuestas, el diagnóstico propone uno de estos tres ritmos
       (sin precios: las condiciones se explican en la llamada). */
    ritmos: {
      esencial:    { videos: 4, posts: 2 },
      recomendado: { videos: 6, posts: 3 },
      completo:    { videos: 8, posts: 4 }
    },

    /* ---------- Suposiciones de la calculadora ----------
       Horas que le costaría a una persona hacerlo por su cuenta.
       Se muestran y se pueden editar en el diagnóstico; aquí se
       fijan los valores por defecto. */
    calculadora: {
      horasPorVideo: 4,      // idea y guion, grabación y repeticiones, edición, subtítulos, rótulos y publicación
      horasPorPost: 1.5      // redacción, revisión, imagen y publicación
    }
  };
})();
