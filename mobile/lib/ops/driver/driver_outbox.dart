import 'dart:async';
import 'dart:convert';

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Where an update stands in the outbox.
enum OutboxState {
  /// Saved on the phone, sent as soon as the signal allows.
  waiting,

  /// The server said no (the job was given to someone else, the order was cancelled, a photo is missing ...): this job's later updates wait until
  /// the driver discards it or tries again. The other jobs keep going.
  refused,

  /// The session ended: everything waits until the driver signs in again.
  signIn,
}

/// One step the driver pressed (accept, picked up, on the way, delivered, could not deliver, the assembly answer), kept on the phone until the server
/// has it. [key] is made when he pressed and goes with every try as the `Idempotency-Key`, so a try whose answer was lost is never recorded twice;
/// [actedAt] is when he pressed (sent as `acted_at`). Nothing secret is kept: no token, only what the step itself sends. The photo is a copy in the app's
/// private files, deleted once the update is sent or discarded.
@immutable
class OutboxItem {
  const OutboxItem({
    required this.key,
    required this.ownerId,
    required this.deliveryId,
    required this.orderNumber,
    required this.action,
    required this.actedAt,
    this.fields = const {},
    this.photoPath,
    this.sendTime = true,
    this.attempts = 0,
    this.nextTryAt,
    this.state = OutboxState.waiting,
    this.errorCode,
    this.errorMessage,
    this.branchPhone,
  });

  factory OutboxItem.fromJson(JsonMap j) => OutboxItem(
    key: asString(j['key']),
    ownerId: asString(j['owner_id']),
    deliveryId: asString(j['delivery_id']),
    orderNumber: asString(j['order_number']),
    action: asString(j['action']),
    actedAt: asDate(j['acted_at']) ?? DateTime.now().toUtc(),
    fields: asMap(j['fields']),
    photoPath: asStringOrNull(j['photo_path']),
    sendTime: asBool(j['send_time'], true),
    attempts: asInt(j['attempts']),
    nextTryAt: asDate(j['next_try_at']),
    state: OutboxState.values.asNameMap()[asString(j['state'])] ?? OutboxState.waiting,
    errorCode: asStringOrNull(j['error_code']),
    errorMessage: asStringOrNull(j['error_message']),
    branchPhone: asStringOrNull(j['branch_phone']),
  );

  /// The steps the outbox may hold (the server's action names).
  static const actions = {'accept', 'pickup', 'out-for-delivery', 'deliver', 'fail', 'assembly'};

  final String key;

  /// The driver who pressed: an update is only ever sent with his own session.
  final String ownerId;
  final String deliveryId;
  final String orderNumber;
  final String action;

  /// When the driver pressed (UTC).
  final DateTime actedAt;

  /// What the step sends besides the photo: recipient, cash, position, reason, note, assembly answer.
  final JsonMap fields;
  final String? photoPath;

  /// False once the server said the phone's clock is ahead: the step is then sent without its time (the server records when it arrives).
  final bool sendTime;
  final int attempts;
  final DateTime? nextTryAt;
  final OutboxState state;
  final String? errorCode;

  /// The server's own plain sentence for a refusal (already in the driver's language when it was refused).
  final String? errorMessage;

  /// The branch's number, for "Call the branch" on a refusal.
  final String? branchPhone;

  /// The job's status once this step is recorded (the assembly answer does not move the job).
  String? get becomes => switch (action) {
    'accept' => 'accepted',
    'pickup' => 'picked_up',
    'out-for-delivery' => 'out_for_delivery',
    'deliver' => 'delivered',
    'fail' => 'failed',
    _ => null,
  };

  OutboxItem copyWith({
    bool? sendTime,
    int? attempts,
    DateTime? nextTryAt,
    bool clearNextTry = false,
    OutboxState? state,
    String? errorCode,
    String? errorMessage,
    bool clearError = false,
  }) => OutboxItem(
    key: key,
    ownerId: ownerId,
    deliveryId: deliveryId,
    orderNumber: orderNumber,
    action: action,
    actedAt: actedAt,
    fields: fields,
    photoPath: photoPath,
    sendTime: sendTime ?? this.sendTime,
    attempts: attempts ?? this.attempts,
    nextTryAt: clearNextTry ? null : (nextTryAt ?? this.nextTryAt),
    state: state ?? this.state,
    errorCode: clearError ? null : (errorCode ?? this.errorCode),
    errorMessage: clearError ? null : (errorMessage ?? this.errorMessage),
    branchPhone: branchPhone,
  );

