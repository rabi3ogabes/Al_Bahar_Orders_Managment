# Branch employees · موظفو الفروع

An account for someone who works at **one branch** (a cashier, order desk, stock keeper, delivery coordinator or a branch manager), who
signs in from a **tablet, computer or phone** and sees **only the features you tick**, **only for their branch**. It is not an admin account:
it has no company-wide role and no access to settings, roles, marketing, content or other branches.

## How to add one

1. **Organization › Employees › Add branch employee** (a side window; **Open full page** gives the same form as a page).
2. **Who is this?** Name, mobile number (Qatar numbers are normalised to `+974…`), e-mail (give at least one of the two: they sign in with it),
   job title, language.
3. **Branch and sign-in.** Choose the branch (a branch manager only sees their own). Write or **generate** a *temporary password* (copy button next to it).
4. **What can they use?** Tap a ready-made set, then tick or untick anything:

   | Preset | For |
   |---|---|
   | Branch manager | runs the branch day to day (orders, stock, prices, cash, reports) |
   | Order desk | takes, prepares and dispatches orders, answers customers |
   | Cashier / pickup | hands orders to customers and handles the cash drawer |
   | Stock keeper | stock levels, counts and transfers |
   | Delivery coordinator | plans deliveries and assigns drivers |
   | Read only | looks at orders, stock, products and reports; changes nothing |
   | Wall display screen | the live board on a wall screen, read only, nothing else |

   Ticking a feature also ticks what it cannot work without ("Create orders" also ticks "See orders" and "See products and prices");
   unticking one unticks what needs it. A set that matches no preset is shown as *Custom*.
5. **Add employee.** The person is told nothing automatically: give them the temporary password privately.

The employee **signs in** at `/admin` (or in the staff app) with their e-mail *or* mobile number and the temporary password, and is taken to
**My profile** to choose their own password. Until they do, every other page and every staff API call is refused. **Reset password** (Security tab)
does the same again.

## Changing access later

Open the employee › **Features & branches**: the same checklist, pre-filled from what they can do now, per branch (choose the branch if they work at
several). **Save changes** stays disabled until something changes. If the person had ready-made *roles* at that branch, saving the checklist
**replaces** them (the page says which). A change is written to the audit log as `employee.access_changed` with the old and new permission keys.

The **Roles** page keeps working for admins who prefer roles: you can still give someone *Order Manager* instead of ticking features, and switch back.

## The employees list

Role / preset, branches, **Features** (count, with the feature names in a tooltip: hover or keyboard focus), last sign-in, and an **active / disabled switch**
per row with **Undo**. Search, branch filter (only your branches), role filter (including *Custom feature access*) and status filter. A person marked
*Has not chosen a password yet* has not signed in since you created or reset them.

## What each feature opens

