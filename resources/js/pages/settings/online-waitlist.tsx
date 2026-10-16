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
}: {
    onlineOpen: boolean;
    // What customers are told while it is closed; null for the plain words.
    closedMessage: string | null;
    maxMessageLength: number;
}) {
    const [open, setOpen] = useState(onlineOpen);

    return (
        <>
            <Head title="Online waitlist settings" />

            <h1 className="sr-only">Online waitlist settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Online waitlist"
                    description="Choose whether customers can join the waitlist from their phone"
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
                                        : 'The website tells customers to come to the counter instead. Groups already in the line stay in it and are still called, and staff can still add walk-ins.'}
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
                                    a private event." Leave it empty for the
                                    words shown in grey.
                                </p>
                                <InputError message={errors.closed_message} />
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
