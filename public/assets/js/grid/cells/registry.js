// assets/js/grid/cells/registry.js — CellRenderer: maps column type -> cell renderer fn (register/render/has). Cell modules self-register; render() throws on an unknown type.

const renderers = new Map();

export const CellRenderer = {
    register(type, fn) {
        renderers.set(type, fn);
    },

    render(type, ctx) {
        const fn = renderers.get(type);
        if (!fn) throw new Error(`No cell renderer for type: "${type}"`);
        return fn(ctx);
    },

    has(type) {
        return renderers.has(type);
    },
};