| Group | Feature | What it opens | Permission keys | Needs |
|---|---|---|---|---|
| Home | **Home overview** | Opens the dashboard home with today’s numbers. | `dashboard.view` | — |
| Orders | **See orders** | Opens the orders list and the details of each order. | `orders.view` | — |
|  | **Accept and prepare orders** | Accept an order, mark it preparing and ready, and fix missing items. | `orders.edit` | See orders |
|  | **Create orders for customers** | Take an order for a customer by phone or in the branch. | `orders.create` | See orders, See products and prices |
|  | **Cancel orders** | Cancel an order and answer a customer’s cancellation request. | `orders.approve` | See orders |
|  | **Ask for refunds** | Send a refund request for an order. An administrator approves it. | `refunds.view`, `refunds.create` | See orders |
| Pickup and delivery | **See store pickups** | Opens the pickup list and the code scanner. | `pickup.view` | — |
|  | **Prepare and hand over pickups** | Mark an order ready for pickup and hand it to the customer. | `pickup.edit`, `pickup.approve` | See store pickups |
|  | **See deliveries and drivers** | Opens deliveries, the driver list and the live map. | `deliveries.view` | — |
|  | **Create and update deliveries** | Book a delivery for an order and move it through its steps. | `deliveries.create`, `deliveries.edit` | See deliveries and drivers |
|  | **Assign a driver** | Choose which driver takes a delivery. | `deliveries.assign` | See deliveries and drivers |
| Stock | **See stock** | Opens the stock levels and the stock history of this branch. | `inventory.view` | — |
|  | **Adjust stock** | Record damaged, found or corrected items and set low-stock levels. | `inventory.create`, `inventory.edit` | See stock |
|  | **Count stock** | Run a stock count (stocktake) for this branch. | `stocktaking.view`, `stocktaking.create`, `stocktaking.edit` | See stock |
|  | **Transfer stock** | Send stock to another branch or receive it. | `transfers.view`, `transfers.create`, `transfers.edit` | See stock |
|  | **Approve stock changes** | Approve the adjustments, counts and transfers that others made. | `inventory.approve`, `stocktaking.approve`, `transfers.approve` | See stock |
| Prices and products | **See products and prices** | Opens the product list with this branch’s prices. | `catalog.view`, `pricing.view` | — |
|  | **Change this branch’s prices** | Set the selling price and availability for this branch only. | `pricing.edit` | See products and prices |
| Customers | **See customers** | Opens the customers who ordered from this branch. | `customers.view` | — |
| Support | **See support requests** | Opens the support inbox of this branch. | `support.view` | — |
|  | **Reply to support requests** | Answer customers and add internal notes. | `support.create`, `support.edit` | See support requests |
| Reports | **See reports** | Opens the sales and stock reports of this branch. | `reports.view` | — |
|  | **Download reports** | Export report data to a file. | `reports.export` | See reports |
| Cash drawer | **See cash drawer and payments** | Opens the cash drawer and the payments list of this branch. | `payments.view` | — |
|  | **Collect cash from customers** | Record cash taken at pickup or on delivery. | `payments.create` | — |
|  | **Hand in and deposit cash** | Hand collected cash to the office and record bank deposits. | `payments.edit` | See cash drawer and payments |
|  | **Approve petty-cash payouts above the limit** | Can stand behind a payout from the till that is above the limit, as the second person. Does not open any other payment or cash screen. | `payouts.approve` (narrower than `payments.override`, which stays accepted) | See cash drawer and payments |
| Staff of this branch | **See staff of this branch** | Opens the list of people who work at this branch (no changes). | `employees.view` | — |
| Screen on the wall | **Wall display** | A screen that shows the live board of this branch and nothing else (no menu, search, phone numbers or buttons). Cannot be mixed with other features. See [SEARCH_AND_LIVE_BOARD.md](SEARCH_AND_LIVE_BOARD.md). | `board.view` | — |

*Product details (names, descriptions, photos) are shared by every branch and the website, so editing them is **not** a branch feature: `catalog.edit` (like create, delete and approve) stays with administrators. Branch prices are a feature ("Change this branch’s prices").*

### Presets

| Feature | Branch manager | Order desk | Cashier / pickup | Stock keeper | Delivery coordinator | Read only |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| Home overview | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| See orders | ✓ | ✓ | ✓ |  | ✓ | ✓ |
| Accept and prepare orders | ✓ | ✓ |  |  |  |  |
| Create orders for customers | ✓ | ✓ |  |  |  |  |
| Cancel orders | ✓ |  |  |  |  |  |
| Ask for refunds | ✓ | ✓ |  |  |  |  |
| See store pickups | ✓ | ✓ | ✓ |  | ✓ | ✓ |
| Prepare and hand over pickups | ✓ | ✓ | ✓ |  |  |  |
| See deliveries and drivers | ✓ | ✓ |  |  | ✓ | ✓ |
| Create and update deliveries | ✓ | ✓ |  |  | ✓ |  |
| Assign a driver | ✓ | ✓ |  |  | ✓ |  |
| See stock | ✓ | ✓ |  | ✓ |  | ✓ |
| Adjust stock | ✓ |  |  | ✓ |  |  |
| Count stock | ✓ |  |  | ✓ |  |  |
| Transfer stock | ✓ |  |  | ✓ |  |  |
| Approve stock changes | ✓ |  |  |  |  |  |
| See products and prices | ✓ | ✓ |  | ✓ |  | ✓ |
| Change this branch’s prices | ✓ |  |  |  |  |  |
| See customers | ✓ | ✓ | ✓ |  | ✓ | ✓ |
| See support requests | ✓ | ✓ |  |  |  | ✓ |
| Reply to support requests |  |  |  |  |  |  |
| See reports | ✓ |  |  |  |  | ✓ |
| Download reports | ✓ |  |  |  |  |  |
| See cash drawer and payments | ✓ |  | ✓ |  |  |  |
| Collect cash from customers | ✓ |  | ✓ |  |  |  |
| Hand in and deposit cash | ✓ |  |  |  |  |  |
| Approve petty-cash payouts above the limit |  |  |  |  |  |  |
| See staff of this branch | ✓ |  |  |  |  |  |


