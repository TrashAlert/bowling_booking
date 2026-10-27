import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import OnlineWaitlistController from '@/actions/App/Http/Controllers/Settings/OnlineWaitlistController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/online-waitlist';

/**
 * Where an admin opens or closes joining the waitlist online, for a private
 * event, a busy night or anything else, and writes what customers are told
 * meanwhile. Walk-ins at the counter are not affected.
 */
export default function OnlineWaitlist({
    onlineOpen,
    closedMessage,
    maxMessageLength,
    finishByClosing,
    maxWaitMinutes,
    maxWaitLimits,
}: {
    onlineOpen: boolean;
    // What customers are told while it is closed; null for the plain words.
    closedMessage: string | null;
    maxMessageLength: number;
    // Whether a group joining online must finish by closing time.
    finishByClosing: boolean;
    // The longest wait at which groups can still join online; null for none.
    maxWaitMinutes: number | null;
    maxWaitLimits: { min: number; max: number };
}) {
    const [open, setOpen] = useState(onlineOpen);
    const [byClosing, setByClosing] = useState(finishByClosing);

    return (
        <>
            <Head title="Online waitlist settings" />

            <h1 className="sr-only">Online waitlist settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Online waitlist"
                    description="Choose whether customers can join the waitlist from their phone, and when"
                />

                <Form
                    {...OnlineWaitlistController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <input
                                    type="hidden"
                                    name="online_open"
                                    value={open ? '1' : '0'}
                                />
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="online-open"
                                        checked={open}
                                        onCheckedChange={(checked) =>
                                            setOpen(checked === true)
                                        }
                                    />
                                    <Label htmlFor="online-open">
                                        Let customers join the waitlist online
                                    </Label>
                                </div>

                                <p className="text-sm text-muted-foreground">
                                    {open
                                        ? 'Customers can add their group to the line from the website, during opening hours.'
                                        : 'The waitlist card is taken off the front page, so only the reservation card is left. Groups already in the line stay in it and are still called, and staff can still add walk-ins.'}
                                </p>

                                <InputError message={errors.online_open} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="closed-message">
                                    What customers are told while it is closed
                                </Label>
                                <Input
                                    id="closed-message"
                                    name="closed_message"
                                    maxLength={maxMessageLength}
                                    defaultValue={closedMessage ?? ''}
                                    placeholder="We are not taking groups online right now. Please ask our staff at the counter."
                                />
                                <p className="text-sm text-muted-foreground">
                                    Optional, up to {maxMessageLength}{' '}
                                    characters, for example "Closed tonight for
                                    a private event." Shown to anyone who opens
                                    the join page from a saved link or a shared
                                    address. Leave it empty for the words shown
                                    in grey.
                                </p>
                                <InputError message={errors.closed_message} />
                            </div>

                            <div className="grid gap-2">
                                <input
                                    type="hidden"
                                    name="finish_by_closing"
                                    value={byClosing ? '1' : '0'}
                                />
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="finish-by-closing"
                                        checked={byClosing}
                                        onCheckedChange={(checked) =>
                                            setByClosing(checked === true)
                                        }
                                    />
                                    <Label htmlFor="finish-by-closing">
                                        Only take groups that can finish by
                                        closing time
                                    </Label>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {byClosing
                                        ? 'A group can only join online if its session, starting after the expected wait, ends by closing time. Late in the evening only shorter sessions can join. Groups already in the line are still called, even after closing.'
                                        : 'Groups can join online until closing time for any length, and may play past it.'}
                                </p>
                                <InputError
                                    message={errors.finish_by_closing}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="max-wait">
                                    Stop online joins when the wait is over
                                </Label>
                                <div className="flex items-center gap-2">
                                    <Input
                                        id="max-wait"
                                        name="max_wait_minutes"
                                        type="number"
                                        inputMode="numeric"
                                        min={maxWaitLimits.min}
                                        max={maxWaitLimits.max}
                                        defaultValue={maxWaitMinutes ?? ''}
                                        placeholder="No limit"
                                        className="w-28"
                                    />
                                    <span className="text-sm text-muted-foreground">
                                        minutes
                                    </span>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    Optional. While the wait for the session a
                                    group picks is longer, it is asked to come
                                    to the counter instead. Leave it empty for
                                    no limit. Walk-ins added at the counter are
                                    not limited.
                                </p>
                                <InputError message={errors.max_wait_minutes} />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button disabled={processing}>Save</Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

OnlineWaitlist.layout = {
    breadcrumbs: [
        {
            title: 'Online waitlist settings',
            href: edit(),
        },
    ],
};
