import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/ops/ops_strings.dart';

/// English / Arabic text of the driver app's newer screens (job context, arrival, handing a job back, the failed-delivery sheet, My day,
/// cancelled-job notices, plain error sentences). It extends [OpsStrings] so a screen reads `OpsStrings.of(context)` as it always did; the
/// Arabic is written for Qatari drivers: short, clear, no word-for-word translation.
extension DriverStrings on OpsStrings {
  String _d(String en, String arText) => ar ? arText : en;

  // ── The job: arrival, before you go, notes, who to call ──────────────────────────────────────────────────────────────
  String get iHaveArrived => _d('I have arrived', 'وصلت');
  String get slideArrived => _d('Slide: I have arrived', 'اسحب: وصلت');
  String get arrivedDone => _d('The customer knows you are here.', 'العميل يعلم أنك وصلت.');
  String get beforeYouGo => _d('Before you go', 'قبل أن تنطلق');
  String get needsTwoPeople => _d('Needs two people', 'يحتاج شخصين');
  String get assemblyRequested => _d('Assembly requested', 'مطلوب تركيب');
  String get oversizedChip => _d('Oversized', 'حجم كبير');
  String cashChip(String amount) => _d('Cash on delivery ${money(amount)}', 'الدفع عند الاستلام ${money(amount)}');
  String get customerNote => _d('Note from the customer', 'ملاحظة من العميل');
  String get doorInstructions => _d('Instructions for the door', 'تعليمات الوصول');
  String get landmark => _d('Landmark', 'معلم قريب');
  String get receivingTheOrder => _d('Receiving the order', 'المستلم');
  String get accountHolder => _d('Account holder', 'صاحب الحساب');
  String callPerson(String name) => _d('Call $name', 'اتصال بـ $name');
  String get callRecipient => _d('Call recipient', 'اتصال بالمستلم');
  String get callAccountHolder => _d('Call account holder', 'اتصال بصاحب الحساب');
  String get today => _d('Today', 'اليوم');
  String get deliveryWindow => _d('Delivery window', 'فترة التوصيل');
  String get yourJobLooksPlain => _d('Nothing special about this one.', 'لا شيء خاص بهذا الطلب.');

  /// "12.5 kg" with the Arabic unit.
  String kg(double v) {
    final text = v == v.roundToDouble() ? v.toStringAsFixed(0) : v.toStringAsFixed(1);
    return _d('$text kg', '$text كجم');
  }

  String totalWeight(double v) => _d('Total weight about ${kg(v)}', 'الوزن الكلي حوالي ${kg(v)}');

  /// "120 × 80 × 40 cm" (digits and the multiplication sign are laid out left to right by the caller).
  String size(double l, double w, double h) {
    String n(double v) => v == v.roundToDouble() ? v.toStringAsFixed(0) : v.toStringAsFixed(1);
    return _d('${n(l)} × ${n(w)} × ${n(h)} cm', '${n(l)} × ${n(w)} × ${n(h)} سم');
  }

  String get lineAssembly => _d('Assembly', 'تركيب');

  // ── Handing a job back ─────────────────────────────────────────────────────────────────────────────────────────────────
  String get cantTakeThis => _d("I can't take this", 'لا أستطيع توصيله');
  String get declineTitle => _d("Can't take this delivery?", 'لا تستطيع توصيل هذا الطلب؟');
  String get declineSubtitle => _d('The branch will give it to another driver.', 'سيسنده الفرع إلى سائق آخر.');
  String declineReason(String code) => switch (code) {
    'too_far' => _d('Too far from me', 'بعيد عني'),
    'vehicle' => _d('My vehicle cannot carry it', 'مركبتي لا تتسع له'),
    'shift_ending' => _d('My shift is ending', 'ورديتي على وشك الانتهاء'),
    _ => _d('Something else', 'سبب آخر'),
  };
  String get declineNoteHint => _d('Add a short note (optional)', 'أضف ملاحظة قصيرة (اختياري)');
  String get declineVerb => _d('Hand it back', 'إرجاع الطلب');
  String get slideDecline => _d('Slide to hand it back', 'اسحب لإرجاع الطلب');
  String get declineDone =>
      _d('Handed back. The branch will give it to another driver.', 'تم الإرجاع. سيسنده الفرع إلى سائق آخر.');
  String get chooseWhyFirst => _d('Choose a reason first.', 'اختر السبب أولاً.');

