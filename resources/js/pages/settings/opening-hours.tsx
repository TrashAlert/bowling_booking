import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import OpeningHoursController from '@/actions/App/Http/Controllers/Settings/OpeningHoursController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/opening-hours';
import type { DayHours } from '@/types';

const dayNames = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
];

// What a day starts as on the form before any hours have been saved.
const DEFAULT_OPENS = '10:00';
const DEFAULT_CLOSES = '23:00';

type DayForm = {
    weekday: number;
    open: boolean;
    opens: string;
    closes: string;
};

/**
 * Where an admin sets when the venue is open, day by day. Customers can't
 * join the waitlist or reserve outside these hours.
 */
export default function OpeningHours({
    week,
    isSet,
    timezone,
}: {
    week: DayHours[];
    isSet: boolean;
    timezone: string;
}) {
    const form = useForm<{ days: DayForm[] }>({
        days: week.map((day) => ({
            weekday: day.weekday,
            open: !isSet || day.opens !== null,
            opens: day.opens ?? DEFAULT_OPENS,
            closes: day.closes ?? DEFAULT_CLOSES,
        })),
    });

    const change = (index: number, values: Partial<DayForm>) =>
        form.setData(
            'days',
            form.data.days.map((day, at) =>
                at === index ? { ...day, ...values } : day,
            ),
        );

    const errorFor = (index: number, field: 'opens' | 'closes') =>
        (form.errors as Record<string, string | undefined>)[
            `days.${index}.${field}`
        ];

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.submit(OpeningHoursController.update(), { preserveScroll: true });
    }

    return (
        <>
            <Head title="Opening hours" />

            <h1 className="sr-only">Opening hours</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Opening hours"
                    description="Customers can only join the waitlist or reserve while the venue is open"
                />

                {!isSet && (
                    <p className="rounded-lg border border-dashed p-3 text-sm text-muted-foreground">
                        No opening hours are set yet, so the venue counts as
                        always open. Check the times below and save them.
                    </p>
                )}

                <form onSubmit={submit} className="space-y-6">
                    <div className="divide-y rounded-lg border">
                        {form.data.days.map((day, index) => (
                            <div
                                key={day.weekday}
                                className="flex flex-wrap items-start gap-x-4 gap-y-2 p-3"
                            >
                                <div className="flex w-36 items-center gap-2 pt-2">
                                    <Checkbox
                                        id={`open-${day.weekday}`}
                                        checked={day.open}
                                        onCheckedChange={(checked) =>
                                            change(index, {
                                                open: checked === true,
                                            })
                                        }
                                    />
                                    <Label htmlFor={`open-${day.weekday}`}>
                                        {dayNames[day.weekday]}
                                    </Label>
                                </div>

                                {day.open ? (
                                    <div className="grid gap-1">
                                        <div className="flex items-center gap-2">
                                            <Input
                                                type="time"
                                                aria-label={`${dayNames[day.weekday]} opens`}
                                                value={day.opens}
                                                onChange={(event) =>
                                                    change(index, {
                                                        opens: event.target
                                                            .value,
                                                    })
                                                }
                                                className="w-32"
                                            />
                                            <span className="text-sm text-muted-foreground">
                                                to
                                            </span>
                                            <Input
                                                type="time"
                                                aria-label={`${dayNames[day.weekday]} closes`}
                                                value={day.closes}
                                                onChange={(event) =>
                                                    change(index, {
                                                        closes: event.target
                                                            .value,
                                                    })
                                                }
                                                className="w-32"
                                            />
                                        </div>
                                        {day.closes !== '' &&
                                            day.opens !== '' &&
                                            day.closes < day.opens && (
                                                <p className="text-xs text-muted-foreground">
                                                    Closes after midnight.
                                                </p>
                                            )}
                                        <InputError
                                            message={
                                                errorFor(index, 'opens') ??
                                                errorFor(index, 'closes')
                                            }
                                        />
                                    </div>
                                ) : (
                                    <p className="pt-2 text-sm text-muted-foreground">
                                        Closed
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>

                    <p className="text-sm text-muted-foreground">
                        Times are in the venue's time zone, {timezone}.
                    </p>

                    <Button disabled={form.processing}>Save</Button>
                </form>
            </div>
        </>
    );
}

OpeningHours.layout = {
    breadcrumbs: [
        {
            title: 'Opening hours',
            href: edit(),
        },
    ],
};
