import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:flutter/foundation.dart';

String? _text(Object? v) {
  final t = asString(v).trim();
  return t.isEmpty ? null : t;
}

/// What the customer can report, in the order the form shows them. A damaged or wrong item cannot be judged without a picture.
const claimTypes = ['damaged', 'wrong_item', 'missing_item', 'quality', 'changed_mind'];

bool claimNeedsPhoto(String type) => type == 'damaged' || type == 'wrong_item';

/// At most this many photos on one claim, each under [maxClaimPhotoBytes] (the server says the same).
const maxClaimPhotos = 5;
const maxClaimPhotoBytes = 8 * 1024 * 1024;
const maxClaimNote = 500;

/// One step of a claim's timeline (`return_request.timeline`): `done`, `current` (what the claim is waiting for), `upcoming` or `stopped`
/// (where it ended: not approved, cancelled). The words are the app's own; only the team's free text comes from the server.
@immutable
class ClaimStep {
  const ClaimStep({required this.key, required this.state, this.at, this.detail});

  factory ClaimStep.fromJson(JsonMap j) => ClaimStep(
    key: asString(j['key']),
    state: asString(j['state'], 'upcoming'),
    at: asDate(j['at']),
    detail: _text(j['detail']),
  );

  final String key;
  final String state;
  final DateTime? at;
  final String? detail;

  bool get isDone => state == 'done';
  bool get isCurrent => state == 'current';
  bool get isStopped => state == 'stopped';
}

/// A photo of a claim: private, fetched with the customer's sign-in by its [path] (relative to /api/v1).
@immutable
class ClaimPhoto {
  const ClaimPhoto({required this.id, required this.path});
  factory ClaimPhoto.fromJson(JsonMap j) => ClaimPhoto(id: asString(j['id']), path: asString(j['path']));
  final String id;
  final String path;
}

/// Where the money of a claim stands: the amount, how it goes back (`original`, `manual_cash`, `manual_bank_transfer`) and whether it was sent.
@immutable
class ClaimRefund {
  const ClaimRefund({required this.status, required this.amount, required this.method});

  static ClaimRefund? maybe(Object? json) {
    final m = asMapOrNull(json);
    if (m == null) return null;
    return ClaimRefund(
      status: asString(m['status']),
      amount: Money.lenient(m['amount']) ?? Money.zero,
      method: asString(m['method'], 'original'),
    );
  }

  final String status;
  final Money amount;
  final String method;

  bool get isProcessed => status == 'processed';
}

/// A return or damage claim of an order (`return_request` is the latest, `returns` all of them): where it stands, what the customer sent,
/// what the team answered, how the item comes back, the refund and the timeline.
@immutable
class ReturnRequestInfo {
  const ReturnRequestInfo({
    required this.id,
    required this.number,
    required this.status,
    this.requestedAt,
    this.decidedAt,
    this.decisionNote,
    this.kind = 'other',
    this.note,
    this.photos = const [],
    this.messages = const [],
    this.canAddEvidence = false,
    this.canCancel = false,
    this.method,
    this.methodNote,
    this.branchName,
    this.refund,
    this.steps = const [],
    this.itemsText,
  });

  static ReturnRequestInfo? maybe(Object? json) {
    final m = asMapOrNull(json);
    if (m == null) return null;
    final items = [
      for (final i in asList(m['items']))
        if (i is Map) '${asInt(i['quantity'])} × ${asString(i['name'])}',
    ];
    return ReturnRequestInfo(
      id: asString(m['id']),
      number: asString(m['number']),
      status: asString(m['status']),
      requestedAt: asDate(m['requested_at']),
      decidedAt: asDate(m['decided_at']),
      decisionNote: _text(m['decision_note']),
      kind: asString(m['kind'], 'other'),
      note: _text(m['note']),
      photos: mapList(m['photos'], ClaimPhoto.fromJson),
      messages: [
        for (final x in asList(m['messages']))
          if (x is Map && _text(x['text']) != null) _text(x['text'])!,
      ],
      canAddEvidence: asBool(m['can_add_evidence']),
      canCancel: asBool(m['can_cancel']),
      method: _text(m['method']),
      methodNote: _text(m['method_note']),
      branchName: _text(asMapOrNull(m['branch'])?['name']),
      refund: ClaimRefund.maybe(m['refund']),
      steps: mapList(m['timeline'], ClaimStep.fromJson),
      itemsText: items.isEmpty ? null : items.join(' · '),
    );
  }

  final String id;
  final String number;

  /// `requested`, `under_review`, `approved`, `rejected`, `received`, `refunded`, `closed` or `cancelled` (another word from a newer server reads as "other").
  final String status;
  final DateTime? requestedAt;
  final DateTime? decidedAt;

  /// What the team wrote when it decided, if anything.
  final String? decisionNote;

  /// `damaged`, `wrong_item`, `missing_item`, `quality`, `changed_mind` or `other`.
  final String kind;

  /// What the customer wrote when they sent it, and later while it waited.
  final String? note;
  final List<String> messages;
  final List<ClaimPhoto> photos;
  final bool canAddEvidence;
  final bool canCancel;

  /// How the item comes back: `pickup`, `branch` or `none` (decided with the approval).
  final String? method;
  final String? methodNote;
  final String? branchName;
  final ClaimRefund? refund;
  final List<ClaimStep> steps;
  final String? itemsText;

  static const known = {
    'requested',
    'under_review',
    'approved',
    'rejected',
    'received',
    'refunded',
    'closed',
    'cancelled',
  };
  bool get isRejected => status == 'rejected';

  /// Waiting on the team or on the goods coming back.
  bool get isOpen => status == 'requested' || status == 'under_review' || status == 'approved';

  /// Not decided yet: the customer may still add photos or a note, and cancel.
  bool get isEditable => status == 'requested' || status == 'under_review';
}

/// A photo the customer picked, ready to send (read into memory: the phone's camera pictures are small once the picker has scaled them).
@immutable
class PickedPhoto {
  const PickedPhoto({required this.name, required this.bytes, this.mimeType});
  final String name;
  final Uint8List bytes;
  final String? mimeType;
}

/// Why this set of pictures cannot be sent (a key the screens translate), or null when they can. [already] = pictures the claim has.
String? photoProblem(List<PickedPhoto> photos, {int already = 0}) {
  const allowed = {'image/jpeg', 'image/png', 'image/webp'};
  if (photos.any((p) => p.mimeType != null && !allowed.contains(p.mimeType))) return 'type';
  if (photos.any((p) => p.bytes.length > maxClaimPhotoBytes)) return 'size';
  if (already + photos.length > maxClaimPhotos) return 'count';
  return null;
}
