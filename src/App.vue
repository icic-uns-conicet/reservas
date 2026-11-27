<template>
    <div class="icic-calendar">
        <header class="calendar-topbar">
            <div class="brand">
                <img
                    class="brand-icon"
                    :src="appIconUrl"
                    alt=""
                >
                <span class="brand-name">Reservas</span>
            </div>

            <div class="calendar-toolbar">
                <button class="today-button" type="button" @click="calendar?.today()">
                    Hoy
                </button>
                <div class="navigation-buttons">
                    <button
                        type="button"
                        aria-label="Periodo anterior"
                        title="Periodo anterior"
                        @click="calendar?.prev()"
                    >‹</button>
                    <button
                        type="button"
                        aria-label="Periodo siguiente"
                        title="Periodo siguiente"
                        @click="calendar?.next()"
                    >›</button>
                </div>
                <h1>{{ calendarTitle }}</h1>
            </div>

            <div class="toolbar-actions">
                <div class="view-switcher" aria-label="Vista del calendario">
                    <button
                        v-for="view in views"
                        :key="view.id"
                        type="button"
                        :class="{ active: currentView === view.id }"
                        :aria-pressed="currentView === view.id"
                        @click="calendar?.changeView(view.id)"
                    >
                        {{ view.label }}
                    </button>
                </div>
                <button
                    class="refresh-button"
                    type="button"
                    aria-label="Actualizar calendarios"
                    title="Actualizar calendarios"
                    @click="loadCalendars"
                >
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M20 7v5h-5M4 17v-5h5" />
                        <path d="M5.6 9a7 7 0 0 1 11.6-2L20 12M4 12l2.8 5a7 7 0 0 0 11.6-2" />
                    </svg>
                </button>
                <button
                    v-if="accessLoaded"
                    class="secondary-button availability-button"
                    type="button"
                    :disabled="selectedRoomKeys.length === 0"
                    @click="openAvailabilitySearch"
                >
                    Buscar espacio libre
                </button>
                <button v-if="accessLoaded" class="reserve-button" type="button" @click="openReservation">
                    Nueva reserva
                </button>
            </div>
        </header>

        <div v-if="availabilityOpen" class="modal-backdrop" @click.self="closeAvailabilitySearch">
            <section
                class="reservation-modal availability-modal"
                role="dialog"
                aria-modal="true"
                aria-labelledby="availability-title"
            >
                <button
                    class="modal-close"
                    type="button"
                    aria-label="Cerrar"
                    @click="closeAvailabilitySearch"
                >×</button>
                <h2 id="availability-title">{{ availabilityTitle }}</h2>
                <p class="availability-context">
                    {{ availabilityContext }}
                </p>
                <label class="availability-duration duration-control">
                    <span class="duration-heading">
                        Duración
                        <output>{{ formatDuration(availabilityDuration) }}</output>
                    </span>
                    <input
                        v-model.number="availabilityDuration"
                        type="range"
                        min="30"
                        max="480"
                        step="30"
                        :disabled="availabilityLoading"
                        aria-label="Duración de la reserva"
                        @change="updateAvailabilityDuration"
                    >
                    <span class="duration-limits" aria-hidden="true">
                        <span>30 min</span>
                        <span>8 h</span>
                    </span>
                </label>
                <p v-if="availabilityLoading" class="availability-message" role="status">
                    Buscando horarios libres…
                </p>
                <p v-else-if="availabilityError" class="form-error" role="alert">
                    {{ availabilityError }}
                </p>
                <p
                    v-else-if="availabilityResults.length === 0"
                    class="availability-message"
                    role="status"
                >
                    No se encontraron horarios libres con esa duración en el período visible.
                </p>
                <div v-else class="availability-results">
                    <div class="availability-room-grid">
                        <article
                            v-for="room in availabilityRoomGroups"
                            :key="room.key"
                            class="availability-room-card"
                            :style="{ '--availability-room-color': room.color }"
                        >
                            <h3 class="availability-room-title">
                                <span aria-hidden="true"></span>
                                {{ room.name }}
                            </h3>
                            <p class="availability-room-label">Horarios disponibles:</p>
                            <div
                                v-for="day in room.days"
                                :key="day.key"
                                class="availability-day-group"
                            >
                                <h4 v-if="availabilityHasMultipleDates">
                                    {{ formatAvailabilityDate(day.date) }}
                                </h4>
                                <div class="availability-time-slots">
                                    <button
                                        v-for="slot in day.slots"
                                        :key="slot.key"
                                        class="availability-time-button"
                                        :class="{ selected: selectedAvailabilitySlotKey === slot.key }"
                                        type="button"
                                        :aria-pressed="selectedAvailabilitySlotKey === slot.key"
                                        @click="selectAvailabilitySlot(slot)"
                                    >
                                        {{ formatAvailabilityTime(slot.start) }}
                                    </button>
                                </div>
                            </div>
                            <button
                                class="reserve-button availability-room-book-button"
                                type="button"
                                :disabled="!getSelectedAvailabilitySlot(room.key)"
                                @click="reserveAvailableSlot(getSelectedAvailabilitySlot(room.key))"
                            >
                                Reservar en {{ room.name }}
                            </button>
                        </article>
                    </div>
                    <button
                        v-if="availabilityVisibleCount < availabilityResults.length"
                        class="secondary-button availability-more-button"
                        type="button"
                        @click="availabilityVisibleCount += 50"
                    >
                        Mostrar más horarios
                    </button>
                </div>
                <div class="modal-actions">
                    <button class="secondary-button" type="button" @click="closeAvailabilitySearch">
                        Cerrar
                    </button>
                </div>
            </section>
        </div>

        <main class="calendar-layout">
            <aside class="calendar-sidebar">
                <section class="mini-calendar" aria-label="Calendario mensual">
                    <div class="mini-calendar-heading">
                        <h2>{{ miniMonthTitle }}</h2>
                        <div class="mini-navigation">
                            <button type="button" aria-label="Mes anterior" @click="changeMiniMonth(-1)">‹</button>
                            <button type="button" aria-label="Mes siguiente" @click="changeMiniMonth(1)">›</button>
                        </div>
                    </div>
                    <div class="mini-grid mini-weekdays" aria-hidden="true">
                        <span v-for="day in weekdays" :key="day">{{ day }}</span>
                    </div>
                    <div class="mini-grid">
                        <button
                            v-for="day in miniMonthDays"
                            :key="day.key"
                            type="button"
                            :class="{
                                'outside-month': !day.currentMonth,
                                today: day.today,
                                selected: day.selected,
                            }"
                            :aria-label="day.label"
                            :aria-pressed="day.selected"
                            @click="selectDate(day.date)"
                        >
                            {{ day.date.getDate() }}
                        </button>
                    </div>
                </section>

                <section class="calendar-list">
                    <h2>Mis calendarios</h2>
                    <div
                        v-for="calendarItem in calendars"
                        :key="calendarItem.uri"
                        class="calendar-item"
                    >
                        <span
                            class="calendar-color"
                            :style="{ backgroundColor: calendarItem.color || '#4285f4' }"
                            aria-hidden="true"
                        ></span>
                        <span>{{ calendarItem.displayName }}</span>
                    </div>
                </section>

                <section class="calendar-list room-list">
                    <h2>Espacios reservables</h2>
                    <p v-if="rooms.length === 0" class="empty-room-list">
                        No hay salas configuradas en Calendar Resource Management.
                    </p>
                    <button
                        v-for="room in rooms"
                        :key="room.key"
                        class="calendar-item room-filter"
                        type="button"
                        :aria-pressed="selectedRoomKeys.includes(room.key)"
                        @click="toggleRoomFilter(room.key)"
                    >
                        <span
                            class="calendar-color"
                            :style="{ backgroundColor: room.color }"
                            aria-hidden="true"
                        ></span>
                        <span>{{ room.displayName }}</span>
                    </button>
                </section>
            </aside>

            <section class="calendar-content" aria-label="Eventos">
                <Calendar
                    ref="calendar"
                    :selected-room-keys="selectedRoomKeys"
                    @date-change="updateCalendarDate"
                    @view-change="currentView = $event"
                    @event-select="selectEvent"
                    @reservation-start="openReservationForDate"
                    @reservation-range-start="openReservationForRange"
                />
            </section>
        </main>

        <div v-if="reservationOpen" class="modal-backdrop" @click.self="closeReservation">
            <section class="reservation-modal" role="dialog" aria-modal="true" aria-labelledby="reservation-title">
                <button class="modal-close" type="button" aria-label="Cerrar" @click="closeReservation">×</button>
                <h2 id="reservation-title">
                    {{ editingReservation
                        ? (editingReservation.isRoomReservation ? 'Editar reserva' : 'Editar evento')
                        : 'Reservar un espacio' }}
                </h2>
                <form @submit.prevent="submitReservation">
                    <p v-if="rooms.length === 0 && !editingReservation" class="form-error" role="alert">
                        No hay espacios configurados. Pide a un administrador que configure salas en Calendar Resource Management.
                    </p>
                    <label v-if="!editingReservation || editingReservation.isRoomReservation">
                        Espacio
                        <select v-model="reservation.roomKey" required :disabled="Boolean(editingReservation)">
                            <option disabled value="">Selecciona un espacio</option>
                            <option v-for="room in rooms" :key="room.key" :value="room.key">
                                {{ room.displayName }}
                            </option>
                        </select>
                    </label>
                    <label>
                        {{ editingReservation && !editingReservation.isRoomReservation ? 'Título' : 'Materia' }}
                        <input v-model.trim="reservation.subject" maxlength="255" required>
                    </label>
                    <label v-if="canCreateRecurring && !editingReservation">
                        Reservar para
                        <select v-model="reservation.forUser">
                            <option value="">Yo mismo/a</option>
                            <option
                                v-for="user in eligibleUsers"
                                :key="user.uid"
                                :value="user.uid"
                            >
                                {{ user.displayName }} ({{ user.uid }})
                            </option>
                        </select>
                    </label>
                    <p v-if="eligibleUsersError && canCreateRecurring && !editingReservation" class="form-error" role="alert">
                        {{ eligibleUsersError }}
                    </p>
                    <label v-if="editingReservation">
                        Fecha
                        <input v-model="reservation.date" type="date" required>
                    </label>
                    <p v-else class="reservation-date">
                        Fecha: <strong>{{ formatReservationDate(reservation.date) }}</strong>
                    </p>
                    <label
                        v-if="(!editingReservation || editingReservation.isRoomReservation)
                            && canCreateRecurring"
                    >
                        Tipo de reserva
                        <select v-model="reservation.reservationType" required @change="updateReservationType">
                            <option value="punctual">Puntual</option>
                            <option value="continuous">Semanal</option>
                        </select>
                    </label>
                    <p
                        v-else-if="(!editingReservation || editingReservation.isRoomReservation)
                            && !canCreateRecurring"
                        class="reservation-type"
                    >
                        Tipo de reserva: Puntual
                    </p>
                    <div class="date-fields">
                        <label
                            v-if="editingReservation && !editingReservation.isRoomReservation"
                            class="all-day-toggle"
                        >
                            <input v-model="reservation.allDay" type="checkbox">
                            Todo el día
                        </label>
                        <label v-if="!reservation.allDay">
                            Hora
                            <input
                                v-model="reservation.time"
                                type="time"
                                :step="editingReservation && !editingReservation.isRoomReservation ? 60 : 1800"
                                required
                            >
                        </label>
                        <label v-if="!reservation.allDay && !editingReservation" class="duration-control">
                            <span class="duration-heading">
                                Duración
                                <output>{{ formatDuration(reservation.durationMinutes) }}</output>
                            </span>
                            <input
                                v-model.number="reservation.durationMinutes"
                                type="range"
                                min="30"
                                max="480"
                                step="30"
                                required
                                aria-label="Duración de la reserva"
                            >
                            <span class="duration-limits" aria-hidden="true">
                                <span>30 min</span>
                                <span>8 h</span>
                            </span>
                        </label>
                        <label v-else-if="!reservation.allDay">
                            Duración
                            <select v-model.number="reservation.durationMinutes" required>
                                <option
                                    v-if="!durationOptions.some((duration) => duration.minutes === reservation.durationMinutes)"
                                    :value="reservation.durationMinutes"
                                >
                                    {{ reservation.durationMinutes }} min
                                </option>
                                <option
                                    v-for="duration in durationOptions"
                                    :key="duration.minutes"
                                    :value="duration.minutes"
                                >
                                    {{ duration.label }}
                                </option>
                            </select>
                        </label>
                    </div>
                    <label v-if="(!editingReservation || editingReservation.isRoomReservation)
                        && canCreateRecurring
                        && reservation.reservationType === 'continuous'">
                        Último día de repetición
                        <input
                            v-model="reservation.lastOccurrenceDate"
                            type="date"
                            :min="reservation.date"
                            required
                            @change="adjustAndRememberContinuousEndDate"
                        >
                    </label>
                    <p
                        v-if="(!editingReservation || editingReservation.isRoomReservation)
                            && canCreateRecurring
                            && reservation.reservationType === 'continuous'
                            && reservation.lastOccurrenceDate
                            && !isValidContinuousEndDate(reservation.lastOccurrenceDate)"
                        class="form-error"
                        role="alert"
                    >
                        El último día debe coincidir con el mismo día de la semana de la fecha inicial.
                    </p>
                    <p
                        v-if="(!editingReservation || editingReservation.isRoomReservation)
                            && canCreateRecurring"
                        class="reservation-type"
                    >
                        {{ reservation.reservationType === 'continuous'
                            ? 'Se repetirá cada semana el mismo día hasta la fecha elegida.'
                            : 'Reserva puntual, sin repetición.' }}
                    </p>
                    <p v-if="reservationError" class="form-error" role="alert">
                        {{ reservationError }}
                        <template v-for="(conflict, index) in reservationConflicts" :key="`${conflict.subject}-${index}`">
                            <template v-if="index === 0"> </template>
                            <template v-else>, </template>
                            {{ conflict.subject || 'Reserva existente' }}
                            <template v-if="conflict.owner">
                                (
                                <a v-if="conflict.ownerEmail" :href="`mailto:${conflict.ownerEmail}`">{{ conflict.owner }}</a>
                                <template v-else>{{ conflict.owner }}</template>
                                )
                            </template>
                        </template>
                    </p>
                    <p
                        v-if="editingReservation && !editingReservation.isRoomReservation && editingReservation.isRecurring"
                        class="reservation-type"
                    >
                        Los cambios se aplicarán a toda la serie del evento.
                    </p>
                    <div class="modal-actions">
                        <button type="button" class="secondary-button" @click="closeReservation">Cancelar</button>
                        <button
                            type="submit"
                            class="reserve-button"
                            :disabled="savingReservation || (!editingReservation && rooms.length === 0)"
                        >
                            {{ savingReservation ? (editingReservation ? 'Guardando…' : 'Reservando…')
                                : (editingReservation ? 'Guardar cambios' : 'Confirmar reserva') }}
                        </button>
                    </div>
                </form>
            </section>
        </div>

        <div v-if="selectedEvent" class="modal-backdrop" @click.self="selectedEvent = null">
            <section class="reservation-modal event-details" role="dialog" aria-modal="true" aria-labelledby="event-title">
                <button class="modal-close" type="button" aria-label="Cerrar" @click="selectedEvent = null">×</button>
                <h2 id="event-title">{{ selectedEvent.title }}</h2>
                <dl>
                    <template v-if="selectedEvent.recipient">
                        <dt>Para:</dt>
                        <dd>
                            {{ selectedEvent.recipient }}
                            <template v-if="selectedEvent.recipientEmail">
                                (<a :href="`mailto:${selectedEvent.recipientEmail}`">{{ selectedEvent.recipientEmail }}</a>)
                            </template>
                        </dd>
                    </template>
                    <template v-if="selectedEvent.owner">
                        <dt>Reservado por</dt>
                        <dd>
                            {{ selectedEvent.owner }}
                            <template v-if="selectedEvent.ownerEmail">
                                (<a :href="`mailto:${selectedEvent.ownerEmail}`">{{ selectedEvent.ownerEmail }}</a>)
                            </template>
                        </dd>
                    </template>
                    <template v-if="selectedEvent.location">
                        <dt>Espacio</dt>
                        <dd>{{ selectedEvent.location }}</dd>
                    </template>
                    <template v-if="selectedEvent.reservationType">
                        <dt>Tipo de reserva</dt>
                        <dd>{{ selectedEvent.reservationType }}</dd>
                    </template>
                    <template v-if="selectedEvent.reservationType === 'Período continuo' && selectedEvent.seriesEndDate">
                        <dt>Se repite hasta</dt>
                        <dd>{{ formatReservationDate(selectedEvent.seriesEndDate) }}</dd>
                    </template>
                    <dt>Horario</dt>
                    <dd>{{ formatEventRange(selectedEvent.start, selectedEvent.end) }}</dd>
                </dl>
                <p v-if="deleteReservationError" class="form-error" role="alert">
                    {{ deleteReservationError }}
                </p>
                <div class="modal-actions">
                    <button
                        v-if="selectedEvent.canEdit
                            && (selectedEvent.reservationType !== 'Período continuo' || canCreateRecurring)"
                        type="button"
                        class="secondary-button"
                        @click="openEditReservation"
                    >
                        {{ isRoomCalendarEvent(selectedEvent) ? 'Editar reserva' : 'Editar evento' }}
                    </button>
                    <button
                        v-if="selectedEvent.canDelete
                            && (selectedEvent.reservationType !== 'Período continuo' || canCreateRecurring)"
                        class="delete-button"
                        type="button"
                        :disabled="deletingReservation"
                        :aria-label="deletingReservation
                            ? 'Eliminando evento'
                            : (isRoomCalendarEvent(selectedEvent) ? 'Eliminar reserva' : 'Eliminar evento')"
                        :title="deletingReservation
                            ? 'Eliminando evento'
                            : (isRoomCalendarEvent(selectedEvent) ? 'Eliminar reserva' : 'Eliminar evento')"
                        @click="removeSelectedReservation"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M4 7h16M9 7V4h6v3m3 0-.8 13H6.8L6 7m4 4v5m4-5v5" />
                        </svg>
                        {{ deletingReservation ? 'Eliminando…' : (isRoomCalendarEvent(selectedEvent) ? 'Eliminar reserva' : 'Eliminar evento') }}
                    </button>
                    <button class="secondary-button" type="button" @click="selectedEvent = null">Cerrar</button>
                </div>
            </section>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import Calendar from './components/Calendar.vue'
