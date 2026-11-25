(function () {
    const saveButton = document.getElementById('reservas-save-settings')
    if (!saveButton) {
        return
    }

    saveButton.addEventListener('click', async function () {
        const status = document.getElementById('reservas-settings-status')
        status.textContent = ''

        try {
            const response = await fetch(OC.generateUrl('/apps/reservas/api/settings'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken,
                },
                body: JSON.stringify({
                    recurringGroups: Array.from(
                        document.getElementById('reservas-recurring-groups').selectedOptions,
                        (option) => option.value,
                    ),
                    applicationGroups: Array.from(
                        document.getElementById('reservas-application-groups').selectedOptions,
                        (option) => option.value,
                    ),
                }),
            })
            const data = await response.json()
            if (!response.ok) {
                throw new Error(data.error || 'No se pudo guardar la configuración.')
            }
            status.textContent = 'Configuración guardada.'
        } catch (error) {
            status.textContent = error.message
        }
    })
})()
