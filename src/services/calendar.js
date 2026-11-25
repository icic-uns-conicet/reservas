import axios from '@nextcloud/axios'

export async function getAccess() {
    const response = await axios.get(
        OC.generateUrl('/apps/reservas/api/access'),
        {
            headers: {
                'OCS-APIRequest': 'true'
            }
        }
    )

    return response.data
}

export async function getCalendars() {
    const response = await axios.get(
        OC.generateUrl('/apps/reservas/api/calendars'),
        {
            headers: {
                'OCS-APIRequest': 'true'
            }
        }
    )

    return response.data.calendars
}

export async function getRooms() {
    const response = await axios.get(
        OC.generateUrl('/apps/reservas/api/rooms'),
        {
            headers: {
                'OCS-APIRequest': 'true'
            }
        }
    )

    return response.data.rooms
}

export async function getEvents(start, end) {
    const response = await axios.get(
        OC.generateUrl('/apps/reservas/api/events'),
        {
            params: {
                start,
                end
            },
            headers: {
                'OCS-APIRequest': 'true'
            }
        }
    )

    return response.data.events
}

export async function createReservation(reservation) {
    const response = await axios.post(
        OC.generateUrl('/apps/reservas/api/reservations'),
        reservation,
        {
            headers: {
                'OCS-APIRequest': 'true'
            }
        }
    )

    return response.data
}

export async function updateReservation(reservation) {
    const response = await axios.post(
        OC.generateUrl('/apps/reservas/api/reservations/update'),
        reservation,
        {
            headers: {
                'OCS-APIRequest': 'true'
            }
        }
    )

    return response.data
}

export async function deleteReservation(calendarUri, objectUri, roomKey) {
    const response = await axios.post(
        OC.generateUrl('/apps/reservas/api/reservations/delete'),
        {
            calendarUri,
            objectUri,
            roomKey,
        },
        {
            headers: {
                'OCS-APIRequest': 'true'
            }
        }
    )

    return response.data
}