import {
    createReservation,
    deleteReservation,
    getAccess,
    getCalendars,
    getEvents,
    getRooms,
    updateReservation,
} from './services/calendar.js'

const calendar = ref(null)
const appIconUrl = OC.imagePath('reservas', 'reservas.svg')
const accessLoaded = ref(false)
const canCreateRecurring = ref(false)
const eligibleUsers = ref([])
const eligibleUsersError = ref('')
const calendars = ref([])
const rooms = ref([])
const selectedRoomKeys = ref([])
const roomFiltersInitialized = ref(false)
const selectedDate = ref(new Date())
const calendarTitle = ref('')
const visibleCalendarRange = ref({ start: null, end: null })
const currentView = ref('dayGridMonth')
const availabilityOpen = ref(false)
const availabilityDuration = ref(60)
const availabilityLoading = ref(false)
const availabilityError = ref('')
const availabilityEvents = ref([])
const availabilityResults = ref([])
const availabilityVisibleCount = ref(50)
const selectedAvailabilitySlotKey = ref('')
const reservationOpen = ref(false)
const savingReservation = ref(false)
const reservationError = ref('')
const reservationConflicts = ref([])
const editingReservation = ref(null)
const selectedEvent = ref(null)
const deletingReservation = ref(false)
const deleteReservationError = ref('')
const reservation = ref({
    roomKey: '',
    subject: '',
    date: '',
    time: '',
    durationMinutes: 60,
    reservationType: 'punctual',
    forUser: '',
    lastOccurrenceDate: '',
    allDay: false,
})
const lastContinuousEndDate = ref(sessionStorage.getItem('reservas-last-continuous-end-date') || '')
const durationOptions = [
    { minutes: 30, label: '30m' },
    { minutes: 60, label: '1h' },
    { minutes: 90, label: '1.5h' },
    { minutes: 120, label: '2h' },
    { minutes: 150, label: '2.5h' },
    { minutes: 180, label: '3h' },
    { minutes: 210, label: '3.5h' },
    { minutes: 240, label: '4h' },
    { minutes: 270, label: '4.5h' },
    { minutes: 300, label: '5h' },
    { minutes: 330, label: '5.5h' },
    { minutes: 360, label: '6h' },
    { minutes: 390, label: '6.5h' },
    { minutes: 420, label: '7h' },
    { minutes: 450, label: '7.5h' },
    { minutes: 480, label: '8h' },
]
const savedDuration = Number(localStorage.getItem('reservas-last-duration-minutes'))
const lastReservationDuration = ref(
    isValidDuration(savedDuration) ? savedDuration : 60,
)

