import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/ops/ops_staff_models.dart';
import 'package:albahar_customer/ops/ops_strings.dart';

/// The words of the branch staff app's order clock, pickup counter, cancellation requests, needs-attention list, alerts and branch picker,
/// English and Arabic (kept apart from [OpsStrings] so the two staff apps' shared strings stay small).
extension StaffStrings on OpsStrings {
  String _s(String en, String arText) => ar ? arText : en;

  // ── The order clock ──────────────────────────────────────────────────────────────────────────────────────────────

  /// "just now", "12 min", "1 h 05 min", "2 d 3 h" (the counted age).
  String ageText(int minutes) {
    final m = minutes < 0 ? 0 : minutes;
    if (m < 1) return _s('just now', 'الآن');
    if (m < 60) return _s('$m min', '$m د');
    String pad(int n) => n.toString().padLeft(2, '0');
    if (m < 1440) {
      final h = m ~/ 60;
      final r = m % 60;
      return r == 0 ? _s('$h h', '$h س') : _s('$h h ${pad(r)} min', '$h س ${pad(r)} د');
    }
    final d = m ~/ 1440;
    final h = (m % 1440) ~/ 60;
    return h == 0 ? _s('$d d', '$d ي') : _s('$d d $h h', '$d ي $h س');
  }

  /// The age for a screen reader, in full words.
  String ageWords(int minutes) {
    final m = minutes < 0 ? 0 : minutes;
    if (m < 1) return _s('just now', 'الآن');
    if (m < 60) return _minutes(m);
    if (m < 1440) {
      final r = m % 60;
      return [_hours(m ~/ 60), if (r != 0) _minutes(r)].join(_s(' and ', ' و'));
    }
    final h = (m % 1440) ~/ 60;
    return [_days(m ~/ 1440), if (h != 0) _hours(h)].join(_s(' and ', ' و'));
  }

  String _minutes(int n) => _count(n, 'minute', 'دقيقة واحدة', 'دقيقتان', 'دقائق', 'دقيقة');
  String _hours(int n) => _count(n, 'hour', 'ساعة واحدة', 'ساعتان', 'ساعات', 'ساعة');
  String _days(int n) => _count(n, 'day', 'يوم واحد', 'يومان', 'أيام', 'يوماً');

  /// "1 minute" / "5 minutes"; Arabic has its own forms for one, two, three to ten, and eleven or more.
  String _count(int n, String en, String one, String two, String few, String many) {
    if (!ar) return n == 1 ? '1 $en' : '$n ${en}s';
    return switch (n) {
      1 => one,
      2 => two,
      <= 10 => '$n $few',
      _ => '$n $many',
    };
  }

  /// What the age chip says: "Waiting 12 min", "Getting late · 22 min", "Late · 40 min". [kind] `none` (nobody has to act) and `placed` (an older
  /// server's row) are never amber or red and say what they are.
  String clockChip(OrderClockState state, int minutes, {String kind = 'pending'}) {
    final age = ageText(minutes);
    if (kind == 'none' && state == OrderClockState.ok) return _s('In progress · $age', 'قيد المتابعة · $age');
    if (kind == 'placed') return _s('Placed $age ago', 'قبل $age');
    return switch (state) {
      OrderClockState.ok => _s('Waiting $age', 'ينتظر منذ $age'),
      OrderClockState.amber => _s('Getting late · $age', 'يقترب من التأخر · $age'),
      OrderClockState.red => _s('Late · $age', 'متأخر · $age'),
    };
  }

  /// The same for a screen reader.
  String clockSpeech(OrderClockState state, int minutes, {bool paused = false, String kind = 'pending'}) {
    final age = ageWords(minutes);
    final base = kind == 'none' && state == OrderClockState.ok
        ? _s('In progress for $age', 'قيد المتابعة منذ $age')
        : kind == 'placed'
        ? _s('Placed $age ago', 'قبل $age')
        : switch (state) {
            OrderClockState.ok => _s('On time, waiting $age', 'في الوقت، ينتظر منذ $age'),
            OrderClockState.amber => _s('Getting late, waiting $age', 'يقترب من التأخر، ينتظر منذ $age'),
            OrderClockState.red => _s('Late, waiting $age', 'متأخر، ينتظر منذ $age'),
          };
    return paused ? '$base. $clockPaused' : base;
  }

  String get clockPaused => _s('The clock is paused while the branch is closed.', 'الساعة متوقفة أثناء إغلاق الفرع.');

  // ── Orders screen: delivery | pickup ─────────────────────────────────────────────────────────────────────────────

