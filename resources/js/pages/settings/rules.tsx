import { Form, Head } from '@inertiajs/react';
import RulesController from '@/actions/App/Http/Controllers/Settings/RulesController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/rules';

// The rules on this page, by their name on the server.
type Rule =
    | 'waitlist_checkin_minutes'
    | 'waitlist_max_per_device'
    | 'no_show_grace_minutes'
    | 'reservation_lead_minutes'
    | 'check_in_opens_minutes'
    | 'reservation_max_days_ahead'
    | 'max_players_per_lane'
    | 'max_session_minutes';

/**
 * One rule that is a whole number: its label, the box to type it in, the
 * unit after the box, and a line saying what it does.
 */
function NumberRule({
    rule,
    label,
    unit,
    help,
    value,
    limits,
    error,
}: {
    rule: Rule;
    label: string;
    unit: string;
    help: string;
    value: number;
    // "step" is set when the value has to be a multiple of something.
    limits: { min: number; max: number; step?: number };
    error?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={rule}>{label}</Label>

            <div className="flex items-center gap-2">
                <Input
                    id={rule}
                    name={rule}
                    type="number"
                    inputMode="numeric"
                    min={limits.min}
                    max={limits.max}
                    step={limits.step}
                    defaultValue={value}
                    required
                    className="w-24"
                />
                <span className="text-sm text-muted-foreground">{unit}</span>
            </div>

            <p className="text-sm text-muted-foreground">{help}</p>

            <InputError message={error} />
        </div>
    );
}

/**
 * Where an admin sets the rules bookings follow. A rule changed here
 * applies from then on.
 */
export default function Rules({
    rules,
    limits,
}: {
    rules: Record<Rule, number>;
    limits: Record<Rule, { min: number; max: number; step?: number }>;
}) {
    return (
        <>
            <Head title="Booking rules" />

            <h1 className="sr-only">Booking rules</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Booking rules"
                    description="The rules the waitlist and reservations follow"
                />

                <Form
                    {...RulesController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <NumberRule
                                rule="waitlist_checkin_minutes"
                                label="Time to check in after being called"
                                unit="minutes"
                                help="How long a called party has to reach the counter before it is skipped and its lane goes to the next party. Applies to parties called from now on."
                                value={rules.waitlist_checkin_minutes}
                                limits={limits.waitlist_checkin_minutes}
                                error={errors.waitlist_checkin_minutes}
                            />

                            <NumberRule
                                rule="waitlist_max_per_device"
                                label="Groups one phone can have in the online line"
                                unit="at the same time"
                                help="Stops one person filling the waitlist with made-up groups. A phone that has this many groups waiting or called can't add another until one has played or left. Walk-ins added at the counter are not counted."
                                value={rules.waitlist_max_per_device}
                                limits={limits.waitlist_max_per_device}
                                error={errors.waitlist_max_per_device}
                            />

                            <NumberRule
                                rule="no_show_grace_minutes"
                                label="How late a reservation can arrive"
                                unit="minutes after its start time"
                                help="A reservation that has not checked in by then becomes a no-show and its lanes are freed. Applies at once, to reservations already running late too."
                                value={rules.no_show_grace_minutes}
                                limits={limits.no_show_grace_minutes}
                                error={errors.no_show_grace_minutes}
                            />

                            <NumberRule
                                rule="reservation_lead_minutes"
                                label="How long a lane closes before a reservation"
                                unit="minutes before its start time"
                                help="From then on the lane takes nobody else, so it is sure to be empty on time. Use 0 to keep lanes in use right up to the start. Applies to reservations made or changed from now on; ones already made keep the closing time they have."
                                value={rules.reservation_lead_minutes}
                                limits={limits.reservation_lead_minutes}
                                error={errors.reservation_lead_minutes}
                            />

                            <NumberRule
                                rule="check_in_opens_minutes"
                                label="How early a reservation can be checked in"
                                unit="minutes before its start time"
                                help="Check-in is refused before then. Applies at once, to every reservation."
                                value={rules.check_in_opens_minutes}
                                limits={limits.check_in_opens_minutes}
                                error={errors.check_in_opens_minutes}
                            />

                            <NumberRule
                                rule="reservation_max_days_ahead"
                                label="How far ahead a reservation can be made"
                                unit="days"
                                help="The furthest date staff can reserve for and customers can ask for. Reservations already made beyond it stay as they are."
                                value={rules.reservation_max_days_ahead}
                                limits={limits.reservation_max_days_ahead}
                                error={errors.reservation_max_days_ahead}
                            />

                            <NumberRule
                                rule="max_players_per_lane"
                                label="People per lane"
                                unit="people"
                                help="The most people on one lane, and in one walk-in or online waitlist entry; a bigger group joins once per lane. For reservations it only decides how many lanes are suggested. If you lower it, a party already in line with more people than this will be given two lanes."
                                value={rules.max_players_per_lane}
                                limits={limits.max_players_per_lane}
                                error={errors.max_players_per_lane}
                            />

                            <NumberRule
                                rule="max_session_minutes"
                                label="Longest session"
                                unit="minutes"
                                help={`The longest session that can be booked or joined for, and the most time that can be added to a session in one go. In steps of ${limits.max_session_minutes.step ?? limits.max_session_minutes.min} minutes. Sessions already booked are not shortened.`}
                                value={rules.max_session_minutes}
                                limits={limits.max_session_minutes}
                                error={errors.max_session_minutes}
                            />

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

Rules.layout = {
    breadcrumbs: [
        {
            title: 'Booking rules',
            href: edit(),
        },
    ],
};
