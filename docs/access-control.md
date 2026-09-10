# Access-control review

Reviewed for Slice 10 on 2026-09-10.

Vhod uses authenticated access globally and capability-specific checks inside management controllers and domain policies. `ROLE_ADMIN` inherits manager, cashier and controller capabilities through `security.yaml`, but sensitive workflows still apply their explicit policy checks.

## Management route matrix

| Area | Resident | Cashier | Controller | Manager | Admin |
| --- | --- | --- | --- | --- | --- |
| Condominium book management | no | no | read | read + review | read + review |
| Finance | no | read + operational write | read | read + operational write + budget | all |
| Community moderation | no | no | no | yes | yes |
| Maintenance management | no | no | no | yes | yes |
| Official document management | no | no | no | yes | yes |
| Official announcement management | no | no | no | yes | yes |
| General Assembly management | no | no | yes | yes | yes |
| Compliance page | no | no | read + annual audit | all | all |
| Audit review | no | no | no | no | read-only |

The matrix is enforced by `tests/Security/ManagementAccessMatrixTest.php` against real HTTP routes. Granular policy tests remain the source of truth for operations inside each area.

## Intentional asymmetries

- `ROLE_CONTROLLER` may manage General Assembly workflows. This is deliberate and follows the Phase 9 governance design; it must not be removed merely to make roles symmetrical.
- `ROLE_CONTROLLER` can read finance but cannot post operational finance entries through `FinanceManagementController`.
- `ROLE_CASHIER` can perform operational finance writes but cannot create budgets.
- Compliance annual cash/control audit completion may be recorded by controller, manager or admin; registry identity, mandate and monthly-report completion remain manager/admin operations.
- Governance documents are visible to controller/manager/admin, while management-only documents remain manager/admin.
- Audit review is deliberately admin-only because it contains actor identifiers and cross-domain operational metadata.

## Security rules

- Authentication is required for every application route except login and development assets.
- Every state-changing HTTP action is expected to use POST plus CSRF protection.
- Twig navigation visibility is convenience only; controller/domain authorization is the security boundary.
- Resident access never grants access to another household's private book or debt data.
- Role checks must not be inferred from ownership relations; `OWNER`, `USER` and `OCCUPANT` are domain relations, not Symfony roles.

Any future management area or role change must update both this document and the functional matrix test.
