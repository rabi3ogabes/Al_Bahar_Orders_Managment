import 'dart:async';
import 'dart:io';

import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
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
  final _picker = ImagePicker();
  ShopCategory? _category;
  bool _publish = false;
  bool _studio = false;
  bool _writing = false;
  bool _saving = false;

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
        final x = await _picker.pickImage(source: source, imageQuality: 88, maxWidth: 2400);
        if (x != null) setState(() => _photos.add(File(x.path)));
      } else {
        final xs = await _picker.pickMultiImage(imageQuality: 88, maxWidth: 2400, limit: room);
        setState(() => _photos.addAll(xs.take(room).map((x) => File(x.path))));
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
    if (_titleEn.text.trim().isEmpty && _titleAr.text.trim().isEmpty) {
      showInfoSnack(context, t.needTitleFirst);
      return;
    }
    setState(() => _writing = true);
    try {
      final r = await ref
          .read(opsRepositoryProvider)
          .describe(titleEn: _titleEn.text.trim(), titleAr: _titleAr.text.trim(), categoryId: _category?.id);
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

  Future<void> _save() async {
    if (!(_form.currentState?.validate() ?? false) || _saving) return;
    final t = OpsStrings.of(context);
    FocusScope.of(context).unfocus();
    setState(() => _saving = true);
    final repo = ref.read(opsRepositoryProvider);
    final sku = 'AB-${DateTime.now().millisecondsSinceEpoch.toRadixString(36).toUpperCase()}';
    final titleEn = _titleEn.text.trim().isEmpty ? _titleAr.text.trim() : _titleEn.text.trim();
    final titleAr = _titleAr.text.trim().isEmpty ? _titleEn.text.trim() : _titleAr.text.trim();
    try {
      final made = await repo.createProduct(
        sku: sku,
        price: _price.text.trim(),
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
        photos: _photos,
      );
      if (_studio && made.imageIds.isNotEmpty) {
        // A failed studio request must not undo a saved product.
        try {
          await repo.askPhotoStudio(made.id, made.imageIds.first, 'clean_background');
        } catch (_) {}
      }
      if (!mounted) return;
      showInfoSnack(context, t.productSaved);
      Navigator.of(context).pop(true);
    } catch (e) {
      if (mounted) {
        showErrorSnack(context, e);
        setState(() => _saving = false);
      }
    }
  }

  Future<bool> _confirmLeave() async {
    if (!_dirty || _saving) return true;
    final t = OpsStrings.of(context);
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
                textInputAction: TextInputAction.next,
                textCapitalization: TextCapitalization.words,
                decoration: InputDecoration(labelText: t.titleEn),
                validator: (v) => (v ?? '').trim().isEmpty && _titleAr.text.trim().isEmpty ? t.fieldRequired : null,
              ),
              const SizedBox(height: AppSpace.sm),
              TextFormField(
                controller: _titleAr,
                textDirection: TextDirection.rtl,
                textInputAction: TextInputAction.next,
                decoration: InputDecoration(labelText: t.titleAr),
              ),
              const SizedBox(height: AppSpace.sm),
              TextFormField(
                controller: _price,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
                textInputAction: TextInputAction.next,
                decoration: InputDecoration(labelText: t.price),
                validator: (v) => RegExp(r'^\d{1,9}(\.\d{1,2})?$').hasMatch((v ?? '').trim()) ? null : t.priceInvalid,
              ),
              const SizedBox(height: AppSpace.sm),
              InkWell(
                borderRadius: BorderRadius.circular(AppRadius.input),
                onTap: cats.hasValue ? () => _chooseCategory(cats.value!) : null,
                child: InputDecorator(
                  decoration: InputDecoration(labelText: t.category, suffixIcon: const Icon(Icons.expand_more_rounded)),
                  child: Text(
                    _category?.name ?? (cats.isLoading ? '…' : t.chooseCategory),
                    style: _category == null ? TextStyle(color: scheme.onSurfaceVariant) : null,
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
            child: FilledButton(onPressed: _saving ? null : _save, child: Text(_saving ? t.saving : t.saveProduct)),
          ),
        ),
      ),
    );
  }
}

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
          child: Image.file(file, width: 104, height: 104, fit: BoxFit.cover, cacheWidth: 320),
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
