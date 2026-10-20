# KeyPro Frontend Guidelines

- Use Laravel/PHP and Blade for server-side rendering where appropriate; browser-facing UI and interactions must use semantic HTML, CSS, and modular vanilla JavaScript.
- Do not add React, JSX/TSX, TypeScript, or another client-side frontend framework. Keep existing Laravel/PHP domain logic and API contracts unchanged unless explicitly requested.
- Preserve RTL, accessibility, responsive behavior, and existing business behavior when migrating pages.
- Keep JavaScript page styles scoped to the relevant frontend shell or page.
- Treat LockAtlas (https://lockatlas.com/) as a high-level visual reference across the KeyPro product when redesign is requested. Build an original KeyPro interface; do not reproduce proprietary assets, copy, or distinctive layouts exactly.
- Run `npm run lint`, `npm test`, and `npm run build` for frontend changes; keep tests focused on observable behavior.
