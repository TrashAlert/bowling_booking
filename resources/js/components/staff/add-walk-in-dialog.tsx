import { Form } from '@inertiajs/react';
import { useState } from 'react';
import WaitlistController from '@/actions/App/Http/Controllers/Staff/WaitlistController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMoney } from '@/lib/format';
import type { PackageOption } from '@/types';

export function AddWalkInDialog({ packages }: { packages: PackageOption[] }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>Add walk-in</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Add a walk-in party</DialogTitle>
                <DialogDescription>
                    They join the end of the line and are called when a lane is
                    free for their whole session.
                </DialogDescription>

                <Form
                    {...WaitlistController.store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    resetOnSuccess
                    className="space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="walk-in-name">Name</Label>
                                <Input
                                    id="walk-in-name"
                                    name="name"
                                    required
                                    autoFocus
                                    autoComplete="off"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid grid-cols-2 items-start gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="walk-in-phone">
                                        Phone (optional)
                                    </Label>
                                    <Input
                                        id="walk-in-phone"
                                        name="phone"
                                        type="tel"
                                        autoComplete="off"
                                    />
                                    <InputError message={errors.phone} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="walk-in-party-size">
                                        Party size
                                    </Label>
                                    <Input
                                        id="walk-in-party-size"
                                        name="party_size"
                                        type="number"
                                        inputMode="numeric"
                                        min={1}
                                        defaultValue={2}
                                        required
                                    />
                                    <InputError message={errors.party_size} />
                                </div>
                            </div>

                            <fieldset className="grid gap-2">
                                <legend className="mb-2 text-sm leading-none font-medium">
                                    Package
                                </legend>
                                <div className="grid gap-2 sm:grid-cols-2">
                                    {packages.map((option, index) => (
                                        <label
                                            key={option.id}
                                            className="flex cursor-pointer flex-col rounded-lg border p-3 has-checked:border-primary has-checked:bg-accent has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                                        >
                                            <input
                                                type="radio"
                                                name="package_id"
                                                value={option.id}
                                                defaultChecked={index === 0}
                                                required
                                                className="sr-only"
                                            />
                                            <span className="font-medium">
                                                {option.name}
                                            </span>
                                            <span className="text-sm text-muted-foreground">
                                                {formatMoney(option.priceCents)}{' '}
                                                per lane · up to{' '}
                                                {option.maxPlayers} per lane
                                            </span>
                                        </label>
                                    ))}
                                </div>
                                <InputError message={errors.package_id} />
                            </fieldset>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button type="button" variant="secondary">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    Add to line
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
