# Admin guide · دليل الإدارة

> English first, Arabic below. The dashboard language switch (top bar) changes the whole interface, including direction.
> الإنجليزية أولاً ثم العربية. زر اللغة أعلى الشاشة يغيّر الواجهة بالكامل بما فيها الاتجاه.

---

## 1. Signing in & security
- Open `/admin`, sign in with your staff email/phone and password.
- **Two-factor authentication is mandatory.** On first sign-in you are taken to **Profile** to scan the QR code with an
  authenticator app (Google Authenticator, Microsoft Authenticator, 1Password…). Save the recovery codes somewhere safe —
  each works once.
- Lost your phone? Ask a super admin to reset your 2FA; you will enrol again at next sign-in.
- Every action you take is recorded in the **Audit log** with your name, time, IP and the before/after values.

## 2. Organization (super admin / admin)
| Task | Where | Notes |
|---|---|---|
| Add a branch | Organization › Branches › New | Address & map pin, opening hours, holidays, pickup slots, COD limits |
| Delivery zones & fees | Organization › Zones, then each branch › Zones | A zone can be served by several branches with different fees/priorities. Add **delivery slots** (day, time window, capacity) per branch under Branches › Slots: once a branch has delivery slots, customers must pick one at checkout |
| Staff | Organization › Employees | Create the account, assign it to branches with **branch roles**; company-wide roles (finance, marketing) are separate |
| Roles | Organization › Roles | Module × action matrix. System roles cannot be deleted; Super Admin is locked |
| Settings | Organization › Settings | Payment window, COD fee, loyalty, auction defaults, contact details. Secret values are write-only |

**Least privilege:** give each person only the roles they need, only in their branches. Nobody can grant permissions
they don't hold, or change their own roles or status.

## 3. Catalog & pricing
- **Products › New**: content in English and Arabic side by side, SEO, specs, dimensions, warranty, then **Variants**
  (colour/size combinations), **Media** (drag to order; set alt text in both languages) and **Pricing**.
- **Price precedence**: variant+branch → product+branch → variant → product. A branch can disable a product entirely;
  it is then never sold there. Every price change requires a reason and is kept in price history.
- **Imports**: download the template, fill it, upload; rows with errors are listed and downloadable — nothing partial is
  half-saved per row.

## 4. Inventory
- **Stock levels** shows on hand, reserved and available per branch; set min/max thresholds for low-stock alerts.
- **Adjustments** (damage, loss, found) need a second person to approve.
- **Transfers**: request → approve (reserves stock at source) → dispatch → receive (partial, damaged quantities) → complete.
- **Stocktaking**: start a count (whole branch or a category incl. sub-categories), scan or type counts, submit, then a
  manager posts the variances. Sales during counting are handled automatically.
- **Purchase orders**: draft → approval → goods receipt (partial/rejected quantities, supplier invoice) → close.

## 5. Orders, pickup & delivery
- **Orders**: filter by status/branch/payment. An order may be split across branches — each fulfillment moves on its own
  (accepted → preparing → ready → dispatched/pickup ready …). Only valid next steps are offered.
- **Online (SADAD) orders** cannot be prepared until paid; unpaid ones expire after 30 minutes and stock is released.
- **Cancellations**: customer requests appear in the order; approve or reject with a reason. Refunds follow automatically
  as a refund request.
- **Pickup**: scan the customer's QR pass (or type its code), check ID, hand over (partial handover is allowed), collect
  payment for pay-at-pickup.
- **Deliveries**: assign a driver or courier; drivers update status from their phone (`/admin/driver`) and record COD. A delivery is scheduled for the slot the customer chose. To **reschedule** a failed delivery pick a new date and one of the branch's free slots; the booking moves automatically (a full slot is never offered).

## 6. Finance
- **Payments**: every attempt with its SADAD reference. Flags: *amount mismatch* (held for review) and *late capture*
  (paid after the order expired — refund or re-instate manually; the order is never revived automatically).
- **Refunds**: one person requests, **a different person approves**; never more than was captured. While SADAD refund API
  is not enabled, refunds are made by bank transfer and the bank reference is recorded.
- **Cash** (three tabs):
  1. *Collections* — every COD / pay-at-pickup collection.
  2. *To hand in* — cash each driver or counter still holds. A branch manager or accountant **receives** it: count the cash together, enter the amount counted. The system compares it with what was collected. A difference needs a note and becomes a **variance**; nobody can receive their own cash.
  3. *Hand-ins & deposits* — every count with its variance. Finance **reviews** open variances (someone who neither handed over nor received the cash). Tick hand-ins and **bank the cash** with the bank reference; the *In branch drawer* figure drops accordingly.
  Receiving and banking need the `payments.edit` permission (branch managers and accountants); collectors only need `payments.create`.
- **Reconciliation**: compare with the SADAD settlement report; resolve each flagged line.

