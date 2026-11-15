import 'dart:typed_data';

import 'package:albahar_customer/features/orders/data/invoice_delivery_io.dart'
    if (dart.library.js_interop) 'package:albahar_customer/features/orders/data/invoice_delivery_web.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// What happened to the invoice file once it was ready.
enum InvoiceDelivered {
  /// The browser saved it (the web app): the page says so, since nothing else on screen shows it.
  downloaded,

  /// The phone's own sheet took it (save to Files, open in a PDF viewer, print, WhatsApp): that sheet is the feedback.
  shared,
}

/// Hands a finished PDF to the person: a normal download in a browser, the system sheet on a phone.
typedef InvoiceDelivery = Future<InvoiceDelivered> Function(Uint8List bytes, String fileName);

final invoiceDeliveryProvider = Provider<InvoiceDelivery>((ref) => deliverInvoiceFile);

/// `AlBahar-<order number>.pdf`, with anything a file system dislikes in the number replaced.
String invoiceFileName(String orderNumber) => 'AlBahar-${orderNumber.replaceAll(RegExp(r'[^A-Za-z0-9._-]+'), '-')}.pdf';
