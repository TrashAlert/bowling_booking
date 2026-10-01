import { Head, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import { RemoveUserDialog } from '@/components/remove-user-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { UserFormDialog } from '@/components/user-form-dialog';
import { index } from '@/routes/users';
import type { ManagedUser } from '@/types';

const roleLabels = {
    admin: 'Admin',
    staff: 'Staff',
};

export default function Users({ users }: { users: ManagedUser[] }) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="User settings" />

            <h1 className="sr-only">User settings</h1>

            <div className="space-y-6">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        variant="small"
                        title="Users"
                        description="Everyone who can log in to the staff area"
                    />
                    <UserFormDialog>
                        <Button>Add user</Button>
                    </UserFormDialog>
                </div>

                <ul className="divide-y rounded-lg border">
                    {users.map((user) => {
                        const isSelf = user.id === auth.user.id;

                        return (
                            <li
                                key={user.id}
                                className="flex items-center gap-3 p-3"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="flex items-center gap-2 font-medium">
                                        <span className="truncate">
                                            {user.name}
                                        </span>
                                        {isSelf && (
                                            <span className="shrink-0 text-xs font-normal text-muted-foreground">
                                                You
                                            </span>
                                        )}
                                    </p>
                                    <p className="truncate text-sm text-muted-foreground">
                                        {user.email}
                                    </p>
                                </div>

                                <Badge
                                    variant={
                                        user.role === 'admin'
                                            ? 'default'
                                            : 'secondary'
                                    }
                                >
                                    {user.role
                                        ? roleLabels[user.role]
                                        : 'No access'}
                                </Badge>

                                <div className="flex shrink-0 gap-1">
                                    <UserFormDialog user={user} isSelf={isSelf}>
                                        <Button variant="outline" size="sm">
                                            Edit
                                        </Button>
                                    </UserFormDialog>
                                    {!isSelf && (
                                        <RemoveUserDialog user={user} />
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            </div>
        </>
    );
}

Users.layout = {
    breadcrumbs: [
        {
            title: 'User settings',
            href: index(),
        },
    ],
};