## Security model

* **Stored as a personal role** (decision below). Authorisation (`AccessControl`, the `permission:` route middleware, branch scope, the API) is unchanged: the checklist is an
  ordinary *branch-scoped* role owned by one employee at one branch. The employee's account is `type = staff`, `is_super_admin = false`, with **no company-wide role**.
* **Nobody grants what they do not hold.** Every permission that changes (on or off) must be one the person saving holds *at that branch*; features they lack are shown locked.
  This is checked on the server (`BranchEmployeeService`), so a tampered request is refused with *You cannot grant permissions or branches beyond your own authority*.
* **Branch scope.** The person needs `employees.assign` at the branch; a manager only sees and changes staff of their own branches, and can switch a person on/off only
  if every branch that person works at is theirs. Nobody changes their own access. An admin, a super admin or anyone with a company-wide role is never touched through the checklist.
* **Never offered:** `roles.*`, `settings.*`, `notifications.*`, `cms.*`, `marketing.*`, `audit.*`, `branches.*`, `zones.*`, `auctions.*`, `loyalty.*`, `reviews.*`, and
  `employees.create/edit/delete/assign`, and `catalog.create/edit/delete/approve` (the shared catalog: the staff API checks them without a branch, so a branch grant would edit every branch's products). Staff of the branch can be *viewed* only.
* **Pages and APIs.** Every dashboard page and staff API route is guarded by the permission *and* the branch scope in the page or controller: a feature that is not ticked answers
  **403**, another branch's data is empty or **403/404**. The menu shows only what is permitted (and direct URLs are closed anyway).
* **Sessions are cut at once, whatever the session driver.** Every dashboard session carries the account's `users.session_stamp` (written at sign-in, checked on every request, also
  Livewire calls). Rotating it ends every browser session of the person, with Redis, file or database sessions alike, and an old cookie **stays dead even if the account is switched
  on again**. It is rotated (together with the "remember me" token, every API token and every refresh token) when: the **password is changed** (dashboard form or `PUT /admin/me/password`: only the
  device making the change stays signed in), an administrator **resets the password**, an account is **suspended or deactivated** (the row switch too), a password is reset by code,
  or the person's **last active branch** is removed or switched off.
* **First sign-in.** `users.must_change_password` is set when the account is created and when the password is reset; the dashboard sends the person to **My profile** and the staff API
  answers `403 password_change_required` until `PUT /api/v1/admin/me/password` (or the profile form) succeeds. The new password must differ from the current one. The live-channel
  authorisation endpoints (`/broadcasting/auth`, `/api/v1/broadcasting/auth`) apply the same staff policy, so the person subscribes to nothing before choosing their own password.
* **Those first-step gates (password, two-factor enrolment) cannot be spoofed.** A Livewire call is let through only when every component in it is the profile component (read from the
  server-signed snapshots) and it comes from the profile page; request headers (`X-Livewire`, `Referer`) play no part. While a first step is pending the profile page shows no cart components.
* **Assignment edits keep the checklist.** Saving the assignment form (or `POST /api/v1/admin/branches/{branch}/employees`) for someone whose access is a checklist updates only the job
  title and the driver flag; giving ready-made roles to that person from there is refused (`422 personal_role_replace`): use *Features & branches*. The branch's staff list in the API shows
  `custom_access: true` instead of the personal role's key.
* **Scoped data.** The features hand out branch-scoped data only: customers, reports, support and refund figures are limited to the person's branches (the dashboard's open-ticket and
  pending-refund counts and the customers report's sign-up count included: that count is company-wide and only shown to people who see every branch).
* **Two-factor** stays as the team setting says (Settings › Security); an employee with it on is asked for the code as before.
* **Rate limits:** at most 20 accounts per person per hour; dashboard sign-in is limited per person (one bucket however the mobile number is typed) and per address of the caller, and always checks a password so
  an unknown identifier answers as slowly as a known one; wrong *current password* attempts on the profile (change password, turn two-factor off) share one budget of five per five minutes. **Audit:** `employee.created` (branch, sign-in method; no password), `employee.access_changed`
  (old and new permission keys and features, replaced roles), `employee.status_changed`, `employee.password_reset`. No secret is ever written to a log.

