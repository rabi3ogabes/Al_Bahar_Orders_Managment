import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/utils/phone.dart';
import 'package:albahar_customer/core/utils/validators.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/app_text_field.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/dirty_form_guard.dart';
import 'package:albahar_customer/core/widgets/input_sheets.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auth/data/auth_repository.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/checkout/domain/alternate_phone.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

/// Opens the profile form in a side window. Resolves to true once the profile was saved (and confirms it on [context]).
Future<bool> showProfilePanel(BuildContext context) async {
  final l10n = context.l10n;
  final saved = await showSidePanel<bool>(
    context: context,
    title: l10n.editProfile,
    scrollable: false,
    isForm: true,
    builder: (_) => const EditProfileScreen(embedded: true),
  );
  if (saved == true && context.mounted) showInfoSnack(context, l10n.profileSaved);
  return saved ?? false;
}

class EditProfileScreen extends ConsumerStatefulWidget {
  const EditProfileScreen({super.key, this.embedded = false});

  /// True inside a side window ([showProfilePanel]): no app bar, Save pinned at the bottom, and the password form
  /// opens as a step of the window instead of another window on top of it.
  final bool embedded;

  @override
  ConsumerState<EditProfileScreen> createState() => _EditProfileScreenState();
}

class _EditProfileScreenState extends ConsumerState<EditProfileScreen> {
  late final _user = ref.read(sessionControllerProvider).user;
  late final _name = TextEditingController(text: _user?.name ?? '');
  late final _email = TextEditingController(text: _user?.email ?? '');
  late final _alternate = TextEditingController(text: AlternatePhoneInput.fromSaved(_user?.alternatePhone).text);
  late String? _gender = _user?.gender;
  late String? _dob = _user?.dateOfBirth;
  late bool _marketing = _user?.marketingConsent ?? false;
  final _form = GlobalKey<FormState>();
  bool _busy = false;
  bool _done = false;
  bool _passwordStep = false;
  ApiException? _error;

