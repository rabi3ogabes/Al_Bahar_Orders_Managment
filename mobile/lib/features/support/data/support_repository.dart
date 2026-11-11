import 'package:albahar_customer/core/models/paginated.dart';
import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/network/interceptors.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/core/widgets/paged.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/support/domain/support_models.dart';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

class SupportRepository {
  SupportRepository(this._api);
  final ApiClient _api;

  Future<Paginated<SupportTicket>> tickets({int page = 1}) =>
      _api.getPage('/me/support/tickets', SupportTicket.fromJson, query: {'page': page});

  Future<SupportTicket> ticket(String id) => _api.getObject('/me/support/tickets/$id', SupportTicket.fromJson);

  Future<SupportTicket> create({
    required String category,
    required String subject,
    required String message,
    String? orderId,
  }) async => SupportTicket.fromJson(
    asMap(
      await _api.post(
        '/me/support/tickets',
        data: {'category': category, 'subject': subject, 'message': message, 'order_id': ?orderId},
      ),
    ),
  );

  Future<TicketMessage?> reply(String ticketId, String body) async {
    final data = await _api.post('/me/support/tickets/$ticketId/messages', data: {'body': body});
    final map = asMapOrNull(data);
    return map == null || map['body'] == null ? null : TicketMessage.fromJson(map);
  }

  /// CMS pages are public.
  Future<CmsPage> page(String slug) async =>
      CmsPage.fromJson(asMap(await _api.get('/pages/$slug', options: Options(extra: {RequestFlags.skipAuth: true}))));
}

final supportRepositoryProvider = Provider<SupportRepository>((ref) => SupportRepository(ref.watch(apiClientProvider)));

class TicketsController extends PagedNotifier<SupportTicket> {
  @override
  void watchDependencies() {
    ref.watch(isAuthenticatedProvider);
    ref.watch(localeCodeProvider);
  }

  @override
  Future<Paginated<SupportTicket>> fetchPage(int page) => ref.read(supportRepositoryProvider).tickets(page: page);
}

final ticketsProvider = AsyncNotifierProvider.autoDispose<TicketsController, PagedState<SupportTicket>>(
  TicketsController.new,
);

final ticketProvider = FutureProvider.autoDispose.family<SupportTicket, String>(
  (ref, id) => ref.watch(supportRepositoryProvider).ticket(id),
);

final cmsPageProvider = FutureProvider.autoDispose.family<CmsPage, String>((ref, slug) {
  ref.watch(localeCodeProvider);
  return ref.watch(supportRepositoryProvider).page(slug);
});