function isValidDuration(minutes) {
    return durationOptions.some((duration) => duration.minutes === minutes)
}

function normalizeDuration(minutes) {
    return durationOptions.reduce((closest, duration) => (
        Math.abs(duration.minutes - minutes) < Math.abs(closest - minutes)
            ? duration.minutes
            : closest
    ), durationOptions[0].minutes)
}

function formatDuration(minutes) {
    return durationOptions.find((duration) => duration.minutes === minutes)?.label
        ?? `${minutes} min`
}

const weekdays = ['L', 'M', 'X', 'J', 'V', 'S', 'D']
const views = [
    { id: 'dayGridMonth', label: 'Mes' },
    { id: 'timeGridWeek', label: 'Semana' },
    { id: 'timeGridDay', label: 'Día' },
]

const miniMonthTitle = computed(() => new Intl.DateTimeFormat('es', {
    month: 'long',
    year: 'numeric',
}).format(selectedDate.value))

const availabilityTitle = computed(() => 'Buscar espacio libre')

const availabilityContext = computed(() => {
    const { start, end } = visibleCalendarRange.value
    if (!start || !end) {
        return 'Espacios seleccionados · 08:00–20:00'
    }

    const lastDate = new Date(end.getTime() - 1)
    const startLabel = formatAvailabilityDate(start)
    const dateRange = toLocalDateValue(start) === toLocalDateValue(lastDate)
        ? startLabel
        : `${startLabel}–${formatAvailabilityDate(lastDate)}`

    return `Espacios seleccionados · ${dateRange} · 08:00–20:00`
})