  JsonMap toJson() => {
    'key': key,
    'owner_id': ownerId,
    'delivery_id': deliveryId,
    'order_number': orderNumber,
    'action': action,
    'acted_at': actedAt.toUtc().toIso8601String(),
    'fields': fields,
    'photo_path': ?photoPath,
    'send_time': sendTime,
    'attempts': attempts,
    'next_try_at': ?nextTryAt?.toUtc().toIso8601String(),
    'state': state.name,
    'error_code': ?errorCode,
    'error_message': ?errorMessage,
    'branch_phone': ?branchPhone,
  };
}

/// Where the outbox is kept so that it survives the app being closed and the phone restarting.
abstract class OutboxStore {
  Future<List<OutboxItem>> load();
  Future<void> save(List<OutboxItem> items);
}

/// The phone's own storage (the same preferences file the apps already use). One JSON list; an unreadable entry is dropped, never a crash.
class PrefsOutboxStore implements OutboxStore {
  PrefsOutboxStore(this._prefs);
  final SharedPreferences _prefs;
  static const storageKey = 'ops.driver.outbox.v1';

  @override
  Future<List<OutboxItem>> load() async => decodeOutbox(_prefs.getString(storageKey));

  @override
  Future<void> save(List<OutboxItem> items) async {
    if (items.isEmpty) {
      await _prefs.remove(storageKey);
    } else {
      await _prefs.setString(storageKey, encodeOutbox(items));
    }
  }
}

/// Kept in memory only, as JSON (so what comes back is what a restart would read): used where the phone storage is not available.
class MemoryOutboxStore implements OutboxStore {
  String? raw;

  @override
  Future<List<OutboxItem>> load() async => decodeOutbox(raw);

  @override
  Future<void> save(List<OutboxItem> items) async => raw = items.isEmpty ? null : encodeOutbox(items);
}

String encodeOutbox(List<OutboxItem> items) => jsonEncode([for (final i in items) i.toJson()]);

List<OutboxItem> decodeOutbox(String? raw) {
  if (raw == null || raw.isEmpty) return const [];
  try {
    final list = jsonDecode(raw);
    if (list is! List) return const [];
    return [
      for (final e in list)
        if (e is Map) OutboxItem.fromJson(asMap(e)),
    ].where((i) => i.key.isNotEmpty && i.deliveryId.isNotEmpty && OutboxItem.actions.contains(i.action)).toList();
  } catch (_) {
    return const [];
  }
}

/// How a fresh press ended.
enum OutboxSubmit {
  /// The server has it.
  sent,

  /// No signal (or the server could not answer, or an earlier update of the same job is still waiting): saved, sent automatically later.
  queued,
}

/// Sends one update to the server (the repository call); throws what the call throws.
typedef OutboxSender = Future<void> Function(OutboxItem item);

/// The driver app's outbox: the steps pressed without signal, sent later, strictly in the order pressed per job, one at a time, never twice.
///
///  * Every update is written to the [store] before it is tried, so an app kill in the middle loses nothing.
///  * A try that fails for lack of signal, a timeout or a server fault keeps the update; the next try waits [backoff] (5 s doubling up to 5 min).
///    The same key goes with every try.
///  * A refusal (4xx) stops only that job's updates and keeps the server's reason for the driver; a 401 keeps everything until he signs in again.
///  * "The phone's clock is ahead" (`acted_at_in_future`) is answered by sending the same update again without its time.
class DriverOutbox {
  DriverOutbox({
    required this.store,
    required this.send,
    Future<void> Function(String path)? deletePhoto,
    DateTime Function()? now,
    this.onChanged,
    this.onSent,
  }) : _deletePhoto = deletePhoto ?? ((_) async {}),
       _now = now ?? DateTime.now;

  /// Where the list is written after every change.
  final OutboxStore store;

  /// The repository call that sends one update.
  final OutboxSender send;
  final Future<void> Function(String path) _deletePhoto;
  final DateTime Function() _now;

