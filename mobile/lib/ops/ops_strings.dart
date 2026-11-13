import 'package:albahar_customer/ops/ops_models.dart';
import 'package:flutter/widgets.dart';

/// English / Arabic text for the two staff apps (kept small and local; the customer app uses ARB files).
class OpsStrings {
  const OpsStrings(this.code);
  final String code;

  static OpsStrings of(BuildContext context) => OpsStrings(Localizations.localeOf(context).languageCode);

  bool get ar => code == 'ar';
  String _t(String en, String arText) => ar ? arText : en;

  String get driverApp => _t('Al Bahar Driver', 'سائق البحار');
  String get staffApp => _t('Al Bahar Branch', 'موظف فرع البحار');
  String get signIn => _t('Sign in', 'تسجيل الدخول');
  String get email => _t('Email', 'البريد الإلكتروني');
  String get password => _t('Password', 'كلمة المرور');
  String get signOut => _t('Sign out', 'تسجيل الخروج');
  String get signInHintDriver =>
      _t('Use the account your branch manager created for you.', 'استخدم الحساب الذي أنشأه لك مدير الفرع.');
  String get signInHintStaff => _t('Sign in with your branch staff account.', 'سجّل الدخول بحساب موظف الفرع.');
  String get notDriver => _t('This account is not set up as a delivery driver.', 'هذا الحساب غير مفعّل كسائق توصيل.');
  String get notStaff =>
      _t('This account cannot manage branch orders.', 'هذا الحساب لا يملك صلاحية إدارة طلبات الفرع.');
  String get retry => _t('Try again', 'حاول مرة أخرى');
  String get refresh => _t('Refresh', 'تحديث');

  // Driver
  String get onDuty => _t('On duty', 'متواجد');
  String get offDuty => _t('Off duty', 'غير متواجد');
  String get dutyOnHint => _t('You will receive deliveries from your branches.', 'ستصلك طلبات التوصيل من فروعك.');
  String get dutyOffHint => _t('Go on duty to receive deliveries.', 'فعّل التواجد لاستلام الطلبات.');
  String get trackingTitle => _t('Sharing your live location', 'مشاركة موقعك المباشر');
  String get trackingBody => _t('Customers and dispatch can follow your delivery.', 'يتابع العملاء والمرسل توصيلك.');
  String get myJobs => _t('My deliveries', 'توصيلاتي');
  String get history => _t('History', 'السجل');
  String get noJobs => _t('No deliveries yet', 'لا توجد توصيلات الآن');
  String get noJobsHint => _t(
    'New deliveries appear here as soon as they are assigned to you.',
    'تظهر التوصيلات الجديدة هنا فور إسنادها إليك.',
  );
  String get cod => _t('Collect cash', 'تحصيل نقدي');
  String get call => _t('Call', 'اتصال');
  String get navigate => _t('Navigate', 'الاتجاه');
  String get accept => _t('Accept', 'قبول');
  String get pickedUp => _t('Picked up from branch', 'تم الاستلام من الفرع');
  String get onTheWay => _t('On my way', 'في الطريق');
  String get delivered => _t('Delivered', 'تم التسليم');
  String get failed => _t('Could not deliver', 'تعذّر التسليم');
  String get receivedBy => _t('Received by', 'استلم الطلب');
  String get takePhoto => _t('Take proof photo', 'التقط صورة الإثبات');
  String get photoTaken => _t('Photo added', 'تمت إضافة الصورة');
  String get photoRequired => _t('A proof photo is required.', 'صورة الإثبات مطلوبة.');
  String get cashCollected => _t('Cash collected', 'المبلغ المحصّل');
  String get confirm => _t('Confirm', 'تأكيد');
  String get cancel => _t('Cancel', 'إلغاء');
  String get reason => _t('Reason', 'السبب');
  String reasonLabel(String code) => switch (code) {
    'customer_unreachable' => _t('Customer unreachable', 'لا يمكن الوصول للعميل'),
    'customer_absent' => _t('Customer not there', 'العميل غير موجود'),
    'wrong_address' => _t('Wrong address', 'عنوان خاطئ'),
    'refused' => _t('Refused the order', 'رفض الطلب'),
    'damaged' => _t('Damaged', 'تالف'),
    'access_denied' => _t('No access', 'لا يمكن الدخول'),
    _ => _t('Other', 'أخرى'),
  };
  String statusLabel(String s) => switch (s) {
    'assigned' => _t('New', 'جديد'),
    'accepted' => _t('Accepted', 'تم القبول'),
    'picked_up' => _t('Picked up', 'تم الاستلام'),
    'out_for_delivery' => _t('On the way', 'في الطريق'),
    'delivered' => _t('Delivered', 'تم التسليم'),
    'failed' => _t('Failed', 'تعذّر'),
    'rescheduled' => _t('Rescheduled', 'أعيدت جدولته'),
    'returned' => _t('Returned', 'أُعيد'),
    'cancelled' => _t('Cancelled', 'ملغي'),
    'pending' => _t('Waiting for a driver', 'بانتظار سائق'),
    _ => s,
  };

