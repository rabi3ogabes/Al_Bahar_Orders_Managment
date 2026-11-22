import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/ops/driver/driver_outbox.dart';
import 'package:albahar_customer/ops/driver/driver_strings.dart';
import 'package:albahar_customer/ops/ops_strings.dart';

/// English / Arabic words of the driver's outbox (updates pressed without signal, sent later) and of the lists shown from the phone. Short and plain:
/// the driver reads them at a door with one hand free.
extension OutboxStrings on OpsStrings {
  String _o(String en, String arText) => ar ? arText : en;

  /// "1 update waiting to send", "2 updates waiting to send".
  String outboxWaiting(int n) {
    if (!ar) return n == 1 ? '1 update waiting to send' : '$n updates waiting to send';
    return switch (n) {
      1 => 'تحديث واحد بانتظار الإرسال',
      2 => 'تحديثان بانتظار الإرسال',
      >= 3 && <= 10 => '$n تحديثات بانتظار الإرسال',
      _ => '$n تحديثاً بانتظار الإرسال',
    };
  }

  /// "1 update was not accepted", for the banner when the server refused something.
  String outboxRefused(int n) {
    if (!ar) return n == 1 ? '1 update was not accepted' : '$n updates were not accepted';
    return switch (n) {
      1 => 'لم يُقبل تحديث واحد',
      2 => 'لم يُقبل تحديثان',
      >= 3 && <= 10 => 'لم تُقبل $n تحديثات',
      _ => 'لم يُقبل $n تحديثاً',
    };
  }

  String get outboxTitle => _o('Updates waiting to send', 'تحديثات بانتظار الإرسال');
  String get outboxHint => _o(
    'They go by themselves when the signal is back, in the order you pressed them. Each is recorded at the time you pressed.',
    'تُرسل تلقائياً عند عودة الإشارة، بالترتيب الذي ضغطتها به. يُسجَّل كل منها بالوقت الذي ضغطت فيه.',
  );
  String get outboxAllSent => _o('Everything has been sent.', 'تم إرسال كل شيء.');
  String get outboxOpen => _o('See updates', 'عرض التحديثات');
  String get sendNow => _o('Send now', 'أرسل الآن');
  String get sendingNow => _o('Sending…', 'جارٍ الإرسال…');
  String get waitingToSend => _o('Waiting to send', 'بانتظار الإرسال');
  String get notSent => _o('Not sent', 'لم يُرسل');
  String get discardUpdate => _o('Discard', 'حذف');
  String get callTheBranch => _o('Call the branch', 'اتصل بالفرع');
  String get discardUpdateQ => _o('Discard this update?', 'حذف هذا التحديث؟');
  String get discardUpdateBody =>
      _o('It will not be sent. Tell the branch what happened at the door.', 'لن يُرسل. أخبر الفرع بما حدث عند الباب.');
  String get waitsForAbove => _o('Waits for the update above.', 'ينتظر التحديث الذي قبله.');
  String get signInToSend =>
      _o('Your session ended. Sign in again and it is sent.', 'انتهت جلستك. سجّل الدخول مرة أخرى ليُرسل.');
  String get whatToDo => _o('Discard it, try again, or call the branch.', 'احذفه أو حاول مرة أخرى أو اتصل بالفرع.');
  String get photoLost => _o(
    'The photo of this update was lost. Discard it and do the step again.',
    'فُقدت صورة هذا التحديث. احذفه ثم كرّر الخطوة.',
  );

  /// The snack after a step that could not be sent at once.
  String get savedForLater => _o(
    'No signal. Saved on the phone: it is sent by itself when the signal is back.',
    'لا توجد إشارة. حُفظ على الهاتف وسيُرسل تلقائياً عند عودة الإشارة.',
  );

  /// The red strip across the top while the phone has no network.
  String get offlineSaved => _o(
    'No connection. Your steps are saved and sent when the signal is back.',
    'لا يوجد اتصال. تُحفظ خطواتك وتُرسل عند عودة الإشارة.',
  );

  /// What a waiting update is, in the driver's words.
  String outboxStep(String action) => switch (action) {
    'accept' => _o('Accepted', 'قبول الطلب'),
    'pickup' => pickedUp,
    'out-for-delivery' => _o('On the way', 'في الطريق'),
    'deliver' => _o('Delivered', 'تم التسليم'),
    'fail' => _o('Could not deliver', 'تعذّر التسليم'),
    'assembly' => _o('Assembly answer', 'إجابة التركيب'),
    _ => action,
  };

  /// "Pressed at 2:05 PM".
  String pressedAt(DateTime at) =>
      _o('Pressed at ${QatarTime.time(at, code)}', 'ضُغط الساعة ${QatarTime.time(at, code)}');

  /// "Last updated 2:05 PM": the list is the one the phone kept.
  String lastUpdated(DateTime at) =>
      _o('Last updated ${QatarTime.time(at, code)}', 'آخر تحديث ${QatarTime.time(at, code)}');

  /// Why the server did not take an update, in plain words (the server's own sentence when the app has none of its own).
  String refusalText(OutboxItem item) {
    final code = item.errorCode;
    if (code == 'not_sent') return photoLost;
    final known = switch (code) {
      'delivery_cancelled' => noticeCancelledBody,
      'delivery_not_yours' ||
      'branch_forbidden' => _o('This job was given to another driver.', 'أُعطي هذا الطلب لسائق آخر.'),
      'invalid_state_transition' => _o(
        'The branch already moved this job on, so this step is no longer possible.',
        'نقل الفرع هذا الطلب إلى مرحلة أخرى، لذلك لم تعد هذه الخطوة ممكنة.',
      ),
      'delivery_proof_required' => photoRequired,
      'assembly_answer_required' => assemblyAnswerFirst,
      'cod_collection_required' => _o('The cash collected was missing.', 'المبلغ المحصّل غير موجود.'),
      'cancellation_pending' => _o(
        'The customer asked to cancel this order. Wait for the branch to decide.',
        'طلب العميل إلغاء هذا الطلب. انتظر قرار الفرع.',
      ),
      _ => null,
    };
    final message = item.errorMessage?.trim() ?? '';
    return known ?? (message.isNotEmpty && message.length <= 220 ? message : errorFallback);
  }

  // ── Signing out with updates waiting ────────────────────────────────────────────────────────────────────────────────
  String outboxSignOutQ(int n) {
    if (!ar) {
      return n == 1 ? '1 update has not been sent. Send it first?' : '$n updates have not been sent. Send them first?';
    }
    return switch (n) {
      1 => 'لم يُرسل تحديث واحد. هل ترسله أولاً؟',
      2 => 'لم يُرسل تحديثان. هل ترسلهما أولاً؟',
      >= 3 && <= 10 => 'لم تُرسل $n تحديثات. هل ترسلها أولاً؟',
      _ => 'لم يُرسل $n تحديثاً. هل ترسلها أولاً؟',
    };
  }

  String get outboxSignOutBody => _o(
    'If you sign out now they are lost, and the branch does not see what you did.',
    'إذا سجّلت الخروج الآن ستضيع، ولن يرى الفرع ما قمت به.',
  );
  String get sendFirst => _o('Send first', 'أرسل أولاً');
  String get signOutAnyway => _o('Sign out anyway', 'تسجيل الخروج على أي حال');
  String get stillNotSent => _o(
    'Some updates could not be sent yet. Try again when the signal is better.',
    'تعذّر إرسال بعض التحديثات بعد. حاول مرة أخرى عندما تتحسن الإشارة.',
  );
}