  bool get _dirty =>
      _name.text.trim() != (_user?.name ?? '') ||
      _email.text.trim() != (_user?.email ?? '') ||
      _alternate.text.trim() != AlternatePhoneInput.fromSaved(_user?.alternatePhone).text ||
      _gender != _user?.gender ||
      _dob != _user?.dateOfBirth ||
      _marketing != (_user?.marketingConsent ?? false);

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _alternate.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_busy || !_form.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(sessionControllerProvider.notifier).updateProfile({
        'name': _name.text.trim(),
        'email': _email.text.trim().isEmpty ? null : _email.text.trim(),
        // Empty clears it; a number is sent in full so the server validates it the same way as at checkout.
        'alternate_phone': _alternate.text.trim().isEmpty ? null : (QatarPhone.toE164(_alternate.text) ?? _alternate.text.trim()),
        'gender': _gender,
        'date_of_birth': _dob,
        'marketing_consent': _marketing,
      });
      Haptics.success();
      if (!mounted) return;
      setState(() => _done = true);
      if (widget.embedded) {
        Navigator.of(context).pop(true);
      } else {
        showInfoSnack(context, context.l10n.profileSaved);
        context.pop();
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _changePassword() async {
    if (widget.embedded) {
      setState(() => _passwordStep = true);
      return;
    }
    final change = await showPasswordSheet(context, askCurrent: _user?.hasPassword ?? false);
    if (change != null) await _applyPassword(change);
  }

  Future<void> _applyPassword(PasswordChange change) async {
    final l10n = context.l10n;
    try {
      await ref.read(authRepositoryProvider).changePassword(currentPassword: change.current, password: change.next);
      await ref.read(sessionControllerProvider.notifier).refreshUser();
      if (mounted) showInfoSnack(context, l10n.passwordChanged);
    } catch (e) {
      if (mounted) showErrorSnack(context, e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final dobDate = _dob == null ? null : DateTime.tryParse(_dob!);
    if (_passwordStep) {
      final hasPassword = _user?.hasPassword ?? false;
      return PanelStep(
        title: hasPassword ? l10n.changePassword : l10n.setPassword,
        onBack: () => setState(() => _passwordStep = false),
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 20),
          child: PasswordFormBody(
            askCurrent: hasPassword,
            onDone: (change) {
              setState(() => _passwordStep = false);
              unawaited(_applyPassword(change));
            },
          ),
        ),
      );
    }
    return DirtyFormGuard(
      controllers: [_name, _email, _alternate],
      enabled: !_busy && !_done,
      isDirty: () => _dirty,
      child: _frame(
        context,
        Form(
          key: _form,
          child: SidePanelLayout(
            padding: widget.embedded
                ? const EdgeInsets.fromLTRB(20, 16, 20, 20)
                : const EdgeInsets.all(AppSpace.gutter),
            footer: AppButton(label: l10n.save, loading: _busy, onPressed: _save),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                AppTextField(
                  controller: _name,
                  label: l10n.nameLabel,
                  errorText: _error?.fieldError('name'),
                  textCapitalization: TextCapitalization.words,
                  autofillHints: const [AutofillHints.name],
                  textInputAction: TextInputAction.next,
                  inputFormatters: [LengthLimitingTextInputFormatter(120)],
                  validator: (v) => (v == null || v.trim().length < 2) ? l10n.fieldRequired : null,
                ),
                const Gap(AppSpace.md),
                AppTextField(
                  controller: _email,
                  label: l10n.emailLabel,
                  forceLtr: true,
                  keyboardType: TextInputType.emailAddress,
                  autofillHints: const [AutofillHints.email],
                  textInputAction: TextInputAction.done,
                  inputFormatters: [LengthLimitingTextInputFormatter(191)],
                  validator: (v) =>
                      (v != null && v.trim().isNotEmpty && !Validators.isEmail(v)) ? l10n.emailInvalid : null,
                  errorText: _error?.fieldError('email'),
                ),
                const Gap(AppSpace.md),
                AppTextField(
                  label: l10n.phoneLabel,
                  initialValue: _user == null ? '' : QatarPhone.display(_user.phone),
                  enabled: false,
                  forceLtr: true,
                ),
                const Gap(AppSpace.md),
                AppTextField(
                  key: const ValueKey('profile-alternate-phone'),
                  controller: _alternate,
                  label: '${l10n.alternatePhoneLabel} (${l10n.optional})',
                  hint: l10n.phoneHint,
                  helper: l10n.alternatePhoneProfileHelper,
                  prefix: '+974  ',
                  forceLtr: true,
                  keyboardType: TextInputType.phone,
                  textInputAction: TextInputAction.next,
                  autofillHints: const [AutofillHints.telephoneNumberNational],
                  inputFormatters: [
                    FilteringTextInputFormatter.allow(RegExp(r'[0-9٠-٩۰-۹+ ]')),
                    LengthLimitingTextInputFormatter(16),
                  ],
                  validator: (v) {
                    if (v == null || v.trim().isEmpty) return null;
                    final number = QatarPhone.toE164(v);
                    if (number == null) return l10n.alternatePhoneInvalid;
                    return number == QatarPhone.toE164(_user?.phone ?? '') ? l10n.alternatePhoneSame : null;
                  },
                  errorText: _error?.fieldError('alternate_phone'),
                ),
                const Gap(AppSpace.md),
                AppTextField(
                  key: ValueKey(_dob),
                  label: '${l10n.dateOfBirth} (${l10n.optional})',
                  readOnly: true,
                  initialValue: dobDate == null ? '' : DateFormat.yMMMd(context.localeCode).format(dobDate),
                  suffix: const Icon(Icons.calendar_today_outlined, size: 18),
                  errorText: _error?.fieldError('date_of_birth'),
                  onTap: () async {
                    final now = DateTime.now();
                    final picked = await showDatePicker(
                      context: context,
                      initialDate: dobDate ?? DateTime(now.year - 30),
                      firstDate: DateTime(1920),
                      lastDate: DateTime(now.year - 13),
                    );
                    if (picked != null && mounted) setState(() => _dob = DateFormat('yyyy-MM-dd').format(picked));
                  },
                ),
                const Gap(AppSpace.md),
                Text(l10n.gender, style: context.text.titleSmall),
                const Gap(AppSpace.xs),
                SegmentedButton<String>(
                  emptySelectionAllowed: true,
                  segments: [
                    ButtonSegment(value: 'female', label: Text(l10n.female)),
                    ButtonSegment(value: 'male', label: Text(l10n.male)),
                  ],
                  selected: {?_gender},
                  onSelectionChanged: (s) => setState(() => _gender = s.firstOrNull),
                ),
                const Gap(AppSpace.md),
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  value: _marketing,
                  onChanged: (v) => setState(() => _marketing = v),
                  title: Text(l10n.marketingConsentToggle),
                  subtitle: Text(l10n.marketingConsent),
                ),
                if (_error != null && _error!.errors.isEmpty)
                  Text(
                    describeError(l10n, _error),
                    style: context.text.bodySmall?.copyWith(color: context.palette.danger),
                  ),
                const Gap(AppSpace.sm),
                AppButton.secondary(
                  label: (_user?.hasPassword ?? false) ? l10n.changePassword : l10n.setPassword,
                  icon: Icons.lock_outline_rounded,
                  onPressed: _changePassword,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _frame(BuildContext context, Widget body) => widget.embedded
      ? body
      : Scaffold(
          appBar: AppBar(title: Text(context.l10n.editProfile)),
          body: ContentWidth(child: body),
        );
}