  String newJobAlert(String number) => _t('New delivery #$number assigned to you', 'تم إسناد توصيل جديد #$number إليك');
  String newOrderAlert(String number) => _t('New order #$number', 'طلب جديد #$number');
  String get liveUpdate => _t('Live', 'مباشر');

  // Staff
  String get orders => _t('Orders', 'الطلبات');
  String get drivers => _t('Drivers', 'السائقون');
  String get stageNew => _t('New', 'جديدة');
  String get stagePreparing => _t('Preparing', 'قيد التحضير');
  String get stageReady => _t('Ready', 'جاهزة');
  String get stageOut => _t('On the way', 'في الطريق');
  String get stageFailed => _t('Failed', 'تعذّر');
  String get deliveryFailedTag => _t('Delivery failed', 'تعذّر التوصيل');
  String get waitingForDriver => _t('Waiting for a driver', 'بانتظار سائق');
  String get driversOnDuty => _t('Drivers on duty', 'سائقون متواجدون');
  String get autoDispatch => _t(
    'Auto dispatch: ready orders go to the least busy driver.',
    'التوزيع التلقائي: تُسند الطلبات الجاهزة للسائق الأقل انشغالاً.',
  );
  String get manualDispatch => _t('You assign drivers to ready orders.', 'أنت تُسند السائقين للطلبات الجاهزة.');
  String get noOrders => _t('Nothing here right now', 'لا يوجد شيء هنا الآن');
  String get acceptOrder => _t('Accept order', 'قبول الطلب');
  String get startPreparing => _t('Start preparing', 'بدء التحضير');
  String get markReady => _t('Mark ready', 'جاهز للتسليم');
  String get assignDriver => _t('Assign driver', 'إسناد سائق');
  String get changeDriver => _t('Change driver', 'تغيير السائق');
  String get chooseDriver => _t('Choose a driver', 'اختر سائقاً');
  String get noDrivers => _t(
    'No drivers in this branch yet. Add them in the admin panel.',
    'لا يوجد سائقون في هذا الفرع بعد. أضفهم من لوحة الإدارة.',
  );
  String openJobs(int n) => _t('$n open', '$n مفتوحة');
  String items(int n) => _t('$n items', '$n قطعة');
  String assignedTo(String name) => _t('Driver: $name', 'السائق: $name');
  String get offDutyTag => _t('Off duty', 'غير متواجد');
  String get done => _t('Done', 'تم');
  // Add product
  String get addProduct => _t('Add product', 'إضافة منتج');
  String get newProduct => _t('New product', 'منتج جديد');
  String get photos => _t('Photos', 'الصور');
  String get addPhoto => _t('Add photo', 'إضافة صورة');
  String get takeProductPhoto => _t('Take a photo', 'التقاط صورة');
  String get fromGallery => _t('Choose from gallery', 'اختيار من المعرض');
  String get removePhoto => _t('Remove photo', 'حذف الصورة');
  String get photosHint => _t('Up to 8 photos. The first one is the cover.', 'حتى 8 صور. الأولى هي الغلاف.');
  String get titleEn => _t('Title (English)', 'العنوان (بالإنجليزية)');
  String get titleAr => _t('Title (Arabic)', 'العنوان (بالعربية)');
  String get price => _t('Price (QAR)', 'السعر (ر.ق)');
  String get category => _t('Category', 'الفئة');
  String get chooseCategory => _t('Choose a category', 'اختر فئة');
  String get searchCategory => _t('Search categories', 'ابحث في الفئات');
  String get noCategory => _t('No category', 'بدون فئة');
  String get description => _t('Description', 'الوصف');
  String get shortDescription => _t('Short description', 'وصف مختصر');
  String get writeWithAi => _t('Translate and write the description with AI', 'ترجم واكتب الوصف بالذكاء الاصطناعي');
  String get writing => _t('Writing…', 'جارٍ الكتابة…');
  String get aiHint => _t(
    'Claude writes it from the title, in English and Arabic. Read it and change what you like.',
    'يكتبه Claude من العنوان بالعربية والإنجليزية. اقرأه وعدّل ما تشاء.',
  );
  String get aiNotReady => _t(
    'AI writing is not set up yet. Ask the admin to add the Claude key.',
    'الكتابة الذكية غير مفعّلة بعد. اطلب من المدير إضافة مفتاح Claude.',
  );
  String get needTitleFirst => _t('Write a title first.', 'اكتب العنوان أولاً.');
  String get saveDraft => _t('Save as draft', 'حفظ كمسودة');
  String get publishNow => _t('Publish now', 'نشر الآن');
  String get saveProduct => _t('Save product', 'حفظ المنتج');
  String get saving => _t('Saving…', 'جارٍ الحفظ…');
  String get studioOption => _t('Ask the photo studio to clean the background', 'اطلب من استوديو الصور تنظيف الخلفية');
  String get studioOptionHint => _t(
    'An edited copy appears in the dashboard for approval. Nothing goes live by itself.',
    'تظهر نسخة معدّلة في لوحة التحكم للموافقة. لا ينشر شيء تلقائياً.',
  );
  String get productSaved => _t('Product saved.', 'تم حفظ المنتج.');
  String get leaveQ => _t('Leave without saving?', 'المغادرة دون حفظ؟');
  String get leaveBody => _t('The photos and text you entered will be lost.', 'ستفقد الصور والنصوص التي أدخلتها.');
  String get leave => _t('Leave', 'مغادرة');
  String get stay => _t('Keep editing', 'متابعة التعديل');
  String get fieldRequired => _t('Required', 'مطلوب');
  String get priceInvalid => _t('Enter a price like 120 or 120.50', 'أدخل سعراً مثل 120 أو 120.50');
  String get close => _t('Close', 'إغلاق');
  // Live map
  String get mapTab => _t('Live map', 'الخريطة');
  String get liveNow => _t('Live', 'مباشر');
  String get noLocationYet => _t('No location yet', 'لا يوجد موقع بعد');
  String get driversFree => _t('Free', 'متاح');
  String get recenter => _t('Show all drivers', 'عرض كل السائقين');
  String get mapEmptyTitle => _t('No drivers to show', 'لا يوجد سائقون لعرضهم');
  String get mapEmptyHint => _t(
    'Drivers appear here once they go on duty in the driver app and share their location.',
    'يظهر السائقون هنا عندما يبدؤون الدوام في تطبيق السائق ويشاركون موقعهم.',
  );
  String orderNo(String n) => _t('Order $n', 'الطلب $n');
  String seenAgo(Duration d) {
    if (d.inSeconds < 45) return _t('just now', 'الآن');
    if (d.inMinutes < 60) return _t('${d.inMinutes} min ago', 'قبل ${d.inMinutes} د');
    return _t('${d.inHours} h ago', 'قبل ${d.inHours} س');
  }

