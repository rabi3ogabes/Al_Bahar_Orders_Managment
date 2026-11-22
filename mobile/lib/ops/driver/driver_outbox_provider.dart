import 'dart:async';
import 'dart:io';

import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/ops/driver/driver_outbox.dart';
import 'package:albahar_customer/ops/driver/driver_providers.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path_provider/path_provider.dart';

/// Keeps a photo of a waiting update in the app's private files (the camera's own file may be cleared by the phone), and deletes it again.
abstract class OutboxFiles {
  /// A private copy of [photo] for the update [key]; its path.
  Future<String> keep(File photo, String key);

  Future<void> delete(String path);
}

/// The app's support directory, never shared and never backed up to a gallery.
class AppOutboxFiles implements OutboxFiles {
  const AppOutboxFiles();

  @override
  Future<String> keep(File photo, String key) async {
    final dir = Directory('${(await getApplicationSupportDirectory()).path}/outbox');
    await dir.create(recursive: true);
    return (await photo.copy('${dir.path}/$key.jpg')).path;
  }

  @override
  Future<void> delete(String path) async {
    final file = File(path);
    if (path.contains('/outbox/') && await file.exists()) await file.delete();
  }
}

final outboxFilesProvider = Provider<OutboxFiles>((ref) => const AppOutboxFiles());

/// Where the outbox is kept: the phone's preferences, or memory where they are not available.
final driverOutboxStoreProvider = Provider<OutboxStore>((ref) {
  try {
    return PrefsOutboxStore(ref.watch(sharedPreferencesProvider));
  } catch (_) {
    return MemoryOutboxStore();
  }
});

/// The driver app's outbox as the screens see it: the updates in the order pressed. It sends by itself when the network comes back, when the app
/// comes back to the front, after the driver signs in again (OpsApp calls [resumeAfterSignIn]), and on a timer that waits longer after each failed try (see [DriverOutbox]).
class DriverOutboxController extends Notifier<List<OutboxItem>> {
  late DriverOutbox _box;
  Timer? _timer;
  String _owner = '';

  /// Where the last known driver id is remembered, so a step pressed on a cold start without signal still knows whose it is.
  static const ownerKey = 'ops.driver.outbox.owner';

  @override
  List<OutboxItem> build() {
    _box = DriverOutbox(
      store: ref.watch(driverOutboxStoreProvider),
      send: _send,
      deletePhoto: (path) => ref.read(outboxFilesProvider).delete(path),
      onChanged: (items) {
        if (!ref.mounted) return;
        state = items;
        _schedule();
      },
      onSent: _sent,
    );
    _owner = _remembered() ?? '';
    ref.listen<bool>(onlineProvider, (prev, online) {
      if (!online || prev != false) return;
      // The signal is back: what waited goes, and dispatch learns where the driver is now (positions are not queued, see DriverTracker).
      unawaited(flush());
      unawaited(ref.read(driverTrackerProvider).resendLast().catchError((Object _) {}));
    });
    final lifecycle = AppLifecycleListener(onResume: () => unawaited(flush()));
    ref.onDispose(() {
      lifecycle.dispose();
      _timer?.cancel();
    });
    unawaited(_box.load().then((_) => flush()));
    return const [];
  }

  /// The driver now signed in (from his profile): his id is remembered and nothing of another account is ever sent with his session.
  void adoptOwner(String id) {
    if (id.isEmpty || id == _owner) return;
    _owner = id;
    try {
      unawaited(ref.read(sharedPreferencesProvider).setString(ownerKey, id));
    } catch (_) {}
    unawaited(_box.keepOwner(id));
  }

  String? _remembered() {
    try {
      return ref.read(sharedPreferencesProvider).getString(ownerKey);
    } catch (_) {
      return null;
    }
  }

