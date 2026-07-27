import { describe, expect, it } from 'vitest';
import { isNonEmptyString } from '../src/index';

describe('isNonEmptyString', () => {
    it('returns true for a defined, non-empty string input', () => {
        expect(isNonEmptyString('hubspot')).toBe(true);
    });

    it('returns false for an empty string', () => {
        expect(isNonEmptyString('')).toBe(false);
    });

    it('returns false for a non-string input', () => {
        expect(isNonEmptyString(42)).toBe(false);
    });
});