  // Account, session and menu
  String get sessionExpired => _t('Your session ended. Please sign in again.', 'انتهت جلستك. سجّل الدخول من جديد.');
  String get showPassword => _t('Show password', 'إظهار كلمة المرور');
  String get hidePassword => _t('Hide password', 'إخفاء كلمة المرور');
  String get twoFactorCode => _t('Verification code', 'رمز التحقق');
  String get twoFactorHint => _t('Enter the code from your authenticator app.', 'أدخل الرمز من تطبيق المصادقة.');
  String get signInHintRequired => _t('Enter your email and password.', 'أدخل البريد الإلكتروني وكلمة المرور.');
  String get menu => _t('Menu', 'القائمة');
  String get signOutDriverQ => _t('Sign out and go off duty?', 'تسجيل الخروج وإنهاء التواجد؟');
  String get signOutDriverBody => _t(
    'You will stop receiving deliveries and stop sharing your location.',
    'ستتوقف عن استلام التوصيلات ومشاركة موقعك.',
  );
  String get signOutQ => _t('Sign out?', 'تسجيل الخروج؟');
  String get errorFallback => _t('Something went wrong. Please try again.', 'حدث خطأ ما. حاول مرة أخرى.');

  // Driver extras
  String get locationOffTitle => _t('Location is off', 'الموقع متوقف');
  String get locationOffBody => _t(
    'Dispatch cannot see where you are. Turn on location for this app.',
    'لا يستطيع المرسل رؤية موقعك. فعّل الموقع لهذا التطبيق.',
  );
  String get openSettings => _t('Open settings', 'فتح الإعدادات');
  String get retryDelivery => _t('Try delivery again', 'إعادة محاولة التوصيل');
  String get returnToBranch => _t('Return to branch', 'إعادة إلى الفرع');
  String get confirmFailQ => _t('Report as not delivered?', 'الإبلاغ بتعذّر التسليم؟');
  String confirmFailBody(String reason) =>
      _t('Reason: $reason. The branch will be told.', 'السبب: $reason. سيتم إبلاغ الفرع.');
  String get confirmReturnQ => _t('Handed back at the branch?', 'تم تسليمه للفرع؟');
  String get confirmReturnBody =>
      _t('Only confirm once the order is back at the branch.', 'أكّد فقط بعد عودة الطلب إلى الفرع.');
  String scheduledFor(String when) => _t('Scheduled: $when', 'الموعد: $when');
  String get couldNotRefresh => _t('Could not refresh. Showing the last list.', 'تعذّر التحديث. تُعرض آخر قائمة.');
  String get cashRequired => _t('Enter the cash amount.', 'أدخل المبلغ المحصّل.');
  String get cashInvalid => _t('Enter a valid amount.', 'أدخل مبلغاً صحيحاً.');
  String cashTooMuch(String due) =>
      _t('More than the amount due (${money(due)}).', 'أكثر من المبلغ المستحق (${money(due)}).');
  String get currency => _t('QAR', 'ر.ق');
  String money(String amount) => _t('QAR $amount', '$amount ر.ق');
  String get photoFailed =>
      _t('Could not open the camera. Check the camera permission.', 'تعذّر فتح الكاميرا. تحقق من إذن الكاميرا.');
  String get zoneWord => _t('Zone', 'المنطقة');
  String get streetWord => _t('Street', 'الشارع');
  String get buildingWord => _t('Bldg', 'المبنى');
  String get unitWord => _t('Unit', 'الوحدة');
  String get couldNotOpen => _t('Could not open this on your phone.', 'تعذّر فتح هذا على هاتفك.');

