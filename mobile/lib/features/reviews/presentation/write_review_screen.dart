import 'dart:typed_data';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/app_text_field.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/dirty_form_guard.dart';
import 'package:albahar_customer/core/widgets/rating.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/catalog/data/catalog_repository.dart';
import 'package:albahar_customer/features/catalog/presentation/state/catalog_providers.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

/// Opens the review form in a side window. Resolves to true once the review was sent (and confirms it on [context]).
Future<bool> showReviewPanel(BuildContext context, {required String productId, String? productName}) async {
  final l10n = context.l10n;
  final sent = await showSidePanel<bool>(
    context: context,
    title: l10n.writeReviewTitle,
    scrollable: false,
    isForm: true,
    builder: (_) => WriteReviewScreen(productId: productId, productName: productName, embedded: true),
  );
  if (sent == true && context.mounted) showInfoSnack(context, l10n.reviewSubmitted);
  return sent ?? false;
}

/// Review form: 1–5 stars, headline, body and up to 4 photos (multipart).
/// Reviews are moderated; the verified-purchase flag is set server-side.
class WriteReviewScreen extends ConsumerStatefulWidget {
  const WriteReviewScreen({super.key, required this.productId, this.productName, this.embedded = false});
  final String productId;
  final String? productName;

  /// True inside a side window ([showReviewPanel]): no app bar, closes with `true` once the review is sent.
  final bool embedded;

  @override
  ConsumerState<WriteReviewScreen> createState() => _WriteReviewScreenState();
}

class _WriteReviewScreenState extends ConsumerState<WriteReviewScreen> {
  static const maxPhotos = 4;
  final _title = TextEditingController();
  final _body = TextEditingController();
  final _photos = <({XFile file, Uint8List bytes})>[];
  int _rating = 0;
  bool _busy = false;
  bool _done = false;
  ApiException? _error;
  String? _ratingError;

  @override
  void dispose() {
    _title.dispose();
    _body.dispose();
    super.dispose();
  }

  Future<void> _pick(ImageSource source) async {
    try {
      final picker = ImagePicker();
      final remaining = maxPhotos - _photos.length;
      if (remaining <= 0) return;
      final files = source == ImageSource.camera
          ? [?await picker.pickImage(source: source, maxWidth: 2000, imageQuality: 85)]
          : await picker.pickMultiImage(maxWidth: 2000, imageQuality: 85, limit: remaining);
      for (final f in files.take(remaining)) {
        final bytes = await f.readAsBytes();
        if (!mounted) return;
        setState(() => _photos.add((file: f, bytes: bytes)));
      }
    } catch (e) {
      if (mounted) showErrorSnack(context, e);
    }
  }

