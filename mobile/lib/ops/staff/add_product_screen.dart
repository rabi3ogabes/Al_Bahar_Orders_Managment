import 'dart:async';
import 'dart:io';
import 'dart:math';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/ops/ops_input.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';

final _categoriesProvider = FutureProvider.autoDispose<List<ShopCategory>>(
  (ref) => ref.watch(opsRepositoryProvider).categories(),
);
final _aiReadyProvider = FutureProvider.autoDispose<bool>((ref) => ref.watch(opsRepositoryProvider).aiReady());

/// Full-screen flow: photos, title, price, category, a description Claude can write, and an optional photo-studio request.
/// The exit (✕) is always top-start; leaving with unsaved input asks first; the save button stays pinned at the bottom.
class AddProductScreen extends ConsumerStatefulWidget {
  const AddProductScreen({super.key});

  @override
  ConsumerState<AddProductScreen> createState() => _AddProductScreenState();
}

class _AddProductScreenState extends ConsumerState<AddProductScreen> {
  static const _maxPhotos = 8;
  final _form = GlobalKey<FormState>();
  final _titleEn = TextEditingController();
  final _titleAr = TextEditingController();
  final _price = TextEditingController();
  final _shortEn = TextEditingController();
  final _descEn = TextEditingController();
  final _shortAr = TextEditingController();
  final _descAr = TextEditingController();
  final _photos = <File>[];
  ShopCategory? _category;
  bool _publish = false;
  bool _studio = false;
  bool _writing = false;
  bool _saving = false;
  String? _savingNote;

  // A save that failed half way (product created, some photos not uploaded) continues from where it stopped, so a second
  // tap never creates a duplicate product.
  String? _productId;
  String? _firstImageId;
  final _uploaded = <String>{};

  bool get _dirty =>
      _photos.isNotEmpty ||
      [_titleEn, _titleAr, _price, _shortEn, _descEn, _shortAr, _descAr].any((c) => c.text.trim().isNotEmpty);