  /// "Zone 24 · Street 817 · Bldg 12" in the app language.
  String address(OpsAddress a) {
    final parts = <String>[
      if (a.zone != null) '$zoneWord ${a.zone}',
      if (a.street != null) '$streetWord ${a.street}',
      if (a.building != null) '$buildingWord ${a.building}',
      if (a.unit != null) '$unitWord ${a.unit}',
      if (a.area != null) a.area!,
    ];
    return parts.isEmpty ? a.line : parts.join(' · ');
  }

  // Add product extras
  String get studioFailed => _t(
    'Product saved, but the photo studio request was not sent. Ask again from the dashboard.',
    'تم حفظ المنتج، لكن لم يُرسل طلب استوديو الصور. اطلبه من لوحة التحكم.',
  );
  String get replaceTextQ => _t('Replace your text?', 'استبدال نصك؟');
  String get replaceTextBody =>
      _t('The AI will overwrite the descriptions you already typed.', 'سيستبدل الذكاء الاصطناعي الأوصاف التي كتبتها.');
  String get replace => _t('Replace', 'استبدال');
  String get priceZero => _t('The price must be more than zero.', 'يجب أن يكون السعر أكبر من صفر.');
  String uploadingPhoto(int n, int total) => _t('Uploading photo $n of $total…', 'جارٍ رفع الصورة $n من $total…');
  String get partialSaved => _t(
    'The product was created but some photos did not upload. Tap Save to finish uploading.',
    'تم إنشاء المنتج لكن لم ترفع بعض الصور. اضغط حفظ لإكمال الرفع.',
  );
  String get categoriesFailed => _t('Could not load categories. Tap to retry.', 'تعذّر تحميل الفئات. اضغط للمحاولة.');
  String get saveBlocked => _t('Please wait, saving…', 'يرجى الانتظار، جارٍ الحفظ…');
  String get photoBroken => _t('Photo unreadable', 'تعذّر عرض الصورة');

