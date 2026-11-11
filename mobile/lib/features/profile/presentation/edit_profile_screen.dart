import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/utils/phone.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/app_text_field.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auth/data/auth_repository.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

class EditProfileScreen extends ConsumerStatefulWidget {
  const EditProfileScreen({super.key});

  @override
  ConsumerState<EditProfileScreen> createState() => _EditProfileScreenState();
}

class _EditProfileScreenState extends ConsumerState<EditProfileScreen> {
  late final _user = ref.read(sessionControllerProvider).user;
  late final _name = TextEditingController(text: _user?.name ?? '');
  late final _email = TextEditingController(text: _user?.email ?? '');
  late String? _gender = _user?.gender;
  late String? _dob = _user?.dateOfBirth;
  late bool _marketing = _user?.marketingConsent ?? false;
  bool _busy = false;
  ApiException? _error;

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(sessionControllerProvider.notifier).updateProfile({
        'name': _name.text.trim(),
        'email': _email.text.trim().isEmpty ? null : _email.text.trim(),
        'gender': _gender,
        'date_of_birth': _dob,
        'marketing_consent': _marketing,
      });
      Haptics.success();
      if (!mounted) return;
      showInfoSnack(context, context.l10n.profileSaved);
      context.pop();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _changePassword() async {
    final l10n = context.l10n;
    final current = TextEditingController();
    final next = TextEditingController();
    final hasPassword = _user?.hasPassword ?? false;
    final ok = await showAppBottomSheet<bool>(
      context: context,
      title: hasPassword ? l10n.changePassword : l10n.setPassword,
      builder: (ctx) => Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (hasPassword) ...[
            AppTextField(controller: current, label: l10n.currentPassword, obscure: true),
            const Gap(AppSpace.md),
          ],
          AppTextField(controller: next, label: l10n.newPasswordLabel, obscure: true, helper: l10n.passwordTooShort),
          const Gap(AppSpace.lg),
          AppButton(label: l10n.save, onPressed: () => Navigator.of(ctx).pop(next.text.length >= 8)),
        ],
      ),
    );
    if (ok == true) {
      try {
        await ref.read(authRepositoryProvider).changePassword(currentPassword: current.text, password: next.text);
        await ref.read(sessionControllerProvider.notifier).refreshUser();
        if (mounted) showInfoSnack(context, l10n.passwordChanged);
      } catch (e) {
        if (mounted) showErrorSnack(context, e);
      }
    }
    current.dispose();
    next.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final dobDate = _dob == null ? null : DateTime.tryParse(_dob!);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.editProfile)),
      body: ListView(
        padding: const EdgeInsets.all(AppSpace.gutter),
        children: [
          ContentWidth(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                AppTextField(
                  controller: _name,
                  label: l10n.nameLabel,
                  errorText: _error?.fieldError('name'),
                  textCapitalization: TextCapitalization.words,
                ),
                const Gap(AppSpace.md),
                AppTextField(
                  controller: _email,
                  label: l10n.emailLabel,
                  forceLtr: true,
                  keyboardType: TextInputType.emailAddress,
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
                    if (picked != null) setState(() => _dob = DateFormat('yyyy-MM-dd').format(picked));
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
                const Gap(AppSpace.lg),
                AppButton(label: l10n.save, loading: _busy, onPressed: _save),
                const Gap(AppSpace.sm),
                AppButton.secondary(
                  label: (_user?.hasPassword ?? false) ? l10n.changePassword : l10n.setPassword,
                  icon: Icons.lock_outline_rounded,
                  onPressed: _changePassword,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
