import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import DashboardPartsController from '@/actions/App/Http/Controllers/Settings/DashboardPartsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/dashboard-parts';
import type { DashboardPart, DashboardParts } from '@/types';

// The parts of the dashboard, in the order it shows them, under the heading
// each sits under there.
const groups: {
    title: string;
    parts: { part: DashboardPart; label: string; description: string }[];
}[] = [
    {
        title: 'Now',
        parts: [
            {
                part: 'rightNow',
                label: 'Right now',
                description:
                    'Lanes free, in play and closed, and the groups in the line.',
            },
            {
                part: 'attention',
                label: 'Needs attention',
                description:
                    'Late reservations, reservations on a closed lane, requests waiting and lanes out of order.',
            },
        ],
    },
    {
        title: 'How the venue is doing',
        parts: [
            {
                part: 'totals',
                label: 'Numbers',
                description:
                    'Sessions played, people bowling, lane hours, no-shows, the waitlist and the average wait.',
            },
            {
                part: 'sessionsByDay',
                label: 'Sessions by day',
                description:
                    'Walk-ins and reservations for each day, or each week or month over a long span.',
            },
            {
                part: 'sessionsByHour',
                label: 'Sessions by the hour they started',
                description: 'Which hours of the day are busiest.',
            },
            {
                part: 'busiestTimes',
                label: 'Busiest times',
                description: 'Sessions by day of the week and hour.',
            },
            {
                part: 'closedTime',
                label: 'Time out of order',
                description:
                    'How long each lane was closed for repair, re-oiling or maintenance.',
            },
        ],
    },
];

/**
 * Where an admin chooses which parts of the dashboard everyone sees. A part
 * turned off is hidden for all staff, admins included, and is not worked
 * out. Nothing is lost by turning one off.
 */
export default function DashboardPartsSettings({
    parts,
}: {
    parts: DashboardParts;
}) {
    const [shown, setShown] = useState(parts);

    return (
        <>
            <Head title="Dashboard settings" />

            <h1 className="sr-only">Dashboard settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Dashboard"
                    description="Choose what everyone sees on the dashboard"
                />

                <Form
                    {...DashboardPartsController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            {groups.map((group) => (
                                <fieldset
                                    key={group.title}
                                    className="space-y-4"
                                >
                                    <legend className="mb-3 text-sm font-medium">
                                        {group.title}
                                    </legend>

                                    {group.parts.map(
                                        ({ part, label, description }) => (
                                            <div
                                                key={part}
                                                className="grid gap-1"
                                            >
                                                <input
                                                    type="hidden"
                                                    name={`parts[${part}]`}
                                                    value={
                                                        shown[part] ? '1' : '0'
                                                    }
                                                />
                                                <div className="flex items-center gap-2">
                                                    <Checkbox
                                                        id={`part-${part}`}
                                                        checked={shown[part]}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            setShown(
                                                                (current) => ({
                                                                    ...current,
                                                                    [part]:
                                                                        checked ===
                                                                        true,
                                                                }),
                                                            )
                                                        }
                                                    />
                                                    <Label
                                                        htmlFor={`part-${part}`}
                                                    >
                                                        {label}
                                                    </Label>
                                                </div>
                                                <p className="pl-6 text-sm text-muted-foreground">
                                                    {description}
                                                </p>
                                                <InputError
                                                    message={
                                                        errors[`parts.${part}`]
                                                    }
                                                />
                                            </div>
                                        ),
                                    )}
                                </fieldset>
                            ))}

                            <p className="text-sm text-muted-foreground">
                                A part turned off is hidden for everyone, admins
                                included. Nothing is lost: turn it on again to
                                see it.
                            </p>

                            <InputError message={errors.parts} />

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

DashboardPartsSettings.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard settings',
            href: edit(),
        },
    ],
};
