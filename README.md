# Garner CMS

Private development scaffold.

## Requirements

- PHP 8.3+
- Composer
- Node.js and npm

## Install

```sh
composer install
composer studio:install
```

## Development

Backend:

```sh
composer start
```

Default backend URL:

```sh
http://localhost:8030
```

Debug mode defaults to `true` on `localhost` and can be overridden with `APP_DEBUG`.

Studio:

```sh
composer studio:dev
```

Open Studio at:

```sh
http://localhost:5173/studio
```

Built Studio via the PHP backend:

```sh
composer studio:build
http://localhost:8030/studio
```

Health check:

```sh
http://localhost:8030/api/meta/health
```

## Other Commands

Run tests:

```sh
composer test
```

Run the full PHP check suite:

```sh
composer check
```

Lint PHP:

```sh
composer lint
```

Analyze PHP:

```sh
composer analyze
```

Format PHP:

```sh
composer format
```

Build Studio:

```sh
composer studio:build
```

Preview Studio build:

```sh
composer studio:preview
```

Check platform requirements:

```sh
composer platform-check
```

Format repo files:

```sh
npm run format
```

Lint frontend/root JS and Svelte files:

```sh
npm run lint
```
