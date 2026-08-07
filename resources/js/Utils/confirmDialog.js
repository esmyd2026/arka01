import { reactive } from 'vue';

// Diálogo de confirmación con el estilo oscuro de la app (pedido explícito
// del usuario: "mejoremos el diseño de las alertas" — window.confirm() nativo
// no se puede re-estilar, se ve como una alerta del sistema operativo, no de
// Arka01). Un solo estado global + un único <ConfirmDialogHost> montado en la
// raíz (resources/js/app.js) — cualquier pantalla pide una confirmación con
// `await confirmDialog('¿Seguro...?')`, casi igual que antes con `confirm()`.
export const confirmDialogState = reactive({
    visible: false,
    message: '',
    danger: false,
    confirmLabel: 'Aceptar',
    cancelLabel: 'Cancelar',
    resolve: null,
});

/**
 * @param {string} message
 * @param {{ danger?: boolean, confirmLabel?: string, cancelLabel?: string }} options
 * @returns {Promise<boolean>}
 */
export function confirmDialog(message, options = {}) {
    return new Promise((resolve) => {
        confirmDialogState.message = message;
        confirmDialogState.danger = options.danger ?? false;
        confirmDialogState.confirmLabel = options.confirmLabel ?? 'Aceptar';
        confirmDialogState.cancelLabel = options.cancelLabel ?? 'Cancelar';
        confirmDialogState.resolve = resolve;
        confirmDialogState.visible = true;
    });
}

export function resolveConfirmDialog(result) {
    confirmDialogState.visible = false;
    confirmDialogState.resolve?.(result);
    confirmDialogState.resolve = null;
}
