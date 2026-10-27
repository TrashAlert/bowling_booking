import { Form, Head, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';

type Props = {
    status?: string;
};

/**
 * On the public demo, one button per demo login, so a visitor is in with a
 * single tap. Each sends the login form with that login filled in.
 */
function DemoLogins() {
    const { demo } = usePage().props;

    if (demo === null) {
        return null;
    }

    return (
        <section
            aria-label="Demo logins"
            className="space-y-3 rounded-lg border p-4"
        >
            <p className="text-sm text-muted-foreground">
                Try the staff side of the demo, as either of these:
            </p>
            {demo.logins.map((login) => (
                <Form key={login.email} {...store.form()}>
                    {({ processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="email"
                                value={login.email}
                            />
                            <input
                                type="hidden"
                                name="password"
                                value={login.password}
                            />
                            <Button
                                type="submit"
                                variant="outline"
                                className="w-full"
                                disabled={processing}
                            >
                                Log in as {login.role.toLowerCase()}
                            </Button>
                        </>
                    )}
                </Form>
            ))}
            <p className="text-xs text-muted-foreground">
                Or type them in below:{' '}
                {demo.logins
                    .map((login) => `${login.email} / ${login.password}`)
                    .join(', ')}
                .
            </p>
        </section>
    );
}

export default function Login({ status }: Props) {
    return (
        <>
            <Head title="Log in" />

            <DemoLogins />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder="email@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder="Password"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />
                                <Label htmlFor="remember">Remember me</Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                Log in
                            </Button>
                        </div>
                    </>
                )}
            </Form>

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}
        </>
    );
}

Login.layout = {
    title: 'Log in to your account',
    description: 'Enter your email and password below to log in',
};
