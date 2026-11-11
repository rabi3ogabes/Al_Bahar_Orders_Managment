# Branch staff guide · دليل موظفي الفروع

Quick, task-based instructions for showroom, warehouse and driver staff. You only see the branches and menus your role allows.
تعليمات مختصرة لموظفي المعارض والمستودعات والسائقين. تظهر لك فقط الفروع والقوائم المسموح بها لدورك.

---

## Showroom / cashier
**Prepare an order** — Sales › Orders › filter *Needs action* → open → **Accept** → **Preparing** → **Ready** (or **Ready for pickup**).
Online-paid orders appear only after payment succeeds.

**Hand over a pickup**
1. Sales › Pickup › **Scan** (allow the camera) — or type the code under the customer's QR.
2. Check the name and the customer's ID; the screen shows exactly what to hand over.
3. Pay-at-pickup: collect the amount shown and record cash/card.
4. Confirm handover. You can hand over part of the order; the rest stays reserved.
A pass that is invalid, expired, for another branch, or already used is rejected — never hand goods over on a rejected scan.

**Customer wants to cancel** — open the order › Cancellation request › approve or reject with a reason. Goods already
dispatched can't be cancelled; register a return instead.

## Warehouse
- **Receive a transfer**: Inventory › Transfers › open the *in transit* transfer › enter received and damaged quantities › Receive.
- **Receive a purchase order**: Inventory › Purchase orders › open › Goods receipt › quantities (rejected with reason) and supplier invoice number.
- **Stock count**: Inventory › Stocktaking › Start › scan each item (or type the count) › Submit. Your manager posts it.
- **Damage/loss**: Inventory › Adjustments › New with a reason; a manager approves it. Never edit stock any other way.

## Driver (phone)
1. Open `/admin/driver` on your phone and sign in (2FA code from your authenticator app).
2. **Today** lists your deliveries in order. Tap one → **Navigate** opens maps.
3. **Accept** → **Picked up** (at the branch) → **Out for delivery** → **Delivered**.
4. Cash on delivery: collect exactly the amount shown and record it before confirming *Delivered*.
5. Can't deliver? **Failed** → choose the reason (customer absent, wrong address, refused…) and add a note. The branch reschedules or returns it.
6. Hand collected cash to a branch cashier or manager at the end of the shift. They count it with you under Finance › Cash › *To hand in*; it must match your collection list. The driver screen shows how much you still owe the branch, and you can't receive your own cash.

## Branch manager
- **Receive cash** from drivers and counters (Finance › Cash › *To hand in* › Receive cash): count it together, enter the amount; explain any difference in the note. **Bank** the drawer from the *Hand-ins & deposits* tab with the bank reference.
- Reschedule failed deliveries onto one of the branch's free delivery slots, and keep the branch's delivery slots up to date (Branches › Slots).
- Approve adjustments and transfers, post stocktakes, set branch prices (a reason is required), manage branch staff
  within your branch, watch **Dashboard** and **Reports** (low stock, orders, collections) for your branch.

---

## المعرض / أمين الصندوق
**تجهيز طلب** — المبيعات › الطلبات › تصفية *بحاجة لإجراء* ← افتح ← **قبول** ← **قيد التجهيز** ← **جاهز** (أو **جاهز للاستلام**).
الطلبات المدفوعة إلكترونياً تظهر فقط بعد نجاح الدفع.

**تسليم طلب استلام**
١. المبيعات › الاستلام › **مسح** (اسمح بالكاميرا) أو أدخل الرمز المكتوب أسفل رمز QR.
٢. تحقق من الاسم وهوية العميل؛ تعرض الشاشة ما يجب تسليمه بالضبط.
٣. الدفع في الفرع: حصّل المبلغ الظاهر وسجّل طريقة الدفع.
٤. أكّد التسليم. يمكن التسليم الجزئي ويبقى الباقي محجوزاً.
أي رمز غير صالح أو منتهٍ أو لفرع آخر أو مستخدم سابقاً يُرفض — لا تسلّم البضاعة أبداً عند رفض المسح.

**العميل يريد الإلغاء** — افتح الطلب › طلب الإلغاء › اقبل أو ارفض مع السبب. البضاعة المُرسلة لا تُلغى، بل يُسجَّل إرجاع.

## المستودع
- **استلام تحويل**: المخزون › التحويلات › افتح التحويل *قيد النقل* › أدخل الكميات المستلمة والتالفة › استلام.
- **استلام أمر شراء**: المخزون › أوامر الشراء › افتح › استلام البضاعة › الكميات (المرفوضة مع السبب) ورقم فاتورة المورد.
- **الجرد**: المخزون › الجرد › ابدأ › امسح كل صنف (أو أدخل العدد) › إرسال. يعتمده المدير.
- **تلف/فقد**: المخزون › التسويات › جديد مع السبب؛ يوافق عليه المدير. لا تعدّل المخزون بأي طريقة أخرى.

## السائق (الهاتف)
١. افتح `/admin/driver` على هاتفك وسجّل الدخول (رمز المصادقة الثنائية من التطبيق).
٢. قائمة **اليوم** تعرض توصيلاتك بالترتيب. اضغط على أحدها ← **الملاحة** لفتح الخرائط.
٣. **قبول** ← **تم الاستلام** (من الفرع) ← **في الطريق** ← **تم التسليم**.
٤. الدفع عند الاستلام: حصّل المبلغ الظاهر بالضبط وسجّله قبل تأكيد *تم التسليم*.
٥. تعذّر التسليم؟ **فشل** ← اختر السبب (العميل غير موجود، عنوان خاطئ، رفض…) وأضف ملاحظة. يعيد الفرع الجدولة أو الإرجاع.
٦. سلّم النقد المحصّل لأمين صندوق أو مدير الفرع في نهاية الوردية؛ يعدّه معك من المالية › النقد › *بانتظار التسليم* ويجب أن يطابق قائمة التحصيل. تعرض شاشة السائق المبلغ الذي لا يزال عليك للفرع، ولا يمكنك استلام نقدك بنفسك.

## مدير الفرع
- **استلام النقد** من السائقين والموظفين (المالية › النقد › *بانتظار التسليم* › استلام النقد): عُدّوه معاً وأدخل المبلغ، واشرح أي فرق في الملاحظة. و**أودع** صندوق الفرع من تبويب *التسليمات والإيداعات* بمرجع البنك.
- إعادة جدولة التوصيلات الفاشلة على إحدى فترات التوصيل المتاحة للفرع، وإبقاء فترات التوصيل محدّثة (الفروع › الفترات).
- اعتماد التسويات والتحويلات، اعتماد الجرد، تحديد أسعار الفرع (مع سبب)، إدارة موظفي فرعك، ومتابعة **لوحة التحكم** و**التقارير** (نقص المخزون، الطلبات، التحصيلات) لفرعك.