  String get modeDelivery => _s('Delivery', 'توصيل');
  String get modePickup => _s('Pickup', 'استلام');
  String get stagePickupReady => _s('Ready for pickup', 'جاهزة للاستلام');
  String get pickupOrderTag => _s('Pickup', 'استلام من الفرع');
  String get noPickupOrders => _s('No pickup orders right now', 'لا توجد طلبات استلام الآن');
  String get noPickupOrdersHint =>
      _s('New pickup orders appear here as soon as they arrive.', 'تظهر طلبات الاستلام الجديدة هنا فور وصولها.');
  String get loadMore => _s('Load more', 'عرض المزيد');
  String get andMore => _s('and more', 'والمزيد');
  String get loadingMore => _s('Loading…', 'جارٍ التحميل…');
  String newPickupAlert(String number) => _s('New pickup order #$number', 'طلب استلام جديد #$number');
  String modeLabel(String name, int count) => '$name${ar ? '، ' : ', '}${orderCount(count)}';

  // ── Pickup actions ───────────────────────────────────────────────────────────────────────────────────────────────

  String get markPickupReady => _s('Ready for pickup', 'جاهز للاستلام');
  String get scanPass => _s('Scan pass', 'مسح التصريح');
  String get reissuePass => _s('Re-issue pass', 'إصدار تصريح جديد');
  String get handOverWithoutPass => _s('Hand over without the pass', 'تسليم بدون التصريح');
  String get passExpired => _s('The pass has expired', 'انتهت صلاحية التصريح');
  String get passExpiredHint => _s(
    'Give the customer a new pass, or a manager can hand over without it.',
    'أصدر للعميل تصريحاً جديداً، أو يمكن للمدير التسليم بدونه.',
  );
  String passValidUntil(String when) => _s('Pass valid until $when', 'التصريح صالح حتى $when');
  String get partlyHandedOver => _s('Part of this order was handed over', 'سُلِّم جزء من هذا الطلب');
  String amountToCollect(String money) => _s('Collect $money at the counter', 'يُحصَّل $money عند الكاونتر');
  String get reissueQ => _s('Issue a new pass?', 'إصدار تصريح جديد؟');
  String get reissueBody => _s(
    'The old pass stops working at once and the customer is told.',
    'يتوقف التصريح القديم عن العمل فوراً ويُبلَّغ العميل.',
  );
  String get reissueVerb => _s('Issue new pass', 'إصدار تصريح');
  String get passReissued => _s('New pass issued. The customer has been told.', 'تم إصدار تصريح جديد وأُبلِغ العميل.');
  String get pickupIsReady => _s('Ready for pickup. The customer has been told.', 'جاهز للاستلام. أُبلِغ العميل.');

  // ── Scanning and hand-over ───────────────────────────────────────────────────────────────────────────────────────