  /// Told after every change of the list (the screens redraw).
  final void Function(List<OutboxItem> items)? onChanged;

  /// Told after an update reached the server (the job lists are read again).
  final void Function(OutboxItem item)? onSent;

  List<OutboxItem> _items = const [];
  bool _flushing = false;
  Future<void>? _round;
  bool _loaded = false;

  /// The updates in the order the driver pressed them.
  List<OutboxItem> get items => _items;

  /// Nothing is sent while the session is gone (a 401): the driver signs in again first.
  bool get waitingForSignIn => _items.any((i) => i.state == OutboxState.signIn);

  /// The earliest moment a waiting update may be tried again (null: nothing waits for a timer).
  DateTime? get nextDue {
    DateTime? due;
    for (final i in _sendable()) {
      final at = i.nextTryAt;
      if (at == null) return _now();
      if (due == null || at.isBefore(due)) due = at;
    }
    return due;
  }

  /// The wait before the next try after [attempts] failed tries: 5 s, 10 s, 20 s ... at most 5 min.
  static Duration backoff(int attempts) {
    final seconds = 5 * (1 << (attempts.clamp(1, 7) - 1));
    return Duration(seconds: seconds.clamp(5, 300));
  }

  /// Reads what was saved (after an app kill or a phone restart). Safe to call more than once.
  Future<void> load() async {
    if (_loaded) return;
    _loaded = true;
    final saved = await store.load();
    // Something pressed while the list was loading stays after what was saved before it.
    _items = [...saved, ..._items.where((i) => !saved.any((s) => s.key == i.key))];
    _changed();
  }

  /// Keeps only [ownerId]'s updates: another person signed in on this phone, and nothing of the first driver is ever sent with his session.
  Future<void> keepOwner(String ownerId) async {
    final others = _items.where((i) => i.ownerId != ownerId).toList();
    if (others.isEmpty) return;
    for (final i in others) {
      await _dropPhoto(i);
    }
    await _write(_items.where((i) => i.ownerId == ownerId).toList());
  }

  /// A fresh press: saved first, then sent at once when nothing of the same job is waiting before it. A refusal of a fresh press is thrown (the
  /// form is still open and shows it, as before) and the update is not kept; no signal keeps it and answers [OutboxSubmit.queued].
  Future<OutboxSubmit> submit(OutboxItem item) async {
    await _write([..._items.where((i) => i.key != item.key), item]);
    if (_flushing || waitingForSignIn || _items.any((i) => i.deliveryId == item.deliveryId && i.key != item.key)) {
      return OutboxSubmit.queued;
    }
    _flushing = true;
    try {
      final outcome = await _try(item, fresh: true);
      return outcome == _Outcome.sent ? OutboxSubmit.sent : OutboxSubmit.queued;
    } finally {
      _flushing = false;
      // Anything pressed meanwhile goes now (one at a time).
      if (_items.any((i) => i.state == OutboxState.waiting)) unawaited(flush());
    }
  }

  /// Sends everything that may go now, one at a time: per job in the order pressed, a job whose first update was refused waits, and a try that
  /// finds no signal ends the round (the timer, the network coming back or the app coming to the front start the next). [force] ignores the wait
  /// between tries (the driver pressed "Send now"). A call while a round runs waits for that round (and, forced, then starts another).
  Future<void> flush({bool force = false}) async {
    final running = _round;
    if (running != null) {
      await running;
      if (force) await flush(force: true);
      return;
    }
    if (_flushing) return; // a fresh press is on its way; it starts a round itself when it is done
    final round = _flushRound(force);
    _round = round;
    try {
      await round;
    } finally {
      if (identical(_round, round)) _round = null;
    }
  }

  Future<void> _flushRound(bool force) async {
    _flushing = true;
    try {
      while (true) {
        final now = _now();
        final next = _sendable().where((i) => force || i.nextTryAt == null || !i.nextTryAt!.isAfter(now)).firstOrNull;
        if (next == null) break;
        final outcome = await _try(next, fresh: false);
        if (outcome == _Outcome.offline || outcome == _Outcome.signIn) break;
      }
    } finally {
      _flushing = false;
    }
  }

