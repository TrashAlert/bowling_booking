import { Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/hooks/use-appearance';

/**
 * A button that switches the page between light and dark. Until someone
 * presses it the page follows the device's own setting; the choice is then
 * remembered on that device.
 */
export default function ThemeToggle() {
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const isDark = resolvedAppearance === 'dark';
    const label = isDark ? 'Switch to light mode' : 'Switch to dark mode';

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            aria-label={label}
            title={label}
            className="shrink-0"
            onClick={() => updateAppearance(isDark ? 'light' : 'dark')}
        >
            {isDark ? <Sun aria-hidden /> : <Moon aria-hidden />}
        </Button>
    );
}
