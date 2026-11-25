# Reservas

Reservas presenta los eventos de Nextcloud Calendar y permite reservar los
espacios configurados en **Calendar Resource Management**.

## Requisitos

- Nextcloud 34 y PHP 8.3–8.5.
- Las aplicaciones **Calendar** y **Calendar Resource Management** activadas.
- Salas creadas en Calendar Resource Management.
- La cuenta de cada persona debe tener un correo electrónico válido en su perfil
  para identificar a quien organiza la reserva.

## Reservas

Pulsa un día del calendario para iniciar una reserva con esa fecha seleccionada.
Elige la sala, la materia, la hora y una duración del selector:
30m, 1h, 1.5h, 2h, 2.5h, 3h, 3.5h, 4h, 4.5h, 5h, 6h, 7h u 8h.
Puedes elegir una reserva puntual o un período continuo que se repita semanalmente
el mismo día; en ese caso indica el último día de repetición. La última fecha se
recuerda en la sesión y se propone en la siguiente reserva del mismo día de semana.
Las reservas se guardan directamente en el calendario de reservas del room
(`principals/calendar-rooms/.../calendar`), con la persona que reservó y la
materia en la descripción. De este modo, ese calendario es la fuente de verdad
para verificar colisiones y no depende de la entrega de una invitación iTIP.
El detalle de una reserva semanal muestra hasta qué día se repite. Quien creó
una reserva puede eliminarla desde ese detalle; si es semanal, la eliminación
afecta a toda la serie.

Los eventos de cada espacio tienen un color propio, indicado en la leyenda debajo
de **Mis calendarios**. Pulsa los espacios de la leyenda para filtrar los eventos;
si solo queda uno seleccionado, se propone ese espacio al iniciar una reserva.

Antes de crearla, la app comprueba la agenda de la sala. Si el intervalo se
superpone con otro evento, rechaza la reserva y notifica a quienes organizaron
las reservas existentes. Los eventos de las salas y los eventos propios se
muestran en la misma vista; al abrir un evento se ven sus detalles.

## Prueba de integración desde CLI

Para crear reservas de prueba contra una instancia real, verificar que los
eventos se leen desde el principal de la sala, comprobar colisiones y limpiar
los eventos, sigue las
[instrucciones para ejecutar la prueba PHP desde CLI](./tests/manual/reservation-collision.md).