## Design decision: a personal role, not per-user grants

Permissions are resolved in one place (`AccessControl::grantsFor`: user → `user_roles` → role → permissions, with branch scope and an *active assignment* check) and every page, route and API relies on it.
Per-user grants would need a second table and a change to that resolver (a security-critical path used on every request). A **personal role** needs none: it is a normal role row
(`roles.personal_user_id` / `personal_branch_id`, unique per employee and branch, `scope = branch`, `is_system = false`) assigned through `user_roles`.
It is hidden from the Roles page, the role pickers (employee editor, branch editor) and the role filter, cannot be opened in the role editor or granted to anyone else
(`EmployeeService::assertCanGrantRole` refuses it), and is deleted with its employee or branch (and when the assignment is replaced by shared roles).
Migration: `2026_11_17_300000_add_branch_employee_access.php` (`roles.personal_*`, `users.must_change_password`).

## Tablet, computer, phone

The dashboard is one responsive app: the same pages and rules on every device (side windows become bottom sheets on phones, touch targets are at least 44 px). The feature picker is one column on phones
and two on tablets and larger. The staff app (Flutter, `mobile/lib/ops`) follows the same permissions from `GET /api/v1/admin/me`.

## API additions (staff API)

| Call | What |
|---|---|
| `POST /api/v1/admin/auth/login` | now also accepts a mobile number in `email` or in `phone`; the answer includes `must_change_password` |
| `GET /api/v1/admin/me` | now includes `must_change_password` |
| `PUT /api/v1/admin/me/password` | `current_password`, `password`, `password_confirmation` (12+ characters, upper and lower case, a number, different from the current one); signs out every other app session, refresh token and browser session of the account (this device stays) |
| any other `/api/v1/admin/*` call while the flag is set | `403`, code `password_change_required` |
| `GET /api/v1/admin/branches/{branch}/employees` | rows no longer carry a personal role's key in `roles`; `custom_access` says it exists |

## Known limits

* There is no e-mailed **invite link** (the platform has no staff mail channel yet): the temporary password is shared by the person who adds the employee.
* Browser sessions are ended by the per-account stamp on every request, so they stop at once with any session driver; the session *rows* are deleted as a courtesy only with the `database` driver (Redis/file sessions simply expire).
* The staff apps (branch and driver) have the same first-sign-in step: they read `must_change_password` from the sign-in answer, and treat `403 password_change_required` from any call as "show **Choose your own password**" (the server keeps the same tokens signed in after `PUT /api/v1/admin/me/password`, so the app just carries on). A staff account with no `orders.view` is told so in the branch app (use the dashboard).

## For developers: adding a feature

Add it to `PermissionRegistry::BRANCH_FEATURES` (group, `keys`, `needs`), optionally to a preset, and add `branch_access.<id>` and `branch_access.<id>_hint` in `lang/en` and `lang/ar`.
`tests/Feature/Identity/BranchFeatureCatalogTest.php` fails if a key does not exist, an admin-only permission is offered, a preset is not closed under `needs`, a preset is beyond a system branch
manager's authority, or a text is missing or not Arabic.

---

# موظفو الفروع (العربية)

حساب لشخص يعمل في **فرع واحد** (أمين صندوق، مكتب الطلبات، أمين مخزن، منسق توصيل أو مدير فرع) يسجّل الدخول من **جهاز لوحي أو حاسوب أو هاتف** ويرى **الميزات التي تحددها فقط** و**لفرعه فقط**.
ليس حساب مسؤول: لا دور على مستوى الشركة ولا وصول إلى الإعدادات أو الأدوار أو التسويق أو المحتوى أو الفروع الأخرى.

## كيف تضيف موظفًا

١. **المؤسسة › الموظفون › إضافة موظف فرع** (نافذة جانبية، و**فتح الصفحة الكاملة** يعرض النموذج نفسه كصفحة).
٢. **من هو؟** الاسم ورقم الجوال (أرقام قطر تُحوَّل إلى `+974…`) والبريد (أدخل أحدهما على الأقل: به يسجّل الدخول) والمسمى الوظيفي واللغة.
٣. **الفرع وتسجيل الدخول.** اختر الفرع (مدير الفرع يرى فرعه فقط). اكتب كلمة مرور *مؤقتة* أو **ولّدها** (مع زر نسخ).
٤. **ماذا يستطيع أن يستخدم؟** اضغط على مجموعة جاهزة (مدير الفرع، مكتب الطلبات، أمين الصندوق / الاستلام، أمين المخزن، منسق التوصيل، قراءة فقط) ثم أضف أو أزل ما تشاء.
   تحديد ميزة يحدد معها ما تحتاجه، وإلغاؤها يلغي ما يعتمد عليها. وما لا يطابق مجموعة جاهزة يظهر كـ *مخصص*.
