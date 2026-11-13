import 'package:flutter/rendering.dart';
import 'package:flutter/scheduler.dart';
import 'package:flutter/widgets.dart';

/// Reports the laid-out size of [child] after each frame in which it changed, so a map can keep its markers clear of a
/// bottom panel whose height depends on its text and the user's font size.
class MeasureSize extends SingleChildRenderObjectWidget {
  const MeasureSize({super.key, required this.onChange, required super.child});
  final ValueChanged<Size> onChange;

  @override
  RenderObject createRenderObject(BuildContext context) => _RenderMeasureSize(onChange);

  @override
  void updateRenderObject(BuildContext context, covariant RenderProxyBox renderObject) => (renderObject as _RenderMeasureSize).onChange = onChange;
}

class _RenderMeasureSize extends RenderProxyBox {
  _RenderMeasureSize(this.onChange);
  ValueChanged<Size> onChange;
  Size? _last;

  @override
  void performLayout() {
    super.performLayout();
    if (_last == size) return;
    _last = size;
    final s = size;
    SchedulerBinding.instance.addPostFrameCallback((_) {
      if (attached) onChange(s);
    });
  }
}
