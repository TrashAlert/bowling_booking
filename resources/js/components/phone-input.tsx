import type { ComponentProps } from 'react';
import { Input } from '@/components/ui/input';

/**
 * A phone number field named "phone" that only takes digits. Anything else
 * typed or pasted in is dropped as it arrives; the server refuses it too.
 */
export default function PhoneInput(
    props: Omit<
        ComponentProps<typeof Input>,
        'name' | 'type' | 'inputMode' | 'pattern' | 'maxLength' | 'onInput'
    >,
) {
    return (
        <Input
            {...props}
            name="phone"
            type="tel"
            inputMode="numeric"
            pattern="[0-9]*"
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
