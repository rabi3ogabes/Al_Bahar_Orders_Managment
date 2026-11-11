import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/app_text_field.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/profile/data/profile_repository.dart';
import 'package:albahar_customer/features/profile/domain/profile_models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Account deletion request with typed confirmation and 14-day grace period.
class DeleteAccountScreen extends ConsumerStatefulWidget {
  const DeleteAccountScreen({super.key});

  @override
  ConsumerState<DeleteAccountScreen> createState() =>
      _DeleteAccountScreenState();
}

class _DeleteAccountScreenState extends ConsumerState<DeleteAccountScreen> {
  final _reason = TextEditingController();
  final _confirm = TextEditingController();
  bool _busy = false;
  DeletionRequest? _request;

  @override
  void dispose() {
    _reason.dispose();
    _confirm.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final l10n = context.l10n;
    final ok = await confirmAction(
      context,
      title: l10n.deleteAccountTitle,
      message: l10n.deleteAccountWarning,
      confirmLabel: l10n.deleteAccount,
      destructive: true,
    );
    if (!ok) return;
    setState(() => _busy = true);
    try {
      final r = await ref
          .read(profileRepositoryProvider)
          .requestDeletion(reason: _reason.text.trim());
      Haptics.heavy();
      if (mounted) setState(() => _request = r);
    } catch (e) {
      if (mounted) showErrorSnack(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _cancel() async {
    setState(() => _busy = true);
    try {
      await ref.read(profileRepositoryProvider).cancelDeletion();
      if (!mounted) return;
      showInfoSnack(context, context.l10n.deletionCancelled);
      setState(() => _request = null);
    } catch (e) {
      if (mounted) showErrorSnack(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final word = l10n.deleteWord;
    final matches = _confirm.text.trim().toUpperCase() == word.toUpperCase();
    return Scaffold(
      appBar: AppBar(title: Text(l10n.deleteAccount)),
      body: ListView(
        padding: const EdgeInsets.all(AppSpace.gutter),
        children: [
          ContentWidth(
            child: _request != null
                ? Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Icon(
                        Icons.schedule_rounded,
                        size: 56,
                        color: context.palette.warning,
                      ),
                      const Gap(AppSpace.md),
                      Text(
                        l10n.deleteAccountRequested(
                          QatarTime.date(
                            _request!.scheduledFor,
                            context.localeCode,
                          ),
                        ),
                        textAlign: TextAlign.center,
                        style: context.text.headlineSmall,
                      ),
                      const Gap(AppSpace.lg),
                      AppButton.secondary(
                        label: l10n.cancelDeletion,
                        loading: _busy,
                        onPressed: _cancel,
                      ),
                    ],
                  )
                : Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Icon(
                        Icons.warning_amber_rounded,
                        size: 48,
                        color: context.palette.danger,
                      ),
                      const Gap(AppSpace.md),
                      Text(
                        l10n.deleteAccountTitle,
                        style: context.text.headlineMedium,
                      ),
                      const Gap(AppSpace.sm),
                      Text(
                        l10n.deleteAccountWarning,
                        style: context.text.bodyMedium,
                      ),
                      const Gap(AppSpace.lg),
                      AppTextField(
                        controller: _reason,
                        label: l10n.deleteAccountReason,
                        maxLines: 3,
                        minLines: 2,
                      ),
                      const Gap(AppSpace.md),
                      AppTextField(
                        controller: _confirm,
                        label: l10n.deleteAccountTypeToConfirm(word),
                        onChanged: (_) => setState(() {}),
                      ),
                      const Gap(AppSpace.lg),
                      AppButton(
                        label: l10n.deleteAccount,
                        variant: AppButtonVariant.destructive,
                        loading: _busy,
                        onPressed: matches ? _submit : null,
                      ),
                      const Gap(AppSpace.sm),
                      AppButton.secondary(
                        label: l10n.cancelDeletion,
                        onPressed: _busy ? null : _cancel,
                      ),
                    ],
                  ),
          ),
        ],
      ),
    );
  }
}
