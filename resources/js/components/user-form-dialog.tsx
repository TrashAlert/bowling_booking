import { Form } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import UserController from '@/actions/App/Http/Controllers/Settings/UserController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
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
import type { ManagedUser, UserRole } from '@/types';

const roles: { value: UserRole; label: string; description: string }[] = [
    {
        value: 'staff',
        label: 'Staff',
        description: 'Works the counter: lane board and waitlist.',
    },
    {
        value: 'admin',
        label: 'Admin',
        description: 'Everything staff can do, plus lanes and users.',
    },
];

/**
 * Adds a user, or edits the given one. An admin editing their own account
 * can't change their role, so there is always an admin left.
 */
export function UserFormDialog({
    user,
    isSelf = false,
    children,
}: {
    user?: ManagedUser;
    isSelf?: boolean;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const idPrefix = user ? `user-${user.id}` : 'new-user';

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent>
                <DialogTitle>
                    {user ? `Edit ${user.name}` : 'Add a user'}
                </DialogTitle>
                <DialogDescription>
                    {user
                        ? 'Change their details or role, or set a new password.'
                        : 'They can log in straight away with the email and password you set.'}
                </DialogDescription>

                <Form
                    {...(user
                        ? UserController.update.form(user.id)
                        : UserController.store.form())}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    resetOnSuccess={!user}
                    resetOnError={['password']}
                    className="space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor={`${idPrefix}-name`}>Name</Label>
                                <Input
                                    id={`${idPrefix}-name`}
                                    name="name"
                                    defaultValue={user?.name}
                                    required
                                    autoComplete="off"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor={`${idPrefix}-email`}>
                                    Email address
                                </Label>
                                <Input
                                    id={`${idPrefix}-email`}
                                    name="email"
                                    type="email"
                                    defaultValue={user?.email}
                                    required
                                    autoComplete="off"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <fieldset className="grid gap-2">
                                <legend className="mb-2 text-sm leading-none font-medium">
                                    Role
                                </legend>
                                <div className="grid gap-2 sm:grid-cols-2">
                                    {roles.map((role) => (
                                        <label
                                            key={role.value}
                                            className="flex cursor-pointer flex-col rounded-lg border p-3 has-checked:border-primary has-checked:bg-accent has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50 has-disabled:cursor-not-allowed has-disabled:opacity-60"
                                        >
                                            <input
                                                type="radio"
                                                name="role"
                                                value={role.value}
                                                defaultChecked={
                                                    (user?.role ?? 'staff') ===
                                                    role.value
                                                }
                                                disabled={
                                                    isSelf &&
                                                    user?.role !== role.value
                                                }
                                                required
                                                className="sr-only"
                                            />
                                            <span className="font-medium">
                                                {role.label}
                                            </span>
                                            <span className="text-sm text-muted-foreground">
                                                {role.description}
                                            </span>
                                        </label>
                                    ))}
                                </div>
                                {isSelf && (
                                    <p className="text-sm text-muted-foreground">
                                        You can't change your own role.
                                    </p>
                                )}
                                <InputError message={errors.role} />
                            </fieldset>

                            <div className="grid gap-2">
                                <Label htmlFor={`${idPrefix}-password`}>
                                    {user ? 'New password' : 'Password'}
                                </Label>
                                <PasswordInput
                                    id={`${idPrefix}-password`}
                                    name="password"
                                    required={!user}
                                    autoComplete="new-password"
                                />
                                {user && (
                                    <p className="text-sm text-muted-foreground">
                                        Leave this empty to keep their current
                                        password.
                                    </p>
                                )}
                                <InputError message={errors.password} />
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button type="button" variant="secondary">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    {user ? 'Save changes' : 'Add user'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