  String get scanTitle => _s('Scan pickup pass', 'مسح تصريح الاستلام');
  String get scanHint => _s("Point the camera at the customer's QR code.", 'وجّه الكاميرا إلى رمز QR الخاص بالعميل.');
  String get scanCameraStarting => _s('Opening the camera…', 'جارٍ فتح الكاميرا…');
  String get scanCameraDenied => _s(
    'The camera is not allowed. Allow it in the phone settings, or type the code under the QR.',
    'الكاميرا غير مسموح بها. اسمح بها من إعدادات الهاتف، أو اكتب الرمز المكتوب أسفل QR.',
  );
  String get scanCameraUnavailable => _s(
    'The camera cannot be opened. Type the code under the QR instead.',
    'تعذّر فتح الكاميرا. اكتب الرمز المكتوب أسفل QR بدلاً من ذلك.',
  );
  String get openSettings => _s('Open settings', 'فتح الإعدادات');
  String get typeCode => _s('Type the code', 'اكتب الرمز');
  String get passCode => _s('Pass code (under the QR)', 'رمز التصريح (أسفل QR)');
  String get checkCode => _s('Check the code', 'تحقق من الرمز');
  String get scanAnother => _s('Scan another pass', 'مسح تصريح آخر');
  String get checking => _s('Checking the pass…', 'جارٍ التحقق من التصريح…');
  String get torch => _s('Light', 'الإضاءة');
  String get passValid => _s('The pass is valid', 'التصريح صالح');
  String scanRefusal(String result) => switch (result) {
    'expired' => _s(
      'This pass has expired. Give the customer a new pass, or a manager can hand over without it.',
      'انتهت صلاحية هذا التصريح. أصدر للعميل تصريحاً جديداً، أو يمكن للمدير التسليم بدونه.',
    ),
    'already_used' => _s(
      'This pass was already used. Do not hand anything over.',
      'استُخدم هذا التصريح من قبل. لا تسلّم أي شيء.',
    ),
    'wrong_branch' => _s('This pass is for another branch.', 'هذا التصريح لفرع آخر.'),
    'not_ready' => _s('This order is not ready for pickup yet.', 'هذا الطلب ليس جاهزاً للاستلام بعد.'),
    _ => _s(
      'This code is not a valid pass. Do not hand anything over.',
      'هذا الرمز ليس تصريحاً صالحاً. لا تسلّم أي شيء.',
    ),
  };
  String get handOverItems => _s('What to hand over', 'ما يجب تسليمه');
  String leftToHand(int left) => _s('$left left', 'المتبقي $left');
  String alreadyHanded(int n) => _s('$n already handed over', 'سُلِّم منها $n');
  String get nothingSelected => _s('Choose at least one item to hand over.', 'اختر صنفاً واحداً على الأقل للتسليم.');
  String get handOverNeedsApproval => _s(
    'The pass is valid. Handing over needs a manager: ask one to do it.',
    'التصريح صالح. التسليم يحتاج إلى مدير: اطلب من المدير تنفيذه.',
  );
  String get handOverAll => _s('Hand over everything', 'تسليم الكل');
  String get handOverSelected => _s('Hand over selected items', 'تسليم الأصناف المحددة');
  String get toCollect => _s('To collect', 'المطلوب تحصيله');
  String get collectFirst => _s('Collect payment first', 'حصّل المبلغ أولاً');
  String collectAmount(String money) => _s('Collect $money', 'حصّل $money');
  String get tender => _s('Paid with', 'طريقة الدفع');
  String get tenderCash => _s('Cash', 'نقداً');
  String get tenderCard => _s('Card terminal', 'جهاز البطاقة');
  String get receiptReference => _s('Receipt reference (optional)', 'مرجع الإيصال (اختياري)');
  String get handOverDone => _s('Handed over. The order is complete.', 'تم التسليم. اكتمل الطلب.');
  String get handOverPart => _s('Part handed over. The rest stays reserved.', 'سُلِّم جزء. يبقى الباقي محجوزاً.');
  String get customerLabel => _s('Customer', 'العميل');
  String get paymentAtPickup => _s('Pay at pickup', 'الدفع عند الاستلام من الفرع');

  /// Where a pickup order that is not ready yet stands, for the order window.
  String stageLabelPickup(String status) => switch (status) {
    'pending' => _s('Waiting to be accepted', 'بانتظار القبول'),
    'accepted' => _s('Accepted. Not being prepared yet.', 'تم القبول. لم يبدأ التحضير بعد.'),
    'preparing' => _s('Being prepared', 'قيد التحضير'),
    _ => _s('Not ready for pickup yet', 'غير جاهز للاستلام بعد'),
  };

  // ── Hand over without the pass (a manager) ───────────────────────────────────────────────────────────────────────

  String get manualHint => _s(
    'For a manager. Ask the customer for the last 4 digits of the phone number on the order. Everything is recorded with your reason.',
    'للمدير فقط. اطلب من العميل آخر 4 أرقام من رقم الهاتف المسجّل في الطلب. يُسجَّل كل شيء مع السبب الذي تكتبه.',
  );
  String get manualPhone => _s('Last 4 digits of the phone number', 'آخر 4 أرقام من رقم الهاتف');
  String get manualReason => _s('Why is the pass not used?', 'لماذا لا يُستخدم التصريح؟');
  String get manualReasonHint => _s(
    'For example: the pass expired and the customer is at the counter.',
    'مثال: انتهت صلاحية التصريح والعميل عند الكاونتر.',
  );
  String get manualReasonNeeded => _s('Write why the pass is not used.', 'اكتب سبب عدم استخدام التصريح.');
  String get manualDigitsNeeded => _s('Type exactly 4 digits.', 'اكتب 4 أرقام بالضبط.');
  String get manualDone => _s('Handed over. Your reason was recorded.', 'تم التسليم وسُجّل السبب.');
  String get orderLabel => _s('Order', 'الطلب');

  // ── A replacement the customer has not answered ──────────────────────────────────────────────────────────────────

