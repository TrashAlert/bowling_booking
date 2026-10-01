# bowling_booking

## Staff users

Public registration is turned off. Staff accounts are created from the command line on the server:

```bash
php artisan staff:create "Aina Counter" aina@example.com
```

You are asked for the password. Add `--admin` to create an admin instead of counter staff:

```bash
php artisan staff:create "Olivia Owner" olivia@example.com --admin
```

For scripted setups, pass the password with `--password=...` instead of typing it.

Only users with the `staff` or `admin` role can open pages under `/staff`. Admins can do everything staff can.