const availabilityHasMultipleDates = computed(() => {
    const { start, end } = visibleCalendarRange.value
    return Boolean(start && end
        && toLocalDateValue(start) !== toLocalDateValue(new Date(end.getTime() - 1)))
})

const availabilityRoomGroups = computed(() => {
    const groups = new Map()
    const visibleSlots = availabilityResults.value.slice(0, availabilityVisibleCount.value)

    for (const slot of visibleSlots) {
        let room = groups.get(slot.roomKey)
        if (!room) {
            room = {
                key: slot.roomKey,
                name: slot.roomName,
                color: slot.roomColor,
                days: [],
            }
            groups.set(slot.roomKey, room)
        }

        const dayKey = toLocalDateValue(slot.start)
        let day = room.days.find((item) => item.key === dayKey)
        if (!day) {
            day = { key: dayKey, date: slot.start, slots: [] }
            room.days.push(day)
        }
        day.slots.push(slot)
    }

    return [...groups.values()].sort((left, right) => left.name.localeCompare(right.name, 'es'))
})

const miniMonthDays = computed(() => {
    const monthStart = new Date(
        selectedDate.value.getFullYear(),
        selectedDate.value.getMonth(),
        1,
    )
    const firstVisibleDay = new Date(
        monthStart.getFullYear(),
        monthStart.getMonth(),
        1 - ((monthStart.getDay() + 6) % 7),
    )
    const today = new Date()

    return Array.from({ length: 42 }, (_, index) => {
        const date = new Date(firstVisibleDay)
        date.setDate(firstVisibleDay.getDate() + index)

        return {
            date,
            key: `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}`,
            currentMonth: date.getMonth() === selectedDate.value.getMonth(),
            today: isSameDate(date, today),
            selected: isSameDate(date, selectedDate.value),
            label: new Intl.DateTimeFormat('es', {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                year: 'numeric',
            }).format(date),
        }
    })
})

function isSameDate(first, second) {
    return first.getFullYear() === second.getFullYear()
        && first.getMonth() === second.getMonth()
        && first.getDate() === second.getDate()
}

function updateCalendarDate({ title, date, start, end }) {
    calendarTitle.value = title
    selectedDate.value = new Date(date)
    visibleCalendarRange.value = {
        start: new Date(start),
        end: new Date(end),
    }
}

function selectEvent(event) {
    selectedEvent.value = event
    deleteReservationError.value = ''
}

function isRoomCalendarEvent(event) {
    return Boolean(event?.roomKey)
        && event.sourcePrincipal === `principals/calendar-rooms/${event.roomKey}`
}

function handleDialogKeydown(event) {
    if (event.key !== 'Escape' || savingReservation.value || deletingReservation.value) {
        return
    }

    if (availabilityOpen.value) {
        availabilityOpen.value = false
    } else if (selectedEvent.value) {
        selectedEvent.value = null
    } else if (reservationOpen.value) {
        reservationOpen.value = false
    } else {
        return
    }

    event.preventDefault()
}

function changeMiniMonth(offset) {
    const date = new Date(selectedDate.value)
    date.setMonth(date.getMonth() + offset, 1)
    calendar.value?.gotoDate(date)
}

function selectDate(date) {
    calendar.value?.gotoDate(date)
    calendar.value?.changeView('timeGridDay')
}

async function loadCalendars() {
    const [availableCalendars, availableRooms] = await Promise.all([
        getCalendars(),
        getRooms(),
    ])
    calendars.value = availableCalendars
    rooms.value = availableRooms
    const availableRoomKeys = new Set(availableRooms.map((room) => room.key))
    if (!roomFiltersInitialized.value && availableRooms.length > 0) {
        selectedRoomKeys.value = availableRooms.map((room) => room.key)
        roomFiltersInitialized.value = true
    } else {
        selectedRoomKeys.value = selectedRoomKeys.value.filter((key) => availableRoomKeys.has(key))
    }
}

function toggleRoomFilter(roomKey) {
    selectedRoomKeys.value = selectedRoomKeys.value.includes(roomKey)
        ? selectedRoomKeys.value.filter((key) => key !== roomKey)
        : [...selectedRoomKeys.value, roomKey]
}

async function openAvailabilitySearch() {
    availabilityOpen.value = true
    availabilityDuration.value = lastReservationDuration.value
    availabilityError.value = ''
    availabilityEvents.value = []
    availabilityResults.value = []
    availabilityVisibleCount.value = 50
    selectedAvailabilitySlotKey.value = ''

    if (selectedRoomKeys.value.length === 0) {
        availabilityError.value = 'Selecciona al menos un espacio reservable.'
        return
    }

    const { start, end } = visibleCalendarRange.value
    if (!start || !end) {
        availabilityError.value = 'No se pudo determinar el período visible del calendario.'
        return
    }

    availabilityLoading.value = true
    try {
        availabilityEvents.value = await getEvents(start.toISOString(), end.toISOString())
        calculateAvailableSlots()
    } catch (error) {
        console.error(error)
        availabilityError.value = error.response?.data?.error
            || 'No se pudieron consultar los horarios disponibles.'
    } finally {
        availabilityLoading.value = false
    }
}

function closeAvailabilitySearch() {
    availabilityOpen.value = false
}

function updateAvailabilityDuration() {
    availabilityVisibleCount.value = 50
    selectedAvailabilitySlotKey.value = ''
    calculateAvailableSlots()
}

