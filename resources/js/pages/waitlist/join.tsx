import { Form, Head, usePage } from '@inertiajs/react';
import WaitlistJoinController from '@/actions/App/Http/Controllers/WaitlistJoinController';
import InputError from '@/components/input-error';
import { SessionLengthPicker } from '@/components/staff/session-length-picker';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMoney } from '@/lib/format';
import type { SessionRules } from '@/types';

/**
 * Where a party adds itself to the waitlist. Sending the form doesn't put it
 * in line yet: it pays a deposit on the next page first.
 */
export default function WaitlistJoin({
    session,
    checkInMinutes,
    depositCents,
}: {
    session: SessionRules;
    checkInMinutes: number;
    depositCents: number;
}) {
    const { currencySymbol } = usePage().props;
    const deposit = formatMoney(depositCents, currencySymbol);

    return (
        <>
            <Head title="Join the waitlist" />

            <Card>
                <CardHeader>
                    <CardTitle className="text-xl">Join the waitlist</CardTitle>
                    <CardDescription>
                        Add your group to the line and we will call you when a
                        lane is ready. You then have {checkInMinutes} minutes to
                        check in at the counter, so join when you are here or
                        nearly here.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Form
                        {...WaitlistJoinController.store.form()}
                        className="space-y-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="waitlist-name">Name</Label>
                                    <Input
                                        id="waitlist-name"
                                        name="name"
                                        required
                                        maxLength={255}
                                        autoComplete="name"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid grid-cols-2 items-start gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="waitlist-phone">
                                            Phone
                                        </Label>
                                        <Input
                                            id="waitlist-phone"
                                            name="phone"
                                            type="tel"
                                            required
                                            maxLength={30}
                                            autoComplete="tel"
                                        />
                                        <InputError message={errors.phone} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="waitlist-party-size">
                                            How many people
                                        </Label>
                                        <Input
                                            id="waitlist-party-size"
                                            name="party_size"
                                            type="number"
                                            inputMode="numeric"
                                            min={1}
                                            max={session.maxPlayersPerLane}
                                            defaultValue={2}
                                            required
                                        />
                                        <InputError
                                            message={errors.party_size}
                                        />
                                    </div>
                                </div>
                                <p className="-mt-3 text-xs text-muted-foreground">
                                    Up to {session.maxPlayersPerLane} people
                                    share a lane. A bigger group joins once for
                                    each lane it needs.
                                </p>

                                <fieldset className="grid gap-2">
                                    <legend className="mb-2 text-sm leading-none font-medium">
                                        How long do you want to play?
                                    </legend>
                                    <SessionLengthPicker session={session} />
                                    <InputError message={errors.minutes} />
                                </fieldset>

                                <div className="rounded-lg border p-3 text-sm">
                                    <p className="font-medium">
                                        A {deposit} deposit is needed to join
                                        online
                                    </p>
                                    <ul className="mt-1 list-disc space-y-1 pl-5 text-muted-foreground">
                                        <li>
                                            It comes off your bill when you
                                            play.
                                        </li>
                                        <li>
                                            Leave the line before you are called
                                            and it is returned to you.
                                        </li>
                                        <li>
                                            If you are called and do not check
                                            in within {checkInMinutes} minutes,
                                            it is kept.
                                        </li>
                                    </ul>
                                </div>

                                <Button
                                    type="submit"
                                    className="w-full"
                                    disabled={processing}
                                >
                                    Continue to pay {deposit}
                                </Button>
                            </>
                        )}
                    </Form>
                </CardContent>
            </Card>
        </>
    );
}