## 7. Auctions
Create an auction, add lots (product, starting price, reserve, increment), set anti-sniping (extension window, max
extensions), deposit and terms (EN/AR), then **Publish**. During a live auction the **auctioneer console** shows bids in
real time on server time. Winners have a payment deadline; unpaid winners are handled by the policy you chose
(offer to next bidder, relist, or none).
**Editing lots:** a lot that has not opened can be changed completely (prices, times, linked product) or deleted if it has no bids. A **live** lot can only change its title, description and image, plus the reserve price until the first bid. To give bidders more time use **Extend time** in the console (30 s – 24 h, with a reason); it never shortens a lot and is separate from anti-sniping. A published auction always keeps at least one lot.

## 8. Marketing, content & notifications
- **Promotions/coupons**: date windows, usage limits per customer and total, stackable flag. Free-delivery always combines.
- **Campaigns**: only customers who gave marketing consent are targeted; schedule and preview before sending.
- **Loyalty & referrals**: earning/redemption rules; manual point adjustments need a reason.
- **Reviews**: approve/reject; answer product questions.
- **Homepage**: reorder sections, schedule banners, edit both languages, preview before publishing.
- **Notification templates**: override any message per event, channel and language; use the variables shown.

## 9. Reports & exports
**Reports** lists only the reports your role may see (sales, payments, refunds, stock, transfers, auctions, marketing…),
always limited to your branches. Large exports run in the background — collect them from **Exports** (links expire).

---

# دليل الإدارة (العربية)

## ١. الدخول والأمان
- افتح `/admin` وسجّل الدخول ببريدك أو هاتفك وكلمة المرور.
- **المصادقة الثنائية إلزامية.** عند أول دخول ستنتقل إلى **الملف الشخصي** لمسح رمز QR بتطبيق المصادقة، واحفظ رموز الاسترداد في مكان آمن (كل رمز يُستخدم مرة واحدة).
- فقدت هاتفك؟ اطلب من المشرف العام إعادة ضبط المصادقة الثنائية.
- كل إجراء يُسجَّل في **سجل التدقيق** باسمك ووقته وعنوان IP والقيم قبل وبعد التغيير.

## ٢. المؤسسة
| المهمة | المكان | ملاحظات |
|---|---|---|
| إضافة فرع | المؤسسة › الفروع › جديد | العنوان والموقع، ساعات العمل، العطل، مواعيد الاستلام، حدود الدفع عند الاستلام |
| مناطق التوصيل والرسوم | المؤسسة › المناطق ثم الفرع › المناطق | يمكن أن تخدم عدة فروع المنطقة نفسها برسوم وأولويات مختلفة. أضف **فترات التوصيل** (اليوم والفترة الزمنية والسعة) لكل فرع من الفروع › الفترات: متى وُجدت فترات توصيل لفرع يلزم العميل باختيار إحداها عند الدفع |
| الموظفون | المؤسسة › الموظفون | أنشئ الحساب وعيّنه على الفروع بأدوار الفرع؛ أدوار الشركة (المالية، التسويق) منفصلة |
| الأدوار | المؤسسة › الأدوار | مصفوفة الوحدات × الإجراءات. أدوار النظام لا تُحذف والمشرف العام مقفل |
| الإعدادات | المؤسسة › الإعدادات | مهلة الدفع، رسوم الدفع عند الاستلام، الولاء، إعدادات المزادات. القيم السرية للكتابة فقط |

**أقل صلاحية ممكنة:** امنح كل شخص ما يحتاجه فقط وفي فروعه فقط. لا يمكن لأحد منح صلاحيات لا يملكها أو تعديل أدواره أو حالته.

## ٣. الكتالوج والأسعار
- **المنتجات › جديد**: المحتوى بالعربية والإنجليزية جنباً إلى جنب، ثم **المتغيرات** و**الصور** (اسحب للترتيب واكتب النص البديل باللغتين) و**الأسعار**.
- **أولوية السعر**: المتغير+الفرع ← المنتج+الفرع ← المتغير ← المنتج. يمكن تعطيل المنتج في فرع فلا يُباع فيه أبداً. كل تغيير سعر يتطلب سبباً ويُحفظ في سجل الأسعار.
- **الاستيراد**: نزّل القالب واملأه وارفعه؛ تظهر الصفوف الخاطئة ويمكن تنزيلها.

## ٤. المخزون
- **مستويات المخزون**: المتوفر والمحجوز والمتاح لكل فرع، مع حدود دنيا وعليا للتنبيه.
- **التسويات** (تلف، فقد، عُثر عليه) تحتاج موافقة شخص آخر.
- **التحويلات**: طلب ← موافقة (حجز في المصدر) ← إرسال ← استلام (جزئي أو تالف) ← إكمال.
- **الجرد**: ابدأ الجرد (الفرع كاملاً أو فئة مع فئاتها الفرعية)، امسح أو أدخل الكميات، أرسل، ثم يعتمد المدير الفروقات.
- **أوامر الشراء**: مسودة ← موافقة ← استلام البضاعة (جزئي/مرفوض، فاتورة المورد) ← إغلاق.

