// assets/js/dashboard/registry.js — WidgetRegistry: maps widget.type -> renderer fn (register/render/has). Widget modules self-register here; render() returns an error node for unknown types.

const renderers = new Map();

export const WidgetRegistry = {
    register(type, fn) {
        renderers.set(type, fn);
    },

    render(widget) {
        const fn = renderers.get(widget.type);
        if (!fn) {
            const err = document.createElement('p');
            err.textContent = window.I18n.t('dashboard.unknown_widget_type', { type: widget.type });
            return err;
        }
        return fn(widget);
    },

    has(type) {
        return renderers.has(type);
    },
};