function calculateAvailableSlots() {
    const { start: rangeStart, end: rangeEnd } = visibleCalendarRange.value
    if (!rangeStart || !rangeEnd) {
        availabilityResults.value = []
        return
    }

    const selectedKeys = new Set(selectedRoomKeys.value)
    const selectedRooms = new Map(
        rooms.value
            .filter((room) => selectedKeys.has(room.key))
            .map((room) => [room.key, room]),
    )
    const busyEventsByRoom = new Map(
        [...selectedRooms.keys()].map((roomKey) => [roomKey, []]),
    )

    for (const event of availabilityEvents.value) {
        const busyEvents = busyEventsByRoom.get(event.roomKey)
        if (!busyEvents) {
            continue
        }

        const eventStart = parseAvailabilityDate(event.start, event.allDay)
        if (!eventStart) {
            continue
        }

        let eventEnd = event.end ? parseAvailabilityDate(event.end, event.allDay) : null
        if (!eventEnd) {
            eventEnd = new Date(eventStart)
            if (event.allDay) {
                eventEnd.setDate(eventEnd.getDate() + 1)
            } else {
                eventEnd.setTime(eventEnd.getTime() + 60 * 60 * 1000)
            }
        }
        if (eventEnd > eventStart) {
            busyEvents.push({ start: eventStart, end: eventEnd })
        }
    }

    const results = []
    const now = new Date()
    const durationMilliseconds = availabilityDuration.value * 60 * 1000
    const day = new Date(rangeStart)
    day.setHours(0, 0, 0, 0)

    while (day < rangeEnd) {
        const dayEnd = new Date(day)
        dayEnd.setHours(20, 0, 0, 0)

        for (const [roomKey, room] of selectedRooms) {
            const busyEvents = busyEventsByRoom.get(roomKey)
            for (let minuteOfDay = 8 * 60; minuteOfDay + availabilityDuration.value <= 20 * 60; minuteOfDay += 30) {
                const slotStart = new Date(day)
                slotStart.setHours(Math.floor(minuteOfDay / 60), minuteOfDay % 60, 0, 0)
                const slotEnd = new Date(slotStart.getTime() + durationMilliseconds)
                if (slotStart < now || slotEnd > dayEnd || slotStart < rangeStart || slotEnd > rangeEnd) {
                    continue
                }

                const hasConflict = busyEvents.some((event) =>
                    slotStart < event.end && slotEnd > event.start)
                if (!hasConflict) {
                    results.push({
                        key: `${roomKey}:${slotStart.toISOString()}`,
                        roomKey,
                        roomName: room.displayName,
                        roomColor: room.color,
                        start: slotStart,
                    })
                }
            }
        }

        day.setDate(day.getDate() + 1)
    }

    results.sort((left, right) =>
        left.start.getTime() - right.start.getTime()
        || left.roomName.localeCompare(right.roomName, 'es'))
    availabilityResults.value = results
}

function parseAvailabilityDate(value, allDay) {
    if (typeof value !== 'string' || value === '') {
        return null
    }
    if (allDay && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
        const [year, month, day] = value.split('-').map(Number)
        return new Date(year, month - 1, day)
    }

    const date = new Date(value)
    return Number.isNaN(date.getTime()) ? null : date
}

function reserveAvailableSlot(slot) {
    availabilityOpen.value = false
    openReservationForSelection(slot.start)
    reservation.value.roomKey = slot.roomKey
    reservation.value.durationMinutes = availabilityDuration.value
}

function selectAvailabilitySlot(slot) {
    selectedAvailabilitySlotKey.value = slot.key
}

function getSelectedAvailabilitySlot(roomKey) {
    return availabilityResults.value.find((slot) =>
        slot.key === selectedAvailabilitySlotKey.value && slot.roomKey === roomKey)
}

function formatAvailabilityDate(date) {
    return new Intl.DateTimeFormat('es', {
        day: '2-digit',
        month: '2-digit',
    }).format(date)
}

