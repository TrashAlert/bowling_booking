import { Form, Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import DepositController from '@/actions/App/Http/Controllers/Settings/DepositController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/deposit';

/**
 * Where an admin chooses whether customers pay a deposit to join the
 * waitlist online, and how much. Walk-ins added by staff at the counter
 * never pay one.
 */
export default function Deposit({
    depositRequired,
    depositCents,
    limits,
}: {
    depositRequired: boolean;
    // What the deposit is while it is on.
    depositCents: number;
    // The smallest and largest amount that can be set, in whole units.
    limits: { minAmount: number; maxAmount: number };
}) {
    const { currencySymbol } = usePage().props;
    const [required, setRequired] = useState(depositRequired);

    return (
        <>
            <Head title="Deposit settings" />

            <h1 className="sr-only">Deposit settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Waitlist deposit"
                    description="Choose whether customers pay a deposit to join the waitlist online"
                />

                <Form
                    {...DepositController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <input
                                    type="hidden"
                                    name="deposit_required"
                                    value={required ? '1' : '0'}
                                />
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="deposit-required"
                                        checked={required}
                                        onCheckedChange={(checked) =>
                                            setRequired(checked === true)
                                        }
                                    />
                                    <Label htmlFor="deposit-required">
                                        Ask for a deposit when no lane is free
                                    </Label>
                                </div>

                                <p className="text-sm text-muted-foreground">
                                    {required
                                        ? 'A customer who would have to wait pays the deposit before joining the line. While a lane is free, joining stays free.'
                                        : 'Every customer joins the line for free, whether or not a lane is free. Nothing stops someone joining and not turning up.'}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    Walk-ins added at the counter never pay a
                                    deposit. Deposits already paid are not
                                    changed by this.
                                </p>

                                <InputError
                                    className="mt-2"
                                    message={errors.deposit_required}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="deposit-amount">
                                    Deposit amount ({currencySymbol})
                                </Label>

                                <Input
                                    id="deposit-amount"
                                    name="deposit_amount"
                                    type="number"
                                    inputMode="decimal"
                                    step="0.01"
                                    min={limits.minAmount}
                                    max={limits.maxAmount}
                                    defaultValue={(depositCents / 100).toFixed(
                                        2,
                                    )}
                                    required
                                    className="mt-1 block w-32"
                                />

                                <p className="text-sm text-muted-foreground">
                                    What a customer pays from now on. Anyone
                                    already sent to the pay page keeps the
                                    amount they were shown.
                                    {!required &&
                                        ' It is not asked for while the deposit is turned off.'}
                                </p>

                                <InputError
                                    className="mt-2"
                                    message={errors.deposit_amount}
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

Deposit.layout = {
    breadcrumbs: [
        {
            title: 'Deposit settings',
            href: edit(),
        },
    ],
};