  // ── Could not deliver ───────────────────────────────────────────────────────────────────────────────────────────────────
  String get failWhat => _d('What happened?', 'ماذا حدث؟');
  String get failNoteLabel => _d('Tell the branch what happened', 'أخبر الفرع بما حدث');
  String get failNoteOptional => _d('Note (optional)', 'ملاحظة (اختياري)');
  String get failNoteRequired =>
      _d('Please write a short note so the branch knows what happened.', 'اكتب ملاحظة قصيرة ليعرف الفرع ما حدث.');
  String get failPhotoHint =>
      _d('A photo of the door or of the damage helps the branch.', 'صورة للباب أو للضرر تساعد الفرع.');
  String get failPhotoAdded => _d('Photo added (tap to take another)', 'تمت إضافة الصورة (اضغط لالتقاط أخرى)');
  String get failVerb => _d('Report: could not deliver', 'تأكيد: تعذّر التسليم');
  String get slideReport => _d('Slide to report', 'اسحب للإبلاغ');
  String get failRecorded => _d(
    'Reported. The branch will call the customer to arrange a new time.',
    'تم الإبلاغ. سيتصل الفرع بالعميل لترتيب موعد جديد.',
  );

  // ── My day ───────────────────────────────────────────────────────────────────────────────────────────────────────────────
  String get myDay => _d('My day', 'يومي');
  String get dayNotDelivered => _d('Not delivered', 'لم تُسلَّم');
  String get dayHandedBack => _d('Handed back', 'أُرجعت');
  String get dayToDo => _d('Still to do', 'متبقية');
  String cashWithYou(String amount) => _d('Cash with you: ${money(amount)}', 'النقد الذي معك: ${money(amount)}');
  String get handItIn => _d('Hand it in at the branch.', 'سلّمه في الفرع.');
  String get cashNone => _d('You hold no cash. Everything is handed in.', 'لا يوجد نقد معك. تم تسليم كل شيء.');
  String get cashAmber =>
      _d('It has been with you a while. Please hand it in soon.', 'مضى وقت على وجوده معك. يرجى تسليمه قريباً.');
  String get cashRed =>
      _d('It is late. Please hand it in at the branch now.', 'تأخر تسليمه. يرجى تسليمه في الفرع الآن.');
  String cashOldest(String when) => _d('Oldest collection: $when', 'أقدم تحصيل: $when');
  String collectedToday(String amount) => _d('Collected today: ${money(amount)}', 'المحصّل اليوم: ${money(amount)}');
  String onDutySince(String time) => _d('On duty since $time', 'متواجد منذ $time');
  String get dayEmptyTitle => _d('Nothing yet today', 'لا شيء حتى الآن اليوم');
  String get dayEmptyHint => _d('Your deliveries for today will show up here.', 'ستظهر هنا توصيلات اليوم.');
  String get dayJobsTitle => _d("Today's deliveries", 'توصيلات اليوم');
  String dayStatus(String status) => status == 'declined' ? dayHandedBack : statusLabel(status);

  /// "You still have 2 jobs": the number of open jobs with the Arabic forms (one, two, few, many).
  String jobsCount(int n) {
    if (!ar) return n == 1 ? '1 job' : '$n jobs';
    return switch (n) {
      1 => 'طلب واحد',
      2 => 'طلبان',
      <= 10 => '$n طلبات',
      _ => '$n طلباً',
    };
  }

  // ── Cancelled and taken-away jobs ────────────────────────────────────────────────────────────────────────────────────
  String noticeCancelledTitle(String number) => _d('Order #$number cancelled', 'أُلغي الطلب #$number');
  String get noticeCancelledBody =>
      _d('This order was cancelled. No need to go.', 'تم إلغاء هذا الطلب. لا داعي للذهاب.');
  String noticeRemovedTitle(String number) => _d('Order #$number moved', 'تغيّر الطلب #$number');
  String get noticeRemovedBody =>
      _d('This order was given to another driver. It is no longer yours.', 'أُسند هذا الطلب إلى سائق آخر. لم يعد لك.');
  String get ok => _d('OK', 'حسناً');

  // ── No signal ────────────────────────────────────────────────────────────────────────────────────────────────────────────
  String get noConnection => _d('No connection. Check your signal.', 'لا يوجد اتصال. تحقق من الإشارة.');