function formatAvailabilityTime(date) {
    return new Intl.DateTimeFormat('es', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(date)
}

function toLocalDateValue(date) {
    const year = date.getFullYear()
    const month = String(date.getMonth() + 1).padStart(2, '0')
    const day = String(date.getDate()).padStart(2, '0')

    return `${year}-${month}-${day}`
}

function openReservation() {
    openReservationForDate(new Date())
}

function openReservationForDate(date) {
    openReservationForSelection(date)
}

function openReservationForRange({ start, end }) {
    openReservationForSelection(start, end)
}

function openReservationForSelection(date, endDate = null) {
    const selectedDate = new Date(date)
    const time = new Date(selectedDate)
    if (endDate === null && time.getHours() === 0 && time.getMinutes() === 0) {
        time.setHours(9, 0, 0, 0)
    } else if (endDate === null) {
        time.setMinutes(Math.ceil(time.getMinutes() / 30) * 30, 0, 0)
    }
    const selectedDurationMinutes = endDate === null
        ? lastReservationDuration.value
        : Math.round((new Date(endDate).getTime() - selectedDate.getTime()) / 60000)
    const durationMinutes = normalizeDuration(selectedDurationMinutes)

    const dateValue = toLocalDateValue(selectedDate)
    const rememberedEndDate = lastContinuousEndDate.value
    const validRememberedEndDate = rememberedEndDate
        && rememberedEndDate >= dateValue
        && weekdayOfDate(rememberedEndDate) === selectedDate.getDay()

    reservation.value = {
        roomKey: selectedRoomKeys.value.length === 1
            ? selectedRoomKeys.value[0]
            : rooms.value[0]?.key || '',
        subject: '',
        date: dateValue,
        time: `${String(time.getHours()).padStart(2, '0')}:${String(time.getMinutes()).padStart(2, '0')}`,
        durationMinutes,
        reservationType: 'punctual',
        forUser: '',
        lastOccurrenceDate: validRememberedEndDate ? rememberedEndDate : '',
        allDay: false,
    }
    editingReservation.value = null
    reservationError.value = ''
    reservationConflicts.value = []
    reservationOpen.value = true
}

function openEditReservation() {
    const event = selectedEvent.value
    if (!event?.canEdit || !event.start || !event.objectUri) {
        return
    }

    const start = new Date(event.start)
    const end = event.end ? new Date(event.end) : new Date(start.getTime() + 60 * 60 * 1000)
    const isRoomReservation = isRoomCalendarEvent(event)
    const reservationType = event.reservationType === 'Período continuo' && canCreateRecurring.value
        ? 'continuous'
        : 'punctual'
    reservation.value = {
        roomKey: event.roomKey || '',
        subject: event.title,
        date: toLocalDateValue(start),
        time: `${String(start.getHours()).padStart(2, '0')}:${String(start.getMinutes()).padStart(2, '0')}`,
        durationMinutes: Math.round((end.getTime() - start.getTime()) / 60000),
        reservationType,
        forUser: '',
        lastOccurrenceDate: reservationType === 'continuous'
            ? (event.seriesEndDate || toLocalDateValue(start))
            : '',
        allDay: event.allDay,
    }
    editingReservation.value = {
        isRoomReservation,
        roomKey: isRoomReservation ? event.roomKey : '',
        calendarUri: event.calendarUri || '',
        objectUri: event.objectUri,
        isRecurring: event.isRecurring,
    }
    selectedEvent.value = null
    reservationError.value = ''
    reservationConflicts.value = []
    reservationOpen.value = true
}

function closeReservation() {
    reservationOpen.value = false
    editingReservation.value = null
    reservationConflicts.value = []
    reservationError.value = ''
}

function updateReservationType() {
    if (reservation.value.reservationType !== 'continuous') {
        return
    }

    const rememberedEndDate = lastContinuousEndDate.value
    reservation.value.lastOccurrenceDate = rememberedEndDate >= reservation.value.date
        && weekdayOfDate(rememberedEndDate) === weekdayOfDate(reservation.value.date)
        ? rememberedEndDate
        : ''
}

function adjustAndRememberContinuousEndDate() {
    let endDate = reservation.value.lastOccurrenceDate
    if (!endDate) {
        return
    }

    if (weekdayOfDate(endDate) !== weekdayOfDate(reservation.value.date)) {
        const adjustedDate = new Date(`${endDate}T00:00:00`)
        const daysBack = (weekdayOfDate(endDate) - weekdayOfDate(reservation.value.date) + 7) % 7
        adjustedDate.setDate(adjustedDate.getDate() - daysBack)
        endDate = toLocalDateValue(adjustedDate)
    }

    if (endDate < reservation.value.date) {
        endDate = reservation.value.date
    }

    reservation.value.lastOccurrenceDate = endDate
    lastContinuousEndDate.value = endDate
    sessionStorage.setItem('reservas-last-continuous-end-date', endDate)
}

function isValidContinuousEndDate(endDate) {
    return endDate >= reservation.value.date
        && weekdayOfDate(endDate) === weekdayOfDate(reservation.value.date)
}

function weekdayOfDate(date) {
    const [year, month, day] = date.split('-').map(Number)
    return new Date(year, month - 1, day).getDay()
}

async function submitReservation() {
    if (reservation.value.reservationType === 'continuous' && !canCreateRecurring.value) {
        reservationError.value = 'Tu grupo no tiene permiso para crear reservas recurrentes.'
        return
    }

    if ((!editingReservation.value || editingReservation.value.isRoomReservation)
        && reservation.value.reservationType === 'continuous'
        && !isValidContinuousEndDate(reservation.value.lastOccurrenceDate)) {
        reservationError.value = 'Elige un último día válido que coincida con el día de la semana de inicio.'
        return
    }

    savingReservation.value = true
    reservationError.value = ''
    reservationConflicts.value = []

    try {
        const reservationData = {
            ...reservation.value,
            start: `${reservation.value.date}T${reservation.value.allDay ? '00:00' : reservation.value.time}:00`,
            timeZone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
        }
        if (editingReservation.value) {
            await updateReservation({
                ...reservationData,
                roomKey: editingReservation.value.roomKey,
                calendarUri: editingReservation.value.calendarUri,
                objectUri: editingReservation.value.objectUri,
            })
        } else {
            await createReservation(reservationData)
            if (!reservation.value.allDay) {
                rememberReservationDuration(reservation.value.durationMinutes)
            }
        }
        reservationOpen.value = false
        editingReservation.value = null
        calendar.value?.refresh()
    } catch (error) {
        reservationConflicts.value = error.response?.data?.conflicts || []
        reservationError.value = error.response?.data?.error || (editingReservation.value
            ? 'No se pudo actualizar la reserva.'
            : 'No se pudo completar la reserva.')
    } finally {
        savingReservation.value = false
    }
}

function rememberReservationDuration(minutes) {
    if (!isValidDuration(minutes)) {
        return
    }

    lastReservationDuration.value = minutes
    try {
        localStorage.setItem('reservas-last-duration-minutes', String(minutes))
    } catch (error) {
        console.error('No se pudo guardar la duración preferida de la reserva.', error)
    }
}

async function removeSelectedReservation() {
    if (!selectedEvent.value?.canDelete || deletingReservation.value) {
        return
    }

    const isRoomReservation = isRoomCalendarEvent(selectedEvent.value)
    const confirmation = isRoomReservation
        ? '¿Eliminar esta reserva? Si se repite semanalmente, se eliminará toda la serie.'
        : '¿Eliminar este evento? Si se repite, se eliminará toda la serie.'
    if (!window.confirm(confirmation)) {
        return
    }

    deletingReservation.value = true
    deleteReservationError.value = ''

    try {
        await deleteReservation(
            selectedEvent.value.calendarUri,
            selectedEvent.value.objectUri,
            selectedEvent.value.roomKey,
        )
        selectedEvent.value = null
        calendar.value?.refresh()
    } catch (error) {
        console.error(error)
        deleteReservationError.value = error.response?.data?.error || 'No se pudo eliminar la reserva.'
    } finally {
        deletingReservation.value = false
    }
}

function formatReservationDate(date) {
    if (!date) {
        return ''
    }

    const [year, month, day] = date.split('-').map(Number)
    return new Intl.DateTimeFormat('es', {
        dateStyle: 'full',
    }).format(new Date(year, month - 1, day))
}

function formatEventRange(start, end) {
    if (!start) {
        return 'Sin especificar'
    }

    const dayFormatter = new Intl.DateTimeFormat('es', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    })
    const timeFormatter = new Intl.DateTimeFormat('es', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    })
    const startDay = dayFormatter.format(start)
    const startTime = timeFormatter.format(start)

    if (!end) {
        return `${startDay}, ${startTime}`
    }

    const endDay = dayFormatter.format(end)
    const endTime = timeFormatter.format(end)

    return startDay === endDay
        ? `${startDay}, ${startTime} - ${endTime}`
        : `${startDay}, ${startTime} - ${endDay}, ${endTime}`
}

onMounted(() => {
    window.addEventListener('keydown', handleDialogKeydown)
    getAccess()
        .then((access) => {
            canCreateRecurring.value = access.canCreateRecurring
            accessLoaded.value = access.canUse
            if (access.canCreateRecurring) {
                if (Array.isArray(access.eligibleUsers)) {
                    eligibleUsers.value = access.eligibleUsers
                } else {
                    eligibleUsersError.value = 'No se pudieron cargar los usuarios disponibles.'
                }
            }
            if (access.canUse) {
                loadCalendars().catch((error) => {
                    console.error(error)
                })
            }
        })
        .catch((error) => {
            console.error(error)
            accessLoaded.value = false
        })
})

onUnmounted(() => {
    window.removeEventListener('keydown', handleDialogKeydown)
})
</script>

<style>
#app {
    flex: 1 1 0;
    min-width: 0;
}

.icic-calendar {
    --calendar-blue: #1a73e8;
    --calendar-text: #3c4043;
    --calendar-muted: #70757a;
    --calendar-border: #dadce0;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    width: 100%;
    height: calc(100vh - 50px);
    min-height: 480px;
    padding: 8px 18px 18px;
    color: var(--calendar-text);
    background: #f8fafd;
    font-family: var(--font-face, Arial, sans-serif);
}

.icic-calendar *,
.icic-calendar *::before,
.icic-calendar *::after {
    box-sizing: border-box;
}

.calendar-topbar {
    display: flex;
    flex: 0 0 64px;
    align-items: center;
    gap: 30px;
    padding: 0 10px 8px;
}

.brand {
    display: flex;
    flex: 0 0 220px;
    align-items: center;
    gap: 12px;
}

.brand-icon {
    display: block;
    width: 40px;
    height: 40px;
    padding: 5px;
    border-radius: 10px;
    background: var(--calendar-blue);
}

.brand-name {
    color: var(--calendar-text);
    font-size: 21px;
    font-weight: 400;
    white-space: nowrap;
}

.calendar-toolbar,
.toolbar-actions,
.navigation-buttons,
.mini-calendar-heading,
.mini-navigation {
    display: flex;
    align-items: center;
}

.calendar-toolbar {
    min-width: 0;
    gap: 14px;
}

.calendar-toolbar h1 {
    overflow: hidden;
    margin: 0;
    color: var(--calendar-text);
    font-size: 22px;
    font-weight: 400;
    text-overflow: ellipsis;
    text-transform: none;
    white-space: nowrap;
}

