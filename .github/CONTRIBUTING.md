# Contributing

Pull requests are welcome.

1. Fork the repository and branch from `main`.
2. Make your change, with tests for it.
3. Run the checks locally before opening the pull request:

   ```sh
   composer install
   vendor/bin/pint
   vendor/bin/phpstan analyse
   vendor/bin/pest
   ```

4. Open the pull request against `main`.

Opening the pull request runs the full test suite across every supported PHP,
Laravel, Livewire and database combination. It needs to pass before it can be
merged; merging does not run it again.

If you change the dashboard's styles, rebuild the assets with `npm install &&
npm run build` and commit `dist/` with your change.

To try the dashboard locally against a demo application:

```sh
composer serve
```

and, in another terminal, fill every card with data:

```sh
php vendor/bin/testbench demo:traffic
```

For anything larger than a fix, open an issue first so we can agree on the
approach before you spend time on it.
