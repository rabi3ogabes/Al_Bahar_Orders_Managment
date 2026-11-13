import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/utils/phone.dart';
import 'package:albahar_customer/core/utils/validators.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/app_text_field.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/dirty_form_guard.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/branches/domain/branch_models.dart';
import 'package:albahar_customer/features/branches/presentation/branch_widgets.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/profile/data/profile_repository.dart';
import 'package:albahar_customer/features/profile/domain/profile_models.dart';
import 'package:albahar_customer/features/profile/presentation/state/profile_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:latlong2/latlong.dart';

/// Qatar address form: zone / street / building / unit (blue plate),
/// area, landmark, instructions, delivery zone and an optional map pin.
class AddressFormScreen extends ConsumerStatefulWidget {
  const AddressFormScreen({super.key, this.addressId});
  final String? addressId;

  @override
  ConsumerState<AddressFormScreen> createState() => _AddressFormScreenState();
}

class _AddressFormScreenState extends ConsumerState<AddressFormScreen> {
  final _form = GlobalKey<FormState>();
  final _label = TextEditingController();
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _zone = TextEditingController();
  final _street = TextEditingController();
  final _building = TextEditingController();
  final _unit = TextEditingController();
  final _area = TextEditingController();
  final _landmark = TextEditingController();
  final _instructions = TextEditingController();
  String? _zoneId;
  LatLng? _pin;
  bool _default = false;
  bool _busy = false;
  bool _loaded = false;
  bool _done = false;
  Object? _loadError;
  ApiException? _error;
  String? _localError;
  String _initial = '';

  /// Everything the customer can change, for the unsaved-changes check.
  String _snapshot() => [..._all.map((c) => c.text.trim()), _zoneId, _pin, _default].join('\u0001');

  List<TextEditingController> get _all => [
    _label,
    _name,
    _phone,
    _zone,
    _street,
    _building,
    _unit,
    _area,
    _landmark,
    _instructions,
  ];

  @override
  void initState() {
    super.initState();
    final user = ref.read(sessionControllerProvider).user;
    if (widget.addressId == null) {
      _name.text = user?.name ?? '';
      _phone.text = user == null ? '' : user.phone.replaceFirst('+974', '');
      _loaded = true;
      _initial = _snapshot();
    } else {
      unawaited(_load());
    }
  }

  Future<void> _load() async {
    try {
      final a = await ref.read(profileRepositoryProvider).address(widget.addressId!);
      if (!mounted) return;
      setState(() {
        _label.text = a.label ?? '';
        _name.text = a.recipientName;
        _phone.text = a.recipientPhone.replaceFirst('+974', '');
        _zone.text = a.zoneNumber ?? '';
        _street.text = a.streetNumber ?? '';
        _building.text = a.buildingNumber ?? '';
        _unit.text = a.unitNumber ?? '';
        _area.text = a.area ?? '';
        _landmark.text = a.landmark ?? '';
        _instructions.text = a.instructions ?? '';
        _zoneId = a.deliveryZone?.id;
        _pin = a.hasPin ? LatLng(a.latitude!, a.longitude!) : null;
        _default = a.isDefault;
        _loaded = true;
        _loadError = null;
        _initial = _snapshot();
      });
    } catch (e) {
      if (mounted) setState(() => _loadError = e);
    }
  }