  // Maps: fleet filters, delivery map, navigation
  String get fleetFilterAll => _t('All', 'الكل');
  String get fleetFilterMoving => _t('Moving', 'في حركة');
  String get fleetFilterIdle => _t('Waiting', 'متوقف');
  String get fleetFilterStale => _t('Not live', 'غير مباشر');
  String get fleetNoMatch => _t('No drivers match this filter.', 'لا يوجد سائقون بهذا التصنيف.');
  String fleetListTitle(int n) => _t('Drivers · $n', 'السائقون · $n');
  String get fleetLinkConnecting => _t('Connecting…', 'جارٍ الاتصال…');
  String get fleetLinkReconnecting => _t('Reconnecting…', 'جارٍ إعادة الاتصال…');
  String get fleetLinkOffline => _t('Offline', 'غير متصل');
  String fleetSpeed(int kmh) => _t('$kmh km/h', '$kmh كم/س');
  String showOnMap(String name) => _t('Show $name on the map', 'عرض $name على الخريطة');
  String get jobMapTitle => _t('Delivery map', 'خريطة التوصيل');
  String get jobMap => _t('Map', 'الخريطة');
  String jobEta(int minutes) => _t('About $minutes min', 'حوالي $minutes د');
  String jobToGo(String distance) => _t('$distance to go', 'تبقى $distance');
  String get jobEstimateNote =>
      _t('Estimated from the straight-line distance.', 'تقدير بناءً على المسافة المباشرة.');
  String get jobNoPin => _t(
    'This delivery has no map pin. Use the address text to find it.',
    'لا يوجد دبوس خريطة لهذا التوصيل. استخدم نص العنوان للوصول.',
  );
  String get jobFindingYou => _t('Finding your position…', 'جارٍ تحديد موقعك…');
  String get trackingChannel => _t('Live location', 'الموقع المباشر');

  String get language => _t('العربية', 'English');
}
