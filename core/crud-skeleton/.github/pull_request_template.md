## Summary

<!-- Briefly describe what this PR does. -->

## Related Issues

<!-- Link related issues: Closes #123 -->

## Type of Change

- [ ] Bug fix
- [ ] New feature
- [ ] Documentation
- [ ] Test coverage improvement
- [ ] Refactor / cleanup
- [ ] CI / build / tooling
- [ ] i18n / translation

## Verification

<!-- How did you verify this change? Commands, screenshots, manual testing steps. -->

- [ ] `vendor/bin/phpunit` — all tests pass
- [ ] Coverage does not drop below 90%
- [ ] `composer phpstan` — Level 8 check passes
- [ ] `composer rector:types:check` — no PHPDoc type changes required
- [ ] Manual smoke test performed (describe below)

## Checklist

- [ ] Branch is up-to-date with `main`
- [ ] Commits follow [Conventional Commits](https://www.conventionalcommits.org/)
- [ ] New features include tests
- [ ] API changes documented with `#[OA\*]` attributes
- [ ] Behavior changes reflected in `docs/ai/context.md` where appropriate
- [ ] i18n translation keys added for new user-facing messages (en, zh, zh_Hant, ja)