  @override
  void dispose() {
    for (final c in _all) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _pickOnMap() async {
    final result = await Navigator.of(context)
        .push<LatLng>(MaterialPageRoute(builder: (_) => MapPinPicker(initial: _pin), fullscreenDialog: true));
    if (result != null) setState(() => _pin = result);
  }

  Future<void> _save() async {
    if (_busy) return;
    final l10n = context.l10n;
    if (!_form.currentState!.validate()) return;
    final phone = QatarPhone.toE164(_phone.text);
    if (phone == null) {
      setState(() => _localError = l10n.phoneInvalid);
      return;
    }
    final input = AddressInput(
      label: _label.text,
      recipientName: _name.text,
      recipientPhone: phone,
      deliveryZoneId: _zoneId,
      zoneNumber: _zone.text,
      streetNumber: _street.text,
      buildingNumber: _building.text,
      unitNumber: _unit.text,
      area: _area.text,
      landmark: _landmark.text,
      instructions: _instructions.text,
      latitude: _pin?.latitude,
      longitude: _pin?.longitude,
      isDefault: _default,
    );
    if (!input.isLocatable) {
      setState(() => _localError = l10n.addressRequirement);
      Haptics.warning();
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
      _localError = null;
    });
    try {
      final saved = await ref.read(addressesProvider.notifier).save(input, id: widget.addressId);
      Haptics.success();
      if (!mounted) return;
      setState(() => _done = true);
      showInfoSnack(context, l10n.addressSaved);
      context.pop(saved);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final zones = ref.watch(deliveryZonesProvider);
    final digits = [
      const AsciiDigitsFormatter(),
      FilteringTextInputFormatter.digitsOnly,
      LengthLimitingTextInputFormatter(8),
    ];
    String? err(String f) => _error?.fieldError(f);
    // Server errors for fields this form does not show (e.g. map pin outside Qatar).
    const shown = {
      'label',
      'recipient_name',
      'recipient_phone',
      'zone_number',
      'street_number',
      'building_number',
      'unit_number',
      'area',
      'delivery_zone_id',
      'landmark',
      'instructions',
    };
    final hiddenField = _error?.errors.entries.where((e) => !shown.contains(e.key)).firstOrNull;
    final generalError =
        _localError ??
        (_error == null
            ? null
            : (hiddenField?.value.firstOrNull ?? (_error!.errors.isEmpty ? describeError(l10n, _error) : null)));

    if (!_loaded) {
      return Scaffold(
        appBar: AppBar(),
        body: _loadError != null
            ? ErrorState(
                error: _loadError,
                onRetry: () {
                  setState(() => _loadError = null);
                  unawaited(_load());
                },
              )
            : const ListSkeleton(leading: false),
      );
    }

    return DirtyFormGuard(
      controllers: _all,
      enabled: !_busy && !_done,
      isDirty: () => _snapshot() != _initial,
      child: _buildForm(context, l10n, zones, digits, err, generalError),
    );
  }

  Widget _buildForm(
    BuildContext context,
    AppLocalizations l10n,
    AsyncValue<List<DeliveryZone>> zones,
    List<TextInputFormatter> digits,
    String? Function(String) err,
    String? generalError,
  ) {
    return Scaffold(
      appBar: AppBar(title: Text(widget.addressId == null ? l10n.newAddress : l10n.editAddress)),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.all(AppSpace.gutter),
          children: [
            ContentWidth(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  AppTextField(
                    controller: _label,
                    label: '${l10n.addressLabel} (${l10n.optional})',
                    hint: l10n.addressLabelHint,
                    textInputAction: TextInputAction.next,
                    textCapitalization: TextCapitalization.sentences,
                    inputFormatters: [LengthLimitingTextInputFormatter(48)],
                    errorText: err('label'),
                  ),
                  const Gap(AppSpace.md),
                  AppTextField(
                    controller: _name,
                    label: l10n.recipientName,
                    autofillHints: const [AutofillHints.name],
                    textCapitalization: TextCapitalization.words,
                    textInputAction: TextInputAction.next,
                    inputFormatters: [LengthLimitingTextInputFormatter(120)],
                    errorText: err('recipient_name'),
                    validator: (v) => (v == null || v.trim().isEmpty) ? l10n.fieldRequired : null,
                  ),
                  const Gap(AppSpace.md),
                  AppTextField(
                    controller: _phone,
                    label: l10n.recipientPhone,
                    prefix: '+974  ',
                    forceLtr: true,
                    keyboardType: TextInputType.phone,
                    textInputAction: TextInputAction.next,
                    autofillHints: const [AutofillHints.telephoneNumberNational],
                    inputFormatters: [
                      FilteringTextInputFormatter.allow(RegExp(r'[0-9٠-٩۰-۹+ ]')),
                      LengthLimitingTextInputFormatter(16),
                    ],
                    errorText: err('recipient_phone'),
                    validator: (v) => QatarPhone.isValid(v ?? '') ? null : l10n.phoneInvalid,
                  ),
                  const Gap(AppSpace.lg),
                  Text(l10n.qatarAddressHint, style: context.text.bodySmall),
                  const Gap(AppSpace.xs),
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: AppTextField(
                          controller: _zone,
                          label: l10n.zoneNumber,
                          keyboardType: TextInputType.number,
                          inputFormatters: digits,
                          forceLtr: true,
                          textInputAction: TextInputAction.next,
                          errorText: err('zone_number'),
                        ),
                      ),
                      const Gap(AppSpace.xs),
                      Expanded(
                        child: AppTextField(
                          controller: _street,
                          label: l10n.streetNumber,
                          keyboardType: TextInputType.number,
                          inputFormatters: digits,
                          forceLtr: true,
                          textInputAction: TextInputAction.next,
                          errorText: err('street_number'),
                        ),
                      ),
                      const Gap(AppSpace.xs),
                      Expanded(
                        child: AppTextField(
                          controller: _building,
                          label: l10n.buildingNumber,
                          keyboardType: TextInputType.number,
                          inputFormatters: digits,
                          forceLtr: true,
                          textInputAction: TextInputAction.next,
                          errorText: err('building_number'),
                        ),
                      ),
                    ],
                  ),
                  const Gap(AppSpace.md),
                  Row(
                    children: [
                      Expanded(
                        child: AppTextField(
                          controller: _unit,
                          label: '${l10n.unitNumber} (${l10n.optional})',
                          textInputAction: TextInputAction.next,
                          inputFormatters: [LengthLimitingTextInputFormatter(16)],
                          errorText: err('unit_number'),
                        ),
                      ),
                      const Gap(AppSpace.xs),
                      Expanded(
                        child: AppTextField(
                          controller: _area,
                          label: l10n.area,
                          textInputAction: TextInputAction.next,
                          inputFormatters: [LengthLimitingTextInputFormatter(120)],
                          errorText: err('area'),
                        ),
                      ),
                    ],
                  ),
                  const Gap(AppSpace.md),
                  zones.when(
                    loading: () => const Skeleton(height: 56, radius: AppRadius.input),
                    error: (_, _) => const SizedBox.shrink(),
                    data: (list) => list.isEmpty
                        ? const SizedBox.shrink()
                        : DropdownButtonFormField<String>(
                            initialValue: list.any((z) => z.id == _zoneId) ? _zoneId : null,
                            isExpanded: true,
                            decoration: InputDecoration(
                              labelText: l10n.deliveryZone,
                              errorText: err('delivery_zone_id'),
                            ),
                            items: [
                              for (final DeliveryZone z in list) DropdownMenuItem(value: z.id, child: Text(z.name)),
                            ],
                            onChanged: (v) {
                              setState(() => _zoneId = v);
                              // Auto-fill area from zone when empty.
                              final zone = list.where((z) => z.id == v).firstOrNull;
                              if (zone != null && _area.text.isEmpty) _area.text = zone.name;
                            },
                          ),
                  ),
                  const Gap(AppSpace.md),
                  AppTextField(
                    controller: _landmark,
                    label: '${l10n.landmark} (${l10n.optional})',
                    textInputAction: TextInputAction.next,
                    inputFormatters: [LengthLimitingTextInputFormatter(255)],
                    errorText: err('landmark'),
                  ),
                  const Gap(AppSpace.md),
                  AppTextField(
                    controller: _instructions,
                    label: '${l10n.instructions} (${l10n.optional})',
                    maxLines: 3,
                    minLines: 1,
                    keyboardType: TextInputType.multiline,
                    inputFormatters: [LengthLimitingTextInputFormatter(500)],
                    errorText: err('instructions'),
                  ),
                  const Gap(AppSpace.lg),
                  SurfaceCard(
                    onTap: _pickOnMap,
                    padding: EdgeInsets.zero,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        if (_pin != null)
                          SizedBox(
                            height: 140,
                            child: IgnorePointer(
                              child: BranchMap(
                                branches: [
                                  Branch(id: 'pin', name: '', latitude: _pin!.latitude, longitude: _pin!.longitude),
                                ],
                                selectedId: 'pin',
                                onSelect: (_) {},
                                interactive: false,
                              ),
                            ),
                          ),
                        ListTile(
                          leading: Icon(
                            _pin != null ? Icons.where_to_vote_rounded : Icons.add_location_alt_outlined,
                            color: context.palette.brass,
                          ),
                          title: Text(_pin != null ? l10n.pinSet : l10n.pinOnMap),
                          trailing: const Icon(Icons.chevron_right_rounded),
                        ),
                      ],
                    ),
                  ),
                  const Gap(AppSpace.sm),
                  SwitchListTile(
                    contentPadding: EdgeInsets.zero,
                    value: _default,
                    onChanged: (v) => setState(() => _default = v),
                    title: Text(l10n.setAsDefault),
                  ),
                  if (generalError != null)
                    Padding(
                      padding: const EdgeInsets.only(top: AppSpace.xs),
                      child: Text(generalError, style: context.text.bodySmall?.copyWith(color: context.palette.danger)),
                    ),
                  const Gap(AppSpace.lg),
                  AppButton(label: l10n.saveAddress, loading: _busy, onPressed: _save),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Full-screen map with a centred pin; "use current location" when allowed.
class MapPinPicker extends ConsumerStatefulWidget {
  const MapPinPicker({super.key, this.initial});
  final LatLng? initial;