٥. **إضافة الموظف.** لا يُرسَل أي شيء تلقائيًا: سلّم الموظف كلمة المرور المؤقتة بشكل خاص.

يسجّل الموظف الدخول من `/admin` (أو من تطبيق الموظفين) برقم جواله **أو** بريده وكلمة المرور المؤقتة، فينتقل إلى **ملفي الشخصي** ليختار كلمة مرور خاصة به. إلى ذلك الحين تُرفض كل الصفحات
وكل طلبات واجهة الموظفين الأخرى. **إعادة تعيين كلمة المرور** (تبويب الأمان) تفعل الأمر نفسه من جديد.

## تغيير الصلاحيات لاحقًا

افتح الموظف › **الميزات والفروع**: القائمة نفسها معبّأة بما يستطيعه الآن، لكل فرع (اختر الفرع إن كان يعمل في أكثر من فرع). زر **حفظ التغييرات** معطّل حتى يتغير شيء.
إذا كان له *أدوار* جاهزة في ذلك الفرع فإن الحفظ **يستبدلها** بالقائمة (تذكر الصفحة أسماءها). يُسجَّل التغيير في سجل التدقيق `employee.access_changed` بمفاتيح الصلاحيات قبل وبعد.
صفحة **الأدوار** تعمل كما كانت لمن يفضّل الأدوار.

## قائمة الموظفين

الدور / القالب، الفروع، **الميزات** (العدد مع أسماء الميزات في تلميح عند التمرير أو التركيز بلوحة المفاتيح)، آخر دخول، و**مفتاح تفعيل / تعطيل** في كل صف مع **تراجع**. بحث وتصفية بالفرع (فروعك فقط) وبالدور (ومنه *صلاحيات ميزات مخصصة*) وبالحالة.

## الأمان

* الميزات تُحفظ كـ **دور شخصي** لموظف واحد في فرع واحد؛ نظام الصلاحيات نفسه لم يتغير. الحساب `staff` وليس مشرفًا عامًا ولا يحمل أي دور على مستوى الشركة.
* **لا أحد يمنح ما لا يملكه:** كل صلاحية تتغير (تفعيلًا أو إلغاءً) يجب أن يملكها من يحفظ في ذلك الفرع؛ والميزات التي لا يملكها تظهر مقفلة، ويتحقق الخادم من ذلك دائمًا.
* **نطاق الفرع:** يلزم `employees.assign` في الفرع؛ المدير يرى ويعدّل موظفي فروعه فقط، ولا يعدّل أحد صلاحياته بنفسه، ولا تُعدَّل بهذه القائمة حسابات المسؤولين أو من لديهم دور على مستوى الشركة.
* **لا تُعرض إطلاقًا:** الأدوار، الإعدادات، الإشعارات، المحتوى، التسويق، التدقيق، الفروع، المناطق، المزادات، الولاء، التقييمات، وإنشاء الموظفين وتعديلهم، وتعديل بيانات المنتجات (الكتالوج مشترك بين كل الفروع والموقع، فيبقى للمسؤولين؛ أما أسعار الفرع فميزة متاحة).
* **التعطيل أو تغيير كلمة المرور أو إعادة تعيينها** يلغي كل الرموز وكل جلسات المتصفح فورًا مهما كان نوع تخزين الجلسات (بصمة جلسة لكل حساب تُفحص مع كل طلب)، وتبقى الجلسة القديمة ميتة حتى لو أُعيد تفعيل الحساب؛ والجهاز الذي غيّر كلمة المرور يبقى مفتوحًا. ويُرفض الحساب المعطّل في كل طلب. **تعديل التعيين** لا يمسح قائمة الميزات: يحدّث المسمى الوظيفي وخيار السائق فقط. **أول دخول:** يجب اختيار كلمة مرور خاصة قبل أي شيء آخر. **المصادقة الثنائية** كما في إعدادات الفريق. يُسجَّل `employee.created` و`employee.access_changed` في سجل التدقيق دون أي سر.
