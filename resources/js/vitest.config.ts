import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        include: ['tests/**/*.test.ts'],
        coverage: {
            provider: 'v8',
            // Scoped to this workspace's own src/ only. plan 06 adds a `site` workspace for the
            // documentation build; if this glob ever widened to include it, the 95% floor that
            // exists to guard the Phase 8 origin-validating listener would silently start
            // measuring documentation content instead -- see 01-03-PLAN.md's placement note.
            include: ['src/**/*.ts'],
            // Files with zero tests count against the number instead of being invisible to it.
            all: true,
            thresholds: {
                lines: 95,
                functions: 95,
                branches: 95,
                statements: 95,
            },
        },
    },
});
