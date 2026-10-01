import { Form, Head } from '@inertiajs/react';
import LaneController from '@/actions/App/Http/Controllers/Settings/LaneController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/lanes';

export default function Lanes({
    laneCount,
    maxLanes,
}: {
    laneCount: number;
    maxLanes: number;
}) {
    return (
        <>
            <Head title="Lane settings" />

            <h1 className="sr-only">Lane settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Lanes"
                    description="Set how many lanes the venue has"
                />

                <Form
                    {...LaneController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="count">Number of lanes</Label>

                                <Input
                                    id="count"
                                    name="count"
                                    type="number"
                                    inputMode="numeric"
                                    min={1}
                                    max={maxLanes}
                                    defaultValue={laneCount}
                                    required
                                    className="mt-1 block w-32"
                                />

                                <p className="text-sm text-muted-foreground">
                                    Lanes are numbered 1 to this number. A lower
                                    number removes the highest-numbered lanes; a
                                    lane that is in use or has bookings coming
                                    up can't be removed.
                                </p>

                                <InputError
                                    className="mt-2"
                                    message={errors.count}
                                />
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

Lanes.layout = {
    breadcrumbs: [
        {
            title: 'Lane settings',
            href: edit(),
        },
    ],
};