  /// A step the driver pressed on [job]: kept on the phone first, then sent at once when nothing of the same job waits before it. Answers
  /// [OutboxSubmit.sent] when the server has it, [OutboxSubmit.queued] when it waits for the signal; a refusal of a fresh press is thrown (the form
  /// shows it, as before). [key] is the form's own key (the same for every tap of that form); [photo] is copied into the app's private files.
  Future<OutboxSubmit> press(
    DriverJob job,
    String action, {
    required String key,
    Map<String, Object?> fields = const {},
    File? photo,
  }) async {
    String? kept;
    if (photo != null) {
      try {
        kept = await ref.read(outboxFilesProvider).keep(photo, key);
      } catch (_) {
        kept = photo.path; // the camera's own file, rather than losing the step
      }
    }
    final item = OutboxItem(
      key: key,
      ownerId: ref.read(driverMeProvider).value?.id ?? _owner,
      deliveryId: job.id,
      orderNumber: job.orderNumber,
      action: action,
      actedAt: DateTime.now().toUtc(),
      fields: {
        for (final e in fields.entries)
          if (e.value != null) e.key: e.value,
      },
      photoPath: kept,
      branchPhone: job.branchPhone,
    );
    return _box.submit(item);
  }

  /// Sends what may go now (the driver pressed "Send now" when [force]).
  Future<void> flush({bool force = false}) => _box.flush(force: force);

  /// The driver signed in again after his session ended: what waited for it is sent.
  Future<void> resumeAfterSignIn() => _box.resume();

  Future<void> retry(String key) => _box.retry(key);

  Future<void> discard(String key) => _box.discard(key);

  /// The driver signs out without sending: everything is dropped, photos too.
  Future<void> clear() => _box.clear();

  Future<void> _send(OutboxItem i) async {
    final repo = ref.read(opsRepositoryProvider);
    final f = i.fields;
    final at = i.sendTime ? i.actedAt : null;
    final photo = i.photoPath == null ? null : File(i.photoPath!);
    double? number(String name) => (f[name] as num?)?.toDouble();
    String? text(String name) => f[name] as String?;
    switch (i.action) {
      case 'deliver':
        await repo.deliver(
          i.deliveryId,
          recipient: text('recipient'),
          proof: photo,
          codAmount: number('cod'),
          lat: number('lat'),
          lng: number('lng'),
          accuracy: number('accuracy'),
          idempotencyKey: i.key,
          actedAt: at,
        );
      case 'fail':
        await repo.fail(
          i.deliveryId,
          text('reason') ?? 'other',
          notes: text('notes'),
          photo: photo,
          lat: number('lat'),
          lng: number('lng'),
          accuracy: number('accuracy'),
          idempotencyKey: i.key,
          actedAt: at,
        );
      case 'assembly':
        await repo.assembly(
          i.deliveryId,
          text('status') ?? 'done',
          reason: text('reason'),
          note: text('note'),
          photo: photo,
          idempotencyKey: i.key,
          actedAt: at,
        );
      default:
        await repo.jobStep(
          i.deliveryId,
          i.action,
          lat: number('lat'),
          lng: number('lng'),
          idempotencyKey: i.key,
          actedAt: at,
        );
    }
  }

  /// An update reached the server: the job shows its new state at once (the live link's own message then changes nothing) and the lists are read again.
  void _sent(OutboxItem item) {
    if (!ref.mounted) return;
    final becomes = item.becomes;
    if (becomes != null && ref.exists(driverLiveProvider)) {
      ref.read(driverLiveProvider.notifier).applyOwnStep(item.deliveryId, becomes);
    }
    ref
      ..invalidate(driverJobsProvider(false))
      ..invalidate(driverJobsProvider(true));
  }

  void _schedule() {
    _timer?.cancel();
    final due = _box.nextDue;
    if (due == null) return;
    final wait = due.difference(DateTime.now());
    _timer = Timer(wait.isNegative ? Duration.zero : wait, () => unawaited(flush()));
  }
}

final driverOutboxProvider = NotifierProvider<DriverOutboxController, List<OutboxItem>>(DriverOutboxController.new);

/// The updates of one job that wait in the outbox, in the order pressed.
List<OutboxItem> outboxFor(List<OutboxItem> items, String deliveryId) => [
  for (final i in items)
    if (i.deliveryId == deliveryId) i,
];

/// The job as the driver sees it while some of its updates wait: in the state of his last step, with the assembly answer he gave.
DriverJob withOutbox(DriverJob job, List<OutboxItem> waiting) {
  var shown = job;
  for (final i in waiting) {
    final becomes = i.becomes;
    if (becomes != null) shown = shown.withState(status: becomes);
    if (i.action == 'assembly') {
      shown = shown.withAssemblyAnswer(
        (i.fields['status'] as String?) ?? 'done',
        reason: i.fields['reason'] as String?,
        note: i.fields['note'] as String?,
      );
    }
  }
  return shown;
}
