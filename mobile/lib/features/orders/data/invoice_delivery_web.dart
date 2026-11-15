import 'dart:async';
import 'dart:js_interop';
import 'dart:typed_data';

import 'package:albahar_customer/features/orders/data/invoice_delivery.dart';

@JS('Blob')
extension type _Blob._(JSObject _) implements JSObject {
  external factory _Blob(JSArray<JSAny> parts, _BlobOptions options);
}

extension type _BlobOptions._(JSObject _) implements JSObject {
  external factory _BlobOptions({String type});
}

@JS('URL')
extension type _Url._(JSObject _) implements JSObject {
  external static String createObjectURL(_Blob blob);
  external static void revokeObjectURL(String url);
}

extension type _Anchor._(JSObject _) implements JSObject {
  external set href(String value);
  external set download(String value);
  external void click();
  external void remove();
}

extension type _Body._(JSObject _) implements JSObject {
  external void appendChild(JSObject child);
}

extension type _Document._(JSObject _) implements JSObject {
  external _Anchor createElement(String tag);
  external _Body get body;
}

@JS('document')
external _Document get _document;

/// The web app: a normal browser download of the PDF, named like the order.
Future<InvoiceDelivered> deliverInvoiceFile(Uint8List bytes, String fileName) async {
  final blob = _Blob(<JSAny>[bytes.toJS].toJS, _BlobOptions(type: 'application/pdf'));
  final url = _Url.createObjectURL(blob);
  final anchor = _document.createElement('a')
    ..href = url
    ..download = fileName;
  _document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  // Some browsers read the file a moment after the click: the temporary address goes a little later.
  Timer(const Duration(seconds: 30), () => _Url.revokeObjectURL(url));
  return InvoiceDelivered.downloaded;
}
