const DARK_TEXT = '#0a0a0a';

/**
 * How light a colour like "#1d4ed8" looks, from 0 for black to 1 for white,
 * as the web's accessibility guidelines (WCAG) measure it.
 */
function luminance(color: string): number {
    const [red, green, blue] = [1, 3, 5].map((at) => {
        const value = parseInt(color.slice(at, at + 2), 16) / 255;

        return value <= 0.03928
            ? value / 12.92
            : ((value + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * red + 0.7152 * green + 0.0722 * blue;
}

/**
 * White or near-black, whichever stands out more from the colour. The server
 * makes the same choice for the customer pages; this one is for previews.
 */
export function textColorOn(color: string): string {
    const light = luminance(color);

    return 1.05 / (light + 0.05) >=
        (light + 0.05) / (luminance(DARK_TEXT) + 0.05)
        ? '#ffffff'
        : DARK_TEXT;
}