  @override
  ConsumerState<MapPinPicker> createState() => _MapPinPickerState();
}

class _MapPinPickerState extends ConsumerState<MapPinPicker> {
  final _map = MapController();
  late LatLng _center = widget.initial ?? dohaCenter;
  bool _locating = false;

  Future<void> _locate() async {
    setState(() => _locating = true);
    final location = ref.read(locationServiceProvider);
    final access = await location.ensurePermission();
    if (access == LocationAccess.granted) {
      final p = await location.currentPosition();
      if (p != null && mounted) {
        _center = LatLng(p.lat, p.lng);
        _map.move(_center, 17);
      }
    } else if (mounted) {
      showInfoSnack(context, context.l10n.locationDeniedMessage);
    }
    if (mounted) setState(() => _locating = false);
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final tiles = ref.watch(appConfigProvider).mapTileUrlTemplate;
    return Scaffold(
      appBar: AppBar(title: Text(l10n.pinOnMap)),
      body: Stack(
        children: [
          FlutterMap(
            mapController: _map,
            options: MapOptions(
              initialCenter: _center,
              initialZoom: widget.initial != null ? 17 : 12,
              onPositionChanged: (camera, _) => _center = camera.center,
              interactionOptions: const InteractionOptions(flags: InteractiveFlag.all & ~InteractiveFlag.rotate),
            ),
            children: [
              TileLayer(urlTemplate: tiles, userAgentPackageName: 'qa.albahar.customer'),
              RichAttributionWidget(attributions: [TextSourceAttribution(l10n.mapAttribution)]),
            ],
          ),
          IgnorePointer(
            child: Center(
              child: Padding(
                padding: const EdgeInsets.only(bottom: 44),
                child: Icon(Icons.location_on_rounded, size: 48, color: context.palette.brass),
              ),
            ),
          ),
          PositionedDirectional(
            top: AppSpace.md,
            start: AppSpace.md,
            end: AppSpace.md,
            child: Material(
              color: context.colors.surface,
              borderRadius: AppRadius.inputAll,
              child: Padding(
                padding: const EdgeInsets.all(AppSpace.sm),
                child: Text(l10n.moveMapToPin, textAlign: TextAlign.center, style: context.text.bodySmall),
              ),
            ),
          ),
          PositionedDirectional(
            end: AppSpace.md,
            bottom: 110,
            child: FloatingActionButton.small(
              heroTag: 'locate',
              tooltip: l10n.useCurrentLocation,
              onPressed: _locating ? null : _locate,
              child: _locating
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.my_location_rounded),
            ),
          ),
          PositionedDirectional(
            start: AppSpace.gutter,
            end: AppSpace.gutter,
            bottom: AppSpace.lg,
            child: SafeArea(
              child: AppButton(label: l10n.confirmLocation, onPressed: () => Navigator.of(context).pop(_center)),
            ),
          ),
        ],
      ),
    );
  }
}
