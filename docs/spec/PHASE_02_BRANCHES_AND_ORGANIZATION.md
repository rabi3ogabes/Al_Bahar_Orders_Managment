# Phase 02 — Company, Branches, Employees and Operating Configuration

## Objective
Implement the single-company, multi-branch organization model and branch-scoped staff operations.

## Business rules
- One company owns all branches and products.
- This is not a marketplace.
- A staff member may be assigned to one or more branches.
- Branch inventory and branch operations are isolated.
- Company-wide administrators may access all branches.
- Branch-specific settings must not silently affect other branches.

## Admin dashboard requirements
Build Laravel screens for:
- Branch list, search, filters and status.
- Create/edit branch.
- Branch details.
- Branch employees.
- Branch operating hours.
- Branch holidays and temporary closures.
- Branch pickup settings.
- Branch delivery settings.
- Branch contact and location information.
- Branch reports placeholder/navigation.
- Branch-specific settings.

Branch fields:
- code (unique)
- name_ar
- name_en
- description_ar/en
- phone
- email
- address text
- zone/area
- latitude
- longitude
- opening status
- pickup_enabled
- delivery_enabled
- status

Operating configuration:
- Weekly opening/closing times.
- Break periods if needed.
- Holidays.
- Temporary closure date ranges.
- Timezone defaults to Asia/Qatar.

## Branch discovery for customers
Create API endpoints to:
- List active branches.
- Get branch details.
- Search branches.
- Find nearby branches using customer-provided coordinates.
- Return opening status based on configured hours.
- Return pickup/delivery capabilities.

Do not request or use device location without customer permission. If location is unavailable, allow manual branch selection.

## Delivery zones
Create:
- delivery_zones
- branch_delivery_zones

A zone may be served by one or multiple branches according to admin configuration. Configure delivery fee, free-delivery threshold, minimum order amount, maximum order amount, active state, and service restrictions. Avoid assuming Qatar zone data; allow admins to configure and import it.

## Employees
- Assign employees to branches.
- Allow multi-branch assignments.
- Set job title and active status.
- Assign roles and permissions.
- Support employee activation/deactivation.
- Record who changed assignments.
- Prevent branch managers from assigning company-wide privileges.

## Database tables
- branches
- branch_employees
- branch_hours
- branch_holidays
- branch_settings
- delivery_zones
- branch_delivery_zones
- delivery_slots (initial structure/configuration)
- user_roles with branch scope

## API endpoints
- `GET /api/v1/branches`
- `GET /api/v1/branches/{id}`
- `GET /api/v1/branches/nearby`
- `GET /api/v1/branches/{id}/hours`
- Admin CRUD endpoints under `/api/v1/admin/branches`
- Branch employee and operating configuration endpoints.

## Security
Every branch-specific read/write must use server-derived authorization scope. A branch manager cannot add themselves to another branch or grant permissions beyond their authority.

## Tests
- Create/edit/deactivate branch.
- Branch hours and holiday calculations.
- Nearby branch search.
- Employee assigned to multiple branches.
- Branch manager cannot access another branch.
- Admin can access all branches.
- Delivery zone configuration and branch eligibility.

## Acceptance criteria
- Admin can manage branches without code changes.
- Staff can be assigned to authorized branches.
- Branch discovery API works.
- Working hours and holidays are respected.
- Branch access restrictions are tested at API and query levels.
