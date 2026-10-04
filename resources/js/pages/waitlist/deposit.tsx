import { Form, Head, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatMoney, formatSessionLength } from '@/lib/format';
import type { PendingDeposit } from '@/types';

/**
 * Where a party pays its deposit. It is not in line until this is paid.
 *
 * The payment is a stand-in for now: the button takes no money. The form posts
 * back to this page's own address, which carries the party's secret token.
 */
export default function WaitlistDeposit({
    deposit,
}: {
    deposit: PendingDeposit;
}) {
    const { props, url } = usePage();
    const amount = formatMoney(deposit.amountCents, props.currencySymbol);

    const details = [
        ['Name', deposit.name],
        [
            'Group',
            `${deposit.partySize} ${deposit.partySize === 1 ? 'person' : 'people'}`,
        ],
        ['Playing for', formatSessionLength(deposit.minutes)],
        ['Deposit', amount],
    ];

    return (
        <>
            <Head title="Pay your deposit" />

            <p className="rounded-lg border border-dashed p-3 text-sm text-muted-foreground">
                Online payment is not connected yet. The button below is a
                stand-in: no money is taken, and it puts you in line as if you
                had paid.
            </p>

            <Card>
                <CardHeader>
                    <CardTitle className="text-xl">Pay your deposit</CardTitle>
                    <CardDescription>
                        You are not in line yet. Your place is taken the moment
                        the deposit is paid.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-5">
                    <dl className="divide-y rounded-lg border text-sm">
                        {details.map(([label, value]) => (
                            <div
                                key={label}
                                className="flex justify-between gap-4 p-3"
                            >
                                <dt className="text-muted-foreground">
                                    {label}
                                </dt>
                                <dd className="text-right font-medium tabular-nums">
                                    {value}
                                </dd>
                            </div>
                        ))}
                    </dl>

                    <Form
                        action={url.split('?')[0]}
                        method="post"
                        className="space-y-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <InputError message={errors.closed} />
                                <Button
                                    type="submit"
                                    className="w-full"
                                    disabled={processing}
                                >
                                    Pay {amount}
                                </Button>
                            </>
                        )}
                    </Form>
                </CardContent>
            </Card>
        </>
    );
}
