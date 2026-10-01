import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * A row of buttons of which one is chosen, submitted with a form under the
 * given name. An option may carry a line of small print under its label.
 */
export function ChoicePills<Value extends string | number>({
    name,
    options,
    value,
    onChange,
    className,
}: {
    name: string;
    options: { value: Value; label: ReactNode; hint?: ReactNode }[];
    value: Value;
    onChange: (value: Value) => void;
    className?: string;
}) {
    return (
        <div className={cn('grid grid-cols-4 gap-2', className)}>
            {options.map((option) => (
                <label
                    key={option.value}
                    className="flex min-h-11 cursor-pointer flex-col items-center justify-center rounded-lg border px-2 py-1.5 text-center text-sm font-medium tabular-nums has-checked:border-primary has-checked:bg-primary has-checked:text-primary-foreground has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                >
                    <input
                        type="radio"
                        name={name}
                        value={option.value}
                        checked={option.value === value}
                        onChange={() => onChange(option.value)}
                        className="sr-only"
                    />
                    {option.label}
                    {option.hint && (
                        <span className="text-xs font-normal opacity-80">
                            {option.hint}
                        </span>
                    )}
                </label>
            ))}
        </div>
    );
}
