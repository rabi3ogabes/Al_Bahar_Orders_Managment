import 'dart:typed_data';

import 'package:albahar_customer/features/orders/data/invoice_delivery.dart';
import 'package:share_plus/share_plus.dart';

/// Phones and desktops: the system share sheet (save to Files, open in a PDF viewer, print, send on).
Future<InvoiceDelivered> deliverInvoiceFile(Uint8List bytes, String fileName) async {
  await SharePlus.instance.share(
    ShareParams(
      files: [XFile.fromData(bytes, mimeType: 'application/pdf', name: fileName)],
      fileNameOverrides: [fileName],
    ),
  );
  return InvoiceDelivered.shared;
}
