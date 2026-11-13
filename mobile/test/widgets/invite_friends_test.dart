import 'package:albahar_customer/features/profile/domain/profile_models.dart';
import 'package:albahar_customer/features/profile/presentation/invite_friends_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';

ReferralInfo _info() => ReferralInfo.fromJson({
  'enabled': true,
  'code': 'AB12CD',
  'share_url': 'https://albahar.qa/?ref=AB12CD',
  'share_message': 'Join with AB12CD',
  'referrer_reward': {
    'type': 'balance',
    'text': 'Get QAR 25.00 on your balance when your friend places their first order.',
  },
  'friend_reward': null,
  'referred_count': 2,
  'pending_count': 1,
  'rewarded_count': 1,
  'friends': [
    {'name': 'Salem', 'status': 'pending'},
    {'name': 'Mona', 'status': 'rewarded'},
  ],
});

void main() {
  test('disabled payload parses to a hidden programme', () {
    expect(ReferralInfo.fromJson({'enabled': false}).enabled, isFalse);
    expect(_info().friendRewardText, isNull);
  });

  for (final locale in ['en', 'ar']) {
    testWidgets('shows code, reward, friends and statuses ($locale)', (t) async {
      await t.pumpWidget(
        wrapApp(
          SingleChildScrollView(child: InviteBody(info: _info())),
          locale: Locale(locale),
        ),
      );
      expect(find.text('AB12CD'), findsOneWidget);
      expect(find.textContaining('QAR 25.00'), findsOneWidget);
      expect(find.text('Salem'), findsOneWidget);
      expect(find.text('Mona'), findsOneWidget);
      expect(find.byIcon(Icons.ios_share_rounded), findsOneWidget);
      expect(find.bySemanticsLabel(RegExp('AB12CD')), findsWidgets);
    });
  }
}
