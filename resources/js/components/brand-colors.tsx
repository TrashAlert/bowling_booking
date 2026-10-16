import { usePage } from '@inertiajs/react';

// The only shape a colour can have before it is written into the styles.
const COLOR = /^#[0-9a-f]{6}$/i;

/**
 * Paints buttons and highlights in the business's brand colour, in light
 * mode and dark, while a customer page is shown. Without a brand colour the
 * pages keep their plain black and white.
 */
export default function BrandColors() {
    const { brand } = usePage().props;

    if (
        brand === null ||
        !COLOR.test(brand.color) ||
        !COLOR.test(brand.textColor)
    ) {
        return null;
    }

    return (
        <style>{`:root, .dark { --primary: ${brand.color}; --primary-foreground: ${brand.textColor}; }`}</style>
    );
}