## ٥. الطلبات والاستلام والتوصيل
- قد يُقسَّم الطلب على عدة فروع، ولكل فرع مسار تنفيذ مستقل؛ تظهر الخطوات الصحيحة التالية فقط.
- طلبات الدفع الإلكتروني (SADAD) لا تُجهَّز قبل الدفع، وتنتهي بعد ٣٠ دقيقة مع تحرير المخزون.
- **الإلغاء**: طلبات العملاء تظهر داخل الطلب؛ اقبل أو ارفض مع ذكر السبب.
- **الاستلام من الفرع**: امسح رمز QR للعميل (أو أدخل الرمز)، تحقق من الهوية، سلّم (يُسمح بالتسليم الجزئي)، وحصّل المبلغ عند الدفع في الفرع.
- **التوصيل**: عيّن سائقاً أو شركة توصيل؛ يحدّث السائق الحالة من هاتفه (`/admin/driver`) ويسجّل المبلغ المحصّل. يُجدوَل التوصيل على الفترة التي اختارها العميل. ولإعادة جدولة توصيل فاشل اختر تاريخاً جديداً وإحدى فترات الفرع المتاحة؛ ينتقل الحجز تلقائياً ولا تُعرض الفترة الممتلئة.

## ٦. المالية
- **المدفوعات**: كل محاولة مع مرجع SADAD. تنبيهات: *اختلاف المبلغ* (معلّق للمراجعة) و*دفع متأخر* (بعد انتهاء الطلب — يُعالَج يدوياً ولا يُعاد الطلب تلقائياً).
- **المبالغ المستردة**: شخص يطلب و**شخص آخر يوافق**، ولا تتجاوز المبلغ المحصّل. إلى حين تفعيل واجهة الاسترداد من SADAD يتم الاسترداد بتحويل بنكي مع تسجيل المرجع.
- **النقد** (ثلاثة تبويبات):
  ١. *التحصيلات* — كل تحصيل عند الاستلام أو في الفرع.
  ٢. *بانتظار التسليم* — النقد الذي لا يزال بحوزة كل سائق أو موظف. **يستلمه** مدير الفرع أو المحاسب: عُدّوا النقد معاً وأدخل المبلغ المعدود، فيقارنه النظام بما تم تحصيله. الفرق يتطلب ملاحظة ويصبح **فرقاً** مفتوحاً؛ ولا يمكن لأحد استلام نقده بنفسه.
  ٣. *التسليمات والإيداعات* — كل عملية عدّ مع فرقها. تراجع المالية الفروقات المفتوحة (شخص لم يسلّم النقد ولم يستلمه). حدّد التسليمات و**أودع النقد** بمرجع البنك فينخفض رقم *في صندوق الفرع*.
  الاستلام والإيداع يتطلبان صلاحية `payments.edit` (مديرو الفروع والمحاسبون)؛ أما المحصّلون فتكفيهم `payments.create`.
- **المطابقة**: قارن مع تقرير تسوية SADAD وعالج كل بند مُعلَّم.

## ٧. المزادات
أنشئ المزاد وأضف القطع (المنتج، سعر البداية، السعر الاحتياطي، الزيادة)، واضبط منع القنص (مدة التمديد وعدد التمديدات)، والتأمين والشروط بالعربية والإنجليزية، ثم **انشر**. أثناء المزاد تعرض **شاشة الدلّال** المزايدات لحظياً بتوقيت الخادم. للفائز مهلة للدفع، ويُطبَّق عند عدم الدفع ما اخترته (عرض على المزايد التالي، إعادة العرض، أو لا شيء).
**تعديل القطع:** القطعة التي لم تُفتح بعد يمكن تعديلها بالكامل (الأسعار والأوقات والمنتج المرتبط) أو حذفها إن لم تكن عليها مزايدات. أما القطعة **المعروضة** فيمكن تغيير عنوانها ووصفها وصورتها فقط، والسعر الاحتياطي حتى أول مزايدة. ولمنح المزايدين وقتاً أطول استخدم **تمديد الوقت** في الشاشة المباشرة (من 30 ثانية إلى 24 ساعة مع ذكر السبب)؛ لا يقصّر مدة القطعة أبداً ومنفصل عن منع القنص. ويحتفظ المزاد المنشور دائماً بقطعة واحدة على الأقل.

## ٨. التسويق والمحتوى والإشعارات
- **العروض والقسائم**: فترات زمنية، حدود استخدام لكل عميل وإجمالية، وخيار الدمج. التوصيل المجاني يُدمج دائماً.
- **الحملات**: تستهدف فقط العملاء الموافقين على التسويق؛ جدولة ومعاينة قبل الإرسال.
- **الولاء والإحالات**: قواعد الكسب والاستبدال؛ التعديل اليدوي للنقاط يتطلب سبباً.
- **التقييمات**: قبول/رفض والرد على أسئلة المنتجات.
- **الصفحة الرئيسية**: رتّب الأقسام وجدول البانرات وعدّل باللغتين وعاين قبل النشر.
- **قوالب الإشعارات**: خصّص أي رسالة لكل حدث وقناة ولغة باستخدام المتغيرات الظاهرة.

## ٩. التقارير والتصدير
تعرض **التقارير** ما يسمح به دورك فقط وضمن فروعك. التصديرات الكبيرة تعمل في الخلفية وتُستلم من **التصديرات** (الروابط مؤقتة).
