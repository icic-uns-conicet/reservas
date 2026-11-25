<div id="reservas-admin-settings" class="section">
    <h2><?php p($l->t('Acceso a Reservas')); ?></h2>
    <p><?php p($l->t('Configure los grupos que pueden usar la aplicación. Los usuarios fuera de ambos grupos no podrán acceder.')); ?></p>

    <label for="reservas-recurring-groups" style="display: flex; flex-direction: column; align-items: flex-start;">
        <?php p($l->t('Grupo(s) autorizado para reservas recurrentes')); ?>
        <select id="reservas-recurring-groups" multiple size="16" style="min-height: 20rem; width: 50%;">
            <?php foreach ($_['groups'] as $group): ?>
                <option value="<?php p($group['gid']); ?>" <?php print(in_array($group['gid'], $_['recurringGroups'], true) ? 'selected' : ''); ?>>
                    <?php p($group['displayName']); ?> (<?php p($group['gid']); ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <p><?php p($l->t('Puedes seleccionar varios grupos manteniendo presionada la tecla Ctrl o Cmd.')); ?></p>
    </label>

    <label for="reservas-application-groups" style="display: flex; flex-direction: column; align-items: flex-start;">
        <?php p($l->t('Grupo(s) autorizado para utilizar la aplicación')); ?>
        <select id="reservas-application-groups" multiple size="16" style="min-height: 20rem; width: 50%;">
            <?php foreach ($_['groups'] as $group): ?>
                <option value="<?php p($group['gid']); ?>" <?php print(in_array($group['gid'], $_['applicationGroups'], true) ? 'selected' : ''); ?>>
                    <?php p($group['displayName']); ?> (<?php p($group['gid']); ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <p><?php p($l->t('Puedes seleccionar varios grupos manteniendo presionada la tecla Ctrl o Cmd.')); ?></p>
    </label>

    <button id="reservas-save-settings" class="primary">
        <?php p($l->t('Guardar')); ?>
    </button>
    <span id="reservas-settings-status" role="status"></span>
</div>

<?php script('reservas', 'settings-admin'); ?>
