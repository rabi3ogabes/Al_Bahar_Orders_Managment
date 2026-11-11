# Dispatch, drivers and branch staff

Three Android apps share one codebase: **customer**, **driver** and **branch staff** (own package id, name and icon each; see `mobile/README.md`).

## Who assigns a delivery

Set per branch in the admin panel: **Branches > (branch) > Services > Who assigns deliveries**.

| Mode | What happens when an order is marked *Ready* |
|---|---|
| **Automatic** | The system creates the delivery and gives it to the on-duty driver of that branch with the fewest open jobs (the one idle longest wins a tie). If nobody is on duty the delivery waits as *Waiting for a driver* and goes to the first driver who goes on duty. |
| **Manual** (default) | A branch employee picks the driver in the staff app (*Ready* tab > *Assign driver*). The server checks the driver belongs to that branch. |

Branch employees can also change the driver in automatic mode.

## People

- A branch can have **several employees** (roles such as *Order Manager* or *Branch Manager*) and **several drivers**.
- A driver can work for **several branches**: add the person to each branch in **Branches > Employees** with *delivery driver* ticked (role *Delivery Employee*).
- Drivers switch **On duty / Off duty** in the app; only on-duty drivers receive automatic assignments. While on duty the app reports its position about once a minute.
- Sign-in for both apps is the staff **email + password** (`POST /api/v1/admin/auth/login`).

## API (prefix `/api/v1/admin`, staff token)

| Who | Endpoint |
|---|---|
| Driver | `GET dispatch/driver`, `PUT dispatch/driver/duty`, `POST dispatch/driver/location`, `GET dispatch/driver/jobs?scope=active\|history` |
| Driver steps | `POST deliveries/{id}/accept\|pickup\|out-for-delivery\|deliver\|fail` (deliver takes a proof photo and the cash collected) |
| Staff | `GET dispatch/overview`, `GET dispatch/queue?stage=new\|preparing\|ready\|out`, `GET dispatch/drivers`, `POST dispatch/fulfillments/{id}/assign` |
| Staff order steps | `POST fulfillments/{id}/transition` (`accepted`, `preparing`, `ready`) |

Code: `app/Domain/Fulfillment/Services/DispatchService.php`, `app/Http/Controllers/Api/V1/Admin/DispatchController.php`, tests in `tests/Feature/Commerce/DispatchTest.php`.
