/* ============================================================
   Banco de Preguntas · interacciones
   ============================================================ */
(function () {
  'use strict';

  var meta = document.querySelector('meta[name="csrf"]');
  var CSRF = meta ? meta.content : '';

  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

  function post(url, datos) {
    datos.csrf = CSRF;
    return fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'Accept': 'application/json'
      },
      body: new URLSearchParams(datos).toString(),
      credentials: 'same-origin'
    }).then(function (r) {
      return r.json().catch(function () { return null; }).then(function (data) {
        if (!r.ok || !data || data.ok !== true) {
          var err = new Error((data && data.error) ? data.error : ('Error HTTP ' + r.status));
          err.data = data || {};
          throw err;
        }
        return data;
      });
    });
  }

  /* ---------------- Índice: formulario de nueva ronda ---------------- */

  var form = $('#form-nueva');
  if (form) {
    var nota = $('#nota-errores');
    var sinFallos = form.getAttribute('data-fallos') === '0';
    var radios = $$('input[name="modo"]', form);

    function comprobarErrores() {
      var actual = radios.filter(function (r) { return r.checked; })[0];
      var bloquear = sinFallos && actual && actual.value === 'errores';
      if (nota) { nota.hidden = !bloquear; }
      return !!bloquear;
    }

    radios.forEach(function (r) { r.addEventListener('change', comprobarErrores); });
    comprobarErrores();

    form.addEventListener('submit', function (ev) {
      if (comprobarErrores()) { ev.preventDefault(); }
    });

    var params = new URLSearchParams(window.location.search);
    var aviso = params.get('aviso');
    if (aviso) {
      var textos = {
        'errores-vacios': 'Todavía no has fallado ninguna pregunta. Juega primero una ronda en modo Estudio o Examen.',
        'sin-preguntas': 'No hay preguntas disponibles con esos filtros.'
      };
      if (textos[aviso]) {
        var caja = document.createElement('div');
        caja.className = 'aviso aviso-rojo';
        caja.textContent = textos[aviso];
        var main = $('.contenedor');
        if (main) { main.insertBefore(caja, main.firstChild); }
      }
    }
  }

  /* ---------------- Detalle: filtro "solo mis fallos" ---------------- */

  var soloFallos = $('#solo-fallos');
  if (soloFallos) {
    soloFallos.addEventListener('change', function () {
      $$('.listado-preguntas .item').forEach(function (li) {
        var tipo = li.getAttribute('data-tipo');
        li.hidden = soloFallos.checked && tipo !== 'fallo';
      });
    });
  }

  /* ---------------- Ronda en curso ---------------- */

  var cfg = window.__JUGAR__;
  if (!cfg) { return; }

  var cajaOpciones = $('#opciones');
  var cajaFeedback = $('#feedback');
  var tituloFeedback = $('#feedback-titulo');
  var textoFeedback = $('#feedback-texto');
  var btnSiguiente = $('#btn-siguiente');
  var btnTerminar = $('#btn-terminar');
  var cajaError = $('#error-caja');
  var ocupado = false;
  var ultimoResultado = null;

  function mostrarError(msg) {
    if (!cajaError) { return; }
    cajaError.hidden = false;
    cajaError.textContent = msg;
  }

  function finalizar() {
    return post('api/finalizar.php', { sesion_id: cfg.sesionId })
      .then(function (res) { window.location.href = res.redirect; })
      .catch(function (e) { mostrarError(e.message); });
  }

  function avanzar() {
    if (ultimoResultado && ultimoResultado.fin) {
      finalizar();
    } else {
      window.location.reload();
    }
  }

  function avisarGuardado() {
    var chip = document.createElement('div');
    chip.className = 'feedback';
    chip.style.cssText = 'margin-top:1rem;text-align:center;font-weight:600;';
    chip.textContent = 'Respuesta guardada…';
    if (cajaOpciones && cajaOpciones.parentNode) {
      cajaOpciones.parentNode.insertBefore(chip, cajaOpciones.nextSibling);
    }
    return chip;
  }

  function pintarRespuesta(res, elegida) {
    var botones = $$('.opcion', cajaOpciones);
    botones.forEach(function (b) {
      b.disabled = true;
      // Se compara por la letra original de la BD, no por la visible.
      var original = b.getAttribute('data-original');
      if (original === res.letra_correcta) {
        b.classList.add('es-correcta');
      } else if (original === elegida) {
        b.classList.add('es-mala');
      }
      b.classList.remove('esperando');
    });

    cajaFeedback.hidden = false;
    cajaFeedback.classList.add(res.correcta ? 'bien' : 'mal');
    tituloFeedback.textContent = res.correcta
      ? '¡Correcto!'
      : 'Incorrecto. La respuesta correcta era la ' + res.letra_correcta.toUpperCase() + '.';
    textoFeedback.textContent = res.explicacion || '';
    btnSiguiente.textContent = res.fin ? 'Ver resultado' : 'Siguiente pregunta';

    var contador = $('.contador-aciertos');
    if (contador && typeof res.aciertos === 'number') {
      contador.textContent = res.aciertos + ' correctas';
    }
  }

  cajaOpciones.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.opcion');
    if (!btn || ocupado || btn.disabled) { return; }
    ocupado = true;
    if (cajaError) { cajaError.hidden = true; }

    btn.classList.add('esperando');
    $$('.opcion', cajaOpciones).forEach(function (b) {
      if (b !== btn) { b.disabled = true; b.classList.add('esperando'); }
    });

    post('api/responder.php', {
      sesion_id: cfg.sesionId,
      pregunta_id: cfg.preguntaId,
      letra: btn.getAttribute('data-original')
    }).then(function (res) {
      ultimoResultado = res;
      if (cfg.mostrarFeedback) {
        pintarRespuesta(res, btn.getAttribute('data-original'));
        ocupado = false;
        if (btnSiguiente) { btnSiguiente.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
      } else {
        avisarGuardado();
        setTimeout(function () {
          if (res.fin) { finalizar(); } else { window.location.reload(); }
        }, 420);
      }
    }).catch(function (e) {
      ocupado = false;
      $$('.opcion', cajaOpciones).forEach(function (b) { b.disabled = false; b.classList.remove('esperando'); });
      if (e.data && e.data.recargar) {
        window.location.reload();
        return;
      }
      mostrarError(e.message);
    });
  });

  if (btnSiguiente) {
    btnSiguiente.addEventListener('click', function () {
      btnSiguiente.disabled = true;
      avanzar();
    });
  }

  if (btnTerminar) {
    btnTerminar.addEventListener('click', function () {
      if (window.confirm('¿Terminar la ronda ahora y ver la nota?')) {
        btnTerminar.disabled = true;
        finalizar();
      }
    });
  }
})();