  String get waitingTitle => _s('Waiting for the customer', 'بانتظار رد العميل');
  String waitingCount(int n) => _s(
    n == 1 ? '1 replacement waits for the customer\'s answer' : '$n replacements wait for the customer\'s answer',
    n == 1 ? 'بديل واحد بانتظار رد العميل' : '$n بدائل بانتظار رد العميل',
  );
  String waitingTimeLeft(String time) => _s('$time left', 'متبقٍّ $time');
  String get waitingTimeUp => _s('Time is up', 'انتهى الوقت');
  String get waitingHint => _s(
    'You can keep preparing the rest. This part can be marked ready once the customer answers or the time is up.',
    'يمكنك متابعة تجهيز الباقي. ويمكن تحديد هذا الجزء جاهزاً بعد رد العميل أو انتهاء الوقت.',
  );

  // ── The customer's request to cancel ─────────────────────────────────────────────────────────────────────────────

  String get cancelBannerTitle => _s('The customer asked to cancel', 'طلب العميل إلغاء الطلب');
  String get cancelHold => _s(
    'The customer asked to cancel. A manager must decide before this order leaves the branch.',
    'طلب العميل إلغاء الطلب. يجب أن يقرر المدير قبل أن يغادر الطلب الفرع.',
  );
  String cancelReason(String reason) => _s('Reason: $reason', 'السبب: $reason');
  String get cancelAskManager => _s('Ask a manager to decide.', 'اطلب من المدير أن يقرر.');
  String get cancelOpenToDecide => _s('Open the order to decide.', 'افتح الطلب لتقرر.');
  String get approveCancellation => _s('Approve cancellation', 'الموافقة على الإلغاء');
  String get keepOrder => _s('Keep the order', 'الإبقاء على الطلب');
  String get approveCancelQ => _s('Approve the cancellation?', 'الموافقة على الإلغاء؟');
  String get approveCancelBody => _s(
    'The order is cancelled, its stock is released and the customer is told. This cannot be undone.',
    'يُلغى الطلب ويُحرَّر مخزونه ويُبلَّغ العميل. لا يمكن التراجع عن ذلك.',
  );
  String get keepOrderQ => _s('Keep this order?', 'الإبقاء على هذا الطلب؟');
  String get keepOrderBody => _s(
    'The request is declined and the order goes on. The customer is told. This cannot be undone.',
    'يُرفض الطلب ويستمر تجهيز الطلب ويُبلَّغ العميل. لا يمكن التراجع عن ذلك.',
  );
  String get decisionNote => _s('Note (optional)', 'ملاحظة (اختيارية)');
  String get cancellationApproved =>
      _s('Cancellation approved. The order is cancelled.', 'تمت الموافقة على الإلغاء. أُلغي الطلب.');
  String get cancellationDeclined => _s('Request declined. The order goes on.', 'رُفض الطلب. يستمر تجهيز الطلب.');

  // ── Needs attention ──────────────────────────────────────────────────────────────────────────────────────────────

  String attentionStrip(int n) => _s('Needs attention ($n)', 'تحتاج متابعة ($n)');
  String get attentionAllClear =>
      _s('All clear. Nothing needs you right now.', 'كل شيء على ما يرام. لا شيء يحتاجك الآن.');
  String get attentionTitle => _s('Needs attention', 'تحتاج متابعة');
  String attentionSummary(int late, int soon) {
    final parts = [
      if (late > 0) _s('$late late', '$late متأخر'),
      if (soon > 0) _s('$soon getting late', '$soon يقترب من التأخر'),
    ];
    return parts.join(ar ? '، ' : ', ');
  }

  String get attentionOpenOrder => _s('Open order', 'فتح الطلب');
  String get attentionAssign => _s('Assign driver', 'تعيين سائق');
  String get attentionReissue => _s('Re-issue pass', 'إصدار تصريح جديد');
  String get attentionOnDashboard => _s('On the dashboard', 'على لوحة التحكم');
  String get attentionMore => _s(
    'The most urgent lines are shown. The dashboard has the rest.',
    'تظهر الأكثر إلحاحاً. الباقي على لوحة التحكم.',
  );
  String get attentionUnavailable => _s('Could not read the list. Try again.', 'تعذّر قراءة القائمة. حاول مرة أخرى.');
  String get orderNotInList =>
      _s('That order is not in your list. Pull down to refresh.', 'هذا الطلب ليس في قائمتك. اسحب للأسفل للتحديث.');
  String get orderNoLongerHere => _s('That order is no longer in your queue.', 'هذا الطلب لم يعد في قائمتك.');

  // ── Alerts and the account menu ──────────────────────────────────────────────────────────────────────────────────

