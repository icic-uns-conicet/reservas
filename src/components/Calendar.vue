<template>
    <FullCalendar
        ref="fullCalendar"
        :options="calendarOptions"
    />
</template>

<script setup>
import { ref, watch } from 'vue'
import FullCalendar from '@fullcalendar/vue3'
import dayGridPlugin from '@fullcalendar/daygrid'
import timeGridPlugin from '@fullcalendar/timegrid'
import interactionPlugin from '@fullcalendar/interaction'
import esLocale from '@fullcalendar/core/locales/es'

import { getEvents } from '../services/calendar.js'

const props = defineProps({
    selectedRoomKeys: {
        type: Array,
        default: () => [],
    },
})

const emit = defineEmits(['date-change', 'view-change', 'event-select', 'reservation-start', 'reservation-range-start'])
const fullCalendar = ref(null)

const calendarOptions = {
    plugins: [
        dayGridPlugin,
        timeGridPlugin,
        interactionPlugin
    ],

    locale: esLocale,
    firstDay: 1,
    initialView: 'dayGridMonth',
    headerToolbar: false,
    eventTimeFormat: {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    },
    slotLabelFormat: {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    },
    slotEventOverlap: false,

    editable: false,
    selectable: true,
    selectMirror: true,
    selectAllow(selection) {
        const durationMinutes = (selection.end.getTime() - selection.start.getTime()) / 60000
        return !selection.allDay && durationMinutes > 0 && durationMinutes <= 480
    },
    select(info) {
        if (info.view.type !== 'timeGridWeek' && info.view.type !== 'timeGridDay') {
            return
        }

        info.view.calendar.unselect()
        emit('reservation-range-start', {
            start: info.start,
            end: info.end,
        })
    },
    dateClick(info) {
        emit('reservation-start', info.date)
    },
    eventClick(info) {
        emit('event-select', {
            title: info.event.title,
            start: info.event.start,
            end: info.event.end,
            allDay: info.event.allDay,
            recipient: info.event.extendedProps.recipient || '',
            recipientEmail: info.event.extendedProps.recipientEmail || '',
            owner: info.event.extendedProps.owner || '',
            ownerEmail: info.event.extendedProps.ownerEmail || '',
            location: info.event.extendedProps.location || '',
            reservationType: info.event.extendedProps.reservationType || '',
            seriesEndDate: info.event.extendedProps.seriesEndDate || '',
            isRecurring: info.event.extendedProps.isRecurring || false,
            canDelete: info.event.extendedProps.canDelete || false,
            canEdit: info.event.extendedProps.canEdit || false,
            calendarUri: info.event.extendedProps.calendarUri || '',
            objectUri: info.event.extendedProps.objectUri || '',
            roomKey: info.event.extendedProps.roomKey || '',
            sourcePrincipal: info.event.extendedProps.sourcePrincipal || '',
        })
    },

    datesSet(info) {
        emit('date-change', {
            title: info.view.title,
            date: info.view.calendar.getDate(),
            start: info.start,
            end: info.end,
        })
        emit('view-change', info.view.type)
    },

    events: async function(info, successCallback, failureCallback) {
        try {
            const events = await getEvents(
                info.start.toISOString(),
                info.end.toISOString()
            )

            successCallback(events.filter((event) => props.selectedRoomKeys.includes(event.roomKey)))
        } catch (error) {
            console.error(error)
            failureCallback(error)
        }
    }
}

function getApi() {
    return fullCalendar.value?.getApi()
}

watch(
    () => props.selectedRoomKeys,
    () => getApi()?.refetchEvents(),
    { deep: true },
)

defineExpose({
    prev: () => getApi()?.prev(),
    next: () => getApi()?.next(),
    today: () => getApi()?.today(),
    gotoDate: (date) => getApi()?.gotoDate(date),
    changeView: (view) => getApi()?.changeView(view),
    refresh: () => getApi()?.refetchEvents(),
})
</script>

<style>
.fc {
    height: 100%;
}

.fc .fc-view-harness {
    height: 100% !important;
}

.fc .fc-timegrid-col-events {
    margin: 0 !important;
}
</style>
