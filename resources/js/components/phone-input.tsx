import type { ComponentProps } from 'react';
import { Input } from '@/components/ui/input';

// The fewest digits a phone number can have; the server checks it too.
const MIN_DIGITS = 10;

/**
 * A phone number field named "phone" that only takes digits, at least ten of
 * them. Anything else typed or pasted in is dropped as it arrives; the server
 * refuses it too. Left empty, an optional field is not held to the minimum.
 */
export default function PhoneInput(
    props: Omit<
        ComponentProps<typeof Input>,
        | 'name'
        | 'type'
        | 'inputMode'
        | 'pattern'
        | 'minLength'
        | 'maxLength'
        | 'onInput'
    >,
) {
    return (
        <Input
            {...props}
            name="phone"
            type="tel"
            inputMode="numeric"
            pattern="[0-9]*"
            minLength={MIN_DIGITS}
            maxLength={30}
            onInput={(event) => {
                event.currentTarget.value = event.currentTarget.value.replace(
                    /\D/g,
                    '',
                );
            }}
        />
    );
}