  String get soundForNewOrders => _s('Sound for new orders', 'صوت للطلبات الجديدة');
  String get soundForNewOrdersHint => _s(
    'A short chime when a new order arrives while the app is open.',
    'نغمة قصيرة عند وصول طلب جديد والتطبيق مفتوح.',
  );

  // ── Branch picker ────────────────────────────────────────────────────────────────────────────────────────────────

  String get branch => _s('Branch', 'الفرع');
  String get chooseBranch => _s('Choose a branch', 'اختر الفرع');
  String branchPicker(String name) => _s('Branch: $name. Tap to change.', 'الفرع: $name. اضغط للتغيير.');
  String get switchingBranch => _s('Switching branch…', 'جارٍ تغيير الفرع…');

  // ── Connection ───────────────────────────────────────────────────────────────────────────────────────────────────

  String get offlineBanner => _s('You are offline. Showing the last list.', 'أنت غير متصل. تُعرض آخر قائمة.');

  // ── What the server says, in plain words ─────────────────────────────────────────────────────────────────────────

  /// The plain sentence for the answers the branch staff meet at the counter; null for any other (the screen then falls back to the server's
  /// own message, which is already in the app's language).
  String? errorFor(Object error) {
    if (error is! ApiException) return null;
    final minutes = error.context['minutes'];
    final due = error.context['due'];
    return switch (error.code) {
      'order_awaiting_payment' => _s(
        'This order is not paid yet. It can be accepted when the online payment arrives.',
        'لم يُدفع هذا الطلب بعد. يمكن قبوله عند وصول الدفع الإلكتروني.',
      ),
      'cancellation_pending' => cancelHold,
      'invalid_state_transition' => _s(
        'Someone already moved this order. The list was refreshed.',
        'قام زميل بتحريك هذا الطلب بالفعل. تم تحديث القائمة.',
      ),
      'branch_forbidden' => _s(
        'You do not have the right to do this at this branch. Ask a manager.',
        'ليست لديك صلاحية تنفيذ هذا في هذا الفرع. اسأل المدير.',
      ),
      'approval_required' => _s(
        'This needs a manager. Ask a manager to approve it.',
        'يحتاج هذا إلى مدير. اطلب من المدير الموافقة.',
      ),
      'order_partly_handed_over' => _s(
        'Part of this order already left the branch, so it cannot be cancelled as a whole. Ask a manager.',
        'جزء من هذا الطلب غادر الفرع بالفعل، فلا يمكن إلغاؤه كاملاً. اسأل المدير.',
      ),
      'delivery_cancelled' => _s('This delivery was cancelled.', 'تم إلغاء هذا التوصيل.'),
      'pickup_not_ready' => _s(
        'This pass cannot be used. Check the order, or issue a new pass.',
        'لا يمكن استخدام هذا التصريح. راجع الطلب أو أصدر تصريحاً جديداً.',
      ),
      'pickup_payment_required' => _s('Collect the payment before handing over.', 'حصّل المبلغ قبل التسليم.'),
      'pickup_details_mismatch' => _s(
        'These details do not match an order waiting at this branch. Check the order number and the last 4 digits of the phone number.',
        'هذه البيانات لا تطابق طلباً ينتظر الاستلام في هذا الفرع. تأكد من رقم الطلب ومن آخر 4 أرقام من رقم الهاتف.',
      ),
      'pickup_manual_locked' => _s(
        'Too many wrong tries for this order. Try again in ${minutes ?? 15} minutes.',
        'محاولات خاطئة كثيرة لهذا الطلب. حاول مرة أخرى بعد ${minutes ?? 15} دقيقة.',
      ),
      'pickup_reason_required' => manualReasonNeeded,
      'quantity_invalid' => _s('Check the quantities to hand over.', 'راجع الكميات المراد تسليمها.'),
      'stock_would_go_negative' => _s(
        'The stock count does not allow this hand-over. Ask a manager.',
        'عدد المخزون لا يسمح بهذا التسليم. اسأل المدير.',
      ),
      'collection_amount_mismatch' => _s(
        'The amount to collect is ${due ?? 'different'}.',
        'المبلغ المطلوب تحصيله هو ${due ?? 'مختلف'}.',
      ),
      'payment_already_settled' => _s('This order is already paid.', 'هذا الطلب مدفوع بالفعل.'),
      _ when error.kind == ApiErrorKind.notFound => _s(
        'This order was not found. It may have been removed. Pull down to refresh.',
        'لم يُعثر على هذا الطلب. ربما أُزيل. اسحب للأسفل للتحديث.',
      ),
      _ => null,
    };
  }
}
