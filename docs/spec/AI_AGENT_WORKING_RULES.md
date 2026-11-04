# AI Agent Working Rules

Use this file together with `README_MASTER.md` and the current phase file.

## Before coding
1. Inspect the repository.
2. Identify completed work and existing conventions.
3. Read the master spec and current phase.
4. List implementation steps and affected modules.
5. Identify schema changes and integration dependencies.
6. Ask a question only if a missing business decision blocks safe implementation. Otherwise use the documented defaults and record assumptions.

## During coding
- Implement real persistence and business logic.
- Use Laravel for backend and admin dashboard.
- Use Flutter for Android/iOS customer applications.
- Do not build the admin dashboard in React or Next.js.
- Do not replace PostgreSQL with a different database without approval.
- Do not invent SADAD API capabilities. Inspect merchant documentation and report unsupported requirements.
- Do not invent delivery provider APIs. Use adapter interfaces until provider is selected.
- Do not use mock success responses in production code.
- Do not put business-critical rules only in Flutter.
- Do not use hard-coded branch IDs, prices, stock or permissions.
- Do not commit credentials or private keys.
- Do not delete or rewrite historical financial/inventory/audit records.
- Use transactions and idempotency for critical workflows.
- Add tests with every business rule.

## Before declaring a task complete
- Run backend tests.
- Run Flutter tests if Flutter code changed.
- Run formatting and static analysis.
- Verify migrations on a clean database.
- Verify API documentation.
- Verify branch authorization.
- Verify Arabic/English where UI changed.
- Report exact tests and results.
- List remaining limitations honestly.

## Completion report format
### Implemented
- ...

### Database changes
- ...

### APIs/UI
- ...

### Tests executed
- Command:
- Result:

### Security and authorization checks
- ...

### Known limitations / decisions needed
- ...

### Ready for next phase
- Yes/No, with reason.
