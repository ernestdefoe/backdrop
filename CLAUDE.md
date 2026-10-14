# Backdrop: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.**
- Commit subjects are conventional (`feat:`, `fix:`, `docs:`, `chore:`). The release drafter reads the bump from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. A cloud session cannot push tags or edit releases (403 by session type): push the commits, confirm the draft release exists, and say so.

## Design rules that exist for a reason

- Never patch `app` or `app.mount`. An own `mount` on the app instance hides every later prototype hook; that is the bug that hid Modern Footer under Cover Studio. If something must run after every initializer, use `app.beforeMount(cb)`.
- No `override()` of core components, no classes on `<body>`, no CSS outside `.Backdrop*` / `.UserCard.Backdrop`.
- Card positioning goes through `:where()` so core's absolute hover cards and any theme win. A plain `.UserCard.Backdrop { position: relative }` broke every hover card.
- Cover data stays in columns on `users`. A companion table needs eager loading on every user query, which is how Cover Studio ended up with a global scope on `User`.
- Migrations run before the extension's extenders boot, so the enable migration must not touch Backdrop's disk. The import is URL-only there; `backdrop:import` copies files.
