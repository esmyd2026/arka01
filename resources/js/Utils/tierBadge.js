// Colores válidos para la insignia de medalla del conductor (pedido
// explícito del usuario: medallas configurables desde /admin/medallas).
// Tiene que ser un diccionario fijo acá, no texto libre desde la base: el
// build de Tailwind solo genera CSS para clases que aparecen literalmente en
// el código fuente — una guardada en la base de datos no serviría de nada.
export const TIER_COLOR_CLASSES = {
    slate: 'bg-slate-400/15 text-slate-700',
    orange: 'bg-orange-700/15 text-orange-700',
    cyan: 'bg-cyan-500/15 text-cyan-700',
    yellow: 'bg-yellow-500/15 text-yellow-700',
    purple: 'bg-purple-500/15 text-purple-700',
    blue: 'bg-blue-500/15 text-blue-700',
    green: 'bg-green-500/15 text-green-700',
    red: 'bg-red-500/15 text-red-700',
};

const DEFAULT_CLASS = 'bg-arka-text-muted/15 text-arka-text-muted';

export function tierColorClass(colorKey) {
    return TIER_COLOR_CLASSES[colorKey] ?? DEFAULT_CLASS;
}

export function tierLabel(tier) {
    if (!tier) return '';
    return tier.badge_emoji ? `${tier.badge_emoji} ${tier.name}` : tier.name;
}
