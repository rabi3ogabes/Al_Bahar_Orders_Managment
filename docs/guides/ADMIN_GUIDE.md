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
| Delivery zones & fees | Organization › Zones, then each branch › Zones | A zone can be served by several branches with different fees/priorities. Every delivery pays the zone fee, whatever the basket is worth; free delivery only comes from a coupon code (Marketing › Promotions). Add **delivery slots** (day, time window, capacity) per branch under Branches › Slots: once a branch has delivery slots, customers must pick one at checkout |
| Staff | Organization › Employees | Create the account, assign it to branches with **branch roles**; company-wide roles (finance, marketing) are separate |
| Roles | Organization › Roles | Module × action matrix. System roles cannot be deleted; Super Admin is locked |
| Settings | Organization › Settings | Payment window, COD fee, loyalty, auction defaults, contact details, the "Designed by" line of the customer app intro and the **Ask for an alternative phone number** switch (both under General & brand) and the **Let customers return orders** switch (Checkout & fees: off hides "Return order" in the app and the web store and refuses new requests). Secret values are write-only |

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
- **Missing items found while preparing (Adjust items)**: on an order that is *Accepted* or *Preparing*, branch staff (and you, with the right to edit orders for that branch) can reduce, replace or remove items. The order page shows **Item changes**: every revision with who, when, branch, reason, note, each line (before → after), the total before → after and the refund. The customer is told by notification (in-app + push, in their language), sees the change on the order page and gets a **revised invoice** (same number, *REVISED INVOICE · revision N*). The order total never goes up: a replacement costs at most what the original did; delivery and service fees stay; discount and tax follow the units that remain; redeemed loyalty points the smaller order cannot use go back to the customer.
- **Refunds for item changes**: when the order was paid online, the difference becomes a normal refund request (*Items changed by the branch*) under Finance › Refunds; a finance user other than the requester approves and processes it. Nothing is paid out automatically. Cash on delivery / pay at pickup: the amount to collect simply drops. If every item is removed the part (or the whole order) is cancelled with the usual refund.
- **Staff & driver apps — how to change a status** (Settings › Checkout & fees): *Buttons (one tap)* is the default; *Slide to confirm* asks staff and drivers to slide a handle, so a status cannot change by an accidental tap. It applies the next time an app opens or resumes.
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
- **Promotions/coupons**: date windows, usage limits per customer and total, stackable flag. Delivery always costs the zone fee (there is no "free above X" minimum); the only way to waive it is a **Free delivery** promotion with a coupon code, which always requires the code and combines with item discounts. *Applies to*: the **whole basket**, **selected categories** (sub-categories included) or **specific products** — search by name or SKU, or paste a list of product/variant SKUs (up to 200 products; use categories beyond that). Only the targeted lines are discounted, a fixed amount never exceeds their value, and a coupon whose products aren't in the basket tells the customer it isn't eligible. A branch restriction can be combined with any of these.
- **Campaigns**: only customers who gave marketing consent are targeted; schedule and preview before sending.
- **Loyalty & referrals**: earning/redemption rules; manual point adjustments need a reason.
- **Reviews**: approve/reject; answer product questions.
- **Homepage builder** (Content › Homepage builder): the other home sections (categories, products, brands, offers, auctions): reorder, schedule, edit both languages, preview what the app receives.
- **Home banners** (Content › Home banners): the slide banners of the home page, in the app and the web store.
- **Page background** (Content › Page background): the calm picture behind every page of the web store and the customer app, its strength and the page colour. See *Page background* below.
- **Notification templates**: override any message per event, channel and language; use the variables shown.