  // ── Signing out ───────────────────────────────────────────────────────────────────────────────────────────────────────────
  /// "Finish it or ask the branch to hand it back." with the right pronoun for one, two or many jobs.
  String finishOrHandBack(int n) {
    if (!ar) {
      return n == 1
          ? 'Finish it or ask the branch to hand it back.'
          : 'Finish them or ask the branch to hand them back.';
    }
    return switch (n) {
      1 => 'أنهِه أو اطلب من الفرع استرجاعه.',
      2 => 'أنهِهما أو اطلب من الفرع استرجاعهما.',
      _ => 'أنهِها أو اطلب من الفرع استرجاعها.',
    };
  }

  String signOutJobsBody(int n) {
    if (!ar) {
      return n == 1
          ? 'You still have 1 job. It stays yours: finish it, or ask the branch to hand it back.'
          : 'You still have ${jobsCount(n)}. They stay yours: finish them, or ask the branch to hand them back.';
    }
    return switch (n) {
      1 => 'ما زال عندك ${jobsCount(n)}. يبقى لك: أنهِه أو اطلب من الفرع استرجاعه.',
      2 => 'ما زال عندك ${jobsCount(n)}. يبقيان لك: أنهِهما أو اطلب من الفرع استرجاعهما.',
      _ => 'ما زال عندك ${jobsCount(n)}. تبقى لك: أنهِها أو اطلب من الفرع استرجاعها.',
    };
  }

  // ── Plain sentences for what the server can answer ────────────────────────────────────────────────────────────────────
  /// The one plain sentence for an error, or null when the app has no better words than the server's own message (which is already in the
  /// app's language). Network and server failures are worded here too, so a driver never reads a status code or a stack of English.
  String? plainError(Object error) {
    if (error is! ApiException) return errorFallback;
    final code = error.code;
    switch (code) {
      case 'cod_collection_required':
        return _d('Collect the cash first, then mark it delivered.', 'حصّل المبلغ نقداً أولاً ثم سجّل التسليم.');
      case 'collection_amount_mismatch':
        final due = error.context['due']?.toString();
        return due == null
            ? _d(
                'The cash is not what is due. Check the amount and try again.',
                'المبلغ لا يطابق المستحق. راجعه وحاول مرة أخرى.',
              )
            : _d('Collect exactly $due.', 'حصّل المبلغ المستحق بالضبط: $due.');
      case 'delivery_cancelled':
        return noticeCancelledBody;
      case 'cancellation_pending':
        return _d(
          'The customer asked to cancel this order. Wait for the branch to decide.',
          'طلب العميل إلغاء هذا الطلب. انتظر قرار الفرع.',
        );
      case 'invalid_state_transition':
        return _d(
          'This step is not possible now. The list was updated.',
          'لا يمكن تنفيذ هذه الخطوة الآن. تم تحديث القائمة.',
        );
      case 'jobs_in_progress':
        final n = int.tryParse('${error.context['count'] ?? ''}') ?? 1;
        return _d(
          'You still have ${jobsCount(n)}. ${finishOrHandBack(n)}',
          'ما زال عندك ${jobsCount(n)}. ${finishOrHandBack(n)}',
        );
      case 'delivery_not_yours':
        return _d('This delivery belongs to another driver.', 'هذا الطلب مع سائق آخر.');
      case 'delivery_proof_required':
        return photoRequired;
      case 'media_invalid':
        return _d('That photo could not be used. Please take it again.', 'تعذّر استخدام الصورة. التقطها مرة أخرى.');
      case 'branch_forbidden':
      case 'driver_not_eligible':
        return _d('You cannot do this for this branch.', 'لا يمكنك تنفيذ هذا لهذا الفرع.');
    }
    if (error.fieldError('notes') != null) return failNoteRequired;
    return switch (error.kind) {
      ApiErrorKind.offline || ApiErrorKind.timeout => _d(
        'No connection. Check your signal and try again.',
        'لا يوجد اتصال. تحقق من الإشارة وحاول مرة أخرى.',
      ),
      ApiErrorKind.notFound => jobGone,
      ApiErrorKind.server => _d(
        'Something went wrong on our side. Try again in a moment.',
        'حدث خلل من طرفنا. حاول بعد قليل.',
      ),
      ApiErrorKind.rateLimited => _d(
        'Too many taps. Wait a moment and try again.',
        'ضغطات كثيرة. انتظر قليلاً ثم حاول.',
      ),
      ApiErrorKind.unauthorized => sessionExpired,
      _ => _plainMessage(error.message) ?? errorFallback,
    };
  }

  /// The server's own sentence, when it is short and plain (its messages are written for staff, in the app's language).
  String? _plainMessage(String? message) {
    final m = message?.trim() ?? '';
    return m.isEmpty || m.length > 220 ? null : m;
  }
}