  @override
  void dispose() {
    for (final c in [_titleEn, _titleAr, _price, _shortEn, _descEn, _shortAr, _descAr]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _pick(ImageSource source) async {
    final room = _maxPhotos - _photos.length;
    if (room <= 0) return;
    try {
      if (source == ImageSource.camera) {
        final x = await ref.read(opsImagePickerProvider).pickImage(source: source, imageQuality: 88, maxWidth: 2400);
        if (x != null && mounted) setState(() => _photos.add(File(x.path)));
      } else {
        // The multi-picker needs a limit of at least 2: with one slot left, pick a single photo.
        final xs = room == 1
            ? [?await ref.read(opsImagePickerProvider).pickImage(source: source, imageQuality: 88, maxWidth: 2400)]
            : await ref.read(opsImagePickerProvider).pickMultiImage(imageQuality: 88, maxWidth: 2400, limit: room);
        if (mounted) setState(() => _photos.addAll(xs.take(room).map((x) => File(x.path))));
      }
    } catch (_) {
      // Permission refused or picker closed: nothing to add.
    }
  }

  void _photoSheet() {
    final t = OpsStrings.of(context);
    unawaited(
      showModalBottomSheet<void>(
        context: context,
        showDragHandle: true,
        useSafeArea: true,
        builder: (c) => SafeArea(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              ListTile(
                minTileHeight: 56,
                leading: const Icon(Icons.photo_camera_rounded),
                title: Text(t.takeProductPhoto),
                onTap: () {
                  Navigator.pop(c);
                  unawaited(_pick(ImageSource.camera));
                },
              ),
              ListTile(
                minTileHeight: 56,
                leading: const Icon(Icons.photo_library_rounded),
                title: Text(t.fromGallery),
                onTap: () {
                  Navigator.pop(c);
                  unawaited(_pick(ImageSource.gallery));
                },
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _chooseCategory(List<ShopCategory> all) async {
    final t = OpsStrings.of(context);
    final picked = await showModalBottomSheet<ShopCategory>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      useSafeArea: true,
      builder: (c) => _CategorySheet(all: all, title: t.chooseCategory, hint: t.searchCategory, none: t.noCategory),
    );
    if (picked != null && mounted) setState(() => _category = picked.id.isEmpty ? null : picked);
  }

  Future<void> _write() async {
    final t = OpsStrings.of(context);
    if (_writing) return;
    if (_titleEn.text.trim().isEmpty && _titleAr.text.trim().isEmpty) {
      showInfoSnack(context, t.needTitleFirst);
      return;
    }
    final typed = [_shortEn, _descEn, _shortAr, _descAr].any((c) => c.text.trim().isNotEmpty);
    if (typed && !await _confirmReplace() || !mounted) return;
    setState(() => _writing = true);
    try {
      final r = await ref
          .read(opsRepositoryProvider)
          .describe(titleEn: _titleEn.text.trim(), titleAr: _titleAr.text.trim(), categoryId: _category?.id);
      // The screen may have been closed while Claude was writing: its controllers are gone.
      if (!mounted) return;
      if (_titleEn.text.trim().isEmpty) _titleEn.text = r.nameEn;
      if (_titleAr.text.trim().isEmpty) _titleAr.text = r.nameAr;
      _shortEn.text = r.shortEn;
      _descEn.text = r.descriptionEn;
      _shortAr.text = r.shortAr;
      _descAr.text = r.descriptionAr;
    } catch (e) {
      if (mounted) showErrorSnack(context, e);
    } finally {
      if (mounted) setState(() => _writing = false);
    }
  }

  Future<bool> _confirmReplace() async {
    final t = OpsStrings.of(context);
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(t.replaceTextQ),
        content: Text(t.replaceTextBody),
        actions: [
          TextButton(autofocus: true, onPressed: () => Navigator.pop(c, false), child: Text(t.cancel)),
          TextButton(onPressed: () => Navigator.pop(c, true), child: Text(t.replace)),
        ],
      ),
    );
    return ok ?? false;
  }

  /// A unique SKU the staff never has to type: time plus a random tail (two employees saving in the same millisecond differ).
  static String _newSku() {
    final tail = Random().nextInt(36 * 36 * 36).toRadixString(36).toUpperCase().padLeft(3, '0');
    return 'AB-${DateTime.now().millisecondsSinceEpoch.toRadixString(36).toUpperCase()}$tail';
  }

  Future<void> _save() async {
    if (_saving || !(_form.currentState?.validate() ?? false)) return;
    final t = OpsStrings.of(context);
    FocusScope.of(context).unfocus();
    setState(() {
      _saving = true;
      _savingNote = null;
    });
    final repo = ref.read(opsRepositoryProvider);
    final titleEn = _titleEn.text.trim().isEmpty ? _titleAr.text.trim() : _titleEn.text.trim();
    final titleAr = _titleAr.text.trim().isEmpty ? _titleEn.text.trim() : _titleAr.text.trim();
    try {
      _productId ??= await repo.createProduct(
        sku: _newSku(),
        price: normalizeDigits(_price.text.trim()),
        titleEn: titleEn,
        titleAr: titleAr,
        text: ProductDraftText(
          shortEn: _shortEn.text.trim(),
          descriptionEn: _descEn.text.trim(),
          shortAr: _shortAr.text.trim(),
          descriptionAr: _descAr.text.trim(),
        ),
        categoryId: _category?.id,
        publish: _publish,
      );
      final id = _productId!;
      final todo = _photos.where((f) => !_uploaded.contains(f.path)).toList();
      for (var i = 0; i < todo.length; i++) {
        if (mounted) setState(() => _savingNote = t.uploadingPhoto(i + 1, todo.length));
        final imageId = await repo.uploadProductImage(id, todo[i]);
        _uploaded.add(todo[i].path);
        _firstImageId ??= imageId;
      }
      var studioFailed = false;
      if (_studio && _firstImageId != null) {
        // A failed studio request must not undo a saved product: tell the user and carry on.
        try {
          await repo.askPhotoStudio(id, _firstImageId!, 'clean_background');
        } catch (_) {
          studioFailed = true;
        }
      }
      if (!mounted) return;
      showInfoSnack(context, studioFailed ? t.studioFailed : t.productSaved);
      Navigator.of(context).pop(true);
    } catch (e) {
      if (mounted) {
        final message = describeError(context.l10n, e);
        showInfoSnack(context, _productId != null ? '$message\n${t.partialSaved}' : message);
        setState(() {
          _saving = false;
          _savingNote = null;
        });
      }
    }
  }

  Future<bool> _confirmLeave() async {
    final t = OpsStrings.of(context);
    if (_saving) {
      // Leaving mid-save would orphan a half-uploaded product: ask the user to wait.
      showInfoSnack(context, t.saveBlocked);
      return false;
    }
    if (!_dirty) return true;
    final leave = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(t.leaveQ),
        content: Text(t.leaveBody),
        actions: [
          // Initial focus on the safe choice: Enter never throws work away by accident.
          TextButton(autofocus: true, onPressed: () => Navigator.pop(c, false), child: Text(t.stay)),
          TextButton(
            style: TextButton.styleFrom(foregroundColor: Theme.of(c).colorScheme.error),
            onPressed: () => Navigator.pop(c, true),
            child: Text(t.leave),
          ),
        ],
      ),
    );
    return leave ?? false;
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final cats = ref.watch(_categoriesProvider);
    final aiReady = ref.watch(_aiReadyProvider).value ?? false;
    final scheme = Theme.of(context).colorScheme;
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) return;
        final nav = Navigator.of(context);
        if (await _confirmLeave()) nav.pop();
      },
      child: Scaffold(
        appBar: AppBar(
          leading: IconButton(
            tooltip: t.close,
            icon: const Icon(Icons.close_rounded),
            onPressed: () async {
              final nav = Navigator.of(context);
              if (await _confirmLeave()) nav.pop();
            },
          ),
          title: Text(t.newProduct),
        ),
        body: Form(
          key: _form,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.sm, AppSpace.gutter, AppSpace.xxl),
            children: [
              Text(t.photos, style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: AppSpace.xxs),
              Text(t.photosHint, style: Theme.of(context).textTheme.bodySmall),
              const SizedBox(height: AppSpace.sm),
              SizedBox(
                height: 104,
                child: ListView(
                  scrollDirection: Axis.horizontal,
                  children: [
                    if (_photos.length < _maxPhotos) _AddTile(label: t.addPhoto, onTap: _photoSheet),
                    for (var i = 0; i < _photos.length; i++)
                      _PhotoTile(
                        file: _photos[i],
                        cover: i == 0,
                        removeLabel: t.removePhoto,
                        onRemove: () => setState(() => _photos.removeAt(i)),
                      ),
                  ],
                ),
              ),
              const SizedBox(height: AppSpace.lg),
              TextFormField(
                controller: _titleEn,
                maxLength: 255,
                maxLengthEnforcement: MaxLengthEnforcement.enforced,
                buildCounter: _noCounter,
                textInputAction: TextInputAction.next,
                textCapitalization: TextCapitalization.words,
                decoration: InputDecoration(labelText: t.titleEn),
                validator: (v) => (v ?? '').trim().isEmpty && _titleAr.text.trim().isEmpty ? t.fieldRequired : null,
              ),
              const SizedBox(height: AppSpace.sm),
              TextFormField(
                controller: _titleAr,
                maxLength: 255,
                buildCounter: _noCounter,
                textDirection: TextDirection.rtl,
                textInputAction: TextInputAction.next,
                decoration: InputDecoration(labelText: t.titleAr),
              ),
              const SizedBox(height: AppSpace.sm),
              TextFormField(
                controller: _price,
                maxLength: 12,
                buildCounter: _noCounter,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                inputFormatters: const [AmountInputFormatter()],
                textInputAction: TextInputAction.next,
                decoration: InputDecoration(labelText: t.price),
                validator: (v) {
                  final amount = parseAmount(v ?? '');
                  if (amount == null) return t.priceInvalid;
                  return amount > 0 ? null : t.priceZero;
                },
              ),
              const SizedBox(height: AppSpace.sm),
              InkWell(
                borderRadius: BorderRadius.circular(AppRadius.input),
                onTap: cats.hasValue
                    ? () => _chooseCategory(cats.value!)
                    : cats.hasError
                    ? () => ref.invalidate(_categoriesProvider)
                    : null,
                child: InputDecorator(
                  decoration: InputDecoration(
                    labelText: t.category,
                    suffixIcon: Icon(cats.hasError ? Icons.refresh_rounded : Icons.expand_more_rounded),
                  ),
                  child: Text(
                    _category?.name ?? (cats.isLoading ? '…' : (cats.hasError ? t.categoriesFailed : t.chooseCategory)),
                    style: _category == null
                        ? TextStyle(color: cats.hasError ? scheme.error : scheme.onSurfaceVariant)
                        : null,
                  ),
                ),
              ),
              const SizedBox(height: AppSpace.lg),
              Text(t.description, style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: AppSpace.xxs),
              Text(aiReady ? t.aiHint : t.aiNotReady, style: Theme.of(context).textTheme.bodySmall),
              const SizedBox(height: AppSpace.sm),
              SizedBox(
                height: 48,
                child: OutlinedButton.icon(
                  onPressed: aiReady && !_writing ? _write : null,
                  icon: _writing
                      ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                      : const Icon(Icons.auto_awesome_rounded),
                  label: Text(_writing ? t.writing : t.writeWithAi),
                ),
              ),
              const SizedBox(height: AppSpace.sm),
              TextFormField(
                controller: _shortEn,
                maxLength: 300,
                decoration: InputDecoration(labelText: '${t.shortDescription} · EN'),
              ),
              TextFormField(
                controller: _descEn,
                minLines: 3,
                maxLines: 8,
                decoration: InputDecoration(labelText: '${t.description} · EN'),
              ),
              const SizedBox(height: AppSpace.sm),
              TextFormField(
                controller: _shortAr,
                maxLength: 300,
                textDirection: TextDirection.rtl,
                decoration: InputDecoration(labelText: '${t.shortDescription} · AR'),
              ),
              TextFormField(
                controller: _descAr,
                minLines: 3,
                maxLines: 8,
                textDirection: TextDirection.rtl,
                decoration: InputDecoration(labelText: '${t.description} · AR'),
              ),
              const SizedBox(height: AppSpace.lg),
              // A form choice (waits for Save): a checkbox, not an instant switch.
              CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
                value: _studio,
                onChanged: _photos.isEmpty ? null : (v) => setState(() => _studio = v ?? false),
                title: Text(t.studioOption),
                subtitle: Text(t.studioOptionHint),
              ),
              const SizedBox(height: AppSpace.sm),
              SizedBox(
                width: double.infinity,
                child: SegmentedButton<bool>(
                  segments: [
                    ButtonSegment(value: false, label: Text(t.saveDraft)),
                    ButtonSegment(value: true, label: Text(t.publishNow)),
                  ],
                  selected: {_publish},
                  onSelectionChanged: (s) => setState(() => _publish = s.first),
                ),
              ),
            ],
          ),
        ),
        bottomNavigationBar: SafeArea(
          minimum: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.xs, AppSpace.gutter, AppSpace.sm),
          child: SizedBox(
            height: 52,
            child: FilledButton(
              onPressed: _saving ? null : _save,
              child: Text(_saving ? (_savingNote ?? t.saving) : t.saveProduct),
            ),
          ),
        ),
      ),
    );
  }
}