### Home banners: sliders and slides
The page is a map of the home page, top to bottom. Each **slider** (a row of pictures that slides across the page) is opened up to show its slides; the other home sections appear as thin grey rows so you can see exactly where each slider sits.
- **Add a slider** → choose **where it goes** (at the very top, after a section, or at the bottom), its **name** (only you see it), the **shape** of its pictures (16:9, 21:9, 3:1, 4:3 or 1:1), whether it **slides on its own** and how many seconds per picture (3–15), dots, titles over the pictures, rounded corners, an optional heading, and an optional schedule. A slider is shown only while it is Visible, inside its dates and has at least one live slide.
- **Move a slider** with the up and down arrows on its header: it jumps over one home section each time (an *Undo* appears for a few seconds). A slider at the very top of the page is the first thing customers see on the web store (above the hero) and in the app.
- **Add a slide** (button under the slides) opens a window beside the page: the **English picture** (required) and an **Arabic picture** (optional: without it Arabic customers see the English one), drag a picture onto the box or click it (JPG, PNG or WebP, up to 8 MB; the window shows the recommended size for the slider's shape and warns when a picture would be cropped or is too small). A **live preview** shows the slide as customers see it, in English or Arabic (right to left), light or dark.
- **Where the slide leads**: *No link* (the slide is not tappable: customers just see the picture), *Category* (pick from the real category tree), *Page* (a published page from Content › Pages), *Product* (search by name or code), *Brand*, *Auction*, *Web address* (must start with https://; other kinds of address are refused) or *Search text* (customers land on the search results). The window says in plain words what the slide opens. If the target is later deleted or hidden, the slide shows a red **Broken link** chip and customers see the picture without a link until you choose a new target.
- **Order the slides** with the arrows on each slide or by dragging its handle. **Hide/show**, **duplicate** (the copy is hidden until you publish it) and **delete** (with *Undo*; a deleted slide or slider can be restored for a week) are on each row. Slides can have a start and end date, and can be limited to the apps or the website. A slider holds up to 20 slides.
- **Preview** (play button on the slider) plays exactly what customers see now, on a phone, tablet or desktop width.
- **Other places** (below the map): banners for category pages and the auctions area, managed the same way (they have no slider settings). *Old banners not in any slider* are listed there too: edit one and choose where it shows.
- Needs the **Content** permissions: *view* sees the map and the preview; *create*, *edit* and *delete* unlock the matching buttons. Every change is recorded in the audit log.

### Page background
**Content › Page background** sets the picture behind every page of the web store and the customer app. *View* of the Content permissions lets you look, *edit* lets you change.
- **Picture**: *Qatar skyline* (the Doha towers drawn in one soft colour; the default), *My picture* (JPG, PNG or WebP, up to 4 MB and at least 600 px wide; a wide picture works best) or *None* (the page colour only). **Replace** swaps the file when you save. **Remove picture** deletes it for good (it asks first) and the background goes back to the skyline.
- **How the picture sits** (*My picture* only): *On the bottom* (full width, standing on the bottom edge) or *Fill the page*.
- **Strength** from 2 to 50 %: low is calm and keeps text easy to read.
- **Page colour** replaces the page colour in light mode (dark mode keeps its own dark colour). It is refused when the text would be hard to read (contrast below 4.5 : 1 against the look's text colour); the page tells you before you save. **Skyline colour** tints the skyline. **Use theme colour** on either goes back to following the current look, event looks included.
- The **live preview** (a phone and a web page, light or dark) follows every change at once. Nothing reaches customers until you press **Save changes**, which stays off until something changed. **Back to default** (asks first) brings back the skyline, the original strength and the theme colours; a picture you uploaded stays under *My picture*.
- Customers see a saved change within a minute. Cards, bars and menus keep their own solid colour, so text never sits on the picture. Every save and every removed picture is in the audit log.

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
| الإعدادات | المؤسسة › الإعدادات | مهلة الدفع، رسوم الدفع عند الاستلام، الولاء، إعدادات المزادات، وزر **طلب رقم هاتف بديل** (عام والعلامة: عند الإيقاف يتوقف التطبيق والمتجر عن طلبه وتُخفى الأرقام في لوحة التحكم وشاشات السائقين والموظفين وتبقى محفوظة). القيم السرية للكتابة فقط |

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
- **نقص الأصناف أثناء التجهيز (تعديل الأصناف)**: في طلب *مقبول* أو *قيد التجهيز* يستطيع موظفو الفرع (وأنت، إن كان لك حق تعديل الطلبات لذلك الفرع) تقليل الأصناف أو استبدالها أو إزالتها. تعرض صفحة الطلب **تعديلات الأصناف**: كل تعديل مع من أجراه ومتى والفرع والسبب والملاحظة وكل صنف (قبل ← بعد) والإجمالي قبل ← بعد والاسترداد. يُبلَّغ العميل بإشعار (داخل التطبيق ودفع، بلغته) ويرى التغيير في صفحة الطلب ويحصل على **فاتورة معدّلة** (الرقم نفسه، *فاتورة معدّلة · التعديل N*). لا يرتفع إجمالي الطلب أبداً: البديل لا يكلّف أكثر من الأصلي؛ تبقى رسوم التوصيل والخدمة؛ ويتبع الخصم والضريبة الوحدات المتبقية؛ وتُعاد للعميل نقاط الولاء المستبدلة التي لا يستوعبها الطلب الأصغر.
- **استرداد تعديل الأصناف**: إذا كان الطلب مدفوعاً إلكترونياً يتحول الفرق إلى طلب استرداد عادي (*تعديل الأصناف من الفرع*) في المالية ‹ المبالغ المستردة؛ يعتمده ويعالجه مستخدم مالي غير مقدّم الطلب. لا يُصرف أي مبلغ تلقائياً. الدفع عند الاستلام أو في الفرع: ينخفض المبلغ المطلوب تحصيله فقط. وإذا أُزيلت كل الأصناف تُلغى الحصة (أو الطلب كله) مع الاسترداد المعتاد.
- **تطبيقات الموظفين والسائقين — طريقة تغيير الحالة** (الإعدادات ‹ الدفع والرسوم): *أزرار (نقرة واحدة)* هو الافتراضي؛ و*اسحب للتأكيد* يطلب من الموظف أو السائق سحب مقبض فلا تتغير الحالة بنقرة خاطئة. يسري عند فتح التطبيق أو استئنافه في المرة القادمة.
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
- **العروض والقسائم**: فترات زمنية، حدود استخدام لكل عميل وإجمالية، وخيار الدمج. التوصيل المجاني يُدمج دائماً. *ينطبق على*: **السلة بالكامل** أو **فئات محددة** (مع فئاتها الفرعية) أو **منتجات محددة** — ابحث بالاسم أو الرمز SKU أو الصق قائمة رموز المنتجات/المتغيرات (حتى 200 منتج؛ استخدم الفئات لما يزيد). تُخصم القيمة من السطور المستهدفة فقط، ولا يتجاوز الخصم الثابت قيمتها، وإذا لم تكن منتجات القسيمة في السلة يُبلَغ العميل بأنها غير مؤهلة. ويمكن دمج تقييد الفرع مع أي منها.
- **الحملات**: تستهدف فقط العملاء الموافقين على التسويق؛ جدولة ومعاينة قبل الإرسال.
- **الولاء والإحالات**: قواعد الكسب والاستبدال؛ التعديل اليدوي للنقاط يتطلب سبباً.
- **التقييمات**: قبول/رفض والرد على أسئلة المنتجات.
- **منسّق الصفحة الرئيسية** (المحتوى ‹ منسّق الصفحة الرئيسية): بقية أقسام الرئيسية (الفئات والمنتجات والعلامات والعروض والمزادات): رتّبها وجدولها وعدّل باللغتين وعاين ما يستقبله التطبيق.
- **بانرات الصفحة الرئيسية** (المحتوى ‹ بانرات الصفحة الرئيسية): بانرات الشرائح في الرئيسية، في التطبيق وفي المتجر الإلكتروني.
  - الصفحة خريطة للرئيسية من الأعلى إلى الأسفل، وكل **عارض شرائح** مفتوح ليُظهر شرائحه، وبقية الأقسام صفوف رمادية رفيعة لترى مكان كل عارض بدقة.
  - **إضافة عارض**: اختر مكانه (في الأعلى أو بعد قسم أو في الأسفل) واسمه (تراه أنت فقط) وشكل الصور (16:9 أو 21:9 أو 3:1 أو 4:3 أو 1:1) والتحرك التلقائي وعدد الثواني (3–15) والنقاط والعناوين فوق الصور والزوايا المستديرة وجدولاً اختيارياً. ينقل السهمان الأعلى والأسفل العارض قسماً واحداً في كل مرة (مع «تراجع»).
  - **إضافة شريحة**: صورة إنجليزية (مطلوبة) وصورة عربية (اختيارية)، اسحب الصورة إلى المربع أو اضغط عليه. تظهر **معاينة حية** باللغتين (يمين لليسار) وبالسمة الفاتحة والداكنة.
  - **وجهة الشريحة**: بدون رابط (الشريحة غير قابلة للضغط)، قسم، صفحة، منتج، علامة تجارية، مزاد، عنوان ويب (يبدأ بـ https:// فقط) أو نص بحث. إن حُذفت الوجهة لاحقاً تظهر علامة حمراء «رابط معطّل» ويرى العملاء الصورة بدون رابط.
  - رتّب الشرائح بالأسهم أو بسحب المقبض، وأخفِ/أظهر، وكرّر (النسخة مخفية حتى تنشرها)، واحذف (مع «تراجع»، ويمكن استعادة المحذوف خلال أسبوع). يتسع العارض لما يصل إلى 20 شريحة.
  - **أماكن أخرى**: بانرات صفحات الفئات ومنطقة المزادات بالطريقة نفسها. تحتاج صلاحيات **المحتوى**.
- **خلفية الصفحات** (المحتوى ‹ خلفية الصفحات): الصورة الهادئة خلف كل صفحة في المتجر الإلكتروني وتطبيق العملاء. يكفي «عرض» المحتوى للاطلاع و«تعديل» للتغيير.
  - **الصورة**: *أفق قطر* (أبراج الدوحة بلون واحد ناعم، وهو الأصل) أو *صورتي* (JPG أو PNG أو WebP حتى 4 ميغابايت وبعرض 600 بكسل على الأقل) أو *بدون* (لون الصفحة فقط). **استبدال الصورة** يُطبَّق عند الحفظ، و**حذف الصورة** يمحو الملف نهائياً (بعد تأكيد) وتعود الخلفية إلى أفق قطر.
  - **مكان الصورة** (لـ«صورتي» فقط): في الأسفل بعرض الصفحة، أو تغطية الصفحة. **القوة** من 2 إلى 50٪: القوة المنخفضة هادئة وتُبقي النص سهل القراءة.
  - **لون الصفحة** يحل محل لون الصفحة في الوضع الفاتح (الداكن يحتفظ بلونه). يُرفض اللون إن صعبت قراءة النص عليه (تباين أقل من 4.5 : 1 مع لون نص المظهر)، وتنبهك الصفحة قبل الحفظ. **لون الأفق** يلوّن الأفق، و**استخدام لون المظهر** يعيد اللون إلى لون المظهر الحالي.
  - **المعاينة المباشرة** (جوال وصفحة ويب، فاتح وداكن) تتبع كل تغيير فوراً، ولا يصل شيء للعملاء قبل **حفظ التغييرات** (معطّل حتى يتغير شيء). **العودة إلى الافتراضي** (بعد تأكيد) يعيد الأفق والقوة الأصلية وألوان المظهر وتبقى صورتك ضمن «صورتي». يرى العملاء التغيير خلال دقيقة، وتحتفظ البطاقات والأشرطة بلونها الصلب فلا يظهر النص فوق الصورة. كل حفظ وحذف للصورة يُسجَّل في سجل التدقيق.
- **قوالب الإشعارات**: خصّص أي رسالة لكل حدث وقناة ولغة باستخدام المتغيرات الظاهرة.

## ٩. التقارير والتصدير
تعرض **التقارير** ما يسمح به دورك فقط وضمن فروعك. التصديرات الكبيرة تعمل في الخلفية وتُستلم من **التصديرات** (الروابط مؤقتة).
