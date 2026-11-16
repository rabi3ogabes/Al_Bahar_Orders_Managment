import 'dart:async';

import 'package:albahar_customer/core/models/paginated.dart';
import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart' show pusherClientProvider;
import 'package:albahar_customer/features/auctions/presentation/state/auction_providers.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/notifications/data/notifications_repository.dart';
import 'package:albahar_customer/features/notifications/domain/notification_models.dart';
import 'package:albahar_customer/features/notifications/presentation/state/notification_live.dart';
import 'package:albahar_customer/features/notifications/presentation/state/notifications_controller.dart';
import 'package:dio/dio.dart';
import 'package:fake_async/fake_async.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../helpers/fakes.dart' show IdlePusher, testConfig;

/// A socket the test opens and closes.
class _Socket extends IdlePusher {
  RealtimeState current = RealtimeState.connected;
  final changes = StreamController<RealtimeState>.broadcast();

  @override
  RealtimeState get state => current;

  @override
  Stream<RealtimeState> get states => changes.stream;

  void set(RealtimeState next) {
    current = next;
    changes.add(next);
  }
}

/// An inbox the server keeps: an unread count and a list, and how often each was read.
class _Inbox extends NotificationsRepository {
  _Inbox() : super(ApiClient(Dio()));

  int unread = 2;
  int countReads = 0;
  int listReads = 0;

  @override
  Future<int> unreadCount() async {
    countReads++;
    return unread;
  }

  @override
  Future<Paginated<AppNotification>> list({int page = 1}) async {
    listReads++;
    return Paginated(
      items: const [],
      currentPage: 1,
      lastPage: 1,
      perPage: 20,
      total: 0,
      extraMeta: {'unread_count': unread},
    );
  }
}

PusherEvent _event(String name, Map<String, dynamic> data) =>
    PusherEvent(channel: 'private-users.u1', event: name, data: data);

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late StreamController<PusherEvent> events;
  late _Socket socket;
  late _Inbox inbox;

  late SharedPreferences prefs;

  ProviderContainer make({bool signedIn = true}) {
    final container = ProviderContainer(
      overrides: [
        appConfigProvider.overrideWithValue(testConfig),
        sharedPreferencesProvider.overrideWithValue(prefs),
        isAuthenticatedProvider.overrideWithValue(signedIn),
        pusherClientProvider.overrideWithValue(socket),
        userAuctionEventsProvider.overrideWith((ref) => events.stream),
        notificationsRepositoryProvider.overrideWithValue(inbox),
      ],
    );
    addTearDown(container.dispose);
    // The inbox screen is open: its list is alive.
    container.listen(notificationsProvider, (_, _) {});
    container.listen(unreadCountProvider, (_, _) {});
    container.listen(notificationLiveProvider, (_, _) {});
    return container;
  }

  setUp(() async {
    SharedPreferences.setMockInitialValues({'locale': 'en'});
    prefs = await SharedPreferences.getInstance();
    events = StreamController<PusherEvent>.broadcast();
    socket = _Socket();
    inbox = _Inbox();
    addTearDown(events.close);
  });

  test(
    'a new notification moves the badge at once (the message carries the count), then the list is read once',
    () async {
      final c = make();
      await Future<void>.delayed(const Duration(milliseconds: 50));
      final countReads = inbox.countReads;
      final listReads = inbox.listReads;

      events.add(
        _event('notification.created', {'notification_id': 'n1', 'unread_count': 7, 'title': 'Order confirmed'}),
      );
      await Future<void>.delayed(const Duration(milliseconds: 20));
      expect(c.read(unreadCountProvider), 7, reason: 'at once, before any read');

      inbox.unread = 7;
      await Future<void>.delayed(const Duration(milliseconds: 800)); // the burst is over: one read confirms it
      expect(inbox.countReads, countReads + 1);
      expect(inbox.listReads, listReads + 1);
      expect(c.read(unreadCountProvider), 7);
    },
  );

  test('a burst of notifications is confirmed by one read, not one each', () async {
    make();
    await Future<void>.delayed(const Duration(milliseconds: 50));
    final countReads = inbox.countReads;

    for (var i = 0; i < 5; i++) {
      events.add(_event('notification.created', {'unread_count': 3 + i}));
      await Future<void>.delayed(const Duration(milliseconds: 30));
    }
    await Future<void>.delayed(const Duration(milliseconds: 800));
    expect(inbox.countReads, countReads + 1);
  });

  test(
    'other messages on the channel (orders, auctions) and a message without a count change nothing about the badge',
    () async {
      final c = make();
      await Future<void>.delayed(const Duration(milliseconds: 50));
      final reads = inbox.countReads;
      final badge = c.read(unreadCountProvider);

      events.add(_event('order.updated', {'order_id': 'o1', 'unread_count': 99}));
      events.add(_event('auction.outbid', {'unread_count': 99}));
      await Future<void>.delayed(const Duration(milliseconds: 800));
      expect(c.read(unreadCountProvider), badge);
      expect(inbox.countReads, reads);

      events.add(_event('notification.created', {'title': 'No count here'}));
      await Future<void>.delayed(const Duration(milliseconds: 20));
      expect(c.read(unreadCountProvider), badge, reason: 'no count in the message: the read after the burst tells');
    },
  );

  test('a signed-out phone listens to nothing', () async {
    final c = make(signedIn: false);
    await Future<void>.delayed(const Duration(milliseconds: 50));
    final badge = c.read(unreadCountProvider);
    final reads = inbox.countReads;

    events.add(_event('notification.created', {'unread_count': 9}));
    await Future<void>.delayed(const Duration(milliseconds: 800));
    expect(c.read(unreadCountProvider), badge);
    expect(inbox.countReads, reads);
  });

  test('a socket that came back reads what was missed while it was down', () async {
    final c = make();
    await Future<void>.delayed(const Duration(milliseconds: 50));
    socket.set(RealtimeState.reconnecting);
    await Future<void>.delayed(const Duration(milliseconds: 20));
    final reads = inbox.countReads;
    final lists = inbox.listReads;

    inbox.unread = 5; // arrived while the socket was down
    socket.set(RealtimeState.connected);
    await Future<void>.delayed(const Duration(milliseconds: 100));
    expect(inbox.countReads, reads + 1);
    expect(c.read(unreadCountProvider), 5);
    expect(inbox.listReads, lists + 1, reason: 'the badge went up, so the list is read again');
  });

  test('while the socket is blocked the badge is read every 30 seconds, and the list only when something new came', () {
    fakeAsync((async) {
      final c = make();
      async.flushMicrotasks();
      socket.set(RealtimeState.disconnected);
      async.flushMicrotasks();
      final reads = inbox.countReads;
      final lists = inbox.listReads;

      async.elapse(const Duration(seconds: 31));
      expect(inbox.countReads, reads + 1, reason: 'first poll');
      expect(inbox.listReads, lists, reason: 'nothing new: the list is left alone');

      inbox.unread = 6;
      async.elapse(const Duration(seconds: 30));
      expect(inbox.countReads, reads + 2);
      expect(c.read(unreadCountProvider), 6);
      expect(inbox.listReads, lists + 1, reason: 'new since the last read: the list follows');

      socket.set(RealtimeState.connected);
      async.flushMicrotasks();
      final after = inbox.countReads;
      async.elapse(const Duration(minutes: 5));
      expect(inbox.countReads, after, reason: 'the socket is back: no more polling');
    });
  });
}
