export type UserRole = 'staff' | 'admin';

export type User = {
    id: number;
    name: string;
    email: string;
    role: UserRole | null;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

// A user as listed on the admin's Users settings page.
export type ManagedUser = {
    id: number;
    name: string;
    email: string;
    role: UserRole | null;
};
