# Fayadhowr Web Panel

Internal admin application for Fayadhowr — Mobile App Management today, with Marketing and
Human Resources reserved as future departments (see `layouts/nav.config.ts`). A separate
React SPA, not a Laravel-rendered frontend: it talks to the existing Laravel Admin API
(`backend/`) over `/api/v1/admin/*` and shares no code with the Flutter Mobile App.

## Stack

React 18 · TypeScript · Vite · Tailwind CSS · React Router · TanStack Query · TanStack Table.

## Getting started

```bash
npm install
cp .env.example .env.local   # point VITE_API_BASE_URL at your local Laravel API
npm run dev
```

The backend must be running separately (`cd ../backend && php artisan serve`) with a
seeded `Admin` account to log in — the backend has no admin/super-admin seeder yet
(see the Web Panel Blueprint's blockers list), so one needs to be created manually
(e.g. via `php artisan tinker`) before the login screen can be used.

## Scripts

- `npm run dev` — start the dev server
- `npm run build` — type-check (`tsc -b`) then production build
- `npm run typecheck` — type-check only
- `npm run lint` — ESLint, zero warnings allowed

## Project structure

See `src/features/*` for one folder per department/module, `src/api/*` for the typed API
client (one module per backend resource — never call `fetch` directly from a component),
and `src/components/ui/*` for the shared design-system components. Full architecture
rationale lives in the Web Panel Blueprint audit document.
