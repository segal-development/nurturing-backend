# Skill Registry — nurturing-backend

**Generated**: 2026-05-12
**Project**: nurturing-backend (Laravel 12 PHP) + nurturing-front (React 19 TypeScript)

## Project Skills

No project-level skills detected.

## User Skills (Applicable)

Skills from `~/.config/opencode/skills/` and `~/.claude/skills/` that apply to this stack:

### Backend (Laravel PHP)

| Skill | Trigger | Path |
|-------|---------|------|
| pytest | Python tests (N/A for PHP) | — |
| django-drf | Django APIs (N/A for PHP) | — |

*Note: No PHP/Laravel-specific skills installed. Consider creating skills for Laravel testing, Pint linting, or Sanctum auth patterns.*

### Frontend (React TypeScript)

| Skill | Trigger | Path |
|-------|---------|------|
| react-19 | React components | `~/.claude/skills/react-19/SKILL.md` |
| typescript | TypeScript code | `~/.config/opencode/skills/typescript/SKILL.md` |
| tailwind-4 | Tailwind styling | `~/.config/opencode/skills/tailwind-4/SKILL.md` |
| zod-4 | Zod validation | `~/.config/opencode/skills/zod-4/SKILL.md` |
| zustand-5 | State management | `~/.config/opencode/skills/zustand-5/SKILL.md` |

### Testing

| Skill | Trigger | Path |
|-------|---------|------|
| playwright | E2E tests (not installed in project) | `~/.claude/skills/playwright/SKILL.md` |

### Workflow

| Skill | Trigger | Path |
|-------|---------|------|
| branch-pr | Creating PRs | `~/.config/opencode/skills/branch-pr/SKILL.md` |
| issue-creation | Creating issues | `~/.config/opencode/skills/issue-creation/SKILL.md` |
| pr-review | Reviewing PRs | `~/.config/opencode/skills/pr-review/SKILL.md` |
| work-unit-commits | Commit planning | `~/.config/opencode/skills/work-unit-commits/SKILL.md` |

## Compact Rules

### react-19
- React 19 with React Compiler — no useMemo/useCallback needed
- Use `use()` for promises, Server Components where applicable
- Prefer Server Actions for mutations
- `ref` is now a prop, not `forwardRef`

### typescript
- Strict mode always: `strict: true` in tsconfig
- Prefer `interface` for objects, `type` for unions/intersections
- Use `unknown` over `any`, narrow with type guards
- Avoid enums; use const objects with `as const`

### tailwind-4
- Use `cn()` for conditional classes (clsx + tailwind-merge)
- Theme via CSS variables in `@theme`
- No `var()` in className — Tailwind handles variables
- v4 uses `@import "tailwindcss"` not `@tailwind` directives

### zod-4
- Breaking: `z.object()` returns `ZodObject` with `.shape` access
- Use `z.input<>` and `z.output<>` for type inference
- `.transform()` changes output type
- Async validation with `.refine()` is now `.superRefine()`

### zustand-5
- No more `create()` — use `createStore()` with `useStore()`
- Or use `create()` from `zustand/react` for hooks
- Selectors are required for performance
- Middleware order matters: `devtools(persist(...))`

## Convention Files

No convention files detected (`AGENTS.md`, `CLAUDE.md`, `.cursorrules`).

## Recommendations

1. **Create Laravel skill**: Testing patterns with PHPUnit, RefreshDatabase, Sanctum auth mocking
2. **Create Pint skill**: Laravel Pint linting conventions and auto-fix patterns
3. **Add convention file**: `AGENTS.md` or `CLAUDE.md` with project-specific rules
4. **Consider E2E**: Install Playwright for critical flow testing (flow builder, auth)