.today-button,
.navigation-buttons button,
.refresh-button,
.mini-navigation button {
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border: 1px solid var(--calendar-border);
    color: var(--calendar-text);
    background: #fff;
    cursor: pointer;
}

.today-button {
    min-width: 64px;
    height: 36px;
    padding: 0 15px;
    border-radius: 5px;
    font-size: 14px;
    font-weight: 500;
}

.navigation-buttons {
    overflow: hidden;
    border-radius: 50px;
}

.navigation-buttons button {
    width: 36px;
    height: 36px;
    border-radius: 0;
    font-size: 25px;
    line-height: 1;
}

.navigation-buttons button + button {
    border-left: 0;
}

.toolbar-actions {
    gap: 12px;
    margin-left: auto;
}

.availability-button {
    white-space: nowrap;
}

.availability-button:disabled {
    cursor: not-allowed;
    opacity: .65;
}

.view-switcher {
    display: flex;
    overflow: hidden;
    border: 1px solid var(--calendar-border);
    border-radius: 5px;
    background: #fff;
}

.view-switcher button {
    min-width: 60px;
    height: 34px;
    padding: 0 12px;
    border: 0;
    color: var(--calendar-text);
    background: transparent;
    cursor: pointer;
    font-size: 13px;
}

.view-switcher button + button {
    border-left: 1px solid var(--calendar-border);
}

.view-switcher button.active {
    color: #174ea6;
    background: #e8f0fe;
    font-weight: 600;
}

.refresh-button {
    width: 36px;
    height: 36px;
    border-radius: 50%;
}

.refresh-button svg {
    width: 19px;
    fill: none;
    stroke: currentColor;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-width: 1.7;
}

.reserve-button {
    min-height: 36px;
    padding: 0 15px;
    border: 0;
    border-radius: 6px;
    color: #fff;
    background: var(--calendar-blue);
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
}

.reserve-button:hover {
    background: #1765cc;
}

.reserve-button:disabled {
    cursor: wait;
    opacity: .65;
}

.room-list {
    margin-top: 24px;
}

.empty-room-list {
    margin: 0;
    padding: 5px 7px;
    color: var(--calendar-muted);
    font-size: 12px;
    line-height: 1.5;
}

.modal-backdrop {
    position: fixed;
    z-index: 10000;
    inset: 0;
    display: grid;
    overflow-y: auto;
    padding: 24px;
    place-items: center;
    background: rgb(25 32 40 / 48%);
}

.reservation-modal {
    position: relative;
    width: min(100%, 520px);
    max-height: 100%;
    overflow-y: auto;
    padding: 28px;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 12px 48px rgb(0 0 0 / 24%);
}

.availability-modal {
    width: min(100%, 520px);
    padding: 22px;
}

.availability-modal h2 {
    margin-bottom: 12px;
    font-size: 20px;
}

.reservation-modal h2 {
    margin: 0 36px 22px 0;
    color: var(--calendar-text);
    font-size: 21px;
    font-weight: 500;
}

.modal-close {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 34px;
    height: 34px;
    border: 0;
    border-radius: 50%;
    color: var(--calendar-muted);
    background: transparent;
    cursor: pointer;
    font-size: 25px;
}

.reservation-modal form,
.reservation-modal label {
    display: flex;
    flex-direction: column;
}

.reservation-modal form {
    gap: 16px;
}

.reservation-modal label {
    gap: 6px;
    color: var(--calendar-text);
    font-size: 13px;
    font-weight: 600;
}

.availability-context,
.availability-message {
    margin: 0 0 14px;
    color: var(--calendar-muted);
    font-size: 13px;
    line-height: 1.5;
}

.availability-results {
    max-height: min(52vh, 420px);
    overflow: auto;
}

.availability-room-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-items: stretch;
    gap: 8px;
}

.availability-room-card {
    display: flex;
    min-width: 0;
    flex-direction: column;
    gap: 8px;
    padding: 10px;
    border: 1px solid var(--calendar-border);
    border-top: 3px solid var(--availability-room-color);
    border-radius: 7px;
    background: #fff;
}

.availability-room-title {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    color: var(--calendar-text);
    font-size: 17px;
}

.availability-room-title span {
    width: 14px;
    height: 14px;
    flex: 0 0 14px;
    border-radius: 50%;
    background: var(--availability-room-color);
}

.availability-room-label {
    margin: 0;
    color: var(--calendar-text);
    font-size: 13px;
}

.availability-day-group + .availability-day-group {
    margin-top: 5px;
}

.availability-day-group h4 {
    margin: 0 0 5px;
    color: var(--calendar-muted);
    font-size: 12px;
    font-weight: 600;
}

.availability-time-slots {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 5px;
}

.availability-time-button {
    min-width: 0;
    min-height: 32px;
    padding: 0 5px;
    border: 1px solid #d2e3fc;
    border-radius: 6px;
    color: #174ea6;
    background: #e8f0fe;
    cursor: pointer;
    font: inherit;
    font-size: 13px;
    font-weight: 500;
}

.availability-time-button:hover {
    background: #d3e3fd;
}

.availability-time-button.selected {
    border-color: var(--calendar-blue);
    color: #fff;
    background: var(--calendar-blue);
}

.availability-room-book-button {
    width: 100%;
    margin-top: auto;
    white-space: normal;
}

.availability-room-book-button:disabled {
    cursor: not-allowed;
    opacity: .6;
}

.availability-more-button {
    display: block;
    margin: 12px auto;
}

.reservation-modal label.all-day-toggle {
    flex-direction: row;
    align-items: center;
}

.reservation-modal .all-day-toggle input {
    width: auto;
    min-height: 0;
}

.reservation-modal input,
.reservation-modal select {
    width: 100%;
    min-height: 40px;
    padding: 8px 10px;
    border: 1px solid var(--calendar-border);
    border-radius: 6px;
    color: var(--calendar-text);
    background: #fff;
    font: inherit;
    font-weight: 400;
}

.reservation-modal input[type="range"] {
    min-height: 0;
    padding: 0;
    border: 0;
    accent-color: var(--calendar-blue);
}

.duration-control {
    grid-column: 1 / -1;
}