  Future<void> _submit() async {
    if (_busy) return;
    final l10n = context.l10n;
    if (_rating == 0) {
      setState(() => _ratingError = l10n.ratingRequired);
      Haptics.warning();
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
      _ratingError = null;
    });
    try {
      await ref
          .read(catalogRepositoryProvider)
          .submitReview(
            widget.productId,
            rating: _rating,
            title: _title.text.trim(),
            body: _body.text.trim(),
            photos: [for (final p in _photos) MultipartFile.fromBytes(p.bytes, filename: p.file.name)],
          );
      ref.invalidate(reviewsProvider(widget.productId));
      if (!mounted) return;
      Haptics.success();
      setState(() => _done = true);
      if (widget.embedded) {
        // The window closes; its opener confirms on the page behind.
        Navigator.of(context).pop(true);
      } else {
        showInfoSnack(context, l10n.reviewSubmitted);
        context.pop();
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return DirtyFormGuard(
      controllers: [_title, _body],
      enabled: !_busy && !_done,
      isDirty: () => _rating > 0 || _photos.isNotEmpty || _title.text.trim().isNotEmpty || _body.text.trim().isNotEmpty,
      child: _frame(
        context,
        SidePanelLayout(
          padding: widget.embedded ? const EdgeInsets.fromLTRB(20, 16, 20, 20) : const EdgeInsets.all(AppSpace.gutter),
          footer: AppButton(label: l10n.submitReview, loading: _busy, onPressed: _submit),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (widget.productName != null) Text(widget.productName!, style: context.text.headlineSmall),
              const Gap(AppSpace.lg),
              Text(l10n.yourRating, style: context.text.titleMedium),
              const Gap(AppSpace.xs),
              RatingInput(value: _rating, onChanged: (v) => setState(() => _rating = v)),
              if (_ratingError != null || _error?.fieldError('rating') != null)
                Text(
                  _ratingError ?? _error!.fieldError('rating')!,
                  style: context.text.bodySmall?.copyWith(color: context.palette.danger),
                ),
              const Gap(AppSpace.lg),
              AppTextField(
                controller: _title,
                label: '${l10n.reviewTitleLabel} (${l10n.optional})',
                maxLength: 120,
                textInputAction: TextInputAction.next,
                textCapitalization: TextCapitalization.sentences,
                errorText: _error?.fieldError('title'),
              ),
              const Gap(AppSpace.sm),
              AppTextField(
                controller: _body,
                label: l10n.reviewBodyLabel,
                maxLines: 6,
                minLines: 4,
                maxLength: 2000,
                keyboardType: TextInputType.multiline,
                textCapitalization: TextCapitalization.sentences,
                errorText: _error?.fieldError('body'),
              ),
              const Gap(AppSpace.md),
              Text('${l10n.addPhotos} · ${l10n.maxPhotos(maxPhotos)}', style: context.text.titleSmall),
              const Gap(AppSpace.xs),
              Wrap(
                spacing: AppSpace.xs,
                runSpacing: AppSpace.xs,
                children: [
                  for (var i = 0; i < _photos.length; i++)
                    Stack(
                      children: [
                        ClipRRect(
                          borderRadius: BorderRadius.circular(AppRadius.sm),
                          child: Image.memory(_photos[i].bytes, width: 84, height: 84, fit: BoxFit.cover),
                        ),
                        PositionedDirectional(
                          top: -6,
                          end: -6,
                          child: IconButton(
                            tooltip: l10n.removePhoto,
                            icon: const Icon(Icons.cancel_rounded, size: 20),
                            onPressed: () => setState(() => _photos.removeAt(i)),
                          ),
                        ),
                      ],
                    ),
                  if (_photos.length < maxPhotos) ...[
                    _AddPhotoButton(
                      icon: Icons.photo_camera_outlined,
                      label: l10n.fromCamera,
                      onTap: () => _pick(ImageSource.camera),
                    ),
                    _AddPhotoButton(
                      icon: Icons.photo_library_outlined,
                      label: l10n.fromGallery,
                      onTap: () => _pick(ImageSource.gallery),
                    ),
                  ],
                ],
              ),
              if (_error != null && _error!.errors.isEmpty) ...[
                const Gap(AppSpace.md),
                Text(
                  describeError(l10n, _error),
                  style: context.text.bodySmall?.copyWith(color: context.palette.danger),
                ),
              ],
              const Gap(AppSpace.xl),
            ],
          ),
        ),
      ),
    );
  }

  Widget _frame(BuildContext context, Widget layout) => widget.embedded
      ? layout
      : Scaffold(
          appBar: AppBar(title: Text(context.l10n.writeReviewTitle)),
          body: ContentWidth(child: layout),
        );
}

class _AddPhotoButton extends StatelessWidget {
  const _AddPhotoButton({required this.icon, required this.label, required this.onTap});
  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Semantics(
    button: true,
    label: label,
    excludeSemantics: true,
    child: InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(AppRadius.sm),
      child: Container(
        width: 84,
        height: 84,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(AppRadius.sm),
          border: Border.all(color: context.palette.line),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(icon, color: context.palette.muted),
            const Gap(4),
            Text(label, style: context.text.labelSmall, textAlign: TextAlign.center),
          ],
        ),
      ),
    ),
  );
}
