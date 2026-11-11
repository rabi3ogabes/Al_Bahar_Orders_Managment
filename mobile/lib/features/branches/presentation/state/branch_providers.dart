import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';

import '../../../../core/providers.dart';
import '../../../../core/utils/qatar_time.dart';
import '../../data/branch_repository.dart';
import '../../domain/branch_models.dart';

/// The customer's preferred branch (persisted on device). It drives the
/// `branch_id` sent with catalog, home and cart requests. Changing it never
/// affects existing orders — their branch is fixed server-side.
class PreferredBranchController extends Notifier<BranchRef?> {
  @override
  BranchRef? build() {
    final json = ref.watch(appPreferencesProvider).preferredBranch;
    return json == null ? null : BranchRef.fromJson(json);
  }

  /// Listeners (e.g. the cart) react to the change via `ref.listen`.
  Future<void> select(BranchRef branch) async {
    if (state == branch) return;
    state = branch;
    await ref.read(appPreferencesProvider).setPreferredBranch(branch.toJson());
  }

  Future<void> clear() async {
    state = null;
    await ref.read(appPreferencesProvider).setPreferredBranch(null);
  }
}

final preferredBranchProvider = NotifierProvider<PreferredBranchController, BranchRef?>(PreferredBranchController.new);

final preferredBranchIdProvider = Provider<String?>((ref) => ref.watch(preferredBranchProvider)?.id);

final branchSearchProvider = NotifierProvider<BranchSearch, String>(BranchSearch.new);

class BranchSearch extends Notifier<String> {
  @override
  String build() => '';
  void set(String value) => state = value.trim();
}

final branchesProvider = FutureProvider.autoDispose<List<Branch>>((ref) async {
  ref.watch(localeCodeProvider);
  final search = ref.watch(branchSearchProvider);
  final page = await ref.watch(branchRepositoryProvider).list(search: search.isEmpty ? null : search);
  return page.items;
});

final branchDetailProvider = FutureProvider.autoDispose.family<Branch, String>((ref, id) {
  ref.watch(localeCodeProvider);
  return ref.watch(branchRepositoryProvider).get(id);
});

final branchHoursProvider = FutureProvider.autoDispose.family<BranchHours, String>((ref, id) {
  ref.watch(localeCodeProvider);
  return ref.watch(branchRepositoryProvider).hours(id);
});

final pickupSlotsProvider = FutureProvider.autoDispose.family<List<PickupSlot>, ({String branchId, String date})>(
  (ref, args) => ref.watch(branchRepositoryProvider).pickupSlots(args.branchId, args.date),
);

final deliverySlotsProvider = FutureProvider.autoDispose.family<List<DeliverySlot>, ({String branchId, String date})>(
  (ref, args) => ref.watch(branchRepositoryProvider).deliverySlots(args.branchId, args.date),
);

/// The next 7 days (from today, Qatar) that have at least one selectable
/// delivery slot, in order — a single span request. A failure is rethrown so
/// the UI can offer a retry.
final deliveryDatesProvider = FutureProvider.autoDispose.family<List<String>, String>((ref, branchId) async {
  const days = 7;
  final today = QatarTime.today();
  final from = QatarTime.isoDate(today);
  final to = QatarTime.isoDate(today.add(const Duration(days: days - 1)));
  final span = await ref
      .watch(branchRepositoryProvider)
      .deliverySlotDays(branchId, from: from, to: to, onlyAvailable: true);
  return [
    for (final d in span)
      if (d.hasSelectable && d.date.compareTo(from) >= 0 && d.date.compareTo(to) <= 0) d.date,
  ]..sort();
});

final deliveryZonesProvider = FutureProvider<List<DeliveryZone>>((ref) {
  ref.watch(localeCodeProvider);
  return ref.watch(branchRepositoryProvider).deliveryZones();
});

/// Location access outcome for nearby discovery.
enum LocationAccess { granted, denied, deniedForever, serviceDisabled }

/// Wraps geolocator so it can be faked in tests.
class LocationService {
  const LocationService();

  Future<LocationAccess> ensurePermission() async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) return LocationAccess.serviceDisabled;
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) permission = await Geolocator.requestPermission();
      return switch (permission) {
        LocationPermission.always || LocationPermission.whileInUse => LocationAccess.granted,
        LocationPermission.deniedForever => LocationAccess.deniedForever,
        _ => LocationAccess.denied,
      };
    } catch (_) {
      return LocationAccess.denied;
    }
  }

  Future<({double lat, double lng})?> currentPosition() async {
    try {
      final p = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(accuracy: LocationAccuracy.medium, timeLimit: Duration(seconds: 12)),
      );
      return (lat: p.latitude, lng: p.longitude);
    } catch (_) {
      return null;
    }
  }

  Future<void> openSettings() async {
    try {
      await Geolocator.openAppSettings();
    } catch (_) {}
  }
}

final locationServiceProvider = Provider<LocationService>((ref) => const LocationService());

/// Result of nearby discovery (null list while permission is missing).
class NearbyResult {
  const NearbyResult({required this.access, this.branches = const []});
  final LocationAccess access;
  final List<Branch> branches;
}

final nearbyBranchesProvider = FutureProvider.autoDispose<NearbyResult>((ref) async {
  ref.watch(localeCodeProvider);
  final location = ref.watch(locationServiceProvider);
  final access = await location.ensurePermission();
  if (access != LocationAccess.granted) return NearbyResult(access: access);
  final pos = await location.currentPosition();
  if (pos == null) return const NearbyResult(access: LocationAccess.denied);
  final list = await ref.watch(branchRepositoryProvider).nearby(lat: pos.lat, lng: pos.lng);
  return NearbyResult(access: access, branches: list);
});