.duration-heading,
.duration-limits {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.duration-heading output {
    color: var(--calendar-blue);
    font-size: 15px;
    font-weight: 600;
}

.duration-limits {
    color: var(--calendar-muted);
    font-size: 11px;
    font-weight: 400;
}

.date-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.reservation-date {
    margin: 0;
    color: var(--calendar-muted);
    font-size: 13px;
}

.reservation-date strong {
    color: var(--calendar-text);
    font-weight: 600;
}

.reservation-type {
    margin: -4px 0 0;
    color: var(--calendar-muted);
    font-size: 12px;
}

.form-error {
    margin: 0;
    padding: 10px 12px;
    border-radius: 6px;
    color: #8d1b13;
    background: #fce8e6;
    font-size: 13px;
    line-height: 1.5;
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 6px;
}

.secondary-button {
    min-height: 36px;
    padding: 0 14px;
    border: 1px solid var(--calendar-border);
    border-radius: 6px;
    color: var(--calendar-text);
    background: #fff;
    cursor: pointer;
    font-size: 13px;
}

.delete-button {
    display: inline-flex;
    min-height: 36px;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 0 12px;
    border: 1px solid #d93025;
    border-radius: 6px;
    color: #b3261e;
    background: #fff;
    cursor: pointer;
    font-size: 13px;
}

.delete-button svg {
    width: 17px;
    height: 17px;
    fill: none;
    stroke: currentColor;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-width: 1.8;
}

.delete-button:disabled {
    cursor: wait;
    opacity: .65;
}

.event-details dl {
    display: grid;
    grid-template-columns: 130px minmax(0, 1fr);
    gap: 10px 14px;
    margin: 0;
    font-size: 13px;
}

.event-details dt {
    color: var(--calendar-muted);
}

.event-details dd {
    overflow-wrap: anywhere;
    margin: 0;
    color: var(--calendar-text);
}



.today-button:hover,
.navigation-buttons button:hover,
.refresh-button:hover,
.mini-navigation button:hover {
    background: #f1f3f4;
}

.calendar-layout {
    display: flex;
    flex: 1;
    min-height: 0;
    overflow: hidden;
    border: 1px solid #e4e7eb;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 1px 2px rgb(60 64 67 / 12%);
}

.calendar-sidebar {
    flex: 0 0 250px;
    overflow-y: auto;
    padding: 20px 16px;
    border-right: 1px solid #e4e7eb;
    background: #fff;
}

.mini-calendar {
    margin-bottom: 30px;
}

.mini-calendar-heading {
    justify-content: space-between;
    margin: 0 0 14px;
    padding: 0 3px;
}

.mini-calendar-heading h2,
.calendar-list h2 {
    margin: 0;
    color: var(--calendar-text);
    font-size: 14px;
    font-weight: 600;
}

.mini-calendar-heading h2 {
    text-transform: none;
}

.mini-navigation {
    gap: 2px;
}

.mini-navigation button {
    width: 28px;
    height: 28px;
    border: 0;
    border-radius: 50%;
    font-size: 22px;
    line-height: 1;
}

.mini-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    justify-items: center;
    row-gap: 3px;
}

.mini-weekdays {
    margin-bottom: 5px;
    color: var(--calendar-muted);
    font-size: 11px;
    font-weight: 600;
}

.mini-grid button {
    width: 27px;
    height: 27px;
    padding: 0;
    border: 0;
    border-radius: 50%;
    color: var(--calendar-text);
    background: transparent;
    cursor: pointer;
    font-size: 11px;
}

.mini-grid button:hover {
    background: #f1f3f4;
}

.mini-grid button.outside-month {
    color: #bdc1c6;
}

.mini-grid button.today {
    color: var(--calendar-blue);
    font-weight: 700;
}

.mini-grid button.selected {
    color: #fff;
    background: var(--calendar-blue);
    font-weight: 600;
}

.calendar-list h2 {
    margin: 0 0 13px 4px;
}

.calendar-item {
    display: flex;
    align-items: center;
    gap: 11px;
    min-height: 36px;
    padding: 5px 7px;
    border-radius: 6px;
    color: var(--calendar-text);
    font-size: 13px;
}

.room-filter {
    width: 100%;
    border: 0;
    background: transparent;
    text-align: left;
    cursor: pointer;
    opacity: .48;
}

.room-filter[aria-pressed="true"] {
    opacity: 1;
}

.room-filter:focus-visible {
    outline: 2px solid var(--calendar-blue);
    outline-offset: 1px;
}

.calendar-color {
    width: 13px;
    height: 13px;
    flex: 0 0 13px;
    border-radius: 3px;
}

.calendar-content {
    flex: 1;
    min-width: 0;
    min-height: 0;
    padding: 4px 0 12px 12px;
}

.fc {
    height: 100%;
    color: var(--calendar-text);
    font-size: 13px;
}

.fc .fc-scrollgrid,
.fc .fc-scrollgrid-section > td,
.fc-theme-standard td,
.fc-theme-standard th {
    border-color: #e4e7eb;
}

.fc .fc-col-header-cell {
    height: 42px;
    background: #fff;
}

.fc .fc-col-header-cell-cushion {
    padding: 13px 4px 9px;
    color: var(--calendar-muted);
    font-size: 11px;
    font-weight: 500;
    text-decoration: none;
    text-transform: uppercase;
}

.fc .fc-daygrid-day-number {
    display: grid;
    width: 28px;
    height: 28px;
    margin: 4px 5px 0 auto;
    place-items: center;
    border-radius: 50%;
    color: var(--calendar-text);
    font-size: 12px;
    text-decoration: none;
}

.fc .fc-daygrid-day.fc-day-today {
    background: #fff;
}

.fc .fc-daygrid-day.fc-day-today .fc-daygrid-day-number {
    color: #fff;
    background: var(--calendar-blue);
}

.fc .fc-day-other .fc-daygrid-day-number {
    color: #9aa0a6;
}

.fc .fc-daygrid-event {
    margin: 1px 5px;
    padding: 2px 4px;
    border: 0;
    border-radius: 4px;
    background: #d3e3fd;
    color: #174ea6;
    font-size: 12px;
}

.fc .fc-timegrid-slot {
    height: 40px;
}

.fc .fc-timegrid-axis-cushion,
.fc .fc-timegrid-slot-label-cushion {
    color: var(--calendar-muted);
    font-size: 11px;
}

@media (max-width: 1050px) {
    .calendar-topbar {
        gap: 16px;
    }

    .brand {
        flex-basis: 185px;
    }

    .calendar-sidebar {
        flex-basis: 220px;
    }

    .view-switcher button {
        min-width: 48px;
        padding: 0 8px;
    }
}

@media (max-width: 760px) {
    .icic-calendar {
        height: calc(100vh - 50px);
        min-height: 420px;
        padding: 4px 8px 8px;
    }

    .calendar-topbar {
        flex-wrap: wrap;
        flex: 0 0 auto;
        gap: 4px 12px;
        padding: 6px 4px 10px;
    }

    .brand {
        flex: 1 1 100%;
        gap: 7px;
    }

    .brand-icon {
        width: 30px;
        height: 30px;
        padding: 4px;
    }

    .brand-name {
        font-size: 17px;
    }

    .calendar-toolbar {
        gap: 7px;
    }

    .calendar-toolbar h1 {
        max-width: 150px;
        font-size: 17px;
    }

    .today-button,
    .navigation-buttons button {
        height: 32px;
    }

    .navigation-buttons button {
        width: 32px;
    }

    .view-switcher button {
        min-width: 40px;
        height: 30px;
        padding: 0 6px;
        font-size: 12px;
    }

    .toolbar-actions {
        gap: 5px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .reserve-button {
        min-height: 32px;
        padding: 0 9px;
        font-size: 11px;
    }

    .availability-button {
        min-height: 32px;
        padding: 0 7px;
        font-size: 10px;
    }

    .refresh-button {
        width: 32px;
        height: 32px;
    }

    .calendar-layout {
        border-radius: 12px;
    }

    .calendar-sidebar {
        display: none;
    }

    .calendar-content {
        padding: 3px 0 5px 4px;
    }

    .fc {
        font-size: 11px;
    }

    .fc .fc-col-header-cell-cushion {
        padding-right: 0;
        padding-left: 0;
        font-size: 9px;
    }

    .modal-backdrop {
        padding: 12px;
    }

    .reservation-modal {
        padding: 22px 18px;
    }

    .date-fields {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 480px) {
    .availability-room-grid {
        grid-template-columns: 1fr;
    }
}
</style>