  /// The driver signed in again: what waited for it is sent.
  Future<void> resume() async {
    if (!waitingForSignIn) return;
    await _write([
      for (final i in _items)
        i.state == OutboxState.signIn ? i.copyWith(state: OutboxState.waiting, clearNextTry: true) : i,
    ]);
    await flush(force: true);
  }

  /// "Try again" on a refused update: it goes first in its job's line again.
  Future<void> retry(String key) async {
    await _write([
      for (final i in _items)
        i.key == key ? i.copyWith(state: OutboxState.waiting, attempts: 0, clearNextTry: true, clearError: true) : i,
    ]);
    await flush(force: true);
  }

  /// "Discard": the update is dropped (its photo copy deleted) and the job's next update may go.
  Future<void> discard(String key) async {
    final item = _items.where((i) => i.key == key).firstOrNull;
    if (item == null) return;
    await _dropPhoto(item);
    await _write(_items.where((i) => i.key != key).toList());
    unawaited(flush());
  }

  /// Everything is dropped (the driver chose to sign out without sending): the photo copies too.
  Future<void> clear() async {
    for (final i in _items) {
      await _dropPhoto(i);
    }
    await _write(const []);
  }

  /// The updates that may be tried: waiting, and first in their job's line (a refused or signed-out update ahead of them holds them back).
  Iterable<OutboxItem> _sendable() sync* {
    final blocked = <String>{};
    for (final i in _items) {
      if (blocked.contains(i.deliveryId)) continue;
      blocked.add(i.deliveryId);
      if (i.state == OutboxState.waiting) yield i;
    }
  }

  Future<_Outcome> _try(OutboxItem item, {required bool fresh}) async {
    try {
      await send(item);
    } on ApiException catch (e) {
      if (e.code == 'acted_at_in_future' && item.sendTime) {
        // The phone's clock runs ahead: the same update again, without its time (the server then records when it arrives).
        final again = item.copyWith(sendTime: false);
        await _replace(again);
        return _try(again, fresh: fresh);
      }
      if (e.kind == ApiErrorKind.unauthorized) {
        await _write([
          for (final i in _items) i.state == OutboxState.waiting ? i.copyWith(state: OutboxState.signIn) : i,
        ]);
        return _Outcome.signIn;
      }
      if (_transient(e)) {
        final attempts = item.attempts + 1;
        await _replace(item.copyWith(attempts: attempts, nextTryAt: _now().add(e.retryAfter ?? backoff(attempts))));
        return e.isNetwork ? _Outcome.offline : _Outcome.later;
      }
      if (fresh) {
        // The driver is still in the form: he reads the reason there and can correct it, as before. Nothing is kept.
        await _dropPhoto(item);
        await _write(_items.where((i) => i.key != item.key).toList());
        rethrow;
      }
      await _replace(
        item.copyWith(state: OutboxState.refused, errorCode: e.code ?? e.kind.name, errorMessage: e.message),
      );
      return _Outcome.refused;
    } catch (e) {
      if (fresh) {
        await _dropPhoto(item);
        await _write(_items.where((i) => i.key != item.key).toList());
        rethrow;
      }
      // Not an answer from the server (the photo copy is gone, say): the driver decides, like a refusal.
      await _replace(item.copyWith(state: OutboxState.refused, errorCode: 'not_sent'));
      return _Outcome.refused;
    }
    await _dropPhoto(item);
    await _write(_items.where((i) => i.key != item.key).toList());
    onSent?.call(item);
    return _Outcome.sent;
  }

  /// No signal, a timeout, the server's own fault or "too many": worth trying again later. Everything else is the server's answer about the step.
  static bool _transient(ApiException e) =>
      e.isRetryable || e.kind == ApiErrorKind.cancelled || (e.kind == ApiErrorKind.unknown && e.statusCode == null);

  Future<void> _replace(OutboxItem item) => _write([for (final i in _items) i.key == item.key ? item : i]);

  Future<void> _write(List<OutboxItem> items) async {
    _items = List.unmodifiable(items);
    _changed();
    await store.save(_items);
  }

  Future<void> _dropPhoto(OutboxItem item) async {
    final path = item.photoPath;
    if (path == null) return;
    try {
      await _deletePhoto(path);
    } catch (_) {
      // A copy that is already gone is fine.
    }
  }

  void _changed() => onChanged?.call(_items);
}

enum _Outcome { sent, offline, later, refused, signIn }
