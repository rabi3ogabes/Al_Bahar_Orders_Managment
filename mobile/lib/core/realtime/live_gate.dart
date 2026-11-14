/// Keeps the newest version seen per record, so a message that arrives late, twice or out of order never moves a screen backwards.
///
/// Every real-time message and every REST answer about an order carries its `seq`: a number the server raises by one with each change
/// of that order. [accept] says whether a message is at least as new as what is already known (an equal one is let through: it
/// repeats the same state, so applying it again changes nothing), [seen] records a version learned from a REST read.
/// A message or answer without a version (an older server) is always accepted.
class SeqGate {
  final _last = <String, int>{};

  /// The newest version known for [key], or null.
  int? lastOf(String key) => _last[key];

  /// True when [seq] is not older than the newest known for [key]; records it.
  bool accept(String key, int? seq) {
    if (seq == null) return true;
    final last = _last[key];
    if (last != null && seq < last) return false;
    _last[key] = seq;
    return true;
  }

  /// A version learned from a REST read: raises the known version, never lowers it.
  void seen(String key, int? seq) {
    if (seq == null) return;
    final last = _last[key];
    if (last == null || seq > last) _last[key] = seq;
  }

  void clear() => _last.clear();
}
