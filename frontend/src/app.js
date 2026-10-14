const API = '/api/reservaciones';
const CAMPOS = ['espacio', 'solicitante', 'correo', 'fecha', 'hora_inicio', 'hora_fin', 'estado'];
const $ = (id) => document.getElementById(id);
let reservaciones = [];

function mostrarMensaje(texto, tipo) {
  const caja = $('mensaje');
  caja.textContent = texto;
  caja.className = 'mensaje ' + tipo;
  caja.hidden = false;
  clearTimeout(mostrarMensaje.temporizador);
  mostrarMensaje.temporizador = setTimeout(() => { caja.hidden = true; }, 6000);
}

function limpiarErrores() {
  document.querySelectorAll('.error-campo').forEach((e) => e.remove());
  document.querySelectorAll('.invalido').forEach((e) => e.classList.remove('invalido'));
}

function mostrarErrores(campos) {
  if (!campos) return;
  for (const [campo, texto] of Object.entries(campos)) {
    const aviso = document.createElement('span');
    aviso.className = 'error-campo';
    aviso.textContent = texto;
    $(campo).insertAdjacentElement('afterend', aviso);
    $(campo).classList.add('invalido');
  }
}

async function pedir(url, opciones) {
  const respuesta = await fetch(url, opciones);
  const datos = await respuesta.json().catch(() => ({}));
  if (!respuesta.ok) {
    const error = new Error(datos.error || 'Error ' + respuesta.status);
    error.campos = datos.campos;
    throw error;
  }
  return datos;
}

async function cargar() {
  try {
    reservaciones = await pedir(API);
    dibujar();
  } catch (e) {
    mostrarMensaje('No se pudo cargar la lista: ' + e.message, 'error');
  }
}

function dibujar() {
  const cuerpo = $('lista');
  cuerpo.replaceChildren();
  if (reservaciones.length === 0) {
    const fila = document.createElement('tr');
    const celda = document.createElement('td');
    celda.colSpan = 8;
    celda.textContent = 'No hay reservaciones registradas.';
    fila.appendChild(celda);
    cuerpo.appendChild(fila);
    return;
  }
  for (const r of reservaciones) {
    const fila = document.createElement('tr');
    const valores = [r.id, r.espacio, r.solicitante, r.correo, r.fecha,
      r.hora_inicio.slice(0, 5) + ' - ' + r.hora_fin.slice(0, 5)];
    for (const v of valores) {
      const celda = document.createElement('td');
      celda.textContent = v;
      fila.appendChild(celda);
    }

    const celdaEstado = document.createElement('td');
    const etiqueta = document.createElement('span');
    etiqueta.className = 'estado ' + r.estado;
    etiqueta.textContent = r.estado;
    celdaEstado.appendChild(etiqueta);
    fila.appendChild(celdaEstado);

    const celdaAcciones = document.createElement('td');
    const botonEditar = document.createElement('button');
    botonEditar.textContent = 'Editar';
    botonEditar.addEventListener('click', () => editar(r));
    const botonEliminar = document.createElement('button');
    botonEliminar.textContent = 'Eliminar';
    botonEliminar.className = 'peligro';
    botonEliminar.addEventListener('click', () => eliminar(r));
    celdaAcciones.append(botonEditar, botonEliminar);
    fila.appendChild(celdaAcciones);

    cuerpo.appendChild(fila);
  }
}

function reiniciarFormulario() {
  $('formulario').reset();
  $('id').value = '';
  $('titulo-form').textContent = 'Nueva reservación';
  $('cancelar').hidden = true;
  limpiarErrores();
}

function editar(r) {
  limpiarErrores();
  $('id').value = r.id;
  for (const c of CAMPOS) {
    $(c).value = (c === 'hora_inicio' || c === 'hora_fin') ? r[c].slice(0, 5) : r[c];
  }
  $('titulo-form').textContent = 'Editar reservación #' + r.id;
  $('cancelar').hidden = false;
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function eliminar(r) {
  if (!confirm('¿Eliminar la reservación #' + r.id + ' de "' + r.espacio + '"?')) return;
  try {
    await pedir(API + '/' + r.id, { method: 'DELETE' });
    mostrarMensaje('Reservación eliminada correctamente', 'exito');
    await cargar();
  } catch (e) {
    mostrarMensaje('No se pudo eliminar: ' + e.message, 'error');
  }
}

$('formulario').addEventListener('submit', async (evento) => {
  evento.preventDefault();
  limpiarErrores();
  const datos = {};
  for (const c of CAMPOS) datos[c] = $(c).value;
  const id = $('id').value;
  try {
    await pedir(id ? API + '/' + id : API, {
      method: id ? 'PUT' : 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(datos),
    });
    mostrarMensaje(id ? 'Reservación actualizada correctamente'
      : 'Reservación creada correctamente. Se envió un correo de prueba a Mailpit.', 'exito');
    reiniciarFormulario();
    await cargar();
  } catch (e) {
    mostrarErrores(e.campos);
    mostrarMensaje(e.message, 'error');
  }
});

$('cancelar').addEventListener('click', reiniciarFormulario);
cargar();