/// Hides Flutter's "0/255" counter on fields where the limit is only a safety net.
Widget? _noCounter(BuildContext context, {required int currentLength, required bool isFocused, required int? maxLength}) =>
    null;

class _AddTile extends StatelessWidget {
  const _AddTile({required this.label, required this.onTap});
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Padding(
      padding: const EdgeInsetsDirectional.only(end: AppSpace.xs),
      child: Semantics(
        button: true,
        label: label,
        child: InkWell(
          borderRadius: BorderRadius.circular(AppRadius.input),
          onTap: onTap,
          child: Container(
            width: 104,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(AppRadius.input),
              border: Border.all(color: scheme.outlineVariant, width: 1.5),
            ),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(Icons.add_a_photo_rounded, color: scheme.primary),
                const SizedBox(height: 6),
                Text(label, style: Theme.of(context).textTheme.labelSmall, textAlign: TextAlign.center),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _PhotoTile extends StatelessWidget {
  const _PhotoTile({required this.file, required this.cover, required this.removeLabel, required this.onRemove});
  final File file;
  final bool cover;
  final String removeLabel;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsetsDirectional.only(end: AppSpace.xs),
    child: Stack(
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(AppRadius.input),
          child: Image.file(
            file,
            width: 104,
            height: 104,
            fit: BoxFit.cover,
            cacheWidth: 320,
            errorBuilder: (_, _, _) => SizedBox(
              width: 104,
              height: 104,
              child: Center(child: Icon(Icons.broken_image_outlined, color: Theme.of(context).colorScheme.error)),
            ),
          ),
        ),
        PositionedDirectional(
          top: 2,
          end: 2,
          child: IconButton.filledTonal(
            visualDensity: VisualDensity.compact,
            tooltip: removeLabel,
            icon: const Icon(Icons.close_rounded, size: 18),
            onPressed: onRemove,
          ),
        ),
        if (cover)
          PositionedDirectional(
            bottom: 6,
            start: 6,
            child: DecoratedBox(
              decoration: BoxDecoration(color: Colors.black54, borderRadius: BorderRadius.circular(6)),
              child: const Padding(
                padding: EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                child: Icon(Icons.star_rounded, size: 14, color: Colors.white),
              ),
            ),
          ),
      ],
    ),
  );
}

/// Searchable category list (many options → a combobox-style sheet).
class _CategorySheet extends StatefulWidget {
  const _CategorySheet({required this.all, required this.title, required this.hint, required this.none});
  final List<ShopCategory> all;
  final String title;
  final String hint;
  final String none;

  @override
  State<_CategorySheet> createState() => _CategorySheetState();
}

class _CategorySheetState extends State<_CategorySheet> {
  String _q = '';

  @override
  Widget build(BuildContext context) {
    final rows = widget.all.where((c) => c.name.toLowerCase().contains(_q.toLowerCase())).toList();
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SizedBox(
        height: MediaQuery.sizeOf(context).height * 0.7,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(AppSpace.gutter, 0, AppSpace.gutter, AppSpace.xs),
              child: TextField(
                autofocus: true,
                decoration: InputDecoration(prefixIcon: const Icon(Icons.search_rounded), hintText: widget.hint),
                onChanged: (v) => setState(() => _q = v),
              ),
            ),
            Expanded(
              child: ListView(
                children: [
                  ListTile(
                    minTileHeight: 52,
                    title: Text(widget.none),
                    onTap: () => Navigator.pop(context, const ShopCategory(id: '', name: '')),
                  ),
                  for (final c in rows)
                    ListTile(minTileHeight: 52, title: Text(c.name), onTap: () => Navigator.pop(context, c)),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
